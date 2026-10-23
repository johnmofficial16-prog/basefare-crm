<?php
/**
 * Aisha usage report: how much staff actually talk to her, and how it goes.
 * READ-ONLY. Run over SSH from the repo root:
 *
 *   php scripts/buddy_usage_report.php            # last 30 shift days
 *   php scripts/buddy_usage_report.php --days=14
 *
 * This is the baseline for the Aisha upgrade (John, 23 Oct 2026: "what makes it
 * perfect is when the user talks to it regularly"). It prints:
 *   1. Reach and habit: who talks to her, how often, on how many of the days they work
 *   2. Conversations: turns, sessions, hours of the night, what people ask about
 *   3. Proactive Aisha: nudges by type, whether they were read, replied to, resolved
 *   4. Quality: thumbs, fallback replies, reply time, tool use, "couldn't help" answers
 *   5. The v2 scorecard (once the aisha_v2 migration has run): AI spend per
 *      shift day against the cap, measured reply times, failed answers, what
 *      people click (chips, the Tonight card, nudge buttons, quiet mode,
 *      "What Aisha knows"), nudges acted on or dropped as already done,
 *      reminders, and how many sittings the agent started themselves
 *   6. Samples: recent questions and thumbs-down answers, with card numbers,
 *      emails and phone numbers masked
 *
 * Counts by shift day (6 PM to 6 PM, or system_config business_day_start_hour),
 * like the dashboards. Admin "Super" and maintenance chats are reported separately.
 * CLI only: scripts/ is denied over HTTP by the root .htaccess.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();
date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'Asia/Kolkata');

$env = fn(string $k, string $d = ''): string => (string) ($_ENV[$k] ?? (getenv($k) !== false ? getenv($k) : $d));
$days = 30;
foreach ($argv as $a) {
    if (preg_match('/^--days=(\d+)$/', $a, $m)) {
        $days = max(3, min(120, (int) $m[1]));
    }
}

$db = new mysqli($env('DB_HOST', 'localhost'), $env('DB_USERNAME'), $env('DB_PASSWORD'), $env('DB_DATABASE'), (int) $env('DB_PORT', '3306'));
if ($db->connect_errno) {
    fwrite(STDERR, "Could not connect to the database: {$db->connect_error}\n");
    exit(1);
}
$db->set_charset('utf8mb4');
$q = function (string $sql) use ($db): array {
    $r = $db->query($sql);
    if ($r === false) {
        fwrite(STDERR, "Query failed: {$db->error}\n");
        return [];
    }
    return $r === true ? [] : $r->fetch_all(MYSQLI_ASSOC);
};

// ---- shift-day helpers ----------------------------------------------------------------------
$h = (int) ($q("SELECT value FROM system_config WHERE `key` = 'business_day_start_hour'")[0]['value'] ?? 18);
$h = max(0, min(23, $h));
$bday = fn(string $dt): string => date('Y-m-d', strtotime($dt) - $h * 3600);
$nowB = $bday(date('Y-m-d H:i:s'));
$fromB = date('Y-m-d', strtotime($nowB) - ($days - 1) * 86400);
$fromTs = date('Y-m-d H:i:s', strtotime($fromB) + $h * 3600);
$weekOf = fn(string $b): int => intdiv((int) ((strtotime($nowB) - strtotime($b)) / 86400), 7); // 0 = this week

$pct = fn($a, $b): string => $b > 0 ? round(100 * $a / $b) . '%' : 'n/a';
$med = function (array $v) {
    if (!$v) return null;
    sort($v);
    $n = count($v);
    return $n % 2 ? $v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2;
};
$p90 = function (array $v) {
    if (!$v) return null;
    sort($v);
    return $v[max(0, (int) ceil(0.9 * count($v)) - 1)];   // nearest rank: never below the median on small samples
};
$scrub = function (string $s, int $max): string {
    $s = preg_replace_callback('/(?<!\d)(?:\d[ -]?){12,18}\d(?!\d)/', function ($m) {
        $d = preg_replace('/\D/', '', $m[0]);
        $sum = 0;
        for ($i = 0, $n = strlen($d); $i < $n; $i++) {
            $x = (int) $d[$n - 1 - $i];
            if ($i % 2) { $x *= 2; if ($x > 9) $x -= 9; }
            $sum += $x;
        }
        return $sum % 10 === 0 ? '[card]' : $m[0];
    }, $s);
    $s = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[email]', $s);
    $s = preg_replace('/\+?\d[\d\s().-]{8,}\d/', '[phone]', $s);
    $s = trim(preg_replace('/\s+/u', ' ', $s));
    return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1) . '…' : $s;
};
$line = fn(string $t = '') => print($t . "\n");
$head = function (string $t) use ($line) { $line(); $line(str_repeat('=', 78)); $line(' ' . $t); $line(str_repeat('=', 78)); };

// ---- load the window ----------------------------------------------------------------------
$users = [];
foreach ($q("SELECT id, name, role, status FROM users WHERE deleted_at IS NULL") as $u) {
    $users[(int) $u['id']] = $u;
}
$first = fn(int $id): string => explode(' ', trim($users[$id]['name'] ?? ('#' . $id)))[0] . ' #' . $id;

$msgs = $q("SELECT m.id, m.conversation_id AS cid, m.role, m.content, m.tokens_in, m.tokens_out, m.created_at, m.feedback, c.user_id, c.kind
            FROM buddy_messages m JOIN buddy_conversations c ON c.id = m.conversation_id
            WHERE m.created_at >= '{$fromTs}' ORDER BY m.id");
$nudges = $q("SELECT id, user_id, type, status, created_at, delivered_at, outcome, outcome_hours FROM buddy_nudges WHERE created_at >= '{$fromTs}' ORDER BY id");
$sessions = $q("SELECT user_id, clock_in FROM attendance_sessions WHERE clock_in >= '{$fromTs}'");
$settings = [];
foreach ($q("SELECT user_id, enabled, voice_enabled, display_name, extra_json FROM buddy_settings") as $s) {
    $settings[(int) $s['user_id']] = $s;
}
$facts = [];
foreach ($q("SELECT user_id, COUNT(*) n FROM buddy_agent_facts WHERE active = 1 GROUP BY user_id") as $f) {
    $facts[(int) $f['user_id']] = (int) $f['n'];
}
$tools = $q("SELECT tool_name, duration_ms, user_id FROM buddy_tool_calls WHERE created_at >= '{$fromTs}'");

$worked = [];   // user => [bday => true]
foreach ($sessions as $s) {
    $worked[(int) $s['user_id']][$bday($s['clock_in'])] = true;
}
$talk = [];     // agent-kind user messages: user => [bday => count]
$userMsgs = []; // user => [ts, ...]
$agentUser = [];
$byKind = ['agent' => ['user' => 0, 'model' => 0], 'admin' => ['user' => 0, 'model' => 0], 'maintenance' => ['user' => 0, 'model' => 0]];
foreach ($msgs as $m) {
    $k = $m['kind'];
    if (isset($byKind[$k][$m['role']])) $byKind[$k][$m['role']]++;
    if ($m['role'] === 'user' && $k === 'agent') {
        $u = (int) $m['user_id'];
        $b = $bday($m['created_at']);
        $talk[$u][$b] = ($talk[$u][$b] ?? 0) + 1;
        $userMsgs[$u][] = strtotime($m['created_at']);
        $agentUser[] = $m;
    }
}

// ---- 1. reach and habit ---------------------------------------------------------------------
$head("1. REACH AND HABIT (last {$days} shift days: {$fromB} to {$nowB})");
$sellers = array_filter(array_keys($worked), fn($id) => isset($users[$id]) && $users[$id]['role'] !== 'admin');
$talkers = array_keys($talk);
$talkers7 = array_filter($talkers, fn($u) => max(array_keys($talk[$u])) >= date('Y-m-d', strtotime($nowB) - 6 * 86400));
$worked7 = array_filter($sellers, fn($u) => max(array_keys($worked[$u])) >= date('Y-m-d', strtotime($nowB) - 6 * 86400));
$line(sprintf('Staff who worked at least one shift:           %d', count($sellers)));
$line(sprintf('...of them, talked to Aisha at least once:    %d  (%s)', count(array_intersect($sellers, $talkers)), $pct(count(array_intersect($sellers, $talkers)), count($sellers))));
$line(sprintf('Last 7 days: worked %d, talked to Aisha %d     (%s)', count($worked7), count(array_intersect($worked7, $talkers7)), $pct(count(array_intersect($worked7, $talkers7)), count($worked7))));
$workDays = 0; $talkOnWork = 0; $regulars = 0;
foreach ($sellers as $u) {
    $wd = array_keys($worked[$u]);
    $workDays += count($wd);
    $tw = count(array_intersect($wd, array_keys($talk[$u] ?? [])));
    $talkOnWork += $tw;
    if (count($wd) >= 3 && $tw / count($wd) >= 0.5) $regulars++;
}
$line(sprintf('Shifts worked (person-days):                  %d', $workDays));
$line(sprintf('...on which they talked to Aisha:             %d  (%s)  <- habit rate', $talkOnWork, $pct($talkOnWork, $workDays)));
$line(sprintf('"Regulars" (talk on half or more of their shifts, 3+ shifts): %d of %d', $regulars, count($sellers)));
$allUserMsgs = array_sum(array_map('array_sum', $talk));
$activeDays = array_sum(array_map('count', $talk));
$line(sprintf('Messages from staff to Aisha:                 %d  (%.1f per talking day)', $allUserMsgs, $activeDays ? $allUserMsgs / $activeDays : 0));

// weekly retention: talked in week w+1 given talked in week w
$wk = [];
foreach ($talk as $u => $ds) foreach ($ds as $d => $_) $wk[$u][$weekOf($d)] = true;
$ret = [];
for ($w = min(4, intdiv($days, 7) - 1); $w >= 1; $w--) {
    $base = array_filter(array_keys($wk), fn($u) => isset($wk[$u][$w]));
    $back = array_filter($base, fn($u) => isset($wk[$u][$w - 1]));
    $ret[] = sprintf('%d->%d: %s', count($base), count($back), $pct(count($back), count($base)));
}
$line('Week-to-week return (talked one week -> came back the next): ' . ($ret ? implode('   ', $ret) : 'n/a'));

$line();
$line('Per person (sellers who worked):');
$line(sprintf('  %-22s %-11s %6s %6s %7s %6s %5s %5s', 'Person', 'Role', 'Shifts', 'Talked', 'Habit', 'Msgs', 'Facts', 'Voice'));
$rows = [];
foreach ($sellers as $u) {
    $wd = count($worked[$u]);
    $td = count(array_intersect(array_keys($worked[$u]), array_keys($talk[$u] ?? [])));
    $rows[] = [$u, $wd, $td, array_sum($talk[$u] ?? [])];
}
usort($rows, fn($a, $b) => [$b[2], $b[3]] <=> [$a[2], $a[3]]);
foreach ($rows as [$u, $wd, $td, $n]) {
    $s = $settings[$u] ?? null;
    $line(sprintf('  %-22s %-11s %6d %6d %7s %6d %5d %5s', mb_substr($first($u), 0, 22), $users[$u]['role'], $wd, $td, $pct($td, $wd), $n, $facts[$u] ?? 0,
        $s === null ? '-' : ((int) $s['voice_enabled'] ? 'on' : 'off')));
}

// daily series
$line();
$line('Day by day (shift day: people who worked / talked to Aisha / staff messages):');
$series = [];
for ($i = $days - 1; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime($nowB) - $i * 86400);
    $w = 0; $t = 0; $n = 0;
    foreach ($sellers as $u) {
        if (isset($worked[$u][$d])) $w++;
        if (isset($talk[$u][$d])) { $t++; $n += $talk[$u][$d]; }
    }
    $series[] = sprintf('%s %s %2d/%2d/%3d', date('D', strtotime($d)), substr($d, 5), $w, $t, $n);
}
foreach (array_chunk($series, 3) as $c) $line('  ' . implode('    ', $c));

// ---- 2. conversations ----------------------------------------------------------------------
$head('2. CONVERSATIONS');
$sess = []; $turns = [];
foreach ($userMsgs as $u => $ts) {
    sort($ts);
    $n = 1;
    for ($i = 1; $i < count($ts); $i++) {
        if ($ts[$i] - $ts[$i - 1] > 1800) { $turns[] = $n; $n = 1; } else { $n++; }
    }
    $turns[] = $n;
}
$line(sprintf('Sittings (messages <30 min apart): %d; staff messages per sitting: median %s, 90th pct %s; one-message sittings: %s',
    count($turns), $med($turns) ?? '-', $p90($turns) ?? '-', $pct(count(array_filter($turns, fn($t) => $t === 1)), count($turns))));
$hours = array_fill(0, 24, 0);
foreach ($agentUser as $m) $hours[(int) date('G', strtotime($m['created_at']))]++;
$line('Staff messages by hour of day (IST):');
$order = array_merge(range($h, 23), range(0, $h - 1));
$maxH = max($hours) ?: 1;
foreach ($order as $hr) {
    if ($hours[$hr] === 0) continue;
    $line(sprintf('  %02d:00 %4d %s', $hr, $hours[$hr], str_repeat('#', (int) ceil(30 * $hours[$hr] / $maxH))));
}
$buckets = [
    'My numbers / sales / rank' => '/\b(sale|sales|booking|bookings|mco|profit|revenue|kitn[ae]|score|rank|today|month|number|numbers|commission|incentive|kitne)\b/i',
    'Goal' => '/\b(goal|target)\b/i',
    'Patterns / coaching / tips' => '/\b(pattern|conversion|best day|improve|tip|tips|advice|coach|how can i|better|kaise\s+(badh|improve))\b/i',
    'CRM how-to' => '/\b(how (do|to|can) i|where (is|do|can)|kaise|kaha|kahan|e-?ticket|acceptance|invoice|refund|void|voucher|email|reminder|call log|clock)\b/i',
    'Travel / fares / airlines' => '/\b(baggage|bag|fare|airline|layover|visa|cancel|change fee|seat|flight|airport|terminal|lounge|esta|passport|time ?zone)\b/i',
    'Greeting / small talk / feelings' => '/\b(hi|hii+|hello|hey|thanks|thank you|thx|good (night|morning|evening)|bored|tired|sleepy|how are you|kya haal|kaisi ho|joke|love|miss|sad|happy|bye|ok|okay)\b/i',
];
$bc = array_fill_keys(array_keys($buckets), 0);
$bc['Other'] = 0;
foreach ($agentUser as $m) {
    $hit = 'Other';
    foreach ($buckets as $name => $re) if (preg_match($re, $m['content'])) { $hit = $name; break; }
    $bc[$hit]++;
}
$line('What staff talk to her about (first matching topic per message):');
arsort($bc);
foreach ($bc as $k => $n) $line(sprintf('  %-34s %4d  %s', $k, $n, $pct($n, count($agentUser))));
$len = array_map(fn($m) => mb_strlen($m['content']), $agentUser);
$line(sprintf('Message length (characters): median %s, 90th pct %s', $med($len) ?? '-', $p90($len) ?? '-'));
$line(sprintf('Admin "Super" chat: %d messages from admins, %d replies.  Maintenance chat: %d / %d.',
    $byKind['admin']['user'], $byKind['admin']['model'], $byKind['maintenance']['user'], $byKind['maintenance']['model']));

// ---- 3. proactive -------------------------------------------------------------------------
$head('3. PROACTIVE AISHA (nudges she starts herself)');
$byType = [];
foreach ($nudges as $n) {
    $t = $n['type'];
    $byType[$t] ??= ['made' => 0, 'delivered' => 0, 'seen' => 0, 'replied' => 0, 'resolved' => 0, 'judged' => 0, 'hours' => []];
    $byType[$t]['made']++;
    if ($n['delivered_at']) {
        $byType[$t]['delivered']++;
        $u = (int) $n['user_id'];
        $d = strtotime($n['delivered_at']);
        foreach ($userMsgs[$u] ?? [] as $ts) if ($ts >= $d && $ts <= $d + 1800) { $byType[$t]['replied']++; break; }
    }
    if ($n['status'] === 'seen') $byType[$t]['seen']++;
    if ($n['outcome']) {
        $byType[$t]['judged']++;
        if ($n['outcome'] === 'resolved') { $byType[$t]['resolved']++; if ($n['outcome_hours'] !== null) $byType[$t]['hours'][] = (float) $n['outcome_hours']; }
    }
}
$line(sprintf('  %-20s %6s %9s %6s %13s %12s %10s', 'Type', 'Made', 'Delivered', 'Seen', 'Reply<30min', 'Resolved', 'Med hours'));
uasort($byType, fn($a, $b) => $b['made'] <=> $a['made']);
foreach ($byType as $t => $s) {
    $line(sprintf('  %-20s %6d %9s %6s %13s %12s %10s', $t, $s['made'], $pct($s['delivered'], $s['made']), $pct($s['seen'], $s['delivered']),
        $pct($s['replied'], $s['delivered']), $s['judged'] ? $pct($s['resolved'], $s['judged']) : '-', $med($s['hours']) ?? '-'));
}
$perUserDay = [];
foreach ($nudges as $n) if ($n['delivered_at']) {
    $k = $n['user_id'] . '|' . $bday($n['delivered_at']);
    $perUserDay[$k] = ($perUserDay[$k] ?? 0) + 1;
}
$line(sprintf('Nudges delivered per person per shift: median %s, max %s', $med(array_values($perUserDay)) ?? '-', $perUserDay ? max($perUserDay) : '-'));

// ---- 4. quality ---------------------------------------------------------------------------
$head('4. QUALITY');
$model = array_filter($msgs, fn($m) => $m['role'] === 'model' && $m['kind'] === 'agent');
$up = count(array_filter($model, fn($m) => (int) $m['feedback'] === 1));
$down = count(array_filter($model, fn($m) => (int) $m['feedback'] === -1));
$line(sprintf('Aisha messages to staff: %d. Thumbs: %d up, %d down (%s of messages rated)', count($model), $up, $down, $pct($up + $down, count($model))));
$fallback = array_filter($model, fn($m) => str_starts_with((string) $m['content'], "I can't reach my AI brain"));
$line(sprintf('"Can\'t reach my AI brain" fallback replies: %d (%s of replies to staff questions)', count($fallback), $pct(count($fallback), max(1, count($agentUser)))));
// reply time: user message -> next model message in the same conversation
$lat = [];
$byConv = [];
foreach ($msgs as $m) $byConv[$m['cid']][] = $m;
foreach ($byConv as $list) {
    for ($i = 0; $i < count($list) - 1; $i++) {
        if ($list[$i]['role'] === 'user' && $list[$i + 1]['role'] === 'model') {
            $dt = strtotime($list[$i + 1]['created_at']) - strtotime($list[$i]['created_at']);
            if ($dt >= 0 && $dt <= 180) $lat[$list[$i]['kind']][] = $dt;
        }
    }
}
foreach ($lat as $k => $v) {
    $line(sprintf('Reply time, %s chat (seconds, from the stored timestamps): median %s, 90th pct %s, over 10s: %s', $k, $med($v), $p90($v), $pct(count(array_filter($v, fn($x) => $x > 10)), count($v))));
}
$tin = array_sum(array_map(fn($m) => (int) $m['tokens_in'], $msgs));
$tout = array_sum(array_map(fn($m) => (int) $m['tokens_out'], $msgs));
$pin = (float) $env('BUDDY_PRICE_IN', '0.30');
$pout = (float) $env('BUDDY_PRICE_OUT', '2.50');
$line(sprintf('Tokens: %s in / %s out. Estimated AI cost: $%.2f (at $%.2f/$%.2f per million; BUDDY_PRICE_IN/OUT)', number_format($tin), number_format($tout), $tin / 1e6 * $pin + $tout / 1e6 * $pout, $pin, $pout));
$tc = [];
foreach ($tools as $t) $tc[$t['tool_name']][] = (int) $t['duration_ms'];
uasort($tc, fn($a, $b) => count($b) <=> count($a));
$line('Tool use (calls, median ms):');
foreach ($tc as $name => $v) $line(sprintf('  %-28s %5d  %6s', $name, count($v), $med($v)));
$cant = '/\b(i (don\'?t|do not) (have|know|track|see)|i can\'?t|i cannot|not able to|unable to|no access|outside what i|beyond what i|i\'?m not sure)\b/i';
$misses = [];
foreach ($byConv as $list) {
    for ($i = 1; $i < count($list); $i++) {
        if ($list[$i]['role'] === 'model' && $list[$i]['kind'] === 'agent' && $list[$i - 1]['role'] === 'user' && preg_match($cant, (string) $list[$i]['content'])) {
            $misses[] = [$list[$i - 1], $list[$i]];
        }
    }
}
$line(sprintf('Answers where she says she can\'t / doesn\'t know: %d (%s of staff questions)', count($misses), $pct(count($misses), max(1, count($agentUser)))));
$named = count(array_filter($sellers, fn($u) => !empty($settings[$u]['display_name'])));
$goals = count(array_filter($sellers, fn($u) => !empty($settings[$u]['extra_json']) && str_contains((string) $settings[$u]['extra_json'], '"goal"')));
$voiceOff = count(array_filter($sellers, fn($u) => isset($settings[$u]) && !(int) $settings[$u]['voice_enabled']));
$line(sprintf('Personal touch: knows the name of %d, a goal for %d, 1+ facts for %d (of %d sellers). Voice switched off by %d.',
    $named, $goals, count(array_filter($sellers, fn($u) => ($facts[$u] ?? 0) > 0)), count($sellers), $voiceOff));

// ---- 5. v2 scorecard ------------------------------------------------------------------------
$head('5. AISHA v2 SCORECARD');
$hasV2 = $q("SHOW COLUMNS FROM buddy_messages LIKE 'kind'") !== [];
if (!$hasV2) {
    $line('The aisha_v2 migration has not run yet (php hostinger_migrate.php) — nothing to show.');
} else {
    // Spend, from the ledger BuddyBudget writes on every call.
    $cap = (float) $env('BUDDY_DAILY_BUDGET_USD', '2.50');
    $spend = $q("SELECT bday, model, calls, tokens_in, tokens_out, cost_usd FROM buddy_ai_usage WHERE bday >= '{$fromB}' ORDER BY bday, model");
    $byDay = [];
    foreach ($spend as $r) {
        if ($r['model'] === 'tts') {
            $byDay[$r['bday']]['tts'] = (int) $r['tokens_out'];
            continue;
        }
        $byDay[$r['bday']]['usd'] = ($byDay[$r['bday']]['usd'] ?? 0) + (float) $r['cost_usd'];
        $byDay[$r['bday']]['calls'] = ($byDay[$r['bday']]['calls'] ?? 0) + (int) $r['calls'];
    }
    $total = array_sum(array_map(fn($d) => $d['usd'] ?? 0, $byDay));
    $line(sprintf('AI spend: $%.2f over %d shift days with use (cap $%.2f a day, BUDDY_DAILY_BUDGET_USD). Highest day: $%.2f.',
        $total, count($byDay), $cap, $byDay ? max(array_map(fn($d) => $d['usd'] ?? 0, $byDay)) : 0));
    foreach (array_slice($byDay, -7, null, true) as $d => $v) {
        $line(sprintf('  %s  %4d calls  $%.3f%s', $d, $v['calls'] ?? 0, $v['usd'] ?? 0, isset($v['tts']) ? sprintf('  · %s characters spoken', number_format($v['tts'])) : ''));
    }

    // Measured reply time and failed answers (stored per message since v2).
    $mm = $q("SELECT m.kind, m.latency_ms, m.model, m.ai FROM buddy_messages m JOIN buddy_conversations c ON c.id = m.conversation_id
              WHERE m.role = 'model' AND c.kind = 'agent' AND m.kind IN ('chat', 'fallback') AND m.created_at >= '{$fromTs}'");
    $ms = array_map(fn($r) => (int) $r['latency_ms'] / 1000, array_filter($mm, fn($r) => $r['latency_ms'] !== null && $r['kind'] === 'chat'));
    $fb = count(array_filter($mm, fn($r) => $r['kind'] === 'fallback'));
    $line(sprintf('Answers: %d. Measured reply time: median %ss, 90th pct %ss, over 10s %s. Failed answers (basic mode, busy, error): %d (%s).',
        count($mm), $med($ms) !== null ? round($med($ms), 1) : '-', $p90($ms) !== null ? round($p90($ms), 1) : '-',
        $pct(count(array_filter($ms, fn($x) => $x > 10)), count($ms)), $fb, $pct($fb, count($mm))));
    $lanes = [];
    foreach ($mm as $r) if ($r['model']) $lanes[$r['model']] = ($lanes[$r['model']] ?? 0) + 1;
    if ($lanes) $line('  by brain: ' . implode(', ', array_map(fn($k, $v) => "{$k} {$v}", array_keys($lanes), $lanes)));

    // What people click.
    $ev = $q("SELECT event, COUNT(*) n, COUNT(DISTINCT user_id) people FROM buddy_events WHERE created_at >= '{$fromTs}' GROUP BY event ORDER BY n DESC");
    if ($ev === []) {
        $line('Clicks: none recorded yet.');
    } else {
        $line('Clicks and settings (times · people):');
        foreach (array_chunk($ev, 3) as $c) {
            $line('  ' . implode('   ', array_map(fn($e) => sprintf('%-13s %4d · %-3d', $e['event'], $e['n'], $e['people']), $c)));
        }
        $why = $q("SELECT SUBSTRING_INDEX(detail, ' ', -2) why, COUNT(*) n FROM buddy_events WHERE event = 'thumb_down' AND detail LIKE '% %' AND created_at >= '{$fromTs}' GROUP BY why ORDER BY n DESC LIMIT 5");
        if ($why) $line('  thumbs-down reasons: ' . implode(', ', array_map(fn($w) => $w['why'] . ' ' . $w['n'], $why)));
    }

    // Nudges: acted on, already done before she said it, reminders.
    $nv = $q("SELECT type, status, delivered_at, acted_at FROM buddy_nudges WHERE created_at >= '{$fromTs}'");
    $said = array_filter($nv, fn($n) => in_array($n['status'], ['delivered', 'seen', 'done'], true) && $n['type'] !== 'reminder');
    $acted = array_filter($said, fn($n) => $n['acted_at'] !== null);
    $dropped = array_filter($nv, fn($n) => $n['status'] === 'expired');
    $rem = array_filter($nv, fn($n) => $n['type'] === 'reminder');
    $line(sprintf('Nudges she raised: %d; button pressed or marked Done: %d (%s). Dropped because already done/stale: %d.',
        count($said), count($acted), $pct(count($acted), count($said)), count($dropped)));
    $line(sprintf('Personal reminders set: %d (delivered %d, snoozed or pending %d).', count($rem),
        count(array_filter($rem, fn($n) => $n['delivered_at'] !== null)), count(array_filter($rem, fn($n) => $n['status'] === 'pending'))));

    // Who starts the conversation: a sitting the agent began on their own vs.
    // one that followed her greeting or a nudge within 10 minutes.
    $seq = $q("SELECT m.conversation_id cid, m.role, m.kind, m.created_at FROM buddy_messages m JOIN buddy_conversations c ON c.id = m.conversation_id
               WHERE c.kind = 'agent' AND m.created_at >= '{$fromTs}' ORDER BY m.conversation_id, m.id");
    $self = 0; $prompted = 0; $lastUser = []; $lastHers = [];
    foreach ($seq as $r) {
        $t = strtotime($r['created_at']);
        if ($r['role'] === 'model' && in_array($r['kind'], ['greeting', 'nudge'], true)) {
            $lastHers[$r['cid']] = $t;
            continue;
        }
        if ($r['role'] !== 'user') continue;
        if (!isset($lastUser[$r['cid']]) || $t - $lastUser[$r['cid']] > 1800) {
            (isset($lastHers[$r['cid']]) && $t - $lastHers[$r['cid']] <= 600) ? $prompted++ : $self++;
        }
        $lastUser[$r['cid']] = $t;
    }
    $line(sprintf('Sittings the agent started on their own: %d of %d (%s) — the habit signal; the rest followed her greeting or a nudge.',
        $self, $self + $prompted, $pct($self, $self + $prompted)));
}

// ---- 6. samples ---------------------------------------------------------------------------
$head('6. SAMPLES (masked)');
$line('Most recent 40 things staff said to her:');
foreach (array_slice(array_reverse($agentUser), 0, 40) as $m) {
    $line(sprintf('  [%s %s] %s', date('d M H:i', strtotime($m['created_at'])), $first((int) $m['user_id']), $scrub((string) $m['content'], 140)));
}
$line();
$line('Thumbs-down answers (question -> her answer):');
$shown = 0;
foreach ($byConv as $list) {
    for ($i = 0; $i < count($list); $i++) {
        if ($list[$i]['role'] === 'model' && (int) $list[$i]['feedback'] === -1 && $shown < 15) {
            $prev = $i > 0 && $list[$i - 1]['role'] === 'user' ? $scrub((string) $list[$i - 1]['content'], 120) : '(she spoke first)';
            $line('  Q: ' . $prev);
            $line('  A: ' . $scrub((string) $list[$i]['content'], 220));
            $shown++;
        }
    }
}
if (!$shown) $line('  (none)');
$line();
$line('"Can\'t help" answers (question -> her answer), up to 15:');
foreach (array_slice($misses, -15) as [$qm, $am]) {
    $line('  Q: ' . $scrub((string) $qm['content'], 120));
    $line('  A: ' . $scrub((string) $am['content'], 200));
}
if (!$misses) $line('  (none)');
$line();
$line('End of report. Nothing was changed.');
