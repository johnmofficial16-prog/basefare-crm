<?php
/**
 * jsr_login_audit.php — "who actually logged in today?" for one centre.
 *
 * The Live Board headcount counts ACTIVE ATTENDANCE SESSIONS. A session is not
 * proof that the agent showed up:
 *
 *   - AttendanceService::adminClockIn() lets an admin/manager open a session on
 *     an agent's behalf. Those sessions carry user_agent = 'admin-manual'.
 *     The agent never authenticated.
 *   - A self clock-in carries the real browser user-agent. Because /clock-in is
 *     behind AuthMiddleware, that IS evidence of a genuine login.
 *   - An agent can clock in and then do nothing for the rest of the shift.
 *
 * This script separates those cases. Read-only; every query is a SELECT.
 *
 * NOTE ON A REAL LIMITATION: successful logins are never written to
 * activity_log (there is no 'login' action), login_attempts records only
 * FAILURES and is cleared on success, and users.active_session_id keeps no
 * history. So genuine login history does not exist in the database. Self
 * clock-in is the closest reliable proxy and is what this report uses.
 *
 * Usage (from the project root, over SSH):
 *   php scripts/jsr_login_audit.php               # today, JSR
 *   php scripts/jsr_login_audit.php 2026-07-15    # a specific date
 *   php scripts/jsr_login_audit.php 2026-07-01 2026-07-31   # a date range
 *   php scripts/jsr_login_audit.php 2026-07-01 2026-07-31 MOH
 */

$dateFrom = $argv[1] ?? date('Y-m-d');
$dateTo   = $argv[2] ?? $dateFrom;
$centreWanted = strtoupper($argv[3] ?? 'JSR');

foreach ([$dateFrom, $dateTo] as $d) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
        exit("Bad date '{$d}'. Use YYYY-MM-DD.\n");
    }
}

// ── Connect ──────────────────────────────────────────────────────────────────
$envPath = __DIR__ . '/../.env';
if (!file_exists($envPath)) exit("No .env at {$envPath}\n");
$env = [];
foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
    [$k, $v] = explode('=', $line, 2);
    $env[trim($k)] = trim(trim($v), "\"'");
}
try {
    $db = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $env['DB_HOST'] ?? '127.0.0.1', $env['DB_PORT'] ?? '3306', $env['DB_DATABASE'] ?? ''),
        $env['DB_USERNAME'] ?? '', $env['DB_PASSWORD'] ?? '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (Throwable $e) {
    exit('DB connect failed: ' . $e->getMessage() . "\n");
}

// ── Resolve centre membership — mirrors CentreService::resolveMap() exactly ───
// Priority, lowest first (later writes win): MOH team → JSR team → DMR → overrides.
$cfg = [];
foreach ($db->query("SELECT `key`,`value` FROM system_config WHERE `key` LIKE 'centre.%'") as $r) {
    $cfg[$r['key']] = $r['value'];
}
$jsrMgr = (int)($cfg['centre.jsr_manager_id'] ?? 0);
$mohMgr = (int)($cfg['centre.moh_manager_id'] ?? 0);
$dmrIds = array_map('intval', json_decode($cfg['centre.dmr_member_ids'] ?? '[]', true) ?: []);
$ovr    = json_decode($cfg['centre.overrides'] ?? '{}', true) ?: [];

if (!$jsrMgr && !$mohMgr && !$dmrIds) {
    echo "!! Centre config is empty in system_config — cannot resolve {$centreWanted}.\n";
    echo "   Set it at /analytics/settings first, or this report has no roster.\n\n";
}

// Whole reports_to graph, then BFS down from a manager.
$children = [];
foreach ($db->query("SELECT id, reports_to_id FROM users WHERE reports_to_id IS NOT NULL AND deleted_at IS NULL") as $r) {
    $children[(int)$r['reports_to_id']][] = (int)$r['id'];
}
$teamOf = function (int $mgr) use ($children): array {
    if (!$mgr) return [];
    $out = [$mgr]; $queue = [$mgr];
    while ($queue) {
        $cur = array_pop($queue);
        foreach ($children[$cur] ?? [] as $c) {
            if (!in_array($c, $out, true)) { $out[] = $c; $queue[] = $c; }
        }
    }
    return $out;
};

$map = [];
foreach ($teamOf($mohMgr) as $id) $map[$id] = 'MOH';
foreach ($teamOf($jsrMgr) as $id) $map[$id] = 'JSR';
foreach ($dmrIds as $id)          $map[$id] = 'DMR';
foreach ($ovr as $id => $c)       $map[(int)$id] = strtoupper($c);

$memberIds = array_keys(array_filter($map, fn($c) => $c === $centreWanted));
$resolvedVia = 'system_config centre.* rules';

