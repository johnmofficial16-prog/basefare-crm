<?php

namespace App\Services\Buddy;

use App\Services\DatabaseBackupService;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * MaintenanceTools — the maintenance buddy's read-only window into system
 * health. Registered for admins + is_dev users only (BuddyController gates).
 *
 * Data sources: error_log (the pipeline shipped 2026-08-12), schema_migrations
 * ledger, storage/backups/, and cron traces in activity_log. Everything is
 * aggregate or operator-facing diagnostics; no customer tables are touched at
 * all, so this surface is PII-free by construction. (Error messages could
 * still embed PII by accident — the scrubber in BuddyPromptBuilder covers
 * that, and logs any hit as a tool bug.)
 *
 * All handlers capture nothing user-specific — maintenance data is global —
 * but they still go through the registry so every call is audited.
 */
class MaintenanceTools
{
    /**
     * Cron heartbeats: activity_log action → what "healthy" means.
     *
     * The buddy jobs were added after this monitor failed to catch a real
     * outage: buddy_triggers was believed registered in hPanel for two days and
     * was not, so no nudges were ever created and the entire proactive Aisha
     * layer was dead — while every health check stayed green, because the job
     * was not being watched at all. Both buddy crons now write an unconditional
     * heartbeat, which is what makes silence here meaningful.
     */
    private const CRON_TRACES = [
        'auto_clock_out'          => ['label' => 'Auto clock-out',    'stale_hours' => 26],
        'booking_reminder_fired'  => ['label' => 'Booking reminders', 'stale_hours' => null], // event-driven, silence can be normal
        'shift_gap_alert'         => ['label' => 'Shift gap alert',   'stale_hours' => null],
        // Runs every 15 min; an hour of silence means it is not running.
        'buddy_triggers_ran'      => ['label' => 'Buddy triggers (Aisha engine)', 'stale_hours' => 1],
        // Weekly; allow a day of slack before calling it broken.
        'buddy_consolidate_ran'   => ['label' => 'Buddy memory consolidator',     'stale_hours' => 192],
    ];

    public static function registry(int $userId): BuddyToolRegistry
    {
        $r = new BuddyToolRegistry($userId);

        $r->register(
            'get_error_summary',
            'Summarise the CRM error log: counts by severity and the most frequent error messages in the last N hours. Start here for any "how is the system doing" question.',
            [
                'type'       => 'object',
                'properties' => [
                    'hours' => ['type' => 'integer', 'description' => 'Look-back window in hours, 1–168. Default 24.'],
                ],
            ],
            fn(array $a) => self::errorSummary((int) ($a['hours'] ?? 24))
        );

        $r->register(
            'get_recent_errors',
            'List individual recent error rows (newest first), optionally filtered by severity: fatal, error, warning, notice, info, client, critical.',
            [
                'type'       => 'object',
                'properties' => [
                    'severity' => ['type' => 'string', 'description' => 'Optional severity filter.'],
                    'limit'    => ['type' => 'integer', 'description' => 'Max rows, 1–20. Default 10.'],
                ],
            ],
            fn(array $a) => self::recentErrors((string) ($a['severity'] ?? ''), (int) ($a['limit'] ?? 10))
        );

        $r->register(
            'get_backup_status',
            'Report database backup health: newest backups on disk with size and age, and whether the nightly cadence looks broken.',
            [],
            fn() => self::backupStatus()
        );

        $r->register(
            'get_migration_status',
            'Report the schema_migrations ledger: counts by status, the most recent entries, and any migration files on disk that have not been applied.',
            [],
            fn() => self::migrationStatus()
        );

        $r->register(
            'get_cron_health',
            'Report when each cron job last left a trace (auto clock-out, booking reminders, shift gap alert, nightly backup, buddy triggers, buddy consolidator) and flag anything overdue. A job with note "NEVER run" has no heartbeat at all and is almost certainly missing from hPanel — say so plainly and prominently.',
            [],
            fn() => self::cronHealth()
        );

        $r->register(
            'get_buddy_usage',
            'AI buddy usage and estimated Gemini cost: messages and tokens by surface (agent/admin/maintenance) for today and the last 7 days. Use for any cost or usage question.',
            [],
            fn() => self::buddyUsage()
        );

        $r->register(
            'get_system_pulse',
            'Small operational pulse: active users, currently clocked-in agents, transactions and errors in the current business day.',
            [],
            fn() => self::systemPulse()
        );

        return $r;
    }

    // =========================================================================
    // HANDLERS — every field in every return is deliberately whitelisted.
    // =========================================================================

