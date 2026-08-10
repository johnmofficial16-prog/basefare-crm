<?php
/**
 * test_shield_save.php — prove the total shield's decode + the save pipeline
 * work end-to-end, with a payload full of firewall-tripping characters.
 *
 * IMPORTANT — WHAT THIS DOES AND DOES NOT TEST
 *   It runs on the server, over CLI, BEHIND LiteSpeed. It therefore CANNOT
 *   reproduce the edge 403 (that only fires on real HTTP requests through the
 *   front end). What it DOES prove: the browser's __wafpack encoding, decoded by
 *   the REAL TransactionController methods, feeds the REAL TransactionService and
 *   produces a correct transaction — i.e. the shield did not break saving.
 *
 * Self-cleaning: the test transaction and its notes are deleted at the end.
 * Read-only to everything else. Run:  php scripts/test_shield_save.php
 */

require __DIR__ . '/../vendor/autoload.php';

use Illuminate\Database\Capsule\Manager as Capsule;
use Dotenv\Dotenv;
use App\Models\User;
use App\Models\Transaction;
use App\Services\TransactionService;
use App\Controllers\TransactionController;

Dotenv::createImmutable(__DIR__ . '/..')->load();

$capsule = new Capsule;
$capsule->addConnection([
    'driver'   => 'mysql',
    'host'     => $_ENV['DB_HOST'],
    'database' => $_ENV['DB_DATABASE'],
    'username' => $_ENV['DB_USERNAME'],
    'password' => $_ENV['DB_PASSWORD'],
    'charset'  => 'utf8mb4',
    'collation'=> 'utf8mb4_unicode_ci',
    'prefix'   => '',
]);
$capsule->setAsGlobal();
$capsule->bootEloquent();

echo "\n=== SHIELD + SAVE PIPELINE TEST ===\n";
echo "(runs behind the firewall — proves our code, NOT the 403 fix)\n\n";

// ── pick a real agent to own the test row ───────────────────────────────────
$agent = User::whereIn('role', [User::ROLE_AGENT, User::ROLE_MANAGER])
    ->where('status', 'active')->orderBy('id')->first();
if (!$agent) exit("No active agent/manager to attribute the test to.\n");
echo "Test agent: {$agent->name} (#{$agent->id})\n";

// ── the exact nasty content that trips a WAF ─────────────────────────────────
$evilNotes = "Charged twice -- refund OR credit; per O'Connor. SELECT best seat. 1=1 <script>x</script>";
$evilName  = "Sha'ron O\"Neil-D'Angelo <TEST>";
$pnr       = 'SHIELDTEST';

// ── 1. simulate the browser: build the form field map, pack to __wafpack ─────
$fields = [
    'type'                     => 'new_booking',
    'customer_name'            => $evilName,
    'customer_email'           => 'shieldtest@example.com',
    'customer_phone'           => "+1 (206) 920-7611",
    'pnr'                      => $pnr,
    'airline'                  => 'Delta',
    'total_amount'             => '2904.92',
    'cost_amount'              => '2156.74',
    'profit_mco'               => '748.18',
    'currency'                 => 'USD',
    'payment_method'           => 'credit_card',
    'payment_status'           => 'paid',
    'agent_notes'              => $evilNotes,
    'passengers_json'          => json_encode([['first_name'=>"D'Angelo",'last_name'=>'O"Neil','pax_type'=>'adult']]),
    'type_specific_data_json'  => json_encode(['route'=>'SEA-CDG','note'=>'<b>window</b> & aisle']),
    'fare_breakdown_json'      => json_encode([['label'=>'Base Fare','amount'=>2156.74]]),
    'additional_cards_json'    => 'null',
];
$wire = ['csrf_token' => 'x', '__wafpack' => 'b64:' . base64_encode(json_encode($fields))];
echo "Packed wire body keys: " . implode(', ', array_keys($wire)) . "\n";
echo "__wafpack contains an apostrophe? " . (str_contains($wire['__wafpack'], "'") ? "YES (bad)" : "no") . "\n";
echo "__wafpack contains '<'?        " . (str_contains($wire['__wafpack'], '<') ? "YES (bad)" : "no") . "\n\n";

