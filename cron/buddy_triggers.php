<?php
/**
 * AI Buddy Trigger Engine (plan §5) — deterministic rules, run every 15 min:
 *
 *   php /home/u501549865/domains/base-fare.com/public_html/crm/cron/buddy_triggers.php
 *
 * SQL decides WHEN the buddy speaks; Gemini only ever decides HOW (later, in
 * chat). This cron writes buddy_nudges rows and mirrors each one into the
 * existing notification bell.
 *
 * Idempotency is structural: buddy_nudges.dedupe_key is UNIQUE and every rule
 * builds a stable key (rule:entity), so re-runs and overlapping crons cannot
 * double-nudge. INSERT IGNORE semantics via the duplicate-key catch.
 *
 * Rules (v1):
 *   sale_praise_t1   approved txn >= $500 (last 24h)     one per txn
 *   sale_praise_t2   approved txn >= $1000 (last 24h)    one per txn (suppresses t1)
 *   eticket_lag      approved txn, no e-ticket after 4h  one per txn
 *   acceptance_lag   approved acceptance, no txn after 6h  one per acceptance
 *   departure_24h    booking departs < 24h               one per txn
 *   dry_spell        no approved sale in 3 days          one per agent per quiet-streak day
 */

require __DIR__ . '/../vendor/autoload.php';

use Illuminate\Database\Capsule\Manager as Capsule;
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'Asia/Kolkata');

$capsule = new Capsule;
$capsule->addConnection([
    'driver'   => 'mysql',
    'host'     => $_ENV['DB_HOST'],
    'database' => $_ENV['DB_DATABASE'],
    'username' => $_ENV['DB_USERNAME'],
    'password' => $_ENV['DB_PASSWORD'],
    'charset'  => 'utf8mb4',
    'collation'=> 'utf8mb4_unicode_ci',
]);
$capsule->setAsGlobal();
$capsule->bootEloquent();

echo '[' . date('Y-m-d H:i:s') . "] Buddy trigger engine starting...\n";

$created = 0;

/**
 * Insert a nudge + bell notification. Returns true if it was NEW.
 * The UNIQUE dedupe_key makes this safe under overlap and re-runs.
 */
function nudge(int $userId, string $type, string $dedupeKey, array $payload, string $bellTitle, string $bellBody, ?int $txnId = null): bool
{
    try {
        Capsule::table('buddy_nudges')->insert([
            'user_id'      => $userId,
            'type'         => $type,
            'ref_table'    => $txnId !== null ? 'transactions' : null,
            'ref_id'       => $txnId,
            'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'status'       => 'pending',
            'dedupe_key'   => substr($dedupeKey, 0, 120),
            'created_at'   => date('Y-m-d H:i:s'),
        ]);
    } catch (\Throwable $e) {
        if (str_contains($e->getMessage(), 'Duplicate entry')) {
            return false;   // already nudged — the whole point of the dedupe key
        }
        error_log('[buddy_triggers] nudge insert failed: ' . $e->getMessage());
        return false;
    }

    // Mirror into the existing bell (best-effort; nudge row is the source of truth).
    try {
        Capsule::table('notifications')->insert([
            'user_id'        => $userId,
            'type'           => 'buddy_nudge',
            'transaction_id' => $txnId,
            'title'          => $bellTitle,
            'body'           => $bellBody,
            'link'           => '/buddy',
            'read_at'        => null,
            'created_at'     => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ]);
    } catch (\Throwable $e) {
        error_log('[buddy_triggers] bell mirror failed: ' . $e->getMessage());
    }
    return true;
}

// ── 1+2. Sale praise (approved sales in the last 24h) ────────────────────────
$sales = Capsule::table('transactions')
    ->where('status', 'approved')
    ->where('total_amount', '>=', 500)
    ->where('created_at', '>=', date('Y-m-d H:i:s', time() - 86400))
    ->get(['id', 'agent_id', 'total_amount', 'currency', 'pnr']);

foreach ($sales as $s) {
    $tier = ((float) $s->total_amount >= 1000) ? 't2' : 't1';
    $ref  = $s->pnr ?: ('#' . $s->id);
    $created += (int) nudge(
        (int) $s->agent_id,
        'sale_praise_' . $tier,
        "sale_praise:txn:{$s->id}",
        ['ref' => $ref, 'amount' => (float) $s->total_amount, 'currency' => $s->currency, 'tier' => $tier],
        $tier === 't2' ? '🎉 Big sale!' : '👏 Nice sale!',
        "Your buddy noticed {$ref} — " . $s->currency . ' ' . number_format((float) $s->total_amount, 2) . '. Come get your praise.',
        (int) $s->id
    );
}

