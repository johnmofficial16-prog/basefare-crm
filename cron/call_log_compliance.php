<?php
/**
 * Call-log compliance + call-back reminders.
 *
 * Schedule HOURLY on Hostinger (e.g. minute 5 of every hour):
 *   php /home/<user>/domains/base-fare.com/public_html/crm/cron/call_log_compliance.php
 *
 * Each run:
 *   1. rings the agent's bell for any due call-back (belt-and-braces — the bell
 *      poll also does this while someone is logged in),
 *   2. on working shift days (not Sat/Sun by default), flags clocked-in agents
 *      who have logged no calls / gone quiet → nudge to the agent, digest to
 *      their manager/supervisor and to every admin,
 *   3. after the summary hour (default 10:00 IST), sends leads a once-per-shift
 *      recap of the finished overnight shift.
 *
 * Idempotent: alerts are recorded in call_log_alerts and never repeated inside
 * the re-alert window, so running it twice in an hour is harmless.
 *
 * Usage: php cron/call_log_compliance.php
 */

require __DIR__ . '/../vendor/autoload.php';

$dotenv = \Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->load();

$capsule = new \Illuminate\Database\Capsule\Manager();
$capsule->addConnection([
    'driver'    => 'mysql',
    'host'      => $_ENV['DB_HOST'] ?? 'localhost',
    'database'  => $_ENV['DB_DATABASE'] ?? 'basefare_crm',
    'username'  => $_ENV['DB_USERNAME'] ?? 'root',
    'password'  => $_ENV['DB_PASSWORD'] ?? '',
    'charset'   => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
]);
$capsule->setAsGlobal();
$capsule->bootEloquent();

date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'Asia/Kolkata');

$svc = new \App\Services\CallLogService();
$stamp = date('Y-m-d H:i:s');

try {
    $fu = $svc->dispatchDueFollowUps();
    $r  = $svc->runCompliance();

    echo "[{$stamp}] call-log compliance — shift {$r['shift_date']}"
        . ($r['off_day'] ? ' (off day, no live checks)' : '') . "\n";
    echo "  call-backs rung: {$fu}\n";
    echo '  flagged: ' . ($r['flagged'] ? implode(', ', $r['flagged']) : 'none') . "\n";
    echo '  shift summary: ' . ($r['summary'] ? "sent for {$r['summary']}" : 'not due') . "\n";
} catch (\Throwable $e) {
    echo "[{$stamp}] call-log compliance FAILED: " . $e->getMessage() . "\n";
    \App\Services\ErrorLogService::logThrowable($e, 'critical');
    exit(1);
}
