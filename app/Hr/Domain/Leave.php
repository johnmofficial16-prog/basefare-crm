<?php

namespace App\Hr\Domain;

use App\Hr\Http\ApiError;
use App\Hr\Kernel;
use App\Hr\Support\Clock;
use App\Hr\Support\Fmt;

/**
 * Leave requests and balances. One night of leave = one shift day.
 *
 * Balances are opening + accrued − used per calendar year. The opening
 * balance is set by an admin (Attendance → Leave balances); company holidays
 * inside a request are left out, so they never use up leave.
 */
final class Leave
{
    public static function types(): array
    {
        return Kernel::hr()->table('hr_leave_types')->orderBy('sort')->get()->map(fn($t) => [
            'code' => $t->code, 'name' => $t->name, 'paid' => (bool) $t->paid,
            'needs_balance' => (bool) $t->needs_balance, 'min_notice_days' => (int) $t->min_notice_days,
        ])->all();
    }

    /** @return array<string,string> */
    public static function typeNames(): array
    {
        return Kernel::hr()->table('hr_leave_types')->orderBy('sort')->pluck('name', 'code')->all();
    }

    private static function type(string $code): object
    {
        $t = Kernel::hr()->table('hr_leave_types')->where('code', $code)->first();
        if (!$t) {
            throw ApiError::invalid('Please pick a kind of leave.', ['leave_type' => 'Pick a kind of leave.']);
        }
        return $t;
    }

    /** Nights waiting for approval in one year, per leave type. */
    private static function pendingNights(int $employeeId, int $year): array
    {
        $out = [];
        foreach (Kernel::hr()->table('hr_requests')->where('employee_id', $employeeId)->where('type', 'leave')->where('status', 'pending')->get(['payload']) as $r) {
            $p = json_decode($r->payload, true) ?: [];
            $inYear = count(array_filter($p['days'] ?? [], fn($d) => (int) substr((string) $d, 0, 4) === $year));
            $out[$p['leave_type'] ?? ''] = ($out[$p['leave_type'] ?? ''] ?? 0) + $inYear;
        }
        return $out;
    }

    /** Nights grouped by the year they fall in: ['2026' => [...], '2027' => [...]]. */
    public static function byYear(array $days): array
    {
        $out = [];
        foreach ($days as $d) {
            $out[(int) substr((string) $d, 0, 4)][] = $d;
        }
        return $out;
    }

    public static function balances(int $employeeId, ?int $year = null): array
    {
        $year ??= (int) substr(Clock::shiftDay(), 0, 4);
        $rows = Kernel::hr()->table('hr_leave_balances')->where('employee_id', $employeeId)->where('year', $year)->get()->keyBy('leave_type');
        $pending = self::pendingNights($employeeId, $year);
        $out = [];
        foreach (self::types() as $t) {
            if (!$t['needs_balance']) {
                continue;
            }
            $b = $rows[$t['code']] ?? null;
            $total = $b ? (float) $b->opening + (float) $b->accrued : 0.0;
            $used = $b ? (float) $b->used : 0.0;
            $out[] = [
                'code' => $t['code'], 'name' => $t['name'], 'total' => $total, 'used' => $used,
                'available' => $total - $used, 'pending' => (float) ($pending[$t['code']] ?? 0),
            ];
        }
        return $out;
    }

    private static function available(int $employeeId, string $type, int $year): float
    {
        foreach (self::balances($employeeId, $year) as $b) {
            if ($b['code'] === $type) {
                return $b['available'] - $b['pending'];
            }
        }
        return 0.0;
    }

    /** "Casual leave · Thu 12 – Fri 13 Nov · 2 nights" */
    public static function summary(string $typeName, array $days): string
    {
        $n = count($days);
        if (!$n) {
            return $typeName;
        }
        $first = $days[0];
        $last = $days[$n - 1];
        $when = $n === 1 ? Fmt::day($first) : (substr($first, 0, 7) === substr($last, 0, 7)
            ? date('D j', strtotime($first)) . ' – ' . Fmt::day($last)
            : Fmt::day($first) . ' – ' . Fmt::day($last));
        return $typeName . ' · ' . $when . ' · ' . Fmt::plural($n, 'night');
    }

    /** The first night leave can start on: the next one that hasn't begun. */
    public static function firstNight(): string
    {
        return Clock::addDays(Clock::shiftDay(), 1);
    }

    /** Sick leave can be asked for afterwards, up to 3 nights back. */
    public static function sickFrom(): string
    {
        return Clock::addDays(Clock::shiftDay(), -3);
    }

