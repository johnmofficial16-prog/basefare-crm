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
 *  F30. The greeting is a HELLO, not a briefing (19 Aug, after the client's
 *       verdict on the arrival experience). The hold notice never reaches a
 *       greeting; the highlight stays silent on an empty day and never
 *       narrates a zero; the degraded fallback is still a hello rather than a
 *       digest dump; and the voice text the server pre-synthesizes is
 *       byte-identical to what the widget would have sent.
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
  content TEXT, tokens_in INTEGER, tokens_out INTEGER, feedback INTEGER, created_at TEXT);
CREATE TABLE buddy_nudges (
  id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, type TEXT,
  ref_table TEXT, ref_id INTEGER, payload_json TEXT,
  status TEXT DEFAULT 'pending', dedupe_key TEXT UNIQUE,
  created_at TEXT, delivered_at TEXT,
  outcome TEXT, outcome_at TEXT, outcome_hours REAL);
CREATE TABLE buddy_settings (
  user_id INTEGER PRIMARY KEY, enabled INTEGER DEFAULT 1,
  voice_enabled INTEGER DEFAULT 1, display_name TEXT,
  onboarded_at TEXT, extra_json TEXT);
CREATE TABLE buddy_agent_facts (
  id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, fact TEXT,
  source TEXT, active INTEGER DEFAULT 1, created_at TEXT, updated_at TEXT);
-- ShiftService::businessDayStartHour() reads this. Without it the daily-pacing
-- ceiling throws, gets swallowed by a fail-open catch, and silently does
-- nothing — which is exactly how the first version of F17d passed by accident.
CREATE TABLE system_config ("key" TEXT PRIMARY KEY, value TEXT);
SQL);
Capsule::table('system_config')->insert(['key' => 'business_day_start_hour', 'value' => '18']);

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
$r1 = $svc->agentFeed(7);
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

// From F17 on, drains are PACED: an unsolicited batch starts a cooldown. The
// mechanics tests below pass panelOpen=true — the agent is sitting in the chat,
// which is the one case that legitimately bypasses the cooldown — so they keep
// testing draining rather than accidentally testing the pacing gate.
echo "F1/F3. Second drain (remaining 2)\n";
$r2 = $svc->agentFeed(7, true);
check('returns the remaining 2', count($r2['messages']) === 2, count($r2['messages']) . ' returned');
check('pending_left is 0', $r2['pending_left'] === 0);
$t2 = array_column($r2['messages'], 'content');
check('acceptance before dry_spell', isset($t2[0], $t2[1]) && str_contains($t2[0], '#88') && str_contains($t2[1], '4 days'));

echo "F4. Third drain (empty) is a no-op\n";
$r3 = $svc->agentFeed(7, true);
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
$r4 = $svc->agentFeed(7, true);
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
$svc->agentFeed(7, true);
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
$r5 = $svc->agentFeed(7);
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

echo "F16. Stale time-sensitive nudges are swallowed, not voiced with wrong numbers\n";
$old = date('Y-m-d H:i:s', time() - 60 * 3600);   // 2.5 days ago
$stale = [
    ['eticket_lag',    ['ref' => 'OLD001', 'waiting_hours' => 5], 'st:1'],
    ['acceptance_lag', ['acceptance' => '#OLD2', 'waiting_hours' => 7], 'st:2'],
    ['departure_24h',  ['ref' => 'OLD003', 'departs' => 'tomorrow'], 'st:3'],
    ['dry_spell',      ['days_since_last_sale' => 3], 'st:4'],
];
foreach ($stale as [$type, $payload, $key]) {
    Capsule::table('buddy_nudges')->insert([
        'user_id' => 21, 'type' => $type, 'payload_json' => json_encode($payload),
        'status' => 'pending', 'dedupe_key' => $key, 'created_at' => $old,
    ]);
}
$s1 = $svc->agentFeed(21);
$s2 = $svc->agentFeed(21);   // drain past the batch cap
$said = array_merge(array_column($s1['messages'], 'content'), array_column($s2['messages'], 'content'));
check('nothing voiced from stale lag/departure/dry-spell', $said === [], implode(' | ', $said));
check('stale nudges still drained (queue not clogged)',
    Capsule::table('buddy_nudges')->where('user_id', 21)->where('status', 'pending')->count() === 0);
check('no wrong "5h" claim reached the transcript',
    !Capsule::table('buddy_messages')->where('content', 'like', '%OLD001%')->exists());

// ...but a stale sale praise and a stale admin message MUST still arrive.
Capsule::table('buddy_nudges')->insert([
    'user_id' => 22, 'type' => 'sale_praise_t2',
    'payload_json' => json_encode(['ref' => 'LATE01', 'amount' => 1800, 'currency' => 'USD']),
    'status' => 'pending', 'dedupe_key' => 'st:5', 'created_at' => $old,
]);
Capsule::table('buddy_nudges')->insert([
    'user_id' => 22, 'type' => 'admin_message',
    'payload_json' => json_encode(['message' => 'Call me when you are in.', 'from' => 'admin']),
    'status' => 'pending', 'dedupe_key' => 'st:6', 'created_at' => $old,
]);
$s3 = $svc->agentFeed(22);
$t3 = implode(' | ', array_column($s3['messages'], 'content'));
check('old admin message still delivered', str_contains($t3, 'Call me when you are in.'));
check('old praise still delivered', str_contains($t3, 'LATE01'));
check('old praise uses catching-up framing', str_contains($t3, 'while you were away') || str_contains($t3, 'catching you up'));

// Boundary: just inside the TTL must still be voiced.
Capsule::table('buddy_nudges')->insert([
    'user_id' => 23, 'type' => 'eticket_lag',
    'payload_json' => json_encode(['ref' => 'FRESH1', 'waiting_hours' => 5]),
    'status' => 'pending', 'dedupe_key' => 'st:7',
    'created_at' => date('Y-m-d H:i:s', time() - 20 * 3600),
]);
$s4 = $svc->agentFeed(23);
check('20h-old lag nudge still voiced (inside TTL)',
    str_contains($s4['messages'][0]['content'] ?? '', 'FRESH1'));

echo "F17. Pacing — cooldown between batches\n";
$mk = function (int $uid, string $type, array $payload, string $key, ?string $at = null) use ($now) {
    Capsule::table('buddy_nudges')->insert([
        'user_id' => $uid, 'type' => $type, 'payload_json' => json_encode($payload),
        'status' => 'pending', 'dedupe_key' => $key, 'created_at' => $at ?? $now,
    ]);
};
for ($i = 0; $i < 9; $i++) {
    $mk(31, 'eticket_lag', ['ref' => 'P' . $i, 'waiting_hours' => 5], 'pc:' . $i);
}
$p1 = $svc->agentFeed(31);
check('first batch speaks (3)', count($p1['messages']) === 3, count($p1['messages']) . ' returned');
$p2 = $svc->agentFeed(31);
check('immediate re-poll is held', $p2['messages'] === []);
check('hold_seconds reported to the client', ($p2['hold_seconds'] ?? 0) > 0 && ($p2['hold_seconds'] ?? 0) <= 180);
check('backlog still reported while held', $p2['pending_left'] === 6);
check('nothing extra was drained during the hold',
    Capsule::table('buddy_nudges')->where('user_id', 31)->where('status', 'pending')->count() === 6);

