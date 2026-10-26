<?php

namespace App\Hr\Domain;

use App\Hr\Auth\Auth;
use App\Hr\Crm\CrmGateway;
use App\Hr\Http\ApiError;
use App\Hr\Http\Ctx;
use App\Hr\Kernel;
use App\Hr\Support\Audit;
use App\Hr\Support\Clock;
use App\Hr\Support\Fmt;
use App\Hr\Support\Notify;
use App\Hr\Support\Vault;

/**
 * Phase 3 (Money): salaries, the monthly payroll run, payslips, the ICICI bank
 * file, advances, final settlements and tax profiles.
 *
 * John's decisions (26 Oct 2026):
 *  - one person runs and locks payroll (no second approver);
 *  - one monthly salary per person, split on the payslip into Basic / HRA /
 *    Special by a ratio set once in Payroll settings;
 *  - unpaid days cut salary pro rata (salary × paid days ÷ days in the month),
 *    from the LOCKED attendance month — the run can't start before that;
 *  - the bank file is ICICI's PAB-SAL bulk upload (MCW within ICICI, MCO by NEFT).
 *
 * A run goes draft (figures can change) → locked (frozen, payslips made, bank
 * file) → paid (payslips published to staff). Locked months are never recalculated:
 * the attendance month and the KRA month a locked run used can't be reopened.
 */
final class Payroll
{
    private const SETTINGS = [
        'split'       => ['basic' => 50, 'hra' => 20, 'special' => 30],
        'icici'       => ['corp_id' => null, 'debit_account' => null],
        'psdt'        => ['MOH' => false, 'DMR' => false, 'JSR' => false],
        'psdt_amount' => 200,
        'pay_day'     => 6,
        'deduction_cap_pct' => 50,
    ];

    public const ADJ_LABELS = ['addition' => ['Arrears', 'Bonus', 'Reimbursement', 'Leave encashment', 'Other addition'],
                               'deduction' => ['Notice pay recovery', 'Damage or loss', 'Other deduction']];

    // ---- settings ----------------------------------------------------------------

    public static function settings(): array
    {
        $raw = Kernel::hr()->table('hr_settings')->where('key', 'payroll')->value('value');
        $val = $raw !== null ? (json_decode($raw, true) ?: []) : [];
        return array_replace_recursive(self::SETTINGS, $val);
    }

    /** The tax table as stored (whole, so the CA can drop or add slabs), or the defaults. */
    public static function taxTable(): array
    {
        $raw = Kernel::hr()->table('hr_settings')->where('key', 'tax_table')->value('value');
        $val = $raw !== null ? json_decode($raw, true) : null;
        return is_array($val) && isset($val['new'], $val['old']) ? $val : Tax::DEFAULT_TABLE;
    }

    public static function settingsView(Ctx $c): array
    {
        $s = self::settings();
        return ['settings' => $s, 'tax' => self::taxTable(), 'centres' => array_map(fn($x) => $x['name'], Settings::get('centres')),
                'adj_labels' => self::ADJ_LABELS];
    }

    public static function saveSettings(Ctx $c): array
    {
        $s = self::settings();
        $basic = $c->intVal('basic', 10, 90, true, 'Basic');
        $hra = $c->intVal('hra', 0, 60, true, 'HRA');
        $errors = [];
        if ($basic + $hra > 100) {
            $errors['hra'] = 'Basic and HRA together can\'t be more than 100%.';
        }
        $corp = $c->str('corp_id', 40);
        $debit = $c->str('debit_account', 20);
        if ($debit !== null && $debit !== '' && !preg_match('/^\d{12}$/', $debit)) {
            $errors['debit_account'] = 'ICICI account numbers are 12 digits.';
        }
        if ($corp !== null && $corp !== '' && !preg_match('/^[A-Za-z0-9._-]{2,40}$/', $corp)) {
            $errors['corp_id'] = 'Use the Corporate ID exactly as ICICI gave it.';
        }
        if ($errors) {
            throw ApiError::invalid('Please check the highlighted details.', $errors);
        }
        $s['split'] = ['basic' => $basic, 'hra' => $hra, 'special' => 100 - $basic - $hra];
        $s['icici'] = ['corp_id' => $corp ?: null, 'debit_account' => $debit ?: null];
        foreach (array_keys(Settings::get('centres')) as $code) {
            $s['psdt'][$code] = (bool) $c->raw('psdt_' . $code);
        }
        $s['psdt_amount'] = $c->intVal('psdt_amount', 0, 2500, true, 'PSDT amount');
        $s['pay_day'] = $c->intVal('pay_day', 1, 7, true, 'Pay day');
        $s['deduction_cap_pct'] = $c->intVal('deduction_cap_pct', 10, 50, true, 'Deduction cap');
        Kernel::hr()->table('hr_settings')->updateOrInsert(['key' => 'payroll'], [
            'value' => json_encode($s, JSON_UNESCAPED_UNICODE), 'updated_by' => $c->accountId(), 'updated_at' => Kernel::now(),
        ]);
        Audit::log('payroll.settings', ['summary' => Fmt::first((string) $c->auth['display_name']) . ' changed the payroll settings']);
        return ['message' => 'Payroll settings saved.'];
    }

    public static function saveTaxTable(Ctx $c): array
    {
        $in = $c->raw('table');
        if (!is_array($in)) {
            throw ApiError::invalid('Nothing to save.');
        }
        $out = ['source' => mb_substr(trim((string) ($in['source'] ?? '')), 0, 200) ?: 'Changed in Trio HR',
                'cess_pct' => self::num($in['cess_pct'] ?? null, 0, 20, 'Cess'), 'no_pan_pct' => self::num($in['no_pan_pct'] ?? null, 0, 50, 'No-PAN rate')];
        foreach (['new', 'old'] as $r) {
            $x = $in[$r] ?? [];
            $slabs = [];
            $prev = 0;
            foreach (array_values((array) ($x['slabs'] ?? [])) as $i => $s) {
                $upper = ($s[0] ?? null) === null || $s[0] === '' ? null : (int) $s[0];
                $pct = self::num($s[1] ?? null, 0, 50, 'A slab rate');
                if ($upper !== null && $upper <= $prev) {
                    throw ApiError::invalid('Each slab must start above the one before it.');
                }
                $slabs[] = [$upper, $pct];
                if ($upper === null) {
                    break;
                }
                $prev = $upper;
            }
            if (!$slabs || end($slabs)[0] !== null) {
                throw ApiError::invalid('The last slab has to be open-ended ("and above").');
            }
            $out[$r] = ['std' => (int) self::num($x['std'] ?? null, 0, 500000, 'Standard deduction'),
                        'rebate_limit' => (int) self::num($x['rebate_limit'] ?? null, 0, 5000000, 'Rebate limit'),
                        'rebate_max' => (int) self::num($x['rebate_max'] ?? null, 0, 500000, 'Rebate'), 'slabs' => $slabs];
        }
        Kernel::hr()->table('hr_settings')->updateOrInsert(['key' => 'tax_table'], [
            'value' => json_encode($out, JSON_UNESCAPED_UNICODE), 'updated_by' => $c->accountId(), 'updated_at' => Kernel::now(),
        ]);
        Audit::log('payroll.tax_table', ['summary' => Fmt::first((string) $c->auth['display_name']) . ' changed the tax table', 'detail' => $out]);
        return ['message' => 'Tax table saved. Draft runs pick it up when you recalculate.'];
    }

    private static function num(mixed $v, float $min, float $max, string $label): float
    {
        if (!is_numeric($v) || (float) $v < $min || (float) $v > $max) {
            throw ApiError::invalid($label . ' should be a number from ' . $min . ' to ' . $max . '.');
        }
        return (float) $v;
    }

    // ---- salaries ------------------------------------------------------------------

    /** The monthly salary in force on a date, or null. */
    public static function salaryOn(int $employeeId, string $date): ?int
    {
        $v = Kernel::hr()->table('hr_salaries')->where('employee_id', $employeeId)->where('effective_from', '<=', $date)
            ->orderByDesc('effective_from')->orderByDesc('id')->value('monthly');
        return $v === null ? null : (int) $v;
    }

    public static function salaries(Ctx $c): array
    {
        $db = Kernel::hr();
        $people = $db->table('hr_employees')->whereIn('status', ['joining', 'active', 'notice'])->orderBy('full_name')
            ->get(['id', 'full_name', 'employee_code', 'designation', 'centre', 'status', 'date_joined']);
        $rows = $db->table('hr_salaries')->whereIn('employee_id', $people->pluck('id')->all() ?: [0])
            ->orderBy('effective_from')->orderBy('id')->get()->groupBy('employee_id');
        $today = date('Y-m-d');
        $out = [];
        $total = 0;
        foreach ($people as $p) {
            $hist = $rows[$p->id] ?? collect();
            $current = $hist->filter(fn($h) => $h->effective_from <= $today)->last();
            $next = $hist->filter(fn($h) => $h->effective_from > $today)->first();
            $total += $current ? (int) $current->monthly : 0;
            $out[] = ['id' => (int) $p->id, 'name' => $p->full_name, 'code' => $p->employee_code, 'designation' => $p->designation,
                'centre' => Settings::centreName($p->centre), 'status' => $p->status, 'initials' => Fmt::initials($p->full_name),
                'monthly' => $current ? (int) $current->monthly : null, 'since' => $current->effective_from ?? null,
                'next' => $next ? ['monthly' => (int) $next->monthly, 'from' => $next->effective_from] : null,
                'history' => $hist->reverse()->values()->map(fn($h) => ['monthly' => (int) $h->monthly, 'from' => $h->effective_from, 'note' => $h->note])->all()];
        }
        Audit::log('salary.view', ['summary' => Fmt::first((string) $c->auth['display_name']) . ' opened the salary list']);
        return ['people' => $out, 'total' => $total, 'missing' => count(array_filter($out, fn($r) => $r['monthly'] === null))];
    }

    public static function setSalary(Ctx $c, int $employeeId): array
    {
        $e = Kernel::hr()->table('hr_employees')->where('id', $employeeId)->first(['id', 'full_name', 'date_joined']);
        if (!$e) {
            throw ApiError::notFound('That person');
        }
        $monthly = $c->intVal('monthly', 1000, 10000000, true, 'The monthly salary');
        $from = $c->date('from', true, 'Effective from');
        $note = $c->str('note', 255);
        if (self::lockedThrough($from)) {
            throw ApiError::conflict('Payroll is already locked for ' . date('F Y', strtotime($from)) . '. Choose a date after it.');
        }
        Kernel::hr()->table('hr_salaries')->insert(['employee_id' => $employeeId, 'monthly' => $monthly, 'effective_from' => $from,
            'note' => $note, 'created_by' => $c->accountId(), 'created_at' => Kernel::now()]);
        Kernel::hr()->table('hr_employee_history')->insert(['employee_id' => $employeeId, 'kind' => 'pay', 'effective_on' => $from,
            'summary' => 'Monthly salary set to ' . Fmt::rupees($monthly) . ($note ? ' (' . $note . ')' : ''), 'by_account_id' => $c->accountId(), 'at' => Kernel::now()]);
        Audit::log('salary.set', ['employee_id' => $employeeId, 'entity' => 'hr_salaries',
            'summary' => Fmt::first((string) $c->auth['display_name']) . ' set ' . $e->full_name . "'s salary from " . Fmt::day($from)]);
        return ['message' => Fmt::first($e->full_name) . "'s salary is " . Fmt::rupees($monthly) . ' a month from ' . Fmt::day($from) . '.'];
    }