    private static function errorSummary(int $hours): array
    {
        $hours = max(1, min(168, $hours));
        $since = date('Y-m-d H:i:s', time() - $hours * 3600);

        $bySeverity = DB::table('error_log')
            ->where('created_at', '>=', $since)
            ->selectRaw('severity, COUNT(*) AS n')
            ->groupBy('severity')
            ->pluck('n', 'severity')
            ->toArray();

        // Group near-identical messages by their first 120 chars.
        $top = DB::table('error_log')
            ->where('created_at', '>=', $since)
            ->selectRaw('LEFT(message, 120) AS msg, severity, COUNT(*) AS n, MAX(created_at) AS last_seen')
            ->groupBy('msg', 'severity')
            ->orderByDesc('n')
            ->limit(8)
            ->get()
            ->map(fn($r) => [
                'message'   => $r->msg,
                'severity'  => $r->severity,
                'count'     => (int) $r->n,
                'last_seen' => $r->last_seen,
            ])
            ->all();

        return [
            'window_hours' => $hours,
            'total'        => array_sum($bySeverity),
            'by_severity'  => $bySeverity,
            'top_messages' => $top,
        ];
    }

    private static function recentErrors(string $severity, int $limit): array
    {
        $limit = max(1, min(20, $limit));
        $q = DB::table('error_log')->orderByDesc('id')->limit($limit);
        if ($severity !== '' && preg_match('/^[a-z]+$/', $severity)) {
            $q->where('severity', $severity);
        }

        $rows = $q->get()->map(fn($r) => [
            'id'         => (int) $r->id,
            'severity'   => $r->severity,
            'message'    => mb_substr((string) $r->message, 0, 300),
            'file'       => $r->file !== null ? basename((string) $r->file) : null,
            'line'       => $r->line !== null ? (int) $r->line : null,
            'url'        => $r->url !== null ? mb_substr((string) $r->url, 0, 120) : null,
            'user_id'    => $r->user_id !== null ? (int) $r->user_id : null,
            'created_at' => $r->created_at,
        ])->all();

        return ['errors' => $rows];
    }

