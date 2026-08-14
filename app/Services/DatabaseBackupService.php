<?php

namespace App\Services;

use Throwable;

/**
 * DatabaseBackupService
 *
 * Dumps the whole MySQL database to storage/backups/<label>_<stamp>.sql.gz.
 * Before 2026-08-12 there were NO backups anywhere — one bad migration or key
 * rotation meant permanently lost attendance/card data.
 *
 * Deliberately has ZERO dependency on Eloquent/Capsule so it can run from:
 *  - cron/db_backup.php          (nightly, label 'nightly')
 *  - hostinger_migrate.php       (label 'premigrate', BEFORE applying anything)
 *  - any future script           (DatabaseBackupService::run('manual'))
 *
 * Strategy:
 *  1. mysqldump if exec() is available (credentials passed via a 0600
 *     --defaults-extra-file temp file, never on the command line).
 *  2. Pure-PHP fallback (SHOW CREATE TABLE + chunked INSERTs, binary-safe via
 *     hex literals) if exec is disabled — slower but dependency-free.
 *
 * Rotation: nightly_* kept RETENTION_NIGHTLY_DAYS, premigrate_* and manual_*
 * kept RETENTION_SNAPSHOT_DAYS. storage/ is denied over HTTP (root .htaccess)
 * and sits outside the crm subdomain docroot (public/), so dumps are not
 * web-reachable; storage/backups/ is additionally gitignored.
 */
class DatabaseBackupService
{
    private const RETENTION_NIGHTLY_DAYS  = 14;
    private const RETENTION_SNAPSHOT_DAYS = 60;
    private const MIN_VALID_BYTES         = 1024;   // a real dump is never this small
    private const INSERT_BATCH            = 200;

