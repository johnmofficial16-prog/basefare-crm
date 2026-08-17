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
 *   F8. Money reads as dollars ("$1,240.50") — right on screen and out loud.
 *   F9. Stale praise is framed as catching up, not as just-happened.
 *  F10. Personal bests are celebrated, and never claimed when absent.
 *  F11. Lag escalation changes tone on rounds 2+ without guilt-tripping.
 *  F13. Admin messages are relayed VERBATIM and outrank everything else. The
 *       Super Buddy writes type='admin_message' with the admin's text in the
 *       payload; a generic fallback here silently destroys a human-authored,
 *       human-confirmed message.
 *  F14. The greeting claim is atomic — concurrent tabs greet exactly once.
 *  F15. History is scrubbed on the way to Gemini. Relaying admin text verbatim
 *       to the agent must not become a PII path to Google.
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
check('praise carries amount as dollars', isset($texts[0]) && str_contains($texts[0], '$1,240.50'));
check('no raw "USD" prefix (reads badly aloud)', isset($texts[0]) && !str_contains($texts[0], 'USD 1,240'));
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

echo "F7. Greeting claim survives feed deliveries (the original P5 bug)\n";
$ref   = new ReflectionClass(BuddyService::class);
$claim = $ref->getMethod('claimGreeting');
check('greeting claimable on a fresh business day', $claim->invoke($svc, 7, '2026-08-17') === true);
// Deliver more nudges — these write model messages into the same conversation.
// The ORIGINAL bug was checking "any model message since day start", which
// these would satisfy, silently cancelling the next login greeting.
Capsule::table('buddy_nudges')->insert([
    'user_id' => 7, 'type' => 'departure_24h',
    'payload_json' => json_encode(['ref' => 'FEED01', 'departs' => 'tomorrow']),
    'status' => 'pending', 'dedupe_key' => 'dp:feed01', 'created_at' => $now,
]);
$svc->agentFeed(7, 'agent');
check('feed wrote model messages', Capsule::table('buddy_messages')->where('role', 'model')->count() > 5);
check('same day still counts as greeted', $claim->invoke($svc, 7, '2026-08-17') === false);
$extra = json_decode((string) Capsule::table('buddy_settings')->where('user_id', 7)->value('extra_json'), true);
check('display_name preserved alongside stamp',
    Capsule::table('buddy_settings')->where('user_id', 7)->value('display_name') === 'Sammy'
    && ($extra['last_greeted_bday'] ?? null) === '2026-08-17');

echo "F8. Money formatting\n";
$usd = BuddyService::phraseNudge('sale_praise_t1', ['ref' => 'A1', 'amount' => 900, 'currency' => 'USD'], 'Sam', 0);
check('USD renders as $900.00', str_contains($usd, '$900.00'));
$blank = BuddyService::phraseNudge('sale_praise_t1', ['ref' => 'A1', 'amount' => 900], 'Sam', 0);
check('missing currency defaults to dollars', str_contains($blank, '$900.00'));
$eur = BuddyService::phraseNudge('sale_praise_t1', ['ref' => 'A1', 'amount' => 900, 'currency' => 'EUR'], 'Sam', 0);
check('non-USD keeps its code', str_contains($eur, 'EUR 900.00'));

echo "F9. Stale praise framing\n";
$fresh = BuddyService::phraseNudge('sale_praise_t2', ['ref' => 'B2', 'amount' => 1500, 'currency' => 'USD'], 'Sam', 0, 0.5);
$old   = BuddyService::phraseNudge('sale_praise_t2', ['ref' => 'B2', 'amount' => 1500, 'currency' => 'USD'], 'Sam', 0, 14.0);
check('fresh praise says "just saw"', str_contains($fresh, 'just saw'));
check('stale praise does NOT claim it just happened', !str_contains($old, 'just saw') && !str_contains($old, 'Stop everything'));
check('stale praise frames as catching up', str_contains($old, 'while you were away') || str_contains($old, 'catching you up'));
check('stale praise still names ref + amount', str_contains($old, 'B2') && str_contains($old, '$1,500.00'));