    private static function backupStatus(): array
    {
        $dir   = DatabaseBackupService::backupDir();
        $files = glob($dir . '/*.sql.gz') ?: [];
        usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));

        $newest = [];
        foreach (array_slice($files, 0, 5) as $f) {
            $newest[] = [
                'file'      => basename($f),
                'size_mb'   => round((filesize($f) ?: 0) / 1048576, 2),
                'age_hours' => round((time() - (filemtime($f) ?: time())) / 3600, 1),
            ];
        }

        $nightly = array_values(array_filter($files, fn($f) => str_starts_with(basename($f), 'nightly_')));
        $newestNightlyAge = $nightly !== []
            ? round((time() - (filemtime($nightly[0]) ?: time())) / 3600, 1)
            : null;

        $verdict = 'no backups on disk yet — the nightly cron may not be registered in hPanel';
        if ($newestNightlyAge !== null) {
            $verdict = $newestNightlyAge <= 26
                ? 'nightly cadence healthy'
                : "STALE — newest nightly backup is {$newestNightlyAge}h old (cadence is 24h)";
        } elseif ($files !== []) {
            $verdict = 'only pre-migration/manual snapshots exist — nightly cron not running yet';
        }

        return [
            'backup_count'          => count($files),
            'newest'                => $newest,
            'newest_nightly_age_h'  => $newestNightlyAge,
            'verdict'               => $verdict,
        ];
    }

    private static function migrationStatus(): array
    {
        $byStatus = DB::table('schema_migrations')
            ->selectRaw('status, COUNT(*) AS n')
            ->groupBy('status')
            ->pluck('n', 'status')
            ->toArray();

        $recent = DB::table('schema_migrations')
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->map(fn($r) => [
                'filename'   => $r->filename,
                'status'     => $r->status,
                'applied_at' => $r->applied_at,
            ])->all();

        // Files on disk the ledger has never seen (pending on next migrate run).
        $ledgered = DB::table('schema_migrations')->pluck('filename')->all();
        $onDisk   = array_map('basename', glob(dirname(__DIR__, 3) . '/database/migrations/*.sql') ?: []);
        $pending  = array_values(array_diff($onDisk, $ledgered));

        return [
            'by_status'      => $byStatus,
            'recent'         => $recent,
            'pending_on_disk' => $pending,
        ];
    }

    private static function cronHealth(): array
    {
        $out = [];
        foreach (self::CRON_TRACES as $action => $meta) {
            $last = DB::table('activity_log')->where('action', $action)->max('created_at');
            $ageH = $last !== null ? round((time() - strtotime($last)) / 3600, 1) : null;
            $note = null;
            if ($meta['stale_hours'] === null) {
                $note = 'event-driven; silence can be normal';
            } elseif ($last === null) {
                // The single most useful thing this tool can say. Say it plainly.
                $note = 'NEVER run — cron almost certainly not registered in hPanel';
            } elseif ($ageH > $meta['stale_hours']) {
                $note = 'expected every ' . $meta['stale_hours'] . 'h or sooner';
            }

            $out[] = [
                'job'         => $meta['label'],
                'last_trace'  => $last,
                'age_hours'   => $ageH,
                'overdue'     => $meta['stale_hours'] !== null && ($ageH === null || $ageH > $meta['stale_hours']),
                'note'        => $note,
            ];
        }

        // Nightly backup heartbeat lives in error_log as an info row.
        $lastBackup = DB::table('error_log')
            ->where('severity', 'info')
            ->where('message', 'like', '[backup]%')
            ->max('created_at');
        $out[] = [
            'job'        => 'Nightly DB backup',
            'last_trace' => $lastBackup,
            'age_hours'  => $lastBackup !== null ? round((time() - strtotime($lastBackup)) / 3600, 1) : null,
            'overdue'    => $lastBackup === null || (time() - strtotime($lastBackup)) > 26 * 3600,
            'note'       => $lastBackup === null ? 'no backup heartbeat yet — cron likely not registered' : null,
        ];

        return ['jobs' => $out];
    }

    /**
     * The P3 "cost tile". Token counts are recorded per model reply by
     * BuddyService; prices are env-tunable so a Google price change is a .env
     * edit, not a deploy (BUDDY_PRICE_IN / BUDDY_PRICE_OUT, USD per 1M tokens).
     */
    private static function buddyUsage(): array
    {
        $priceIn  = (float) ($_ENV['BUDDY_PRICE_IN']  ?? 0.30);
        $priceOut = (float) ($_ENV['BUDDY_PRICE_OUT'] ?? 2.50);

        $windows = [
            'today'  => date('Y-m-d 00:00:00'),
            'last_7_days' => date('Y-m-d H:i:s', time() - 7 * 86400),
        ];
        $out = ['prices_usd_per_1m' => ['in' => $priceIn, 'out' => $priceOut]];

        foreach ($windows as $label => $since) {
            $rows = DB::table('buddy_messages AS m')
                ->join('buddy_conversations AS c', 'c.id', '=', 'm.conversation_id')
                ->where('m.created_at', '>=', $since)
                ->selectRaw('c.kind,
                             SUM(m.role = "user") AS user_msgs,
                             SUM(m.role = "model") AS model_msgs,
                             COALESCE(SUM(m.tokens_in), 0)  AS tin,
                             COALESCE(SUM(m.tokens_out), 0) AS tout')
                ->groupBy('c.kind')->get();

            $surfaces = [];
            $tin = 0;
            $tout = 0;
            foreach ($rows as $rrow) {
                $surfaces[$rrow->kind] = [
                    'user_msgs'  => (int) $rrow->user_msgs,
                    'model_msgs' => (int) $rrow->model_msgs,
                    'tokens_in'  => (int) $rrow->tin,
                    'tokens_out' => (int) $rrow->tout,
                ];
                $tin  += (int) $rrow->tin;
                $tout += (int) $rrow->tout;
            }
            $out[$label] = [
                'surfaces'      => $surfaces,
                'tokens_total'  => ['in' => $tin, 'out' => $tout],
                'est_cost_usd'  => round($tin / 1e6 * $priceIn + $tout / 1e6 * $priceOut, 4),
                'note'          => 'estimate covers recorded buddy turns only; greeting turns and pre-P3 traffic have no token data',
            ];
        }
        return $out;
    }

    private static function systemPulse(): array
    {
        [$dayStart, $dayEnd] = \App\Services\ShiftService::businessDayBounds();

        return [
            'active_users'      => (int) DB::table('users')->where('status', 'active')->whereNull('deleted_at')->count(),
            'clocked_in_now'    => (int) DB::table('attendance_sessions')->where('status', 'active')->count(),
            'txns_business_day' => (int) DB::table('transactions')
                ->whereBetween('created_at', [$dayStart, $dayEnd])
                ->where('status', '!=', 'voided')->count(),
            'errors_business_day' => (int) DB::table('error_log')
                ->whereBetween('created_at', [$dayStart, $dayEnd])
                ->whereNotIn('severity', ['info'])->count(),
            'business_day'      => ['from' => (string) $dayStart, 'to' => (string) $dayEnd],
        ];
    }
}