    /** Is the month of this date already in a locked or paid regular run? */
    private static function lockedThrough(string $date): bool
    {
        return Kernel::hr()->table('hr_payroll_runs')->where('kind', 'regular')->where('month', substr($date, 0, 7))
            ->whereIn('status', ['locked', 'paid'])->exists();
    }

    /** Used by Attendance::unlock and Kra::reopenPayouts: a locked payroll month can't be reopened under it. */
    public static function monthUsedByLockedRun(string $ym, string $as = 'month'): bool
    {
        if ($as !== 'incentive') {
            // Only the monthly run reads the locked attendance month; a settlement reads live attendance.
            return Kernel::hr()->table('hr_payroll_runs')->where('kind', 'regular')->where('month', $ym)->whereIn('status', ['locked', 'paid'])->exists();
        }
        // An incentive month is "used" once some locked run actually paid it: the months paid are listed on each line.
        return Kernel::hr()->table('hr_payroll_lines as l')->join('hr_payroll_runs as r', 'r.id', '=', 'l.run_id')
            ->whereIn('r.status', ['locked', 'paid'])->whereRaw("JSON_CONTAINS(JSON_EXTRACT(l.detail, '$.incentive_months'), ?)", [json_encode($ym)])->exists();
    }

    // ---- runs: list and start ----------------------------------------------------------

    public static function overview(Ctx $c): array
    {
        $db = Kernel::hr();
        $runs = $db->table('hr_payroll_runs as r')->leftJoin('hr_employees as e', 'e.id', '=', 'r.employee_id')
            ->orderByDesc('r.month')->orderBy('r.kind')->limit(36)->get(['r.*', 'e.full_name']);
        $locked = Attendance::lockedMonths();
        $regular = $runs->where('kind', 'regular')->pluck('month')->all();
        $ready = array_values(array_filter(array_keys($locked), fn($m) => !in_array($m, $regular, true)));
        rsort($ready);
        return [
            'runs' => $runs->map(fn($r) => self::runRow($r))->all(),
            'can_start' => array_map(fn($m) => ['value' => $m, 'label' => self::monthName($m)], $ready),
            'next_open' => self::nextUnlockedMonth($regular),
            'settlements_due' => self::settlementsDue(),
            'setup' => self::setupGaps(),
        ];
    }

    private static function runRow(object $r): array
    {
        $t = json_decode((string) $r->totals, true) ?: [];
        return ['id' => (int) $r->id, 'kind' => $r->kind, 'month' => $r->month, 'month_name' => self::monthName($r->month),
            'status' => $r->status, 'person' => $r->full_name ?? null, 'people' => (int) ($t['people'] ?? 0), 'net' => (int) ($t['net'] ?? 0),
            'pay_date' => $r->pay_date, 'paid_at' => $r->paid_at, 'locked_at' => $r->locked_at];
    }

    /** The earliest month that has neither a payroll run nor a locked attendance month (shown as "lock attendance first"). */
    private static function nextUnlockedMonth(array $regular): ?array
    {
        $last = date('Y-m', strtotime(date('Y-m-01') . ' -1 month'));
        if (in_array($last, $regular, true) || isset(Attendance::lockedMonths()[$last])) {
            return null;
        }
        return ['value' => $last, 'label' => self::monthName($last)];
    }

    /** What's missing before the first payroll can go smoothly. */
    private static function setupGaps(): array
    {
        $s = self::settings();
        $company = Settings::get('company');
        $gaps = [];
        if (!$s['icici']['corp_id'] || !$s['icici']['debit_account']) {
            $gaps[] = ['text' => 'ICICI Corporate ID and salary account number (for the bank file)', 'href' => '#/payroll?tab=settings'];
        }
        if (empty($company['tan'])) {
            $gaps[] = ['text' => "Trio's TAN (for TDS)", 'href' => '#/settings'];
        }
        $active = Kernel::hr()->table('hr_employees')->whereIn('status', ['active', 'notice'])->pluck('id')->all();
        $withSalary = Kernel::hr()->table('hr_salaries')->whereIn('employee_id', $active ?: [0])->distinct()->count('employee_id');
        if ($withSalary < count($active)) {
            $gaps[] = ['text' => (count($active) - $withSalary) . ' of ' . count($active) . ' people have no salary yet', 'href' => '#/payroll?tab=salaries'];
        }
        return $gaps;
    }

    public static function start(Ctx $c): array
    {
        $ym = (string) $c->str('month', 7, true, 'The month');
        if (!preg_match('/^\d{4}-\d{2}$/', $ym)) {
            throw ApiError::invalid('Pick a month.');
        }
        if (Kernel::hr()->table('hr_payroll_runs')->where('run_key', 'regular:' . $ym)->exists()) {
            throw ApiError::conflict('Payroll for ' . self::monthName($ym) . ' is already started. Open it from the list.');
        }
        $lock = Kernel::hr()->table('hr_attendance_months')->where('month', $ym)->where('status', 'locked')->first();
        if (!$lock) {
            throw ApiError::conflict('Attendance for ' . self::monthName($ym) . ' isn\'t locked yet. Lock it first (Attendance → Close the month), so payroll uses final days.');
        }
        $id = Kernel::hr()->table('hr_payroll_runs')->insertGetId([
            'run_key' => 'regular:' . $ym, 'kind' => 'regular', 'month' => $ym,
            'incentive_month' => date('Y-m', strtotime($ym . '-01 -1 month')), 'status' => 'draft',
            'pay_date' => self::defaultPayDate($ym), 'prepared_by' => $c->accountId(), 'prepared_at' => Kernel::now(),
            'created_at' => Kernel::now(), 'updated_at' => Kernel::now(),
        ]);
        self::compute($id);
        Audit::log('payroll.start', ['entity' => 'hr_payroll_runs', 'entity_id' => $id,
            'summary' => Fmt::first((string) $c->auth['display_name']) . ' started payroll for ' . self::monthName($ym)]);
        return ['id' => $id, 'message' => 'Payroll for ' . self::monthName($ym) . ' is ready to review.'];
    }

    private static function defaultPayDate(string $ym): string
    {
        $next = date('Y-m', strtotime($ym . '-01 +1 month'));
        return $next . '-' . str_pad((string) self::settings()['pay_day'], 2, '0', STR_PAD_LEFT);
    }

    // ---- the calculation ------------------------------------------------------------

    /** (Re)work out every line of a draft run. */
    public static function compute(int $runId): void
    {
        $db = Kernel::hr();
        $run = $db->table('hr_payroll_runs')->where('id', $runId)->first();
        if (!$run || $run->status !== 'draft') {
            throw ApiError::conflict('Only a draft can be recalculated.');
        }
        [$from, $to, $daysInMonth] = self::bounds($run->month);
        $s = self::settings();
        $table = self::taxTable();

        // Who and how many paid days
        if ($run->kind === 'regular') {
            $snap = json_decode((string) $db->table('hr_attendance_months')->where('month', $run->month)->where('status', 'locked')->value('snapshot'), true);
            if (!is_array($snap)) {
                throw ApiError::conflict('Attendance for ' . self::monthName($run->month) . ' was unlocked. Lock it again, then recalculate.');
            }
            $settled = $db->table('hr_payroll_runs')->where('kind', 'settlement')->where('month', $run->month)->pluck('employee_id')->map(fn($v) => (int) $v)->all();
            $days = [];
            foreach ($snap as $id => $row) {
                if (!in_array((int) $id, $settled, true)) {
                    $days[(int) $id] = ['paid' => (float) $row['paid_days'], 'unpaid' => (float) $row['unpaid_days'], 'employed' => (int) $row['days'], 'issues' => []];
                }
            }
        } else {
            $days = [(int) $run->employee_id => self::settlementDays((int) $run->employee_id)];
        }

        $people = $db->table('hr_employees')->whereIn('id', array_keys($days) ?: [0])->get()->keyBy('id');
        $incentives = self::incentivesFor($run, $people);
        $kraClosed = $run->incentive_month && Kernel::crm()->table('kra_closed_months')->where('month', $run->incentive_month)->exists();
        $adjust = $db->table('hr_payroll_adjustments')->where('run_id', $runId)->get()->groupBy('employee_id');
        $vault = $db->table('hr_employee_secrets')->whereIn('employee_id', array_keys($days) ?: [0])
            ->whereIn('field', ['bank_account', 'bank_ifsc', 'pan'])->get(['employee_id', 'field', 'hint', 'ciphertext'])->groupBy('employee_id');
        $held = $db->table('hr_payroll_lines')->where('run_id', $runId)->where('held', 1)->pluck('hold_reason', 'employee_id')->all();
        $taxYear = Tax::taxYear($run->month);
        $profiles = $db->table('hr_tax_profiles')->where('tax_year', $taxYear)->whereIn('employee_id', array_keys($days) ?: [0])->get()->keyBy('employee_id');
        $before = self::paidThisYear(array_keys($days), $taxYear, $runId);

        $checks = [];
        if ($run->kind === 'regular' && $run->incentive_month && !$kraClosed) {
            $checks[] = ['block' => false, 'text' => 'KRA incentives for ' . self::monthName($run->incentive_month) . ' aren\'t closed yet, so this run pays none. If you lock it now, they are paid with the next month\'s payroll once closed.'];
        }
        if (!$s['icici']['corp_id'] || !$s['icici']['debit_account']) {
            $checks[] = ['block' => false, 'text' => 'The ICICI Corporate ID and salary account aren\'t set, so the bank file can\'t be made yet (Payroll → Settings).'];
        }

        $totals = ['people' => 0, 'earned' => 0, 'incentive' => 0, 'additions' => 0, 'deductions' => 0, 'tds' => 0, 'net' => 0, 'held' => 0];
        $db->transaction(function () use ($db, $run, $runId, $days, $people, $daysInMonth, $from, $to, $s, $table, $incentives, $adjust, $vault, $held, $profiles, $before, $checks, &$totals) {
            // Someone may have locked it since we looked: a locked run is never recalculated.
            if ($db->table('hr_payroll_runs')->where('id', $runId)->lockForUpdate()->value('status') !== 'draft') {
                throw ApiError::conflict('This run was locked a moment ago, so it can\'t be recalculated.');
            }
            // Lines are updated in place (their ids stay the same, so the screen's links and holds keep working).
            $db->table('hr_payroll_lines')->where('run_id', $runId)->whereNotIn('employee_id', array_keys($days) ?: [0])->delete();
            foreach ($days as $id => $d) {
                $e = $people[$id] ?? null;
                if (!$e) {
                    continue;
                }
                $line = self::line($run, $e, $d, $daysInMonth, $from, $to, $s, $table, $incentives[$id] ?? null, $adjust[$id] ?? collect(),
                    $vault[$id] ?? collect(), $profiles[$id] ?? null, $before[$id] ?? ['salary' => 0, 'tds' => 0], isset($held[$id]));
                $db->table('hr_payroll_lines')->updateOrInsert(['run_id' => $runId, 'employee_id' => $id], [
                    'held' => isset($held[$id]) ? 1 : 0, 'hold_reason' => $held[$id] ?? null,
                    'earned' => $line['earned'], 'incentive' => $line['incentive'], 'additions' => $line['additions'],
                    'deductions' => $line['deductions'], 'tds' => $line['tds'], 'net' => $line['net'],
                    'detail' => json_encode($line['detail'], JSON_UNESCAPED_UNICODE),
                ]);
                $totals['people']++;
                foreach (['earned', 'incentive', 'additions', 'deductions', 'tds', 'net'] as $k) {
                    $totals[$k] += $line[$k];
                }
                if (isset($held[$id])) {
                    $totals['held']++;
                }
            }
            $db->table('hr_payroll_runs')->where('id', $runId)->update([
                'totals' => json_encode($totals), 'checks' => json_encode($checks), 'updated_at' => Kernel::now(),
            ]);
        });
    }