echo "F17b. Opening the chat bypasses the cooldown (they are asking)\n";
$p3 = $svc->agentFeed(31, true);
check('panel-open drain delivers despite cooldown', count($p3['messages']) === 3, count($p3['messages']) . ' returned');

echo "F17c. A human message ignores pacing entirely\n";
$mk(32, 'eticket_lag', ['ref' => 'H1', 'waiting_hours' => 5], 'hp:1');
$svc->agentFeed(32);                        // starts a cooldown
$held = $svc->agentFeed(32);
check('cooldown active for user 32', $held['messages'] === []);
$mk(32, 'admin_message', ['message' => 'Ring me now please.', 'from' => 'admin'], 'hp:2');
$urgent = $svc->agentFeed(32);
$ut = implode(' | ', array_column($urgent['messages'], 'content'));
check('admin message punches through the cooldown', str_contains($ut, 'Ring me now please.'));

echo "F17d. Daily ceiling on how much she initiates\n";
$capUser = 33;
for ($i = 0; $i < 20; $i++) {
    $mk($capUser, 'eticket_lag', ['ref' => 'C' . $i, 'waiting_hours' => 5], 'cap:' . $i);
}
$said = 0;
for ($round = 0; $round < 10; $round++) {
    $r = $svc->agentFeed($capUser, true);   // panel open = no cooldown, cap only
    $said += count($r['messages']);
    if ($r['messages'] === []) { break; }
}
check('daily ceiling enforced (<= 12 initiated)', $said <= 12, "said {$said}");
check('ceiling actually reached, not just a short queue', $said === 12, "said {$said}");
$after = $svc->agentFeed($capUser, true);
check('further drains held once capped', $after['messages'] === []);
check('hold points past today, not a short pause', ($after['hold_seconds'] ?? 0) > 180);
check('remaining nudges left pending, not silently binned',
    Capsule::table('buddy_nudges')->where('user_id', $capUser)->where('status', 'pending')->count() === 8);

echo "F17e. A swallowed-only drain must not start a cooldown\n";
$staleOnly = 34;
$oldTs = date('Y-m-d H:i:s', time() - 60 * 3600);
$mk($staleOnly, 'eticket_lag', ['ref' => 'S1', 'waiting_hours' => 5], 'so:1', $oldTs);
$mk($staleOnly, 'sale_praise_t1', ['ref' => 'S2', 'amount' => 700, 'currency' => 'USD'], 'so:2');
$d1 = $svc->agentFeed($staleOnly);
check('expired nudge swallowed but fresh praise still spoken',
    count($d1['messages']) === 1 && str_contains($d1['messages'][0]['content'], 'S2'));

echo "F18. Self-set monthly goal\n";
Capsule::connection()->getPdo()->exec(
    'CREATE TABLE transactions (id INTEGER PRIMARY KEY AUTOINCREMENT, agent_id INTEGER,
     status TEXT, total_amount REAL, created_at TEXT)'
);
$reg  = \App\Services\Buddy\AgentTools::registry(41, 'agent');
$none = $reg->execute('get_my_goal_progress', []);
check('no goal yet reports has_goal:false', ($none['has_goal'] ?? null) === false);
check('and tells Aisha not to invent one', str_contains((string) ($none['hint'] ?? ''), 'never invent'));

check('rejects an empty goal', isset($reg->execute('set_my_goal', [])['error']));
check('rejects a silly sales target', isset($reg->execute('set_my_goal', ['sales' => 5000])['error']));
check('rejects a negative revenue target', isset($reg->execute('set_my_goal', ['revenue' => -5])['error']));

$saved = $reg->execute('set_my_goal', ['sales' => 20]);
check('saves a sales goal', ($saved['saved'] ?? false) === true && ($saved['goal']['sales'] ?? 0) === 20);
$saved2 = $reg->execute('set_my_goal', ['revenue' => 30000]);
check('adding revenue keeps the existing sales goal',
    ($saved2['goal']['sales'] ?? 0) === 20 && ($saved2['goal']['revenue'] ?? 0) == 30000.0);

// Eight approved sales this month, $12,000.
for ($i = 0; $i < 8; $i++) {
    Capsule::table('transactions')->insert([
        'agent_id' => 41, 'status' => 'approved', 'total_amount' => 1500,
        'created_at' => date('Y-m-d H:i:s'),
    ]);
}
// Noise that must NOT count: another agent, and a non-approved sale.
Capsule::table('transactions')->insert(['agent_id' => 99, 'status' => 'approved', 'total_amount' => 9999, 'created_at' => date('Y-m-d H:i:s')]);
Capsule::table('transactions')->insert(['agent_id' => 41, 'status' => 'pending',  'total_amount' => 9999, 'created_at' => date('Y-m-d H:i:s')]);

$prog = $reg->execute('get_my_goal_progress', []);
check('counts only this agent, only approved', ($prog['sales']['done'] ?? -1) === 8);
check('revenue matches the same rule', ($prog['revenue']['done'] ?? -1) == 12000.0);
check('remaining is computed', ($prog['sales']['remaining'] ?? -1) === 12);
check('goal not yet hit', ($prog['sales']['hit'] ?? true) === false);
check('month framing present', ($prog['days_in_month'] ?? 0) >= 28 && ($prog['days_elapsed'] ?? 0) >= 1);
check('pace expectation present', isset($prog['sales']['expected_by_now'], $prog['sales']['on_track']));
check('labelled as the agent\'s own goal', str_contains((string) ($prog['goal_is'] ?? ''), 'self-chosen'));

// Hitting it must be recognised.
for ($i = 0; $i < 15; $i++) {
    Capsule::table('transactions')->insert([
        'agent_id' => 41, 'status' => 'approved', 'total_amount' => 1500,
        'created_at' => date('Y-m-d H:i:s'),
    ]);
}
$hit = $reg->execute('get_my_goal_progress', []);
check('goal recognised as hit', ($hit['sales']['hit'] ?? false) === true);
check('remaining floors at zero', ($hit['sales']['remaining'] ?? -1) === 0);

echo "F18c. A goal can be abandoned\n";
$reg->execute('set_my_goal', ['sales' => 30]);
check('goal set before clearing', ($reg->execute('get_my_goal_progress', [])['has_goal'] ?? false) === true);
$cleared = $reg->execute('set_my_goal', ['clear' => true]);
check('clear reports success', ($cleared['cleared'] ?? false) === true);
check('progress reverts to has_goal:false', ($reg->execute('get_my_goal_progress', [])['has_goal'] ?? null) === false);
check('clearing tells Aisha not to re-badger', str_contains((string) ($cleared['note'] ?? ''), 'unless they raise it'));
$reg->execute('set_my_goal', ['sales' => 20]);   // restore for the tests below
$reg->execute('set_my_goal', ['revenue' => 30000]);

