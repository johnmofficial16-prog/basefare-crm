<?php
/**
 * Auto Clock-Out Cron Script
 *
 * Architecture Reference: Section 1.7
 *
 * Run every 15 minutes via cron:
 *   php /home/u501549865/domains/base-fare.com/public_html/crm/cron/auto_clockout.php
 *
 * Logic:
 * - Finds sessions where status='active' and (scheduled_end + 1 hour) < NOW()
 * - For overnight shifts (scheduled_end < scheduled_start), adds 24h to scheduled_end before comparison
 * - Sessions < 24h stale: auto-close with scheduled_end as clock_out
 * - Sessions > 24h stale: auto-close, do NOT compute pay, add to admin queue
 * - All auto-closed sessions get resolution_required = 1
 * - Open breaks are force-closed at time of auto-close
 */

require __DIR__ . '/../vendor/autoload.php';

use Illuminate\Database\Capsule\Manager as Capsule;
use Dotenv\Dotenv;
use App\Models\AttendanceSession;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

// Set timezone consistently with the web app
date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'Asia/Kolkata');

$capsule = new Capsule;
$capsule->addConnection([
    'driver'    => 'mysql',
    'host'      => $_ENV['DB_HOST'],
    'database'  => $_ENV['DB_DATABASE'],
    'username'  => $_ENV['DB_USERNAME'],
    'password'  => $_ENV['DB_PASSWORD'],
    'charset'   => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
]);
$capsule->setAsGlobal();
$capsule->bootEloquent();

echo "[" . date('Y-m-d H:i:s') . "] Auto clock-out cron starting...\n";

/**
 * Compute the correct scheduled_end timestamp for a session,
 * handling overnight shifts where scheduled_end < scheduled_start.
 *
 * @param  AttendanceSession $session
 * @return int  Unix timestamp of the true scheduled end
 */
function getScheduledEndTimestamp(AttendanceSession $session): int
{
    $date         = is_string($session->date) ? $session->date : $session->date->format('Y-m-d');
    $schedStart   = $session->scheduled_start ?? '09:00:00';
    $schedEnd     = $session->scheduled_end   ?? '18:00:00';

    $startTs = strtotime($date . ' ' . $schedStart);
    $endTs   = strtotime($date . ' ' . $schedEnd);

    // Overnight shift: end time is on the next calendar day
    if ($endTs <= $startTs) {
        $endTs += 86400;
    }

    return $endTs;
}

// ── Find stale active sessions ────────────────────────────────────────────────
// We cannot use a raw SQL ADDTIME for overnight shifts (end < start means the
// SQL comparison would fire immediately). Instead, fetch all active sessions
// and filter in PHP using the correct overnight-aware timestamp.
$activeSessions = AttendanceSession::where('status', AttendanceSession::STATUS_ACTIVE)->get();

$now       = time();
$processed = 0;

foreach ($activeSessions as $session) {
    $scheduledEndTs = getScheduledEndTimestamp($session);
    $cutoffTs       = $scheduledEndTs + 3600; // 1 hour grace after scheduled end

    // Not stale yet — skip
    if ($now < $cutoffTs) {
        continue;
    }

    $clockInTs  = strtotime($session->clock_in);
    $staleHours = ($now - $clockInTs) / 3600;

    if ($staleHours < 24) {
        // ── Normal stale: set clock_out to scheduled_end (overnight-aware) ──
        $clockOutTime = date('Y-m-d H:i:s', $scheduledEndTs);

        // Close any open breaks first (set duration_mins = 0, flagged = 1)
        Capsule::table('attendance_breaks')
            ->where('session_id', $session->id)
            ->whereNull('break_end')
            ->update([
                'break_end'     => $clockOutTime,
                'duration_mins' => 0,
                'flagged'       => 1,
            ]);

        // Recompute totals after closing breaks
        $totalBreakMins = (int) Capsule::table('attendance_breaks')
            ->where('session_id', $session->id)
            ->whereNotNull('break_end')
            ->sum('duration_mins');

        $grossMins   = (int) round(($scheduledEndTs - $clockInTs) / 60);
        $netWorkMins = max(0, $grossMins - $totalBreakMins);

        $session->update([
            'clock_out'           => $clockOutTime,
            'total_work_mins'     => $netWorkMins,
            'total_break_mins'    => $totalBreakMins,
            'status'              => AttendanceSession::STATUS_AUTO_CLOSED,
            'resolution_required' => 1,
        ]);

        echo "  [Auto-close] Session #{$session->id} for user #{$session->user_id}"
            . " — scheduled_end: {$clockOutTime}, net {$netWorkMins} mins\n";
    } else {
        // ── Over 24 hours stale — do NOT compute pay ──────────────────────
        // Close any open breaks
        Capsule::table('attendance_breaks')
            ->where('session_id', $session->id)
            ->whereNull('break_end')
            ->update([
                'break_end'     => date('Y-m-d H:i:s'),
                'duration_mins' => 0,
                'flagged'       => 1,
            ]);

        $session->update([
            'status'              => AttendanceSession::STATUS_AUTO_CLOSED,
            'resolution_required' => 1,
        ]);

        echo "  [STALE >24h] Session #{$session->id} for user #{$session->user_id}"
            . " — flagged for admin, NO pay computed\n";
    }

    // Log the auto-close event
    Capsule::table('activity_log')->insert([
        'user_id'     => $session->user_id,
        'action'      => 'auto_clock_out',
        'entity_type' => 'attendance_sessions',
        'entity_id'   => $session->id,
        'details'     => json_encode([
            'stale_hours'         => round($staleHours, 1),
            'resolution_required' => true,
            'scheduled_end_used'  => date('Y-m-d H:i:s', $scheduledEndTs),
            'was_overnight_shift' => strtotime($session->date . ' ' . $session->scheduled_end) <= strtotime($session->date . ' ' . $session->scheduled_start),
        ]),
        'ip_address'  => null,
        'created_at'  => date('Y-m-d H:i:s'),
    ]);

    $processed++;
}

echo "[" . date('Y-m-d H:i:s') . "] Done. Processed {$processed} stale session(s).\n";