echo "F10. Personal bests\n";
$ever = BuddyService::phraseNudge('sale_praise_t2', ['ref' => 'C3', 'amount' => 2000, 'best_ever' => true, 'best_month' => true], 'Sam', 0);
check('all-time best celebrated', str_contains($ever, 'EVER'));
$mon = BuddyService::phraseNudge('sale_praise_t1', ['ref' => 'C3', 'amount' => 700, 'best_month' => true], 'Sam', 0);
check('monthly best celebrated', str_contains($mon, 'biggest this month'));
check('monthly best does not claim all-time', !str_contains($mon, 'EVER'));
$none = BuddyService::phraseNudge('sale_praise_t1', ['ref' => 'C3', 'amount' => 600], 'Sam', 0);
check('no record claimed when absent', !str_contains($none, 'biggest') && !str_contains($none, 'EVER'));
$staleBest = BuddyService::phraseNudge('sale_praise_t2', ['ref' => 'C4', 'amount' => 3000, 'best_ever' => true], 'Sam', 0, 20.0);
check('stale praise still celebrates the record', str_contains($staleBest, 'EVER'));

echo "F11. Lag escalation\n";
$r1 = BuddyService::phraseNudge('eticket_lag', ['ref' => 'D4', 'waiting_hours' => 5, 'round' => 1], 'Sam', 0);
$r2 = BuddyService::phraseNudge('eticket_lag', ['ref' => 'D4', 'waiting_hours' => 26, 'round' => 2], 'Sam', 0);
$r3 = BuddyService::phraseNudge('eticket_lag', ['ref' => 'D4', 'waiting_hours' => 74, 'round' => 3], 'Sam', 0);
check('round 1 is the gentle opener', str_contains($r1, 'tiny heads-up') || str_contains($r1, 'Two minutes'));
check('round 2 acknowledges repetition', $r2 !== $r1 && (str_contains($r2, 'me again') || str_contains($r2, 'Circling back')));
check('round 3 still escalated', $r3 !== $r1);
foreach (['round 2' => $r2, 'round 3' => $r3] as $lbl => $txt) {
    check("{$lbl} carries no guilt language",
        !preg_match('/\b(you (still |always )?(failed|forgot|ignored)|why haven\'t you|disappoint)/i', $txt), $txt);
}
$ar2 = BuddyService::phraseNudge('acceptance_lag', ['acceptance' => '#12', 'waiting_hours' => 30, 'round' => 2], 'Sam', 0);
check('acceptance round 2 escalates too', str_contains($ar2, '#12') && (str_contains($ar2, 'still sitting') || str_contains($ar2, 'One more time')));
$missing = BuddyService::phraseNudge('eticket_lag', ['ref' => 'D4', 'waiting_hours' => 5], 'Sam', 0);
check('missing round defaults to gentle', $missing === $r1);

echo "F12. Escalation dedupe keys (cron contract)\n";
require_once __DIR__ . '/../cron/buddy_triggers_rules.php';
check('round 1 keeps the legacy key', lagKey('eticket_lag', 'txn', 9, 1) === 'eticket_lag:txn:9');
check('round 0 also maps to legacy key', lagKey('eticket_lag', 'txn', 9, 0) === 'eticket_lag:txn:9');
check('round 2 is distinct', lagKey('eticket_lag', 'txn', 9, 2) === 'eticket_lag:txn:9:r2');
check('round 3 is distinct', lagKey('eticket_lag', 'txn', 9, 3) === 'eticket_lag:txn:9:r3');
check('below tier1 = no nudge', lagRound(3, 4) === 0);
check('at tier1 = round 1', lagRound(4, 4) === 1);
check('23h still round 1', lagRound(23, 4) === 1);
check('24h = round 2', lagRound(24, 4) === 2);
check('72h = round 3', lagRound(72, 4) === 3);
check('acceptance tier1 is 6h', lagRound(5, 6) === 0 && lagRound(6, 6) === 1);

