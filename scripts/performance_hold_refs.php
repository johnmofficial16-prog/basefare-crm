<?php
/**
 * performance_hold_refs.php — validate and store the "safe booking" exemption
 * list for the Performance merchant hold.
 *
 * These are bookings the client confirmed were charged through a DIFFERENT
 * merchant, so they are not affected by the hold and must still count toward an
 * agent's score.
 *
 * Read-only by default: it reports what each reference resolves to and which
 * ones resolve to nothing. Pass --save to write the list into system_config.
 * Nothing in `transactions` is ever modified.
 *
 * Usage (from the project root):
 *   php scripts/performance_hold_refs.php            # dry run, report only
 *   php scripts/performance_hold_refs.php --save     # report, then store
 */

// ── The client's list. Lines containing "||" are exchange pairs (old || new);
//    both sides are treated as valid references for the same booking. ─────────
$RAW = <<<'TXT'
SRENTH
9BYEPT
BTN5ON
ZCOQJG
ZDP3FJ
G49284
ZE5FSW || ZELHY3
YA7RN9
XK5KQY
ZVRXFJ
YEBGZH || YDT5LZ
CK5R3J
TXT;

$save = in_array('--save', $argv, true);

// Split on newlines, "||", commas and whitespace; normalise to upper/trimmed.
$refs = preg_split('/[\s,|]+/', $RAW, -1, PREG_SPLIT_NO_EMPTY);
$refs = array_values(array_unique(array_map(fn($r) => strtoupper(trim($r)), $refs)));

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

// Current hold window, so we can say whether each exemption actually matters.
$cfg = [];
$st = $db->prepare("SELECT `key`,`value` FROM system_config WHERE `key` LIKE 'performance.hold%'");
$st->execute();
foreach ($st->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v) $cfg[$k] = $v;
$from  = $cfg['performance.hold_from']  ?? null;
$until = $cfg['performance.hold_until'] ?? null;

echo "\n" . str_repeat('=', 96) . "\n";
echo " SAFE-BOOKING EXEMPTION CHECK — " . count($refs) . " references\n";
echo " Hold window: " . ($from ? "{$from} .. {$until}" : "(not configured yet)") . "\n";
echo str_repeat('=', 96) . "\n\n";

// Agents sometimes put two PNRs in one field, inconsistently — production holds
// both "ZE5FSW | ZELHY3" and "YEBGZH/YDT5LZ". Normalise separators to commas and
// match whole tokens, so "ZE5FSWX" can never be mistaken for "ZE5FSW".
$norm = function (string $col): string {
    $u = "UPPER(TRIM(COALESCE(`{$col}`, '')))";
    $u = "REPLACE($u, ' ', '')";
    foreach (['|', '/', ';', '\\\\'] as $sep) {
        $u = "REPLACE($u, '{$sep}', ',')";
    }
    return $u;
};

$clauses = [];
$binds   = [];
foreach ($refs as $r) {
    $clauses[] = 'FIND_IN_SET(?, ' . $norm('pnr') . ') > 0';
    $binds[]   = $r;
    $clauses[] = 'FIND_IN_SET(?, ' . $norm('order_id') . ') > 0';
    $binds[]   = $r;
}
$sql = "SELECT id, agent_id, pnr, order_id, customer_name, total_amount, profit_mco,
               currency, status, created_at
          FROM transactions
         WHERE " . implode(' OR ', $clauses) . "
         ORDER BY created_at";
$st = $db->prepare($sql);
$st->execute($binds);
$hits = $st->fetchAll(PDO::FETCH_ASSOC);

// Agent names
$names = [];
foreach ($db->query("SELECT id,name FROM users") as $u) $names[(int)$u['id']] = $u['name'];

$matchedRefs = [];
$inWindow = 0; $outWindow = 0; $mcoRestored = 0.0;

printf("%-9s %-11s %-18s %-22s %-11s %10s %10s  %s\n",
       'PNR','ORDER','AGENT','CUSTOMER','DATE','AMOUNT','GROSS MCO','IN HOLD?');
echo str_repeat('-', 118) . "\n";

/** Same normalisation as the SQL, so the "unmatched" report agrees with the query. */
$tokens = function (?string $v): array {
    $u = str_replace(' ', '', strtoupper(trim((string) $v)));
    $u = str_replace(['|', '/', ';', '\\'], ',', $u);
    return array_filter(explode(',', $u));
};

