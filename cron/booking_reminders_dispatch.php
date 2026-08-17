<?php
/**
 * Booking Reminders — Dispatcher Cron
 *
 * Fires any scheduled reminder whose remind_at has passed, creating one in-app
 * notification per recipient (agent + agent's manager chain + all admins).
 *
 * Schedule every ~15 minutes via Hostinger cron or Windows Task Scheduler,
 * e.g. cron expression "0,15,30,45 * * * *":
 *   php /path/to/cron/booking_reminders_dispatch.php
 *
 * (The notification bell also lazily dispatches on poll, so this cron is a
 *  safety net that fires alerts even when nobody has the app open.)
 *
 * Usage: php cron/booking_reminders_dispatch.php
 */

require __DIR__ . '/../vendor/autoload.php';

// Bootstrap env + Eloquent (same pattern as cron/shift_gap_alert.php).
$dotenv = \Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

$capsule = new \Illuminate\Database\Capsule\Manager();
$capsule->addConnection([
    'driver'    => 'mysql',
    'host'      => $_ENV['DB_HOST'] ?? 'localhost',
    'port'      => $_ENV['DB_PORT'] ?? 3306,
    'database'  => $_ENV['DB_DATABASE'] ?? 'basefare_crm',
    'username'  => $_ENV['DB_USERNAME'] ?? 'root',
    'password'  => $_ENV['DB_PASSWORD'] ?? '',
    'charset'   => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
]);
$capsule->setAsGlobal();
$capsule->bootEloquent();

date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'Asia/Kolkata');

$now = date('Y-m-d H:i:s');
echo "[Booking Reminders] Dispatch run at {$now}\n";

try {
    $result = (new \App\Services\ReminderService())->dispatchDue();
    echo "  Reminders fired : {$result['reminders']}\n";
    echo "  Notifications   : {$result['notifications']}\n";
} catch (\Throwable $e) {
    echo "  ERROR: " . $e->getMessage() . "\n";
    error_log('[booking_reminders_dispatch] ' . $e->getMessage());
    exit(1);
}

// Unconditional heartbeat — see the note in auto_clockout.php. Dispatch rows
// only appear when a reminder actually fires, so silence here was ambiguous
// between "nothing due" and "not running at all".
try {
    \Illuminate\Database\Capsule\Manager::table('activity_log')->insert([
        'user_id'     => null,
        'action'      => 'booking_reminders_ran',
        'entity_type' => 'booking_reminders',
        'entity_id'   => null,
        'details'     => json_encode(['dispatched' => $result['notifications'] ?? 0]),
        'ip_address'  => null,
        'created_at'  => date('Y-m-d H:i:s'),
    ]);
} catch (\Throwable $e) {
    error_log('[booking_reminders_dispatch] heartbeat failed: ' . $e->getMessage());
}

echo "[Booking Reminders] Done.\n";