// Fallback: the business rule is "JSR = everyone under Thomas Jaan (recursive)".
// If centre config was never saved, resolve straight off that manager's tree so
// the report still runs. Override the name with env CENTRE_MANAGER if needed.
if (!$memberIds && $centreWanted === 'JSR') {
    $mgrName = getenv('CENTRE_MANAGER') ?: 'thomas';
    $st = $db->prepare("SELECT id, name FROM users WHERE LOWER(name) LIKE :n AND deleted_at IS NULL ORDER BY id");
    $st->execute(['n' => '%' . strtolower($mgrName) . '%']);
    $hits = $st->fetchAll(PDO::FETCH_ASSOC);

    if (count($hits) === 1) {
        $memberIds   = $teamOf((int)$hits[0]['id']);
        $resolvedVia = "fallback: recursive tree under '{$hits[0]['name']}' (id {$hits[0]['id']}) — centre config was empty";
    } elseif (count($hits) > 1) {
        echo "Ambiguous manager name '{$mgrName}' — matched:\n";
        foreach ($hits as $h) echo "   id={$h['id']}  {$h['name']}\n";
        exit("Re-run with:  CENTRE_MANAGER='Full Name' php scripts/jsr_login_audit.php ...\n");
    }
}

if (!$memberIds) {
    exit("No members resolved for centre {$centreWanted}.\n"
       . "Centre config is empty and no manager matched. Run scripts/centre_check.php first.\n");
}

