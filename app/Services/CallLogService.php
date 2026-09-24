<?php

namespace App\Services;

use App\Models\AttendanceSession;
use App\Models\CallLog;
use App\Models\Notification;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * CallLogService — the single source of truth for call-log rules:
 *
 *   - which business (shift) day a call belongs to, and which days are off,
 *   - who a team lead may see,
 *   - the live per-agent board (calls vs. clocked-in time),
 *   - compliance alerts (hourly cron) and follow-up call-back reminders.
 *
 * Shared by CallLogController and cron/call_log_compliance.php.
 */
class CallLogService
{
    /** Roles expected to log calls (and therefore checked by compliance). */
    const LOGGER_ROLES = [User::ROLE_AGENT, User::ROLE_CSA];

    // ─── Config (system_config, all optional) ─────────────────────────────────

    const DEFAULTS = [
        // Carbon dayOfWeek of the SHIFT START date: 0 = Sunday … 6 = Saturday.
        // The Saturday-18:00 and Sunday-18:00 shifts are the US weekend.
        'call_log_off_days'      => '6,0',
        // Clocked in this long with zero calls logged → flagged.
        'call_log_grace_hours'   => '2',
        // Has logged before, but nothing for this long while clocked in → flagged.
        'call_log_quiet_hours'   => '3',
        // Don't re-alert about the same agent for the same problem sooner than this.
        'call_log_realert_hours' => '3',
        // Hour (IST) after which the finished shift's summary goes out.
        'call_log_summary_hour'  => '10',
    ];

    private array $config = [];

    public function config(string $key): string
    {
        if (!array_key_exists($key, $this->config)) {
            $val = DB::table('system_config')->where('key', $key)->value('value');
            $this->config[$key] = ($val === null || $val === '') ? self::DEFAULTS[$key] : (string) $val;
        }
        return $this->config[$key];
    }

    public function configInt(string $key, int $min, int $max): int
    {
        return max($min, min($max, (int) $this->config($key)));
    }

    /** @return int[] */
    public function offDays(): array
    {
        // 'none' (or any non-numeric value) = no off days.
        $days = array_filter(array_map('trim', explode(',', $this->config('call_log_off_days'))), 'is_numeric');
        return array_values(array_unique(array_map(fn ($d) => max(0, min(6, (int) $d)), $days)));
    }

    public function saveConfig(array $values, int $userId): void
    {
        foreach ($values as $key => $value) {
            if (!array_key_exists($key, self::DEFAULTS)) {
                continue;
            }
            $exists = DB::table('system_config')->where('key', $key)->exists();
            $row = ['value' => (string) $value, 'updated_by' => $userId];
            if ($exists) {
                DB::table('system_config')->where('key', $key)->update($row);
            } else {
                DB::table('system_config')->insert(['key' => $key] + $row);
            }
            $this->config[$key] = (string) $value;
        }
    }

    // ─── Shift day ────────────────────────────────────────────────────────────

    /** Business day (date the shift started) that a moment belongs to. */
    public function shiftDateFor(?Carbon $at = null): string
    {
        $at = $at ?? Carbon::now();
        return $at->copy()->subHours(ShiftService::businessDayStartHour())->toDateString();
    }

    /** @return array{0: Carbon, 1: Carbon} inclusive window of a shift day */
    public function shiftWindow(string $shiftDate): array
    {
        $start = Carbon::parse($shiftDate)->addHours(ShiftService::businessDayStartHour());
        return [$start, $start->copy()->addDay()->subSecond()];
    }

    public function isOffDay(string $shiftDate): bool
    {
        return in_array(Carbon::parse($shiftDate)->dayOfWeek, $this->offDays(), true);
    }

    /** The working shift day before $shiftDate, skipping off days. */
    public function previousWorkingDay(string $shiftDate): string
    {
        $d = Carbon::parse($shiftDate);
        for ($i = 0; $i < 7; $i++) {
            $d->subDay();
            if (!$this->isOffDay($d->toDateString())) {
                break;
            }
        }
        return $d->toDateString();
    }

    // ─── Scope ────────────────────────────────────────────────────────────────

