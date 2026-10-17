<?php

namespace App\Hr\Domain;

use App\Hr\Crm\CrmGateway;
use App\Hr\Http\ApiError;
use App\Hr\Http\Ctx;
use App\Hr\Kernel;
use App\Hr\Support\Audit;
use App\Hr\Support\Clock;
use App\Hr\Support\Fmt;
use App\Hr\Support\Notify;
use Throwable;

/**
 * Attendance from punches, and nothing else (John, 16 Oct: "all it matters is
 * punch in and punch out. no assumptions").
 *
 * A person's punch is either their CRM clock-in (agents and the rest of the
 * floor) or the Trio HR button (everyone else, office network only). Each
 * day shows what happened: worked (with gross hours, breaks listed apart),
 * in now, punch-out missing, leave, holiday, or "No punch". There is no
 * "late" or "absent": an admin decides what a no-punch day means for pay at
 * month end, then locks the month, and payroll reads the lock.
 *
 * Days are work days: a punch belongs to the day it was punched IN on, with
 * the CRM's business-day rule (Clock::sessionNight), so a punch at 1 am
 * belongs to the evening before. One work day = one night of leave.
 */
final class Attendance
{
    /** A punch still open after this long is a missing punch-out. It is never guessed. */
    public const OPEN_LIMIT_HOURS = 16;

    /** Longest a day can be when someone tells us when they left. */
    private const MAX_HOURS = 16;

    public const MARK_LABELS = ['day_off' => 'Weekly off', 'unpaid' => 'Unpaid', 'worked' => 'Worked, punch missed'];

    public const VIA_LABELS = ['crm' => 'CRM clock-in', 'hr' => 'Trio HR punch button'];

    // ---- who and how ------------------------------------------------------------

    /** The work day now under way: a punch made now belongs to it. */
    public static function today(): string
    {
        return Clock::sessionNight(date('Y-m-d H:i:s'));
    }

    /** 'crm' when their CRM clock-in is the punch, else 'hr' (the Trio HR button). */
    public static function via(object $e, ?string $crmRole): string
    {
        if ($e->punch_via === 'hr' || !$e->crm_user_id) {
            return 'hr';
        }
        if ($e->punch_via === 'crm') {
            return 'crm';
        }
        return in_array($crmRole, CrmGateway::FLOOR_ROLES, true) ? 'crm' : 'hr';
    }

    /** How one person punches, with the reason (for the person page). */
    public static function viaFor(object $e): array
    {
        $role = $e->crm_user_id ? (CrmGateway::users([(int) $e->crm_user_id])[(int) $e->crm_user_id]->role ?? null) : null;
        $via = self::via($e, $role);
        return [
            'via' => $via, 'label' => self::VIA_LABELS[$via], 'set' => $e->punch_via,
            'why' => $e->punch_via ? 'Set by an admin' : ($via === 'crm' ? 'They clock in on the CRM (' . (CrmGateway::ROLE_LABELS[$role] ?? $role) . ')' : ($e->crm_user_id ? 'Their CRM role doesn\'t clock in' : 'No CRM login')),
            'can_crm' => (bool) $e->crm_user_id,
        ];
    }

    /**
     * People whose time at Trio overlaps from..to, keyed by id, each with
     * ->via set.
     */
    public static function people(string $from, string $to, ?array $ids = null, ?string $centre = null): array
    {
        $q = Kernel::hr()->table('hr_employees')
            ->where(fn($q) => $q->where('status', '!=', 'joining')->orWhere('date_joined', '<=', $to))
            ->where(fn($q) => $q->where('status', '!=', 'left')->orWhere('last_working_day', '>=', $from))
            ->where(fn($q) => $q->whereNull('date_joined')->orWhere('date_joined', '<=', $to))
            ->where(fn($q) => $q->whereNull('last_working_day')->orWhere('last_working_day', '>=', $from));
        if ($ids !== null) {
            $q->whereIn('id', $ids ?: [0]);
        }
        if ($centre !== null) {
            $q->where('centre', $centre);
        }
        $rows = $q->orderBy('full_name')->get(['id', 'full_name', 'preferred_name', 'crm_user_id', 'punch_via', 'centre', 'designation', 'status', 'date_joined', 'last_working_day']);
        $users = CrmGateway::users($rows->pluck('crm_user_id')->filter()->all());
        $out = [];
        foreach ($rows as $e) {
            $e->via = self::via($e, $e->crm_user_id ? ($users[(int) $e->crm_user_id]->role ?? null) : null);
            $out[(int) $e->id] = $e;
        }
        return $out;
    }

    // ---- the grid -------------------------------------------------------------------

    /**
     * Every day from..to for each person, as facts.
     *
     * @return array<int, array<string, array>> [employee_id][day] => day
     */
    public static function grid(array $people, string $from, string $to): array
    {
        if (!$people) {
            return [];
        }
        $db = Kernel::hr();
        $ids = array_keys($people);
        $crmOf = [];
        foreach ($people as $id => $e) {
            if ($e->via === 'crm') {
                $crmOf[$id] = (int) $e->crm_user_id;
            }
        }
        $nights = $crmOf ? CrmGateway::nights($from, $to, array_values($crmOf)) : [];
        $fixes = self::fixes($ids);
        $punches = [];
        foreach ($db->table('hr_punches')->whereIn('employee_id', $ids)->whereBetween('work_day', [$from, $to])->orderBy('punch_in')->get() as $p) {
            $punches[(int) $p->employee_id][$p->work_day][] = $p;
        }
        $types = collect(Leave::types())->keyBy('code');
        $leave = [];
        foreach ($db->table('hr_leave_days')->whereIn('employee_id', $ids)->whereBetween('shift_day', [$from, $to])->get() as $l) {
            $t = $types[$l->leave_type] ?? null;
            $leave[(int) $l->employee_id][$l->shift_day] = ['code' => $l->leave_type, 'name' => $t['name'] ?? 'Leave', 'paid' => (bool) ($t['paid'] ?? true), 'request_id' => (int) $l->request_id];
        }
        $pendingLeave = self::pendingLeave($ids);
        $holidays = self::holidayMap($from, $to);
        $marks = [];
        foreach ($db->table('hr_day_marks')->whereIn('employee_id', $ids)->whereBetween('work_day', [$from, $to])->get() as $m) {
            $marks[(int) $m->employee_id][$m->work_day] = ['mark' => $m->mark, 'label' => self::MARK_LABELS[$m->mark], 'note' => $m->note];
        }

        $today = self::today();
        $now = time();
        $range = Clock::range($from, $to);
        $out = [];
        foreach ($people as $id => $e) {
            $items = [];
            foreach (isset($crmOf[$id]) ? ($nights[$crmOf[$id]] ?? []) : [] as $d => $sessions) {
                foreach ($sessions as $s) {
                    $items[$d][] = self::fromSession($s, $fixes['s'][$s['id']] ?? null, $now);
                }
            }
            foreach ($punches[$id] ?? [] as $d => $list) {
                foreach ($list as $p) {
                    $items[$d][] = self::fromPunch($p, $fixes['p'][(int) $p->id] ?? null, $now);
                }
            }
            foreach ($range as $d) {
                $list = $items[$d] ?? [];
                usort($list, fn($a, $b) => strcmp($a['in'], $b['in']));
                $out[$id][$d] = self::day($e, $d, $today, $list, $leave[$id][$d] ?? null, self::holidayFor($holidays[$d] ?? [], $e->centre),
                    $marks[$id][$d] ?? null, $pendingLeave[$id][$d] ?? null);
            }
        }
        return $out;
    }

