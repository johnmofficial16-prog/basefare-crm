<?php
/**
 * Greeting probe — what does Aisha ACTUALLY say when someone opens the app?
 *
 *   php scripts/buddy_greeting_probe.php
 *   php scripts/buddy_greeting_probe.php --repeat=3    # variance across runs
 *
 * Written 19 Aug 2026. The client's verdict on the old greeting was blunt, and
 * the diagnosis was that the PROMPT was the bug, not the model — it asked for
 * a warm hello, a 3-5 sentence read on the digest, a concrete focus, a week
 * recap AND a get-to-know-you question, and four paragraphs is the correct
 * output for that request. buddy_feed_verify.php can prove the deterministic
 * half (no hold notice, no zero-narration, a one-line fallback) but it runs
 * with the AI layer switched off, so it can never answer the only question
 * that matters: is what she says now actually short and actually warm?
 *
 * This runs the REAL BuddyService::agentGreeting() against an in-memory SQLite
 * database and the REAL Gemini key, across four situations, and prints what
 * came back with a word count and the wall-clock time. It is the ear test the
 * verifier structurally cannot be.
 *
 * Cost: four small calls (~1k tokens each) on the fast lane. Cents.
 */

require __DIR__ . '/../vendor/autoload.php';

use Illuminate\Database\Capsule\Manager as Capsule;
use App\Services\Buddy\BuddyService;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

if (($_ENV['VERTEX_API_KEY'] ?? '') === '') {
    fwrite(STDERR, "No VERTEX_API_KEY in .env — this probe needs the real brain.\n");
    exit(1);
}

$repeat = 1;
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--repeat=')) {
        $repeat = max(1, min(5, (int) substr($a, 9)));
    }
}

// ── schema: only what the greeting path touches ─────────────────────────────
$capsule = new Capsule;
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$capsule->setAsGlobal();
$capsule->bootEloquent();

Capsule::connection()->getPdo()->exec(<<<SQL
CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, role TEXT);
CREATE TABLE buddy_conversations (
  id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, kind TEXT,
  title TEXT, created_at TEXT, last_message_at TEXT);
CREATE TABLE buddy_messages (
  id INTEGER PRIMARY KEY AUTOINCREMENT, conversation_id INTEGER, role TEXT,
  content TEXT, tokens_in INTEGER, tokens_out INTEGER, feedback INTEGER, created_at TEXT);
CREATE TABLE buddy_nudges (
  id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, type TEXT,
  ref_table TEXT, ref_id INTEGER, payload_json TEXT, status TEXT DEFAULT 'pending',
  dedupe_key TEXT UNIQUE, created_at TEXT, delivered_at TEXT,
  outcome TEXT, outcome_at TEXT, outcome_hours REAL);
CREATE TABLE buddy_settings (
  user_id INTEGER PRIMARY KEY, enabled INTEGER DEFAULT 1, voice_enabled INTEGER DEFAULT 1,
  display_name TEXT, onboarded_at TEXT, extra_json TEXT);
CREATE TABLE buddy_agent_facts (
  id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, fact TEXT,
  source TEXT, active INTEGER DEFAULT 1, created_at TEXT, updated_at TEXT);
CREATE TABLE system_config ("key" TEXT PRIMARY KEY, value TEXT);
CREATE TABLE transactions (
  id INTEGER PRIMARY KEY AUTOINCREMENT, agent_id INTEGER, acceptance_id INTEGER,
  status TEXT, total_amount REAL, profit_mco REAL DEFAULT 0, refund_mco_impact REAL DEFAULT 0,
  refund_status TEXT, currency TEXT, pnr TEXT, travel_date TEXT, departure_time TEXT,
  created_at TEXT);
CREATE TABLE etickets (
  id INTEGER PRIMARY KEY AUTOINCREMENT, transaction_id INTEGER, created_at TEXT);
