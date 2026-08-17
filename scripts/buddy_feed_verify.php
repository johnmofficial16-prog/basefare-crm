<?php
/**
 * P5 feed — offline verification (no Gemini, no MySQL, no network).
 *
 *   php scripts/buddy_feed_verify.php
 *
 * Drives BuddyService::agentFeed() against an in-memory SQLite database and
 * asserts the P5 contract:
 *
 *   F1. A drain phrases pending nudges as Aisha model messages and marks
 *       exactly those rows delivered.
 *   F2. Priority: praise first, then urgency, dry_spell last.
 *   F3. Batch cap: max 3 per drain; the rest stay pending for the next poll.
 *   F4. Idempotent: an empty drain returns messages:[] and changes nothing.
 *   F5. Quota-exempt: a drain stores ZERO role='user' rows (the quota counter).
 *   F6. Templates: every nudge type phrases non-empty, personalized, no raw
 *       placeholders; unknown types get the generic line instead of crashing.
 *   F7. Greeting stamp: once-per-business-day survives feed deliveries (the
 *       old "any model message today" check would not).
 *
 * The Gemini praise call is structurally absent here (no VERTEX_API_KEY), so
 * the template fallback path is what runs — the same path production takes
 * whenever the AI layer is down.
 */

require __DIR__ . '/../vendor/autoload.php';

use Illuminate\Database\Capsule\Manager as Capsule;
use App\Services\Buddy\BuddyService;

unset($_ENV['VERTEX_API_KEY']);
putenv('VERTEX_API_KEY');

$capsule = new Capsule;
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$capsule->setAsGlobal();
$capsule->bootEloquent();

// Minimal schema — only what the feed path touches.
Capsule::connection()->getPdo()->exec(<<<SQL
CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT);
CREATE TABLE buddy_conversations (
  id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, kind TEXT,
  title TEXT, created_at TEXT, last_message_at TEXT);
CREATE TABLE buddy_messages (
  id INTEGER PRIMARY KEY AUTOINCREMENT, conversation_id INTEGER, role TEXT,
  content TEXT, tokens_in INTEGER, tokens_out INTEGER, created_at TEXT);
CREATE TABLE buddy_nudges (
  id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, type TEXT,
  ref_table TEXT, ref_id INTEGER, payload_json TEXT,
  status TEXT DEFAULT 'pending', dedupe_key TEXT UNIQUE,
  created_at TEXT, delivered_at TEXT);
CREATE TABLE buddy_settings (
  user_id INTEGER PRIMARY KEY, enabled INTEGER DEFAULT 1,
  voice_enabled INTEGER DEFAULT 1, display_name TEXT,
  onboarded_at TEXT, extra_json TEXT);
CREATE TABLE buddy_agent_facts (
  id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, fact TEXT,
  source TEXT, active INTEGER DEFAULT 1, created_at TEXT, updated_at TEXT);
SQL);

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ✓ {$label}\n"; }
    else     { $fail++; echo "  ✗ {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; }
}

$now = date('Y-m-d H:i:s');
Capsule::table('users')->insert(['id' => 7, 'name' => 'Sam Kumar']);

$seed = [
    ['dry_spell',      ['days_since_last_sale' => 4, 'last_sale_at' => '2026-08-13 20:00:00'], 'ds:7'],
    ['sale_praise_t2', ['ref' => 'G7BGL3', 'amount' => 1240.5, 'currency' => 'USD', 'tier' => 't2'], 'sp:1'],
    ['eticket_lag',    ['ref' => 'AHYX2I', 'waiting_hours' => 6], 'el:2'],
    ['acceptance_lag', ['acceptance' => '#88', 'waiting_hours' => 9], 'al:88'],
    ['departure_24h',  ['ref' => 'K9PMQ2', 'departs' => '2026-08-18 09:40'], 'dp:3'],
];
foreach ($seed as [$type, $payload, $key]) {
    Capsule::table('buddy_nudges')->insert([
        'user_id' => 7, 'type' => $type,
        'payload_json' => json_encode($payload),
        'status' => 'pending', 'dedupe_key' => $key, 'created_at' => $now,
    ]);
}

$svc = new BuddyService();