echo "F18b. Goal storage shares extra_json without clobbering pacing/greeting\n";
$claim2 = $ref->getMethod('claimGreeting');
$claim2->invoke($svc, 41, '2026-08-20');
$reg->execute('set_my_goal', ['sales' => 25]);
$blob = json_decode((string) Capsule::table('buddy_settings')->where('user_id', 41)->value('extra_json'), true);
check('greeting stamp survives a goal write', ($blob['last_greeted_bday'] ?? null) === '2026-08-20');
check('goal survives alongside it', ($blob['goal']['sales'] ?? 0) === 25);
check('claim still honoured after goal write', $claim2->invoke($svc, 41, '2026-08-20') === false);

echo "F19. Goal nudge phrasing keeps ownership with the agent\n";
$hitS = BuddyService::phraseNudge('goal_hit', ['metric' => 'sales', 'target' => 20, 'done' => 21], 'Sam', 0);
check('goal_hit celebrates with real figures', str_contains($hitS, '20') && str_contains($hitS, '21'));
check('goal_hit says the agent set it', str_contains($hitS, 'you set') || str_contains($hitS, 'you said you wanted'));
$hitR = BuddyService::phraseNudge('goal_hit', ['metric' => 'revenue', 'target' => 30000, 'done' => 31500], 'Sam', 1);
check('revenue goal renders as dollars', str_contains($hitR, '$30,000') && str_contains($hitR, '$31,500'));
check('revenue goal drops the "sales" unit', !str_contains($hitR, '$30,000 sales'));

$pace = BuddyService::phraseNudge('goal_pace',
    ['metric' => 'sales', 'target' => 20, 'done' => 6, 'days_left' => 10, 'per_day' => 1.4], 'Sam', 0);
check('pace nudge gives the run rate', str_contains($pace, '10 days') || str_contains($pace, '10 days to go'));
check('pace nudge keeps ownership', str_contains($pace, 'you set yourself') || str_contains($pace, 'your own'));
foreach (['goal_hit' => $hitS, 'goal_pace' => $pace] as $lbl => $txt) {
    check("{$lbl} never implies a management target",
        !preg_match('/\b(target set by|management|your manager expects|required|quota|must hit)\b/i', $txt), $txt);
    check("{$lbl} promises no reward or consequence",
        !preg_match('/\b(bonus|incentive|commission|promotion|warning|trouble)\b/i', $txt), $txt);
}

echo "F19b. Goal nudge staleness rules\n";
$mk(51, 'goal_hit', ['metric' => 'sales', 'target' => 20, 'done' => 21], 'g:1', date('Y-m-d H:i:s', time() - 60 * 3600));
$mk(51, 'goal_pace', ['metric' => 'sales', 'target' => 20, 'done' => 6, 'days_left' => 10, 'per_day' => 1.4], 'g:2', date('Y-m-d H:i:s', time() - 60 * 3600));
$gd = $svc->agentFeed(51, true);
$gt = implode(' | ', array_column($gd['messages'], 'content'));
check('old goal_hit still celebrated (milestone)', str_contains($gt, 'DID it') || str_contains($gt, 'Goal reached'));
check('old goal_pace swallowed (its run rate has rotted)', !str_contains($gt, 'days to go') && !str_contains($gt, 'days left'));

echo "F19c. goal_hit outranks routine nudges, goal_pace does not\n";
$mk(52, 'dry_spell',  ['days_since_last_sale' => 4], 'gp:1');
$mk(52, 'goal_pace',  ['metric' => 'sales', 'target' => 20, 'done' => 6, 'days_left' => 9, 'per_day' => 1.5], 'gp:2');
$mk(52, 'goal_hit',   ['metric' => 'sales', 'target' => 20, 'done' => 20], 'gp:3');
$mk(52, 'eticket_lag',['ref' => 'GX1', 'waiting_hours' => 5], 'gp:4');
$ord = $svc->agentFeed(52, true);
$o0 = $ord['messages'][0]['content'] ?? '';
check('goal_hit delivered first', str_contains($o0, 'DID it') || str_contains($o0, 'Goal reached'));
$o = array_column($ord['messages'], 'content');
$paceIdx = -1; $lagIdx = -1;
foreach ($o as $i => $txt) {
    if (str_contains($txt, 'days to go') || str_contains($txt, 'days left')) { $paceIdx = $i; }
    if (str_contains($txt, 'GX1')) { $lagIdx = $i; }
}
check('e-ticket lag ranks above the pace check-in', $lagIdx !== -1 && ($paceIdx === -1 || $lagIdx < $paceIdx));

echo "F20. First sale of the day (the sub-threshold silence gap)\n";
$fs = BuddyService::phraseNudge('first_sale_today', ['ref' => 'SM350', 'amount' => 350, 'currency' => 'USD'], 'Sam', 0);
check('acknowledges a small sale warmly', str_contains($fs, 'SM350') && str_contains($fs, '$350.00'));
check('frames it as the first of the day', str_contains($fs, 'first') || str_contains($fs, 'On the board') || str_contains($fs, 'on the board'));
check('does not oversell it as huge', !preg_match('/\b(BIG|huge|biggest|EVER)\b/', $fs), $fs);

echo "F20b. Moment-shaped nudges expire faster than state-shaped ones\n";
$mk(61, 'first_sale_today', ['ref' => 'OLDFS', 'amount' => 350, 'currency' => 'USD'], 'fs:1', date('Y-m-d H:i:s', time() - 9 * 3600));
$e1 = $svc->agentFeed(61, true);
check('9h-old first-sale is swallowed (6h TTL)', $e1['messages'] === [], implode(' | ', array_column($e1['messages'], 'content')));
$mk(62, 'first_sale_today', ['ref' => 'NEWFS', 'amount' => 350, 'currency' => 'USD'], 'fs:2', date('Y-m-d H:i:s', time() - 2 * 3600));
$e2 = $svc->agentFeed(62, true);
check('2h-old first-sale still delivered', str_contains($e2['messages'][0]['content'] ?? '', 'NEWFS'));
// A lag nudge of the same age must NOT be caught by the shorter override.
$mk(63, 'eticket_lag', ['ref' => 'LAG9', 'waiting_hours' => 5], 'fs:3', date('Y-m-d H:i:s', time() - 9 * 3600));
$e3 = $svc->agentFeed(63, true);
check('9h-old lag nudge unaffected by the override', str_contains($e3['messages'][0]['content'] ?? '', 'LAG9'));

echo "F20c. Praise still outranks the first-sale note\n";
$mk(64, 'first_sale_today', ['ref' => 'A1', 'amount' => 350, 'currency' => 'USD'], 'fs:4');
$mk(64, 'sale_praise_t2',   ['ref' => 'B1', 'amount' => 2000, 'currency' => 'USD'], 'fs:5');
$ordr = $svc->agentFeed(64, true);
check('big sale spoken before the on-the-board note',
    str_contains($ordr['messages'][0]['content'] ?? '', 'B1'));

echo "F21. extra_json has three writers — none may clobber another\n";
$U = 71;
$claim3 = $ref->getMethod('claimGreeting');
$stamp3 = $ref->getMethod('stampFeed');
$reg71  = \App\Services\Buddy\AgentTools::registry($U, 'agent');

