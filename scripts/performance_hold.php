<?php
/**
 * performance_hold.php — turn the Performance-tab merchant hold on or off.
 *
 * The hold hides a window of activity from the Performance scoreboard for every
 * role EXCEPT admin. It changes no records whatsoever: transactions, acceptances,
 * invoices, e-tickets, the dashboard, the live board, centre analytics and every
 * report continue to show the true figures. See App\Services\PerformanceHold.
 *
 * Usage (from the project root):
 *   php scripts/performance_hold.php status
 *   php scripts/performance_hold.php on  2026-08-01 2026-08-09
 *   php scripts/performance_hold.php off
 *
 * "on <from> <until>" holds everything from <from> 00:00:00 through
 * <until> 23:59:59 inclusive. Scoring resumes at 00:00:00 the following day.
 *
 * Lifting the hold restores every figure instantly — nothing was ever changed,
 * so there is nothing to backfill.
 */

$cmd = $argv[1] ?? 'status';

// ── Connect ──────────────────────────────────────────────────────────────────
$envPath = __DIR__ . '/../.env';
if (!file_exists($envPath)) exit("No .env at {$envPath}\n");
$e = [];
foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
    $l = trim($l);
    if ($l === '' || $l[0] === '#' || !str_contains($l, '=')) continue;
    [$k, $v] = explode('=', $l, 2);
    $e[trim($k)] = trim(trim($v), "\"'");
}
try {
    $db = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $e['DB_HOST'] ?? '127.0.0.1', $e['DB_PORT'] ?? '3306', $e['DB_DATABASE'] ?? ''),
        $e['DB_USERNAME'] ?? '', $e['DB_PASSWORD'] ?? '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (Throwable $x) {
    exit('DB connect failed: ' . $x->getMessage() . "\n");
}

$KEYS = ['performance.hold_enabled', 'performance.hold_from',
         'performance.hold_until', 'performance.hold_notice'];

function put(PDO $db, string $key, string $val): void
{
    $now = date('Y-m-d H:i:s');
    $ex  = $db->prepare('SELECT COUNT(*) FROM system_config WHERE `key` = ?');
    $ex->execute([$key]);
    if ((int)$ex->fetchColumn() > 0) {
        $db->prepare('UPDATE system_config SET `value` = ?, updated_at = ? WHERE `key` = ?')
           ->execute([$val, $now, $key]);
    } else {
        $db->prepare('INSERT INTO system_config (`key`, `value`, updated_at) VALUES (?, ?, ?)')
           ->execute([$key, $val, $now]);
    }
}

function show(PDO $db, array $keys): void
{
    $in = implode(',', array_fill(0, count($keys), '?'));
    $st = $db->prepare("SELECT `key`,`value` FROM system_config WHERE `key` IN ($in)");
    $st->execute($keys);
    $rows = $st->fetchAll(PDO::FETCH_KEY_PAIR);

    $on = ($rows['performance.hold_enabled'] ?? '0') === '1';
    echo "\n  Performance hold: " . ($on ? "*** ACTIVE ***" : "off") . "\n";
    if ($rows) {
        foreach ($keys as $k) {
            printf("    %-26s %s\n", $k, $rows[$k] ?? '(unset)');
        }
    }
    if ($on) {
        $until = strtotime($rows['performance.hold_until'] ?? '');
        if ($until) echo "    scoring resumes           " . date('Y-m-d H:i:s', $until + 1) . "\n";
        echo "\n    Admins see real figures. All other roles see the scoreboard\n";
        echo "    with the held window excluded. No records were modified.\n";
    }
    echo "\n";
}

// ── Commands ─────────────────────────────────────────────────────────────────
if ($cmd === 'status') {
    show($db, $KEYS);
    exit(0);
}

if ($cmd === 'off') {
    put($db, 'performance.hold_enabled', '0');
    echo "\n  Hold LIFTED. Every figure is back to normal immediately.\n";
    show($db, $KEYS);
    exit(0);
}

if ($cmd === 'on') {
    $from  = $argv[2] ?? null;
    $until = $argv[3] ?? null;
    foreach ([$from, $until] as $d) {
        if (!$d || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
            exit("Usage: php scripts/performance_hold.php on YYYY-MM-DD YYYY-MM-DD\n");
        }
    }
    if (strtotime($until) < strtotime($from)) {
        exit("The 'until' date is before the 'from' date.\n");
    }

    $resumes = date('j F Y', strtotime($until) + 86400);
    $notice  = "Performance scoring for this month starts from {$resumes}. "
             . "Sales recorded earlier this month are pending release with the merchant "
             . "and are not counted toward the current score, apart from bookings "
             . "already confirmed as unaffected.";

    put($db, 'performance.hold_from',    $from  . ' 00:00:00');
    put($db, 'performance.hold_until',   $until . ' 23:59:59');
    put($db, 'performance.hold_notice',  $notice);
    put($db, 'performance.hold_enabled', '1');

    // Audit trail — best effort, never blocks the change.
    try {
        $db->prepare("INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, ip_address, created_at)
                      VALUES (NULL, 'performance_hold_enabled', 'system_config', NULL, ?, '127.0.0.1', ?)")
           ->execute([json_encode(['from' => $from, 'until' => $until, 'via' => 'cli']), date('Y-m-d H:i:s')]);
    } catch (Throwable $x) {
        error_log('[performance_hold] activity_log insert failed: ' . $x->getMessage());
    }

    echo "\n  Hold ENABLED.\n";
    echo "    held window   {$from} 00:00:00 .. {$until} 23:59:59\n";
    echo "    resumes       " . date('Y-m-d', strtotime($until) + 86400) . " 00:00:00\n";
    echo "    banner        {$notice}\n";
    show($db, $KEYS);
    exit(0);
}

exit("Unknown command '{$cmd}'. Use: status | on <from> <until> | off\n");
