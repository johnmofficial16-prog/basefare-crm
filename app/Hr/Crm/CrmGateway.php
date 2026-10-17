<?php

namespace App\Hr\Crm;

use App\Hr\Kernel;
use App\Hr\Support\Clock;

/**
 * The bridge's read side: everything HR needs from the CRM, as plain arrays.
 *
 * READ-ONLY. HR changes the CRM only through the five guarded commands in
 * HR_PLAN.md §6.3 (none are needed yet). Nothing here calls the CRM's own
 * services, because those carry session and cache side-effects.
 *
 * Nights are grouped by SHIFT DAY: a session belongs to the night whose
 * window [rollover, rollover + 24h) contains its clock-in. The CRM's own live
 * board uses the calendar date, which splits a night at midnight.
 */
final class CrmGateway
{
    /** The roles that clock in, as on the CRM live board. */
    public const FLOOR_ROLES = ['agent', 'manager', 'supervisor', 'csa'];

    public const ROLE_LABELS = [
        'admin' => 'Admin', 'manager' => 'Manager', 'supervisor' => 'Team leader', 'agent' => 'Travel agent', 'csa' => 'Customer support',
    ];

    // ---- users --------------------------------------------------------------

    /** @return array<int,object> CRM users by id (deleted ones included, flagged) */
    public static function users(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) {
            return [];
        }
        $rows = Kernel::crm()->table('users')->whereIn('id', $ids)
            ->get(['id', 'name', 'email', 'role', 'status', 'reports_to_id', 'grace_period_mins', 'deleted_at', 'last_activity_at', 'created_at']);
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->id] = $r;
        }
        return $out;
    }

    /** Active CRM logins that no HR person is linked to yet (for "Add a person"). */
    public static function unlinkedUsers(array $linkedIds): array
    {
        $q = Kernel::crm()->table('users')->whereNull('deleted_at')->where('status', 'active')->where('is_dev', 0)->orderBy('name');
        if ($linkedIds) {
            $q->whereNotIn('id', $linkedIds);
        }
        return $q->get(['id', 'name', 'email', 'role'])->map(fn($u) => [
            'id' => (int) $u->id, 'name' => $u->name, 'email' => $u->email, 'role' => self::ROLE_LABELS[$u->role] ?? ucfirst($u->role),
        ])->all();
    }

    // ---- office network -----------------------------------------------------------

    /**
     * Is this address on the office network? Uses the CRM's own list
     * (ip_whitelist: exact addresses, "1.2.3.*" prefixes, or a dynamic-DNS
     * host name), matched exactly as IpRestrictionMiddleware does, so the HR
     * punch button works in exactly the places the CRM does.
     *
     * Unlike the CRM, HR never falls back to "allow": an empty list, or the
     * CRM's restriction being switched off, still means no punch from here.
     *
     * @return array{ok:bool, listed:int}
     */
    public static function officeNetwork(string $ip): array
    {
        $values = Kernel::crm()->table('ip_whitelist')->pluck('ip_address')->all();
        $client = self::normalizeIp($ip);
        if ($client === null) {
            return ['ok' => false, 'listed' => count($values)];
        }
        foreach ($values as $allowed) {
            $allowed = trim((string) $allowed);
            if ($allowed === '') {
                continue;
            }
            if (str_contains($allowed, '*')) {
                $prefix = self::prefix(rtrim($allowed, '*'));
                if ($prefix !== null && str_starts_with($client, $prefix)) {
                    return ['ok' => true, 'listed' => count($values)];
                }
                continue;
            }
            if (!filter_var($allowed, FILTER_VALIDATE_IP)) {
                $resolved = gethostbyname($allowed); // dynamic DNS for a centre without a fixed IP
                if ($resolved === $allowed) {
                    continue;
                }
                $allowed = $resolved;
            }
            if (self::normalizeIp($allowed) === $client) {
                return ['ok' => true, 'listed' => count($values)];
            }
        }
        return ['ok' => false, 'listed' => count($values)];
    }

    private static function normalizeIp(string $ip): ?string
    {
        $bin = @inet_pton(trim($ip));
        return $bin !== false ? inet_ntop($bin) : null;
    }

    private static function prefix(string $raw): ?string
    {
        if (!str_contains($raw, ':')) {
            return $raw; // IPv4 "192.168.1." prefixes are compared as text, as in the CRM
        }
        $norm = self::normalizeIp(rtrim($raw, ':') . '::');
        if ($norm === null) {
            return null;
        }
        $groups = substr_count(rtrim($raw, ':'), ':') + 1;
        return implode(':', array_slice(explode(':', $norm), 0, $groups)) . ':';
    }

    /**
     * The last thing someone did in the CRM between two moments (a booking,
     * a call log, a page action), from the activity log. Shown next to a
     * missing punch-out so the admin can see when they were last active.
     */
    public static function lastActivity(int $userId, string $from, string $to): ?string
    {
        $v = Kernel::crm()->table('activity_log')->where('user_id', $userId)
            ->where('created_at', '>=', $from)->where('created_at', '<', $to)
            ->whereNotIn('action', ['clock_in', 'clock_in_blocked', 'admin_clock_in', 'admin_clock_out', 'auto_clock_out_on_logout',
                'admin_force_end_break', 'booking_reminder_fired', 'break_abuse_detected', 'blocked_login_ip'])
            ->max('created_at');
        return $v ? (string) $v : null;
    }

    // ---- sales ----------------------------------------------------------------

    /**
     * One person's sales from the CRM, month by month (last $months months,
     * current month first), counted the way the Performance module does:
     * approved bookings only; Net MCO = profit_mco − refund_mco_impact.
     * Read-only. Admin view, so the August performance hold doesn't apply.
     *
     * @return array{currency:string, months:list<array>}
     */
    public static function sales(int $crmUserId, int $months = 6): array
    {
        $from = date('Y-m-01', strtotime('first day of -' . ($months - 1) . ' months'));
        $rows = Kernel::crm()->table('transactions')->where('agent_id', $crmUserId)->where('status', 'approved')
            ->where('created_at', '>=', $from . ' 00:00:00')
            ->groupByRaw("DATE_FORMAT(created_at, '%Y-%m')")
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') AS ym, COUNT(*) AS bookings, COALESCE(SUM(profit_mco), 0) AS gross,
                COALESCE(SUM(profit_mco - COALESCE(refund_mco_impact, 0)), 0) AS net")
            ->get()->keyBy('ym');
        $out = [];
        for ($i = 0; $i < $months; $i++) {
            $ym = date('Y-m', strtotime('first day of -' . $i . ' months'));
            $r = $rows[$ym] ?? null;
            $out[] = ['month' => $ym, 'label' => date('M Y', strtotime($ym . '-01')),
                'bookings' => (int) ($r->bookings ?? 0), 'gross' => round((float) ($r->gross ?? 0), 2), 'net' => round((float) ($r->net ?? 0), 2)];
        }
        $currency = 'CAD';
        try {
            $currency = Kernel::crm()->table('system_config')->where('key', 'default_currency')->value('value') ?: 'CAD';
        } catch (\Throwable) {
        }
        return ['currency' => (string) $currency, 'months' => $out];
    }

    // ---- roster -------------------------------------------------------------
    // HR doesn't use shift times. It only warns when the CRM's published roster
    // runs out, because the CRM's clock-in gate stops working without one.


    /** The last night the published roster covers, or null if there is none. */
    public static function rosterEnds(): ?string
    {
        return Kernel::crm()->table('shift_schedules')->where('publish_status', 'published')->max('shift_date');
    }

    // ---- nights (attendance) --------------------------------------------------

    /**
     * Sessions whose clock-in falls in the shift days from..to, grouped by
     * user and shift day. Breaks included.
     *
     * @return array<int, array<string, list<array>>> [user_id][shift_day] => sessions
     */
    public static function nights(string $from, string $to, ?array $userIds = null): array
    {
        [$start] = Clock::sessionWindow($from);
        [, $end] = Clock::sessionWindow($to);
        $q = Kernel::crm()->table('attendance_sessions')
            ->whereBetween('date', [$from, Clock::addDays($to, 1)])
            ->where('clock_in', '>=', $start)->where('clock_in', '<', $end);
        if ($userIds !== null) {
            if (!$userIds) {
                return [];
            }
            $q->whereIn('user_id', $userIds);
        }
        $sessions = $q->orderBy('clock_in')->get();
        return self::group($sessions);
    }

    /** Sessions still open now, whatever night they started (a forgotten clock-out). */
    public static function openSessions(): array
    {
        return self::group(Kernel::crm()->table('attendance_sessions')->where('status', 'active')->orderBy('clock_in')->get());
    }

    private static function group(iterable $sessions): array
    {
        $list = [];
        $ids = [];
        foreach ($sessions as $s) {
            $ids[] = (int) $s->id;
            $list[] = $s;
        }
        $breaks = [];
        if ($ids) {
            foreach (Kernel::crm()->table('attendance_breaks')->whereIn('session_id', $ids)->orderBy('break_start')->get() as $b) {
                $breaks[(int) $b->session_id][] = [
                    'type' => $b->break_type, 'start' => $b->break_start, 'end' => $b->break_end,
                    'minutes' => $b->duration_mins !== null ? (int) $b->duration_mins : null, 'flagged' => (bool) $b->flagged,
                ];
            }
        }
        $out = [];
        foreach ($list as $s) {
            $day = Clock::sessionNight((string) $s->clock_in);
            $out[(int) $s->user_id][$day][] = [
                'id'          => (int) $s->id,
                'day'         => $day,
                'clock_in'    => $s->clock_in,
                'clock_out'   => $s->clock_out,
                'status'      => $s->status,
                'work_mins'   => $s->total_work_mins !== null ? (int) $s->total_work_mins : null,
                'break_mins'  => (int) $s->total_break_mins,
                'late_mins'   => (int) $s->late_minutes,
                'sched_start' => substr((string) $s->scheduled_start, 0, 5),
                'sched_end'   => substr((string) $s->scheduled_end, 0, 5),
                'flagged'     => (bool) $s->resolution_required,
                'via'         => $s->created_via ?? 'self',
                'breaks'      => $breaks[(int) $s->id] ?? [],
            ];
        }
        return $out;
    }

    /**
     * Late-login decisions made in the CRM for a shift day, newest first.
     * Matched by shift_date or by being made inside the night's window
     * (approvals after midnight carry the next calendar date).
     */
    public static function overrides(string $day): array
    {
        [$start, $end] = Clock::window($day);
        $rows = Kernel::crm()->table('attendance_overrides as o')->leftJoin('users as a', 'a.id', '=', 'o.override_by')
            ->where(fn($q) => $q->where('o.shift_date', $day)->orWhereBetween('o.created_at', [$start, $end]))
            ->orderByDesc('o.created_at')
            ->get(['o.id', 'o.agent_id', 'o.override_type', 'o.reason', 'o.created_at', 'a.name as by_name']);
        return $rows->map(fn($o) => [
            'id' => (int) $o->id, 'user_id' => (int) $o->agent_id, 'type' => $o->override_type,
            'reason' => $o->reason, 'at' => $o->created_at, 'by' => $o->by_name,
        ])->all();
    }

    /** CRM users who tried to clock in and were blocked since the night began. */
    public static function blockedSince(string $since): array
    {
        return Kernel::crm()->table('activity_log')->where('action', 'clock_in_blocked')->where('created_at', '>=', $since)
            ->groupBy('user_id')->selectRaw('user_id, MAX(created_at) as at')->pluck('at', 'user_id')->all();
    }

    // ---- the live floor -------------------------------------------------------

    /**
     * Tonight on the floor, person by person, from clock-ins only (no roster
     * or shift times: John, 16 Oct).
     *
     * States: working, on_break, finished, asking (blocked from clocking in
     * and waiting for a decision in the CRM), not_in (no clock-in tonight).
     */
    public static function floor(string $day): array
    {
        [$start] = Clock::window($day);
        $staff = Kernel::crm()->table('users')->whereIn('role', self::FLOOR_ROLES)->where('status', 'active')
            ->whereNull('deleted_at')->where('is_dev', 0)->orderBy('name')->get(['id', 'name', 'role']);
        $nights = self::nights($day, $day);
        $open = self::openSessions();
        $overrides = self::overrides($day);
        $blocked = self::blockedSince($start);

        // A CRM decision to let them in ends the wait (the CRM's own gate, not an HR late mark).
        $letIn = [];
        foreach ($overrides as $o) {
            if ($o['type'] === 'late_login') {
                $letIn[$o['user_id']] = true;
            }
        }

        $people = [];
        $counts = ['working' => 0, 'on_break' => 0, 'finished' => 0, 'asking' => 0, 'not_in' => 0];
        foreach ($staff as $u) {
            $uid = (int) $u->id;
            $sessions = $nights[$uid][$day] ?? [];
            // A session still open from an earlier night means they're working now.
            foreach ($open[$uid] ?? [] as $openDay => $list) {
                if ($openDay !== $day) {
                    foreach ($list as $s) {
                        $sessions[] = $s;
                    }
                }
            }
            $current = null;
            foreach ($sessions as $s) {
                if ($s['status'] === 'active') {
                    $current = $s;
                }
            }
            $last = $current ?? ($sessions ? end($sessions) : null);
            $break = null;
            if ($current) {
                foreach ($current['breaks'] as $b) {
                    if ($b['end'] === null) {
                        $break = $b;
                    }
                }
            }
            $state = match (true) {
                $current !== null && $break !== null => 'on_break',
                $current !== null => 'working',
                $last !== null => 'finished',
                isset($blocked[$uid]) && !isset($letIn[$uid]) => 'asking',
                default => 'not_in',
            };
            $counts[$state]++;
            $people[$uid] = [
                'crm_user_id' => $uid,
                'name'        => $u->name,
                'role'        => self::ROLE_LABELS[$u->role] ?? ucfirst($u->role),
                'state'       => $state,
                'source'      => 'crm',
                'clock_in'    => $last['clock_in'] ?? null,
                'clock_out'   => $last['clock_out'] ?? null,
                'via'         => $last['via'] ?? null,
                'break'       => $break ? ['type' => $break['type'], 'since' => $break['start']] : null,
                'asking_since' => $state === 'asking' ? $blocked[$uid] : null,
                'stale_open'  => $current !== null && $current['day'] < $day, // an early clock-in for the coming night isn't stale
            ];
        }
        return ['day' => $day, 'people' => $people, 'counts' => $counts];
    }

    /**
     * Nights the CRM closed by itself or flagged for review between two shift
     * days (inclusive): the raw material for "missed clock-out" fixes.
     */
    public static function exceptions(string $from, string $to, ?int $userId = null): array
    {
        $out = [];
        foreach (self::nights($from, $to, $userId !== null ? [$userId] : null) as $uid => $days) {
            foreach ($days as $day => $sessions) {
                foreach ($sessions as $s) {
                    if ($s['status'] === 'auto_closed' || $s['flagged']) {
                        $out[] = ['user_id' => $uid] + $s;
                    }
                }
            }
        }
        usort($out, fn($a, $b) => strcmp($b['clock_in'], $a['clock_in']));
        return $out;
    }
}