foreach ($hits as $t) {
    $tok = array_merge($tokens($t['pnr']), $tokens($t['order_id']));
    foreach ($refs as $r) {
        if ($r !== '' && in_array($r, $tok, true)) $matchedRefs[$r] = true;
    }

    $within = ($from && $until)
        ? ($t['created_at'] >= $from && $t['created_at'] <= $until)
        : false;
    if ($within) { $inWindow++; $mcoRestored += (float)$t['profit_mco']; } else { $outWindow++; }

    printf("%-9s %-11s %-18s %-22s %-11s %10s %10s  %s\n",
        substr($t['pnr'] ?: '-', 0, 9),
        substr($t['order_id'] ?: '-', 0, 11),
        substr($names[(int)$t['agent_id']] ?? ('#'.$t['agent_id']), 0, 18),
        substr($t['customer_name'] ?: '-', 0, 22),
        substr($t['created_at'], 0, 10),
        number_format((float)$t['total_amount'], 2),
        number_format((float)$t['profit_mco'], 2),
        $within ? 'YES - will be restored' : 'no - outside window'
    );
}

if (!$hits) echo "  (nothing matched)\n";

// ── Unmatched ────────────────────────────────────────────────────────────────
$missing = array_values(array_diff($refs, array_keys($matchedRefs)));
echo "\n" . str_repeat('-', 96) . "\n";
printf("  references supplied : %d\n", count($refs));
printf("  matched a booking   : %d\n", count($matchedRefs));
printf("  NO MATCH            : %d%s\n", count($missing), $missing ? '  <<< CHECK THESE' : '');
if ($missing) {
    echo "\n  These resolve to no booking in the CRM. A typo or a stale reference here\n";
    echo "  means that agent's sale stays excluded and nobody will notice:\n";
    foreach ($missing as $m) echo "     - {$m}\n";
}
printf("\n  bookings inside the hold window : %d  (these are what the exemption restores)\n", $inWindow);
printf("  bookings outside the window     : %d  (exempting them changes nothing)\n", $outWindow);
printf("  gross MCO restored to scores    : %s\n", number_format($mcoRestored, 2));

// Per-agent effect
if ($inWindow) {
    echo "\n  Effect per agent (bookings restored to the scoreboard):\n";
    $byAgent = [];
    foreach ($hits as $t) {
        if (!($from && $until)) continue;
        if (!($t['created_at'] >= $from && $t['created_at'] <= $until)) continue;
        $n = $names[(int)$t['agent_id']] ?? ('#'.$t['agent_id']);
        $byAgent[$n]['n'] = ($byAgent[$n]['n'] ?? 0) + 1;
        $byAgent[$n]['m'] = ($byAgent[$n]['m'] ?? 0) + (float)$t['profit_mco'];
    }
    arsort($byAgent);
    foreach ($byAgent as $n => $v) printf("     %-20s %d booking(s)  %s MCO\n", $n, $v['n'], number_format($v['m'], 2));
}

// ── Save ─────────────────────────────────────────────────────────────────────
echo "\n";
if (!$save) {
    echo "  DRY RUN — nothing written. Re-run with --save to store the exemption list.\n\n";
    exit(0);
}

$now = date('Y-m-d H:i:s');
$key = 'performance.hold_exempt_refs';
$val = json_encode(array_values($refs));
$ex  = $db->prepare('SELECT COUNT(*) FROM system_config WHERE `key` = ?');
$ex->execute([$key]);
if ((int)$ex->fetchColumn() > 0) {
    $db->prepare('UPDATE system_config SET `value` = ?, updated_at = ? WHERE `key` = ?')->execute([$val, $now, $key]);
} else {
    $db->prepare('INSERT INTO system_config (`key`,`value`,updated_at) VALUES (?,?,?)')->execute([$key, $val, $now]);
}

try {
    $db->prepare("INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, ip_address, created_at)
                  VALUES (NULL,'performance_hold_exemptions_set','system_config',NULL,?, '127.0.0.1', ?)")
       ->execute([json_encode(['refs' => $refs, 'matched' => count($matchedRefs), 'unmatched' => $missing]), $now]);
} catch (Throwable $x) {
    error_log('[performance_hold_refs] activity_log insert failed: ' . $x->getMessage());
}

echo "  SAVED. " . count($refs) . " references stored in system_config.\n";
echo "  These bookings will keep counting toward agent scores despite the hold.\n\n";
