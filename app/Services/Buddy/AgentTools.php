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
            'set_my_name',
            'Save what the agent likes to be CALLED — their preferred name or nickname. Use it the moment they '
            . 'tell you ("call me TJ"). This name is used EVERYWHERE: greetings, toasts, spoken lines, not just '
            . 'this chat — so saving it here is what makes Aisha greet them right tomorrow morning.',
            [
                'type'       => 'object',
                'properties' => [
                    'name' => ['type' => 'string', 'description' => 'The preferred name, 1–40 characters.'],
                ],
                'required'   => ['name'],
            ],
            fn(array $a) => self::setName($userId, (string) ($a['name'] ?? ''))
        );

        $r->register(
            'get_my_patterns',
            "ALWAYS call this when the agent asks about patterns, trends, their conversion rate, their best day "
            . 'or time to sell, their speed, or how to improve. Working patterns mined from THIS AGENT\'S OWN '
            . 'last 90 days: best weekday, acceptance-to-sale conversion rate and average hours to convert, '
            . 'sale-to-e-ticket speed, average sale size this month vs last. Even when their history is thin, '
            . 'call it and report honestly what little there is. Every section carries sample_size: when it is '
            . 'small, say the pattern is tentative rather than presenting it as fact.',
            [],
            fn() => self::patterns($userId, $role)
        );

        $r->register(
            'get_my_week_recap',
            "The agent's own last 7 days vs the 7 days before: sales, revenue, net MCO, best day. "
            . 'Use for "how was my week?" and for telling the story of their week.',
            [],
            fn() => self::weekRecap($userId, $role)
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
            'Save something the agent wants you to remember (how they like to work, what motivates them, a detail they asked you to keep). '
            . 'Save it when they ask you to, or when it is clearly a lasting preference. Do NOT save private confidences '
            . '(health, family problems, feelings about colleagues or managers) unless they ask you to remember them.',
            [
                'type'       => 'object',
                'properties' => [
                    'fact' => ['type' => 'string', 'description' => 'One concise sentence. Max 300 chars.'],
                ],
                'required'   => ['fact'],
            ],
            fn(array $a) => self::rememberFact($userId, (string) ($a['fact'] ?? ''))
        );

        // ── Aisha v2 (23 Oct 2026): help beyond their own numbers ──────────
        $question = [
            'type'       => 'object',
            'properties' => ['question' => ['type' => 'string', 'description' => "The agent's question in a few words."]],
            'required'   => ['question'],
        ];
        $r->register(
            'crm_help',
            'How to do something in the Base Fare CRM: card authorizations (acceptances), transactions, e-tickets, '
            . 'refunds and voids, call logs, clock-in, breaks and clock-out, messages, notepad, customer emails, invoices, '
            . 'vouchers, performance, reminders, Trio HR, passwords and access. ALWAYS call this for any "how do I", '
            . '"where is" or "who can" question about the CRM, and answer ONLY from what it returns — never guess steps.',
            $question,
            fn(array $a) => AishaKnowledge::search(AishaKnowledge::crmGuide(), (string) ($a['question'] ?? ''))
        );
        $r->register(
            'sales_tips',
            'Practical, honest phone-sales coaching for selling flights to US customers: opening a call, discovery '
            . 'questions, "I\'ll think about it", "cheaper online", "too expensive", scam worries, angry customers, delays '
            . 'and cancellations, taking notes from passengers, missing the right words, the phonetic alphabet, closing, '
            . 'follow-ups, add-ons, US time zones, rapport, staying fresh on nights. Call it for any customer-handling or '
            . 'selling question and build your answer on what it returns.',
            $question,
            fn(array $a) => AishaKnowledge::search(AishaKnowledge::playbook(), (string) ($a['question'] ?? ''))
        );
        $r->register(
            'get_my_day',
            "The agent's own results for ONE past shift day (6 PM that day to 6 PM the next, like the dashboard): "
            . 'sales, revenue, net MCO and the bookings. Use for "how did I do yesterday" or "my score on 30 Sep".',
            [
                'type'       => 'object',
                'properties' => ['date' => ['type' => 'string', 'description' => 'YYYY-MM-DD, or "yesterday".']],
                'required'   => ['date'],
            ],
            fn(array $a) => self::dayStats($userId, $role, (string) ($a['date'] ?? ''))
        );
        $r->register(
            'get_my_booking',
            "Look up ONE of the agent's own bookings by booking reference (PNR) or transaction number: type, status, "
            . 'amount, travel date, and whether the e-ticket is issued, emailed and acknowledged. Never another agent\'s booking.',
            [
                'type'       => 'object',
                'properties' => ['ref' => ['type' => 'string', 'description' => 'Booking reference / PNR, or a transaction number like #1234.']],
                'required'   => ['ref'],
            ],
            fn(array $a) => self::booking($userId, (string) ($a['ref'] ?? ''))
        );
        if (self::rankEnabled() && in_array($role, ['agent', 'supervisor'], true)) {
            $r->register(
                'get_my_rank',
                'Where the agent stands this calendar month among everyone who sells: their rank and how many people, '
                . 'by net MCO or by number of sales. Gives no names and no other person\'s numbers — never guess them.',
                [
                    'type'       => 'object',
                    'properties' => ['metric' => ['type' => 'string', 'enum' => ['net_mco', 'sales'], 'description' => 'Default net_mco.']],
                ],
                fn(array $a) => self::rank($userId, $role, (string) ($a['metric'] ?? 'net_mco'))
            );
        }
        $r->register(
            'set_reminder',
            'Set a personal reminder that pops up for the agent at a time ("remind me to call back Mr Shah at 2:30am", '
            . '"in 45 minutes check the fare"). Times are India time unless they name a US zone (e.g. "9pm Eastern").',
            [
                'type'       => 'object',
                'properties' => [
                    'when' => ['type' => 'string', 'description' => 'The time in plain English: "in 30 minutes", "in 2 hours", "at 2:30 am", '
                        . '"tomorrow at 9 pm", "9 pm eastern", "25 oct 3 pm", or an exact India time "2026-10-24 02:30". '
                        . 'Translate Hindi/Hinglish first ("10 minute baad" → "in 10 minutes"). Never a numeric date like 10/24.'],
                    'what' => ['type' => 'string', 'description' => 'What to remind them about, short. No phone numbers or card details.'],
                ],
                'required'   => ['when', 'what'],
            ],
            fn(array $a) => self::setReminder($userId, (string) ($a['when'] ?? ''), (string) ($a['what'] ?? ''))
        );
        $r->register(
            'get_my_reminders',
            "The agent's upcoming personal reminders, soonest first, with ids.",
            [],
            fn() => self::reminders($userId)
        );
        $r->register(
            'cancel_reminder',
            'Cancel one of the agent\'s reminders by id (from get_my_reminders).',
            [
                'type'       => 'object',
                'properties' => ['id' => ['type' => 'integer']],
                'required'   => ['id'],
            ],
            fn(array $a) => self::cancelReminder($userId, (int) ($a['id'] ?? 0))
        );
        $r->register(
            'get_my_memory',
            'Everything Aisha remembers about the agent: their preferred name, their own goal, and the saved facts with ids. '
            . 'Use for "what do you know about me".',
            [],
            fn() => self::memory($userId)
        );
        $r->register(
            'forget_memory',
            'Forget something Aisha remembered about the agent — by fact id from get_my_memory, or by a few words of it. '
            . 'Always do it when they ask; it is their information.',
            [
                'type'       => 'object',
                'properties' => [
                    'fact_id' => ['type' => 'integer'],
                    'words'   => ['type' => 'string', 'description' => 'A few words from the fact to forget.'],
                ],
            ],
            fn(array $a) => self::forget($userId, (int) ($a['fact_id'] ?? 0), (string) ($a['words'] ?? ''))
        );
        if (in_array($role, ['agent', 'supervisor', 'manager'], true)) {
            $r->register(
                'get_my_kra',
                "The agent's OWN KRA this month: target, levels, progress, how far to the next level, the incentive "
                . 'earned so far (₹), their weakest lever (calls, closing, profit per sale) with what fixing it is worth, and '
                . 'two steps to try. Team leaders and managers also get their team\'s progress (never anyone else\'s money). '
                . 'Use for "my target", "my incentive", "how much will I earn", "what should I improve".',
                [],
                fn() => self::kra($userId)
            );
        }
        if ($role === 'supervisor') {
            $r->register(
                'get_my_team',
                'Team leaders only: the agents in THEIR OWN team — who is clocked in now, sales and net MCO this shift and '
                . 'this month, and e-tickets waiting, per agent. Use for "how is my team doing" or "who needs help".',
                [],
                fn() => self::team($userId, $role)
            );
        }

        return $r;
    }

    /** Facts injected into the persona each turn (onboarding memory). */
    public static function facts(int $userId): array
    {
        // The 20 MOST RECENT facts (shown oldest first). It used to take the
        // oldest 20, so everything she learned after the twentieth fact never
        // reached her prompt again.
        return array_reverse(DB::table('buddy_agent_facts')
            ->where('user_id', $userId)->where('active', 1)
            ->orderByDesc('id')->limit(20)->pluck('fact')->all());
    }

    /**
     * What Aisha does NOT yet know about this agent — the engine behind
     * proactive personalization. Until now "get to know them" was a hope
     * buried in the persona; this makes it a computed, ordered list that the
     * persona AND the greeting consume every turn, so she keeps interviewing
     * — one natural question at a time — until she actually knows her person,
     * and stops the moment she does.
     *
     * Ordered by importance: the name shapes every single line she says.
     */
    public static function knowledgeGaps(int $userId, string $surface = 'agent'): array
    {
        $gaps = [];
        try {
            $settings = DB::table('buddy_settings')->where('user_id', $userId)
                ->first(['display_name', 'extra_json']);

            if (empty($settings->display_name)) {
                $gaps[] = 'their preferred name — what they like to be called (save with set_my_name)';
            }

            $factCount = (int) DB::table('buddy_agent_facts')
                ->where('user_id', $userId)->where('active', 1)->count();

            if ($surface === 'admin') {
                // An admin has no sales goal to chase — what she needs to learn
                // is how this person wants to be briefed and what they watch.
                if ($factCount < 2) {
                    $gaps[] = 'what they check first each day and which numbers matter most to them (remember_fact)';
                } elseif ($factCount < 5) {
                    $gaps[] = 'how they prefer briefings — short headlines or full detail — and what tends to worry them (remember_fact)';
                }
                return $gaps;
            }

            $extra = json_decode((string) ($settings->extra_json ?? ''), true) ?: [];
            if (empty($extra['goal'])) {
                $gaps[] = 'whether they want a monthly goal to chase (set_my_goal — offer, never impose)';
            }
            if ($factCount < 2) {
                $gaps[] = 'what keeps them motivated — what they are working toward in life (remember_fact)';
            } elseif ($factCount < 6) {
                $gaps[] = 'more of who they are — how they like to work, what they enjoy selling, life outside work (remember_fact)';
            }
        } catch (\Throwable $e) {
            // no gaps on error — she just skips the interview this turn
        }
        return $gaps;
    }

    /** Shared with the admin surface — same storage, different registry. */
    public static function setNameFor(int $userId, string $name): array
    {
        return self::setName($userId, $name);
    }

    public static function rememberFactFor(int $userId, string $fact): array
    {
        return self::rememberFact($userId, $fact);
    }

    private static function setName(int $userId, string $name): array
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 40) {
            return ['error' => 'Name must be 1–40 characters.'];
        }
        [$name] = BuddyPromptBuilder::scrub($name);
        try {
            DB::table('buddy_settings')->updateOrInsert(
                ['user_id' => $userId],
                ['display_name' => $name]
            );
            return ['saved' => true, 'name' => $name,
                    'note'  => 'From now on every greeting, toast and spoken line uses this name.'];
        } catch (\Throwable $e) {
            return ['error' => 'Could not save the name right now.'];
        }
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
            ->get(['t.id', 't.pnr', 't.created_at', 't.gateway_status'])
            ->map(fn($t) => [
                'booking'       => $t->pnr ?: ('#' . $t->id),
                'sold_at'       => $t->created_at,
                'waiting_hours' => round((time() - strtotime($t->created_at)) / 3600, 1),
                // The e-ticket form only accepts bookings an admin has marked
                // "Charge Successful" — say which ones they can do right now.
                'charged'       => $t->gateway_status === 'charge_successful',
            ])->all();

        return [
            'acceptances_awaiting_transaction' => $accs,
            'transactions_awaiting_eticket'    => $txns,
            'eticket_rule' => 'An e-ticket can only be created once an admin has marked the payment "Charge Successful". '
                . 'charged=false bookings are waiting on an admin, not on the agent.',
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
        // Reminders have their own tools (a future one is not "flagged"), and
        // nudges dropped because the job was already done are history, not news.
        $rows = DB::table('buddy_nudges')
            ->where('user_id', $userId)
            ->where('created_at', '>=', date('Y-m-d H:i:s', time() - 48 * 3600))
            ->where('type', '!=', 'reminder')
            ->whereNotIn('status', ['expired', 'done'])
            ->orderByDesc('id')
            ->limit(10)
            ->get(['id', 'type', 'payload_json', 'status', 'created_at'])
            ->map(fn($n) => [
                'id'      => (int) $n->id,
                'type'    => $n->type,
                'payload' => json_decode((string) $n->payload_json, true) ?: [],
                'unread'  => in_array($n->status, ['pending', 'delivered'], true),
                // true = already in the chat; false = she hasn't raised it yet
                'in_chat' => in_array($n->status, ['delivered', 'seen'], true),
                'at'      => $n->created_at,
            ])->all();

        return ['nudges' => $rows, 'window' => 'last 48 hours'];
    }

    // =========================================================================
    // PATTERNS — the coach's eyes. Deterministic SQL + PHP aggregation over the
    // agent's OWN rows only; aggregated in PHP (not DB date functions) so the
    // exact same code runs on MySQL in production and SQLite in the offline
    // verifier. Every section reports sample_size and refuses to pretend three
    // data points are a pattern — Aisha's honesty rule applies to statistics
    // as much as to numbers.
    // =========================================================================

    private const PATTERN_WINDOW_DAYS = 90;
    private const PATTERN_ROW_CAP     = 400;
    private const PATTERN_MIN_SAMPLE  = 5;

    private static function patterns(int $userId, string $role): array
    {
        $since = date('Y-m-d H:i:s', time() - self::PATTERN_WINDOW_DAYS * 86400);
        $out   = ['window' => 'last ' . self::PATTERN_WINDOW_DAYS . ' days, your own records only'];

        // ── Best weekday ────────────────────────────────────────────────────
        try {
            $q = DB::table('transactions')
                ->where('agent_id', $userId)->where('status', 'approved')
                ->where('created_at', '>=', $since)
                ->orderByDesc('id')->limit(self::PATTERN_ROW_CAP);
            $q = PerformanceHold::apply($q, 'created_at', $role);
            $sales = $q->get(['created_at', 'total_amount']);

            $byDay = [];
            foreach ($sales as $s) {
                $d = date('l', strtotime((string) $s->created_at));
                $byDay[$d] ??= ['sales' => 0, 'revenue' => 0.0];
                $byDay[$d]['sales']++;
                $byDay[$d]['revenue'] += (float) $s->total_amount;
            }
            foreach ($byDay as &$v) {
                $v['revenue'] = round($v['revenue'], 2);
            }
            unset($v);
            arsort($byDay);   // by sales count via array order of first key — sort explicitly:
            uasort($byDay, fn($a, $b) => $b['sales'] <=> $a['sales']);

            $total = count($sales);
            $best  = array_key_first($byDay);
            $out['weekdays'] = [
                'sample_size' => $total,
                'by_day'      => $byDay,
                'best_day'    => ($total >= self::PATTERN_MIN_SAMPLE && $best !== null) ? $best : null,
                'note'        => $total < self::PATTERN_MIN_SAMPLE
                    ? 'too little data to call a best day — do not claim one'
                    : null,
            ];
        } catch (\Throwable $e) {
            $out['weekdays'] = ['error' => 'unavailable'];
        }

        // ── Acceptance → sale conversion ────────────────────────────────────
        try {
            $accs = DB::table('acceptance_requests AS a')
                ->leftJoin('transactions AS t', function ($j) {
                    $j->on('t.acceptance_id', '=', 'a.id')->where('t.status', '!=', 'voided');
                })
                ->where('a.agent_id', $userId)
                ->where('a.status', 'APPROVED')
                ->where('a.is_preauth', 0)
                ->where('a.approved_at', '>=', $since)
                ->orderByDesc('a.id')->limit(self::PATTERN_ROW_CAP)
                ->get(['a.approved_at', 't.created_at AS txn_at']);

            $n = count($accs);
            $converted = 0;
            $hours = [];
            foreach ($accs as $a) {
                if ($a->txn_at !== null) {
                    $converted++;
                    $h = (strtotime((string) $a->txn_at) - strtotime((string) $a->approved_at)) / 3600;
                    if ($h >= 0 && $h < 24 * 14) {
                        $hours[] = $h;
                    }
                }
            }
            $out['conversion'] = [
                'sample_size'          => $n,
                'approved_acceptances' => $n,
                'converted_to_sale'    => $converted,
                'rate_pct'             => $n > 0 ? round($converted / $n * 100, 1) : null,
                'avg_hours_to_convert' => $hours !== [] ? round(array_sum($hours) / count($hours), 1) : null,
                'note'                 => $n < self::PATTERN_MIN_SAMPLE
                    ? 'small sample — treat as tentative'
                    : null,
            ];
        } catch (\Throwable $e) {
            $out['conversion'] = ['error' => 'unavailable'];
        }

        // ── Sale → e-ticket speed ───────────────────────────────────────────
        try {
            $pairs = DB::table('transactions AS t')
                ->join('etickets AS e', 'e.transaction_id', '=', 't.id')
                ->where('t.agent_id', $userId)->where('t.status', 'approved')
                ->where('t.created_at', '>=', $since)
                ->orderByDesc('t.id')->limit(self::PATTERN_ROW_CAP)
                ->get(['t.created_at AS sold_at', 'e.created_at AS ticketed_at']);

            $hours = [];
            foreach ($pairs as $p) {
                $h = (strtotime((string) $p->ticketed_at) - strtotime((string) $p->sold_at)) / 3600;
                if ($h >= 0 && $h < 24 * 14) {
                    $hours[] = $h;
                }
            }
            $out['eticket_speed'] = [
                'sample_size'          => count($hours),
                'avg_hours_to_eticket' => $hours !== [] ? round(array_sum($hours) / count($hours), 1) : null,
                'note'                 => count($hours) < self::PATTERN_MIN_SAMPLE
                    ? 'small sample — treat as tentative'
                    : null,
            ];
        } catch (\Throwable $e) {
            $out['eticket_speed'] = ['error' => 'unavailable'];
        }

        // ── Momentum: avg sale size, this month vs last ─────────────────────
        try {
            $avg = static function (string $from, string $to) use ($userId, $role): array {
                $q = DB::table('transactions')
                    ->where('agent_id', $userId)->where('status', 'approved')
                    ->whereBetween('created_at', [$from, $to]);
                $q = PerformanceHold::apply($q, 'created_at', $role);
                $row = $q->selectRaw('COUNT(*) AS n, COALESCE(AVG(total_amount),0) AS avg_amount')->first();
                return ['sales' => (int) $row->n, 'avg_sale' => round((float) $row->avg_amount, 2)];
            };
            $out['momentum'] = [
                'this_month' => $avg(date('Y-m-01 00:00:00'), date('Y-m-d H:i:s')),
                'last_month' => $avg(
                    date('Y-m-01 00:00:00', strtotime('first day of last month')),
                    date('Y-m-t 23:59:59', strtotime('last day of last month'))
                ),
            ];
        } catch (\Throwable $e) {
            $out['momentum'] = ['error' => 'unavailable'];
        }

        // ── The Aisha effect — what happens after she speaks up ─────────────
        // Built from nudge outcomes recorded by the trigger cron. Self-scoped
        // like everything else, and honest: resolution counts and speeds are
        // only stated when they exist, never projected.
        try {
            $rows = DB::table('buddy_nudges')
                ->where('user_id', $userId)
                ->whereIn('type', ['eticket_lag', 'acceptance_lag', 'dry_spell'])
                ->whereNotNull('outcome')
                ->where('created_at', '>=', $since)
                ->get(['type', 'outcome', 'outcome_hours']);

            $eff = [];
            foreach ($rows as $r) {
                $t = (string) $r->type;
                $eff[$t] ??= ['nudged' => 0, 'resolved' => 0, 'hours' => []];
                $eff[$t]['nudged']++;
                if ($r->outcome === 'resolved') {
                    $eff[$t]['resolved']++;
                    if ($r->outcome_hours !== null) {
                        $eff[$t]['hours'][] = (float) $r->outcome_hours;
                    }
                }
            }
            $effect = [];
            foreach ($eff as $t => $v) {
                $effect[$t] = [
                    'nudged'                   => $v['nudged'],
                    'resolved_after_nudge'     => $v['resolved'],
                    'avg_hours_after_nudge'    => $v['hours'] !== []
                        ? round(array_sum($v['hours']) / count($v['hours']), 1) : null,
                ];
            }
            $out['after_my_nudges'] = [
                'sample_size' => count($rows),
                'by_type'     => $effect,
                'note'        => count($rows) < self::PATTERN_MIN_SAMPLE
                    ? 'small sample — treat as tentative'
                    : 'use this to show the agent that acting on nudges pays off — never to scold',
            ];
        } catch (\Throwable $e) {
            $out['after_my_nudges'] = ['error' => 'unavailable'];
        }

        $notice = PerformanceHold::notice($role);
        if ($notice !== null) {
            $out['hold_notice'] = $notice;
        }
        return $out;
    }

    /** Last 7 days vs the 7 before — also feeds the Monday greeting recap. */
    public static function weekRecap(int $userId, string $role): array
    {
        $week = static function (int $daysAgoStart, int $daysAgoEnd) use ($userId, $role): array {
            $from = date('Y-m-d H:i:s', time() - $daysAgoStart * 86400);
            $to   = date('Y-m-d H:i:s', time() - $daysAgoEnd * 86400);
            $q = DB::table('transactions')
                ->where('agent_id', $userId)->where('status', 'approved')
                ->whereBetween('created_at', [$from, $to]);
            $q = PerformanceHold::apply($q, 'created_at', $role);
            $rows = $q->orderByDesc('id')->limit(self::PATTERN_ROW_CAP)->get(['created_at', 'total_amount', 'profit_mco', 'refund_mco_impact']);

            $sales = count($rows);
            $revenue = 0.0;
            $net = 0.0;
            $byDate = [];
            foreach ($rows as $r) {
                $revenue += (float) $r->total_amount;
                $net     += (float) $r->profit_mco - (float) $r->refund_mco_impact;
                $d = substr((string) $r->created_at, 0, 10);
                $byDate[$d] = ($byDate[$d] ?? 0) + 1;
            }
            arsort($byDate);
            return [
                'sales'    => $sales,
                'revenue'  => round($revenue, 2),
                'net_mco'  => round($net, 2),
                'best_day' => $byDate === [] ? null
                    : ['date' => array_key_first($byDate), 'sales' => reset($byDate)],
            ];
        };

        $out = [
            'window'     => 'rolling: last 7 days vs the 7 days before',
            'last_7'     => $week(7, 0),
            'prior_7'    => $week(14, 7),
        ];
        $notice = PerformanceHold::notice($role);
        if ($notice !== null) {
            $out['hold_notice'] = $notice;
        }
        return $out;
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

    // =========================================================================
    // AISHA v2 HANDLERS (23 Oct 2026)
    // =========================================================================

    /**
     * The person's own KRA, trimmed to what Aisha needs. Incentive figures are
     * the caller's own; team members appear as progress only, with no money —
     * incentives are confidential to each person (owner's rule, 23 Oct 2026).
     */
    private static function kra(int $userId): array
    {
        $card = (new \App\Services\Kra\KraEngine(DB::connection()))->card($userId);
        if ($card === null) {
            return ['live' => false, 'note' => 'KRA targets have not been set up yet. Say so; do not guess any target or incentive.'];
        }
        if (empty($card['in_kra'])) {
            return ['live' => true, 'in_kra' => false];
        }
        $me = $card['me'];
        $out = [
            'month'      => date('F Y', strtotime($card['month'] . '-01')),
            'days_left'  => $card['days_left'],
            'confidential' => 'This incentive is private to the person you are talking to. Never mention anyone else\'s.',
            'amounts_note' => 'Incentive amounts are what is earned SO FAR from approved sales, not a month-end forecast. They are paid with next month\'s salary after late refunds; if all incentives together pass the company\'s monthly budget, everyone\'s is reduced by the same share. Say "so far", never "projected".',
        ];
        if ($me['role'] !== 'manager') {
            $out['net_mco'] = $me['mco'];
            $out['sales'] = $me['sales'];
            if ($me['set_up']) {
                $out += [
                    'target'          => $me['levels']['l2'],
                    'level_1'         => $me['levels']['l1'],
                    'level_3'         => $me['levels']['l3'],
                    'progress_pct'    => $me['progress'],
                    'current_level'   => $me['level'],
                    'next_level'      => $me['next'],
                    'per_night_needed'=> $card['nightly'],
                    'monthly_incentive_inr_so_far' => $me['incentive'],
                    'spot_rewards_inr_so_far' => $me['spot_inr'],
                    'booster_inr_so_far'      => $me['booster_inr'],
                    'total_incentive_inr_so_far' => $me['incentive_total'],
                    'quality_gate_hit'=> $me['quality_hit'],
                    'levers'          => array_map(fn($l) => ['lever' => $l['label'], 'now' => $l['text'], 'target' => $l['target_text'], 'on_target' => $l['ok']], $me['levers']),
                    'weakest'         => $me['gap'],
                ];
            } else {
                $out['target'] = 'not set yet — HR still has to set it';
            }
        }
        if (!empty($card['booster'])) {
            $out['booster_tonight'] = $card['booster'];
        }
        if (isset($me['team'])) {
            $out['team'] = [
                'net_mco'   => $me['team']['mco'],
                'target'    => $me['team']['target'],
                'progress_pct' => $me['team']['progress'],
                'past_level_1' => $me['team']['past_l1'] . ' of ' . $me['team']['set_up'],
                'my_team_share_inr_so_far' => $me['team']['share_inr'],
                'members'   => array_map(fn($m) => ['name' => $m['name'], 'progress_pct' => $m['progress'], 'weakest' => $m['weakest']], $card['team']),
            ];
            if ($me['role'] === 'supervisor') {
                $out['team']['bonus'] = $me['team']['bonus_unlocked'] ? 'unlocked' : ('needs ' . $me['team']['bonus_need'] . ' agents past Level 1');
            }
            $out['total_incentive_inr_so_far'] = $me['incentive_total'];
        }
        return $out;
    }

    /** Rank is on unless BUDDY_RANK_ENABLED=false. */
    private static function rankEnabled(): bool
    {
        return ($_ENV['BUDDY_RANK_ENABLED'] ?? getenv('BUDDY_RANK_ENABLED') ?: 'true') !== 'false';
    }

    /** A date the agent said → the shift date (Y-m-d), or null. */
    public static function parseDay(string $s): ?string
    {
        $s = mb_strtolower(trim($s));
        [$start] = ShiftService::businessDayBounds();
        $today = substr((string) $start, 0, 10);
        if ($s === '' || $s === 'today' || $s === 'aaj') {
            return $today;
        }
        if (in_array($s, ['yesterday', 'kal', 'last night'], true)) {
            return date('Y-m-d', strtotime($today . ' -1 day'));
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
            return $s;
        }
        $ts = strtotime($s);
        if ($ts === false) {
            return null;
        }
        $d = date('Y-m-d', $ts);
        // "30 Sep" asked in January means last year's September.
        return $d > $today ? date('Y-m-d', strtotime($d . ' -1 year')) : $d;
    }

    private static function dayStats(int $userId, string $role, string $date): array
    {
        $d = self::parseDay($date);
        if ($d === null) {
            return ['error' => 'Give a date like 2026-09-30, 30 Sep, or "yesterday".'];
        }
        $h    = ShiftService::businessDayStartHour();
        $from = date('Y-m-d H:i:s', strtotime($d) + $h * 3600);
        $to   = date('Y-m-d H:i:s', strtotime($from) + 86399);
        if (strtotime($from) > time()) {
            return ['error' => 'That shift has not started yet.'];
        }
        $q = DB::table('transactions')
            ->where('agent_id', $userId)->where('status', 'approved')
            ->whereBetween('created_at', [$from, $to]);
        $q = PerformanceHold::apply($q, 'created_at', $role);
        $rows = $q->orderBy('created_at')->limit(40)
            ->get(['id', 'pnr', 'type', 'total_amount', 'profit_mco', 'refund_mco_impact', 'created_at']);

        $out = [
            'shift'    => date('D j M Y', strtotime($d)) . ', 6 PM to 6 PM next day',
            'sales'    => count($rows),
            'revenue'  => round((float) $rows->sum('total_amount'), 2),
            'net_mco'  => round((float) $rows->sum(fn($t) => (float) $t->profit_mco - (float) $t->refund_mco_impact), 2),
            'bookings' => $rows->take(15)->map(fn($t) => [
                'ref' => $t->pnr ?: ('#' . $t->id), 'type' => $t->type,
                'amount' => round((float) $t->total_amount, 2), 'at' => $t->created_at,
            ])->values()->all(),
        ];
        $notice = PerformanceHold::notice($role);
        if ($notice !== null) {
            $out['hold_notice'] = $notice;
        }
        return $out;
    }

    private static function booking(int $userId, string $ref): array
    {
        $ref = strtoupper(trim(ltrim(trim($ref), '#')));
        if ($ref === '' || mb_strlen($ref) > 20) {
            return ['error' => 'Give the booking reference (PNR) or the transaction number.'];
        }
        $t = DB::table('transactions')
            ->where('agent_id', $userId)
            ->where(function ($w) use ($ref) {
                $w->where('pnr', $ref);
                if (ctype_digit($ref)) {
                    $w->orWhere('id', (int) $ref);
                }
            })
            ->orderByDesc('id')
            ->first(['id', 'pnr', 'type', 'status', 'refund_status', 'total_amount', 'currency', 'created_at',
                     'travel_date', 'departure_time', 'return_date', 'acceptance_id']);
        if ($t === null) {
            if (ctype_digit($ref)) {
                $a = DB::table('acceptance_requests')->where('agent_id', $userId)->where('id', (int) $ref)
                    ->first(['id', 'type', 'status', 'created_at', 'approved_at', 'expires_at', 'transaction_id']);
                if ($a !== null) {
                    return ['found' => 'acceptance', 'acceptance' => '#' . $a->id, 'type' => $a->type, 'status' => $a->status,
                            'created_at' => $a->created_at, 'approved_at' => $a->approved_at, 'expires_at' => $a->expires_at,
                            'has_transaction' => $a->transaction_id !== null];
                }
            }
            return ['found' => false, 'note' => 'No booking with that reference among YOUR bookings. Check the reference, or it may belong to someone else.'];
        }
        $e = DB::table('etickets')->where('transaction_id', $t->id)->orderByDesc('id')
            ->first(['id', 'status', 'email_status', 'last_emailed_at', 'acknowledged_at']);
        return [
            'found'       => 'transaction',
            'ref'         => $t->pnr ?: ('#' . $t->id),
            'transaction' => '#' . $t->id,
            'type'        => $t->type,
            'status'      => $t->status,
            'refund'      => $t->refund_status ?: 'none',
            'amount'      => round((float) $t->total_amount, 2),
            'currency'    => $t->currency,
            'sold_at'     => $t->created_at,
            'travel_date' => trim(($t->travel_date ?? '') . ' ' . ($t->departure_time ?? '')),
            'return_date' => $t->return_date,
            'eticket'     => $e === null ? ['issued' => false]
                : ['issued' => true, 'status' => $e->status, 'email' => $e->email_status,
                   'emailed_at' => $e->last_emailed_at, 'acknowledged' => $e->acknowledged_at !== null],
        ];
    }

    private static function rank(int $userId, string $role, string $metric): array
    {
        $metric = $metric === 'sales' ? 'sales' : 'net_mco';
        $sellers = DB::table('users')->whereIn('role', ['agent', 'supervisor'])
            ->where('status', 'active')->whereNull('deleted_at')->pluck('id')->map(fn($v) => (int) $v)->all();
        if (!in_array($userId, $sellers, true)) {
            return ['error' => 'Ranking is only for people who sell.'];
        }
        $q = DB::table('transactions')->whereIn('agent_id', $sellers)->where('status', 'approved')
            ->whereBetween('created_at', [date('Y-m-01 00:00:00'), date('Y-m-d H:i:s')]);
        $q = PerformanceHold::apply($q, 'created_at', $role);
        $rows = $q->groupBy('agent_id')
            ->selectRaw('agent_id, COUNT(*) AS n, COALESCE(SUM(profit_mco - refund_mco_impact),0) AS net')
            ->get()->keyBy('agent_id');
        $score = [];
        foreach ($sellers as $id) {
            $r = $rows[$id] ?? null;
            $score[$id] = $r === null ? 0.0 : (float) ($metric === 'sales' ? $r->n : $r->net);
        }
        $mine = $score[$userId];
        $ahead = count(array_filter($score, fn($v) => $v > $mine));
        $out = [
            'month'     => date('F Y'),
            'metric'    => $metric === 'sales' ? 'number of sales' : 'net MCO',
            'your_rank' => $ahead + 1,
            'out_of'    => count($score),
            'note'      => 'Rank only. Never name, compare with or guess the numbers of any colleague.',
        ];
        $notice = PerformanceHold::notice($role);
        if ($notice !== null) {
            $out['hold_notice'] = $notice;
        }
        return $out;
    }

    /** US time-zone words → zone. Anything else is India time. */
    private const US_ZONES = [
        'America/New_York'    => '/\b(eastern|est|edt|et|new york|ny)\b/',
        'America/Chicago'     => '/\b(central|cst|cdt|ct|chicago|texas)\b/',
        'America/Denver'      => '/\b(mountain|mst|mdt|denver)\b/',
        'America/Los_Angeles' => '/\b(pacific|pst|pdt|pt|los angeles|california|la)\b/',
    ];

    /**
     * A time the agent said → unix timestamp, or null when it is not clear.
     * India time unless a US zone is named ("9pm eastern").
     *
     * Understands, and refuses to guess beyond:
     *   relative   in 30 minutes · after 1 hour 15 min · 5 mins later · half an hour ·
     *              10 minute baad · 2 ghante mein · in 2 days
     *   clock      at 2:30 · 2:30 am · 9pm · at 9 · 10 baje (+ morning/subah, evening/shaam,
     *              night/raat to pick am/pm)
     *   day        tomorrow / kal · day after tomorrow / parso · in 2 days at 3pm ·
     *              25 oct 3pm · on friday at 9pm
     *   exact      2026-10-24 02:30
     * A bare clock time is the next time it comes round. Numeric dates (10/24)
     * are refused: Americans and Indians write them in opposite orders.
     *
     * The night shift moves "tomorrow": until 6 AM it is still last night, so
     * "tomorrow 9pm" said at 1 AM means tonight's 9 PM, not the one after.
     * (Reviewed 23 Oct 2026: the first version read "after 10 minutes" as
     * 10 AM and "in 2 days at 3pm" as two days at the current minute.)
     */
    public static function parseWhen(string $when, ?int $now = null): ?int
    {
        $now = $now ?? time();
        $s = preg_replace('/\s+/', ' ', mb_strtolower(trim($when)));
        if ($s === '') {
            return null;
        }

        $zone = 'Asia/Kolkata';
        foreach (self::US_ZONES as $tz => $re) {
            if (preg_match($re, $s)) {
                $zone = $tz;
                $s = trim(preg_replace('/\s+/', ' ', preg_replace($re, ' ', $s)));
                break;
            }
        }
        $tz = new \DateTimeZone($zone);
        $local = (new \DateTime('@' . $now))->setTimezone($tz);

        // Exact — what the tool asks the model to send when it can.
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})[ t](\d{1,2}):(\d{2})$/', $s, $m)) {
            $d = (clone $local)->setDate((int) $m[1], (int) $m[2], (int) $m[3])->setTime((int) $m[4], (int) $m[5]);
            return ((int) $m[4] < 24 && (int) $m[5] < 60) ? $d->getTimestamp() : null;
        }
        if (preg_match('/\b\d{1,2}\/\d{1,2}(\/\d{2,4})?\b/', $s)) {
            return null;
        }

        // Durations: minutes/hours move the clock, days/weeks move the date.
        // Three shapes, each anchored on whole words: a loose "a"+"m" read the
        // "am" of "2:30 am" — and the end of "subah" — as a duration.
        $secs = 0.0;
        $days = 0.0;
        $found = [];
        preg_match_all('/\b(\d+(?:\.\d+)?)\s*(minutes?|mins?|hours?|hrs?|ghant[ae]?|days?|din|weeks?|hafte?)\b/', $s, $a, PREG_SET_ORDER);
        preg_match_all('/\b(\d+(?:\.\d+)?)(m|h)\b/', $s, $b, PREG_SET_ORDER);                      // 30m, 2h
        preg_match_all('/\b(half an?|aadha|an?)\s+(minutes?|hours?|ghanta|days?|weeks?)\b/', $s, $c, PREG_SET_ORDER);
        foreach (array_merge($a, $b, $c) as $m) {
            $n = is_numeric($m[1]) ? (float) $m[1]
                : (str_starts_with($m[1], 'half') || $m[1] === 'aadha' ? 0.5 : 1.0);
            $u = $m[2];
            if (preg_match('/^(m|mins?|minutes?)$/', $u)) {
                $secs += $n * 60;
            } elseif (preg_match('/^(h|hrs?|hours?|ghant[ae]?)$/', $u)) {
                $secs += $n * 3600;
            } elseif (preg_match('/^(days?|din)$/', $u)) {
                $days += $n;
            } else {
                $days += 7 * $n;
            }
        }
        if (preg_match('/\bnext week\b/', $s)) {
            $days += 7;
        }

        $clock = self::clockOf($s);
        if ($clock === null) {
            if ($secs <= 0 && $days <= 0) {
                return null;
            }
            return $now + (int) round($secs + $days * 86400);
        }
        if ($secs > 0) {
            return null;   // "in 30 minutes at 3pm" — which one?
        }

        // Which day. Fixed when they named one; otherwise the next time the clock comes round.
        $day = (clone $local)->setTime(0, 0);
        $fixed = false;
        $weekday = null;
        $named = self::dateOf($s, $local);
        if ($named !== null) {
            $day = $named;
            $fixed = true;
        } else {
            $shift = 0;
            if (preg_match('/\b(day after tomorrow|parso)\b/', $s)) {
                $shift = 2;
            } elseif (preg_match('/\b(tomorrow|tmrw|tmr|kal)\b/', $s)) {
                $shift = 1;
            }
            if ($shift > 0 && (int) $local->format('G') < 6) {
                $shift--;   // before 6 AM it is still last night
            }
            if (preg_match('/\b(monday|tuesday|wednesday|thursday|friday|saturday|sunday)\b/', $s, $w)) {
                $weekday = (int) date('w', strtotime($w[1]));
                $shift = ($weekday - (int) $local->format('w') + 7) % 7;
            }
            $shift += (int) round($days);
            if ($shift > 0) {
                $day->modify('+' . $shift . ' days');
                $fixed = true;
            } elseif ($weekday !== null) {
                $fixed = true;   // that weekday is today
            }
        }

        [$hour, $min, $ap] = $clock;
        if ($hour > 23 || $min > 59 || ($ap !== '' && ($hour < 1 || $hour > 12))) {
            return null;
        }
        if ($ap === '') {
            if (preg_match('/\b(morning|subah|savere)\b/', $s)) {
                $ap = 'am';
            } elseif (preg_match('/\b(afternoon|evening|dopahar|dopehar|shaam|sham)\b/', $s)) {
                $ap = 'pm';
            } elseif (preg_match('/\b(night|tonight|raat)\b/', $s) && $hour >= 1 && $hour <= 12) {
                $ap = $hour <= 5 ? 'am' : 'pm';
            }
        }
        if ($ap === 'am') {
            $hours = [$hour % 12];
        } elseif ($ap === 'pm') {
            $hours = [$hour % 12 + 12];
        } elseif ($hour >= 13 || $hour === 0) {
            $hours = [$hour];
        } else {
            $hours = [$hour % 12, $hour % 12 + 12];   // "at 2:30" — whichever comes first
        }

        $best = null;
        foreach ([0, 1] as $extra) {
            foreach ($hours as $hh) {
                $d = (clone $day)->setTime($hh, $min);
                if ($extra > 0) {
                    $d->modify($weekday !== null ? '+7 days' : '+1 day');
                }
                $t = $d->getTimestamp();
                if ($t > $now && ($best === null || $t < $best)) {
                    $best = $t;
                }
            }
            if ($best !== null || ($fixed && $weekday === null)) {
                break;   // a named day that has passed stays passed — the caller says so
            }
        }
        return $best ?? ($fixed ? (clone $day)->setTime($hours[0], $min)->getTimestamp() : null);
    }

    /** [hour, minute, 'am'|'pm'|''] of the clock time in a phrase, or null. */
    private static function clockOf(string $s): ?array
    {
        $ap = fn(?string $v) => $v === null || $v === '' ? '' : str_replace('.', '', $v);
        if (preg_match('/\b(\d{1,2}):(\d{2})\s*(am|pm|a\.m\.|p\.m\.)?/', $s, $m)) {
            return [(int) $m[1], (int) $m[2], $ap($m[3] ?? '')];
        }
        if (preg_match('/\b(\d{1,2})\.(\d{2})\s*(am|pm|a\.m\.|p\.m\.)/', $s, $m)) {
            return [(int) $m[1], (int) $m[2], $ap($m[3])];
        }
        if (preg_match('/\b(\d{1,2})\s*(am|pm|a\.m\.|p\.m\.)/', $s, $m)) {
            return [(int) $m[1], 0, $ap($m[2])];
        }
        if (preg_match("/\\b(\\d{1,2})\\s*(baje|o'?clock)\\b/", $s, $m)) {
            return [(int) $m[1], 0, ''];
        }
        if (preg_match('/(?:^|\s)(?:at|@|by)\s*(\d{1,2})(?![\d:.])/', $s, $m)) {
            return [(int) $m[1], 0, ''];
        }
        if (preg_match('/^\d{1,2}$/', $s)) {
            return [(int) $s, 0, ''];
        }
        return null;
    }

    /** "25 oct" / "oct 25th" → that date at midnight in the phrase's zone (this year, or next if passed). */
    private static function dateOf(string $s, \DateTime $local): ?\DateTime
    {
        $mon = '(jan|feb|mar|apr|may|jun|jul|aug|sep|sept|oct|nov|dec)[a-z]*';
        if (preg_match('/\b(\d{1,2})(?:st|nd|rd|th)?\s+(?:of\s+)?' . $mon . '\b/', $s, $m)) {
            [$d, $mo] = [(int) $m[1], $m[2]];
        } elseif (preg_match('/\b' . $mon . '\s+(\d{1,2})(?:st|nd|rd|th)?\b/', $s, $m)) {
            [$d, $mo] = [(int) $m[2], $m[1]];
        } else {
            return null;
        }
        $n = (int) date('n', strtotime('1 ' . substr($mo, 0, 3) . ' 2000'));
        $y = (int) $local->format('Y');
        if ($d < 1 || $d > 31 || !checkdate($n, $d, $y)) {
            return null;
        }
        $day = (clone $local)->setDate($y, $n, $d)->setTime(0, 0);
        if ($day->getTimestamp() < $local->getTimestamp() - 86400) {
            $day->modify('+1 year');
        }
        return $day;
    }

    private static function setReminder(int $userId, string $when, string $what): array
    {
        [$what] = BuddyPromptBuilder::scrub(trim($what));
        $what = mb_substr($what, 0, 200);
        if ($what === '') {
            return ['error' => 'What should I remind you about?'];
        }
        $ts = self::parseWhen($when);
        if ($ts === null) {
            return ['error' => 'I could not read that time. Try "in 30 minutes", "at 2:30 am", "9pm eastern" or "2026-10-24 02:30".'];
        }
        if ($ts < time() + 30) {
            return ['error' => 'That time has already passed — pick a time in the future.'];
        }
        if ($ts > time() + 7 * 86400) {
            return ['error' => 'I can only keep reminders for the next 7 days.'];
        }
        $open = DB::table('buddy_nudges')->where('user_id', $userId)->where('type', 'reminder')
            ->where('status', 'pending')->count();
        if ($open >= 10) {
            return ['error' => 'You already have 10 reminders waiting. Cancel one first (get_my_reminders, cancel_reminder).'];
        }
        $due = date('Y-m-d H:i:s', $ts);
        $key = substr('reminder:' . $userId . ':' . $ts . ':' . substr(md5($what), 0, 10), 0, 120);
        try {
            $id = DB::table('buddy_nudges')->insertGetId([
                'user_id'      => $userId,
                'type'         => 'reminder',
                'payload_json' => json_encode(['text' => $what, 'due' => $due], JSON_UNESCAPED_UNICODE),
                'status'       => 'pending',
                'due_at'       => $due,
                'dedupe_key'   => $key,
                'created_at'   => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // The same reminder for the same minute exists already: asked twice,
            // or asked again after cancelling it. Either way it must fire.
            $id = DB::table('buddy_nudges')->where('dedupe_key', $key)->where('user_id', $userId)->value('id');
            if ($id === null) {
                throw $e;
            }
            DB::table('buddy_nudges')->where('id', $id)->update([
                'status' => 'pending', 'due_at' => $due, 'delivered_at' => null, 'acted_at' => null,
                'payload_json' => json_encode(['text' => $what, 'due' => $due], JSON_UNESCAPED_UNICODE),
            ]);
        }
        $et = new \DateTime('@' . $ts);
        $et->setTimezone(new \DateTimeZone('America/New_York'));
        return ['set' => true, 'id' => (int) $id, 'what' => $what,
                'when_india' => date('D j M, g:i A', $ts) . ' IST',
                'when_us_eastern' => $et->format('g:i A T')];
    }

    private static function reminders(int $userId): array
    {
        $rows = DB::table('buddy_nudges')->where('user_id', $userId)->where('type', 'reminder')
            ->where('status', 'pending')->orderBy('due_at')->limit(10)->get(['id', 'payload_json', 'due_at']);
        return ['reminders' => $rows->map(fn($r) => [
            'id'   => (int) $r->id,
            'what' => (json_decode((string) $r->payload_json, true) ?: [])['text'] ?? '',
            'when' => date('D j M, g:i A', strtotime((string) $r->due_at)) . ' IST',
        ])->all()];
    }

    private static function cancelReminder(int $userId, int $id): array
    {
        // ('done' arrives with the aisha_v2 migration, as do reminders themselves.)
        $n = DB::table('buddy_nudges')->where('id', $id)->where('user_id', $userId)
            ->where('type', 'reminder')->where('status', 'pending')
            ->update(['status' => 'done', 'acted_at' => date('Y-m-d H:i:s')]);
        return $n === 1 ? ['cancelled' => true] : ['error' => 'No waiting reminder with that id.'];
    }

    private static function memory(int $userId): array
    {
        $s = DB::table('buddy_settings')->where('user_id', $userId)->first(['display_name', 'extra_json']);
        $extra = json_decode((string) ($s->extra_json ?? ''), true) ?: [];
        $facts = DB::table('buddy_agent_facts')->where('user_id', $userId)->where('active', 1)
            ->orderByDesc('id')->limit(30)->get(['id', 'fact', 'created_at']);
        return [
            'preferred_name' => $s->display_name ?? null,
            'own_goal'       => $extra['goal'] ?? null,
            'facts'          => $facts->map(fn($f) => ['id' => (int) $f->id, 'fact' => $f->fact,
                                                       'saved' => substr((string) $f->created_at, 0, 10)])->all(),
        ];
    }

    /** Also used by the "What Aisha knows" screen. */
    public static function forget(int $userId, int $factId, string $words = ''): array
    {
        if ($factId > 0) {
            $n = DB::table('buddy_agent_facts')->where('id', $factId)->where('user_id', $userId)->where('active', 1)
                ->update(['active' => 0, 'updated_at' => date('Y-m-d H:i:s')]);
            return $n === 1 ? ['forgotten' => true] : ['error' => 'No saved fact with that id.'];
        }
        $words = trim($words);
        if ($words === '') {
            return ['error' => 'Say which fact to forget (its id, or a few words of it).'];
        }
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $words) . '%';
        $hits = DB::table('buddy_agent_facts')->where('user_id', $userId)->where('active', 1)
            ->where('fact', 'like', $like)->get(['id', 'fact']);
        if ($hits->count() === 0) {
            return ['error' => 'Nothing saved matches that. Use get_my_memory to see what is saved.'];
        }
        DB::table('buddy_agent_facts')->whereIn('id', $hits->pluck('id')->all())
            ->update(['active' => 0, 'updated_at' => date('Y-m-d H:i:s')]);
        return ['forgotten' => true, 'count' => $hits->count(), 'facts' => $hits->pluck('fact')->all()];
    }

    private static function team(int $userId, string $role): array
    {
        $leader = \App\Models\User::find($userId);
        $ids = $leader ? $leader->getTeamAgentIds() : [];
        if ($ids === []) {
            return ['team' => [], 'note' => 'No agents report to you in the CRM yet.'];
        }
        [$from, $to] = ShiftService::businessDayBounds();
        $people = DB::table('users')->whereIn('id', $ids)->orderBy('name')->get(['id', 'name']);
        $today = DB::table('transactions')->whereIn('agent_id', $ids)->where('status', 'approved')
            ->whereBetween('created_at', [$from, $to])->groupBy('agent_id')
            ->selectRaw('agent_id, COUNT(*) AS n, COALESCE(SUM(profit_mco - refund_mco_impact),0) AS net')->get()->keyBy('agent_id');
        $mq = DB::table('transactions')->whereIn('agent_id', $ids)->where('status', 'approved')
            ->whereBetween('created_at', [date('Y-m-01 00:00:00'), date('Y-m-d H:i:s')]);
        $mq = PerformanceHold::apply($mq, 'created_at', $role);
        $month = $mq->groupBy('agent_id')
            ->selectRaw('agent_id, COUNT(*) AS n, COALESCE(SUM(profit_mco - refund_mco_impact),0) AS net')->get()->keyBy('agent_id');
        // Split the way the e-ticket form splits them: an agent can only ticket
        // a booking an admin has marked "Charge Successful"; the rest are
        // waiting on that charge, not on the agent.
        $etix = DB::table('transactions AS t')->leftJoin('etickets AS e', 'e.transaction_id', '=', 't.id')
            ->whereIn('t.agent_id', $ids)->where('t.status', 'approved')
            ->where('t.created_at', '>=', date('Y-m-d H:i:s', time() - 14 * 86400))->whereNull('e.id')
            ->groupBy('t.agent_id')
            ->selectRaw("t.agent_id,
                SUM(CASE WHEN t.gateway_status = 'charge_successful' THEN 1 ELSE 0 END) AS ready,
                SUM(CASE WHEN t.gateway_status = 'charge_successful' THEN 0 ELSE 1 END) AS awaiting")
            ->get()->keyBy('agent_id');
        $inNow = DB::table('attendance_sessions')->whereIn('user_id', $ids)->where('status', 'active')
            ->pluck('user_id')->map(fn($v) => (int) $v)->all();

        $out = [
            'shift' => (string) $from . ' to ' . (string) $to,
            'team'  => [],
            'note'  => 'etickets_to_make = charged bookings the agent can ticket now; awaiting_charge = approved bookings '
                     . 'an admin has not marked "Charge Successful" yet (no e-ticket is possible until then).',
        ];
        foreach ($people as $p) {
            $id = (int) $p->id;
            $out['team'][] = [
                'name'              => $p->name,
                'clocked_in_now'    => in_array($id, $inNow, true),
                'today_sales'       => (int) ($today[$id]->n ?? 0),
                'today_net_mco'     => round((float) ($today[$id]->net ?? 0), 2),
                'month_sales'       => (int) ($month[$id]->n ?? 0),
                'month_net_mco'     => round((float) ($month[$id]->net ?? 0), 2),
                'etickets_to_make'  => (int) ($etix[$id]->ready ?? 0),
                'awaiting_charge'   => (int) ($etix[$id]->awaiting ?? 0),
            ];
        }
        $notice = PerformanceHold::notice($role);
        if ($notice !== null) {
            $out['hold_notice'] = $notice;
        }
        return $out;
    }
}