    /** A draft run, locked for this transaction; refuses once it is locked. */
    private static function draftForUpdate(int $runId): object
    {
        $r = Kernel::hr()->table('hr_payroll_runs')->where('id', $runId)->lockForUpdate()->first();
        if (!$r) {
            throw ApiError::notFound('That payroll run');
        }
        if ($r->status !== 'draft') {
            throw ApiError::conflict('Locked payroll can\'t change.');
        }
        return $r;
    }

    /**
     * Salary for the days of a month, when the monthly figure can change mid-month:
     * each calendar day with Trio carries the salary in force that day, and paid days
     * are paid at the average of those. [average monthly rate, rate at the end, why, days without a salary].
     */
    private static function rateForMonth(object $e, string $from, string $to): array
    {
        $start = max($from, (string) ($e->date_joined ?: $from));
        $end = $e->last_working_day && $e->last_working_day < $to ? $e->last_working_day : $to;
        if ($start > $end) {
            $start = $from;
        }
        $rows = Kernel::hr()->table('hr_salaries')->where('employee_id', $e->id)->where('effective_from', '<=', $end)
            ->orderBy('effective_from')->orderBy('id')->get(['monthly', 'effective_from']);
        $sum = 0;
        $days = 0;
        $none = 0;
        $parts = [];
        for ($d = $start; $d <= $end; $d = Clock::addDays($d, 1)) {
            $rate = null;
            foreach ($rows as $r) {
                if ($r->effective_from <= $d) {
                    $rate = (int) $r->monthly;
                }
            }
            $days++;
            if ($rate === null) {
                $none++;
                continue;
            }
            $sum += $rate;
            $parts[$rate] = ($parts[$rate] ?? 0) + 1;
        }
        $endRate = self::salaryOn((int) $e->id, $end);
        if (!$days || $sum === 0) {
            return [$endRate === null ? null : 0, $endRate, '', $none];
        }
        $avg = $sum / $days;
        $why = count($parts) > 1
            ? ' The salary changed this month: ' . implode(', ', array_map(fn($r, $n) => Fmt::rupees($r) . ' for ' . self::fmtDays($n), array_keys($parts), $parts))
                . ', so paid days are paid at the average, ' . Fmt::rupees((int) round($avg)) . ' a month.'
            : '';
        return [$avg, $endRate, $why, $none];
    }

    /** One person's line: every figure with its reason. */
    private static function line(object $run, object $e, array $d, int $daysInMonth, string $monthStart, string $monthEnd, array $s, array $table, ?array $inc,
                                 $adj, $vault, ?object $profile, array $before, bool $isHeld): array
    {
        $checks = [];
        [$avg, $rate, $changeWhy, $noSalaryDays] = self::rateForMonth($e, $monthStart, $monthEnd);
        if ($avg === null) {
            // Holding their pay lets the rest of payroll go ahead while the salary is sorted out.
            $checks[] = ['code' => 'no_salary', 'block' => !$isHeld, 'text' => 'No salary set.'];
            $avg = 0;
            $rate = 0;
        } elseif ($noSalaryDays > 0) {
            $checks[] = ['code' => 'salary_gap', 'block' => false, 'text' => 'No salary for ' . self::fmtDays($noSalaryDays) . ' of the month (their salary starts later than they joined). Those days are paid nothing.'];
        }
        $rate = (int) $rate;
        $paid = $d['paid'];
        $earned = (int) round($avg * $paid / $daysInMonth);
        $basic = (int) round($earned * $s['split']['basic'] / 100);
        $hra = (int) round($earned * $s['split']['hra'] / 100);
        $special = $earned - $basic - $hra;
        $daysWhy = self::fmtDays($paid) . ' paid of ' . $daysInMonth . ' days in ' . self::monthName($run->month)
            . ($d['unpaid'] > 0 ? ' (' . self::fmtDays($d['unpaid']) . ' unpaid)' : '')
            . ($d['employed'] < $daysInMonth ? ' (' . $d['employed'] . ' days with Trio this month)' : '') . '.';
        $earnings = [];
        if ($earned > 0 || $rate > 0) {
            $earnings[] = ['key' => 'basic', 'label' => 'Basic', 'amount' => $basic, 'why' => $s['split']['basic'] . '% of salary earned. Monthly salary ' . Fmt::rupees($rate) . '; ' . $daysWhy . $changeWhy];
            $earnings[] = ['key' => 'hra', 'label' => 'House rent allowance', 'amount' => $hra, 'why' => $s['split']['hra'] . '% of salary earned.'];
            $earnings[] = ['key' => 'special', 'label' => 'Special allowance', 'amount' => $special, 'why' => 'The rest of salary earned (' . $s['split']['special'] . '%).'];
        }
        $incentive = (int) ($inc['amount'] ?? 0);
        if ($incentive > 0) {
            $earnings[] = ['key' => 'incentive', 'label' => 'Incentive', 'amount' => $incentive, 'why' => $inc['why']];
        }
        $additions = 0;
        $taxableAdd = 0;
        $deductions = [];
        $other = 0;
        foreach ($adj as $a) {
            if ($a->kind === 'addition') {
                $additions += (int) $a->amount;
                $taxableAdd += $a->taxable ? (int) $a->amount : 0;
                $earnings[] = ['key' => 'adj' . $a->id, 'label' => $a->label, 'amount' => (int) $a->amount, 'why' => $a->reason . ($a->taxable ? '' : ' (not taxed)'), 'adjustment' => (int) $a->id];
            } else {
                $other += (int) $a->amount;
                $deductions[] = ['key' => 'adj' . $a->id, 'label' => $a->label, 'amount' => (int) $a->amount, 'why' => $a->reason, 'adjustment' => (int) $a->id];
            }
        }

        // Advances: the instalment due, within the deduction cap.
        $gross = $earned + $incentive + $additions;
        $room = max(0, (int) floor($gross * $s['deduction_cap_pct'] / 100) - $other);
        $advances = [];
        foreach (self::openAdvances((int) $e->id, $run->kind === 'settlement' ? '9999-12' : $run->month, (int) $run->id) as $a) {
            $want = $run->kind === 'settlement' ? $a['remaining'] : min($a['monthly'], $a['remaining']);
            $take = min($want, $room);
            $room -= $take;
            if ($take > 0) {
                $advances[] = ['id' => $a['id'], 'amount' => $take];
                $deductions[] = ['key' => 'advance' . $a['id'], 'label' => 'Advance recovery', 'amount' => $take,
                    'why' => 'Advance of ' . Fmt::rupees($a['amount']) . ' (' . $a['reason'] . '): ' . Fmt::rupees($a['remaining'] - $take) . ' left after this.'];
            }
            if ($take < $want) {
                $checks[] = ['code' => 'cap', 'block' => false, 'text' => 'Advance recovery cut to ' . Fmt::rupees($take) . ' to stay within the ' . $s['deduction_cap_pct'] . '% deduction limit.'];
            }
        }
        $advanceTotal = array_sum(array_column($advances, 'amount'));

        // Tax
        $regime = $profile->regime ?? 'new';
        $panOk = $e->pan_status === 'valid' && $vault->firstWhere('field', 'pan');
        $tax = Tax::monthly([
            'regime' => $regime, 'month' => $run->month,
            'paid_salary' => $before['salary'] + (int) ($profile->prev_income ?? 0), 'paid_tds' => $before['tds'] + (int) ($profile->prev_tds ?? 0),
            'salary' => $earned + $taxableAdd, 'incentive' => $incentive, 'monthly' => $rate,
            'deductions' => (int) ($profile->deductions ?? 0), 'final' => $run->kind === 'settlement', 'pan_ok' => (bool) $panOk,
        ], $table);
        $tds = $tax['tds'];
        if ($tds > 0) {
            $deductions[] = ['key' => 'tds', 'label' => 'Income tax (TDS)', 'amount' => $tds, 'why' => $tax['why']];
        }
        $psdt = 0;
        if (!empty($s['psdt'][$e->centre]) && $tax['annual_tax'] > 0 && $run->kind === 'regular') {
            $psdt = (int) $s['psdt_amount'];
            $deductions[] = ['key' => 'psdt', 'label' => 'State development tax', 'amount' => $psdt, 'why' => 'Punjab State Development Tax for income-tax payees (switched on for this centre in Payroll settings).'];
        }

        $nonTax = $other + $advanceTotal + $psdt;
        $net = $gross - $nonTax - $tds;
        if ($net < 0) {
            $checks[] = ['code' => 'negative', 'block' => true, 'text' => 'Net pay would be negative (' . Fmt::rupees($net) . '). Reduce a deduction.'];
        }
        if ($nonTax > $gross * $s['deduction_cap_pct'] / 100) {
            $checks[] = ['code' => 'cap_total', 'block' => false, 'text' => 'Deductions are more than ' . $s['deduction_cap_pct'] . '% of pay.'];
        }
        $acct = $vault->firstWhere('field', 'bank_account');
        $ifsc = $vault->firstWhere('field', 'bank_ifsc');
        if (!$acct || !$ifsc) {
            $checks[] = ['code' => 'no_bank', 'block' => !$isHeld, 'text' => 'No bank account on record. Ask them to add it in Trio HR, or hold their pay.'];
        } elseif ($problem = self::bankProblem(Vault::decrypt($acct->ciphertext), Vault::decrypt($ifsc->ciphertext))) {
            // Caught now, not at the bank file: ICICI refuses the whole upload over one bad line.
            $checks[] = ['code' => 'bad_bank', 'block' => !$isHeld, 'text' => $problem . ' Ask them to correct it in Trio HR, or hold their pay.'];
        }
        if (!$panOk) {
            $checks[] = ['code' => 'no_pan', 'block' => false, 'text' => $vault->firstWhere('field', 'pan') ? 'PAN not marked valid yet.' : 'No PAN on record.'];
        }
        if ($paid <= 0) {
            $checks[] = ['code' => 'no_days', 'block' => false, 'text' => 'No paid days this month.'];
        }
        foreach ($d['issues'] as $i) {
            $checks[] = $i;
        }

        return [
            'earned' => $earned, 'incentive' => $incentive, 'additions' => $additions, 'deductions' => $nonTax, 'tds' => $tds, 'net' => $net,
            'detail' => [
                'name' => $e->full_name, 'code' => $e->employee_code, 'designation' => $e->designation, 'centre' => Settings::centreName($e->centre),
                'rate' => $rate, 'days_in_month' => $daysInMonth, 'paid_days' => $paid, 'unpaid_days' => $d['unpaid'], 'days_employed' => $d['employed'],
                'earnings' => $earnings, 'deductions' => $deductions, 'gross' => $gross, 'taxable' => $earned + $taxableAdd + $incentive,
                'tax' => ['regime' => $regime, 'annual' => $tax['annual_tax'], 'projected' => $tax['projected']],
                'incentive_months' => $inc['months'] ?? [], 'advances' => $advances, 'checks' => $checks,
                'bank_hint' => $acct ? '••' . $acct->hint . ($ifsc ? ' · ' . $ifsc->hint : '') : null,
            ],
        ];
    }