echo "F13. Admin message relay (Super Buddy → agent)\n";
$adminText = "Sam, please prioritise the Dubai group booking today - client called twice.";
Capsule::table('buddy_nudges')->insert([
    'user_id' => 7, 'type' => 'admin_message',
    'payload_json' => json_encode(['message' => $adminText, 'from' => 'admin'], JSON_UNESCAPED_UNICODE),
    'status' => 'pending', 'dedupe_key' => 'admin_nudge:1:7:' . time(), 'created_at' => $now,
]);
// A lower-priority nudge queued alongside it, to prove ordering.
Capsule::table('buddy_nudges')->insert([
    'user_id' => 7, 'type' => 'dry_spell',
    'payload_json' => json_encode(['days_since_last_sale' => 5]),
    'status' => 'pending', 'dedupe_key' => 'ds:7:b', 'created_at' => $now,
]);
$r5 = $svc->agentFeed(7, 'agent');
$adminMsg = $r5['messages'][0]['content'] ?? '';
check('admin message delivered first', str_contains($adminMsg, 'admin'));
check('admin text relayed VERBATIM', str_contains($adminMsg, $adminText), $adminMsg);
check('not replaced by the generic fallback', !str_contains($adminMsg, 'worth a look'));
$empty = BuddyService::phraseNudge('admin_message', ['message' => '  '], 'Sam', 0);
check('empty admin message degrades honestly', str_contains($empty, 'empty'));
$longText = str_repeat('word ', 200);
$long = BuddyService::phraseNudge('admin_message', ['message' => $longText], 'Sam', 0);
check('long admin message is not truncated', str_contains($long, rtrim($longText)));

echo "F14. Greeting claim is atomic\n";
$claim = $ref->getMethod('claimGreeting');
Capsule::table('buddy_settings')->where('user_id', 7)->update(['extra_json' => null]);
$first  = $claim->invoke($svc, 7, '2026-08-18');
$second = $claim->invoke($svc, 7, '2026-08-18');
$third  = $claim->invoke($svc, 7, '2026-08-18');
check('first caller wins the claim', $first === true);
check('second concurrent caller loses', $second === false);
check('third caller loses too', $third === false);
check('next business day claims again', $claim->invoke($svc, 7, '2026-08-19') === true);
check('display_name still intact after claims',
    Capsule::table('buddy_settings')->where('user_id', 7)->value('display_name') === 'Sammy');
// A user with no buddy_settings row at all (the common case on day one).
$fresh1 = $claim->invoke($svc, 99, '2026-08-18');
$fresh2 = $claim->invoke($svc, 99, '2026-08-18');
check('brand-new user claims once', $fresh1 === true && $fresh2 === false);

echo "F15. History is scrubbed on the way to Gemini\n";
$leaky = "Sam, call the client on 9876543210 or mail raj.sharma@example.com about card 4111 1111 1111 1111.";
$contents = \App\Services\Buddy\BuddyPromptBuilder::buildContents([
    ['role' => 'user',  'content' => 'hi'],
    ['role' => 'model', 'content' => "passing this on from the admin:\n\n\"{$leaky}\""],
]);
$wire = json_encode($contents);
check('phone scrubbed from history', !str_contains($wire, '9876543210') && str_contains($wire, '[phone-redacted]'));
check('email scrubbed from history', !str_contains($wire, 'raj.sharma@example.com') && str_contains($wire, '[email-redacted]'));
check('card scrubbed from history', !str_contains($wire, '4111') && str_contains($wire, '[card-redacted]'));
check('surrounding text survives', str_contains($wire, 'passing this on from the admin'));

// The agent must still see exactly what their admin wrote — scrubbing is for
// the wire to Google only, never for the stored transcript.
$relay = BuddyService::phraseNudge('admin_message', ['message' => $leaky], 'Sam', 0);
check('agent-facing relay is NOT redacted', str_contains($relay, '9876543210') && str_contains($relay, 'raj.sharma@example.com'));

// Timestamps in history must survive (the date-shape exemption).
$dated = \App\Services\Buddy\BuddyPromptBuilder::buildContents([
    ['role' => 'user', 'content' => 'Booking G7BGL3 departs 2026-08-18 09:40 for $1,240.50'],
]);
$dw = json_encode($dated);
check('dates not mistaken for phone numbers', str_contains($dw, '2026-08-18 09:40'));
check('amounts survive scrubbing', str_contains($dw, '1,240.50'));
check('booking refs survive scrubbing', str_contains($dw, 'G7BGL3'));

echo "\n" . ($fail === 0 ? "ALL {$pass} CHECKS PASSED ✓" : "{$fail} FAILED / {$pass} passed ✗") . "\n";
exit($fail === 0 ? 0 : 1);
