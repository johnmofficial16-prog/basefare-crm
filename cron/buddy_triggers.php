<?php
/**
 * AI Buddy Trigger Engine (plan §5) — deterministic rules, run every 15 min:
 *
 *   php /home/u501549865/domains/base-fare.com/public_html/crm/cron/buddy_triggers.php
 *
 * SQL decides WHEN the buddy speaks; Gemini only ever decides HOW (later, in
 * chat). This cron writes buddy_nudges rows; Aisha delivers them herself.
 *
 * Idempotency is structural: buddy_nudges.dedupe_key is UNIQUE and every rule
 * builds a stable key (rule:entity), so re-runs and overlapping crons cannot
 * double-nudge. INSERT IGNORE semantics via the duplicate-key catch.
 *
 * Rules (v2 diet, 23 Oct 2026 — see "LESS IS MORE" below):
 *   sale_praise_t2     approved txn >= t2 (last 24h)         one per txn
 *   eticket_lag        approved txn, no e-ticket after 4h    rounds 1–2 (4h, 24h)
 *   acceptance_lag     approved acceptance, no txn after 6h  round 1 only
 *   departure_24h      departs < 24h AND no e-ticket yet     one per txn
 *   dry_spell          no approved sale in 3 days, while     one per agent per 3 quiet days
 *                      clocked in
 *   first_sale_today   first approved sale of the business   one per agent per business day,
 *                      day, below the big-sale line          (praise covers it otherwise)
 *   shift_wrap         open attendance session past the      one per agent per business day
 *                      wind-down threshold (~hour 13)
 *   goal_hit           self-set monthly goal crossed         one per agent per month per metric
 *   goal_pace          >15% behind own goal's pace           max one per agent per week, after the 7th
 *
 * Amounts are US dollars (the CRM trades in USD). Tunables, all via .env with
 * no deploy: BUDDY_PRAISE_T2 (default 2000; T1 retired in v2),
 * BUDDY_WRAP_AFTER_HOURS (13 — the centre's real shift is 6pm→9am, so the
 * wind-down cue lands ~7am; see the rule for why NOT 8).
 *
 * Design notes that keep this honest:
 *  - Lag rules ESCALATE (4h/24h/72h) rather than firing once ever; round 1
 *    keeps the original dedupe key so deploys do not re-nudge current laggards.
 *  - Praise payloads carry personal-best flags computed HERE in SQL, so Aisha
 *    can say "your biggest this month" without ever estimating.
 *  - Goal rules never run for someone who set no goal, and their wording keeps
 *    ownership with the agent — never a management target.
 *  - Every run ends with an unconditional heartbeat (buddy_triggers_ran) so
 *    the maintenance buddy can tell "nothing to say" from "not running".
 *
 * LESS IS MORE (v2, 23 Oct 2026). The usage report showed Aisha sending nine
 * messages for every one an agent sent, most nudges ignored, and every nudge
 * ALSO landing in the notification bell. Research on proactive assistants
 * says the same thing: people stop valuing help that interrupts often. So:
 *  - no bell copy — the nudge arrives once, from Aisha, with its own button;
 *  - "departs tomorrow" only when the e-ticket is actually missing (it used to
 *    fire for every departing booking, ticketed or not);
 *  - an acceptance is chased once, an e-ticket twice (4h, then 24h) — the 72h
 *    third round only ever reached people who had already decided;
 *  - praise for big sales (>= BUDDY_PRAISE_T2) and for the first sale of the
 *    night; the $500 "nice sale" tier made praise background noise;
 *  - dry-spell check-ins every third quiet day, and only while clocked in.
 * The feed also re-checks each nudge at delivery and drops it if the thing is
 * already done (BuddyService::nudgeResolved).
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
 * Insert a nudge. Returns true if it was NEW.
 * The UNIQUE dedupe_key makes this safe under overlap and re-runs.
 *
 * $bellTitle / $bellBody are the one-line summary of the nudge. They used to
 * be mirrored into the notification bell; since v2 they are not (the nudge
 * arrives once, from Aisha) but they stay as the rule's own description.
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
    return true;
}

// ── 1+2. Sale praise (approved sales in the last 24h) ────────────────────────
//
// Thresholds are env-tunable (BUDDY_PRAISE_T1 / BUDDY_PRAISE_T2, US dollars)
// because the right numbers are a tone decision, not a code one, and they
// depend on ticket sizes that drift. Calibration note from live data on
// 18 Aug 2026: the team's average sale was $1,614, so the default t2 of $1,000
// fires for a BELOW-average sale — i.e. the biggest celebration was the common
// case. Raise BUDDY_PRAISE_T2 in .env (no deploy) if "big" should mean big.
// v2: with the t1 tier retired, the default moved to $2,000 — above that
// average — so "big sale" means a big sale. BUDDY_PRAISE_T1 is no longer read.
$praiseT2 = (float) ($_ENV['BUDDY_PRAISE_T2'] ?? 2000);

// v2: only the big tier speaks on its own. A sale between t1 and t2 is
// celebrated as the first of the night (rule 6b) or not at all — the agent
// can always ask her, and she knows every one of them.
$sales = Capsule::table('transactions')
    ->where('status', 'approved')
    ->where('total_amount', '>=', $praiseT2)
    ->where('created_at', '>=', date('Y-m-d H:i:s', time() - 86400))
    ->get(['id', 'agent_id', 'total_amount', 'currency', 'pnr']);

foreach ($sales as $s) {
    $tier = 't2';
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

// ── 3. E-ticket lag: charged txn, no e-ticket, 4h+ since the charge (last 7d) ─
// v2: the CRM only lets an agent create an e-ticket once an admin has marked
// the payment "Charge Successful" (ETicketService's gate). Before that the
// agent can do nothing about it, so the clock starts at the charge
// (gateway_actioned_at), not at the sale — v1 nagged about bookings the agent
// could not ticket yet.
$lagged = Capsule::table('transactions AS t')
    ->leftJoin('etickets AS e', 'e.transaction_id', '=', 't.id')
    ->where('t.status', 'approved')
    ->where('t.gateway_status', 'charge_successful')
    ->whereNull('e.id')
    ->whereRaw('COALESCE(t.gateway_actioned_at, t.created_at) BETWEEN ? AND ?',
        [date('Y-m-d H:i:s', time() - 7 * 86400), date('Y-m-d H:i:s', time() - 4 * 3600)])
    ->get(['t.id', 't.agent_id', 't.pnr', Capsule::raw('COALESCE(t.gateway_actioned_at, t.created_at) AS created_at')]);

// Escalation arithmetic + dedupe-key contract (unit-tested separately).
require __DIR__ . '/buddy_triggers_rules.php';

foreach ($lagged as $t) {
    $ref   = $t->pnr ?: ('#' . $t->id);
    $hrs   = round((time() - strtotime($t->created_at)) / 3600);
    $round = lagRound((float) $hrs, 4);
    if ($round === 0 || $round > 2) {
        continue;   // v2: 4h and 24h; the 72h round only reached people who had decided
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
    if ($round !== 1) {
        continue;   // v2: chased once — after that it is the agent's call, not a reminder
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

// ── 5. Departure < 24h, still without an e-ticket ───────────────────────────
// v2: a ticketed booking flying tomorrow needs nothing from the agent; this
// used to fire for every departure regardless.
$departing = Capsule::table('transactions AS t')
    ->leftJoin('etickets AS e', 'e.transaction_id', '=', 't.id')
    ->where('t.status', 'approved')
    ->whereNull('e.id')
    ->whereNotNull('t.travel_date')
    ->whereBetween('t.travel_date', [date('Y-m-d'), date('Y-m-d', time() + 86400)])
    ->get(['t.id', 't.agent_id', 't.pnr', 't.travel_date', 't.departure_time', 't.gateway_status']);

foreach ($departing as $t) {
    $ref = $t->pnr ?: ('#' . $t->id);
    $created += (int) nudge(
        (int) $t->agent_id,
        'departure_24h',
        "departure_24h:txn:{$t->id}",
        // 'charged' decides what she says: make the e-ticket now, or get an
        // admin to confirm the charge first (the CRM won't allow it before).
        ['ref' => $ref, 'departs' => trim($t->travel_date . ' ' . ($t->departure_time ?? '')), 'no_eticket' => true,
         'charged' => $t->gateway_status === 'charge_successful'],
        '✈️ Departure within 24h, no e-ticket',
        "Booking {$ref} departs " . trim($t->travel_date . ' ' . ($t->departure_time ?? '')) . ' and has no e-ticket yet.',
        (int) $t->id
    );
}

// ── 6. Dry spell: agents at work with no approved sale in 3 days ─────────────
// v2: once every three quiet days (the key carries the 3-day bucket and the
// last sale it counts from, so a new sale re-arms it), and only for people who
// are clocked in right now — a check-in nobody is at the desk to read is just
// a stale message waiting to greet them.
$agents = Capsule::table('users AS u')
    ->join('attendance_sessions AS s', 's.user_id', '=', 'u.id')
    ->whereIn('u.role', ['agent', 'supervisor'])->where('u.status', 'active')->whereNull('u.deleted_at') // team leaders sell too
    ->where('s.status', 'active')
    ->distinct()
    ->pluck('u.id');

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
        'dry_spell:user:' . $agentId . ':' . substr((string) $lastSale, 0, 10) . ':b' . (int) floor($quietDays / 3),
        ['days_since_last_sale' => floor($quietDays), 'last_sale_at' => $lastSale],
        '💪 Your buddy checked in',
        'It has been ' . floor($quietDays) . ' days since your last recorded sale — come talk tactics.'
    );
}

// ── 6b. First sale of the business day, whatever the size ───────────────────
// Live data showed why this is needed: with the praise floor at $500 and a lot
// of ~$350 bookings, a perfectly good day could pass with Aisha saying nothing
// at all. Getting on the board is worth a word regardless of the number.
//
// Only fires when that first sale is BELOW the big-sale line — above it, the
// praise rule already speaks, and two messages about one booking is clutter.
[$bdStart, $bdEnd] = \App\Services\ShiftService::businessDayBounds();
$bdKey = substr((string) $bdStart, 0, 10);

$firstPerAgent = Capsule::table('transactions')
    ->where('status', 'approved')
    ->whereBetween('created_at', [(string) $bdStart, (string) $bdEnd])
    ->selectRaw('agent_id, MIN(id) AS first_id')
    ->groupBy('agent_id')
    ->get();

if ($firstPerAgent->isNotEmpty()) {
    $firstRows = Capsule::table('transactions')
        ->whereIn('id', $firstPerAgent->pluck('first_id')->all())
        ->get(['id', 'agent_id', 'pnr', 'total_amount'])
        ->keyBy('id');

    foreach ($firstPerAgent as $fp) {
        $t = $firstRows[$fp->first_id] ?? null;
        if ($t === null || (float) $t->total_amount >= $praiseT2) {
            continue;                       // praise rule covers this one
        }
        if (alreadyNudged("first_sale:user:{$t->agent_id}:{$bdKey}")) {
            continue;
        }
        $created += (int) nudge(
            (int) $t->agent_id,
            'first_sale_today',
            "first_sale:user:{$t->agent_id}:{$bdKey}",
            ['ref' => $t->pnr ?: ('#' . $t->id), 'amount' => (float) $t->total_amount, 'currency' => 'USD'],
            '🙌 On the board!',
            'Your first sale of the day is in.',
            (int) $t->id
        );
    }
}

// ── 6c. Shift wrap: close the daily arc ──────────────────────────────────────
// Aisha greets at the start of the shift and reacts during it; this is the
// goodbye. Fired while the agent is still AT the desk — an open attendance
// session that has run 8+ hours — because a wrap delivered after clock-out is
// never seen (and goes stale by the next login).
//
// scheduled_end is deliberately NOT the signal: the 24/7 roster gives everyone
// 18:00→18:00 paper shifts, so "time worked" is the only honest wind-down cue.
// Numbers are a snapshot ("so far today"); the feed TTL keeps them fresh.
//
// Threshold: the centre's real overnight shift runs 6pm→9am (~15h), so the
// wind-down starts around hour 13 (~7am), NOT the office-job 8 — at hour 8
// it is ~2am and "go rest, you've earned it" lands mid-shift, seven hours
// early. Env-tunable because shift patterns change (BUDDY_WRAP_AFTER_HOURS).
// An agent who leaves before the threshold gets no wrap; a missed goodbye
// beats one that tells them to go home while they're still selling.
$wrapAfter = (float) ($_ENV['BUDDY_WRAP_AFTER_HOURS'] ?? 13);

$wrapEligible = Capsule::table('attendance_sessions AS s')
    ->join('users AS u', 'u.id', '=', 's.user_id')
    ->whereIn('u.role', ['agent', 'supervisor'])->where('u.status', 'active')->whereNull('u.deleted_at')
    ->whereNull('s.clock_out')
    ->where('s.clock_in', '<=', date('Y-m-d H:i:s', (int) (time() - $wrapAfter * 3600)))
    ->get(['s.user_id', 's.clock_in']);

foreach ($wrapEligible as $w) {
    $wrapKey = "shift_wrap:user:{$w->user_id}:{$bdKey}";
    if (alreadyNudged($wrapKey)) {
        continue;                       // one wrap per business day
    }

    $stats = Capsule::table('transactions')
        ->where('agent_id', $w->user_id)->where('status', 'approved')
        ->whereBetween('created_at', [(string) $bdStart, (string) $bdEnd])
        ->selectRaw('COUNT(*) AS n, COALESCE(SUM(total_amount),0) AS revenue,
                     COALESCE(SUM(profit_mco - refund_mco_impact),0) AS net_mco,
                     MAX(total_amount) AS best')
        ->first();

    $openEtix = Capsule::table('transactions AS t')
        ->leftJoin('etickets AS e', 'e.transaction_id', '=', 't.id')
        ->where('t.agent_id', $w->user_id)->where('t.status', 'approved')
        ->where('t.gateway_status', 'charge_successful')   // ones they can actually ticket
        ->where('t.created_at', '>=', date('Y-m-d H:i:s', time() - 14 * 86400))
        ->whereNull('e.id')->count();

    $created += (int) nudge(
        (int) $w->user_id,
        'shift_wrap',
        $wrapKey,
        [
            'hours'     => round((time() - strtotime((string) $w->clock_in)) / 3600, 1),
            'sales'     => (int) $stats->n,
            'revenue'   => round((float) $stats->revenue, 2),
            'net_mco'   => round((float) $stats->net_mco, 2),
            'best'      => $stats->best !== null ? round((float) $stats->best, 2) : null,
            'open_etix' => $openEtix,
        ],
        '🌙 Day wrap',
        'Your buddy has a read on your day — come see it before you head off.'
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
// Joined to users so a deactivated or deleted account cannot keep collecting
// goal nudges nobody will ever read.
$goalRows = Capsule::table('buddy_settings AS bs')
    ->join('users AS u', 'u.id', '=', 'bs.user_id')
    ->whereNotNull('bs.extra_json')
    ->where('u.status', 'active')
    ->whereNull('u.deleted_at')
    ->get(['bs.user_id', 'bs.extra_json']);

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

// ── 8. OUTCOME PASS — did the nudges work? ───────────────────────────────────
// For every delivered lag/dry-spell nudge with no outcome yet: check whether
// the thing it asked for actually happened, and how long after delivery. This
// is the learning loop's evidence layer — get_my_patterns turns it into
// "after I flag an e-ticket you close it in Xh", and it is the only honest
// basis for ever claiming Aisha improves productivity.
//
// Nudges still open after 7 days are closed as 'unresolved' so the statistics
// stabilise instead of dangling forever. Fail-soft per row; capped per run.
$outcomes = 0;
try {
    $open = Capsule::table('buddy_nudges')
        ->whereIn('type', ['eticket_lag', 'acceptance_lag', 'dry_spell'])
        ->whereIn('status', ['delivered', 'seen', 'done'])
        ->whereNull('outcome')
        ->whereNotNull('delivered_at')
        ->orderBy('id')
        ->limit(300)
        ->get(['id', 'user_id', 'type', 'ref_id', 'dedupe_key', 'delivered_at']);

    foreach ($open as $n) {
        $resolvedAt = null;

        if ($n->type === 'eticket_lag') {
            $txnId = $n->ref_id ?: lagEntityId((string) $n->dedupe_key);
            if ($txnId > 0) {
                $resolvedAt = Capsule::table('etickets')
                    ->where('transaction_id', $txnId)->min('created_at');
            }
        } elseif ($n->type === 'acceptance_lag') {
            $accId = lagEntityId((string) $n->dedupe_key);
            if ($accId > 0) {
                $resolvedAt = Capsule::table('transactions')
                    ->where('acceptance_id', $accId)->where('status', '!=', 'voided')
                    ->min('created_at');
            }
        } elseif ($n->type === 'dry_spell') {
            $resolvedAt = Capsule::table('transactions')
                ->where('agent_id', $n->user_id)->where('status', 'approved')
                ->where('created_at', '>', (string) $n->delivered_at)
                ->min('created_at');
        }

        $deliveredTs = strtotime((string) $n->delivered_at);
        if ($resolvedAt !== null) {
            $hours = max(0, round((strtotime((string) $resolvedAt) - $deliveredTs) / 3600, 1));
            Capsule::table('buddy_nudges')->where('id', $n->id)->update([
                'outcome'       => 'resolved',
                'outcome_at'    => (string) $resolvedAt,
                'outcome_hours' => $hours,
            ]);
            $outcomes++;
        } elseif (time() - $deliveredTs > 7 * 86400) {
            Capsule::table('buddy_nudges')->where('id', $n->id)->update(['outcome' => 'unresolved']);
            $outcomes++;
        }
    }
} catch (\Throwable $e) {
    error_log('[buddy_triggers] outcome pass failed: ' . $e->getMessage());
}
echo "  outcomes recorded: {$outcomes}\n";

echo "  nudges created: {$created}\n";

/**
 * Heartbeat — written on EVERY run, including runs that create nothing.
 *
 * This exists because of a real incident: this cron was believed to be
 * registered in hPanel for two days and was not. Nothing noticed, because the
 * only evidence a trigger run leaves is the nudges it creates — and "no nudges"
 * is indistinguishable from "never ran". The whole Aisha layer was silently
 * dead with a green-looking system.
 *
 * A heartbeat that only fires when there is work to report is not a heartbeat.
 * get_cron_health() reads this and flags silence over an hour as overdue.
 */
try {
    Capsule::table('activity_log')->insert([
        'user_id'     => null,
        'action'      => 'buddy_triggers_ran',
        'entity_type' => 'buddy_nudges',
        'entity_id'   => null,
        'details'     => json_encode(['created' => $created]),
        'ip_address'  => null,
        'created_at'  => date('Y-m-d H:i:s'),
    ]);
} catch (\Throwable $e) {
    error_log('[buddy_triggers] heartbeat failed: ' . $e->getMessage());
}

echo '[' . date('Y-m-d H:i:s') . "] Done.\n";
exit(0);
