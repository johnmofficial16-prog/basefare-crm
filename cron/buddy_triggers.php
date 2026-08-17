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
 *   eticket_lag      approved txn, no e-ticket after 4h    one per txn per escalation round
 *   acceptance_lag   approved acceptance, no txn after 6h  one per acceptance per round
 *   departure_24h    booking departs < 24h               one per txn
 *   dry_spell        no approved sale in 3 days          one per agent per quiet-streak day
 *
 * (Amounts are US dollars — the CRM trades in USD, so the thresholds are flat.)
 *
 * v2 (P5 companion polish):
 *  - Lag rules ESCALATE instead of firing once ever. Round 1 keeps the original
 *    dedupe key (so deploying this does not re-nudge every currently-lagging
 *    booking); rounds 2+ add a suffix and re-arm once per tier.
 *  - Praise payloads carry personal-best flags computed HERE in SQL, so Aisha
 *    can say "your biggest this month" without ever estimating.
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
 * Has this exact (rule, entity) already been nudged?
 *
 * The UNIQUE dedupe_key is what actually guarantees idempotency — this is a
 * cheap pre-check so a rule can skip EXPENSIVE payload work it would only
 * throw away on the duplicate-key catch. Never rely on it for correctness:
 * between this read and the insert another run could win, which is exactly
 * what the unique index is there to settle.
 */
