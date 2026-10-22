<?php
/**
 * Team leaders (John, 22 Oct 2026): Kevin and Sam become team leaders
 * (role 'supervisor'), each with four agents, and both report to manager Cyrus.
 * Run over SSH from the repo root:
 *
 *   php scripts/assign_teams.php           # shows what will change, then does it
 *   php scripts/assign_teams.php --undo    # puts everyone back as it was before
 *
 * Names are matched against active CRM users: a full name first, otherwise the
 * first name. Two-word names ("Max Marilla") are matched before one-word names
 * ("Max"), so the two Maxes don't get mixed up. If any name matches nobody, or
 * more than one person, NOTHING is changed: the script lists who it found so
 * the name can be fixed with --pick, e.g.
 *
 *   php scripts/assign_teams.php --pick="Max=57"
 *
 * Someone who has no CRM login yet can be left out and added later
 * (Users, edit them, Reports To = their team leader):
 *
 *   php scripts/assign_teams.php --skip="Shawn"
 *
 * Before changing anything it saves everyone's current role and team leader
 * to storage/logs/assign_teams_undo.json, which --undo uses.
 *
 * CLI only: the scripts/ folder is denied over HTTP by the root .htaccess.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();
date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'Asia/Kolkata');


const MANAGER = 'Cyrus';
const TEAMS = [
    'Kevin' => ['Cynthia', 'Marcos', 'Max', 'Shawn'],
    'Sam'   => ['Daphne', 'Max Marilla', 'Tia', 'Mike'],
];
$undoFile = dirname(__DIR__) . '/storage/logs/assign_teams_undo.json';

$env = fn(string $k, string $d = ''): string => (string) ($_ENV[$k] ?? (getenv($k) !== false ? getenv($k) : $d));
$db = new mysqli($env('DB_HOST', 'localhost'), $env('DB_USERNAME'), $env('DB_PASSWORD'), $env('DB_DATABASE'), (int) $env('DB_PORT', '3306'));
if ($db->connect_errno) {
    fwrite(STDERR, "Could not connect to the database: {$db->connect_error}\n");
    exit(1);
}
$db->set_charset('utf8mb4');
$db->autocommit(true);

// ---- undo ----------------------------------------------------------------------------
if (in_array('--undo', $argv, true)) {
    if (!is_file($undoFile)) {
        fwrite(STDERR, "Nothing to undo (no saved state at storage/logs/assign_teams_undo.json).\n");
        exit(1);
    }
    $saved = json_decode((string) file_get_contents($undoFile), true) ?: [];
    $stmt = $db->prepare('UPDATE users SET role = ?, reports_to_id = ? WHERE id = ?');
    foreach ($saved as $row) {
        $role = $row['role'];
        $rep = $row['reports_to_id'] === null ? null : (int) $row['reports_to_id'];
        $id = (int) $row['id'];
        $stmt->bind_param('sii', $role, $rep, $id);
        $stmt->execute();
        echo "  {$row['name']}: back to {$role}" . ($rep ? " (reports to #{$rep})" : '') . "\n";
    }
    rename($undoFile, $undoFile . '.used-' . date('Ymd-His'));
    echo "Undone.\n";
    exit(0);
}

// ---- find everyone -----------------------------------------------------------------------
$picks = [];
$skips = [];
foreach ($argv as $arg) {
    if (preg_match('/^--skip=(.+)$/', $arg, $m)) {
        foreach (explode(',', $m[1]) as $n) {
            if (trim($n) !== '') {
                $skips[mb_strtolower(trim($n))] = trim($n);
            }
        }
    }
    if (preg_match('/^--pick=(.+)$/', $arg, $m)) {
        foreach (explode(',', $m[1]) as $pair) {
            if (preg_match('/^\s*(.+?)\s*=\s*(\d+)\s*$/', $pair, $p)) {
                $picks[mb_strtolower($p[1])] = (int) $p[2];
            }
        }
    }
}

$people = [];
$everyone = [];   // incl. inactive/suspended, only to explain a name that doesn't match
$res = $db->query("SELECT id, name, email, role, status, reports_to_id FROM users WHERE deleted_at IS NULL");
while ($r = $res->fetch_assoc()) {
    $everyone[(int) $r['id']] = $r;
    if ($r['status'] === 'active') {
        $people[(int) $r['id']] = $r;
    }
}
$norm = fn(string $s): string => mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $s)));
$words = fn(string $s): array => preg_split('/[^\p{L}\p{N}]+/u', $norm($s), -1, PREG_SPLIT_NO_EMPTY) ?: [];

$claimed = [];
$problems = [];
/** One person for a name, among the given roles. */
$find = function (string $name, array $roles) use (&$people, &$everyone, &$claimed, &$problems, $picks, $norm, $words): ?int {
    $key = $norm($name);
    if (isset($picks[$key])) {
        $id = $picks[$key];
        if (!isset($people[$id])) {
            $problems[] = "\"{$name}\": --pick gave #{$id}, but there is no active user with that number.";
            return null;
        }
        return $claimed[$id] = $id;
    }
    $pool = array_filter($people, fn($p) => in_array($p['role'], $roles, true) && !isset($claimed[(int) $p['id']]));
    $want = $words($name);
    // 1. exact full name; 2. the name's words are the first words of their name ("Max Marilla" → "Max Marilla Singh"; "Max" → "Max Brown")
    $hits = array_filter($pool, fn($p) => $norm($p['name']) === $key);
    if (!$hits) {
        $hits = array_filter($pool, fn($p) => array_slice($words($p['name']), 0, count($want)) === $want);
    }
    if (count($hits) === 1) {
        $id = (int) array_key_first($hits);
        return $claimed[$id] = $id;
    }
    if ($hits) {
        $problems[] = "\"{$name}\" matches more than one person:"
            . implode('', array_map(fn($p) => "\n      #{$p['id']}  {$p['name']}  <{$p['email']}>  ({$p['role']})", $hits));
        return null;
    }
    // Nobody: show near spellings (Shawn / Sean / Shaun), any role, including inactive accounts.
    $close = function (string $a, string $b): bool {
        return $a === $b || str_contains($b, $a) || str_contains($a, $b)
            || (strlen($a) >= 3 && strlen($b) >= 3 && (levenshtein($a, $b) <= 2 || metaphone($a) === metaphone($b)));
    };
    $list = array_filter($everyone, function ($p) use ($want, $words, $close) {
        foreach ($words($p['name']) as $w) {
            foreach ($want as $x) {
                if ($close($x, $w)) {
                    return true;
                }
            }
        }
        return false;
    });
    $problems[] = "\"{$name}\" matches no active " . implode(' or ', $roles) . '.'
        . ($list ? ' Close names in the CRM:' : ' Nobody with a similar name either (check the spelling in Users).')
        . implode('', array_map(fn($p) => "\n      #{$p['id']}  {$p['name']}  <{$p['email']}>  ({$p['role']}" . ($p['status'] !== 'active' ? ', ' . strtoupper($p['status']) : '') . ')', $list));
    return null;
};