    /** Validate and file a leave request for an employee. */
    public static function apply(int $employeeId, string $code, string $from, string $to, ?string $note): array
    {
        $type = self::type($code);
        if ($to < $from) {
            throw ApiError::invalid('The last night is before the first.', ['to' => 'Pick a date on or after the first night.']);
        }
        $days = Clock::range($from, $to, 32);
        if (count($days) > 31) {
            throw ApiError::invalid('Leave can be asked for up to 31 nights at a time.', ['to' => 'Pick a shorter stretch.']);
        }
        $earliest = $code === 'sick' ? self::sickFrom() : self::firstNight();
        if ($from < $earliest) {
            throw ApiError::invalid($code === 'sick' ? 'Sick leave can go back 3 nights at most.' : "Leave can only start on a night that hasn't begun yet.",
                ['from' => $code === 'sick' ? 'Pick a later night.' : 'Pick ' . Fmt::day($earliest) . ' or later.']);
        }
        Attendance::assertOpen($days);
        // Company holidays are already days off, so leave never uses them up.
        $centre = Kernel::hr()->table('hr_employees')->where('id', $employeeId)->value('centre');
        $holidays = Attendance::holidaysFor($centre, $from, $to);
        if ($holidays) {
            $days = array_values(array_filter($days, fn($d) => !isset($holidays[$d])));
            if (!$days) {
                throw ApiError::invalid(count($holidays) === 1 ? Fmt::day(array_key_first($holidays)) . ' is a holiday (' . reset($holidays) . '), so no leave is needed.'
                    : 'Those days are all holidays, so no leave is needed.', ['from' => 'Pick a day that isn\'t a holiday.']);
            }
        }
        $db = Kernel::hr();
        $taken = $db->table('hr_leave_days')->where('employee_id', $employeeId)->whereIn('shift_day', $days)->orderBy('shift_day')->pluck('shift_day')->all();
        if ($taken) {
            throw ApiError::conflict('You already have approved leave on ' . implode(', ', array_map([Fmt::class, 'day'], $taken)) . '.');
        }
        foreach ($db->table('hr_requests')->where('employee_id', $employeeId)->where('type', 'leave')->where('status', 'pending')->get(['payload']) as $r) {
            $overlap = array_intersect($days, (json_decode($r->payload, true)['days'] ?? []));
            if ($overlap) {
                throw ApiError::conflict('You already asked for ' . Fmt::day(reset($overlap)) . '. That request is waiting for approval.');
            }
        }
        if ($type->needs_balance) {
            foreach (self::byYear($days) as $year => $inYear) {
                $left = self::available($employeeId, $code, $year);
                if (count($inYear) > $left) {
                    throw ApiError::invalid('You have ' . Fmt::plural(max(0, $left), 'night') . ' of ' . Fmt::lower($type->name) . ' left in ' . $year
                        . ", so this can't be approved. Try fewer nights or unpaid leave.", ['to' => 'More nights than your balance.']);
                }
            }
        }
        $now = Kernel::now();
        $id = $db->table('hr_requests')->insertGetId([
            'employee_id' => $employeeId, 'type' => 'leave', 'status' => 'pending',
            'summary' => self::summary($type->name, $days),
            'payload' => json_encode(['leave_type' => $code, 'days' => $days]),
            'note' => $note, 'created_at' => $now, 'updated_at' => $now,
        ]);
        return ['id' => $id, 'nights' => count($days)];
    }

    /** Record approved nights and use the balance. Runs inside the approval transaction. */
    public static function grant(object $request, array $days): void
    {
        $p = json_decode($request->payload, true) ?: [];
        $code = (string) ($p['leave_type'] ?? '');
        $type = self::type($code);
        $db = Kernel::hr();
        foreach ($days as $d) {
            $db->table('hr_leave_days')->insert([
                'employee_id' => $request->employee_id, 'request_id' => $request->id, 'leave_type' => $code, 'shift_day' => $d,
            ]);
        }
        if ($type->needs_balance) {
            $byYear = [];
            foreach ($days as $d) {
                $byYear[(int) substr($d, 0, 4)] = ($byYear[(int) substr($d, 0, 4)] ?? 0) + 1;
            }
            foreach ($byYear as $year => $n) {
                $key = ['employee_id' => $request->employee_id, 'leave_type' => $code, 'year' => $year];
                if (!$db->table('hr_leave_balances')->where($key)->exists()) {
                    $db->table('hr_leave_balances')->insert($key + ['opening' => 0, 'accrued' => 0, 'used' => 0]);
                }
                $db->table('hr_leave_balances')->where($key)->increment('used', $n);
            }
        }
    }

