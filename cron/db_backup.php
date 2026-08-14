<?php
/**
 * Nightly Database Backup Cron
 *
 * Register in hPanel → Advanced → Cron Jobs, daily at 03:30 IST (quiet hour
 * inside the 18:00→09:00 business day, before the morning rush):
 *
 *   php /home/u501549865/domains/base-fare.com/public_html/crm/cron/db_backup.php
 *
 * Dumps the full DB to storage/backups/nightly_<stamp>.sql.gz via
 * DatabaseBackupService (mysqldump, PHP fallback). Rotation is handled by the
 * service (14 days nightly, 60 days premigrate/manual snapshots).
 *
 * Outcome visibility: success logs severity 'info', failure logs 'critical'
 * to the error_log table → both visible in Admin → Error Console. A silent
 * backup system is a broken backup system.
 *
 * Restore procedure (SSH):
 *   gunzip < storage/backups/nightly_YYYYMMDD_HHMMSS.sql.gz | \
 *     mysql -h DB_HOST -u DB_USER -p DB_NAME
 */

require __DIR__ . '/../vendor/autoload.php';

use Illuminate\Database\Capsule\Manager as Capsule;
use Dotenv\Dotenv;
use App\Services\DatabaseBackupService;
use App\Services\ErrorLogService;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'Asia/Kolkata');

// Boot Eloquent only so ErrorLogService can write outcome rows.
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

echo '[' . date('Y-m-d H:i:s') . "] Nightly DB backup starting...\n";

$result = DatabaseBackupService::run('nightly');

if ($result['success']) {
    $mb = round($result['bytes'] / 1048576, 2);
    echo "  OK ({$result['method']}): " . basename($result['file']) . " ({$mb} MB)\n";
    ErrorLogService::log('info', sprintf(
        '[backup] Nightly backup OK via %s: %s (%s MB)',
        $result['method'], basename($result['file']), $mb
    ));
    exit(0);
}

echo "  FAILED ({$result['method']}): {$result['error']}\n";
ErrorLogService::log('critical', sprintf(
    '[backup] NIGHTLY BACKUP FAILED via %s: %s',
    $result['method'], $result['error']
));
exit(1);
