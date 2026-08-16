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
            'Open buddy nudges for this agent (sale praise, dry spell, e-ticket lag, departure reminders) that have not been seen yet.',
            [],
            fn() => self::nudges($userId)
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

    private static function nudges(int $userId): array
    {
        $rows = DB::table('buddy_nudges')
            ->where('user_id', $userId)
            ->whereIn('status', ['pending', 'delivered'])
            ->orderByDesc('id')
            ->limit(10)
            ->get(['id', 'type', 'payload_json', 'created_at'])
            ->map(fn($n) => [
                'id'      => (int) $n->id,
                'type'    => $n->type,
                'payload' => json_decode((string) $n->payload_json, true) ?: [],
                'at'      => $n->created_at,
            ])->all();

        return ['nudges' => $rows];
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