    /** Everyone's balances for a year, for the admin's opening-balance screen. */
    public static function yearSheet(int $year): array
    {
        $types = array_values(array_filter(self::types(), fn($t) => $t['needs_balance']));
        $people = Kernel::hr()->table('hr_employees')->whereIn('status', ['joining', 'active', 'notice'])->orderBy('full_name')
            ->get(['id', 'full_name', 'centre', 'designation']);
        $rows = Kernel::hr()->table('hr_leave_balances')->where('year', $year)->get();
        $by = [];
        foreach ($rows as $r) {
            $by[(int) $r->employee_id][$r->leave_type] = $r;
        }
        return [
            'year' => $year, 'types' => array_map(fn($t) => ['code' => $t['code'], 'name' => $t['name']], $types),
            'people' => $people->map(fn($e) => [
                'id' => (int) $e->id, 'name' => $e->full_name, 'initials' => Fmt::initials($e->full_name), 'centre' => $e->centre, 'designation' => $e->designation,
                'balances' => array_map(fn($t) => [
                    'code' => $t['code'],
                    'opening' => (float) ($by[(int) $e->id][$t['code']]->opening ?? 0),
                    'accrued' => (float) ($by[(int) $e->id][$t['code']]->accrued ?? 0),
                    'used' => (float) ($by[(int) $e->id][$t['code']]->used ?? 0),
                ], $types),
            ])->all(),
        ];
    }

    /** Set opening balances: [[employee_id, leave_type, opening], ...] for one year. */
    public static function setOpenings(int $year, array $rows, int $accountId): int
    {
        $types = array_column(array_filter(self::types(), fn($t) => $t['needs_balance']), 'name', 'code');
        $db = Kernel::hr();
        $n = 0;
        $db->transaction(function () use ($db, $year, $rows, $types, &$n) {
            foreach ($rows as $r) {
                $eid = (int) ($r['employee_id'] ?? 0);
                $code = (string) ($r['leave_type'] ?? '');
                $v = $r['opening'] ?? null;
                if (!isset($types[$code]) || !is_numeric($v) || $v < 0 || $v > 365 || fmod((float) $v * 2, 1.0) != 0.0) {
                    throw ApiError::invalid('Opening balances are whole or half days between 0 and 365.');
                }
                if (!$db->table('hr_employees')->where('id', $eid)->exists()) {
                    throw ApiError::invalid('One of the people wasn\'t found. Refresh and try again.');
                }
                $key = ['employee_id' => $eid, 'leave_type' => $code, 'year' => $year];
                $cur = $db->table('hr_leave_balances')->where($key)->first();
                if ($cur && (float) $cur->opening === (float) $v) {
                    continue;
                }
                $cur ? $db->table('hr_leave_balances')->where($key)->update(['opening' => $v])
                    : $db->table('hr_leave_balances')->insert($key + ['opening' => $v, 'accrued' => 0, 'used' => 0]);
                $n++;
            }
        });
        return $n;
    }

    /** Who else is off (approved) or asking (pending) on these nights. */
    public static function othersOff(array $days, int $exceptEmployee): array
    {
        $out = array_fill_keys($days, []);
        if (!$days) {
            return $out;
        }
        $db = Kernel::hr();
        $names = self::typeNames();
        $rows = $db->table('hr_leave_days as d')->join('hr_employees as e', 'e.id', '=', 'd.employee_id')
            ->whereIn('d.shift_day', $days)->where('d.employee_id', '!=', $exceptEmployee)
            ->get(['d.shift_day', 'd.leave_type', 'e.full_name', 'e.crm_user_id']);
        foreach ($rows as $r) {
            $out[$r->shift_day][] = ['name' => $r->full_name, 'kind' => $names[$r->leave_type] ?? 'Leave', 'approved' => true, 'crm_user_id' => $r->crm_user_id];
        }
        $pending = $db->table('hr_requests as r')->join('hr_employees as e', 'e.id', '=', 'r.employee_id')
            ->where('r.type', 'leave')->where('r.status', 'pending')->where('r.employee_id', '!=', $exceptEmployee)
            ->get(['r.payload', 'e.full_name', 'e.crm_user_id']);
        foreach ($pending as $r) {
            $p = json_decode($r->payload, true) ?: [];
            foreach (array_intersect($days, $p['days'] ?? []) as $d) {
                $out[$d][] = ['name' => $r->full_name, 'kind' => ($names[$p['leave_type'] ?? ''] ?? 'Leave') . ', not decided yet', 'approved' => false, 'crm_user_id' => $r->crm_user_id];
            }
        }
        return $out;
    }

    /** Approved leave tonight, by CRM user id (for the live floor). */
    public static function onLeave(string $day): array
    {
        $names = self::typeNames();
        $out = [];
        foreach (Kernel::hr()->table('hr_leave_days as d')->join('hr_employees as e', 'e.id', '=', 'd.employee_id')
            ->where('d.shift_day', $day)->get(['d.leave_type', 'e.id', 'e.full_name', 'e.crm_user_id']) as $r) {
            $out[] = ['employee_id' => (int) $r->id, 'crm_user_id' => $r->crm_user_id ? (int) $r->crm_user_id : null, 'name' => $r->full_name, 'kind' => $names[$r->leave_type] ?? 'Leave'];
        }
        return $out;
    }
}
