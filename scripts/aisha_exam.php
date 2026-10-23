<?php
/**
 * The Aisha exam: ~45 realistic night-shift questions, scored automatically.
 *
 *   php scripts/aisha_exam.php                         # all cases, as the default exam agent
 *   php scripts/aisha_exam.php --only=N1,T1,W1         # some cases
 *   php scripts/aisha_exam.php --cat=travel            # one category
 *   php scripts/aisha_exam.php --user=priya.sharma@trio-demo.test
 *
 * Built 23 Oct 2026 as the benchmark for the Aisha upgrade: run it before and
 * after every change and compare. Each case starts from a clean conversation
 * (history, tool log, facts and goal of the exam agent are wiped), runs the REAL
 * BuddyService::agentChat() against the real Gemini key, then scores the reply:
 *   tools_any  one of these tools was called         truth   reply contains the true number
 *   regex_all  every pattern matches                 regex_none  no pattern matches
 *   max_words  short enough                          hinglish    mirrors Roman Hindi
 *   judge      a strict grader model checks the rubric (gemini-2.5-flash, JSON verdict)
 * Truth values come from Aisha's own tools, so they use the CRM's definitions.
 *
 * Paced to stay under the AI key's request limit (Vertex express mode allows
 * gemini-2.5-flash only 10 requests/minute; one Aisha answer is 1 request plus
 * 1 per tool call, plus 1 for the grader). A case that still hits the limit
 * (her "can't reach my AI brain" fallback) waits and is retried, and the number
 * of those retries is reported separately: it measures the key, not her brain.
 * --rpm=8 changes the pace.
 *
 * Writes storage/logs/aisha_exam_<time>.json with every reply, for side-by-side
 * comparison. Because it WIPES the exam agent's Aisha history, it refuses to run
 * against any database whose name doesn't look like a local/test one.
 * Cost: ~50 chat turns + ~35 grader calls, a few US cents.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../vendor/autoload.php';

use App\Services\Buddy\AgentTools;
use App\Services\Buddy\BuddyGeminiClient;
use App\Services\Buddy\BuddyService;
use App\Services\Buddy\BuddyToolRegistry;
use Illuminate\Database\Capsule\Manager as DB;

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();
date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'Asia/Kolkata');
$env = fn(string $k, string $d = ''): string => (string) ($_ENV[$k] ?? (getenv($k) !== false ? getenv($k) : $d));

$opt = ['user' => 'aman.gill@trio-demo.test', 'only' => '', 'cat' => '', 'allow-db' => '', 'rpm' => '8'];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z-]+)=(.*)$/', $a, $m) && array_key_exists($m[1], $opt)) {
        $opt[$m[1]] = $m[2];
    }
}
$dbName = $env('DB_DATABASE');
if (!preg_match('/(smoke|local|test|dev)/i', $dbName) && $opt['allow-db'] !== $dbName) {
    fwrite(STDERR, "Refusing: database '{$dbName}' doesn't look local. This wipes the exam agent's Aisha history.\n"
        . "If you really mean it: --allow-db={$dbName}\n");
    exit(1);
}
if ($env('VERTEX_API_KEY') === '' || $env('VERTEX_API_KEY') === 'smoke-disabled') {
    fwrite(STDERR, "No real VERTEX_API_KEY — the exam needs the real brain.\n");
    exit(1);
}

$capsule = new DB;
$capsule->addConnection([
    'driver' => 'mysql', 'host' => explode(';', $env('DB_HOST', '127.0.0.1'))[0], 'port' => (int) $env('DB_PORT', '3306'),
    'database' => $dbName, 'username' => $env('DB_USERNAME'), 'password' => $env('DB_PASSWORD'),
    'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci',
]);
$capsule->setAsGlobal();
$capsule->bootEloquent();

$user = DB::table('users')->where('email', $opt['user'])->first();
if (!$user) {
    fwrite(STDERR, "No user {$opt['user']}.\n");
    exit(1);
}
$uid = (int) $user->id;
$first = explode(' ', $user->name)[0];
$cases = json_decode((string) file_get_contents(__DIR__ . '/aisha_exam_cases.json'), true)['cases'];
if ($opt['only'] !== '') {
    $want = array_map('trim', explode(',', $opt['only']));
    $cases = array_values(array_filter($cases, fn($c) => in_array($c['id'], $want, true)));
}
if ($opt['cat'] !== '') {
    $cases = array_values(array_filter($cases, fn($c) => $c['cat'] === $opt['cat']));
}

// ---- reset: a clean, known agent before every case ------------------------------------------
$reset = function () use ($uid, $first) {
    $convs = DB::table('buddy_conversations')->where('user_id', $uid)->where('kind', 'agent')->pluck('id')->all();
    if ($convs) {
        DB::table('buddy_messages')->whereIn('conversation_id', $convs)->delete();
    }
    DB::table('buddy_tool_calls')->where('user_id', $uid)->delete();
    DB::table('buddy_agent_facts')->where('user_id', $uid)->delete();
    if (DB::table('buddy_settings')->where('user_id', $uid)->exists()) {
        DB::table('buddy_settings')->where('user_id', $uid)->update(['display_name' => $first, 'extra_json' => null]);
    } else {
        DB::table('buddy_settings')->insert(['user_id' => $uid, 'enabled' => 1, 'voice_enabled' => 1, 'display_name' => $first]);
    }
};

// ---- truth from Aisha's own tools ------------------------------------------------------------
$reset();
$reg = AgentTools::registry($uid, (string) $user->role);
$month = $reg->execute('get_my_month_summary', []);
$today = $reg->execute('get_my_today', []);
$pipe = $reg->execute('get_my_pipeline', []);
$week = $reg->execute('get_my_week_recap', []);
$pat = $reg->execute('get_my_patterns', []);
$eti = $pipe['transactions_awaiting_eticket'] ?? [];
$money = fn($v) => (string) round((float) $v);
$truth = [
    'month.sales' => (string) ($month['this_month']['sales'] ?? ''),
    'month.net_mco' => $money($month['this_month']['net_mco'] ?? 0),
    'lastmonth.sales' => (string) ($month['last_month']['sales'] ?? ''),
    'today.sales' => (string) ($today['sales'] ?? ''),
    'pipeline.eticket_count' => (string) count($eti),
    'pipeline.first_booking' => (string) ($eti[0]['booking'] ?? ''),
    'pipeline.second_booking' => (string) ($eti[1]['booking'] ?? ''),
    'week.sales' => (string) ($week['last_7']['sales'] ?? ''),
    'patterns.best_day' => (string) ($pat['weekdays']['best_day'] ?? ''),
];
$reset();
$tz = function (string $zone, string $fmt = 'g:i A') { $d = new DateTime('now', new DateTimeZone($zone)); return $d->format($fmt); };
$e9 = new DateTime('today 21:00', new DateTimeZone('America/New_York'));
$e9->setTimezone(new DateTimeZone('Asia/Kolkata'));
$fill = ['{NY_TIME}' => $tz('America/New_York', 'g:i A (D)'), '{LA_TIME}' => $tz('America/Los_Angeles', 'g:i A (D)'), '{EASTERN_9PM_IST}' => $e9->format('g:i A') . ' (next morning)'];

$hinglish = '/\b(yaar|yar|chalo|accha|achha|haan|bas|kya|hai|hain|nahi|nahin|thoda|aaj|kal|ji|bilkul|zaroor|shabash|arre|arey|tum|aap|apna|apni|mast|bohot|bahut|kar|karo|raha|rahi|ho|toh|na|jaldi|chai)\b/i';

$judge = function (string $ask, string $reply, string $rubric) use ($uid): array {
    $client = new BuddyGeminiClient(null, 'gemini-2.5-flash');
    $sys = "You grade replies from Aisha, an AI work buddy for travel sales agents in India who sell flights by phone to US customers on night shifts. "
        . "Be strict and literal: apply the rubric exactly. Respond with ONLY a JSON object: {\"pass\": true|false, \"reason\": \"<one short sentence>\"}.";
    $prompt = "AGENT ASKED:\n{$ask}\n\nAISHA REPLIED:\n{$reply}\n\nRUBRIC:\n{$rubric}";
    $r = $client->chat($sys, [['role' => 'user', 'parts' => [['text' => $prompt]]]], new BuddyToolRegistry($uid));
    $txt = (string) ($r['text'] ?? '');
    if (preg_match('/\{.*\}/s', $txt, $m) && ($j = json_decode($m[0], true)) && isset($j['pass'])) {
        return [(bool) $j['pass'], (string) ($j['reason'] ?? '')];
    }
    return [false, 'grader gave no verdict: ' . mb_substr($txt, 0, 120)];
};

// ---- pacing: a sliding one-minute window of AI requests ----------------------------------------
$window = [];
$rpm = max(1, (int) $opt['rpm']);
$room = function (int $need) use (&$window, $rpm) {
    for (;;) {
        $now = microtime(true);
        $window = array_values(array_filter($window, fn($t) => $t > $now - 60));
        if (count($window) + $need <= $rpm || !$window) return;
        usleep((int) (($window[0] + 60 - $now) * 1e6) + 200000);
    }
};
$spent = function (int $n) use (&$window) { for ($i = 0; $i < $n; $i++) $window[] = microtime(true); };
$limitRetries = 0;

// ---- run ---------------------------------------------------------------------------------
$svc = new BuddyService();
$out = [];
$byCat = [];
$lat = [];
printf("Aisha exam: %d cases as %s (#%d). Truth: month %s sales, net MCO %s, %s e-tickets waiting, week %s, best day %s.\n\n",
    count($cases), $user->name, $uid, $truth['month.sales'], $truth['month.net_mco'], $truth['pipeline.eticket_count'], $truth['week.sales'], $truth['patterns.best_day'] ?: '-');
foreach ($cases as $c) {
    $reset();
    $marker = (int) (DB::table('buddy_tool_calls')->max('id') ?? 0);
    $steps = $c['steps'] ?? [$c['ask']];
    $reply = '';
    $ms = 0;
    $ai = true;
    for ($attempt = 0; $attempt < 3; $attempt++) {
        if ($attempt > 0) {
            $reset();
            $marker = (int) (DB::table('buddy_tool_calls')->max('id') ?? 0);
            $limitRetries++;
            sleep(45);                      // the key said "too many"; let the minute roll over
        }
        $ai = true;
        foreach ($steps as $s) {
            $room(3);
            $before = (int) (DB::table('buddy_tool_calls')->max('id') ?? 0);
            $t0 = microtime(true);
            $r = $svc->agentChat($uid, (string) $user->role, $s);
            $ms = (int) round((microtime(true) - $t0) * 1000);   // the last step is the one being graded
            $spent(1 + (int) DB::table('buddy_tool_calls')->where('user_id', $uid)->where('id', '>', $before)->count());
            $reply = (string) ($r['reply'] ?? ($r['error'] ?? ''));
            $ai = $ai && !empty($r['ai']);
        }
        if ($ai) break;
    }
    $lat[] = $ms;
    $tools = DB::table('buddy_tool_calls')->where('user_id', $uid)->where('id', '>', $marker)->pluck('tool_name')->all();
    $words = str_word_count(preg_replace('/[^\p{L}\p{N}\s\']/u', ' ', $reply));
    $fails = [];
    if (!empty($c['tools_any']) && !array_intersect($c['tools_any'], $tools)) $fails[] = 'no tool ' . implode('/', $c['tools_any']);
    foreach ($c['truth'] ?? [] as $k) {
        $v = $truth[$k] ?? '';
        $hay = str_replace(',', '', $reply);
        if ($v === '' || !preg_match('/(?<![\d.])' . preg_quote($v, '/') . '(?![\d])/iu', $hay)) $fails[] = "missing {$k}={$v}";
    }
    foreach ($c['regex_all'] ?? [] as $re) if (!preg_match('/' . $re . '/iu', $reply)) $fails[] = "no /{$re}/";
    foreach ($c['regex_none'] ?? [] as $re) if (preg_match('/' . $re . '/iu', $reply)) $fails[] = "has /{$re}/";
    if (!empty($c['max_words']) && $words > $c['max_words']) $fails[] = "too long ({$words} > {$c['max_words']} words)";
    if (!empty($c['hinglish']) && !preg_match($hinglish, $reply)) $fails[] = 'not Hinglish';
    $why = '';
    if (!empty($c['judge'])) {
        $room(1);
        [$ok, $why] = $judge(end($steps), $reply, strtr($c['judge'], $fill));
        $spent(1);
        if (!$ok) $fails[] = 'grader: ' . $why;
    }
    if (!$ai) $fails[] = 'not an AI reply (fallback/limit)';
    $pass = !$fails;
    $byCat[$c['cat']][] = $pass;
    $out[] = ['id' => $c['id'], 'cat' => $c['cat'], 'steps' => $steps, 'reply' => $reply, 'ms' => $ms, 'words' => $words, 'tools' => $tools, 'pass' => $pass, 'fails' => $fails, 'grader' => $why];
    printf("%-4s %-5s %6.1fs %3dw  %s\n      > %s\n      < %s\n", $c['id'], $pass ? 'PASS' : 'FAIL', $ms / 1000, $words, $pass ? '' : implode(' | ', $fails),
        mb_substr(end($steps), 0, 100), mb_substr(preg_replace('/\s+/u', ' ', $reply), 0, 260));
}
$reset();

// ---- score -------------------------------------------------------------------------------
sort($lat);
$p = fn($q) => $lat ? $lat[(int) floor($q * (count($lat) - 1))] / 1000 : 0;
$total = count($out);
$passed = count(array_filter($out, fn($o) => $o['pass']));
echo "\n" . str_repeat('=', 60) . "\nSCORE BY AREA\n";
foreach ($byCat as $cat => $rs) {
    printf("  %-11s %2d/%-2d  %3d%%\n", $cat, count(array_filter($rs)), count($rs), round(100 * count(array_filter($rs)) / count($rs)));
}
printf("  %-11s %2d/%-2d  %3d%%\n", 'OVERALL', $passed, $total, $total ? round(100 * $passed / $total) : 0);
printf("Reply time: median %.1fs, 90th pct %.1fs, slowest %.1fs\n", $p(0.5), $p(0.9), $lat ? end($lat) / 1000 : 0);
printf("Cases that hit the AI key's request limit and had to be retried: %d (paced at %d requests/min)\n", $limitRetries, $rpm);
$file = dirname(__DIR__) . '/storage/logs/aisha_exam_' . date('Ymd_His') . '.json';
@mkdir(dirname($file), 0755, true);
file_put_contents($file, json_encode(['at' => date('c'), 'user' => $user->email, 'truth' => $truth, 'model' => $env('VERTEX_MODEL'), 'results' => $out], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "Saved: {$file}\n";