    private static function fmtDays(float $d): string
    {
        return (floor($d) == $d ? (string) (int) $d : rtrim(rtrim(number_format($d, 1), '0'), '.')) . ($d == 1 ? ' day' : ' days');
    }

    /** [first day, last day, days in month]. */
    private static function bounds(string $ym): array
    {
        $first = $ym . '-01';
        return [$first, date('Y-m-t', strtotime($first)), (int) date('t', strtotime($first))];
    }

    public static function monthName(string $ym): string
    {
        return date('F Y', strtotime($ym . '-01'));
    }

    /**
     * Incentives this run pays, per employee: the closed KRA month(s) not yet
     * paid in another locked run. A regular run pays its incentive month; a
     * settlement pays every closed month the person hasn't been paid for.
     */
    private static function incentivesFor(object $run, $people): array
    {
        $byCrm = [];
        foreach ($people as $e) {
            if ($e->crm_user_id) {
                $byCrm[(int) $e->crm_user_id] = (int) $e->id;
            }
        }
        if (!$byCrm) {
            return [];
        }
        // A regular run pays its incentive month plus any of the two before it that closed late (after their
        // own payroll was locked); a settlement pays every recent closed month. Months already paid are skipped below.
        $upTo = $run->kind === 'regular' ? $run->incentive_month : $run->month;
        $months = $upTo ? Kernel::crm()->table('kra_closed_months')->where('month', '<=', $upTo)
            ->where('month', '>=', date('Y-m', strtotime($upTo . '-01 -' . ($run->kind === 'regular' ? 2 : 3) . ' months')))->pluck('month')->all() : [];
        if (!$months) {
            return [];
        }
        $paid = self::incentiveMonthsPaid(array_values($byCrm), (int) $run->id);
        $rows = Kernel::crm()->table('kra_payouts')->whereIn('month', $months)->whereIn('crm_user_id', array_keys($byCrm))->where('total_inr', '>', 0)->get();
        $out = [];
        foreach ($rows as $r) {
            $emp = $byCrm[(int) $r->crm_user_id];
            if (in_array($r->month, $paid[$emp] ?? [], true)) {
                continue;
            }
            $out[$emp]['amount'] = ($out[$emp]['amount'] ?? 0) + (int) $r->total_inr;
            $out[$emp]['months'][] = $r->month;
            $out[$emp]['parts'][] = self::monthName($r->month) . ': Net MCO $' . number_format((float) $r->net_mco) . ', incentive ' . Fmt::rupees((int) $r->total_inr)
                . ((float) $r->scale < 1 ? ' (scaled to ' . round((float) $r->scale * 100) . '% to stay within the company cap)' : '');
        }
        foreach ($out as &$o) {
            $o['why'] = 'KRA incentives, closed and locked. ' . implode('; ', $o['parts']) . '.';
        }
        return $out;
    }

    /** employee id => incentive months already paid in a locked/paid run (other than $exceptRun). */
    private static function incentiveMonthsPaid(array $employeeIds, int $exceptRun): array
    {
        $rows = Kernel::hr()->table('hr_payroll_lines as l')->join('hr_payroll_runs as r', 'r.id', '=', 'l.run_id')
            ->whereIn('r.status', ['locked', 'paid'])->where('r.id', '!=', $exceptRun)->whereIn('l.employee_id', $employeeIds ?: [0])
            ->get(['l.employee_id', 'l.detail']);
        $out = [];
        foreach ($rows as $r) {
            $d = json_decode((string) $r->detail, true) ?: [];
            foreach ($d['incentive_months'] ?? [] as $m) {
                $out[(int) $r->employee_id][] = $m;
            }
        }
        return $out;
    }

    /** Taxable pay and TDS already in locked/paid runs this tax year (before this one). */
    private static function paidThisYear(array $employeeIds, string $taxYear, int $exceptRun): array
    {
        $months = Tax::yearMonths($taxYear);
        $rows = Kernel::hr()->table('hr_payroll_lines as l')->join('hr_payroll_runs as r', 'r.id', '=', 'l.run_id')
            ->whereIn('r.status', ['locked', 'paid'])->where('r.id', '!=', $exceptRun)->whereIn('r.month', $months)
            ->whereIn('l.employee_id', $employeeIds ?: [0])->get(['l.employee_id', 'l.tds', 'l.detail']);
        $out = [];
        foreach ($rows as $r) {
            $d = json_decode((string) $r->detail, true) ?: [];
            $id = (int) $r->employee_id;
            $out[$id]['salary'] = ($out[$id]['salary'] ?? 0) + (int) ($d['taxable'] ?? 0);
            $out[$id]['tds'] = ($out[$id]['tds'] ?? 0) + (int) $r->tds;
        }
        return $out;
    }

    // ---- advances ----------------------------------------------------------------------

    /** Active advances with what's left, recoveries in locked runs counted (not this run). */
    private static function openAdvances(int $employeeId, string $ym, int $exceptRun): array
    {
        $db = Kernel::hr();
        $out = [];
        foreach ($db->table('hr_advances')->where('employee_id', $employeeId)->where('status', 'active')->where('start_month', '<=', $ym)->orderBy('id')->get() as $a) {
            $rec = (int) $db->table('hr_advance_recoveries as x')->join('hr_payroll_runs as r', 'r.id', '=', 'x.run_id')
                ->where('x.advance_id', $a->id)->where('x.run_id', '!=', $exceptRun)->whereIn('r.status', ['locked', 'paid'])->sum('x.amount');
            $left = (int) $a->amount - $rec;
            if ($left > 0) {
                $out[] = ['id' => (int) $a->id, 'amount' => (int) $a->amount, 'monthly' => (int) $a->monthly, 'remaining' => $left, 'reason' => $a->reason];
            }
        }
        return $out;
    }

    public static function advances(Ctx $c): array
    {
        $db = Kernel::hr();
        $rows = $db->table('hr_advances as a')->join('hr_employees as e', 'e.id', '=', 'a.employee_id')
            ->orderByRaw("FIELD(a.status, 'active', 'closed', 'cancelled')")->orderByDesc('a.id')->limit(300)
            ->get(['a.*', 'e.full_name']);
        $rec = $db->table('hr_advance_recoveries as x')->join('hr_payroll_runs as r', 'r.id', '=', 'x.run_id')->whereIn('r.status', ['locked', 'paid'])
            ->groupBy('x.advance_id')->selectRaw('x.advance_id, SUM(x.amount) as n')->pluck('n', 'advance_id');
        $people = $db->table('hr_employees')->whereIn('status', ['active', 'notice', 'joining'])->orderBy('full_name')->get(['id', 'full_name']);
        return [
            'advances' => $rows->map(fn($a) => ['id' => (int) $a->id, 'name' => $a->full_name, 'employee_id' => (int) $a->employee_id,
                'amount' => (int) $a->amount, 'monthly' => (int) $a->monthly, 'start' => self::monthName($a->start_month), 'reason' => $a->reason,
                'status' => $a->status, 'recovered' => (int) ($rec[$a->id] ?? 0), 'remaining' => max(0, (int) $a->amount - (int) ($rec[$a->id] ?? 0))])->all(),
            'people' => $people->map(fn($p) => ['id' => (int) $p->id, 'name' => $p->full_name])->all(),
        ];
    }

    public static function createAdvance(Ctx $c): array
    {
        $empId = $c->intVal('employee_id', 1, PHP_INT_MAX, true, 'The person');
        $e = Kernel::hr()->table('hr_employees')->where('id', $empId)->whereIn('status', ['active', 'notice', 'joining'])->first(['id', 'full_name']);
        if (!$e) {
            throw ApiError::notFound('That person');
        }
        $amount = $c->intVal('amount', 100, 5000000, true, 'The amount');
        $monthly = $c->intVal('monthly', 100, 5000000, true, 'The monthly instalment');
        $start = (string) $c->str('start_month', 7, true, 'First recovery month');
        $reason = (string) $c->str('reason', 255, true, 'A reason');
        if (!preg_match('/^\d{4}-\d{2}$/', $start) || self::lockedThrough($start . '-01')) {
            throw ApiError::invalid('Pick a month that payroll hasn\'t locked yet.', ['start_month' => 'Pick a later month.']);
        }
        if ($monthly > $amount) {
            throw ApiError::invalid('The instalment can\'t be more than the advance.', ['monthly' => 'Too high.']);
        }
        $id = Kernel::hr()->table('hr_advances')->insertGetId(['employee_id' => $empId, 'amount' => $amount, 'monthly' => $monthly,
            'start_month' => $start, 'reason' => $reason, 'status' => 'active', 'created_by' => $c->accountId(), 'created_at' => Kernel::now()]);
        Audit::log('advance.create', ['employee_id' => $empId, 'entity' => 'hr_advances', 'entity_id' => $id,
            'summary' => Fmt::first((string) $c->auth['display_name']) . ' recorded an advance of ' . Fmt::rupees($amount) . ' for ' . $e->full_name]);
        Notify::employee($empId, 'advance', 'Advance of ' . Fmt::rupees($amount) . ' recorded', Fmt::rupees($monthly) . ' a month comes off your salary from '
            . self::monthName($start) . '.', '#/me/pay');
        return ['message' => 'Advance recorded. ' . Fmt::rupees($monthly) . ' a month is recovered from ' . self::monthName($start) . '.'];
    }

