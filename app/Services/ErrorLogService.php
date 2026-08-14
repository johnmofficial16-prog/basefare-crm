<?php

namespace App\Services;

use Illuminate\Database\Capsule\Manager as DB;
use Throwable;

/**
 * ErrorLogService
 *
 * The single funnel through which every server-side error reaches the
 * `error_log` table (the Admin → Error Console). Until 2026-08-12 that table
 * existed but NOTHING wrote to it — errors went to the PHP error log nobody
 * reads, which is why the WAF and Tailwind-CDN incidents were discovered by
 * humans complaining instead of by the console.
 *
 * Design rules (do not regress these):
 *  - FAIL-SOFT, ALWAYS. Logging an error must never itself break the request.
 *    Every public method swallows its own failures and falls back to
 *    error_log(). A broken DB connection while logging a DB error must not
 *    recurse or throw.
 *  - FLOOD-SAFE. A tight loop that warns on every iteration must not insert
 *    thousands of rows: identical (message,file,line) tuples are deduped per
 *    request, and each request may insert at most MAX_PER_REQUEST rows.
 *  - 404s ARE NOT ERRORS. Bot scans would drown the console; the Slim handler
 *    in public/index.php filters HttpNotFound/HttpMethodNotAllowed before
 *    calling us.
 *
 * Severities used: fatal, error, warning, notice, info, client
 * ('client' rows come from ClientErrorController / error-beacon.js).
 */
class ErrorLogService
{
    private const MAX_PER_REQUEST  = 25;
    private const MAX_MESSAGE_LEN  = 2000;
    private const MAX_TRACE_LEN    = 10000;
    private const MAX_URL_LEN      = 1024;

    /** @var array<string,bool> hashes already logged during this request */
    private static array $seen = [];
    private static int $count = 0;

    // =========================================================================
    // PUBLIC API
    // =========================================================================

    /**
     * Low-level insert. Everything else funnels here.
     */
    public static function log(
        string $severity,
        string $message,
        ?string $file = null,
        ?int $line = null,
        ?string $trace = null
    ): void {
        try {
            if (self::$count >= self::MAX_PER_REQUEST) {
                return;
            }
            $hash = md5($severity . '|' . $message . '|' . $file . '|' . $line);
            if (isset(self::$seen[$hash])) {
                return;
            }
            self::$seen[$hash] = true;
            self::$count++;

            DB::table('error_log')->insert([
                'severity'   => substr($severity, 0, 20),
                'message'    => mb_substr($message, 0, self::MAX_MESSAGE_LEN),
                'file'       => $file !== null ? substr($file, 0, 512) : null,
                'line'       => $line,
                'trace'      => $trace !== null ? mb_substr($trace, 0, self::MAX_TRACE_LEN) : null,
                'url'        => self::currentUrl(),
                'ip_address' => substr($_SERVER['REMOTE_ADDR'] ?? 'cli', 0, 45),
                'user_id'    => isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            // Last resort: the server log. NEVER rethrow from the logger.
            @error_log('[ErrorLogService] failed to persist error: ' . $e->getMessage()
                . ' | original: ' . $severity . ' ' . $message);
        }
    }

    /** Log an exception/throwable with its trace. */
    public static function logThrowable(Throwable $e, string $severity = 'error'): void
    {
        self::log(
            $severity,
            get_class($e) . ': ' . $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString()
        );
    }

    /**
     * Install global PHP handlers. Call ONCE from public/index.php AFTER the
     * DB (Capsule) is booted. Slim's own error middleware handles in-route
     * exceptions; these handlers catch what Slim can't:
     *  - warnings/notices anywhere        (set_error_handler)
     *  - fatals & parse errors            (register_shutdown_function)
     *  - exceptions outside Slim's run()  (set_exception_handler)
     */
    public static function install(): void
    {
        set_error_handler(function (int $errno, string $errstr, string $errfile = '', int $errline = 0): bool {
            // Respect @-suppression and error_reporting() config.
            if (!(error_reporting() & $errno)) {
                return false;
            }
            $severity = match (true) {
                ($errno & (E_WARNING | E_USER_WARNING)) !== 0            => 'warning',
                ($errno & (E_NOTICE | E_USER_NOTICE | E_DEPRECATED
                          | E_USER_DEPRECATED)) !== 0                    => 'notice',
                default                                                  => 'warning',
            };
            self::log($severity, $errstr, $errfile, $errline);
            return false; // let PHP's normal handling continue (server log etc.)
        });

        set_exception_handler(function (Throwable $e): void {
            self::logThrowable($e, 'fatal');
            http_response_code(500);
            echo 'An internal error occurred. It has been logged.';
        });

        register_shutdown_function(function (): void {
            $err = error_get_last();
            if ($err !== null
                && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                self::log('fatal', $err['message'], $err['file'] ?? null, $err['line'] ?? null);
            }
        });
    }

    // =========================================================================
    // INTERNAL
    // =========================================================================

    private static function currentUrl(): ?string
    {
        if (PHP_SAPI === 'cli') {
            return 'cli:' . implode(' ', array_slice($GLOBALS['argv'] ?? [], 0, 3));
        }
        $method = $_SERVER['REQUEST_METHOD'] ?? '';
        $uri    = $_SERVER['REQUEST_URI'] ?? '';
        $url    = trim($method . ' ' . $uri);
        return $url !== '' ? substr($url, 0, self::MAX_URL_LEN) : null;
    }
}