function alreadyNudged(string $dedupeKey): bool
{
    try {
        return Capsule::table('buddy_nudges')
            ->where('dedupe_key', substr($dedupeKey, 0, 120))->exists();
    } catch (\Throwable $e) {
        return false;   // on doubt, do the work — the insert still dedupes
    }
}

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
    $amt  = (float) $s->total_amount;

    // The praise window is 24h but this cron runs every 15 minutes, so each
    // sale is revisited ~96 times and all but the first insert is discarded by
    // the UNIQUE dedupe key. Checking the key first turns 95 of those visits
    // into one indexed lookup instead of two aggregate scans over the agent's
    // whole transaction history (transactions has idx_agent_id only — there is
    // no composite (agent_id, status) index to lean on).
    if (alreadyNudged("sale_praise:txn:{$s->id}")) {
        continue;
    }

    // Personal bests — decided in SQL so Aisha can celebrate a record without
    // ever estimating one. Excludes this txn so ties don't count as a new best.
    $bestMonth = Capsule::table('transactions')
        ->where('agent_id', $s->agent_id)->where('status', 'approved')
        ->where('created_at', '>=', date('Y-m-01 00:00:00'))
        ->where('id', '!=', $s->id)
        ->max('total_amount');
    $bestEver = Capsule::table('transactions')
        ->where('agent_id', $s->agent_id)->where('status', 'approved')
        ->where('id', '!=', $s->id)
        ->max('total_amount');

    $created += (int) nudge(
        (int) $s->agent_id,
        'sale_praise_' . $tier,
        "sale_praise:txn:{$s->id}",
        [
            'ref'        => $ref,
            'amount'     => $amt,
            'currency'   => $s->currency,
            'tier'       => $tier,
            'best_month' => $bestMonth === null || $amt > (float) $bestMonth,
            'best_ever'  => $bestEver === null || $amt > (float) $bestEver,
        ],
        $tier === 't2' ? '🎉 Big sale!' : '👏 Nice sale!',
        "Your buddy noticed {$ref} — $" . number_format($amt, 2) . '. Come get your praise.',
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

// Escalation arithmetic + dedupe-key contract (unit-tested separately).
require __DIR__ . '/buddy_triggers_rules.php';

foreach ($lagged as $t) {
    $ref   = $t->pnr ?: ('#' . $t->id);
    $hrs   = round((time() - strtotime($t->created_at)) / 3600);
    $round = lagRound((float) $hrs, 4);
    if ($round === 0) {
        continue;
    }
    $created += (int) nudge(
        (int) $t->agent_id,
        'eticket_lag',
        lagKey('eticket_lag', 'txn', (int) $t->id, $round),
        ['ref' => $ref, 'waiting_hours' => $hrs, 'round' => $round],
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
    $hrs   = round((time() - strtotime($a->approved_at)) / 3600);
    $round = lagRound((float) $hrs, 6);
    if ($round === 0) {
        continue;
    }
    $created += (int) nudge(
        (int) $a->agent_id,
        'acceptance_lag',
        lagKey('acceptance_lag', 'acc', (int) $a->id, $round),
        ['acceptance' => '#' . $a->id, 'waiting_hours' => $hrs, 'round' => $round],
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

// ── 7. Self-set monthly goals: celebrate the hit, check in when drifting ─────
// Only for agents who actually told Aisha a goal (buddy_settings.extra_json).
// Never invents a target, and never fires for someone who set none.
//
// goal_hit  — once per agent per month per metric, the moment they cross it.
// goal_pace — at most once a week, and only when meaningfully behind (>15% off
//             the pace the month implies). Being slightly behind on a Tuesday
//             is not news; it is nagging.
$goalRows = Capsule::table('buddy_settings')
    ->whereNotNull('extra_json')
    ->get(['user_id', 'extra_json']);

$monthKey   = date('Y-m');
$weekKey    = date('oW');                 // ISO year+week — one pace check a week
$daysInMth  = (int) date('t');
$dayOfMth   = (int) date('j');
$elapsed    = $dayOfMth / $daysInMth;

foreach ($goalRows as $g) {
    $extra = json_decode((string) $g->extra_json, true) ?: [];
    $goal  = $extra['goal'] ?? null;
    if (!is_array($goal) || (!isset($goal['sales']) && !isset($goal['revenue']))) {
        continue;
    }

    $act = Capsule::table('transactions')
        ->where('agent_id', $g->user_id)
        ->where('status', 'approved')
        ->whereBetween('created_at', [date('Y-m-01 00:00:00'), date('Y-m-d H:i:s')])
        ->selectRaw('COUNT(*) AS n, COALESCE(SUM(total_amount),0) AS revenue')
        ->first();

    $metrics = [];
    if (isset($goal['sales'])) {
        $metrics['sales'] = [(float) $act->n, (float) $goal['sales']];
    }
    if (isset($goal['revenue'])) {
        $metrics['revenue'] = [(float) $act->revenue, (float) $goal['revenue']];
    }

    foreach ($metrics as $metric => [$done, $target]) {
        if ($target <= 0) {
            continue;
        }

        if ($done >= $target) {
            $created += (int) nudge(
                (int) $g->user_id,
                'goal_hit',
                "goal_hit:user:{$g->user_id}:{$monthKey}:{$metric}",
                ['metric' => $metric, 'target' => $target, 'done' => $done],
                '🏆 Goal reached!',
                'You hit the monthly ' . $metric . ' goal you set yourself. Come celebrate.'
            );
            continue;   // hit beats pace — never nag about a goal already met
        }

        // Behind pace, with enough month gone for that to mean anything.
        $expected = $target * $elapsed;
        if ($dayOfMth >= 7 && $expected > 0 && $done < $expected * 0.85) {
            $daysLeft = max(1, $daysInMth - $dayOfMth);
            $created += (int) nudge(
                (int) $g->user_id,
                'goal_pace',
                "goal_pace:user:{$g->user_id}:{$monthKey}:{$metric}:w{$weekKey}",
                [
                    'metric'    => $metric,
                    'target'    => $target,
                    'done'      => $done,
                    'days_left' => $daysLeft,
                    'per_day'   => round(($target - $done) / $daysLeft, 2),
                ],
                '🎯 Goal check-in',
                'A nudge about the monthly ' . $metric . ' goal you set yourself.'
            );
        }
    }
}

echo "  nudges created: {$created}\n";
echo '[' . date('Y-m-d H:i:s') . "] Done.\n";
exit(0);