    /**
     * User IDs a viewer may see logs for. null = everyone (admin).
     * Team leads get their reports two levels down (manager → supervisor →
     * agent) plus themselves.
     */
    public function visibleUserIds(int $userId, string $role): ?array
    {
        if ($role === User::ROLE_ADMIN) {
            return null;
        }
        if (!in_array($role, [User::ROLE_MANAGER, User::ROLE_SUPERVISOR], true)) {
            return [$userId];
        }
        $ids      = [$userId];
        $frontier = [$userId];
        for ($depth = 0; $depth < 3 && $frontier; $depth++) {
            $next = User::whereIn('reports_to_id', $frontier)
                ->whereNull('deleted_at')
                ->pluck('id')->map(fn ($v) => (int) $v)->toArray();
            $next     = array_values(array_diff($next, $ids));
            $ids      = array_merge($ids, $next);
            $frontier = $next;
        }
        return $ids;
    }

    /** Active users expected to log calls, optionally limited to a scope. */
    public function loggers(?array $scope = null)
    {
        return User::whereIn('role', self::LOGGER_ROLES)
            ->where('status', User::STATUS_ACTIVE)
            ->whereNull('deleted_at')
            ->when($scope !== null, fn ($q) => $q->whereIn('id', $scope))
            ->orderBy('name')
            ->get(['id', 'name', 'role', 'reports_to_id']);
    }

    // ─── Board ────────────────────────────────────────────────────────────────

    /**
     * Per-agent status for one shift day.
     *
     * status: logging | quiet | no_logs | not_in | off_day
     *
     * @return array<int, array>  keyed by agent id
     */
    public function board(string $shiftDate, $agents): array
    {
        $ids = $agents->pluck('id')->map(fn ($v) => (int) $v)->toArray();
        if (!$ids) {
            return [];
        }
        [$winStart, $winEnd] = $this->shiftWindow($shiftDate);
        $now      = Carbon::now();
        $isToday  = $shiftDate === $this->shiftDateFor($now);
        $offDay   = $this->isOffDay($shiftDate);
        $grace    = $this->configInt('call_log_grace_hours', 1, 12);
        $quiet    = $this->configInt('call_log_quiet_hours', 1, 12);

        $calls = CallLog::where('shift_date', $shiftDate)
            ->whereIn('agent_id', $ids)
            ->selectRaw("agent_id, COUNT(*) AS calls, SUM(outcome = 'booked') AS booked, MAX(created_at) AS last_at")
            ->groupBy('agent_id')
            ->get()->keyBy('agent_id');

        // First clock-in inside the shift window, and whether still clocked in.
        $sessions = AttendanceSession::whereIn('user_id', $ids)
            ->whereBetween('clock_in', [$winStart->format('Y-m-d H:i:s'), $winEnd->format('Y-m-d H:i:s')])
            ->orderBy('clock_in')
            ->get(['user_id', 'clock_in', 'clock_out', 'status'])
            ->groupBy('user_id');

        $rows = [];
        foreach ($agents as $agent) {
            $aid   = (int) $agent->id;
            $c     = $calls->get($aid);
            $sess  = $sessions->get($aid);
            $first = $sess ? Carbon::parse($sess->first()->clock_in) : null;
            $open  = $sess ? $sess->first(fn ($s) => $s->clock_out === null) : null;
            $lastAt = $c && $c->last_at ? Carbon::parse($c->last_at) : null;

            $inForMins = 0;
            if ($first) {
                $until = $open ? $now : Carbon::parse($sess->max('clock_out'));
                $inForMins = max(0, $first->diffInMinutes($until));
            }

            $n = $c ? (int) $c->calls : 0;
            if ($offDay && $n === 0) {
                $status = 'off_day';
            } elseif (!$first) {
                $status = $n > 0 ? 'logging' : 'not_in';
            } elseif ($n === 0) {
                $status = ($inForMins >= $grace * 60) ? 'no_logs' : 'logging';
            } elseif ($isToday && $open && $lastAt && $lastAt->diffInMinutes($now) >= $quiet * 60) {
                $status = 'quiet';
            } else {
                $status = 'logging';
            }
            // "Just clocked in, nothing yet" is not a problem — show it neutrally.
            if ($status === 'logging' && $n === 0) {
                $status = 'warming_up';
            }

            $rows[$aid] = [
                'id'          => $aid,
                'name'        => $agent->name,
                'calls'       => $n,
                'booked'      => $c ? (int) $c->booked : 0,
                'last_at'     => $lastAt,
                'clocked_in'  => $first,
                'clocked_now' => (bool) $open,
                'in_mins'     => $inForMins,
                'status'      => $status,
            ];
        }
        return $rows;
    }