    public static function cancelAdvance(Ctx $c, int $id): array
    {
        $a = Kernel::hr()->table('hr_advances')->where('id', $id)->first();
        if (!$a || $a->status !== 'active') {
            throw ApiError::conflict('Only an active advance can be closed.');
        }
        $reason = (string) $c->str('reason', 255, true, 'A reason');
        Kernel::hr()->table('hr_advances')->where('id', $id)->update(['status' => 'cancelled', 'closed_at' => Kernel::now(), 'reason' => mb_substr($a->reason . ' · Closed: ' . $reason, 0, 255)]);
        Audit::log('advance.cancel', ['employee_id' => (int) $a->employee_id, 'entity' => 'hr_advances', 'entity_id' => $id,
            'summary' => Fmt::first((string) $c->auth['display_name']) . ' closed an advance: ' . $reason]);
        return ['message' => 'Advance closed. Nothing more is recovered for it.'];
    }

    // ---- one run -------------------------------------------------------------------------

    private static function loadRun(int $id): object
    {
        $r = Kernel::hr()->table('hr_payroll_runs')->where('id', $id)->first();
        if (!$r) {
            throw ApiError::notFound('That payroll run');
        }
        return $r;
    }

    public static function get(Ctx $c, int $id): array
    {
        $r = self::loadRun($id);
        $lines = Kernel::hr()->table('hr_payroll_lines')->where('run_id', $id)->get();
        $slips = Kernel::hr()->table('hr_payslips')->where('run_id', $id)->pluck('id', 'line_id');
        $names = Kernel::hr()->table('hr_accounts')->pluck('display_name', 'id');
        $rows = [];
        $blocking = 0;
        foreach ($lines as $l) {
            $d = json_decode((string) $l->detail, true) ?: [];
            $checks = $d['checks'] ?? [];
            $blocking += count(array_filter($checks, fn($x) => !empty($x['block'])));
            $rows[] = ['id' => (int) $l->id, 'employee_id' => (int) $l->employee_id, 'name' => $d['name'] ?? '', 'code' => $d['code'] ?? null,
                'centre' => $d['centre'] ?? null, 'initials' => Fmt::initials($d['name'] ?? '?'), 'rate' => $d['rate'] ?? 0,
                'paid_days' => $d['paid_days'] ?? 0, 'days_in_month' => $d['days_in_month'] ?? 0,
                'earned' => (int) $l->earned, 'incentive' => (int) $l->incentive, 'additions' => (int) $l->additions,
                'deductions' => (int) $l->deductions, 'tds' => (int) $l->tds, 'net' => (int) $l->net,
                'held' => (bool) $l->held, 'hold_reason' => $l->hold_reason, 'paid_at' => $l->paid_at,
                'checks' => $checks, 'earnings' => $d['earnings'] ?? [], 'deduction_lines' => $d['deductions'] ?? [],
                'tax' => $d['tax'] ?? null, 'bank_hint' => $d['bank_hint'] ?? null, 'payslip_id' => isset($slips[$l->id]) ? (int) $slips[$l->id] : null,
                'in_file' => $l->bank_file_at];
        }
        usort($rows, fn($a, $b) => [count(array_filter($b['checks'], fn($x) => !empty($x['block']))), $a['name']]
            <=> [count(array_filter($a['checks'], fn($x) => !empty($x['block']))), $b['name']]);
        $kraClosed = $r->incentive_month ? Kernel::crm()->table('kra_closed_months')->where('month', $r->incentive_month)->exists() : false;
        return [
            'run' => self::runRow((object) ((array) $r + ['full_name' => null])) + [
                'incentive_month' => $r->incentive_month ? self::monthName($r->incentive_month) : null, 'incentives_closed' => $kraClosed,
                'totals' => json_decode((string) $r->totals, true) ?: [], 'checks' => json_decode((string) $r->checks, true) ?: [],
                'blocking' => $blocking, 'bank_ref' => $r->bank_ref,
                'prepared_by' => $names[$r->prepared_by] ?? null, 'locked_by' => $names[$r->locked_by] ?? null, 'paid_by' => $names[$r->paid_by] ?? null,
                'unpaid_held' => count(array_filter($rows, fn($x) => $x['held'] && !$x['paid_at'])),
                'unpaid' => count(array_filter($rows, fn($x) => !$x['held'] && !$x['paid_at'])),
                'not_in_file' => count(array_filter($rows, fn($x) => !$x['held'] && !$x['paid_at'] && !$x['in_file'] && $x['net'] > 0)),
            ],
            'lines' => $rows,
            'adj_labels' => self::ADJ_LABELS,
            'is_owner' => $c->role() === 'owner',
            'bank_ready' => (bool) (self::settings()['icici']['corp_id'] && self::settings()['icici']['debit_account']),
        ];
    }

    public static function recompute(Ctx $c, int $id): array
    {
        self::compute($id);
        return ['message' => 'Recalculated with the latest figures.'];
    }

    public static function discard(Ctx $c, int $id): array
    {
        $r = self::loadRun($id);
        if ($r->status !== 'draft') {
            throw ApiError::conflict('Only a draft can be discarded. Locked payroll is final.');
        }
        Kernel::hr()->transaction(function () use ($id) {
            Kernel::hr()->table('hr_payroll_lines')->where('run_id', $id)->delete();
            Kernel::hr()->table('hr_payroll_adjustments')->where('run_id', $id)->delete();
            Kernel::hr()->table('hr_payroll_runs')->where('id', $id)->where('status', 'draft')->delete();
        });
        Audit::log('payroll.discard', ['entity' => 'hr_payroll_runs', 'entity_id' => $id,
            'summary' => Fmt::first((string) $c->auth['display_name']) . ' discarded the draft payroll for ' . self::monthName($r->month)]);
        return ['message' => 'Draft discarded.'];
    }

    public static function addAdjustment(Ctx $c, int $runId): array
    {
        $r = self::loadRun($runId);
        if ($r->status !== 'draft') {
            throw ApiError::conflict('Locked payroll can\'t change.');
        }
        $empId = $c->intVal('employee_id', 1, PHP_INT_MAX, true, 'The person');
        if (!Kernel::hr()->table('hr_payroll_lines')->where('run_id', $runId)->where('employee_id', $empId)->exists()) {
            throw ApiError::notFound('That person in this run');
        }
        $kind = (string) $c->pick('kind', ['addition', 'deduction'], true, 'Kind');
        $label = (string) $c->str('label', 80, true, 'What it is');
        $amount = $c->intVal('amount', 1, 10000000, true, 'The amount');
        $reason = (string) $c->str('reason', 255, true, 'A reason');
        $taxable = $kind === 'addition' ? ($c->has('taxable') ? (bool) $c->raw('taxable') : true) : true;
        Kernel::hr()->transaction(function () use ($c, $runId, $empId, $kind, $label, $amount, $taxable, $reason) {
            self::draftForUpdate($runId);
            Kernel::hr()->table('hr_payroll_adjustments')->insert(['run_id' => $runId, 'employee_id' => $empId, 'kind' => $kind, 'label' => $label,
                'amount' => $amount, 'taxable' => $taxable ? 1 : 0, 'reason' => $reason, 'created_by' => $c->accountId(), 'created_at' => Kernel::now()]);
        });
        self::compute($runId);
        Audit::log('payroll.adjust', ['employee_id' => $empId, 'entity' => 'hr_payroll_runs', 'entity_id' => $runId,
            'summary' => Fmt::first((string) $c->auth['display_name']) . ' added ' . ($kind === 'addition' ? '+' : '−') . Fmt::rupees($amount) . ' (' . $label . '): ' . $reason]);
        return ['message' => 'Added and recalculated.'];
    }

    public static function removeAdjustment(Ctx $c, int $adjId): array
    {
        $a = Kernel::hr()->table('hr_payroll_adjustments')->where('id', $adjId)->first();
        if (!$a) {
            throw ApiError::notFound('That change');
        }
        if (self::loadRun((int) $a->run_id)->status !== 'draft') {
            throw ApiError::conflict('Locked payroll can\'t change.');
        }
        Kernel::hr()->transaction(function () use ($a, $adjId) {
            self::draftForUpdate((int) $a->run_id);
            Kernel::hr()->table('hr_payroll_adjustments')->where('id', $adjId)->delete();
        });
        self::compute((int) $a->run_id);
        Audit::log('payroll.adjust_remove', ['employee_id' => (int) $a->employee_id, 'entity' => 'hr_payroll_runs', 'entity_id' => (int) $a->run_id,
            'summary' => Fmt::first((string) $c->auth['display_name']) . ' removed ' . $a->label . ' (' . Fmt::rupees((int) $a->amount) . ')']);
        return ['message' => 'Removed and recalculated.'];
    }

    /** Hold or release one person's pay. On a draft: kept out of the bank file. On a locked run: release to pay them separately. */
    public static function hold(Ctx $c, int $lineId): array
    {
        $l = Kernel::hr()->table('hr_payroll_lines')->where('id', $lineId)->first();
        if (!$l) {
            throw ApiError::notFound('That line');
        }
        $r = self::loadRun((int) $l->run_id);
        $on = (bool) $c->raw('hold');
        $reason = $on ? (string) $c->str('reason', 255, true, 'A reason') : null;
        $r = Kernel::hr()->transaction(function () use ($lineId, $on, $reason) {
            $l = Kernel::hr()->table('hr_payroll_lines')->where('id', $lineId)->lockForUpdate()->first();
            $r = Kernel::hr()->table('hr_payroll_runs')->where('id', $l->run_id)->lockForUpdate()->first();
            if ($l->paid_at) {
                throw ApiError::conflict('Already paid.');
            }
            if ($r->status === 'paid' || ($r->status === 'locked' && $on)) {
                throw ApiError::conflict('Holds are set before locking. A locked run can only release a hold.');
            }
            Kernel::hr()->table('hr_payroll_lines')->where('id', $lineId)->update(['held' => $on ? 1 : 0, 'hold_reason' => $reason]);
            return $r;
        });
        if ($r->status === 'draft') {
            self::compute((int) $r->id);
        }
        $name = json_decode((string) $l->detail, true)['name'] ?? 'them';
        Audit::log($on ? 'payroll.hold' : 'payroll.release', ['employee_id' => (int) $l->employee_id, 'entity' => 'hr_payroll_runs', 'entity_id' => (int) $r->id,
            'summary' => Fmt::first((string) $c->auth['display_name']) . ($on ? ' held ' : ' released ') . $name . "'s pay" . ($reason ? ': ' . $reason : '')]);
        return ['message' => $on ? Fmt::first($name) . "'s pay is held. It stays out of the bank file." : Fmt::first($name) . "'s pay is released."];
    }

    // ---- lock ------------------------------------------------------------------------------