    private static function day(object $e, string $d, string $today, array $items, ?array $leave, ?string $holiday, ?array $mark, ?int $pendingLeave): array
    {
        $employed = (!$e->date_joined || $d >= $e->date_joined) && (!$e->last_working_day || $d <= $e->last_working_day);
        $minutes = 0;
        $breaks = 0;
        $missing = false;
        $open = false;
        foreach ($items as $i) {
            $minutes += $i['minutes'];
            $breaks += $i['break_mins'];
            $missing = $missing || $i['state'] === 'missing';
            $open = $open || $i['state'] === 'open';
        }
        $state = match (true) {
            !$employed && !$items => 'not_employed',
            $missing => 'missing_out',
            $open => 'in',
            (bool) $items => 'worked',
            $d > $today => 'upcoming',
            $leave !== null => 'leave',
            $holiday !== null => 'holiday',
            $d === $today => 'today',
            default => 'no_punch',
        };
        return [
            'day' => $d, 'state' => $state, 'minutes' => $minutes, 'break_mins' => $breaks, 'items' => $items,
            'leave' => $leave, 'holiday' => $holiday, 'mark' => $state === 'no_punch' ? $mark : null,
            'pending_leave' => $pendingLeave,
        ];
    }

    private static function fromSession(array $s, ?array $fix, int $now): array
    {
        $out = $s['clock_out'];
        $note = null;
        $state = 'closed';
        if ($fix && $fix['status'] === 'approved') {
            $out = $fix['out'];
            $note = $fix['by_admin'] ? 'Punch-out set by an admin' : 'Punch-out fixed (approved)';
        } elseif ($s['status'] === 'auto_closed') {
            $out = null;
            $state = 'missing'; // the CRM closed it by itself: the real time is unknown
        } elseif ($s['status'] === 'active' || $out === null) {
            $out = null;
            $state = $now - strtotime((string) $s['clock_in']) > self::OPEN_LIMIT_HOURS * 3600 ? 'missing' : 'open';
        }
        return [
            'kind' => 'crm', 'ref' => 's' . $s['id'], 'in' => (string) $s['clock_in'], 'out' => $out, 'state' => $state, 'note' => $note,
            'minutes' => $out ? max(0, intdiv(strtotime($out) - strtotime((string) $s['clock_in']), 60)) : 0,
            'running' => $state === 'open' ? max(0, intdiv($now - strtotime((string) $s['clock_in']), 60)) : null,
            'break_mins' => (int) $s['break_mins'], 'breaks' => array_map(fn($b) => ['type' => $b['type'], 'start' => $b['start'], 'end' => $b['end'], 'minutes' => $b['minutes']], $s['breaks']),
            'flagged' => (bool) $s['flagged'] && !$fix, 'by_admin' => $s['via'] === 'admin',
            'fix' => $fix && $fix['status'] === 'pending' ? ['id' => $fix['id'], 'out' => $fix['out']] : null,
        ];
    }

    private static function fromPunch(object $p, ?array $fix, int $now): array
    {
        $out = $p->punch_out;
        $state = 'closed';
        if ($out === null) {
            $state = $now - strtotime($p->punch_in) > self::OPEN_LIMIT_HOURS * 3600 ? 'missing' : 'open';
        }
        return [
            'kind' => 'hr', 'ref' => 'p' . $p->id, 'in' => $p->punch_in, 'out' => $out, 'state' => $state,
            'note' => match ($p->out_source) { 'fix' => 'Punch-out fixed (approved)', 'admin' => 'Punch-out set by an admin', default => null },
            'minutes' => $out ? max(0, intdiv(strtotime($out) - strtotime($p->punch_in), 60)) : 0,
            'running' => $state === 'open' ? max(0, intdiv($now - strtotime($p->punch_in), 60)) : null,
            'break_mins' => 0, 'breaks' => [], 'flagged' => false, 'by_admin' => false,
            'fix' => $fix && $fix['status'] === 'pending' ? ['id' => $fix['id'], 'out' => $fix['out']] : null,
        ];
    }

    /** Fix requests by what they fix: ['s' => [session_id => fix], 'p' => [punch_id => fix]]. Newest wins. */
    private static function fixes(array $employeeIds): array
    {
        $out = ['s' => [], 'p' => []];
        foreach (Kernel::hr()->table('hr_requests')->whereIn('employee_id', $employeeIds ?: [0])->where('type', 'attendance_fix')
            ->whereIn('status', ['pending', 'approved'])->orderBy('id')->get(['id', 'status', 'payload', 'decision']) as $r) {
            $p = json_decode((string) $r->payload, true) ?: [];
            $fix = ['id' => (int) $r->id, 'status' => $r->status, 'out' => $p['clock_out'] ?? null, 'by_admin' => !empty($p['by_admin'])];
            if (!empty($p['punch_id'])) {
                $out['p'][(int) $p['punch_id']] = $fix;
            } elseif (!empty($p['session_id'])) {
                // An approved fix is never replaced by a later pending one.
                if (($out['s'][(int) $p['session_id']]['status'] ?? '') !== 'approved') {
                    $out['s'][(int) $p['session_id']] = $fix;
                }
            }
        }
        return $out;
    }

    /** Leave asked for and not decided yet: [employee_id][day] => request id. */
    private static function pendingLeave(array $employeeIds): array
    {
        $out = [];
        foreach (Kernel::hr()->table('hr_requests')->whereIn('employee_id', $employeeIds ?: [0])->where('type', 'leave')->where('status', 'pending')
            ->get(['id', 'employee_id', 'payload']) as $r) {
            foreach ((json_decode((string) $r->payload, true)['days'] ?? []) as $d) {
                $out[(int) $r->employee_id][(string) $d] = (int) $r->id;
            }
        }
        return $out;
    }

    // ---- summaries -----------------------------------------------------------------

    /** One person's month in numbers, from their days. */
    public static function summarize(array $days): array
    {
        $s = ['days' => 0, 'worked' => 0, 'minutes' => 0, 'break_mins' => 0, 'paid_leave' => 0, 'unpaid_leave' => 0, 'holidays' => 0,
            'holiday_worked' => 0, 'leave_worked' => 0, 'no_punch' => 0, 'day_off' => 0, 'unpaid' => 0, 'worked_marked' => 0,
            'missing_out' => 0, 'in' => 0, 'pending_leave' => 0, 'pending_fix' => 0, 'to_come' => 0];
        foreach ($days as $d) {
            if ($d['state'] === 'not_employed') {
                continue;
            }
            foreach ($d['items'] as $i) {
                if ($i['fix']) {
                    $s['pending_fix']++;
                }
            }
            if ($d['pending_leave'] && $d['state'] !== 'leave') {
                $s['pending_leave']++;
            }
            if (in_array($d['state'], ['upcoming', 'today'], true)) {
                $s['to_come']++;
                continue;
            }
            $s['days']++;
            switch ($d['state']) {
                case 'worked':
                case 'in':
                case 'missing_out':
                    $s['worked']++;
                    $s['minutes'] += $d['minutes'];
                    $s['break_mins'] += $d['break_mins'];
                    if ($d['state'] === 'missing_out') $s['missing_out']++;
                    if ($d['state'] === 'in') $s['in']++;
                    if ($d['holiday']) $s['holiday_worked']++;
                    if ($d['leave']) $s['leave_worked']++;
                    break;
                case 'leave':
                    $d['leave']['paid'] ? $s['paid_leave']++ : $s['unpaid_leave']++;
                    break;
                case 'holiday':
                    $s['holidays']++;
                    break;
                case 'no_punch':
                    match ($d['mark']['mark'] ?? null) {
                        'day_off' => $s['day_off']++,
                        'unpaid' => $s['unpaid']++,
                        'worked' => $s['worked_marked']++,
                        default => $s['no_punch']++,
                    };
                    break;
            }
        }
        // Salary is for the month's days minus unpaid ones (the usual Indian rule), once every day is decided.
        $s['unpaid_days'] = $s['unpaid_leave'] + $s['unpaid'];
        $s['paid_days'] = $s['days'] - $s['unpaid_days'] - $s['no_punch'];
        return $s;
    }

