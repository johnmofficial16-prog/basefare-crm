<?php

namespace App\Services\Buddy;

use App\Services\PerformanceHold;
use App\Services\ShiftService;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * AgentTools — the agent buddy's window into THE AGENT'S OWN numbers.
 *
 * Wall 1 in practice (AI_BUDDY_PLAN.md §1): every handler closes over the
 * $userId captured at registry construction. NO tool accepts a user/agent
 * parameter from the model — the capability to look at anyone else does not
 * exist in this registry.
 *
 * Wall 2: everything returned is aggregates + booking references (PNR/ids are
 * internal refs, explicitly allowed). No customer names, emails, phones, card
 * fields, or free-text notes — those columns are never selected.
 *
 * Time-window rule (plan §13.4 — the buddy must never disagree with the
 * screen the agent is looking at):
 *   - "today"  = ShiftService::businessDayBounds()  → matches the dashboard
 *   - "month"  = calendar month                     → matches Performance
 *
 * Performance-hold compliance: month/recent-sales queries run through
 * PerformanceHold::apply() with the agent's role, exactly like the
 * Performance tab — the buddy cannot become a side channel around a hold.
 */
class AgentTools
{
    public static function registry(int $userId, string $role): BuddyToolRegistry
    {
        $r = new BuddyToolRegistry($userId);

        $r->register(
            'get_my_today',
            'The agent\'s current business day (6 PM to 6 PM shift window, same as the dashboard): approved sales count, revenue, and net MCO so far.',
            [],
            fn() => self::today($userId)
        );

        $r->register(
            'get_my_month_summary',
            'Calendar-month summary (same window as the Performance tab): sales, revenue, gross and net MCO, refunds — for this month and last month for comparison.',
            [],
            fn() => self::monthSummary($userId, $role)
        );

        $r->register(
            'get_my_recent_sales',
            'The agent\'s own recent transactions (newest first): date, amount, currency, booking reference, status, refund status. No customer details.',
            [
                'type'       => 'object',
                'properties' => [
                    'days' => ['type' => 'integer', 'description' => 'Look-back window in days, 1–31. Default 7.'],
                ],
            ],
            fn(array $a) => self::recentSales($userId, $role, (int) ($a['days'] ?? 7))
        );

        $r->register(
            'get_my_pipeline',
            'Unfinished flow steps the agent owns: approved acceptances still waiting for a transaction, and approved transactions still waiting for an e-ticket. Refs and ages only.',
            [],
            fn() => self::pipeline($userId)
        );

        $r->register(
            'get_my_upcoming_departures',
            'The agent\'s bookings departing in the next 72 hours (booking ref + departure time + e-ticket state). Useful for boarding-pass and pre-departure follow-ups.',
            [],
            fn() => self::upcomingDepartures($userId)
        );

        $r->register(
            'get_my_nudges',
            'Things the buddy raised with this agent recently (last 48h): sale praise, dry spell, e-ticket lag, departure reminders. Each carries "unread": true if the agent has not opened the chat since it was raised. Use this to follow up on something you mentioned.',
            [],
            fn() => self::nudges($userId)
        );

        $r->register(
            'set_my_goal',
            "Save, update, or clear THIS AGENT'S OWN monthly goal — the target they set for themselves and told you about. "
            . 'Use it when they say something like "I want to hit 30 sales this month". Pass sales, revenue, or both. '
            . 'Pass clear:true if they ask you to forget or drop their goal — always honour that immediately and without '
            . 'pushing back; it is theirs to set and theirs to abandon. '
            . 'This is personal and self-chosen: it is NOT a target set by management and must never be described as one.',
            [
                'type'       => 'object',
                'properties' => [
                    'sales'   => ['type' => 'integer', 'description' => 'Target number of approved sales this month, 1–999.'],
                    'revenue' => ['type' => 'number',  'description' => 'Target revenue in US dollars this month.'],
                    'clear'   => ['type' => 'boolean', 'description' => 'True to forget their goal entirely.'],
                ],
            ],
            fn(array $a) => self::setGoal($userId, $a)
        );

        $r->register(
            'get_my_goal_progress',
            "Where the agent stands against the monthly goal THEY set themselves: the goal, what they have done so far "
            . 'this month, how far through the month it is, the pace they would need, and whether they are on track. '
            . 'Returns has_goal:false if they never set one — in that case offer to set one rather than inventing a target.',
            [],
            fn() => self::goalProgress($userId, $role)
        );

        $r->register(
            'remember_fact',
            'Save a short personal fact the agent shared (their preferred name, goals, what motivates them) to the buddy\'s permanent memory for this agent. Use during onboarding and whenever the agent shares something durable.',
            [
                'type'       => 'object',
                'properties' => [
                    'fact' => ['type' => 'string', 'description' => 'One concise sentence. Max 300 chars.'],
                ],
                'required'   => ['fact'],
            ],
            fn(array $a) => self::rememberFact($userId, (string) ($a['fact'] ?? ''))
        );

        return $r;
    }

