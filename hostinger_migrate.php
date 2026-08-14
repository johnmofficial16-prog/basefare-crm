<?php
/**
 * Hostinger Database Migrator — v2 (ledger edition, 2026-08-12)
 *
 * Usage over SSH:   php hostinger_migrate.php [--dry-run] [--no-backup]
 *
 * What changed vs v1 (and why):
 *  - LEDGER. A `schema_migrations` table records every applied file. v1 re-ran
 *    all 16 hardcoded files on every deploy and "detected" idempotency by
 *    matching error strings — FK-constraint errors slipped through as eternal
 *    warnings (the errno-121 noise), and multi_query silently skipped the rest
 *    of a file after the first error.
 *  - AUTO-BASELINE. On first run (empty ledger) every migration file currently
 *    in the repo is marked 'baseline' WITHOUT being executed. This is safe
 *    because as of 2026-08-12 every module those files support (transactions,
 *    acceptances, vouchers, e-tickets, reminders, etc.) is live in production
 *    — the schema is known-applied. Only files added AFTER the baseline run.
 *  - BACKUP FIRST. If there is anything to apply, a premigrate_* snapshot is
 *    taken via DatabaseBackupService BEFORE any statement runs. No backup →
 *    no migration (override with --no-backup if you accept the risk).
 *  - PER-STATEMENT EXECUTION. Files are split into statements (quote- and
 *    comment-aware) and run one by one — an error names the exact statement,
 *    and nothing after a failure is silently skipped.
 *  - HONEST EXIT CODES. 0 = success/nothing-to-do, 1 = migration failed,
 *    2 = aborted (backup failed). v1 always exited 0.
 *
 * Adding a migration: drop a new .sql file in database/migrations/ named
 * YYYY_MM_DD_description.sql (files run in filename sort order), push, then on
 * the server: git pull && php hostinger_migrate.php
 *
 * Do NOT delete this script from the server — it is git-tracked and the
 * ledger makes re-running it a no-op.
 */

require __DIR__ . '/vendor/autoload.php';

use App\Services\DatabaseBackupService;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

$argvOpts  = array_slice($argv ?? [], 1);
$dryRun    = in_array('--dry-run', $argvOpts, true);
$noBackup  = in_array('--no-backup', $argvOpts, true);

$host = $_ENV['DB_HOST']     ?? '127.0.0.1';
$port = (int) ($_ENV['DB_PORT'] ?? 3306);
$db   = $_ENV['DB_DATABASE'] ?? $_ENV['DB_NAME'] ?? 'basefare_crm';
$user = $_ENV['DB_USERNAME'] ?? $_ENV['DB_USER'] ?? 'root';
$pass = $_ENV['DB_PASSWORD'] ?? $_ENV['DB_PASS'] ?? '';

mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = new mysqli($host, $user, $pass, $db, $port);
if ($mysqli->connect_error) {
    fwrite(STDERR, "❌ Connection failed: {$mysqli->connect_error}\n");
    exit(1);
}
$mysqli->set_charset('utf8mb4');
echo "✅ Connected to {$db}.\n";

