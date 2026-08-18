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
    public static function knowledgeGaps(int $userId): array
    {
        $gaps = [];
        try {
            $settings = DB::table('buddy_settings')->where('user_id', $userId)
                ->first(['display_name', 'extra_json']);

            if (empty($settings->display_name)) {
                $gaps[] = 'their preferred name — what they like to be called (save with set_my_name)';
            }

            $extra = json_decode((string) ($settings->extra_json ?? ''), true) ?: [];
            if (empty($extra['goal'])) {
                $gaps[] = 'whether they want a monthly goal to chase (set_my_goal — offer, never impose)';
            }

            $factCount = (int) DB::table('buddy_agent_facts')
                ->where('user_id', $userId)->where('active', 1)->count();
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
}