    public static function lock(Ctx $c, int $id): array
    {
        $r = self::loadRun($id);
        if ($r->status !== 'draft') {
            throw ApiError::conflict('Already locked.');
        }
        self::compute($id); // the latest figures, and the checks re-run
        $d = self::get($c, $id);
        if ($d['run']['blocking'] > 0) {
            throw ApiError::conflict(Fmt::plural($d['run']['blocking'], 'problem') . ' still need sorting before this can be locked (shown in red).');
        }
        $db = Kernel::hr();
        $company = Settings::get('company');
        $seqKey = 'payslip_seq.' . $r->month;
        $db->table('hr_settings')->insertOrIgnore(['key' => $seqKey, 'value' => '0', 'updated_at' => Kernel::now()]);
        $ytd = self::paidThisYear(array_column($d['lines'], 'employee_id'), Tax::taxYear($r->month), $id);
        $db->transaction(function () use ($db, $c, $r, $id, $company, $seqKey, $ytd) {
            $cur = $db->table('hr_payroll_runs')->where('id', $id)->lockForUpdate()->first();
            if (!$cur || $cur->status !== 'draft') {
                throw ApiError::conflict('Someone else locked this a moment ago.');
            }
            if ($r->kind === 'regular' && !$db->table('hr_attendance_months')->where('month', $r->month)->where('status', 'locked')->exists()) {
                throw ApiError::conflict('Attendance for ' . self::monthName($r->month) . ' was unlocked. Lock it again first.');
            }
            $seqRow = $db->table('hr_settings')->where('key', $seqKey)->lockForUpdate()->first();
            $seq = (int) json_decode((string) $seqRow->value);
            foreach ($db->table('hr_payroll_lines')->where('run_id', $id)->orderBy('employee_id')->get() as $l) {
                $det = json_decode((string) $l->detail, true) ?: [];
                foreach ($det['advances'] ?? [] as $a) {
                    $db->table('hr_advance_recoveries')->insert(['advance_id' => $a['id'], 'run_id' => $id, 'amount' => $a['amount']]);
                }
                $seq++;
                $y = $ytd[(int) $l->employee_id] ?? ['salary' => 0, 'tds' => 0];
                $e = $db->table('hr_employees')->where('id', $l->employee_id)->first(['full_name', 'employee_code', 'designation', 'date_joined', 'centre']);
                $pan = $db->table('hr_employee_secrets')->where('employee_id', $l->employee_id)->where('field', 'pan')->value('hint');
                do {
                    $code = '';
                    for ($i = 0; $i < 12; $i++) {
                        $code .= Letters::CODE_ALPHABET[random_int(0, strlen(Letters::CODE_ALPHABET) - 1)];
                    }
                } while ($db->table('hr_payslips')->where('verify_code', $code)->exists() || $db->table('hr_letters')->where('verify_code', $code)->exists());
                $db->table('hr_payslips')->insert([
                    'run_id' => $id, 'line_id' => $l->id, 'employee_id' => $l->employee_id,
                    'number' => sprintf('TT/PAY/%s/%04d', $r->month, $seq), 'verify_code' => $code,
                    'data' => json_encode([
                        'kind' => $r->kind, 'month' => $r->month, 'month_name' => self::monthName($r->month), 'pay_date' => $r->pay_date,
                        'letterhead' => $company,
                        'person' => ['name' => $e->full_name, 'code' => $e->employee_code, 'designation' => $e->designation,
                            'centre' => Settings::centreName($e->centre), 'joined' => $e->date_joined, 'pan' => $pan ? '••••••' . $pan : null],
                        'rate' => $det['rate'] ?? 0, 'days_in_month' => $det['days_in_month'] ?? 0, 'paid_days' => $det['paid_days'] ?? 0,
                        'unpaid_days' => $det['unpaid_days'] ?? 0, 'earnings' => $det['earnings'] ?? [], 'deductions' => $det['deductions'] ?? [],
                        'gross' => (int) $l->earned + (int) $l->incentive + (int) $l->additions, 'total_deductions' => (int) $l->deductions + (int) $l->tds,
                        'net' => (int) $l->net, 'regime' => $det['tax']['regime'] ?? 'new', 'bank' => $det['bank_hint'] ?? null,
                        'ytd' => ['taxable' => $y['salary'] + (int) ($det['taxable'] ?? 0), 'tds' => $y['tds'] + (int) $l->tds],
                    ], JSON_UNESCAPED_UNICODE),
                    'created_at' => Kernel::now(),
                ]);
            }
            $db->table('hr_settings')->where('key', $seqKey)->update(['value' => (string) $seq, 'updated_at' => Kernel::now()]);
            // Advances recovered in full are closed.
            foreach ($db->table('hr_advances')->where('status', 'active')->get(['id', 'amount']) as $adv) {
                $got = (int) $db->table('hr_advance_recoveries as x')->join('hr_payroll_runs as r', 'r.id', '=', 'x.run_id')
                    ->where('x.advance_id', $adv->id)->where(fn($q) => $q->whereIn('r.status', ['locked', 'paid'])->orWhere('r.id', $id))->sum('x.amount');
                if ($got >= (int) $adv->amount) {
                    $db->table('hr_advances')->where('id', $adv->id)->update(['status' => 'closed', 'closed_at' => Kernel::now()]);
                }
            }
            $db->table('hr_payroll_runs')->where('id', $id)->update(['status' => 'locked', 'locked_by' => $c->accountId(), 'locked_at' => Kernel::now(), 'updated_at' => Kernel::now()]);
        });
        $t = $d['run']['totals'];
        Audit::log('payroll.lock', ['entity' => 'hr_payroll_runs', 'entity_id' => $id, 'detail' => $t,
            'summary' => Fmt::first((string) $c->auth['display_name']) . ' locked ' . ($r->kind === 'regular' ? 'payroll for ' . self::monthName($r->month) : 'a final settlement')
                . ': ' . Fmt::rupees((int) ($t['net'] ?? 0)) . ' to ' . Fmt::plural((int) ($t['people'] ?? 0), 'person', 'people')]);
        return ['message' => 'Locked. Payslips are made; download the ICICI file to pay.'];
    }

    // ---- the ICICI bank file ------------------------------------------------------------

    /**
     * ICICI CIB bulk upload, PAB-SAL format (from ICICI's own converter,
     * "File Format for Bulk upload in CIB" v3.3): a pipe-separated text file,
     * each line ending in ^:
     *   FHR|lines|MM/DD/YYYY|reference|total|INR|debit account|0011^
     *   MDR|debit account|0011|corporate id|total|INR|remarks|ICIC0000011|WIB^
     *   MCW|ICICI account (12 digits)|0011|name|amount|INR|remarks|ICIC0000011|WIB^   (within ICICI)
     *   MCO|account|0011|name|amount|INR|remarks|NFT|IFSC^                           (other banks, NEFT)
     * Lines = people + 1. Names: letters and spaces, up to 32. Remarks up to 30.
     */
    public static function bankFile(Ctx $c, int $id): array
    {
        $r = self::loadRun($id);
        if ($r->status === 'draft') {
            throw ApiError::conflict('Lock the payroll first, so the bank file matches the payslips.');
        }
        Auth::reauth($c->auth, self::typedPassword($c));
        $s = self::settings();
        $corp = $s['icici']['corp_id'];
        $debit = $s['icici']['debit_account'];
        if (!$corp || !$debit) {
            throw ApiError::conflict('Add the ICICI Corporate ID and salary account number in Payroll → Settings first.');
        }
        $only = $c->has('employee_id') ? $c->intVal('employee_id', 1, PHP_INT_MAX) : null;
        // Someone already in a downloaded file is left out of the next one, so a second
        // download can't pay them twice. "Again" (with a reason) is for an upload ICICI rejected.
        $again = (bool) $c->raw('again');
        $againWhy = $again ? (string) $c->str('reason', 255, true, 'A reason') : null;
        $db = Kernel::hr();
        $file = $db->transaction(function () use ($db, $r, $id, $only, $again, $corp, $debit) {
            $db->table('hr_payroll_runs')->where('id', $id)->lockForUpdate()->first();
            $q = $db->table('hr_payroll_lines')->where('run_id', $id)->whereNull('paid_at')->where('net', '>', 0)->where('held', 0);
            if ($only) {
                $q->where('employee_id', $only);
            }
            $all = $q->orderBy('employee_id')->get();
            $lines = $again ? $all : $all->whereNull('bank_file_at')->values();
            if ($lines->isEmpty()) {
                if ($all->isNotEmpty()) {
                    $when = $all->max('bank_file_at');
                    throw new ApiError(409, 'already_in_file', ($only ? 'They are' : 'Everyone left to pay is') . ' already in an ICICI file downloaded on '
                        . Fmt::day($when) . ' at ' . date('g:i a', strtotime($when)) . '. Download it again only if ICICI rejected that upload; otherwise mark it paid.');
                }
                throw ApiError::conflict('Nobody left to pay in this run.');
            }
            $file = self::buildBankFile($r, $lines, $corp, $debit, $only);
            $db->table('hr_payroll_lines')->whereIn('id', $lines->pluck('id')->all())->update(['bank_file_at' => Kernel::now()]);
            return $file;
        });
        Audit::log('payroll.bank_file', ['entity' => 'hr_payroll_runs', 'entity_id' => $id,
            'detail' => ['people' => $file['people'], 'total' => $file['total'], 'only' => $only, 'again' => $againWhy],
            'summary' => Fmt::first((string) $c->auth['display_name']) . ($again ? ' downloaded the ICICI file again (' . $againWhy . ')' : ' downloaded the ICICI file')
                . ' for ' . self::monthName($r->month) . ': ' . Fmt::rupees($file['total']) . ' to ' . Fmt::plural($file['people'], 'person', 'people')]);
        return $file;
    }

    /** The PAB-SAL text for these lines (see bankFile). Refuses the whole file over one bad account. */
    private static function buildBankFile(object $r, $lines, string $corp, string $debit, ?int $only): array
    {
        $secrets = Kernel::hr()->table('hr_employee_secrets')->whereIn('employee_id', $lines->pluck('employee_id')->all())
            ->whereIn('field', ['bank_account', 'bank_ifsc', 'bank_holder'])->get()->groupBy('employee_id');
        $remark = self::clean('Salary ' . date('M Y', strtotime($r->month . '-01')), 30);
        $rows = [];
        $total = 0;
        $problems = [];
        foreach ($lines as $l) {
            $det = json_decode((string) $l->detail, true) ?: [];
            $sec = $secrets[$l->employee_id] ?? collect();
            $get = function (string $f) use ($sec) {
                $row = $sec->firstWhere('field', $f);
                return $row ? trim(Vault::decrypt($row->ciphertext)) : '';
            };
            $acct = preg_replace('/\s+/', '', $get('bank_account'));
            $ifsc = strtoupper(preg_replace('/\s+/', '', $get('bank_ifsc')));
            $name = self::clean($get('bank_holder') ?: ($det['name'] ?? ''), 32);
            $amount = (int) $l->net;
            if ($problem = self::bankProblem($acct, $ifsc)) {
                $problems[] = ($det['name'] ?? '?') . ': ' . rtrim($problem, '.');
                continue;
            }
            $rows[] = str_starts_with($ifsc, 'ICIC')
                ? "MCW|{$acct}|0011|{$name}|{$amount}|INR|{$remark}|ICIC0000011|WIB^"
                : "MCO|{$acct}|0011|{$name}|{$amount}|INR|{$remark}|NFT|{$ifsc}^";
            $total += $amount;
        }
        if ($problems) {
            throw ApiError::conflict('Fix these bank details first: ' . implode('; ', $problems) . '.');
        }
        $date = max(date('Y-m-d'), (string) ($r->pay_date ?: date('Y-m-d')));
        $ref = self::clean(($r->kind === 'regular' ? 'TRIO SAL ' : 'TRIO FNF ') . $r->month . ($only ? ' ' . $only : ''), 30);
        $fileRemark = self::clean('Trio salaries ' . date('M Y', strtotime($r->month . '-01')), 30);
        $out = array_merge([
            'FHR|' . (count($rows) + 1) . '|' . date('m/d/Y', strtotime($date)) . '|' . $ref . '|' . $total . '|INR|' . $debit . '|0011^',
            'MDR|' . $debit . '|0011|' . $corp . '|' . $total . '|INR|' . $fileRemark . '|ICIC0000011|WIB^',
        ], $rows);
        return ['filename' => 'Trio_' . ($r->kind === 'regular' ? 'salary' : 'settlement') . '_' . $r->month . ($only ? '_' . $only : '') . '_ICICI.txt',
                'content' => implode("\r\n", $out) . "\r\n", 'people' => count($rows), 'total' => $total, 'pay_date' => $date];
    }