    /** What still stands between this person's month and payroll. */
    public static function issues(object $e, array $days, array $sum, int $allowance): array
    {
        $out = [];
        foreach ($days as $d) {
            foreach ($d['items'] as $i) {
                if ($i['state'] === 'missing' && !$i['fix']) {
                    $out[] = ['kind' => 'missing_out', 'day' => $d['day'], 'ref' => $i['ref'], 'in' => $i['in'],
                        'text' => 'Punch-out missing on ' . Fmt::day($d['day']) . ' (in at ' . Fmt::time($i['in']) . ')'];
                }
                if ($i['fix']) {
                    $out[] = ['kind' => 'pending_fix', 'day' => $d['day'], 'request_id' => $i['fix']['id'],
                        'text' => 'Fix waiting for approval: punch-out on ' . Fmt::day($d['day']) . ' at ' . Fmt::time($i['fix']['out'])];
                }
            }
        }
        $pending = [];
        foreach ($days as $d) {
            if ($d['pending_leave'] && $d['state'] !== 'leave' && $d['state'] !== 'not_employed') {
                $pending[$d['pending_leave']][] = $d['day'];
            }
        }
        foreach ($pending as $rid => $list) {
            $out[] = ['kind' => 'pending_leave', 'request_id' => $rid, 'day' => $list[0],
                'text' => 'Leave not decided yet: ' . self::daysText($list)];
        }
        $noPunch = array_values(array_map(fn($d) => $d['day'], array_filter($days, fn($d) => $d['state'] === 'no_punch' && !$d['mark'])));
        if ($noPunch) {
            $out[] = ['kind' => 'no_punch', 'days' => $noPunch, 'day' => $noPunch[0],
                'text' => Fmt::plural(count($noPunch), 'day') . ' with no punch to decide: ' . self::daysText($noPunch)];
        }
        if ($sum['day_off'] > $allowance) {
            $out[] = ['kind' => 'over_allowance', 'warn' => true, 'day' => null,
                'text' => Fmt::plural($sum['day_off'], 'weekly off') . ' marked, more than the ' . $allowance . ' a month in Settings'];
        }
        return $out;
    }

    private static function daysText(array $days): string
    {
        $short = array_map(fn($d) => date('D j', strtotime($d)), array_slice($days, 0, 6));
        return implode(', ', $short) . (count($days) > 6 ? ' and ' . (count($days) - 6) . ' more' : '') . ' ' . date('M', strtotime($days[0]));
    }

    // ---- holidays ---------------------------------------------------------------------

    /** [day => [ {name, centres|null} ]] */
    private static function holidayMap(string $from, string $to): array
    {
        $out = [];
        foreach (Kernel::hr()->table('hr_holidays')->whereBetween('day', [$from, $to])->get() as $h) {
            $out[$h->day][] = ['name' => $h->name, 'centres' => $h->centres ? explode(',', $h->centres) : null];
        }
        return $out;
    }

    private static function holidayFor(array $list, ?string $centre): ?string
    {
        foreach ($list as $h) {
            if ($h['centres'] === null || ($centre !== null && in_array($centre, $h['centres'], true))) {
                return $h['name'];
            }
        }
        return null;
    }

    /** Holiday days for one person between two days (to keep leave off them). */
    public static function holidaysFor(?string $centre, string $from, string $to): array
    {
        $out = [];
        foreach (self::holidayMap($from, $to) as $d => $list) {
            if (self::holidayFor($list, $centre) !== null) {
                $out[$d] = self::holidayFor($list, $centre);
            }
        }
        return $out;
    }

    public static function holidays(Ctx $c): array
    {
        $year = (int) ($c->query('year', 4) ?? date('Y'));
        if ($year < 2020 || $year > 2100) {
            throw ApiError::invalid('Please pick a year.');
        }
        $locked = self::lockedMonths();
        $rows = Kernel::hr()->table('hr_holidays')->whereBetween('day', [$year . '-01-01', $year . '-12-31'])->orderBy('day')->get();
        return [
            'year' => $year,
            'holidays' => $rows->map(fn($h) => [
                'id' => (int) $h->id, 'day' => $h->day, 'name' => $h->name,
                'centres' => $h->centres ? explode(',', $h->centres) : null,
                'centre_names' => $h->centres ? implode(', ', array_map([Settings::class, 'centreName'], explode(',', $h->centres))) : 'Every centre',
                'locked' => isset($locked[substr($h->day, 0, 7)]), 'past' => $h->day < self::today(),
            ])->all(),
            'centres' => array_map(fn($k, $v) => ['code' => $k, 'name' => $v['name']], array_keys(Settings::get('centres')), Settings::get('centres')),
        ];
    }

    public static function addHoliday(Ctx $c): array
    {
        $day = (string) $c->date('day', true, 'Date');
        $name = (string) $c->str('name', 80, true, 'Name');
        $centres = $c->has('centres') ? array_values(array_intersect(array_keys(Settings::get('centres')), (array) $c->raw('centres'))) : [];
        self::assertOpen([$day]);
        if (Kernel::hr()->table('hr_holidays')->where('day', $day)->exists()) {
            throw ApiError::conflict(Fmt::day($day) . ' is already a holiday.');
        }
        $all = count($centres) === 0 || count($centres) === count(Settings::get('centres'));
        $id = Kernel::hr()->table('hr_holidays')->insertGetId([
            'day' => $day, 'name' => $name, 'centres' => $all ? null : implode(',', $centres),
            'created_by' => $c->accountId(), 'created_at' => Kernel::now(),
        ]);
        Audit::log('holiday.add', ['entity' => 'hr_holidays', 'entity_id' => (int) $id,
            'summary' => 'Holiday added: ' . $name . ', ' . Fmt::day($day) . ($all ? '' : ' (' . implode(', ', $centres) . ')')]);
        return ['id' => (int) $id, 'message' => $name . ' added for ' . Fmt::day($day) . '.'];
    }

    public static function removeHoliday(Ctx $c, int $id): array
    {
        $h = Kernel::hr()->table('hr_holidays')->where('id', $id)->first();
        if (!$h) {
            throw ApiError::notFound('That holiday');
        }
        self::assertOpen([$h->day]);
        Kernel::hr()->table('hr_holidays')->where('id', $id)->delete();
        Audit::log('holiday.remove', ['entity' => 'hr_holidays', 'entity_id' => $id, 'summary' => 'Holiday removed: ' . $h->name . ', ' . Fmt::day($h->day)]);
        return ['message' => $h->name . ' removed.'];
    }

    // ---- the monthly lock -----------------------------------------------------------

    /** ['2026-09' => true] for every locked month. */
    public static function lockedMonths(): array
    {
        return array_fill_keys(Kernel::hr()->table('hr_attendance_months')->where('status', 'locked')->pluck('month')->all(), true);
    }