// All three keys written by their real code paths, in sequence.
check('greeting claimed', $claim3->invoke($svc, $U, '2026-08-18') === true);
$stamp3->invoke($svc, $U);
$reg71->execute('set_my_goal', ['sales' => 40]);

$blob = \App\Services\Buddy\BuddySettings::read($U);
check('greeting stamp survived the goal write', ($blob['last_greeted_bday'] ?? null) === '2026-08-18');
check('pacing stamp survived the goal write', !empty($blob['last_feed_at']));
check('goal survived both stamps', ($blob['goal']['sales'] ?? 0) === 40);

// Reverse order: goal first, then the two stamps.
$U2 = 72;
$reg72 = \App\Services\Buddy\AgentTools::registry($U2, 'agent');
$reg72->execute('set_my_goal', ['revenue' => 50000]);
$claim3->invoke($svc, $U2, '2026-08-18');
$stamp3->invoke($svc, $U2);
$blob2 = \App\Services\Buddy\BuddySettings::read($U2);
check('goal survived the later stamps', ($blob2['goal']['revenue'] ?? 0) == 50000.0);
check('both stamps present alongside it',
    ($blob2['last_greeted_bday'] ?? null) === '2026-08-18' && !empty($blob2['last_feed_at']));

// Clearing the goal must not take the stamps with it.
$reg72->execute('set_my_goal', ['clear' => true]);
$blob3 = \App\Services\Buddy\BuddySettings::read($U2);
check('clearing the goal leaves the stamps intact',
    ($blob3['last_greeted_bday'] ?? null) === '2026-08-18' && !empty($blob3['last_feed_at']));
check('goal really gone', !isset($blob3['goal']));
check('greeting still counts as claimed after a goal clear',
    $claim3->invoke($svc, $U2, '2026-08-18') === false);

// mutate() declining to change anything must not write.
$before = \App\Services\Buddy\BuddySettings::read($U2);
$noop = \App\Services\Buddy\BuddySettings::mutate($U2, fn(array $e) => [$e, 'untouched']);
check('a no-op mutate returns its result', $noop === 'untouched');
check('a no-op mutate leaves the blob byte-identical',
    \App\Services\Buddy\BuddySettings::read($U2) === $before);
check('mutate creates a row for a brand-new user',
    \App\Services\Buddy\BuddySettings::mutate(73, fn(array $e) => [['seeded' => 1], true]) === true
    && (\App\Services\Buddy\BuddySettings::read(73)['seeded'] ?? 0) === 1);

echo "F22. Shift wrap — the goodbye that closes the daily arc\n";
$goodDay = ['hours' => 9.2, 'sales' => 4, 'revenue' => 2870.5, 'net_mco' => 410.0, 'best' => 1240.5, 'open_etix' => 2];
$wg = BuddyService::phraseNudge('shift_wrap', $goodDay, 'Sam', 0);
check('good day celebrates with real numbers', str_contains($wg, '4 sale') && str_contains($wg, '$2,870.50'));
check('best sale mentioned on a multi-sale day', str_contains($wg, '$1,240.50'));
check('open e-tickets carried to tomorrow, not nagged', str_contains($wg, '2 e-tickets still open'));
check('sends them off to rest', stripos($wg, 'rest') !== false || stripos($wg, 'tomorrow') !== false);

$zeroDay = ['hours' => 9.0, 'sales' => 0, 'revenue' => 0, 'net_mco' => 0, 'best' => null, 'open_etix' => 0];
$wz = BuddyService::phraseNudge('shift_wrap', $zeroDay, 'Sam', 0);
check('zero day acknowledges the hours', str_contains($wz, '9 hours') || stripos($wz, 'showed up') !== false);
check('zero day claims no sales it does not have', !str_contains($wz, '0 sale') && !str_contains($wz, '$0.00'));
check('zero day points at tomorrow', stripos($wz, 'tomorrow') !== false);
foreach ([['good', $wg], ['zero', $wz], ['zero-v2', BuddyService::phraseNudge('shift_wrap', $zeroDay, 'Sam', 1)]] as [$lbl, $txt]) {
    check("{$lbl} wrap carries no guilt language",
        !preg_match('/\b(only|just|disappoint|should have|failed|behind|wasted)\b/i', $txt), $txt);
}
$oneSale = BuddyService::phraseNudge('shift_wrap', ['hours' => 8, 'sales' => 1, 'revenue' => 350.0, 'open_etix' => 1], 'Sam', 0);
check('singular forms correct (1 sale, 1 e-ticket)',
    str_contains($oneSale, '1 sale ') && str_contains($oneSale, '1 e-ticket still open') && !str_contains($oneSale, '1 sales'));

echo "F22b. Wrap TTL and priority\n";
$mk(81, 'shift_wrap', $goodDay, 'sw:1', date('Y-m-d H:i:s', time() - 5 * 3600));
$w1 = $svc->agentFeed(81, true);
check('5h-old wrap swallowed (4h TTL — numbers went stale)', $w1['messages'] === []);
$mk(82, 'goal_pace', ['metric' => 'sales', 'target' => 20, 'done' => 6, 'days_left' => 9, 'per_day' => 1.5], 'sw:2');
$mk(82, 'shift_wrap', $goodDay, 'sw:3');
$mk(82, 'eticket_lag', ['ref' => 'WX1', 'waiting_hours' => 5], 'sw:4');
$w2 = $svc->agentFeed(82, true);
$wOrder = array_column($w2['messages'], 'content');
check('lag before wrap, wrap before pace check-in',
    str_contains($wOrder[0] ?? '', 'WX1') && str_contains($wOrder[1] ?? '', '🌙')
    && (str_contains($wOrder[2] ?? '', 'days to go') || str_contains($wOrder[2] ?? '', 'days left')));

echo "F23. History shape — Aisha remembers what she just said\n";
$bc = fn(array $h) => \App\Services\Buddy\BuddyPromptBuilder::buildContents($h);

// Post-P5 head-of-history: a run of Aisha-initiated nudges, then the agent.
$h1 = $bc([
    ['role' => 'model', 'content' => 'Nudge about booking AAA111.'],
    ['role' => 'model', 'content' => 'Nudge about booking BBB222.'],
    ['role' => 'user',  'content' => 'Which bookings did you just mention?'],
]);
$flat1 = json_encode($h1);
check('leading nudges are KEPT, not dropped', str_contains($flat1, 'AAA111') && str_contains($flat1, 'BBB222'));
check('first content is role user', ($h1[0]['role'] ?? '') === 'user');
check('scaffold turn is the tiny resume marker', str_contains((string) ($h1[0]['parts'][0]['text'] ?? ''), 'conversation resumes'));