    // ─── Agent stats ──────────────────────────────────────────────────────────

    public function agentToday(int $agentId): array
    {
        $day   = $this->shiftDateFor();
        $row   = CallLog::where('agent_id', $agentId)->where('shift_date', $day)
            ->selectRaw("COUNT(*) AS calls, SUM(outcome = 'booked') AS booked")
            ->first();
        $calls  = (int) ($row->calls ?? 0);
        $booked = (int) ($row->booked ?? 0);
        $dueFollowUps = CallLog::where('agent_id', $agentId)
            ->where('follow_up_done', 0)->whereNotNull('follow_up_at')
            ->where('follow_up_at', '<=', Carbon::now()->addHour()->format('Y-m-d H:i:s'))
            ->count();

        return [
            'shift_date'  => $day,
            'calls'       => $calls,
            'booked'      => $booked,
            'conversion'  => $calls > 0 ? (int) round($booked * 100 / $calls) : 0,
            'follow_ups'  => $dueFollowUps,
            'streak'      => $this->streak($agentId),
        ];
    }

    /**
     * Consecutive working shift days with at least one call logged, counting
     * back from today (today counts once something is logged; an empty today
     * doesn't break yesterday's streak). Off days are skipped, never break it.
     */
    public function streak(int $agentId): int
    {
        $today = $this->shiftDateFor();
        $days  = CallLog::where('agent_id', $agentId)
            ->where('shift_date', '>=', Carbon::parse($today)->subDays(90)->toDateString())
            ->distinct()->pluck('shift_date')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())->flip();