    /** Why ICICI would refuse this account, or null. ICICI accounts: 12 digits; others: valid IFSC (5th character 0) and a numeric account. */
    private static function bankProblem(string $acct, string $ifsc): ?string
    {
        $acct = preg_replace('/\s+/', '', $acct);
        $ifsc = strtoupper(preg_replace('/\s+/', '', $ifsc));
        if (str_starts_with($ifsc, 'ICIC')) {
            return preg_match('/^\d{12}$/', $acct) ? null : 'ICICI account numbers are 12 digits; this one isn\'t.';
        }
        if (!preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $ifsc)) {
            return 'The IFSC isn\'t valid (11 characters, the 5th is 0).';
        }
        return preg_match('/^\d{6,34}$/', $acct) ? null : 'The account number should be 6 to 34 digits.';
    }

    /** Letters, digits and spaces only (ICICI refuses special characters), trimmed to a length. */
    private static function clean(string $s, int $max): string
    {
        $s = preg_replace('/[^A-Za-z0-9 ]+/', ' ', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s) ?? '';
        return mb_substr(trim(preg_replace('/\s+/', ' ', $s) ?? ''), 0, $max);
    }

    private static function typedPassword(Ctx $c): ?string
    {
        $p = $c->raw('password');
        return is_string($p) && $p !== '' ? mb_substr($p, 0, 200) : null;
    }

    // ---- paid ------------------------------------------------------------------------

    /** The bank confirmed: mark paid, publish the payslips, tell each person. */
    public static function markPaid(Ctx $c, int $id): array
    {
        $r = self::loadRun($id);
        if ($r->status === 'draft') {
            throw ApiError::conflict('Lock it first.');
        }
        $date = $c->date('paid_on', true, 'The date it was paid');
        if ($date > date('Y-m-d') || $date < substr((string) $r->locked_at, 0, 10)) {
            throw ApiError::invalid('Pick the day ICICI paid it: not before the run was locked, and not in the future.', ['paid_on' => 'Check the date.']);
        }
        $ref = $c->str('bank_ref', 60);
        $only = $c->has('employee_id') ? $c->intVal('employee_id', 1, PHP_INT_MAX) : null;
        $db = Kernel::hr();
        $q = $db->table('hr_payroll_lines')->where('run_id', $id)->whereNull('paid_at')->where('held', 0);
        if ($only) {
            $q->where('employee_id', $only);
        }
        $lines = $q->get(['id', 'employee_id', 'net']);
        if ($lines->isEmpty()) {
            throw ApiError::conflict('Nobody left to mark as paid.');
        }
        $now = Kernel::now();
        $paidAt = $date . ' ' . date('H:i:s');
        $db->transaction(function () use ($db, $c, $id, $lines, $paidAt, $now, $ref) {
            $db->table('hr_payroll_lines')->whereIn('id', $lines->pluck('id')->all())->update(['paid_at' => $paidAt]);
            $db->table('hr_payslips')->whereIn('line_id', $lines->pluck('id')->all())->update(['published_at' => $now]);
            // Paid once nobody is left unpaid (held people paid later keep it "locked" until then).
            $left = $db->table('hr_payroll_lines')->where('run_id', $id)->whereNull('paid_at')->count();
            $db->table('hr_payroll_runs')->where('id', $id)->update(['paid_by' => $c->accountId(), 'paid_at' => $paidAt, 'updated_at' => $now]
                + ($left === 0 ? ['status' => 'paid'] : []) + ($ref ? ['bank_ref' => $ref] : []));
        });
        $slips = $db->table('hr_payslips')->whereIn('line_id', $lines->pluck('id')->all())->get(['id', 'employee_id']);
        foreach ($slips as $sl) {
            Notify::employee((int) $sl->employee_id, 'payslip', 'Your payslip for ' . self::monthName($r->month) . ' is ready', 'Tap to see each line and why.', '#/me/pay/' . $sl->id);
        }
        Audit::log('payroll.paid', ['entity' => 'hr_payroll_runs', 'entity_id' => $id, 'detail' => ['people' => $lines->count(), 'ref' => $ref, 'date' => $date],
            'summary' => Fmt::first((string) $c->auth['display_name']) . ' marked ' . Fmt::plural($lines->count(), 'person', 'people') . ' paid for ' . self::monthName($r->month)]);
        return ['message' => 'Marked paid. ' . Fmt::plural($slips->count(), 'payslip') . ' published; each person is told.'];
    }

    /** Salary register for the CA (owner only, logged). */
    public static function register(Ctx $c, int $id): array
    {
        $r = self::loadRun($id);
        if ($r->status === 'draft') {
            throw ApiError::conflict('Lock it first.');
        }
        $rows = [['Code', 'Name', 'Centre', 'Monthly salary', 'Days in month', 'Paid days', 'Basic', 'HRA', 'Special', 'Incentive', 'Other additions',
                  'Gross', 'TDS', 'Advance recovery', 'Other deductions', 'PSDT', 'Net', 'Held', 'Paid on', 'Payslip']];
        $slips = Kernel::hr()->table('hr_payslips')->where('run_id', $id)->pluck('number', 'line_id');
        foreach (Kernel::hr()->table('hr_payroll_lines')->where('run_id', $id)->orderBy('employee_id')->get() as $l) {
            $d = json_decode((string) $l->detail, true) ?: [];
            $by = fn(array $list, string $prefix) => array_sum(array_map(fn($x) => str_starts_with($x['key'], $prefix) ? (int) $x['amount'] : 0, $list));
            $earn = $d['earnings'] ?? [];
            $ded = $d['deductions'] ?? [];
            $rows[] = [$d['code'] ?? '', $d['name'] ?? '', $d['centre'] ?? '', $d['rate'] ?? 0, $d['days_in_month'] ?? 0, $d['paid_days'] ?? 0,
                $by($earn, 'basic'), $by($earn, 'hra'), $by($earn, 'special'), (int) $l->incentive, (int) $l->additions,
                (int) $l->earned + (int) $l->incentive + (int) $l->additions, (int) $l->tds, $by($ded, 'advance'), $by($ded, 'adj'), $by($ded, 'psdt'),
                (int) $l->net, $l->held ? 'Held: ' . $l->hold_reason : '', $l->paid_at ? substr($l->paid_at, 0, 10) : '', $slips[$l->id] ?? ''];
        }
        $fh = fopen('php://temp', 'r+');
        foreach ($rows as $row) {
            fputcsv($fh, array_map(fn($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v, $row));
        }
        rewind($fh);
        $csv = stream_get_contents($fh);
        Audit::log('payroll.register', ['entity' => 'hr_payroll_runs', 'entity_id' => $id,
            'summary' => Fmt::first((string) $c->auth['display_name']) . ' exported the salary register for ' . self::monthName($r->month)]);
        return ['filename' => 'Trio_salary_register_' . $r->month . ($r->kind === 'settlement' ? '_settlement_' . $r->employee_id : '') . '.csv', 'content' => "\xEF\xBB\xBF" . $csv];
    }

    // ---- final settlements -------------------------------------------------------------

    /** People who left (or are leaving within a week) without a locked settlement, with the 2-working-day deadline. */
    public static function settlementsDue(): array
    {
        $db = Kernel::hr();
        $soon = date('Y-m-d', strtotime('+7 days'));
        $rows = $db->table('hr_employees')->whereNotNull('last_working_day')->where('last_working_day', '<=', $soon)
            ->where('last_working_day', '>=', date('Y-m-d', strtotime('-60 days')))->whereIn('status', ['notice', 'left'])->orderBy('last_working_day')
            ->get(['id', 'full_name', 'last_working_day']);
        $out = [];
        foreach ($rows as $e) {
            $run = $db->table('hr_payroll_runs')->where('kind', 'settlement')->where('employee_id', $e->id)->first(['id', 'status']);
            $inRegular = $db->table('hr_payroll_lines as l')->join('hr_payroll_runs as r', 'r.id', '=', 'l.run_id')
                ->where('r.kind', 'regular')->where('r.month', substr($e->last_working_day, 0, 7))->whereIn('r.status', ['locked', 'paid'])
                ->where('l.employee_id', $e->id)->exists();
            if (($run && $run->status === 'paid') || $inRegular) {
                continue;
            }
            $due = self::addWorkingDays($e->last_working_day, (int) (self::lawValue('final_settlement_days') ?? 2));
            $out[] = ['employee_id' => (int) $e->id, 'name' => $e->full_name, 'last_day' => $e->last_working_day, 'due' => $due,
                'late' => date('Y-m-d') > $due, 'run_id' => $run ? (int) $run->id : null, 'run_status' => $run->status ?? null];
        }
        return $out;
    }

    /** Working days after a date (Sundays skipped). */
    private static function addWorkingDays(string $day, int $n): string
    {
        $d = $day;
        while ($n > 0) {
            $d = Clock::addDays($d, 1);
            if (date('N', strtotime($d)) !== '7') {
                $n--;
            }
        }
        return $d;
    }

    private static function lawValue(string $key): ?float
    {
        try {
            $v = Kernel::hr()->table('hr_law_values')->where('key', $key)->where('status', 'in_force')
                ->where('effective_from', '<=', date('Y-m-d'))->orderByDesc('effective_from')->value('value');
            return $v === null ? null : (float) $v;
        } catch (\Throwable) {
            return null;
        }
    }

    public static function startSettlement(Ctx $c): array
    {
        $empId = $c->intVal('employee_id', 1, PHP_INT_MAX, true, 'The person');
        $e = Kernel::hr()->table('hr_employees')->where('id', $empId)->first(['id', 'full_name', 'last_working_day', 'status']);
        if (!$e || !$e->last_working_day) {
            throw ApiError::conflict('Set their last working day on their record first (People → their record → Leaving).');
        }
        $ym = substr($e->last_working_day, 0, 7);
        if (Kernel::hr()->table('hr_payroll_runs')->where('run_key', 'settlement:' . $ym . ':' . $empId)->exists()) {
            throw ApiError::conflict('A final settlement for ' . Fmt::first($e->full_name) . ' is already started. Open it from the list.');
        }
        $inRegular = Kernel::hr()->table('hr_payroll_lines as l')->join('hr_payroll_runs as r', 'r.id', '=', 'l.run_id')
            ->where('r.kind', 'regular')->where('r.month', $ym)->whereIn('r.status', ['locked', 'paid'])->where('l.employee_id', $empId)->exists();
        if ($inRegular) {
            throw ApiError::conflict(Fmt::first($e->full_name) . ' was already paid for ' . self::monthName($ym) . ' in the monthly payroll.');
        }
        $id = Kernel::hr()->table('hr_payroll_runs')->insertGetId([
            'run_key' => 'settlement:' . $ym . ':' . $empId, 'kind' => 'settlement', 'month' => $ym, 'employee_id' => $empId,
            'status' => 'draft', 'pay_date' => self::addWorkingDays($e->last_working_day, 2),
            'prepared_by' => $c->accountId(), 'prepared_at' => Kernel::now(), 'created_at' => Kernel::now(), 'updated_at' => Kernel::now(),
        ]);
        self::compute($id);
        Audit::log('payroll.settlement', ['employee_id' => $empId, 'entity' => 'hr_payroll_runs', 'entity_id' => $id,
            'summary' => Fmt::first((string) $c->auth['display_name']) . ' started the final settlement for ' . $e->full_name]);
        return ['id' => $id, 'message' => 'Final settlement for ' . Fmt::first($e->full_name) . ' is ready to review.'];
    }

    /** Paid days for someone leaving, from the live attendance (their last month to the last working day). */
    private static function settlementDays(int $empId): array
    {
        $e = Kernel::hr()->table('hr_employees')->where('id', $empId)->first();
        $from = substr($e->last_working_day, 0, 7) . '-01';
        $people = Attendance::people($from, $e->last_working_day, [$empId]);
        $grid = $people ? (Attendance::grid($people, $from, $e->last_working_day)[$empId] ?? []) : [];
        $sum = Attendance::summarize($grid);
        $issues = [];
        if ($sum['to_come'] > 0) {
            $issues[] = ['code' => 'attendance', 'block' => true, 'text' => 'Their last working day (' . Fmt::day($e->last_working_day) . ') has not passed yet, so '
                . Fmt::plural($sum['to_come'], 'day') . ' cannot be counted. Recalculate and lock after it.'];
        }
        if ($sum['no_punch'] > 0 || $sum['pending_fix'] > 0 || $sum['pending_leave'] > 0) {
            $issues[] = ['code' => 'attendance', 'block' => true, 'text' => 'Attendance up to the last day isn\'t settled: '
                . implode(', ', array_filter([$sum['no_punch'] ? Fmt::plural($sum['no_punch'], 'day') . ' with no punch' : null,
                    $sum['pending_fix'] ? Fmt::plural($sum['pending_fix'], 'punch fix') . ' waiting' : null,
                    $sum['pending_leave'] ? Fmt::plural($sum['pending_leave'], 'leave day') . ' waiting' : null])) . '. Sort it in Attendance, then recalculate.'];
        }
        return ['paid' => (float) $sum['paid_days'], 'unpaid' => (float) $sum['unpaid_days'], 'employed' => (int) $sum['days'], 'issues' => $issues];
    }

    // ---- payslips ---------------------------------------------------------------------

    /** A payslip for the print page and the app. Staff: only their own, published. */
    public static function payslip(int $id, ?int $employeeId = null): array
    {
        $q = Kernel::hr()->table('hr_payslips')->where('id', $id);
        if ($employeeId !== null) {
            $q->where('employee_id', $employeeId)->whereNotNull('published_at');
        }
        $p = $q->first();
        if (!$p) {
            throw ApiError::notFound('That payslip');
        }
        $d = json_decode((string) $p->data, true) ?: [];
        Audit::log('payslip.view', ['employee_id' => (int) $p->employee_id, 'entity' => 'hr_payslips', 'entity_id' => $id]);
        $host = rtrim((string) Kernel::env('HR_APP_URL', ''), '/');
        return ['payslip' => $d + ['id' => (int) $p->id, 'number' => $p->number, 'verify_code' => Letters::groupCode($p->verify_code),
            'verify_url' => $host . '/#/verify/' . Letters::groupCode($p->verify_code), 'published' => (bool) $p->published_at]];
    }

    /** Staff Pay tab: their published payslips, this year's totals, advances and tax set-up. */
    public static function mine(Ctx $c): array
    {
        $emp = (int) $c->employeeId();
        $db = Kernel::hr();
        $slips = $db->table('hr_payslips')->where('employee_id', $emp)->whereNotNull('published_at')->orderByDesc('id')->limit(36)->get(['id', 'number', 'data', 'published_at']);
        $ty = Tax::taxYear(date('Y-m'));
        $ytd = ['taxable' => 0, 'tds' => 0, 'net' => 0];
        $list = [];
        foreach ($slips as $s) {
            $d = json_decode((string) $s->data, true) ?: [];
            $list[] = ['id' => (int) $s->id, 'month' => $d['month_name'] ?? '', 'kind' => $d['kind'] ?? 'regular', 'net' => (int) ($d['net'] ?? 0), 'number' => $s->number];
            if (Tax::taxYear($d['month'] ?? '2000-01') === $ty) {
                $ytd['net'] += (int) ($d['net'] ?? 0);
            }
        }
        // Only what they have been paid (published payslips), not a run still waiting for the bank.
        foreach ($db->table('hr_payroll_lines as l')->join('hr_payslips as s', 's.line_id', '=', 'l.id')->join('hr_payroll_runs as r', 'r.id', '=', 'l.run_id')
            ->where('l.employee_id', $emp)->whereNotNull('s.published_at')->whereIn('r.month', Tax::yearMonths($ty))->get(['l.tds', 'l.detail']) as $l) {
            $ytd['taxable'] += (int) (json_decode((string) $l->detail, true)['taxable'] ?? 0);
            $ytd['tds'] += (int) $l->tds;
        }
        $profile = $db->table('hr_tax_profiles')->where('employee_id', $emp)->where('tax_year', $ty)->first();
        $adv = array_map(fn($a) => ['amount' => $a['amount'], 'monthly' => $a['monthly'], 'remaining' => $a['remaining'], 'reason' => $a['reason']],
            self::openAdvances($emp, '9999-12', 0));
        return ['payslips' => $list, 'tax_year' => $ty, 'ytd' => $ytd, 'regime' => $profile->regime ?? 'new', 'advances' => $adv];
    }

    // ---- tax profiles -------------------------------------------------------------------

    public static function taxProfiles(Ctx $c): array
    {
        $ty = (string) ($c->query('year', 7) ?: Tax::taxYear(date('Y-m')));
        if (!preg_match('/^\d{4}-\d{2}$/', $ty)) {
            throw ApiError::invalid('Pick a tax year.');
        }
        $db = Kernel::hr();
        $people = $db->table('hr_employees')->whereIn('status', ['joining', 'active', 'notice'])->orderBy('full_name')->get(['id', 'full_name', 'pan_status']);
        $prof = $db->table('hr_tax_profiles')->where('tax_year', $ty)->get()->keyBy('employee_id');
        $paid = self::paidThisYear($people->pluck('id')->all(), $ty, 0);
        $pans = $db->table('hr_employee_secrets')->where('field', 'pan')->pluck('hint', 'employee_id');
        return ['tax_year' => $ty, 'table' => self::taxTable(), 'people' => $people->map(function ($p) use ($prof, $paid, $pans) {
            $x = $prof[$p->id] ?? null;
            return ['id' => (int) $p->id, 'name' => $p->full_name, 'pan' => isset($pans[$p->id]) ? '••' . $pans[$p->id] : null, 'pan_status' => $p->pan_status,
                'regime' => $x->regime ?? 'new', 'prev_income' => (int) ($x->prev_income ?? 0), 'prev_tds' => (int) ($x->prev_tds ?? 0),
                'deductions' => (int) ($x->deductions ?? 0), 'note' => $x->note ?? null,
                'paid_taxable' => (int) ($paid[$p->id]['salary'] ?? 0), 'paid_tds' => (int) ($paid[$p->id]['tds'] ?? 0)];
        })->all()];
    }

    public static function saveTaxProfile(Ctx $c, int $empId): array
    {
        $ty = (string) $c->str('tax_year', 7, true, 'The tax year');
        if (!preg_match('/^\d{4}-\d{2}$/', $ty) || !Kernel::hr()->table('hr_employees')->where('id', $empId)->exists()) {
            throw ApiError::invalid('Pick a person and a tax year.');
        }
        $v = ['regime' => (string) $c->pick('regime', ['new', 'old'], true, 'Regime'),
              'prev_income' => $c->intVal('prev_income', 0, 100000000, false, 'Previous employer income') ?? 0,
              'prev_tds' => $c->intVal('prev_tds', 0, 100000000, false, 'Previous employer TDS') ?? 0,
              'deductions' => $c->intVal('deductions', 0, 10000000, false, 'Declared deductions') ?? 0,
              'note' => $c->str('note', 255), 'updated_by' => $c->accountId(), 'updated_at' => Kernel::now()];
        Kernel::hr()->table('hr_tax_profiles')->updateOrInsert(['employee_id' => $empId, 'tax_year' => $ty], $v);
        Audit::log('tax.profile', ['employee_id' => $empId, 'entity' => 'hr_tax_profiles', 'detail' => ['tax_year' => $ty, 'regime' => $v['regime']],
            'summary' => Fmt::first((string) $c->auth['display_name']) . ' updated a tax set-up for ' . $ty]);
        return ['message' => 'Saved. Draft payroll uses it when recalculated.'];
    }

    // ---- public verification ---------------------------------------------------------

    /** Letters::verify falls back to this for payslip codes. */
    public static function verifyPayslip(string $code): ?array
    {
        $p = Kernel::hr()->table('hr_payslips')->where('verify_code', $code)->whereNotNull('published_at')->first(['number', 'data', 'published_at']);
        if (!$p) {
            return null;
        }
        $d = json_decode((string) $p->data, true) ?: [];
        return ['found' => true, 'valid' => true, 'number' => $p->number, 'kind' => 'Payslip for ' . ($d['month_name'] ?? ''),
            'name' => $d['person']['name'] ?? '', 'issued_on' => date('j F Y', strtotime((string) $p->published_at)),
            'issuer' => $d['letterhead']['legal_name'] ?? '',
            'contact' => (string) (Settings::get('company')['hr_email'] ?? '') ?: null];
    }
}
