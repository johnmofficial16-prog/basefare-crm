<?php
/**
 * centre_check.php — show how JSR / MOH / DMR actually resolve in production.
 *
 * Run this BEFORE trusting any per-centre report. It prints the raw centre
 * config, the resolved roster for each centre, and anyone who lands in no
 * centre at all. Read-only.
 *
 * Usage:  php scripts/centre_check.php
 */

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

// ── Raw config ───────────────────────────────────────────────────────────────
echo "\n=== RAW centre config in system_config ===\n";
$cfg = [];
$rows = $db->query("SELECT `key`,`value`,updated_at FROM system_config WHERE `key` LIKE 'centre.%'")
           ->fetchAll(PDO::FETCH_ASSOC);
if (!$rows) {
    echo "  (NOTHING — centre config was never saved. Per-centre reports cannot work.)\n";
} else {
    foreach ($rows as $r) {
        $cfg[$r['key']] = $r['value'];
        printf("  %-28s %-40s  (updated %s)\n",
            $r['key'], mb_substr((string)$r['value'], 0, 40), $r['updated_at'] ?? '?');
    }
}

$jsrMgr = (int)($cfg['centre.jsr_manager_id'] ?? 0);
$mohMgr = (int)($cfg['centre.moh_manager_id'] ?? 0);
$dmrIds = array_map('intval', json_decode($cfg['centre.dmr_member_ids'] ?? '[]', true) ?: []);
$ovr    = json_decode($cfg['centre.overrides'] ?? '{}', true) ?: [];

// ── Everyone, with their reporting line ──────────────────────────────────────
$users = [];
foreach ($db->query("SELECT id,name,role,status,reports_to_id,deleted_at FROM users ORDER BY id") as $u) {
    $users[(int)$u['id']] = $u;
}

echo "\n=== Named managers ===\n";
foreach ([['JSR', $jsrMgr], ['MOH', $mohMgr]] as [$code, $mid]) {
    if (!$mid) { printf("  %-4s manager: NOT SET\n", $code); continue; }
    $u = $users[$mid] ?? null;
    printf("  %-4s manager: id=%-5d %-24s role=%-10s status=%s%s\n",
        $code, $mid,
        $u['name'] ?? '!! NO SUCH USER',
        $u['role'] ?? '?', $u['status'] ?? '?',
        !empty($u['deleted_at']) ? '  [SOFT-DELETED]' : '');
}

// ── Resolve, mirroring CentreService::resolveMap() ──────────────────────────
$children = [];
foreach ($users as $u) {
    if ($u['reports_to_id'] !== null && empty($u['deleted_at'])) {
        $children[(int)$u['reports_to_id']][] = (int)$u['id'];
    }
}
$teamOf = function (int $mgr) use ($children): array {
    if (!$mgr) return [];
    $out = [$mgr]; $q = [$mgr];
    while ($q) {
        $cur = array_pop($q);
        foreach ($children[$cur] ?? [] as $c) {
            if (!in_array($c, $out, true)) { $out[] = $c; $q[] = $c; }
        }
    }
    return $out;
};

$map = [];
foreach ($teamOf($mohMgr) as $id) $map[$id] = 'MOH';
foreach ($teamOf($jsrMgr) as $id) $map[$id] = 'JSR';
foreach ($dmrIds as $id)          $map[$id] = 'DMR';
foreach ($ovr as $id => $c)       $map[(int)$id] = strtoupper($c);

foreach (['JSR','MOH','DMR'] as $code) {
    $ids = array_keys(array_filter($map, fn($c) => $c === $code));
    echo "\n=== {$code} — " . count($ids) . " members ===\n";
    if (!$ids) { echo "  (empty)\n"; continue; }
    usort($ids, fn($a,$b) => strcmp($users[$a]['name'] ?? '', $users[$b]['name'] ?? ''));
    foreach ($ids as $id) {
        $u = $users[$id] ?? null;
        if (!$u) { printf("  id=%-5d !! no such user\n", $id); continue; }
        $reportsTo = $u['reports_to_id'] ? ($users[(int)$u['reports_to_id']]['name'] ?? '?') : '—';
        printf("  id=%-5d %-26s %-11s %-10s reports_to: %s%s\n",
            $id, mb_substr($u['name'], 0, 26), $u['role'], $u['status'],
            mb_substr($reportsTo, 0, 20),
            !empty($u['deleted_at']) ? '  [DELETED]' : '');
    }
}

// ── Anyone in no centre ──────────────────────────────────────────────────────
$assigned = array_keys($map);
$orphans = [];
foreach ($users as $id => $u) {
    if (in_array($u['role'], ['agent','supervisor','manager','csa'], true)
        && $u['status'] === 'active' && empty($u['deleted_at'])
        && !in_array($id, $assigned, true)) {
        $orphans[] = $u;
    }
}
echo "\n=== ACTIVE staff in NO centre — " . count($orphans) . " ===\n";
if (!$orphans) {
    echo "  (none — every active member is assigned)\n";
} else {
    echo "  These are invisible to every per-centre report:\n";
    foreach ($orphans as $u) {
        printf("  id=%-5d %-26s %-11s reports_to: %s\n",
            $u['id'], mb_substr($u['name'], 0, 26), $u['role'],
            $u['reports_to_id'] ? ($users[(int)$u['reports_to_id']]['name'] ?? '?') : '— (nobody)');
    }
}
echo "\n";