    /** Facts injected into the persona each turn (onboarding memory). */
    public static function facts(int $userId): array
    {
        return DB::table('buddy_agent_facts')
            ->where('user_id', $userId)->where('active', 1)
            ->orderBy('id')->limit(20)->pluck('fact')->all();
    }

    // =========================================================================
    // HANDLERS
    // =========================================================================

    private static function today(int $userId): array
    {
        [$from, $to] = ShiftService::businessDayBounds();

        $row = DB::table('transactions')
            ->where('agent_id', $userId)
            ->whereBetween('created_at', [$from, $to])
            ->where('status', 'approved')
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(total_amount),0) AS revenue,
                         COALESCE(SUM(profit_mco - refund_mco_impact),0) AS net_mco,
                         MAX(currency) AS currency')
            ->first();

        return [
            'window'   => ['from' => (string) $from, 'to' => (string) $to, 'note' => 'business day, matches dashboard'],
            'sales'    => (int) $row->n,
            'revenue'  => round((float) $row->revenue, 2),
            'net_mco'  => round((float) $row->net_mco, 2),
            'currency' => $row->currency ?? 'USD',
        ];
    }

    private static function monthSummary(int $userId, string $role): array
    {
        $month = static function (string $from, string $to) use ($userId, $role): array {
            $q = DB::table('transactions')
                ->where('agent_id', $userId)
                ->whereBetween('created_at', [$from, $to])
                ->where('status', 'approved');
            $q = PerformanceHold::apply($q, 'created_at', $role);
            $row = $q->selectRaw('COUNT(*) AS n, COALESCE(SUM(total_amount),0) AS revenue,
                                  COALESCE(SUM(profit_mco),0) AS gross_mco,
                                  COALESCE(SUM(profit_mco - refund_mco_impact),0) AS net_mco,
                                  SUM(refund_status IS NOT NULL AND refund_status != "none") AS refunds')
                ->first();
            return [
                'sales'     => (int) $row->n,
                'revenue'   => round((float) $row->revenue, 2),
                'gross_mco' => round((float) $row->gross_mco, 2),
                'net_mco'   => round((float) $row->net_mco, 2),
                'refunds'   => (int) $row->refunds,
            ];
        };

        $thisFrom = date('Y-m-01 00:00:00');
        $thisTo   = date('Y-m-d H:i:s');
        $lastFrom = date('Y-m-01 00:00:00', strtotime('first day of last month'));
        $lastTo   = date('Y-m-t 23:59:59', strtotime('last day of last month'));

        $out = [
            'window_note' => 'calendar months, matches the Performance tab',
            'this_month'  => $month($thisFrom, $thisTo),
            'last_month'  => $month($lastFrom, $lastTo),
        ];
        $notice = PerformanceHold::notice($role);
        if ($notice !== null) {
            $out['hold_notice'] = $notice;   // buddy must repeat this honestly
        }
        return $out;
    }

    private static function recentSales(int $userId, string $role, int $days): array
    {
        $days = max(1, min(31, $days));
        $q = DB::table('transactions')
            ->where('agent_id', $userId)
            ->where('created_at', '>=', date('Y-m-d H:i:s', time() - $days * 86400))
            ->orderByDesc('id')
            ->limit(15);
        $q = PerformanceHold::apply($q, 'created_at', $role);

        $rows = $q->get(['id', 'pnr', 'type', 'status', 'refund_status', 'total_amount', 'currency', 'created_at'])
            ->map(fn($t) => [
                'ref'      => $t->pnr ?: ('#' . $t->id),
                'type'     => $t->type,
                'status'   => $t->status,
                'refund'   => $t->refund_status ?? 'none',
                'amount'   => round((float) $t->total_amount, 2),
                'currency' => $t->currency,
                'at'       => $t->created_at,
            ])->all();

        return ['days' => $days, 'sales' => $rows];
    }

    private static function pipeline(int $userId): array
    {
        // Approved full acceptances with no (non-voided) transaction built on them.
        $accs = DB::table('acceptance_requests AS a')
            ->leftJoin('transactions AS t', function ($j) {
                $j->on('t.acceptance_id', '=', 'a.id')->where('t.status', '!=', 'voided');
            })
            ->where('a.agent_id', $userId)
            ->where('a.status', 'APPROVED')
            ->where('a.is_preauth', 0)
            ->whereNull('t.id')
            ->orderBy('a.approved_at')
            ->limit(10)
            ->get(['a.id', 'a.approved_at'])
            ->map(fn($a) => [
                'acceptance' => '#' . $a->id,
                'approved_at' => $a->approved_at,
                'waiting_hours' => $a->approved_at ? round((time() - strtotime($a->approved_at)) / 3600, 1) : null,
            ])->all();

        // Approved transactions (last 14 days) with no e-ticket yet.
        $txns = DB::table('transactions AS t')
            ->leftJoin('etickets AS e', 'e.transaction_id', '=', 't.id')
            ->where('t.agent_id', $userId)
            ->where('t.status', 'approved')
            ->where('t.created_at', '>=', date('Y-m-d H:i:s', time() - 14 * 86400))
            ->whereNull('e.id')
            ->orderBy('t.created_at')
            ->limit(10)
            ->get(['t.id', 't.pnr', 't.created_at'])
            ->map(fn($t) => [
                'booking'       => $t->pnr ?: ('#' . $t->id),
                'sold_at'       => $t->created_at,
                'waiting_hours' => round((time() - strtotime($t->created_at)) / 3600, 1),
            ])->all();

        return [
            'acceptances_awaiting_transaction' => $accs,
            'transactions_awaiting_eticket'    => $txns,
        ];
    }

    private static function upcomingDepartures(int $userId): array
    {
        $now = date('Y-m-d');
        $rows = DB::table('transactions AS t')
            ->leftJoin('etickets AS e', 'e.transaction_id', '=', 't.id')
            ->where('t.agent_id', $userId)
            ->where('t.status', 'approved')
            ->whereNotNull('t.travel_date')
            ->whereBetween('t.travel_date', [$now, date('Y-m-d', time() + 3 * 86400)])
            ->orderBy('t.travel_date')
            ->limit(10)
            ->get(['t.id', 't.pnr', 't.travel_date', 't.departure_time', 'e.id AS eticket_id'])
            ->map(fn($t) => [
                'booking'    => $t->pnr ?: ('#' . $t->id),
                'departs'    => trim($t->travel_date . ' ' . ($t->departure_time ?? '')),
                'has_eticket' => $t->eticket_id !== null,
            ])->all();

        return ['upcoming' => $rows, 'window' => 'next 72 hours'];
    }

    /**
     * Recency-windowed, NOT status-filtered. Opening the chat marks nudges
     * 'seen' (that is what clears the badge), so a status filter would make
     * the buddy claim she has nothing to say the moment the agent walks in.
     * She should still be able to follow up on this morning's e-ticket.
     */
    private static function nudges(int $userId): array
    {
        $rows = DB::table('buddy_nudges')
            ->where('user_id', $userId)
            ->where('created_at', '>=', date('Y-m-d H:i:s', time() - 48 * 3600))
            ->orderByDesc('id')
            ->limit(10)
            ->get(['id', 'type', 'payload_json', 'status', 'created_at'])
            ->map(fn($n) => [
                'id'      => (int) $n->id,
                'type'    => $n->type,
                'payload' => json_decode((string) $n->payload_json, true) ?: [],
                'unread'  => in_array($n->status, ['pending', 'delivered'], true),
                'at'      => $n->created_at,
            ])->all();

        return ['nudges' => $rows, 'window' => 'last 48 hours'];
    }

    // =========================================================================
    // SELF-SET MONTHLY GOAL
    //
    // Stored structured (not as a free-text fact) because the whole point is to
    // MEASURE against it — the persona has always told Aisha to ask for a goal,
    // but until now the answer landed in buddy_agent_facts where nothing could
    // ever compare it to real numbers.
    //
    // A standing goal carries forward month to month until the agent changes
    // it, which is how people actually think about a monthly target.
    // =========================================================================

    /** @return array{goal: array|null} raw stored goal for this user */
    private static function readGoal(int $userId): ?array
    {
        $goal = BuddySettings::read($userId)['goal'] ?? null;
        return is_array($goal) ? $goal : null;
    }

    private static function setGoal(int $userId, array $args): array
    {
        // Clearing comes first: a goal you cannot walk away from is a target
        // somebody else set, which is exactly what this must never become.
        if (!empty($args['clear'])) {
            $ok = BuddySettings::mutate($userId, function (array $extra) {
                unset($extra['goal']);
                return [$extra, true];
            }, false);
            return $ok
                ? ['cleared' => true, 'note' => 'Goal forgotten. Do not ask them to set a new one unless they raise it.']
                : ['error' => 'Could not clear the goal right now.'];
        }

        $sales   = isset($args['sales'])   ? (int) $args['sales']     : null;
        $revenue = isset($args['revenue']) ? (float) $args['revenue'] : null;

        if ($sales === null && $revenue === null) {
            return ['error' => 'Give a sales target, a revenue target, or both.'];
        }
        if ($sales !== null && ($sales < 1 || $sales > 999)) {
            return ['error' => 'Sales target must be between 1 and 999.'];
        }
        if ($revenue !== null && ($revenue <= 0 || $revenue > 10000000)) {
            return ['error' => 'Revenue target looks wrong — give a realistic monthly figure in dollars.'];
        }

        $goal = BuddySettings::mutate($userId, function (array $extra) use ($sales, $revenue) {
            // Merge, so setting only a sales target keeps an existing revenue one.
            $goal = is_array($extra['goal'] ?? null) ? $extra['goal'] : [];
            if ($sales !== null) {
                $goal['sales'] = $sales;
            }
            if ($revenue !== null) {
                $goal['revenue'] = round($revenue, 2);
            }
            $goal['set_at'] = date('Y-m-d H:i:s');
            $extra['goal']  = $goal;
            return [$extra, $goal];
        });

        if ($goal === null) {
            return ['error' => 'Could not save the goal right now.'];
        }
        return ['saved' => true, 'goal' => $goal,
                'note'  => "This is the agent's own goal, self-chosen. Not a management target."];
    }

    private static function goalProgress(int $userId, string $role): array
    {
        $goal = self::readGoal($userId);
        if ($goal === null || (!isset($goal['sales']) && !isset($goal['revenue']))) {
            return ['has_goal' => false,
                    'hint' => 'No goal set yet. Offer to set one with set_my_goal — never invent a target for them.'];
        }

        // Same window and the same hold compliance as the Performance tab, so
        // the goal maths can never disagree with the screen they are looking at
        // — or become a side channel around a performance hold.
        $q = DB::table('transactions')
            ->where('agent_id', $userId)
            ->whereBetween('created_at', [date('Y-m-01 00:00:00'), date('Y-m-d H:i:s')])
            ->where('status', 'approved');
        $q = PerformanceHold::apply($q, 'created_at', $role);
        $row = $q->selectRaw('COUNT(*) AS n, COALESCE(SUM(total_amount),0) AS revenue')->first();

        $salesDone   = (int) $row->n;
        $revenueDone = round((float) $row->revenue, 2);

        $daysInMonth = (int) date('t');
        $dayOfMonth  = (int) date('j');
        $daysLeft    = max(0, $daysInMonth - $dayOfMonth);
        $elapsed     = $dayOfMonth / $daysInMonth;

        $out = [
            'has_goal'      => true,
            'month'         => date('F Y'),
            'days_elapsed'  => $dayOfMonth,
            'days_in_month' => $daysInMonth,
            'days_left'     => $daysLeft,
            'goal_is'       => "the agent's own, self-chosen — never call it a management target",
        ];

        if (isset($goal['sales'])) {
            $target   = (int) $goal['sales'];
            $expected = $target * $elapsed;
            $out['sales'] = [
                'target'         => $target,
                'done'           => $salesDone,
                'remaining'      => max(0, $target - $salesDone),
                'expected_by_now'=> round($expected, 1),
                'on_track'       => $salesDone >= $expected,
                'hit'            => $salesDone >= $target,
                'per_day_needed' => $daysLeft > 0 ? round(max(0, $target - $salesDone) / $daysLeft, 2) : null,
            ];
        }
        if (isset($goal['revenue'])) {
            $target   = (float) $goal['revenue'];
            $expected = $target * $elapsed;
            $out['revenue'] = [
                'target'         => round($target, 2),
                'done'           => $revenueDone,
                'remaining'      => round(max(0, $target - $revenueDone), 2),
                'expected_by_now'=> round($expected, 2),
                'on_track'       => $revenueDone >= $expected,
                'hit'            => $revenueDone >= $target,
                'per_day_needed' => $daysLeft > 0 ? round(max(0, $target - $revenueDone) / $daysLeft, 2) : null,
            ];
        }

        $notice = PerformanceHold::notice($role);
        if ($notice !== null) {
            $out['hold_notice'] = $notice;
        }
        return $out;
    }

    private static function rememberFact(int $userId, string $fact): array
    {
        $fact = trim($fact);
        if ($fact === '' || mb_strlen($fact) > 300) {
            return ['error' => 'Fact must be 1–300 characters.'];
        }
        // Scrub before storing — a fact must never smuggle customer PII into
        // every future prompt.
        [$fact] = BuddyPromptBuilder::scrub($fact);

        $count = DB::table('buddy_agent_facts')->where('user_id', $userId)->where('active', 1)->count();
        if ($count >= 30) {
            return ['error' => 'Memory is full (30 facts). Ask the agent which old fact to forget first.'];
        }

        DB::table('buddy_agent_facts')->insert([
            'user_id'    => $userId,
            'fact'       => $fact,
            'source'     => 'chat',
            'active'     => 1,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        return ['saved' => true, 'fact' => $fact];
    }
}