// Consecutive same-role runs are merged — the proto-shape trap from 16 Aug.
$roles1 = array_map(fn($c) => $c['role'], $h1);
check('no consecutive same-role contents', $roles1 === array_values(array_filter($roles1, fn($r, $i) => $i === 0 || $roles1[$i-1] !== $r, ARRAY_FILTER_USE_BOTH)));
$h2 = $bc([
    ['role' => 'user',  'content' => 'hi'],
    ['role' => 'model', 'content' => 'first'],
    ['role' => 'model', 'content' => 'second'],
    ['role' => 'user',  'content' => 'ok'],
    ['role' => 'user',  'content' => 'and also'],
]);
$roles2 = array_map(fn($c) => $c['role'], $h2);
check('model run merged into one turn', count(array_filter($roles2, fn($r) => $r === 'model')) === 1);
check('user run merged too', $roles2 === ['user', 'model', 'user']);
check('merged content keeps both halves in order',
    str_contains((string) $h2[1]['parts'][0]['text'], "first\n\nsecond"));

// A history that is ALL Aisha (agent never replied) must still reach Gemini.
$h3 = $bc([
    ['role' => 'model', 'content' => 'Solo nudge CCC333.'],
]);
check('all-model history survives with scaffold', count($h3) === 2 && str_contains(json_encode($h3), 'CCC333'));

echo "F24. Patterns — the coach's eyes (and its honesty flags)\n";
Capsule::connection()->getPdo()->exec(<<<SQL
CREATE TABLE acceptance_requests (
  id INTEGER PRIMARY KEY AUTOINCREMENT, agent_id INTEGER, status TEXT,
  is_preauth INTEGER DEFAULT 0, approved_at TEXT);
CREATE TABLE etickets (
  id INTEGER PRIMARY KEY AUTOINCREMENT, transaction_id INTEGER, created_at TEXT);
SQL);
// transactions table exists from F18 but patterns selects profit_mco/refund_mco_impact in weekRecap.
Capsule::connection()->getPdo()->exec(
    'ALTER TABLE transactions ADD COLUMN profit_mco REAL DEFAULT 0');
Capsule::connection()->getPdo()->exec(
    'ALTER TABLE transactions ADD COLUMN refund_mco_impact REAL DEFAULT 0');
Capsule::connection()->getPdo()->exec(
    'ALTER TABLE transactions ADD COLUMN acceptance_id INTEGER');

$P = 91;
$regP = \App\Services\Buddy\AgentTools::registry($P, 'agent');

// Sparse data first: the honesty flags must engage.
Capsule::table('transactions')->insert(['agent_id' => $P, 'status' => 'approved', 'total_amount' => 500,
    'profit_mco' => 50, 'refund_mco_impact' => 0, 'created_at' => date('Y-m-d H:i:s', strtotime('last tuesday 20:00'))]);
$sparse = $regP->execute('get_my_patterns', []);
check('sparse weekdays refuse to name a best day',
    array_key_exists('best_day', $sparse['weekdays']) && $sparse['weekdays']['best_day'] === null);
check('sparse note says do not claim one', str_contains((string) ($sparse['weekdays']['note'] ?? ''), 'do not claim'));

// Now a real shape: 6 Tuesday sales, 2 Friday sales (all within 90d).
for ($i = 0; $i < 5; $i++) {
    Capsule::table('transactions')->insert(['agent_id' => $P, 'status' => 'approved', 'total_amount' => 400 + $i,
        'profit_mco' => 40, 'refund_mco_impact' => 0,
        'created_at' => date('Y-m-d H:i:s', strtotime("-" . (7 * ($i + 1)) . " days tuesday 20:00") ?: time() - 7 * 86400 * ($i + 1))]);
}
// deterministic weekday rows: use explicit recent Tuesdays/Fridays
Capsule::table('transactions')->where('agent_id', $P)->delete();
$mkTxn = function (string $when, float $amt = 400) use ($P) {
    return Capsule::table('transactions')->insertGetId(['agent_id' => $P, 'status' => 'approved',
        'total_amount' => $amt, 'profit_mco' => 40, 'refund_mco_impact' => 0,
        'created_at' => date('Y-m-d 20:00:00', strtotime($when))]);
};
$tues = []; $fris = [];
for ($w = 1; $w <= 6; $w++) { $tues[] = $mkTxn("tuesday -{$w} week"); }
for ($w = 1; $w <= 2; $w++) { $fris[] = $mkTxn("friday -{$w} week"); }
$pat = $regP->execute('get_my_patterns', []);
check('best day is Tuesday', ($pat['weekdays']['best_day'] ?? '') === 'Tuesday', json_encode($pat['weekdays']));
check('weekday sample_size is 8', ($pat['weekdays']['sample_size'] ?? 0) === 8);
check('no tentative note once the sample is real', ($pat['weekdays']['note'] ?? null) === null);

// Conversion: 4 approved acceptances, 3 converted (2h, 4h, 6h), 1 not.
foreach ([2, 4, 6] as $i => $h) {
    $accId = Capsule::table('acceptance_requests')->insertGetId(['agent_id' => $P, 'status' => 'APPROVED',
        'is_preauth' => 0, 'approved_at' => date('Y-m-d H:i:s', time() - 10 * 86400 - $h * 3600)]);
    Capsule::table('transactions')->insert(['agent_id' => $P, 'status' => 'approved', 'total_amount' => 300,
        'profit_mco' => 30, 'refund_mco_impact' => 0, 'acceptance_id' => $accId,
        'created_at' => date('Y-m-d H:i:s', time() - 10 * 86400)]);
}
Capsule::table('acceptance_requests')->insert(['agent_id' => $P, 'status' => 'APPROVED',
    'is_preauth' => 0, 'approved_at' => date('Y-m-d H:i:s', time() - 9 * 86400)]);
$pat2 = $regP->execute('get_my_patterns', []);
check('conversion rate 75%', ($pat2['conversion']['rate_pct'] ?? 0) == 75.0, json_encode($pat2['conversion']));
check('avg hours to convert = 4', ($pat2['conversion']['avg_hours_to_convert'] ?? 0) == 4.0);

// E-ticket speed: two ticketed sales, 3h and 5h after sale.
foreach ([3, 5] as $h) {
    $tid = $mkTxn('-3 days');
    Capsule::table('etickets')->insert(['transaction_id' => $tid,
        'created_at' => date('Y-m-d H:i:s', strtotime(date('Y-m-d 20:00:00', strtotime('-3 days'))) + $h * 3600)]);
}
$pat3 = $regP->execute('get_my_patterns', []);
check('eticket speed averages 4h', ($pat3['eticket_speed']['avg_hours_to_eticket'] ?? 0) == 4.0, json_encode($pat3['eticket_speed']));
check('eticket small-sample flag honest', str_contains((string) ($pat3['eticket_speed']['note'] ?? ''), 'tentative'));

// PII wall: nothing customer-shaped in the whole payload.
$flatP = json_encode($pat3);
check('patterns carry no customer fields', !preg_match('/customer|email|phone|card/i', $flatP));

echo "F24b. Week recap\n";
$wr = $regP->execute('get_my_week_recap', []);
check('recap has both windows', isset($wr['last_7']['sales'], $wr['prior_7']['sales']));
check('recap counts something recent', $wr['last_7']['sales'] >= 1);
check('best day named with its count', !empty($wr['last_7']['best_day']['date']));

