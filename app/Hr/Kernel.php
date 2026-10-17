<?php

namespace App\Hr;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use RuntimeException;

/**
 * Boots Trio HR: environment, database connections, timezone.
 *
 * Two named connections to the same database:
 *  - "crm": the CRM's own tables. Read-only from HR, apart from the guarded
 *    bridge commands (HR_PLAN.md §6.3).
 *  - "hr":  the hr_* tables. Separately named so HR can move to its own
 *    database later by changing config only.
 *
 * LOCAL SAFETY. With HR_ENV=local the repo's .env (production credentials) is
 * never read. Database settings must come from the launcher's environment and
 * point at a loopback host on an explicit port, or the kernel refuses to boot.
 */
final class Kernel
{
    private static bool $booted = false;
    private static bool $local = false;

    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        $root = dirname(__DIR__, 2);
        self::$local = (getenv('HR_ENV') ?: '') === 'local';

        if (!self::$local) {
            // Production shares the CRM's .env (DB credentials + HR_* keys).
            \Dotenv\Dotenv::createImmutable($root)->safeLoad();
        }

        date_default_timezone_set('Asia/Kolkata');

        $host = self::env('DB_HOST');
        $port = self::env('DB_PORT', self::$local ? null : '3306');
        $name = self::env('DB_DATABASE');
        if ($host === null || $name === null || $port === null) {
            throw new RuntimeException('Trio HR: database settings are missing.');
        }
        if (self::$local && !in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
            throw new RuntimeException('Trio HR (local): refusing a non-local database host.');
        }

        $config = [
            'driver'    => 'mysql',
            'host'      => $host,
            'port'      => $port,
            'database'  => $name,
            'username'  => self::env('DB_USERNAME', 'root'),
            'password'  => self::env('DB_PASSWORD', ''),
            'charset'   => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix'    => '',
            // Pinned, whatever the server's default: bad data is refused, never silently cut or zeroed.
            'modes'     => ['STRICT_TRANS_TABLES', 'NO_ZERO_IN_DATE', 'NO_ZERO_DATE', 'ERROR_FOR_DIVISION_BY_ZERO', 'NO_ENGINE_SUBSTITUTION'],
        ];

        $capsule = new Capsule();
        $capsule->addConnection($config, 'hr');
        $capsule->addConnection($config, 'crm');
        $capsule->getDatabaseManager()->setDefaultConnection('hr');
        $capsule->setAsGlobal();

        self::$booted = true;
    }

    public static function isLocal(): bool
    {
        return self::$local;
    }

    /**
     * Run $fn while holding a named database lock, so two requests doing the
     * same one-off job (first-run set-up, a bulk start) take turns.
     */
    public static function withLock(string $name, callable $fn, int $wait = 10): mixed
    {
        $db = self::hr();
        $lock = substr($db->getDatabaseName() . '.trio_hr.' . $name, 0, 64);
        if ((int) ($db->selectOne('SELECT GET_LOCK(?, ?) AS l', [$lock, $wait])->l ?? 0) !== 1) {
            throw new \App\Hr\Http\ApiError(503, 'busy', 'Trio HR is busy with the same job. Please try again in a moment.');
        }
        try {
            return $fn();
        } finally {
            $db->selectOne('SELECT RELEASE_LOCK(?) AS r', [$lock]);
        }
    }

    public static function hr(): Connection
    {
        return Capsule::connection('hr');
    }

    public static function crm(): Connection
    {
        return Capsule::connection('crm');
    }

    /** Environment value, with launcher variables taking precedence over .env. */
    public static function env(string $key, ?string $default = null): ?string
    {
        $v = getenv($key);
        if ($v === false || $v === '') {
            $v = $_ENV[$key] ?? $_SERVER[$key] ?? null;
        }
        return ($v === null || $v === '' || $v === false) ? $default : (string) $v;
    }

    /** Current IST time as a MySQL DATETIME string. HR never uses SQL NOW(). */
    public static function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