// ── 2. run the REAL controller decoders (private static → reflection) ────────
$ref = new ReflectionClass(TransactionController::class);
$decWaf = $ref->getMethod('decodeWafPack');    $decWaf->setAccessible(true);
$decShd = $ref->getMethod('decodeShieldedFields'); $decShd->setAccessible(true);
$body = $decShd->invoke(null, $decWaf->invoke(null, $wire));

$okName  = ($body['customer_name'] ?? null) === $evilName;
$okNotes = ($body['agent_notes'] ?? null) === $evilNotes;
echo "Decoded customer_name matches original: " . ($okName ? "OK" : "FAIL") . "\n";
echo "Decoded agent_notes matches original:   " . ($okNotes ? "OK" : "FAIL") . "\n";
if (!$okName || !$okNotes) exit("\nDECODE FAILED — aborting before touching the DB.\n");

// ── 3. assemble $data like store() and drive the REAL service ────────────────
$data = $body;
$data['passengers']         = json_decode($body['passengers_json'] ?? '[]', true) ?: [];
$data['type_specific_data'] = json_decode($body['type_specific_data_json'] ?? 'null', true);
$data['fare_breakdown']     = json_decode($body['fare_breakdown_json'] ?? '[]', true) ?: [];
$data['acceptance_id']      = null;   // standalone — acceptance_id is optional

$svc = new TransactionService();
$txn = $svc->create($data, (int) $agent->id);
echo "\nTransaction created: #{$txn->id}\n";

// ── 4. read it straight back from the DB, verify byte-identical ──────────────
$fresh = Transaction::with('passengers')->find($txn->id);
$dbNotes = $fresh->agent_notes;
$dbName  = $fresh->customer_name;
$dbPax   = $fresh->passengers->first();

echo "  DB customer_name : " . ($dbName === $evilName ? "OK (identical)" : "FAIL: {$dbName}") . "\n";
echo "  DB agent_notes   : " . ($dbNotes === $evilNotes ? "OK (identical)" : "FAIL: {$dbNotes}") . "\n";
echo "  DB pnr           : " . ($fresh->pnr === $pnr ? "OK" : "FAIL: {$fresh->pnr}") . "\n";
echo "  DB profit_mco    : " . ($fresh->profit_mco == 748.18 ? "OK (748.18)" : "FAIL: {$fresh->profit_mco}") . "\n";
echo "  DB passenger     : " . ($dbPax && $dbPax->last_name === 'O"Neil' ? "OK (O\"Neil)" : "FAIL") . "\n";

$allOk = $dbName === $evilName && $dbNotes === $evilNotes && $fresh->pnr === $pnr && $dbPax && $dbPax->last_name === 'O"Neil';

// ── 5. clean up — this is a test row, delete it and its notes ────────────────
Capsule::table('record_notes')->where('entity_type','transaction')->where('entity_id',$txn->id)->delete();
Capsule::table('transaction_passengers')->where('transaction_id',$txn->id)->delete();
Capsule::table('transactions')->where('id',$txn->id)->delete();
echo "\nCleaned up test transaction #{$txn->id} (and its notes/passengers).\n";
$gone = Transaction::find($txn->id) === null;
echo "Verified removed: " . ($gone ? "yes" : "NO — please delete #{$txn->id} manually") . "\n";

echo "\n" . ($allOk && $gone
    ? "RESULT: PASS — the shield decodes and a real save round-trips byte-identical.\n"
    : "RESULT: PROBLEM — see failures above.\n");
echo "Reminder: this cannot exercise the LiteSpeed 403. Cyrus's browser retry is that test.\n\n";