$in = implode(',', array_map('intval', $memberIds));
$members = $db->query("
    SELECT id, name, role, status, deleted_at
    FROM users WHERE id IN ($in) ORDER BY role, name
")->fetchAll(PDO::FETCH_ASSOC);

echo "\n";
echo "═══════════════════════════════════════════════════════════════════════════\n";
echo " {$centreWanted} LOGIN AUDIT   {$dateFrom}" . ($dateTo !== $dateFrom ? " .. {$dateTo}" : "") . "\n";
echo " Roster: " . count($members) . " members\n";
echo " Resolved via: {$resolvedVia}\n";
echo "═══════════════════════════════════════════════════════════════════════════\n\n";

// ── Sessions in the window ───────────────────────────────────────────────────
$st = $db->prepare("
    SELECT s.id, s.user_id, s.date, s.clock_in, s.clock_out, s.status,
           s.late_minutes, s.total_work_mins, s.ip_address, s.user_agent
    FROM attendance_sessions s
    WHERE s.user_id IN ($in) AND s.date BETWEEN :f AND :t
    ORDER BY s.date, s.clock_in
");
$st->execute(['f' => $dateFrom, 't' => $dateTo]);
$sessions = $st->fetchAll(PDO::FETCH_ASSOC);

// ── Activity per user per day (excluding the clock_in row itself) ────────────
$act = [];
$sa = $db->prepare("
    SELECT user_id, DATE(created_at) d, COUNT(*) n
    FROM activity_log
    WHERE user_id IN ($in) AND DATE(created_at) BETWEEN :f AND :t
      AND action NOT IN ('clock_in','admin_clock_in')
    GROUP BY user_id, DATE(created_at)
");
$sa->execute(['f' => $dateFrom, 't' => $dateTo]);
foreach ($sa as $r) $act[$r['user_id']][$r['d']] = (int)$r['n'];

// Notes are written on nearly every record interaction — a good work signal.
$notes = [];
try {
    $sn = $db->prepare("
        SELECT user_id, DATE(created_at) d, COUNT(*) n
        FROM record_notes
        WHERE user_id IN ($in) AND DATE(created_at) BETWEEN :f AND :t
        GROUP BY user_id, DATE(created_at)
    ");
    $sn->execute(['f' => $dateFrom, 't' => $dateTo]);
    foreach ($sn as $r) $notes[$r['user_id']][$r['d']] = (int)$r['n'];
} catch (Throwable $e) { /* optional signal */ }

$txn = [];
try {
    $stx = $db->prepare("
        SELECT agent_id, DATE(created_at) d, COUNT(*) n
        FROM transactions
        WHERE agent_id IN ($in) AND DATE(created_at) BETWEEN :f AND :t
        GROUP BY agent_id, DATE(created_at)
    ");
    $stx->execute(['f' => $dateFrom, 't' => $dateTo]);
    foreach ($stx as $r) $txn[$r['agent_id']][$r['d']] = (int)$r['n'];
} catch (Throwable $e) { /* optional signal */ }

// Who was rostered — so "no session" can be told apart from "day off".
$roster = [];
try {
    $sr = $db->prepare("
        SELECT agent_id, shift_date FROM shift_schedules
        WHERE agent_id IN ($in) AND shift_date BETWEEN :f AND :t
    ");
    $sr->execute(['f' => $dateFrom, 't' => $dateTo]);
    foreach ($sr as $r) $roster[$r['agent_id']][$r['shift_date']] = true;
} catch (Throwable $e) { /* optional signal */ }

$nameOf = [];
foreach ($members as $m) $nameOf[$m['id']] = $m;

// ── Per-session detail ───────────────────────────────────────────────────────
$tot = ['sessions'=>0,'self'=>0,'admin'=>0,'zero'=>0,'autoclosed'=>0];

printf("%-22s %-11s %-9s %-13s %-12s %6s %6s  %s\n",
       'AGENT','DATE','CLOCK-IN','SOURCE','SESSION','ACTS','TXNS','VERDICT');
echo str_repeat('-', 108) . "\n";

foreach ($sessions as $s) {
    $uid = (int)$s['user_id'];
    $d   = $s['date'];
    $ua  = (string)$s['user_agent'];
    $isAdminMade = ($ua === 'admin-manual');

    $a = ($act[$uid][$d] ?? 0) + ($notes[$uid][$d] ?? 0);
    $t = $txn[$uid][$d] ?? 0;

    $tot['sessions']++;
    $isAdminMade ? $tot['admin']++ : $tot['self']++;
    if ($s['status'] === 'auto_closed') $tot['autoclosed']++;

    if ($isAdminMade && $a === 0 && $t === 0) {
        $verdict = '*** NEVER LOGGED IN — admin-clocked, zero activity';
        $tot['zero']++;
    } elseif ($isAdminMade) {
        $verdict = 'admin-clocked, but agent was active afterwards';
    } elseif ($a === 0 && $t === 0) {
        $verdict = '** logged in, ZERO activity all day';
        $tot['zero']++;
    } elseif ($a < 5 && $t === 0) {
        $verdict = '* barely active';
    } else {
        $verdict = 'ok';
    }

    printf("%-22s %-11s %-9s %-13s %-12s %6d %6d  %s\n",
        mb_substr($nameOf[$uid]['name'] ?? ('#'.$uid), 0, 22),
        $d,
        $s['clock_in'] ? date('H:i', strtotime($s['clock_in'])) : '—',
        $isAdminMade ? 'ADMIN-MANUAL' : 'self',
        $s['status'],
        $a, $t, $verdict
    );
}

// ── Rostered but no session at all ───────────────────────────────────────────
$seen = [];
foreach ($sessions as $s) $seen[$s['user_id']][$s['date']] = true;

$missing = [];
foreach ($members as $m) {
    for ($d = strtotime($dateFrom); $d <= strtotime($dateTo); $d += 86400) {
        $day = date('Y-m-d', $d);
        if (isset($seen[$m['id']][$day])) continue;
        $wasRostered = isset($roster[$m['id']][$day]);
        if ($wasRostered) $missing[] = [$m['name'], $m['role'], $day];
    }
}

echo "\n";
if ($missing) {
    echo "ROSTERED BUT NO SESSION AT ALL (" . count($missing) . ")\n";
    echo str_repeat('-', 60) . "\n";
    foreach (array_slice($missing, 0, 60) as $r) {
        printf("  %-24s %-12s %s\n", mb_substr($r[0], 0, 24), $r[1], $r[2]);
    }
    if (count($missing) > 60) echo "  ... and " . (count($missing) - 60) . " more\n";
} else {
    echo "ROSTERED BUT NO SESSION: none";
    if (!$roster) echo "  (no shift_schedules rows in window — cannot distinguish day-off from absent)";
    echo "\n";
}

// ── Summary ──────────────────────────────────────────────────────────────────
echo "\n";
echo "═══════════════════════════════════════════════════════════════════════════\n";
echo " SUMMARY — {$centreWanted}, {$dateFrom}" . ($dateTo !== $dateFrom ? " .. {$dateTo}" : "") . "\n";
echo "═══════════════════════════════════════════════════════════════════════════\n";
printf("  Sessions counted by the board          : %d\n", $tot['sessions']);
printf("    ├─ self clock-in (agent authenticated): %d\n", $tot['self']);
printf("    └─ ADMIN-MANUAL (agent did NOT log in): %d   <-- headcount inflation\n", $tot['admin']);
printf("  Clocked in but zero activity            : %d\n", $tot['zero']);
printf("  Auto-closed (never clocked out)         : %d\n", $tot['autoclosed']);
printf("  Rostered with no session at all         : %d\n", count($missing));
echo "\n";
echo "  Real headcount (self clock-in AND active): " . ($tot['self'] - max(0, $tot['zero'] - $tot['admin'])) . "\n";
echo "\n";
echo "  Caveat: successful logins are not stored anywhere in this database, so\n";
echo "  'self clock-in' is the strongest available proof of a real login.\n\n";
