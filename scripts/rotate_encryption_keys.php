<?php
/**
 * Encryption Key Rotation — re-encrypt stored card data under a new key.
 *
 * CLI ONLY. Refuses to run over HTTP.
 *
 * ─── HOW IT WORKS ────────────────────────────────────────────────────────────
 * EncryptionService derives one AES-256-GCM key from ENCRYPTION_KEY_A + KEY_B.
 * During rotation you set the NEW keys as the primary pair and keep the old
 * pair as ENCRYPTION_KEY_A_OLD / ENCRYPTION_KEY_B_OLD. In that state the app
 * encrypts with the new key and decrypts with EITHER — so the site keeps
 * working while rows are still on the old key. This script walks every
 * encrypted column and moves each value onto the new key.
 *
 * Because GCM authenticates, a wrong key fails cleanly instead of returning
 * garbage. That is what makes "try new, then old" unambiguous and safe.
 *
 * ─── ORDER OF OPERATIONS (zero downtime) ─────────────────────────────────────
 *   1. BACK UP THE DATABASE. Non-negotiable — without the key, data is gone.
 *   2. Deploy the dual-key EncryptionService (no behaviour change on its own).
 *   3. Generate a new key pair:      php scripts/rotate_encryption_keys.php --generate
 *   4. Edit .env: move the current A/B to *_OLD, put the new values in A/B.
 *   5. Dry run (reads only, changes nothing):
 *                                    php scripts/rotate_encryption_keys.php
 *   6. Live run:                     php scripts/rotate_encryption_keys.php --live
 *   7. Re-run the dry run — it should report 0 pending.
 *   8. Delete the *_OLD entries from .env.
 *
 * The script is idempotent: values already under the new key are detected and
 * skipped, so it is safe to run repeatedly or resume after an interruption.
 *
 * Usage:
 *   php scripts/rotate_encryption_keys.php [--live] [--generate] [--batch=200]
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script is CLI-only.\n");
}

require __DIR__ . '/../vendor/autoload.php';

use App\Services\EncryptionService;
use Illuminate\Database\Capsule\Manager as DB;

$root = dirname(__DIR__);
$dotenv = Dotenv\Dotenv::createImmutable($root);
$dotenv->safeLoad();

$args      = $argv ?? [];
$isLive    = in_array('--live', $args, true);
$doGen     = in_array('--generate', $args, true);
$batchSize = 200;
foreach ($args as $a) {
    if (str_starts_with($a, '--batch=')) {
        $batchSize = max(1, (int) substr($a, 8));
    }
}

// ─── --generate: print a fresh key pair and exit ─────────────────────────────
if ($doGen) {
    echo "\nNew encryption key pair (store securely, then follow step 4):\n\n";
    echo 'ENCRYPTION_KEY_A="' . bin2hex(random_bytes(32)) . "\"\n";
    echo 'ENCRYPTION_KEY_B="' . bin2hex(random_bytes(32)) . "\"\n\n";
    echo "Move your CURRENT ENCRYPTION_KEY_A / ENCRYPTION_KEY_B values to\n";
    echo "ENCRYPTION_KEY_A_OLD / ENCRYPTION_KEY_B_OLD before replacing them.\n\n";
    exit(0);
}

// ─── Boot Eloquent ───────────────────────────────────────────────────────────
$capsule = new DB();
$capsule->addConnection([
    'driver'    => 'mysql',
    'host'      => $_ENV['DB_HOST'] ?? '127.0.0.1',
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

$mode = $isLive ? 'LIVE — data WILL be modified' : 'DRY RUN — nothing will be written';
echo "\n=============================================================\n";
echo "  Encryption Key Rotation\n";
echo "  Mode: {$mode}\n";
echo "=============================================================\n\n";

$enc = new EncryptionService();

if (!$enc->hasOldKey()) {
    echo "No previous key configured (ENCRYPTION_KEY_A_OLD / ENCRYPTION_KEY_B_OLD).\n\n";
    echo "  • If you have NOT started a rotation, that is expected — see step 4.\n";
    echo "  • If you HAVE finished one, nothing to do.\n\n";
    echo "Without the old key this script cannot read rows that are still on it,\n";
    echo "so it will not run. Exiting without touching anything.\n\n";
    exit(1);
}

// ─── Discover encrypted columns from the live schema ─────────────────────────
// Driven by information_schema rather than a hardcoded list, so a column added
// later cannot be silently missed.
$dbName  = $_ENV['DB_DATABASE'] ?? 'basefare_crm';
$columns = DB::select(
    "SELECT TABLE_NAME AS t, COLUMN_NAME AS c
       FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = ?
        AND (COLUMN_NAME LIKE '%\_enc' OR COLUMN_NAME LIKE '%\_enc\_%')
      ORDER BY TABLE_NAME, COLUMN_NAME",
    [$dbName]
);

if (empty($columns)) {
    echo "No encrypted columns found in `{$dbName}`. Nothing to do.\n\n";
    exit(0);
}

// Group columns per table so each row is read and written once.
$byTable = [];
foreach ($columns as $col) {
    $byTable[$col->t][] = $col->c;
}

echo "Encrypted columns discovered:\n";
foreach ($byTable as $table => $cols) {
    echo "  {$table}: " . implode(', ', $cols) . "\n";
}
echo "\n";

// ─── Walk every row ──────────────────────────────────────────────────────────
$totalScanned = 0;
$totalPending = 0;
$totalMoved   = 0;
$totalAlready = 0;
$totalEmpty   = 0;
$failures     = [];

foreach ($byTable as $table => $cols) {
    // Primary key — assume `id` (true for every table in this schema).
    $pk = 'id';
    $hasId = DB::select(
        "SELECT COUNT(*) AS n FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = 'id'",
        [$dbName, $table]
    );
    if ((int) ($hasId[0]->n ?? 0) === 0) {
        echo "  ! Skipping `{$table}` — no `id` primary key to address rows by.\n";
        continue;
    }

    $lastId = 0;
    echo "Processing `{$table}` …\n";

    while (true) {
        $rows = DB::table($table)
            ->select(array_merge([$pk], $cols))
            ->where($pk, '>', $lastId)
            ->orderBy($pk)
            ->limit($batchSize)
            ->get();

        if ($rows->isEmpty()) {
            break;
        }

        foreach ($rows as $row) {
            $lastId = $row->{$pk};
            $updates = [];

            foreach ($cols as $c) {
                $val = $row->{$c} ?? null;
                if ($val === null || $val === '') {
                    $totalEmpty++;
                    continue;
                }
                $totalScanned++;

                // Already migrated? Leave it alone (idempotent).
                if ($enc->isUnderCurrentKey((string) $val)) {
                    $totalAlready++;
                    continue;
                }

                $totalPending++;

                try {
                    // Decrypt via the fallback (old key), re-encrypt under new.
                    $plain  = $enc->decrypt((string) $val);
                    $cipher = $enc->encrypt($plain);

                    // Verify the round-trip BEFORE accepting the new value.
                    if (!$enc->isUnderCurrentKey($cipher) || $enc->decrypt($cipher) !== $plain) {
                        throw new \RuntimeException('round-trip verification failed');
                    }

                    $updates[$c] = $cipher;
                    unset($plain);
                } catch (\Throwable $e) {
                    $failures[] = "{$table}#{$row->{$pk}}.{$c}: " . $e->getMessage();
                }
            }

            if ($updates && $isLive) {
                try {
                    DB::connection()->transaction(function () use ($table, $pk, $row, $updates) {
                        DB::table($table)->where($pk, $row->{$pk})->update($updates);
                    });
                    $totalMoved += count($updates);
                } catch (\Throwable $e) {
                    $failures[] = "{$table}#{$row->{$pk}} UPDATE: " . $e->getMessage();
                }
            } elseif ($updates) {
                $totalMoved += count($updates); // dry run: what WOULD move
            }
        }

        echo "  … through {$pk} {$lastId}\n";
    }
}

// ─── Report ──────────────────────────────────────────────────────────────────
echo "\n-------------------------------------------------------------\n";
echo "  Values scanned (non-empty) : {$totalScanned}\n";
echo "  Already on the new key     : {$totalAlready}\n";
echo "  Empty / null (skipped)     : {$totalEmpty}\n";
echo "  Pending rotation           : {$totalPending}\n";
echo ($isLive ? "  Re-encrypted               : {$totalMoved}\n"
              : "  WOULD re-encrypt           : {$totalMoved}\n");
echo "  Failures                   : " . count($failures) . "\n";
echo "-------------------------------------------------------------\n";

if ($failures) {
    echo "\nFAILURES (these values were NOT modified):\n";
    foreach (array_slice($failures, 0, 40) as $f) {
        echo "  - {$f}\n";
    }
    if (count($failures) > 40) {
        echo "  … and " . (count($failures) - 40) . " more\n";
    }
    echo "\nA failure usually means the value predates the current key pair, was\n";
    echo "written by a different key, or is corrupt. Investigate before removing\n";
    echo "the _OLD keys — those rows will become unreadable once you do.\n";
}

if (!$isLive) {
    echo "\nDry run complete. Nothing was written.\n";
    if ($totalPending > 0 && !$failures) {
        echo "All {$totalPending} pending value(s) decrypted cleanly — safe to run:\n";
        echo "  php scripts/rotate_encryption_keys.php --live\n";
    }
} else {
    echo "\nRotation complete.\n";
    if (!$failures && $totalPending > 0) {
        echo "Re-run WITHOUT --live to confirm 0 pending, then delete\n";
        echo "ENCRYPTION_KEY_A_OLD / ENCRYPTION_KEY_B_OLD from .env.\n";
    }
}
echo "\n";

exit($failures ? 1 : 0);
