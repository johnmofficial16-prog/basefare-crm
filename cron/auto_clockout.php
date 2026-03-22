<?php
/**
 * Auto Clock-Out Cron Script
 * 
 * Architecture Reference: Section 1.7
 * 
 * Run every 15 minutes via cron:
 *   php cron/auto_clockout.php
 * 
 * Logic:
 * - Finds sessions where status='active' and (scheduled_end + 1 hour) < NOW()
 * - Sessions < 24h stale: auto-close with scheduled_end as clock_out
 * - Sessions > 24h stale: auto-close, do NOT compute pay, add to admin queue
 * - All auto-closed sessions get resolution_required = 1
 */

require __DIR__ . '/../vendor/autoload.php';

use Illuminate\Database\Capsule\Manager as Capsule;
use Dotenv\Dotenv;
use App\Models\AttendanceSession;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

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

// Find stale active sessions (scheduled_end + 1 hour < NOW)
$staleSessions = AttendanceSession::where('status', AttendanceSession::STATUS_ACTIVE)
    ->whereRaw("ADDTIME(CONCAT(date, ' ', scheduled_end), '01:00:00') < NOW()")
    ->get();

$processed = 0;

foreach ($staleSessions as $session) {
    $clockIn   = strtotime($session->clock_in);
    $staleHours = (time() - $clockIn) / 3600;

    if ($staleHours < 24) {
        // Normal stale: set clock_out to scheduled_end
        $clockOutTime = $session->date . ' ' . $session->scheduled_end;

        // Calculate totals
        $totalBreakMins = (int) Capsule::table('attendance_breaks')
            ->where('session_id', $session->id)
            ->whereNotNull('break_end')
            ->sum('duration_mins');

        $grossMins  = (int) round((strtotime($clockOutTime) - strtotime($session->clock_in)) / 60);
        $netWorkMins = max(0, $grossMins - $totalBreakMins);

        $session->update([
            'clock_out'           => $clockOutTime,
            'total_work_mins'     => $netWorkMins,
            'total_break_mins'    => $totalBreakMins,
            'status'              => AttendanceSession::STATUS_AUTO_CLOSED,
            'resolution_required' => 1,
        ]);

        echo "  [Auto-close] Session #{$session->id} for user #{$session->user_id} — net {$netWorkMins} mins\n";
    } else {
        // Over 24 hours stale — do NOT compute pay
        $session->update([
            'status'              => AttendanceSession::STATUS_AUTO_CLOSED,
            'resolution_required' => 1,
        ]);

        echo "  [STALE >24h] Session #{$session->id} for user #{$session->user_id} — flagged for admin, NO pay computed\n";
    }

    // Also close any active breaks that were left open
    Capsule::table('attendance_breaks')
        ->where('session_id', $session->id)
        ->whereNull('break_end')
        ->update([
            'break_end'     => date('Y-m-d H:i:s'),
            'duration_mins' => 0,
            'flagged'       => 1,
        ]);

    // Log the auto-close event
    Capsule::table('activity_log')->insert([
        'user_id'     => $session->user_id,
        'action'      => 'auto_clock_out',
        'entity_type' => 'attendance_sessions',
        'entity_id'   => $session->id,
        'details'     => json_encode(['stale_hours' => round($staleHours, 1), 'resolution_required' => true]),
        'ip_address'  => null,
        'created_at'  => date('Y-m-d H:i:s'),
    ]);

    $processed++;
}

echo "[" . date('Y-m-d H:i:s') . "] Done. Processed {$processed} stale session(s).\n";