        $streak = 0;
        $d = Carbon::parse($today);
        if (!isset($days[$today])) {
            $d = Carbon::parse($this->previousWorkingDay($today));
        }
        for ($i = 0; $i < 90; $i++) {
            $ds = $d->toDateString();
            if ($this->isOffDay($ds)) {
                $d->subDay();
                continue;
            }
            if (!isset($days[$ds])) {
                break;
            }
            $streak++;
            $d->subDay();
        }
        return $streak;
    }

    /** Agent's most-used airlines (for quick chips), topped up with common ones. */
    public function favouriteAirlines(int $agentId, int $limit = 8): array
    {
        $mine = CallLog::where('agent_id', $agentId)
            ->where('created_at', '>=', Carbon::now()->subDays(45)->format('Y-m-d H:i:s'))
            ->whereNotIn('airline', ['Not decided', 'Multiple airlines'])
            ->selectRaw('airline, COUNT(*) AS n')->groupBy('airline')
            ->orderByDesc('n')->limit($limit)->pluck('airline')->toArray();
        foreach (CallLog::COMMON_AIRLINES as $a) {
            if (count($mine) >= $limit) {
                break;
            }
            if (!in_array($a, $mine, true)) {
                $mine[] = $a;
            }
        }
        return $mine;
    }

    // ─── Follow-up reminders ──────────────────────────────────────────────────

    /**
     * Ring the agent's bell for every call-back that is due. Called by the
     * cron and lazily by the bell poll; the conditional UPDATE is the gate, so
     * concurrent callers can't double-notify.
     */
    public function dispatchDueFollowUps(): int
    {
        $now = Carbon::now()->format('Y-m-d H:i:s');
        $due = CallLog::where('follow_up_done', 0)
            ->whereNull('follow_up_notified_at')
            ->whereNotNull('follow_up_at')
            ->where('follow_up_at', '<=', $now)
            ->limit(200)->get();

        $sent = 0;
        foreach ($due as $log) {
            $claimed = CallLog::where('id', $log->id)->whereNull('follow_up_notified_at')
                ->update(['follow_up_notified_at' => $now]);
            if ($claimed === 0) {
                continue;
            }
            $who = trim(($log->customer_name ? $log->customer_name . ' · ' : '') . $log->phone);
            Notification::create([
                'user_id' => $log->agent_id,
                'type'    => Notification::TYPE_CALL_FOLLOWUP,
                'title'   => '📞 Call back now: ' . $who,
                'body'    => $log->reasonLabel() . ' · ' . $log->airline . ($log->notes ? "\n" . mb_strimwidth($log->notes, 0, 160, '…') : ''),
                'link'    => '/call-logs#followups',
            ]);
            $sent++;
        }
        return $sent;
    }

    // ─── Compliance (cron) ────────────────────────────────────────────────────

    /**
     * Lazy trigger for the bell poll, so alerts still go out if the hourly cron
     * was never registered. Runs at most once per 15 minutes across ALL users:
     * the conditional UPDATE on system_config is the lock — only the one
     * request that moves the timestamp forward gets to run.
     */
    public function maybeRunCompliance(): void
    {
        $now    = Carbon::now();
        $cutoff = $now->copy()->subMinutes(15)->format('Y-m-d H:i:s');
        DB::statement(
            "INSERT IGNORE INTO system_config (`key`, `value`) VALUES ('call_log_last_run', '2000-01-01 00:00:00')"
        );
        $claimed = DB::table('system_config')
            ->where('key', 'call_log_last_run')
            ->where('value', '<', $cutoff)
            ->update(['value' => $now->format('Y-m-d H:i:s')]);
        if ($claimed === 1) {
            $this->runCompliance($now);
        }
    }

    /**
     * One compliance pass. Safe to run as often as you like — every alert is
     * recorded in call_log_alerts and not repeated inside the re-alert window.
     *
     * @return array summary for the cron log
     */
    public function runCompliance(?Carbon $now = null): array
    {
        $now     = $now ?? Carbon::now();
        $today   = $this->shiftDateFor($now);
        $result  = ['shift_date' => $today, 'off_day' => $this->isOffDay($today), 'flagged' => [], 'summary' => null];

        if (!$result['off_day']) {
            $result['flagged'] = $this->alertLiveGaps($today, $now);
        }
        $result['summary'] = $this->maybeSendSummary($today, $now);
        return $result;
    }

    /** Hourly: agents clocked in right now who aren't logging. */
    private function alertLiveGaps(string $shiftDate, Carbon $now): array
    {
        $agents  = $this->loggers();
        $board   = $this->board($shiftDate, $agents);
        $realert = $this->configInt('call_log_realert_hours', 1, 24);

        $flagged = [];
        foreach ($board as $row) {
            if (!$row['clocked_now'] || !in_array($row['status'], ['no_logs', 'quiet'], true)) {
                continue;
            }
            $last = DB::table('call_log_alerts')
                ->where('shift_date', $shiftDate)->where('kind', $row['status'])->where('agent_id', $row['id'])
                ->max('sent_at');
            if ($last && Carbon::parse($last)->diffInMinutes($now) < $realert * 60) {
                continue;
            }
            $flagged[$row['id']] = $row;
        }
        if (!$flagged) {
            return [];
        }

        // Agent nudge — friendly, points straight at the log form.
        foreach ($flagged as $row) {
            $title = $row['status'] === 'no_logs'
                ? '📋 No calls logged yet this shift'
                : '📋 No call logged in the last ' . $this->hoursText($row['last_at'], $now);
            Notification::create([
                'user_id' => $row['id'],
                'type'    => Notification::TYPE_CALL_COMPLIANCE,
                'title'   => $title,
                'body'    => 'Takes 10 seconds — number, reason, airline. Tap to log your calls.',
                'link'    => '/call-logs',
            ]);
        }

        // Digest for admins (everyone) and team leads (their own people).
        $lines = [];
        foreach ($flagged as $row) {
            $lines[$row['id']] = $this->gapLine($row, $now);
        }
        $this->notifyLeads(
            $lines,
            fn (int $n) => '📵 ' . $n . ' agent' . ($n === 1 ? ' is' : 's are') . ' not logging calls',
            Notification::TYPE_CALL_COMPLIANCE,
            '/call-logs/team'
        );

        $stamp = $now->format('Y-m-d H:i:s');
        foreach ($flagged as $row) {
            DB::table('call_log_alerts')->insert([
                'shift_date' => $shiftDate, 'kind' => $row['status'], 'agent_id' => $row['id'], 'sent_at' => $stamp,
            ]);
        }
        return array_values(array_map(fn ($r) => $r['name'] . ' (' . $r['status'] . ')', $flagged));
    }

    /**
     * Once the overnight shift is over (after the summary hour, before the next
     * shift starts), send leads a recap of that shift. Once per shift day.
     */
    private function maybeSendSummary(string $shiftDate, Carbon $now): ?string
    {
        $rollover = ShiftService::businessDayStartHour();
        $summaryHour = $this->configInt('call_log_summary_hour', 0, 23);
        if ($now->hour < $summaryHour || $now->hour >= $rollover) {
            return null;
        }
        if ($this->isOffDay($shiftDate)) {
            return null;
        }
        $already = DB::table('call_log_alerts')
            ->where('shift_date', $shiftDate)->where('kind', 'shift_summary')->exists();
        if ($already) {
            return null;
        }
        // Record first so a crash mid-send can't produce a second summary.
        DB::table('call_log_alerts')->insert([
            'shift_date' => $shiftDate, 'kind' => 'shift_summary', 'agent_id' => null,
            'sent_at' => $now->format('Y-m-d H:i:s'),
        ]);

        $board = $this->board($shiftDate, $this->loggers());
        $label = Carbon::parse($shiftDate)->format('D j M');

        $recipients = $this->leadRecipients();
        foreach ($recipients as $lead) {
            $mine = $lead['scope'] === null
                ? $board
                : array_intersect_key($board, array_flip($lead['scope']));
            $worked = array_filter($mine, fn ($r) => $r['clocked_in'] !== null || $r['calls'] > 0);
            if (!$worked) {
                continue;
            }
            $calls  = array_sum(array_column($worked, 'calls'));
            $booked = array_sum(array_column($worked, 'booked'));
            $silent = array_filter($worked, fn ($r) => $r['calls'] === 0);

            $body = count($worked) . ' agents worked · ' . $calls . ' calls logged · ' . $booked . ' booked.';
            if ($silent) {
                $body .= "\nLogged nothing: " . implode(', ', array_map(
                    fn ($r) => $r['name'] . ' (' . $this->minsText($r['in_mins']) . ' clocked in)', $silent
                ));
            } else {
                $body .= "\nEveryone who worked logged their calls. ✅";
            }
            Notification::create([
                'user_id' => $lead['id'],
                'type'    => Notification::TYPE_CALL_COMPLIANCE,
                'title'   => ($silent ? '⚠️ ' : '✅ ') . 'Call logs — shift of ' . $label,
                'body'    => $body,
                'link'    => '/call-logs/team?date=' . $shiftDate,
            ]);
        }
        return $label;
    }

    /**
     * Admins get every line; managers/supervisors get only lines for their own
     * people. $lines is keyed by agent id.
     */
    private function notifyLeads(array $lines, callable $title, string $type, string $link): void
    {
        foreach ($this->leadRecipients() as $lead) {
            $mine = $lead['scope'] === null ? $lines : array_intersect_key($lines, array_flip($lead['scope']));
            unset($mine[$lead['id']]);
            if (!$mine) {
                continue;
            }
            Notification::create([
                'user_id' => $lead['id'],
                'type'    => $type,
                'title'   => $title(count($mine)),
                'body'    => implode("\n", $mine),
                'link'    => $link,
            ]);
        }
    }

    /** @return array<int, array{id:int, scope:?array}> */
    private function leadRecipients(): array
    {
        $out = [];
        $leads = User::whereIn('role', [User::ROLE_ADMIN, User::ROLE_MANAGER, User::ROLE_SUPERVISOR])
            ->where('status', User::STATUS_ACTIVE)->whereNull('deleted_at')
            ->get(['id', 'role']);
        foreach ($leads as $lead) {
            $out[] = [
                'id'    => (int) $lead->id,
                'scope' => $this->visibleUserIds((int) $lead->id, $lead->role),
            ];
        }
        return $out;
    }

    private function gapLine(array $row, Carbon $now): string
    {
        if ($row['status'] === 'no_logs') {
            return '• ' . $row['name'] . ' — clocked in ' . $this->minsText($row['in_mins']) . ', 0 calls logged';
        }
        return '• ' . $row['name'] . ' — ' . $row['calls'] . ' calls, none in the last ' . $this->hoursText($row['last_at'], $now);
    }

    public function minsText(int $mins): string
    {
        if ($mins < 60) {
            return $mins . 'm';
        }
        $h = intdiv($mins, 60);
        $m = $mins % 60;
        return $h . 'h' . ($m ? ' ' . $m . 'm' : '');
    }

    private function hoursText(?Carbon $since, Carbon $now): string
    {
        return $since ? $this->minsText((int) $since->diffInMinutes($now)) : '—';
    }
}