CREATE TABLE acceptance_requests (
  id INTEGER PRIMARY KEY AUTOINCREMENT, agent_id INTEGER, status TEXT,
  is_preauth INTEGER DEFAULT 0, approved_at TEXT);
SQL);
Capsule::table('system_config')->insert(['key' => 'business_day_start_hour', 'value' => '18']);

// The hold is deliberately ON. It was live when the client read the greeting
// he complained about, so a probe that switches it off would be testing an
// easier world than the real one.
Capsule::table('system_config')->insert([
    ['key' => 'performance.hold_enabled', 'value' => '1'],
    ['key' => 'performance.hold_from',    'value' => '2026-08-01 00:00:00'],
    ['key' => 'performance.hold_until',   'value' => '2026-08-09 23:59:59'],
    ['key' => 'performance.hold_notice',  'value' => 'Performance scoring for this month only starts from August 10th.'],
]);

$now = date('Y-m-d H:i:s');
$svc = new BuddyService();

/**
 * What must never appear in a hello. "zero"/"nothing to report" is the
 * deflating open; the rest is the policy recital the client quoted back.
 *
 * The two lists differ on purpose. An agent walking in does not need to hear
 * the word "revenue" at all — that is what the chat is for. An admin opens the
 * app precisely to find out what the floor did, so revenue is fair game there;
 * what is NOT fair game is padding a quiet morning with a list of the things
 * there were none of.
 */
const BANNED_AGENT = ['zero', 'net mco', 'revenue', 'august 10', 'scoring', 'performance scoring',
                      'here\'s where you stand', 'on a totally different note'];
// Note what is NOT banned here: "no sales". An admin opening the app on a
// quiet morning is trying to find out exactly that, and suppressing it makes
// her less useful, not kinder. The agent rule does not transfer — the client's
// complaint was about deflating an AGENT, not about informing a manager. What
// stays banned is the policy recital and the padded list of absences.
const BANNED_ADMIN = ['zero', 'august 10', 'scoring', 'performance scoring',
                      'nothing to report', 'here\'s where you stand',
                      'on a totally different note'];

/**
 * An admin account is usually a role label ("Super Admin"), so the leak to
 * watch for on a first meeting is her reading that off the account and using
 * it as a person's name.
 */
const BANNED_FIRST = ['zero', 'august 10', 'scoring', 'no sales', 'no revenue', 'nothing to report',
                      'super', 'here\'s where you stand', 'on a totally different note'];

function report(string $label, array $g, float $ms, array $banned = BANNED_AGENT, ?string $mustMatch = null): void
{
    $reply = trim((string) ($g['reply'] ?? ''));
    $words = str_word_count($reply);
    $lower = strtolower($reply);

    $hits = [];
    foreach ($banned as $b) {
        if (str_contains($lower, $b)) {
            $hits[] = $b;
        }
    }
    $paras = count(array_filter(preg_split('/\n\s*\n/', $reply)));

    echo "── {$label}\n";
    echo "   {$reply}\n\n";
    printf("   %d words · %d paragraph(s) · %dms · ai:%s · audio:%s\n",
        $words, $paras, (int) $ms,
        ($g['ai'] ?? false) ? 'yes' : 'NO',
        ($g['audio_url'] ?? null) ? 'pre-synthesized' : 'none');

    $verdict = [];
    $verdict[] = ($words <= 45 ? '✓' : '✗') . " length (target ≤35, hard fail >45)";
    $verdict[] = ($paras <= 1 ? '✓' : '✗') . " single paragraph";
    $verdict[] = ($hits === [] ? '✓' : '✗') . " no banned content" . ($hits ? ' — FOUND: ' . implode(', ', $hits) : '');
    if ($mustMatch !== null) {
        $verdict[] = (preg_match($mustMatch, $reply) ? '✓' : '✗') . " asks for their name";
    }
    echo '   ' . implode("\n   ", $verdict) . "\n\n";
}

echo "Greeting probe — model: " . ($_ENV['VERTEX_MODEL'] ?? 'gemini-2.5-flash') . "\n";
echo str_repeat('=', 78) . "\n\n";