echo "F25. Proactive personalization — the interview is a system, not a hope\n";
$N = 95;
Capsule::table('users')->insert(['id' => $N, 'name' => 'Newbie Kumar']);
$regN = \App\Services\Buddy\AgentTools::registry($N, 'agent');

$gaps0 = \App\Services\Buddy\AgentTools::knowledgeGaps($N);
check('stranger has all three gaps', count($gaps0) === 3, json_encode($gaps0));
check('name is the FIRST gap (it shapes every line)', str_contains($gaps0[0], 'preferred name'));
check('goal gap is offer-not-impose', str_contains($gaps0[1], 'never impose'));

// She learns the name → toasts and templates immediately use it.
$saved = $regN->execute('set_my_name', ['name' => 'Nina']);
check('set_my_name saves', ($saved['saved'] ?? false) === true);
check('tool result says it applies everywhere', str_contains((string) ($saved['note'] ?? ''), 'spoken line'));
Capsule::table('buddy_nudges')->insert(['user_id' => $N, 'type' => 'eticket_lag',
    'payload_json' => json_encode(['ref' => 'NN1', 'waiting_hours' => 5]),
    'status' => 'pending', 'dedupe_key' => 'nn:1', 'created_at' => $now]);
$nf = $svc->agentFeed($N, true);
check('feed template now greets by the learned name', str_contains($nf['messages'][0]['content'] ?? '', 'Nina'));

$gaps1 = \App\Services\Buddy\AgentTools::knowledgeGaps($N);
check('name gap closes once learned', count($gaps1) === 2 && !str_contains(json_encode($gaps1), 'preferred name'));

// Goal + facts close the rest.
$regN->execute('set_my_goal', ['sales' => 10]);
$regN->execute('remember_fact', ['fact' => 'Saving for a bike.']);
$regN->execute('remember_fact', ['fact' => 'Loves closing Dubai routes.']);
$gaps2 = \App\Services\Buddy\AgentTools::knowledgeGaps($N);
check('goal gap closes', !str_contains(json_encode($gaps2), 'monthly goal'));
check('with 2 facts she still wants more depth', count($gaps2) === 1 && str_contains($gaps2[0], 'more of who they are'));
for ($i = 0; $i < 4; $i++) {
    $regN->execute('remember_fact', ['fact' => "Durable fact number {$i}."]);
}
check('knowing them well = interview over', \App\Services\Buddy\AgentTools::knowledgeGaps($N) === []);

check('set_my_name rejects empty', isset($regN->execute('set_my_name', ['name' => '  '])['error']));
check('set_my_name rejects a novel', isset($regN->execute('set_my_name', ['name' => str_repeat('x', 41)])['error']));
// A name must never smuggle PII into every future prompt.
$regN->execute('set_my_name', ['name' => 'raj@example.com']);
check('a PII-shaped name is scrubbed before storage',
    !str_contains((string) Capsule::table('buddy_settings')->where('user_id', $N)->value('display_name'), '@example.com'));

echo "F26. Learning loop — nudge outcomes and the Aisha effect\n";
require_once __DIR__ . '/../cron/buddy_triggers_rules.php';
check('entity id from txn key', lagEntityId('eticket_lag:txn:91') === 91);
check('entity id from escalated acc key', lagEntityId('acceptance_lag:acc:88:r2') === 88);
check('garbage key yields 0', lagEntityId('dry_spell:user:5:2026-08-19') === 0 && lagEntityId('') === 0);

// Aisha-effect stats: outcomes recorded by the cron → honest self-scoped section.
$L = 97;
$mkOutcome = function (string $type, ?string $outcome, ?float $hours, string $key) use ($L, $now) {
    Capsule::table('buddy_nudges')->insert([
        'user_id' => $L, 'type' => $type, 'payload_json' => '{}',
        'status' => 'seen', 'dedupe_key' => $key, 'created_at' => $now,
        'delivered_at' => $now, 'outcome' => $outcome, 'outcome_hours' => $hours,
    ]);
};
$mkOutcome('eticket_lag', 'resolved', 2.0, 'lo:1');
$mkOutcome('eticket_lag', 'resolved', 4.0, 'lo:2');
$mkOutcome('eticket_lag', 'unresolved', null, 'lo:3');
$mkOutcome('dry_spell',  'resolved', 20.0, 'lo:4');
$regL = \App\Services\Buddy\AgentTools::registry($L, 'agent');
$patL = $regL->execute('get_my_patterns', []);
$aeff = $patL['after_my_nudges'] ?? [];
check('effect section present', ($aeff['sample_size'] ?? 0) === 4, json_encode($aeff));
check('e-ticket nudges: 3 nudged, 2 resolved', ($aeff['by_type']['eticket_lag']['nudged'] ?? 0) === 3
    && ($aeff['by_type']['eticket_lag']['resolved_after_nudge'] ?? 0) === 2);
check('avg hours after nudge = 3', ($aeff['by_type']['eticket_lag']['avg_hours_after_nudge'] ?? 0) == 3.0);
check('small sample flagged honest', str_contains((string) ($aeff['note'] ?? ''), 'tentative'));
check('outcome rows with NULL outcome are excluded', !isset($aeff['by_type']['acceptance_lag']));
$flatL = json_encode($patL);
check('effect section carries no customer fields', !preg_match('/customer|email|phone|card/i', $flatL));

echo "F26b. Feedback thumbs\n";
// Build a conversation with two model messages; feedback must land on the newest.
$convL = Capsule::table('buddy_conversations')->insertGetId(['user_id' => $L, 'kind' => 'agent',
    'created_at' => $now, 'last_message_at' => $now]);
Capsule::table('buddy_messages')->insert(['conversation_id' => $convL, 'role' => 'model', 'content' => 'older', 'created_at' => $now]);
$newest = Capsule::table('buddy_messages')->insertGetId(['conversation_id' => $convL, 'role' => 'model', 'content' => 'newest', 'created_at' => $now]);
check('thumbs-down recorded', $svc->recordFeedback($L, 'agent', -1) === true);
check('lands on the NEWEST model message',
    (int) Capsule::table('buddy_messages')->where('id', $newest)->value('feedback') === -1);
check('older message untouched',
    Capsule::table('buddy_messages')->where('conversation_id', $convL)->where('content', 'older')->value('feedback') === null);
check('changing your mind overwrites', $svc->recordFeedback($L, 'agent', 1) === true
    && (int) Capsule::table('buddy_messages')->where('id', $newest)->value('feedback') === 1);
check('garbage value rejected', $svc->recordFeedback($L, 'agent', 5) === false);
check('no conversation = graceful false', $svc->recordFeedback(98765, 'agent', 1) === false);