    /** Refuse to change days in a locked month. */
    public static function assertOpen(array $days): void
    {
        $locked = self::lockedMonths();
        foreach ($days as $d) {
            if (isset($locked[substr((string) $d, 0, 7)])) {
                throw ApiError::conflict(date('F Y', strtotime(substr((string) $d, 0, 7) . '-01')) . ' is locked for payroll. An admin has to unlock it first (Attendance → Close the month).');
            }
        }
    }

    private static function month(string $ym): array
    {
        if (!preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $ym)) {
            throw ApiError::invalid('Please pick a month.');
        }
        $from = $ym . '-01';
        return [$from, date('Y-m-t', strtotime($from)), date('F Y', strtotime($from))];
    }

    // ---- admin: the register ----------------------------------------------------------

    /** The month for everyone: one row per person, one cell per day. */
    public static function register(Ctx $c): array
    {
        $ym = $c->query('month', 7) ?? substr(self::today(), 0, 7);
        [$from, $to, $label] = self::month($ym);
        $centre = $c->query('centre', 3);
        if ($centre !== null && !isset(Settings::get('centres')[$centre])) {
            $centre = null;
        }
        $people = self::people($from, $to, null, $centre);
        $grid = self::grid($people, $from, $to);
        $allowance = (int) Settings::get('days_off_per_month');
        $rows = [];
        $totals = ['people' => 0, 'ready' => 0, 'missing_out' => 0, 'no_punch' => 0, 'in' => 0];
        foreach ($people as $id => $e) {
            $days = $grid[$id] ?? [];
            $sum = self::summarize($days);
            $issues = self::issues($e, $days, $sum, $allowance);
            $blocking = array_filter($issues, fn($i) => empty($i['warn']));
            $totals['people']++;
            $totals['ready'] += $blocking ? 0 : 1;
            $totals['missing_out'] += $sum['missing_out'];
            $totals['no_punch'] += $sum['no_punch'];
            $totals['in'] += $sum['in'];
            $rows[] = [
                'id' => $id, 'name' => $e->full_name, 'initials' => Fmt::initials($e->full_name), 'centre' => $e->centre,
                'designation' => $e->designation, 'via' => $e->via,
                'days' => array_values(array_map(fn($d) => self::cellBrief($d), $days)),
                'sum' => $sum, 'issues' => count($blocking),
            ];
        }
        $lock = Kernel::hr()->table('hr_attendance_months')->where('month', $ym)->first();
        $hol = [];
        foreach (self::holidayMap($from, $to) as $d => $list) {
            $hol[$d] = implode(', ', array_column($list, 'name'));
        }
        return [
            'month' => $ym, 'label' => $label, 'from' => $from, 'to' => $to, 'today' => self::today(),
            'days' => array_map(fn($d) => ['day' => $d, 'dow' => date('D', strtotime($d)), 'n' => (int) substr($d, 8), 'holiday' => $hol[$d] ?? null], Clock::range($from, $to)),
            'people' => $rows, 'totals' => $totals, 'centre' => $centre,
            'centres' => array_map(fn($k, $v) => ['code' => $k, 'name' => $v['name']], array_keys(Settings::get('centres')), Settings::get('centres')),
            'lock' => self::lockInfo($lock), 'allowance' => $allowance,
            'prev' => date('Y-m', strtotime($from . ' -1 month')), 'next' => $to < self::today() ? date('Y-m', strtotime($from . ' +1 month')) : null,
        ];
    }

    /** A day squeezed for the register grid. */
    private static function cellBrief(array $d): array
    {
        $tag = match ($d['state']) {
            'worked', 'in' => $d['minutes'] ? self::hours($d['minutes']) : ($d['state'] === 'in' ? 'In' : '0'),
            'missing_out' => '?',
            'leave' => strtoupper(substr($d['leave']['code'], 0, 2)),
            'holiday' => 'H',
            'no_punch' => match ($d['mark']['mark'] ?? null) { 'day_off' => 'WO', 'unpaid' => 'LOP', 'worked' => 'W', default => '–' },
            default => '',
        };
        $title = Fmt::day($d['day']) . ': ' . self::stateText($d);
        return ['s' => $d['state'], 't' => $tag, 'title' => $title, 'm' => $d['mark']['mark'] ?? null, 'h' => (bool) $d['holiday']];
    }

    /** "Worked 8 h 40 m (breaks 55 m)" etc. */
    public static function stateText(array $d): string
    {
        $extra = ($d['holiday'] && !in_array($d['state'], ['holiday'], true) ? ' · holiday: ' . $d['holiday'] : '')
            . ($d['leave'] && $d['state'] !== 'leave' ? ' · on approved ' . Fmt::lower($d['leave']['name']) : '');
        return match ($d['state']) {
            'worked' => 'Worked ' . Approvals::hm($d['minutes']) . ($d['break_mins'] ? ' (breaks ' . Approvals::hm($d['break_mins']) . ')' : '') . $extra,
            'in' => 'In now' . $extra,
            'missing_out' => 'Punch-out missing' . $extra,
            'leave' => $d['leave']['name'] . ($d['leave']['paid'] ? '' : ' (unpaid)'),
            'holiday' => 'Holiday: ' . $d['holiday'],
            'no_punch' => 'No punch' . ($d['mark'] ? ' · decided: ' . Fmt::lower($d['mark']['label']) : '') . ($d['pending_leave'] ? ' · leave asked, not decided' : ''),
            'today' => 'Today, no punch yet',
            'upcoming' => $d['leave'] ? $d['leave']['name'] : ($d['holiday'] ? 'Holiday: ' . $d['holiday'] : 'Still to come'),
            default => 'Not at Trio yet / any more',
        };
    }

    private static function hours(int $minutes): string
    {
        $h = $minutes / 60;
        return $h >= 10 ? (string) round($h) : rtrim(rtrim(number_format($h, 1), '0'), '.');
    }

    // ---- admin: one person's month -----------------------------------------------------

    public static function personMonth(object $e, string $ym): array
    {
        [$from, $to, $label] = self::month($ym);
        $people = self::people($from, $to, [(int) $e->id]);
        $p = $people[(int) $e->id] ?? null;
        if (!$p) {
            return ['month' => $ym, 'label' => $label, 'days' => [], 'sum' => null, 'issues' => [], 'via' => self::viaFor($e),
                'prev' => date('Y-m', strtotime($from . ' -1 month')), 'next' => null, 'locked' => false];
        }
        $days = self::grid($people, $from, $to)[(int) $e->id] ?? [];
        $sum = self::summarize($days);
        $out = [];
        foreach (array_reverse($days) as $d) {
            if (in_array($d['state'], ['not_employed'], true) || ($d['state'] === 'upcoming' && !$d['leave'] && !$d['holiday'])) {
                continue;
            }
            $d['text'] = self::stateText($d);
            $out[] = $d;
        }
        $locked = isset(self::lockedMonths()[$ym]);
        return [
            'month' => $ym, 'label' => $label, 'days' => $out, 'sum' => $sum, 'locked' => $locked,
            'issues' => self::issues($p, $days, $sum, (int) Settings::get('days_off_per_month')),
            'via' => self::viaFor($e), 'today' => self::today(),
            'prev' => date('Y-m', strtotime($from . ' -1 month')), 'next' => $to < self::today() ? date('Y-m', strtotime($from . ' +1 month')) : null,
        ];
    }

    /** Last 14 days as dots, plus this month's numbers (the person page card). */
    public static function personStrip(object $e): array
    {
        $today = self::today();
        $from = min(substr($today, 0, 8) . '01', Clock::addDays($today, -13));
        $people = self::people($from, $today, [(int) $e->id]);
        if (!$people) {
            return ['month' => null, 'strip' => [], 'via' => self::viaFor($e)];
        }
        $days = self::grid($people, $from, $today)[(int) $e->id] ?? [];
        $month = self::summarize(array_filter($days, fn($d) => $d['day'] >= substr($today, 0, 8) . '01'));
        $month['avg_minutes'] = $month['worked'] ? (int) round($month['minutes'] / max(1, $month['worked'] - $month['missing_out'] - $month['in'])) : 0;
        $month['label'] = date('F Y', strtotime($today));
        $strip = [];
        foreach ($days as $d) {
            if ($d['day'] >= Clock::addDays($today, -13)) {
                $strip[] = ['day' => $d['day'], 'state' => $d['state'], 'minutes' => $d['minutes'], 'mark' => $d['mark']['mark'] ?? null, 'text' => self::stateText($d)];
            }
        }
        $now = $days[$today] ?? null;
        return ['month' => $month, 'strip' => $strip, 'via' => self::viaFor($e), 'now' => $now ? ['state' => $now['state'], 'text' => self::stateText($now), 'items' => $now['items']] : null];
    }

    // ---- admin: decisions -------------------------------------------------------------

    /** Decide what no-punch days mean for pay: weekly off, unpaid, or worked (punch missed). */
    public static function mark(Ctx $c): array
    {
        $id = (int) $c->intVal('employee_id', 1, PHP_INT_MAX, true, 'The person');
        $days = $c->dates('days');
        $mark = $c->pick('mark', array_merge(array_keys(self::MARK_LABELS), ['clear']), true, 'The decision');
        $note = $c->str('note', 255);
        if (!$days || count($days) > 31) {
            throw ApiError::invalid('Pick the days to decide.');
        }
        self::assertOpen($days);
        $e = Kernel::hr()->table('hr_employees')->where('id', $id)->first();
        if (!$e) {
            throw ApiError::notFound('That person');
        }
        sort($days);
        $people = self::people($days[0], end($days), [$id]);
        $grid = self::grid($people, $days[0], end($days))[$id] ?? [];
        foreach ($days as $d) {
            if (($grid[$d]['state'] ?? null) !== 'no_punch') {
                throw ApiError::conflict(Fmt::day($d) . ' isn\'t a no-punch day any more (' . Fmt::lower(self::stateText($grid[$d] ?? ['state' => 'not_employed', 'holiday' => null, 'leave' => null])) . '). Refresh and look again.');
            }
        }
        $db = Kernel::hr();
        $db->transaction(function () use ($db, $days, $id, $mark, $note, $c) {
            foreach ($days as $d) {
                if ($mark === 'clear') {
                    $db->table('hr_day_marks')->where('employee_id', $id)->where('work_day', $d)->delete();
                } else {
                    $db->table('hr_day_marks')->updateOrInsert(['employee_id' => $id, 'work_day' => $d],
                        ['mark' => $mark, 'note' => $note, 'by_account' => $c->accountId(), 'at' => Kernel::now()]);
                }
            }
        });
        $what = $mark === 'clear' ? 'undecided' : Fmt::lower(self::MARK_LABELS[$mark]);
        Audit::log('attendance.mark', ['employee_id' => $id, 'entity' => 'hr_day_marks', 'detail' => ['days' => $days, 'mark' => $mark],
            'summary' => Fmt::first((string) ($c->auth['display_name'] ?? 'An admin')) . ' marked ' . Fmt::plural(count($days), 'day') . ' for ' . Fmt::first($e->full_name) . ' as ' . $what]);
        return ['message' => Fmt::plural(count($days), 'day') . ' marked as ' . $what . '.'];
    }

    /**
     * One click for the usual case: each person's no-punch days become weekly
     * offs, up to the monthly allowance, earliest first. Anything beyond the
     * allowance stays for the admin to decide. An admin decision, not a guess:
     * it only runs when they press the button, and it says what it did.
     */
    public static function markWeeklyOffs(Ctx $c): array
    {
        $ym = (string) $c->str('month', 7, true, 'The month');
        [$from, $to, $label] = self::month($ym);
        self::assertOpen([$from]);
        if ($to >= self::today()) {
            throw ApiError::conflict($label . " isn't over yet, so its weekly offs can't all be known. Decide days one by one until then.");
        }
        $allowance = (int) Settings::get('days_off_per_month');
        $people = self::people($from, $to);
        $grid = self::grid($people, $from, $to);
        $db = Kernel::hr();
        $marked = 0;
        $who = 0;
        $left = [];
        $db->transaction(function () use ($db, $grid, $allowance, $c, &$marked, &$who, &$left, $people) {
            foreach ($grid as $id => $days) {
                $used = count(array_filter($days, fn($d) => ($d['mark']['mark'] ?? null) === 'day_off'));
                $open = array_values(array_filter($days, fn($d) => $d['state'] === 'no_punch' && !$d['mark'] && !$d['pending_leave']));
                $take = array_slice($open, 0, max(0, $allowance - $used));
                foreach ($take as $d) {
                    $db->table('hr_day_marks')->insert(['employee_id' => $id, 'work_day' => $d['day'], 'mark' => 'day_off', 'note' => 'Weekly offs for the month',
                        'by_account' => $c->accountId(), 'at' => Kernel::now()]);
                }
                $marked += count($take);
                $who += $take ? 1 : 0;
                if (count($open) > count($take)) {
                    $left[] = Fmt::first($people[$id]->full_name);
                }
            }
        });
        Audit::log('attendance.weekly_offs', ['detail' => ['month' => $ym, 'days' => $marked, 'people' => $who],
            'summary' => Fmt::first((string) ($c->auth['display_name'] ?? 'An admin')) . ' marked ' . Fmt::plural($marked, 'weekly off') . ' for ' . Fmt::plural($who, 'person', 'people') . ' in ' . $label]);
        return ['message' => Fmt::plural($marked, 'day') . ' marked as weekly offs for ' . Fmt::plural($who, 'person', 'people') . '.'
            . ($left ? ' Still to decide (more no-punch days than ' . $allowance . '): ' . implode(', ', array_slice($left, 0, 6)) . (count($left) > 6 ? ' and ' . (count($left) - 6) . ' more' : '') . '.' : '')];
    }

    /** An admin sets a missing punch-out directly (e.g. they asked the person in person). */
    public static function setOut(Ctx $c): array
    {
        $id = (int) $c->intVal('employee_id', 1, PHP_INT_MAX, true, 'The person');
        $ref = (string) $c->str('ref', 24, true, 'The day');
        $time = (string) $c->str('time', 5, true, 'The time');
        $note = $c->str('note', 255);
        $e = Kernel::hr()->table('hr_employees')->where('id', $id)->first();
        if (!$e) {
            throw ApiError::notFound('That person');
        }
        $item = self::openItem($e, $ref);
        self::assertOpen([$item['day']]);
        $out = self::outTime($item['in'], $time);
        $who = Fmt::first((string) ($c->auth['display_name'] ?? 'An admin'));
        $db = Kernel::hr();
        if ($item['kind'] === 'hr') {
            $db->table('hr_punches')->where('id', $item['id'])->whereNull('punch_out')->update([
                'punch_out' => $out, 'out_source' => 'admin', 'out_by' => $c->accountId(), 'updated_at' => Kernel::now(),
            ]);
        } else {
            // A CRM night: recorded in HR as an approved fix. The CRM's own record stays as it is.
            $now = Kernel::now();
            $db->table('hr_requests')->insert([
                'employee_id' => $id, 'type' => 'attendance_fix', 'status' => 'approved',
                'summary' => 'Punch-out on ' . Fmt::day($item['day']) . ' · ' . Fmt::time($out) . ' (set by ' . $who . ')',
                'payload' => json_encode(['session_id' => $item['id'], 'day' => $item['day'], 'clock_out' => $out, 'kind' => 'missed_clock_out', 'by_admin' => true]),
                'note' => $note, 'decided_by' => $c->accountId(), 'decided_at' => $now, 'decision_note' => $note,
                'decision' => json_encode(['clock_out' => $out, 'session_id' => $item['id']]), 'created_at' => $now, 'updated_at' => $now,
            ]);
            // A fix the person asked for and nobody decided is now moot.
            foreach ($db->table('hr_requests')->where('employee_id', $id)->where('type', 'attendance_fix')->where('status', 'pending')->get(['id', 'payload']) as $r) {
                if ((int) (json_decode((string) $r->payload, true)['session_id'] ?? 0) === $item['id']) {
                    $db->table('hr_requests')->where('id', $r->id)->update(['status' => 'withdrawn', 'decision_note' => 'Set directly by ' . $who, 'updated_at' => $now]);
                }
            }
        }
        Audit::log('attendance.set_out', ['employee_id' => $id, 'detail' => ['ref' => $ref, 'out' => $out],
            'summary' => $who . ' set ' . Fmt::first($e->full_name) . "'s punch-out on " . Fmt::day($item['day']) . ' to ' . Fmt::time($out)]);
        return ['message' => 'Punch-out set to ' . Fmt::time($out) . '.'];
    }

    /**
     * A punch or CRM session with no real punch-out yet, by ref ("p12" / "s345"),
     * belonging to this person.
     */
    public static function openItem(object $e, string $ref): array
    {
        if (!preg_match('/^([ps])(\d{1,18})$/', $ref, $m)) {
            throw ApiError::notFound('That day');
        }
        $id = (int) $m[2];
        if ($m[1] === 'p') {
            $p = Kernel::hr()->table('hr_punches')->where('id', $id)->where('employee_id', $e->id)->first();
            if (!$p) {
                throw ApiError::notFound('That day');
            }
            if ($p->punch_out !== null) {
                throw ApiError::conflict('That day already has a punch-out (' . Fmt::time($p->punch_out) . ').');
            }
            return ['kind' => 'hr', 'id' => $id, 'ref' => $ref, 'day' => $p->work_day, 'in' => $p->punch_in, 'break_mins' => 0,
                'open_now' => time() - strtotime($p->punch_in) <= self::OPEN_LIMIT_HOURS * 3600];
        }
        $s = $e->crm_user_id ? Kernel::crm()->table('attendance_sessions')->where('id', $id)->where('user_id', $e->crm_user_id)->first() : null;
        if (!$s) {
            throw ApiError::notFound('That day');
        }
        $fixed = self::fixes([(int) $e->id])['s'][$id] ?? null;
        if (($fixed['status'] ?? null) === 'approved') {
            throw ApiError::conflict('That day\'s punch-out has already been fixed (' . Fmt::time($fixed['out']) . ').');
        }
        if (!in_array($s->status, ['auto_closed', 'active'], true) && !$s->resolution_required) {
            throw ApiError::conflict('That day already has a clock-out in the CRM (' . Fmt::time($s->clock_out) . ').');
        }
        return ['kind' => 'crm', 'id' => $id, 'ref' => $ref, 'day' => Clock::sessionNight((string) $s->clock_in), 'in' => (string) $s->clock_in,
            'break_mins' => (int) $s->total_break_mins, 'status' => $s->status,
            'open_now' => $s->status === 'active' && time() - strtotime((string) $s->clock_in) <= self::OPEN_LIMIT_HOURS * 3600];
    }

    /** "03:10" after a punch-in at 17:45 is the next morning. Refuses times in the future or too long after. */
    public static function outTime(string $in, string $time): string
    {
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
            throw ApiError::invalid('Please pick a time.', ['time' => 'Use the time picker, e.g. 03:10.']);
        }
        $inTs = strtotime($in);
        $outTs = strtotime(date('Y-m-d', $inTs) . ' ' . $time . ':00');
        if ($outTs <= $inTs) {
            $outTs = strtotime('+1 day', $outTs);
        }
        if ($outTs > time()) {
            throw ApiError::invalid("That time hasn't happened yet. Pick the time they actually left.", ['time' => 'Pick a time that has passed.']);
        }
        if ($outTs - $inTs > self::MAX_HOURS * 3600) {
            throw ApiError::invalid('That would be more than ' . self::MAX_HOURS . ' hours after the punch-in at ' . Fmt::time($in) . '. Please check the time.', ['time' => 'Pick an earlier time.']);
        }
        return date('Y-m-d H:i:s', $outTs);
    }

    /** When the person last did something in the CRM after punching in (a hint for the time they left). */
    public static function lastSeen(object $e, string $in): ?string
    {
        if (!$e->crm_user_id) {
            return null;
        }
        try {
            return CrmGateway::lastActivity((int) $e->crm_user_id, $in, date('Y-m-d H:i:s', strtotime($in) + self::MAX_HOURS * 3600));
        } catch (Throwable) {
            return null;
        }
    }

    // ---- admin: close the month ---------------------------------------------------------

    public static function closeView(Ctx $c): array
    {
        $ym = $c->query('month', 7) ?? self::defaultCloseMonth();
        [$from, $to, $label] = self::month($ym);
        $people = self::people($from, $to);
        $grid = self::grid($people, $from, $to);
        $allowance = (int) Settings::get('days_off_per_month');
        $rows = [];
        $ready = 0;
        foreach ($people as $id => $e) {
            $days = $grid[$id] ?? [];
            $sum = self::summarize($days);
            $issues = self::issues($e, $days, $sum, $allowance);
            foreach ($issues as &$i) {
                if ($i['kind'] === 'missing_out') {
                    $i['last_seen'] = self::lastSeen($e, $i['in']);
                }
            }
            unset($i);
            $blocking = array_values(array_filter($issues, fn($i) => empty($i['warn'])));
            $ready += $blocking ? 0 : 1;
            $rows[] = [
                'id' => $id, 'name' => $e->full_name, 'initials' => Fmt::initials($e->full_name), 'centre' => $e->centre,
                'designation' => $e->designation, 'ready' => !$blocking, 'issues' => $issues, 'sum' => $sum,
                'days_off_left' => max(0, $allowance - $sum['day_off']),
            ];
        }
        usort($rows, fn($a, $b) => [$a['ready'], $a['name']] <=> [$b['ready'], $b['name']]);
        $lock = Kernel::hr()->table('hr_attendance_months')->where('month', $ym)->first();
        $ended = $to < self::today();
        return [
            'month' => $ym, 'label' => $label, 'from' => $from, 'to' => $to, 'ended' => $ended,
            'people' => $rows, 'ready' => $ready, 'total' => count($rows), 'allowance' => $allowance,
            'lock' => self::lockInfo($lock), 'can_lock' => $ended && $ready === count($rows) && ($lock->status ?? null) !== 'locked',
            'mark_labels' => self::MARK_LABELS,
            'prev' => date('Y-m', strtotime($from . ' -1 month')), 'next' => $ended ? date('Y-m', strtotime($from . ' +1 month')) : null,
        ];
    }

    /** The month an admin most likely wants to close: last month until it's locked, then this one. */
    public static function defaultCloseMonth(): string
    {
        $last = date('Y-m', strtotime(substr(self::today(), 0, 7) . '-01 -1 month'));
        return isset(self::lockedMonths()[$last]) ? substr(self::today(), 0, 7) : $last;
    }

    private static function lockInfo(?object $lock): array
    {
        if (!$lock) {
            return ['status' => 'open'];
        }
        $names = Kernel::hr()->table('hr_accounts')->whereIn('id', array_filter([$lock->locked_by, $lock->unlocked_by]))->pluck('display_name', 'id')->all();
        return [
            'status' => $lock->status === 'locked' ? 'locked' : 'open',
            'locked_at' => $lock->locked_at, 'locked_by' => $names[$lock->locked_by] ?? null,
            'unlocked_at' => $lock->unlocked_at, 'unlocked_by' => $names[$lock->unlocked_by] ?? null, 'unlock_reason' => $lock->unlock_reason,
        ];
    }

    public static function lock(Ctx $c): array
    {
        $ym = (string) $c->str('month', 7, true, 'The month');
        [$from, $to, $label] = self::month($ym);
        if ($to >= self::today()) {
            throw ApiError::conflict($label . " isn't over yet. It can be locked from " . Fmt::day(Clock::addDays($to, 1)) . '.');
        }
        $people = self::people($from, $to);
        $grid = self::grid($people, $from, $to);
        $allowance = (int) Settings::get('days_off_per_month');
        $snapshot = [];
        $left = [];
        foreach ($people as $id => $e) {
            $sum = self::summarize($grid[$id] ?? []);
            $blocking = array_filter(self::issues($e, $grid[$id] ?? [], $sum, $allowance), fn($i) => empty($i['warn']));
            if ($blocking) {
                $left[] = Fmt::first($e->full_name);
            }
            $snapshot[$id] = ['name' => $e->full_name, 'days' => $sum['days'], 'worked' => $sum['worked'] + $sum['worked_marked'],
                'hours' => round($sum['minutes'] / 60, 2), 'paid_leave' => $sum['paid_leave'], 'holidays' => $sum['holidays'],
                'weekly_offs' => $sum['day_off'], 'unpaid_days' => $sum['unpaid_days'], 'paid_days' => $sum['paid_days'],
                'holiday_worked' => $sum['holiday_worked']];
        }
        if ($left) {
            throw ApiError::conflict('Still to sort: ' . implode(', ', array_slice($left, 0, 5)) . (count($left) > 5 ? ' and ' . (count($left) - 5) . ' more' : '') . '.');
        }
        $db = Kernel::hr();
        if ($db->table('hr_attendance_months')->where('month', $ym)->where('status', 'locked')->exists()) {
            throw ApiError::conflict($label . ' is already locked.');
        }
        $db->table('hr_attendance_months')->updateOrInsert(['month' => $ym], [
            'status' => 'locked', 'snapshot' => json_encode($snapshot), 'locked_by' => $c->accountId(), 'locked_at' => Kernel::now(),
        ]);
        Audit::log('attendance.lock', ['entity' => 'hr_attendance_months', 'detail' => ['month' => $ym, 'people' => count($snapshot)],
            'summary' => Fmt::first((string) ($c->auth['display_name'] ?? 'An admin')) . ' locked attendance for ' . $label . ' (' . Fmt::plural(count($snapshot), 'person', 'people') . ')']);
        return ['message' => $label . ' is locked. Days worked and paid leave are ready for payroll.'];
    }

    public static function unlock(Ctx $c): array
    {
        $ym = (string) $c->str('month', 7, true, 'The month');
        [, , $label] = self::month($ym);
        $reason = (string) $c->str('reason', 255, true, 'A reason');
        $n = Kernel::hr()->table('hr_attendance_months')->where('month', $ym)->where('status', 'locked')->update([
            'status' => 'unlocked', 'unlocked_by' => $c->accountId(), 'unlocked_at' => Kernel::now(), 'unlock_reason' => $reason,
        ]);
        if (!$n) {
            throw ApiError::conflict($label . " isn't locked.");
        }
        Audit::log('attendance.unlock', ['entity' => 'hr_attendance_months', 'detail' => ['month' => $ym, 'reason' => $reason],
            'summary' => Fmt::first((string) ($c->auth['display_name'] ?? 'An admin')) . ' unlocked ' . $label . ': ' . $reason]);
        Notify::admins('attendance.unlock', $label . ' was unlocked', $reason, '#/attendance/close?month=' . $ym);
        return ['message' => $label . ' is unlocked. Lock it again once the change is made.'];
    }

    // ---- staff: the punch -----------------------------------------------------------------

    /** The punch card on the staff home. */
    public static function punchCard(Ctx $c, object $e): array
    {
        $info = self::viaFor($e);
        $today = self::today();
        $from = Clock::addDays($today, -1);
        $people = self::people($from, $today, [(int) $e->id]);
        $grid = $people ? (self::grid($people, $from, $today)[(int) $e->id] ?? []) : [];
        $items = array_merge($grid[$from]['items'] ?? [], $grid[$today]['items'] ?? []);
        $open = null;
        foreach ($items as $i) {
            if ($i['state'] === 'open') {
                $open = $i;
            }
        }
        $todayItems = $grid[$today]['items'] ?? [];
        $done = array_sum(array_map(fn($i) => $i['minutes'], $todayItems));
        $out = [
            'via' => $info['via'], 'today' => $today, 'open' => $open,
            'today_items' => array_map(fn($i) => ['in' => $i['in'], 'out' => $i['out'], 'state' => $i['state'], 'kind' => $i['kind']], $todayItems),
            'minutes_today' => $done, 'leave' => $grid[$today]['leave']['name'] ?? null, 'holiday' => $grid[$today]['holiday'] ?? null,
        ];
        if ($info['via'] === 'hr') {
            $net = CrmGateway::officeNetwork(Ctx::clientIp());
            $out['network'] = [
                'ok' => $net['ok'],
                'text' => $net['ok'] ? 'You\'re on the office network' : ($net['listed'] ? 'Punching works on the office network only' : 'The office network list is empty. Ask an admin to set it up in the CRM'),
            ];
        }
        return $out;
    }

    /** Punch in or out with the Trio HR button. Office network only. */
    public static function punch(Ctx $c): array
    {
        $e = Me::employee($c);
        if (!empty($c->auth['leaver'])) {
            throw ApiError::forbidden('You have left Trio, so there is nothing to punch.');
        }
        $action = (string) $c->pick('action', ['in', 'out'], true, 'In or out');
        if (self::viaFor($e)['via'] !== 'hr') {
            throw ApiError::conflict('Your punch is your CRM clock-in. Clock in and out on the CRM as usual; it shows here by itself.');
        }
        $ip = Ctx::clientIp();
        $net = CrmGateway::officeNetwork($ip);
        if (!$net['ok']) {
            Audit::log('punch.refused', ['employee_id' => (int) $e->id, 'detail' => ['ip' => $ip, 'action' => $action]]);
            throw new ApiError(403, 'not_office', $net['listed']
                ? 'You can only punch ' . $action . ' from the office network. If you are in the office, check you are on the office Wi-Fi.'
                : 'The office network isn\'t set up yet, so punching is switched off. Please tell an admin.');
        }
        $db = Kernel::hr();
        $now = Kernel::now();
        return $db->transaction(function () use ($db, $e, $action, $ip, $now) {
            // Lock this person's open punches so a double tap can't make two.
            $open = $db->table('hr_punches')->where('employee_id', $e->id)->whereNull('punch_out')
                ->where('punch_in', '>=', date('Y-m-d H:i:s', time() - self::OPEN_LIMIT_HOURS * 3600))
                ->orderByDesc('punch_in')->lockForUpdate()->first();
            if ($action === 'in') {
                if ($open) {
                    throw ApiError::conflict('You\'re already punched in since ' . Fmt::time($open->punch_in) . '.');
                }
                $day = Clock::sessionNight($now);
                self::assertOpen([$day]);
                $id = $db->table('hr_punches')->insertGetId([
                    'employee_id' => $e->id, 'work_day' => $day, 'punch_in' => $now, 'in_ip' => $ip,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                Audit::log('punch.in', ['employee_id' => (int) $e->id, 'entity' => 'hr_punches', 'entity_id' => (int) $id]);
                return ['message' => 'Punched in at ' . Fmt::time($now) . '.', 'at' => $now];
            }
            if (!$open) {
                throw ApiError::conflict('You aren\'t punched in, so there is nothing to punch out of.');
            }
            $db->table('hr_punches')->where('id', $open->id)->update(['punch_out' => $now, 'out_ip' => $ip, 'out_source' => 'punch', 'updated_at' => $now]);
            Audit::log('punch.out', ['employee_id' => (int) $e->id, 'entity' => 'hr_punches', 'entity_id' => (int) $open->id]);
            $mins = intdiv(time() - strtotime($open->punch_in), 60);
            return ['message' => 'Punched out at ' . Fmt::time($now) . '. ' . Approvals::hm($mins) . ' since you punched in.', 'at' => $now];
        });
    }

    /** The staff member's own month. */
    public static function myMonth(Ctx $c): array
    {
        $e = Me::employee($c);
        $ym = $c->query('month', 7) ?? substr(self::today(), 0, 7);
        $data = self::personMonth($e, $ym);
        $window = (int) Settings::get('fix_window_days');
        $earliest = Clock::addDays(self::today(), -$window);
        foreach ($data['days'] as &$d) {
            foreach ($d['items'] as &$i) {
                $i['can_fix'] = $i['state'] === 'missing' && !$i['fix'] && $d['day'] >= $earliest && !$data['locked'];
            }
            unset($i);
        }
        unset($d);
        unset($data['issues']);
        return $data + ['fix_days' => $window];
    }

    /** Missing punch-outs this person may still fix, newest first. */
    public static function fixable(object $e): array
    {
        $window = (int) Settings::get('fix_window_days');
        $today = self::today();
        $from = Clock::addDays($today, -$window);
        $people = self::people($from, $today, [(int) $e->id]);
        if (!$people) {
            return [];
        }
        $locked = self::lockedMonths();
        $out = [];
        foreach (array_reverse(self::grid($people, $from, $today)[(int) $e->id] ?? []) as $d) {
            foreach ($d['items'] as $i) {
                if ($i['state'] === 'missing' && !$i['fix'] && !isset($locked[substr($d['day'], 0, 7)])) {
                    $out[] = ['ref' => $i['ref'], 'day' => $d['day'], 'in' => $i['in'], 'label' => Fmt::day($d['day']) . ' · in at ' . Fmt::time($i['in'])];
                }
            }
        }
        return $out;
    }

    // ---- nudges and alerts --------------------------------------------------------------

    /**
     * "You didn't punch out on 14 Oct. When did you leave?" Once per missed
     * punch-out, as a Trio Mail with a button to answer. Runs at most every
     * ten minutes, from page loads (like Trio Mail's escalation).
     */
    public static function nudge(): void
    {
        try {
            $db = Kernel::hr();
            $last = $db->table('hr_nudges')->where('kind', 'run')->where('ref', 'missing_out')->value('sent_at');
            if ($last && strtotime((string) $last) > time() - 600) {
                return;
            }
            $db->table('hr_nudges')->updateOrInsert(['kind' => 'run', 'ref' => 'missing_out'], ['employee_id' => 0, 'sent_at' => Kernel::now()]);
            $window = (int) Settings::get('fix_window_days');
            $today = self::today();
            $from = Clock::addDays($today, -$window);
            $people = array_filter(self::people($from, $today), fn($e) => in_array($e->status, ['active', 'notice'], true));
            $sent = array_fill_keys($db->table('hr_nudges')->where('kind', 'missing_out')->pluck('ref')->all(), true);
            $locked = self::lockedMonths();
            foreach (self::grid($people, $from, $today) as $id => $days) {
                foreach ($days as $d) {
                    foreach ($d['items'] as $i) {
                        if ($i['state'] !== 'missing' || $i['fix'] || isset($sent[$i['ref']]) || isset($locked[substr($d['day'], 0, 7)])) {
                            continue;
                        }
                        $db->table('hr_nudges')->insertOrIgnore(['kind' => 'missing_out', 'ref' => $i['ref'], 'employee_id' => $id, 'sent_at' => Kernel::now()]);
                        $first = Fmt::first($people[$id]->preferred_name ?: $people[$id]->full_name);
                        $what = $i['kind'] === 'crm' ? 'clock out on the CRM' : 'punch out';
                        Mail::system($id, 'attendance', "You didn't " . $what . ' on ' . Fmt::day($d['day']),
                            "Hi {$first},\n\nYou " . ($i['kind'] === 'crm' ? 'clocked in' : 'punched in') . ' at ' . Fmt::time($i['in']) . ' on ' . Fmt::day($d['day'])
                            . ", but there's no " . ($i['kind'] === 'crm' ? 'clock-out' : 'punch-out') . ". When did you leave?\n\n"
                            . 'Tap "Tell HR when you left" below. It takes a few seconds, and an admin approves it. '
                            . 'Until then the day shows "Punch-out missing" and no hours are counted. Nothing is guessed.',
                            $i['ref'], 'nudge:' . $i['ref']);
                    }
                }
            }
        } catch (Throwable $err) {
            error_log('[Trio HR] punch-out nudge failed: ' . $err->getMessage());
        }
    }

    /**
     * For Today: people with no punch several days in a row and no leave
     * asked, and missing punch-outs. Facts only: nothing is sent to them.
     */
    public static function alerts(): array
    {
        $today = self::today();
        $streakDays = max(1, (int) Settings::get('no_punch_alert_days'));
        $from = Clock::addDays($today, -max(10, $streakDays + 3));
        $people = array_filter(self::people($from, $today), fn($e) => in_array($e->status, ['active', 'notice'], true));
        $grid = self::grid($people, $from, $today);
        $streaks = [];
        $missing = [];
        foreach ($grid as $id => $days) {
            $run = [];
            foreach (array_reverse($days) as $d) {
                if ($d['day'] >= $today) {
                    continue; // the day under way doesn't count yet, whatever it shows
                }
                if ($d['state'] === 'no_punch' && !$d['mark'] && !$d['pending_leave']) {
                    $run[] = $d['day'];
                    continue;
                }
                break;
            }
            if (count($run) >= $streakDays) {
                $streaks[] = ['employee_id' => $id, 'name' => $people[$id]->full_name, 'days' => count($run), 'since' => end($run)];
            }
            foreach ($days as $d) {
                foreach ($d['items'] as $i) {
                    if ($i['state'] === 'missing' && !$i['fix']) {
                        $missing[] = ['employee_id' => $id, 'name' => $people[$id]->full_name, 'day' => $d['day']];
                    }
                }
            }
        }
        usort($streaks, fn($a, $b) => $b['days'] <=> $a['days']);
        return ['streaks' => $streaks, 'missing' => $missing];
    }
}