$teams = [];
foreach (TEAMS as $leader => $agents) {
    $teams[$leader] = array_values(array_filter($agents, fn($a) => !isset($skips[mb_strtolower($a)])));
}
$managerId = $find(MANAGER, ['manager']);
$leaders = [];
foreach (array_keys(TEAMS) as $leader) {
    $leaders[$leader] = $find($leader, ['agent', 'supervisor', 'csa']);
}
// Two-word names first, so "Max Marilla" is taken before "Max" is looked up.
$all = [];
foreach ($teams as $leader => $agents) {
    foreach ($agents as $a) {
        $all[] = [$leader, $a];
    }
}
usort($all, fn($x, $y) => count($words($y[1])) <=> count($words($x[1])));
$members = [];
foreach ($all as [$leader, $a]) {
    $members[$leader][$a] = $find($a, ['agent', 'csa']);
}

if ($problems) {
    echo "Nothing was changed. Some names need sorting out first:\n\n";
    foreach ($problems as $p) {
        echo "  - {$p}\n";
    }
    echo "\nRun again with the right person's number, for example:\n  php scripts/assign_teams.php --pick=\"Max=57\"\n(several: --pick=\"Max=57,Tia=61\")\n";
    echo "Or leave someone out for now (add them later in Users): php scripts/assign_teams.php --skip=\"Shawn\"\n";
    exit(1);
}

// ---- show and apply ----------------------------------------------------------------------
$changes = [];   // id => [role, reports_to_id]
foreach ($leaders as $leader => $lid) {
    $changes[$lid] = ['supervisor', $managerId];
    foreach ($teams[$leader] as $a) {
        $changes[$members[$leader][$a]] = ['agent', $lid];
    }
}
$undo = [];
echo "Manager: {$people[$managerId]['name']} (#{$managerId})\n";
foreach ($leaders as $leader => $lid) {
    echo "\nTeam leader: {$people[$lid]['name']} (#{$lid}), was {$people[$lid]['role']}, now reports to {$people[$managerId]['name']}\n";
    foreach ($teams[$leader] as $a) {
        $id = $members[$leader][$a];
        $was = $people[$id]['reports_to_id'] ? ($people[(int) $people[$id]['reports_to_id']]['name'] ?? '#' . $people[$id]['reports_to_id']) : 'nobody';
        echo "  - {$people[$id]['name']} (#{$id}), reported to {$was}\n";
    }
}
foreach ($changes as $id => $_) {
    $undo[] = ['id' => $id, 'name' => $people[$id]['name'], 'role' => $people[$id]['role'],
        'reports_to_id' => $people[$id]['reports_to_id'] === null ? null : (int) $people[$id]['reports_to_id']];
}
if (!is_file($undoFile)) {
    @mkdir(dirname($undoFile), 0755, true);
    file_put_contents($undoFile, json_encode($undo, JSON_PRETTY_PRINT));
}

$stmt = $db->prepare('UPDATE users SET role = ?, reports_to_id = ?, updated_at = NOW() WHERE id = ?');
$log = $db->prepare("INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, ip_address, created_at) VALUES (NULL, 'team_assigned', 'users', ?, ?, 'cli', NOW())");
foreach ($changes as $id => [$role, $rep]) {
    $stmt->bind_param('sii', $role, $rep, $id);
    if (!$stmt->execute()) {
        fwrite(STDERR, "Could not update #{$id}: {$stmt->error}\n");
        exit(1);
    }
    $details = json_encode(['before' => ['role' => $people[$id]['role'], 'reports_to_id' => $people[$id]['reports_to_id']], 'after' => ['role' => $role, 'reports_to_id' => $rep], 'by' => 'scripts/assign_teams.php']);
    $log->bind_param('is', $id, $details);
    @$log->execute();
}

$left = [];
$res = $db->query('SELECT name FROM users WHERE status = \'active\' AND deleted_at IS NULL AND reports_to_id = ' . (int) $managerId . " AND role IN ('agent','csa') ORDER BY name");
while ($r = $res->fetch_assoc()) {
    $left[] = $r['name'];
}
echo "\nDone: " . count($changes) . " people updated.\n";
foreach ($skips as $n) {
    echo "Left out: {$n}. When they have a CRM login, set Reports To = their team leader in Users.\n";
}
if ($left) {
    echo "Still reporting straight to {$people[$managerId]['name']} (not in either team): " . implode(', ', $left) . "\n";
}
echo "To put it all back: php scripts/assign_teams.php --undo\n";