echo "F27. Model router — the right brain for the question\n";
$easy = [
    'hi aisha!',
    'sales today?',
    'good morning',
    'call me TJ please',
    'thanks! see you tomorrow',
    'whats the status of booking G7BGL3',
];
foreach ($easy as $q) {
    check("fast lane: \"{$q}\"", BuddyService::isHardQuestion($q) === false, $q);
}
$hard = [
    'do you see any patterns in my work?',
    'whats my conversion rate like?',
    'how am i doing against my goal this month',
    'compare this week to last week',
    'why did my numbers drop? and what should i focus on?',
    'give me advice on improving my e-ticket speed',
    'help me figure out a plan for the rest of the month',
    'how was my week? and my month? and am I on track for the bike?',
];
foreach ($hard as $q) {
    check("thinking lane: \"" . mb_substr($q, 0, 40) . "\"", BuddyService::isHardQuestion($q) === true, $q);
}
// Length alone qualifies (compound intent), two question marks qualify.
check('long message routes to thinking', BuddyService::isHardQuestion(str_repeat('tell me about my day and then some more words ', 5)));
check('double question routes to thinking', BuddyService::isHardQuestion('sales today? bookings tomorrow?'));

echo "F28. Arrival greeting — browser reopen, not calendar day\n";
$G = 101;
Capsule::table('users')->insert(['id' => $G, 'name' => 'Login Tester']);
$claimG    = $ref->getMethod('claimGreeting');
$claimNow  = $ref->getMethod('claimGreetingNow');
$_SESSION  = [];

// First arrival of the day (fresh browser session) → greets.
check('first arrival greets', $claimNow->invoke($svc, $G) === true);
// Second tab / quick reload seconds later → cooldown holds her quiet.
check('a second tab does not re-greet', $claimNow->invoke($svc, $G) === false);
// The old per-business-day path must ALSO now see the day as greeted, so a
// mid-session page navigation stays silent.
[$bdS] = \App\Services\ShiftService::businessDayBounds();
$bdKey = substr((string) $bdS, 0, 10);   // business day, not calendar date
check('page navigation stays silent', $claimG->invoke($svc, $G, $bdKey) === false);

// Simulate returning much later: rewind the stamp past the cooldown.
$blobG = \App\Services\Buddy\BuddySettings::read($G);
$blobG['last_greeted_at'] = time() - 3600;
\App\Services\Buddy\BuddySettings::mutate($G, fn(array $e) => [$blobG, true]);
check('returning after a real gap greets again', $claimNow->invoke($svc, $G) === true);

// The login flag remains a valid trigger for surfaces that do see logins.
$_SESSION['buddy_greet_due'] = true;
$loginFlag = !empty($_SESSION['buddy_greet_due']); unset($_SESSION['buddy_greet_due']);
check('login flag still observed', $loginFlag === true);
check('login flag is consumed, not left armed', empty($_SESSION['buddy_greet_due']));

echo "F29. Admin personalization\n";
$A = 102;
Capsule::table('users')->insert(['id' => $A, 'name' => 'Boss Person']);
$agAdmin = \App\Services\Buddy\AgentTools::knowledgeGaps($A, 'admin');
check('admin gaps start with the name', str_contains($agAdmin[0], 'preferred name'));
check('admin is never asked for a sales goal', !str_contains(json_encode($agAdmin), 'monthly goal'));
check('admin depth gap is about briefings', str_contains(json_encode($agAdmin), 'check first'));
$regAdmin = \App\Services\Buddy\AdminTools::registry($A);
$names = array_column($regAdmin->declarations(), 'name');
check('admin registry gained set_my_name', in_array('set_my_name', $names, true));
check('admin registry gained remember_fact', in_array('remember_fact', $names, true));
check('admin keeps its cross-team tools', in_array('get_team_overview', $names, true));
check('admin CANNOT reach agent-only self tools',
    !in_array('set_my_goal', $names, true) && !in_array('get_my_patterns', $names, true));
$savedA = $regAdmin->execute('set_my_name', ['name' => 'Chief']);
check('admin name saves', ($savedA['saved'] ?? false) === true);
check('name gap closes for admin',
    !str_contains(json_encode(\App\Services\Buddy\AgentTools::knowledgeGaps($A, 'admin')), 'preferred name'));
$regAdmin->execute('remember_fact', ['fact' => 'Checks refunds before anything else each morning.']);
check('one fact still leaves a depth gap',
    count(\App\Services\Buddy\AgentTools::knowledgeGaps($A, 'admin')) === 1);
$regAdmin->execute('remember_fact', ['fact' => 'Wants headlines, not full detail.']);
foreach (['Watches net MCO closely.', 'Dislikes long reports.', 'Reviews chargebacks weekly.'] as $f) {
    $regAdmin->execute('remember_fact', ['fact' => $f]);
}
check('admin interview completes once she knows them',
    \App\Services\Buddy\AgentTools::knowledgeGaps($A, 'admin') === []);
// The persona must actually carry the learned identity.
$pm = $ref->getMethod('adminPersona');
$persona = $pm->invoke(null, $A);
check('admin persona uses the learned name', str_contains($persona, 'Chief'));
check('admin persona carries remembered preferences', str_contains($persona, 'headlines'));
check('agent gaps still include the goal offer',
    str_contains(json_encode(\App\Services\Buddy\AgentTools::knowledgeGaps($A)), 'monthly goal'));

echo "F30. The greeting is a hello, not a briefing\n";

// upcomingDepartures()/today()/monthSummary() need columns the earlier
// sections never added. Without refund_status the month query throws, the
// registry swallows it as ['error'], and the digest silently loses its month
// block — which would have made the hold-notice check below pass for entirely
// the wrong reason.
foreach (['currency TEXT', 'pnr TEXT', 'travel_date TEXT', 'departure_time TEXT', 'refund_status TEXT'] as $col) {
    try { Capsule::connection()->getPdo()->exec('ALTER TABLE transactions ADD COLUMN ' . $col); }
    catch (\Throwable $e) { /* already there */ }
}

$G2 = 501;
Capsule::table('users')->insert(['id' => $G2, 'name' => 'TJ Singh']);

// ── the PerformanceHold leak — the "August 10th" sentence in a hello ────────
Capsule::table('system_config')->insert([
    ['key' => 'performance.hold_enabled', 'value' => '1'],
    ['key' => 'performance.hold_from',    'value' => '2026-08-01 00:00:00'],
    ['key' => 'performance.hold_until',   'value' => '2026-08-09 23:59:59'],
    ['key' => 'performance.hold_notice',  'value' => 'Performance scoring for this month starts from August 10th.'],
]);
\App\Services\PerformanceHold::flush();

$fallbackM = $ref->getMethod('renderAgentFallback');
$forChat     = $fallbackM->invoke(null, $G2, 'agent', false);
$forGreeting = $fallbackM->invoke(null, $G2, 'agent', true);
check('chat digest still carries the hold notice honestly',
    str_contains($forChat, 'August 10th'));
check('greeting digest does NOT — this is the sentence the client quoted',
    !str_contains($forGreeting, 'August 10th'));
check('greeting digest keeps the real numbers it is given',
    str_contains($forGreeting, 'This month:'));

// ── the highlight: at most one thing, and silence when there is nothing ─────
$hl = $ref->getMethod('greetingHighlight');
$hlEmpty = $hl->invoke(null, $G2, 'agent');
check('empty day yields NO highlight at all', $hlEmpty['text'] === '');
check('and an empty day is not urgent', $hlEmpty['urgent'] === false);