    /**
     * Run a backup. Returns
     *   ['success'=>bool, 'file'=>?string, 'bytes'=>int, 'method'=>string, 'error'=>?string]
     * Never throws.
     */
    public static function run(string $label = 'manual'): array
    {
        $result = ['success' => false, 'file' => null, 'bytes' => 0, 'method' => 'none', 'error' => null];

        try {
            $label = preg_replace('/[^a-z0-9_-]/i', '', $label) ?: 'manual';
            $dir   = self::backupDir();
            if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
                $result['error'] = "Cannot create backup dir: $dir";
                return $result;
            }

            $host = $_ENV['DB_HOST']     ?? '127.0.0.1';
            $port = (int) ($_ENV['DB_PORT'] ?? 3306);
            $db   = $_ENV['DB_DATABASE'] ?? '';
            $user = $_ENV['DB_USERNAME'] ?? '';
            $pass = $_ENV['DB_PASSWORD'] ?? '';
            if ($db === '' || $user === '') {
                $result['error'] = 'DB credentials missing from environment';
                return $result;
            }

            $gzFile = $dir . '/' . $label . '_' . date('Ymd_His') . '.sql.gz';

            if (self::execAvailable()) {
                $ok = self::dumpViaMysqldump($host, $port, $db, $user, $pass, $gzFile, $err);
                $result['method'] = 'mysqldump';
                if (!$ok) {
                    // mysqldump missing/broken → fall through to PHP dump
                    @unlink($gzFile);
                    $ok = self::dumpViaPhp($host, $port, $db, $user, $pass, $gzFile, $err);
                    $result['method'] = 'php-fallback';
                }
            } else {
                $ok = self::dumpViaPhp($host, $port, $db, $user, $pass, $gzFile, $err);
                $result['method'] = 'php-fallback';
            }

            if (!$ok) {
                $result['error'] = $err ?? 'unknown dump failure';
                @unlink($gzFile);
                return $result;
            }

            clearstatcache(true, $gzFile);
            $bytes = (int) @filesize($gzFile);
            if ($bytes < self::MIN_VALID_BYTES) {
                $result['error'] = "Dump suspiciously small ($bytes bytes) — treated as failed";
                @unlink($gzFile);
                return $result;
            }

            self::rotate($dir);

            $result['success'] = true;
            $result['file']    = $gzFile;
            $result['bytes']   = $bytes;
            return $result;
        } catch (Throwable $e) {
            $result['error'] = get_class($e) . ': ' . $e->getMessage();
            return $result;
        }
    }

    public static function backupDir(): string
    {
        return dirname(__DIR__, 2) . '/storage/backups';
    }

    // =========================================================================
    // METHOD 1 — mysqldump
    // =========================================================================

    private static function execAvailable(): bool
    {
        if (!function_exists('exec')) {
            return false;
        }
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        return !in_array('exec', $disabled, true);
    }

    private static function dumpViaMysqldump(
        string $host, int $port, string $db, string $user, string $pass,
        string $gzFile, ?string &$err
    ): bool {
        $err = null;
        $cnf = @tempnam(sys_get_temp_dir(), 'bfd');
        if ($cnf === false) {
            $err = 'tempnam failed';
            return false;
        }
        // Credentials via defaults-extra-file so they never appear in `ps`.
        $cnfBody = "[client]\nhost={$host}\nport={$port}\nuser={$user}\npassword=\"{$pass}\"\n";
        @file_put_contents($cnf, $cnfBody);
        @chmod($cnf, 0600);

        $sqlFile = $gzFile . '.tmp.sql';
        $cmd = sprintf(
            'mysqldump --defaults-extra-file=%s --single-transaction --quick --no-tablespaces %s > %s 2>&1',
            escapeshellarg($cnf),
            escapeshellarg($db),
            escapeshellarg($sqlFile)
        );

        $out = [];
        $code = 1;
        @exec($cmd, $out, $code);
        @unlink($cnf);

        if ($code !== 0) {
            $err = 'mysqldump exit ' . $code . ': ' . substr((string) @file_get_contents($sqlFile), 0, 300);
            @unlink($sqlFile);
            return false;
        }

        // A successful mysqldump ends with "-- Dump completed on ..."
        $tail = self::fileTail($sqlFile, 200);
        if (strpos($tail, 'Dump completed') === false) {
            $err = 'mysqldump output missing completion marker (truncated dump?)';
            @unlink($sqlFile);
            return false;
        }

        if (!self::gzipFile($sqlFile, $gzFile)) {
            $err = 'gzip of dump failed';
            @unlink($sqlFile);
            return false;
        }
        @unlink($sqlFile);
        return true;
    }

    private static function fileTail(string $path, int $bytes): string
    {
        $fh = @fopen($path, 'rb');
        if (!$fh) {
            return '';
        }
        $size = filesize($path) ?: 0;
        fseek($fh, max(0, $size - $bytes));
        $tail = (string) fread($fh, $bytes);
        fclose($fh);
        return $tail;
    }

    private static function gzipFile(string $src, string $dst): bool
    {
        $in  = @fopen($src, 'rb');
        $out = @gzopen($dst, 'wb6');
        if (!$in || !$out) {
            return false;
        }
        while (!feof($in)) {
            $chunk = fread($in, 1024 * 512);
            if ($chunk === false) {
                fclose($in);
                gzclose($out);
                return false;
            }
            gzwrite($out, $chunk);
        }
        fclose($in);
        gzclose($out);
        return true;
    }

    // =========================================================================
    // METHOD 2 — pure PHP dump (exec() disabled hosts)
    // =========================================================================

    private static function dumpViaPhp(
        string $host, int $port, string $db, string $user, string $pass,
        string $gzFile, ?string &$err
    ): bool {
        $err = null;
        mysqli_report(MYSQLI_REPORT_OFF);
        $conn = @new \mysqli($host, $user, $pass, $db, $port);
        if ($conn->connect_error) {
            $err = 'connect: ' . $conn->connect_error;
            return false;
        }
        $conn->set_charset('utf8mb4');

        $gz = @gzopen($gzFile, 'wb6');
        if (!$gz) {
            $err = 'cannot open gz for writing';
            $conn->close();
            return false;
        }

        $w = function (string $s) use ($gz): void { gzwrite($gz, $s); };

        $w("-- BaseFare CRM PHP-fallback dump\n-- Database: {$db}\n-- Generated: " . date('c') . "\n\n");
        $w("SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n");

        $tables = [];
        $res = $conn->query('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"');
        if ($res === false) {
            $err = 'SHOW TABLES failed: ' . $conn->error;
            gzclose($gz);
            $conn->close();
            return false;
        }
        while ($row = $res->fetch_row()) {
            $tables[] = $row[0];
        }
        $res->free();

        foreach ($tables as $table) {
            $tq = '`' . str_replace('`', '``', $table) . '`';

            $createRes = $conn->query("SHOW CREATE TABLE $tq");
            if ($createRes === false) {
                $err = "SHOW CREATE TABLE $table failed: " . $conn->error;
                gzclose($gz);
                $conn->close();
                return false;
            }
            $createRow = $createRes->fetch_row();
            $createRes->free();
            $w("DROP TABLE IF EXISTS $tq;\n" . $createRow[1] . ";\n\n");

            // Stream rows unbuffered so big tables don't exhaust memory.
            $dataRes = $conn->query("SELECT * FROM $tq", MYSQLI_USE_RESULT);
            if ($dataRes === false) {
                $err = "SELECT from $table failed: " . $conn->error;
                gzclose($gz);
                $conn->close();
                return false;
            }

            $fields = $dataRes->fetch_fields();
            $binary = [];
            foreach ($fields as $i => $f) {
                // BINARY/VARBINARY/BLOB flags → dump as hex literal (encoding-safe)
                $binary[$i] = ($f->flags & MYSQLI_BINARY_FLAG) !== 0;
            }

            $batch = [];
            while ($row = $dataRes->fetch_row()) {
                $vals = [];
                foreach ($row as $i => $v) {
                    if ($v === null) {
                        $vals[] = 'NULL';
                    } elseif ($binary[$i]) {
                        $vals[] = $v === '' ? "''" : '0x' . bin2hex($v);
                    } else {
                        $vals[] = "'" . $conn->real_escape_string($v) . "'";
                    }
                }
                $batch[] = '(' . implode(',', $vals) . ')';
                if (count($batch) >= self::INSERT_BATCH) {
                    $w("INSERT INTO $tq VALUES\n" . implode(",\n", $batch) . ";\n");
                    $batch = [];
                }
            }
            if ($batch) {
                $w("INSERT INTO $tq VALUES\n" . implode(",\n", $batch) . ";\n");
            }
            $dataRes->free();
            $w("\n");
        }

        $w("SET FOREIGN_KEY_CHECKS=1;\n-- Dump completed on " . date('c') . "\n");
        gzclose($gz);
        $conn->close();
        return true;
    }

    // =========================================================================
    // ROTATION
    // =========================================================================

    private static function rotate(string $dir): void
    {
        $now = time();
        foreach ((array) @glob($dir . '/*.sql.gz') as $f) {
            $age  = ($now - (int) @filemtime($f)) / 86400;
            $base = basename($f);
            $keep = str_starts_with($base, 'nightly_')
                ? self::RETENTION_NIGHTLY_DAYS
                : self::RETENTION_SNAPSHOT_DAYS;
            if ($age > $keep) {
                @unlink($f);
            }
        }
    }
}