// ── 3. E-ticket lag: approved txn, no e-ticket, older than 4h (last 7d) ──────
$lagged = Capsule::table('transactions AS t')
    ->leftJoin('etickets AS e', 'e.transaction_id', '=', 't.id')
    ->where('t.status', 'approved')
    ->whereNull('e.id')
    ->whereBetween('t.created_at', [date('Y-m-d H:i:s', time() - 7 * 86400), date('Y-m-d H:i:s', time() - 4 * 3600)])
    ->get(['t.id', 't.agent_id', 't.pnr', 't.created_at']);

foreach ($lagged as $t) {
    $ref = $t->pnr ?: ('#' . $t->id);
    $hrs = round((time() - strtotime($t->created_at)) / 3600);
    $created += (int) nudge(
        (int) $t->agent_id,
        'eticket_lag',
        "eticket_lag:txn:{$t->id}",
        ['ref' => $ref, 'waiting_hours' => $hrs],
        '🎫 E-ticket pending',
        "Booking {$ref} was recorded {$hrs}h ago and still has no e-ticket.",
        (int) $t->id
    );
}

// ── 4. Acceptance lag: approved, no transaction after 6h (last 7d) ───────────
$accLag = Capsule::table('acceptance_requests AS a')
    ->leftJoin('transactions AS x', function ($j) {
        $j->on('x.acceptance_id', '=', 'a.id')->where('x.status', '!=', 'voided');
    })
    ->where('a.status', 'APPROVED')
    ->where('a.is_preauth', 0)
    ->whereNull('x.id')
    ->whereBetween('a.approved_at', [date('Y-m-d H:i:s', time() - 7 * 86400), date('Y-m-d H:i:s', time() - 6 * 3600)])
    ->get(['a.id', 'a.agent_id', 'a.approved_at']);

foreach ($accLag as $a) {
    $hrs = round((time() - strtotime($a->approved_at)) / 3600);
    $created += (int) nudge(
        (int) $a->agent_id,
        'acceptance_lag',
        "acceptance_lag:acc:{$a->id}",
        ['acceptance' => '#' . $a->id, 'waiting_hours' => $hrs],
        '⏳ Acceptance not converted',
        "Acceptance #{$a->id} was approved {$hrs}h ago and has no transaction yet."
    );
}

// ── 5. Departure < 24h ───────────────────────────────────────────────────────
$departing = Capsule::table('transactions')
    ->where('status', 'approved')
    ->whereNotNull('travel_date')
    ->whereBetween('travel_date', [date('Y-m-d'), date('Y-m-d', time() + 86400)])
    ->get(['id', 'agent_id', 'pnr', 'travel_date', 'departure_time']);

foreach ($departing as $t) {
    $ref = $t->pnr ?: ('#' . $t->id);
    $created += (int) nudge(
        (int) $t->agent_id,
        'departure_24h',
        "departure_24h:txn:{$t->id}",
        ['ref' => $ref, 'departs' => trim($t->travel_date . ' ' . ($t->departure_time ?? ''))],
        '✈️ Departure within 24h',
        "Booking {$ref} departs " . trim($t->travel_date . ' ' . ($t->departure_time ?? '')) . '. Boarding pass / check-in follow-up time.',
        (int) $t->id
    );
}

// ── 6. Dry spell: active agents with no approved sale in 3 days ──────────────
// Dedupe key includes today's date → at most one per agent per day, and it
// naturally re-arms after a sale (the rule stops matching).
$agents = Capsule::table('users')
    ->where('role', 'agent')->where('status', 'active')->whereNull('deleted_at')
    ->pluck('id');

foreach ($agents as $agentId) {
    $lastSale = Capsule::table('transactions')
        ->where('agent_id', $agentId)->where('status', 'approved')
        ->max('created_at');

    // Never sold anything ever → onboarding, not a slump. Skip.
    if ($lastSale === null) {
        continue;
    }
    $quietDays = (time() - strtotime($lastSale)) / 86400;
    if ($quietDays < 3) {
        continue;
    }

    $created += (int) nudge(
        (int) $agentId,
        'dry_spell',
        'dry_spell:user:' . $agentId . ':' . date('Y-m-d'),
        ['days_since_last_sale' => floor($quietDays), 'last_sale_at' => $lastSale],
        '💪 Your buddy checked in',
        'It has been ' . floor($quietDays) . ' days since your last recorded sale — come talk tactics.'
    );
}

echo "  nudges created: {$created}\n";
echo '[' . date('Y-m-d H:i:s') . "] Done.\n";
exit(0);