// ── Ledger ───────────────────────────────────────────────────────────────────
$ledgerSql = "CREATE TABLE IF NOT EXISTS `schema_migrations` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `filename`   VARCHAR(255) NOT NULL,
  `checksum`   CHAR(64)     DEFAULT NULL,
  `status`     ENUM('applied','baseline','failed') NOT NULL DEFAULT 'applied',
  `detail`     TEXT         DEFAULT NULL,
  `applied_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_filename` (`filename`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
if ($mysqli->query($ledgerSql) === false) {
    fwrite(STDERR, "❌ Cannot create schema_migrations ledger: {$mysqli->error}\n");
    exit(1);
}

// ── Discover migration files ─────────────────────────────────────────────────
$files = glob(__DIR__ . '/database/migrations/*.sql') ?: [];
sort($files, SORT_STRING);
// Legacy file living outside migrations/ — part of the known-applied set.
$legacyRbac = __DIR__ . '/database/migrate_four_tier_rbac.sql';
if (file_exists($legacyRbac)) {
    array_unshift($files, $legacyRbac);
}

$ledgered = [];
$res = $mysqli->query("SELECT filename, status FROM schema_migrations");
while ($res && ($row = $res->fetch_assoc())) {
    $ledgered[$row['filename']] = $row['status'];
}
if ($res) {
    $res->free();
}

// ── First run: baseline everything, execute nothing ─────────────────────────
if (count($ledgered) === 0) {
    echo "\nEmpty ledger → BASELINE MODE.\n";
    echo "Marking all " . count($files) . " existing migration files as applied\n";
    echo "(schema is known-live in production; nothing is executed).\n\n";
    $stmt = $mysqli->prepare(
        "INSERT INTO schema_migrations (filename, checksum, status, detail)
         VALUES (?, ?, 'baseline', 'Baselined 2026-08-12: schema pre-dates the ledger')"
    );
    foreach ($files as $file) {
        $name = basename($file);
        $sum  = hash_file('sha256', $file);
        $stmt->bind_param('ss', $name, $sum);
        if ($stmt->execute() === false) {
            fwrite(STDERR, "❌ Ledger insert failed for {$name}: {$stmt->error}\n");
            exit(1);
        }
        echo "  baseline  {$name}\n";
    }
    $stmt->close();
    echo "\n🎉 Baseline complete. Future files in database/migrations/ will run normally.\n";
    exit(0);
}

// ── Determine pending files ──────────────────────────────────────────────────
$pending = [];
foreach ($files as $file) {
    $name = basename($file);
    $status = $ledgered[$name] ?? null;
    if ($status === null || $status === 'failed') {   // failed files retry after a fix
        $pending[] = $file;
    }
}

if (count($pending) === 0) {
    echo "\nNothing to migrate — ledger is up to date (" . count($ledgered) . " entries).\n";
    exit(0);
}

echo "\nPending migrations (" . count($pending) . "):\n";
foreach ($pending as $file) {
    echo "  → " . basename($file) . "\n";
}

if ($dryRun) {
    echo "\n--dry-run: stopping before backup/execution.\n";
    exit(0);
}

// ── Backup before touching anything ─────────────────────────────────────────
if ($noBackup) {
    echo "\n⚠️  --no-backup: SKIPPING pre-migration snapshot at your own risk.\n";
} else {
    echo "\nTaking pre-migration backup...\n";
    $backup = DatabaseBackupService::run('premigrate');
    if (!$backup['success']) {
        fwrite(STDERR, "❌ ABORTED — pre-migration backup failed ({$backup['method']}): {$backup['error']}\n");
        fwrite(STDERR, "   Fix the backup problem, or accept the risk with --no-backup.\n");
        exit(2);
    }
    $mb = round($backup['bytes'] / 1048576, 2);
    echo "  Backup OK ({$backup['method']}): " . basename($backup['file']) . " ({$mb} MB)\n";
}

// ── Apply ────────────────────────────────────────────────────────────────────

/**
 * Split an SQL file into statements. Respects single/double quotes, backticks,
 * dash-dash and hash line comments, and slash-star block comments. Good for
 * the DDL migrations this project uses (no stored procedures / DELIMITER).
 */
function split_sql_statements(string $sql): array
{
    $stmts = [];
    $buf = '';
    $len = strlen($sql);
    $inString = null;      // ', " or `
    for ($i = 0; $i < $len; $i++) {
        $ch = $sql[$i];

        if ($inString !== null) {
            $buf .= $ch;
            if ($ch === '\\' && $inString !== '`') {   // skip escaped char inside '/" strings
                if ($i + 1 < $len) {
                    $buf .= $sql[++$i];
                }
            } elseif ($ch === $inString) {
                $inString = null;
            }
            continue;
        }

        // comment starts?
        if ($ch === '-' && substr($sql, $i, 3) !== false && preg_match('/^--(\s|$)/', substr($sql, $i, 3))) {
            $nl = strpos($sql, "\n", $i);
            $i = ($nl === false) ? $len : $nl;         // skip to end of line
            $buf .= "\n";
            continue;
        }
        if ($ch === '#') {
            $nl = strpos($sql, "\n", $i);
            $i = ($nl === false) ? $len : $nl;
            $buf .= "\n";
            continue;
        }
        if ($ch === '/' && ($sql[$i + 1] ?? '') === '*') {
            $end = strpos($sql, '*/', $i + 2);
            $i = ($end === false) ? $len : $end + 1;
            continue;
        }

        if ($ch === "'" || $ch === '"' || $ch === '`') {
            $inString = $ch;
            $buf .= $ch;
            continue;
        }

        if ($ch === ';') {
            $trimmed = trim($buf);
            if ($trimmed !== '') {
                $stmts[] = $trimmed;
            }
            $buf = '';
            continue;
        }

        $buf .= $ch;
    }
    $trimmed = trim($buf);
    if ($trimmed !== '') {
        $stmts[] = $trimmed;
    }
    return $stmts;
}

/** Errors that mean "this piece already exists" — safe to skip on re-run. */
function is_benign_duplicate(string $err): bool
{
    foreach ([
        'Duplicate column name',
        'already exists',
        'Duplicate entry',
        'Duplicate key name',
        'Duplicate foreign key constraint',
        'Duplicate FOREIGN KEY constraint',
        'errno: 121',                       // duplicate key on write (FK name clash)
    ] as $needle) {
        if (stripos($err, $needle) !== false) {
            return true;
        }
    }
    return false;
}

$failed = false;

foreach ($pending as $file) {
    $name = basename($file);
    echo "\nApplying {$name}...\n";
    $sql = (string) file_get_contents($file);
    $statements = split_sql_statements($sql);
    $ran = 0;
    $skipped = 0;
    $error = null;

    foreach ($statements as $idx => $stmt) {
        if ($mysqli->query($stmt) === false) {
            $err = $mysqli->error;
            if (is_benign_duplicate($err)) {
                $skipped++;
                echo "  ~ stmt " . ($idx + 1) . " skipped (already exists): " . substr($err, 0, 100) . "\n";
                continue;
            }
            $error = "stmt " . ($idx + 1) . "/" . count($statements) . " failed: {$err}\n    SQL: " . substr(preg_replace('/\s+/', ' ', $stmt), 0, 200);
            break;
        }
        $ran++;
    }

    $sum = hash_file('sha256', $file);
    if ($error === null) {
        $status = 'applied';
        $detail = "ran {$ran}, skipped {$skipped} of " . count($statements) . " statements";
        echo "  ✅ {$detail}\n";
    } else {
        $status = 'failed';
        $detail = $error;
        fwrite(STDERR, "  ❌ {$error}\n");
        $failed = true;
    }

    $stmt2 = $mysqli->prepare(
        "INSERT INTO schema_migrations (filename, checksum, status, detail)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE checksum = VALUES(checksum), status = VALUES(status),
                                 detail = VALUES(detail), applied_at = CURRENT_TIMESTAMP"
    );
    $stmt2->bind_param('ssss', $name, $sum, $status, $detail);
    $stmt2->execute();
    $stmt2->close();

    if ($failed) {
        break;   // don't run later files on top of a broken intermediate state
    }
}

if ($failed) {
    fwrite(STDERR, "\n❌ Migration FAILED — later files were not attempted.\n");
    fwrite(STDERR, "   A premigrate_* backup exists in storage/backups/ if rollback is needed.\n");
    exit(1);
}

echo "\n🎉 All pending migrations applied.\n";
exit(0);