for ($run = 1; $run <= $repeat; $run++) {
    if ($repeat > 1) {
        echo "########## RUN {$run} of {$repeat}\n\n";
    }

    // ── 1. the exact situation the client saw: nothing today, nothing pending
    $u = 100 + $run * 10;
    Capsule::table('users')->insert(['id' => $u, 'name' => 'TJ Sharma', 'role' => 'agent']);
    Capsule::table('buddy_settings')->insert(['user_id' => $u, 'display_name' => 'TJ']);
    $t0 = microtime(true);
    $g  = $svc->agentGreeting($u, 'agent', true);
    report('FRESH DAY — no sales, no pipeline, still getting to know them',
        $g, (microtime(true) - $t0) * 1000);

    // ── 2. something genuinely urgent
    $u2 = $u + 1;
    Capsule::table('users')->insert(['id' => $u2, 'name' => 'Priya Nair', 'role' => 'agent']);
    Capsule::table('buddy_settings')->insert(['user_id' => $u2, 'display_name' => 'Priya']);
    Capsule::table('transactions')->insert([
        'agent_id' => $u2, 'status' => 'approved', 'total_amount' => 1200, 'currency' => 'USD',
        'pnr' => 'QR7781', 'travel_date' => date('Y-m-d', time() + 86400), 'departure_time' => '07:15',
        'created_at' => date('Y-m-d H:i:s', time() - 5 * 86400),
    ]);
    $t0 = microtime(true);
    $g  = $svc->agentGreeting($u2, 'agent', true);
    report('URGENT — a flight inside 72h with no e-ticket', $g, (microtime(true) - $t0) * 1000);

    // ── 3. momentum already on the board today
    $u3 = $u + 2;
    Capsule::table('users')->insert(['id' => $u3, 'name' => 'Arun Mehta', 'role' => 'agent']);
    Capsule::table('buddy_settings')->insert(['user_id' => $u3, 'display_name' => 'Arun']);
    foreach ([1, 2] as $i) {
        Capsule::table('transactions')->insert([
            'agent_id' => $u3, 'status' => 'approved', 'total_amount' => 800 * $i,
            'profit_mco' => 90, 'currency' => 'USD', 'pnr' => 'AI900' . $i,
            'created_at' => $now,
        ]);
        Capsule::table('etickets')->insert(['transaction_id' => Capsule::connection()->getPdo()->lastInsertId(), 'created_at' => $now]);
    }
    $t0 = microtime(true);
    $g  = $svc->agentGreeting($u3, 'agent', true);
    report('MOMENTUM — two already on the board today', $g, (microtime(true) - $t0) * 1000);

    // ── 4. the admin, who is a person too
    $a = $u + 3;
    Capsule::table('users')->insert(['id' => $a, 'name' => 'John', 'role' => 'admin']);
    Capsule::table('buddy_settings')->insert(['user_id' => $a, 'display_name' => 'John']);
    $t0 = microtime(true);
    $g  = $svc->adminGreeting($a, true);
    report('ADMIN — the floor briefing (40-word ceiling)', $g, (microtime(true) - $t0) * 1000, BANNED_ADMIN);

    // ── 5. the very first thing a new admin ever hears. No buddy_settings row
    //       at all: no learned name, nothing remembered. She must introduce
    //       herself and ASK — never read the name off the account.
    $a2 = $u + 4;
    Capsule::table('users')->insert(['id' => $a2, 'name' => 'Super Admin', 'role' => 'admin']);
    $t0 = microtime(true);
    $g  = $svc->adminGreeting($a2, true);
    report('ADMIN, FIRST EVER — introduce yourself and ask, never assume',
        $g, (microtime(true) - $t0) * 1000, BANNED_FIRST, '/\?/');
}

echo str_repeat('=', 78) . "\n";
echo "Judge it by ear, not by the ticks. The ticks only catch the failures we\n";
echo "already know the names of.\n";