echo "F1–F3. First drain (5 pending, cap 3, priority order)\n";
$r1 = $svc->agentFeed(7, 'agent');
check('returns 3 messages', count($r1['messages']) === 3, count($r1['messages']) . ' returned');
check('pending_left is 2', $r1['pending_left'] === 2, 'got ' . $r1['pending_left']);
$texts = array_column($r1['messages'], 'content');
check('praise first',   isset($texts[0]) && str_contains($texts[0], 'G7BGL3'));
check('departure next', isset($texts[1]) && str_contains($texts[1], 'K9PMQ2'));
check('eticket third',  isset($texts[2]) && str_contains($texts[2], 'AHYX2I'));
check('praise carries amount', isset($texts[0]) && str_contains($texts[0], '1,240.50'));
check('personalized with first name', isset($texts[0]) && str_contains($texts[0], 'Sam') && !str_contains($texts[0], 'Kumar'));

$delivered = Capsule::table('buddy_nudges')->where('status', 'delivered')->whereNotNull('delivered_at')->count();
check('exactly 3 nudges marked delivered', $delivered === 3, "got {$delivered}");

$conv = Capsule::table('buddy_conversations')->where('user_id', 7)->where('kind', 'agent')->first();
check('agent conversation opened', $conv !== null);
$stored = Capsule::table('buddy_messages')->where('conversation_id', $conv->id)->where('role', 'model')->count();
check('3 model messages stored (history stays coherent)', $stored === 3, "got {$stored}");

echo "F5. Quota exemption\n";
$userRows = Capsule::table('buddy_messages')->where('role', 'user')->count();
check("zero role='user' rows (agent quota untouched)", $userRows === 0, "got {$userRows}");

echo "F1/F3. Second drain (remaining 2)\n";
$r2 = $svc->agentFeed(7, 'agent');
check('returns the remaining 2', count($r2['messages']) === 2, count($r2['messages']) . ' returned');
check('pending_left is 0', $r2['pending_left'] === 0);
$t2 = array_column($r2['messages'], 'content');
check('acceptance before dry_spell', isset($t2[0], $t2[1]) && str_contains($t2[0], '#88') && str_contains($t2[1], '4 days'));

echo "F4. Third drain (empty) is a no-op\n";
$r3 = $svc->agentFeed(7, 'agent');
check('messages empty', $r3['messages'] === []);
$msgCount = Capsule::table('buddy_messages')->count();
check('no new messages written', $msgCount === 5, "got {$msgCount}");

echo "F6. Template coverage\n";
foreach (['sale_praise_t1', 'sale_praise_t2', 'eticket_lag', 'acceptance_lag', 'departure_24h', 'dry_spell', 'some_future_type'] as $type) {
    $out = BuddyService::phraseNudge($type, ['ref' => 'X1', 'amount' => 10, 'currency' => 'USD',
        'waiting_hours' => 2, 'days_since_last_sale' => 3, 'departs' => 'tomorrow'], 'Sam', 1);
    check("{$type} phrases cleanly", $out !== '' && !str_contains($out, '{'), $out);
}
$nameless = BuddyService::phraseNudge('eticket_lag', ['ref' => 'X1', 'waiting_hours' => 2], null, 0);
check('nameless fallback works', str_contains($nameless, 'hey you'));

echo "F6b. display_name override beats CRM name\n";
Capsule::table('buddy_settings')->updateOrInsert(['user_id' => 7], ['display_name' => 'Sammy']);
Capsule::table('buddy_nudges')->insert([
    'user_id' => 7, 'type' => 'eticket_lag',
    'payload_json' => json_encode(['ref' => 'ZZTOP1', 'waiting_hours' => 5]),
    'status' => 'pending', 'dedupe_key' => 'el:99', 'created_at' => $now,
]);
$r4 = $svc->agentFeed(7, 'agent');
check('uses preferred name', isset($r4['messages'][0]) && str_contains($r4['messages'][0]['content'], 'Sammy'));

echo "F7. Greeting stamp survives feed deliveries\n";
$ref  = new ReflectionClass(BuddyService::class);
$get  = $ref->getMethod('greetedStamp');
$set  = $ref->getMethod('setGreetedStamp');
check('no stamp yet', $get->invoke($svc, 7) === null);
$set->invoke($svc, 7, '2026-08-17');
check('stamp reads back', $get->invoke($svc, 7) === '2026-08-17');
$extra = json_decode((string) Capsule::table('buddy_settings')->where('user_id', 7)->value('extra_json'), true);
check('display_name preserved alongside stamp',
    Capsule::table('buddy_settings')->where('user_id', 7)->value('display_name') === 'Sammy'
    && ($extra['last_greeted_bday'] ?? null) === '2026-08-17');

echo "\n" . ($fail === 0 ? "ALL {$pass} CHECKS PASSED ✓" : "{$fail} FAILED / {$pass} passed ✗") . "\n";
exit($fail === 0 ? 0 : 1);