// A flight inside 72h with no e-ticket outranks everything else.
Capsule::table('transactions')->insert([
    'id' => 9001, 'agent_id' => $G2, 'status' => 'approved', 'total_amount' => 900,
    'created_at' => date('Y-m-d H:i:s'), 'currency' => 'USD',
    'pnr' => 'ZZ9001', 'travel_date' => date('Y-m-d', time() + 86400), 'departure_time' => '09:40',
]);
$hlDep = $hl->invoke(null, $G2, 'agent');
check('a naked departure IS worth mentioning', str_contains($hlDep['text'], 'no e-ticket'), $hlDep['text']);
check('and it is half a sentence, not a report', str_word_count($hlDep['text']) <= 14, $hlDep['text']);
check('a departing flight is flagged URGENT, which drops the small talk',
    $hlDep['urgent'] === true);

// Ticket it, and today's own sale becomes the (gentler) highlight instead.
Capsule::table('etickets')->insert(['transaction_id' => 9001, 'created_at' => date('Y-m-d H:i:s')]);
$hlToday = $hl->invoke(null, $G2, 'agent');
check('with nothing urgent left it falls back to momentum',
    str_contains($hlToday['text'], 'on the board today'), $hlToday['text']);
check('momentum is NOT urgent — nothing needs doing about it',
    $hlToday['urgent'] === false);
check('a highlight never narrates a zero',
    !str_contains(strtolower($hlDep['text'] . ' ' . $hlToday['text']), 'zero'));

// ── the degraded path is still a hello (VERTEX_API_KEY is unset up top) ─────
$g = $svc->agentGreeting($G2, 'agent', true);
check('greeting still fires with the brain down', ($g['greeted'] ?? false) === true);
check('and it is honest that the AI did not answer', ($g['ai'] ?? true) === false);
$reply = (string) ($g['reply'] ?? '');
check('the fallback greets by first name', str_contains($reply, 'TJ'));
check('the fallback is ONE short line, not a digest',
    str_word_count($reply) <= 20, $reply);
check('the fallback states no figures at all',
    preg_match('/\d/', $reply) === 0, $reply);
check('the fallback never leaks the hold notice',
    !str_contains($reply, 'August 10th'));
check('no "here is where you stand" briefing framing',
    stripos($reply, 'where you stand') === false, $reply);

// The widget reads g.audio_url on every greeting, so the key must always be
// present — null when TTS is unconfigured, never missing.
check('the response always carries an audio_url key', array_key_exists('audio_url', $g));
check('unconfigured TTS pre-warms to null rather than throwing', $g['audio_url'] === null);

// ── Bug 3: the pre-synthesized URL actually reaches the widget ─────────────
// Still no network. synthesize() short-circuits on a cache hit BEFORE it ever
// reaches curl, so seeding the exact file the greeting would have produced
// exercises the whole pre-warm path — speakable() → cache key → URL — with no
// Google call and no key. The dummy key below is only there to get past
// isConfigured(); it is never sent anywhere.
$_ENV['GOOGLE_TTS_API_KEY'] = 'verifier-only-never-transmitted';

$voiceText = \App\Services\Buddy\TtsService::speakable($reply);
$vVoice = $_ENV['TTS_VOICE'] ?? 'en-IN-Neural2-A';
$vRate  = max(0.7, min(1.3, (float) ($_ENV['TTS_RATE'] ?? 1.04)));
$vPitch = max(-10.0, min(10.0, (float) ($_ENV['TTS_PITCH'] ?? 1.5)));
$vSsml  = \App\Services\Buddy\TtsService::supportsSsml($vVoice) ? 'ssml' : 'txt';
$vHash  = md5($vVoice . '|' . $vRate . '|' . $vPitch . '|' . $vSsml . '|' . $voiceText);

$vDir = \App\Services\Buddy\TtsService::cacheDir();
@mkdir($vDir, 0755, true);
$vFile = $vDir . '/' . $vHash . '.mp3';
file_put_contents($vFile, str_repeat('x', 400));   // >200 bytes = a cache hit

Capsule::table('buddy_settings')->where('user_id', $G2)->update(['extra_json' => null]);  // re-arm
$g2 = $svc->agentGreeting($G2, 'agent', true);
check('a configured voice returns a ready-to-play URL with the greeting',
    ($g2['audio_url'] ?? null) === '/buddy/tts/' . $vHash . '.mp3',
    var_export($g2['audio_url'] ?? null, true));
check('the URL is the shape GET /buddy/tts/{file} will actually serve',
    \App\Services\Buddy\TtsService::safeFile(basename((string) $g2['audio_url'])) !== null);

@unlink($vFile);
unset($_ENV['GOOGLE_TTS_API_KEY']);

// ── voice text parity: server pre-synthesis must match the widget's plain() ──
$speak = \App\Services\Buddy\TtsService::speakable("## Hi **TJ**\n\n* one\n* two\n\nUse `get_my_today` now.");
check('speakable() strips markdown exactly like the widget does',
    $speak === 'Hi TJ one two Use get_my_today now.', $speak);
$clean = "Hey TJ! Fresh page today, let's get one on the board.";
check('a clean greeting passes through untouched (so the cache key matches)',
    \App\Services\Buddy\TtsService::speakable($clean) === $clean);

// ── SSML: the thing that stops her reading a paragraph ──────────────────────
$ssml = \App\Services\Buddy\TtsService::toSsml($clean);
check('SSML is wrapped in <speak>', str_starts_with($ssml, '<speak>') && str_ends_with($ssml, '</speak>'));
check('a beat lands after her name', str_contains($ssml, 'Hey TJ!<break'));
check('and between sentences', substr_count($ssml, '<break') >= 1);
$inject = \App\Services\Buddy\TtsService::toSsml('Careful <break time="9s"/> & co. Next.');
check('caller markup cannot inject SSML',
    str_contains($inject, '&lt;break') && substr_count($inject, '<break') <= 2, $inject);

check('Neural2 takes SSML',  \App\Services\Buddy\TtsService::supportsSsml('en-IN-Neural2-D') === true);
check('Studio takes SSML',   \App\Services\Buddy\TtsService::supportsSsml('en-US-Studio-O') === true);
check('Chirp3-HD does not',  \App\Services\Buddy\TtsService::supportsSsml('en-IN-Chirp3-HD-Achernar') === false);
$_ENV['TTS_SSML'] = 'off';
check('TTS_SSML=off overrides the family rule',
    \App\Services\Buddy\TtsService::supportsSsml('en-IN-Neural2-D') === false);
$_ENV['TTS_SSML'] = 'on';
check('TTS_SSML=on overrides it the other way',
    \App\Services\Buddy\TtsService::supportsSsml('en-IN-Chirp3-HD-Achernar') === true);
unset($_ENV['TTS_SSML']);

echo "\n" . ($fail === 0 ? "ALL {$pass} CHECKS PASSED ✓" : "{$fail} FAILED / {$pass} passed ✗") . "\n";
exit($fail === 0 ? 0 : 1);
