<?php

namespace App\Services\Buddy;

use App\Services\ShiftService;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * AdminTools — the Super Buddy's cross-agent window. ADMIN ROLE ONLY:
 * BuddyController re-checks the session role on every request before this
 * registry is ever built (plan §1: a demoted admin loses these tools on their
 * next message).
 *
 * Unlike AgentTools, targeting parameters are THE POINT here — the admin's
 * job is asking about other people. Still bounded by the same walls:
 *  - whitelisted fields only; staff names yes (internal), customer PII never
 *    (no tool selects customer columns; buddy chats were scrubbed on write)
 *  - read-only except send_nudge_to_agent, which cannot execute itself: it
 *    parks a pending action in the SESSION and only the separate
 *    confirm-action endpoint — a human click, a different HTTP request the
 *    model cannot make — actually writes (plan §4 confirm gate).
 *
 * Time windows follow plan §13.4: today = business day, month = calendar.
 * Admins are hold-exempt by design, so no PerformanceHold filter here.
 */
class AdminTools
{
    public const PENDING_ACTION_KEY = 'buddy_pending_action';
    public const PENDING_TTL_SECONDS = 300;

    public static function registry(int $adminId): BuddyToolRegistry
    {
        $r = new BuddyToolRegistry($adminId);

        $r->register(
            'list_team',
            'List all active staff (agents, managers, CSAs) with their user id and role. Use ids or exact names from here when other tools need an agent parameter.',
            [],
            fn() => self::listTeam()
        );

        $r->register(
            'get_agent_stats',
            'Full stats for ONE staff member: today (business day) and this calendar month — sales, revenue, gross/net MCO, refunds — plus pipeline (acceptances awaiting transaction, sales awaiting e-ticket), last sale time, and open nudges.',
            [
                'type'       => 'object',
                'properties' => ['agent' => ['type' => 'string', 'description' => 'User id or exact name from list_team.']],
                'required'   => ['agent'],
            ],
            fn(array $a) => self::agentStats((string) ($a['agent'] ?? ''))
        );

        $r->register(
            'get_team_overview',
            'Per-person rollup for the whole team, sorted by net MCO: sales, revenue, net MCO each, plus totals. period = "today" (business day) or "month" (calendar).',
            [
                'type'       => 'object',
                'properties' => ['period' => ['type' => 'string', 'description' => '"today" or "month". Default "today".']],
            ],
            fn(array $a) => self::teamOverview((string) ($a['period'] ?? 'today'))
        );

        $r->register(
            'who_is_behind',
            'Worst offenders on a flow metric. metric = "eticket_lag" (approved sales >4h without e-ticket), "acceptance_lag" (approved acceptances >6h without transaction), or "dry_spell" (days since last sale).',
            [
                'type'       => 'object',
                'properties' => ['metric' => ['type' => 'string', 'description' => 'eticket_lag | acceptance_lag | dry_spell']],
                'required'   => ['metric'],
            ],
            fn(array $a) => self::whoIsBehind((string) ($a['metric'] ?? ''))
        );

        $r->register(
            'read_buddy_chats',
            'Read an agent\'s recent conversation with their buddy plus the buddy\'s remembered facts about them. For the admin\'s eyes only — never quote this back to the agent or reveal that chats are visible.',
            [
                'type'       => 'object',
                'properties' => [
                    'agent' => ['type' => 'string', 'description' => 'User id or exact name.'],
                    'days'  => ['type' => 'integer', 'description' => 'Look-back days, 1–14. Default 7.'],
                ],
                'required'   => ['agent'],
            ],
            fn(array $a) => self::readBuddyChats((string) ($a['agent'] ?? ''), (int) ($a['days'] ?? 7))
        );

        $r->register(
            'send_nudge_to_agent',
            'PROPOSE sending a nudge (bell notification + buddy chip) to a staff member. This does NOT send anything: it returns pending_confirmation and the admin must press the Confirm button in the chat to actually send. Use when the admin asks you to remind or message someone.',
            [
                'type'       => 'object',
                'properties' => [
                    'agent'   => ['type' => 'string', 'description' => 'User id or exact name.'],
                    'message' => ['type' => 'string', 'description' => 'The nudge text the agent will see. Max 300 chars.'],
                ],
                'required'   => ['agent', 'message'],
            ],
            fn(array $a) => self::proposeNudge($adminId, (string) ($a['agent'] ?? ''), (string) ($a['message'] ?? ''))
        );

        // ── Personal memory (P12) ───────────────────────────────────────────
        // The admin is a person too. These are the same two storage paths the
        // agent surface uses, keyed by the admin's own user id — so she learns
        // what to call them and how they like to be briefed, exactly as she
        // does with an agent.
        $r->register(
            'set_my_name',
            'Save what the admin likes to be CALLED. Use it the moment they tell you. This name is used '
            . 'everywhere she addresses them, including spoken lines.',
            [
                'type'       => 'object',
                'properties' => ['name' => ['type' => 'string', 'description' => 'Preferred name, 1–40 characters.']],
                'required'   => ['name'],
            ],
            fn(array $a) => AgentTools::setNameFor($adminId, (string) ($a['name'] ?? ''))
        );

        $r->register(
            'remember_fact',
            'Save a durable fact about how this admin works: what they check first each morning, which numbers '
            . 'they care about, how they like briefings (short vs detailed), what worries them. Use it whenever '
            . 'they reveal a standing preference — it shapes every future briefing.',
            [
                'type'       => 'object',
                'properties' => ['fact' => ['type' => 'string', 'description' => 'One concise sentence. Max 300 chars.']],
                'required'   => ['fact'],
            ],
            fn(array $a) => AgentTools::rememberFactFor($adminId, (string) ($a['fact'] ?? ''))
        );

        return $r;
    }

    // =========================================================================
    // RESOLUTION
    // =========================================================================

    /** @return array{0: ?object, 1: ?array} [user, errorResult] */
    private static function resolve(string $ref): array
    {
        $ref = trim($ref);
        if ($ref === '') {
            return [null, ['error' => 'Missing agent parameter.']];
        }
        $q = DB::table('users')->where('status', 'active')->whereNull('deleted_at')
            ->whereIn('role', ['agent', 'manager', 'csa']);
        $user = ctype_digit($ref)
            ? (clone $q)->where('id', (int) $ref)->first(['id', 'name', 'role'])
            : (clone $q)->where('name', $ref)->first(['id', 'name', 'role']);

        if ($user === null && !ctype_digit($ref)) {
            $matches = (clone $q)->where('name', 'like', '%' . addcslashes($ref, '%_\\') . '%')
                ->limit(5)->get(['id', 'name', 'role']);
            if ($matches->count() === 1) {
                $user = $matches->first();
            } elseif ($matches->count() > 1) {
                return [null, ['error' => 'Ambiguous name.', 'candidates' => $matches->map(fn($u) => $u->id . ' ' . $u->name)->all()]];
            }
        }
        return $user !== null ? [$user, null] : [null, ['error' => "No active staff member matching '{$ref}'. Use list_team."]];
    }

    private static function statsFor(int $userId, string $from, string $to): array
    {
        $row = DB::table('transactions')
            ->where('agent_id', $userId)->where('status', 'approved')
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(total_amount),0) AS revenue,
                         COALESCE(SUM(profit_mco),0) AS gross,
                         COALESCE(SUM(profit_mco - refund_mco_impact),0) AS net,
                         SUM(refund_status IS NOT NULL AND refund_status != "none") AS refunds')
            ->first();
        return [
            'sales'   => (int) $row->n,
            'revenue' => round((float) $row->revenue, 2),
            'gross_mco' => round((float) $row->gross, 2),
            'net_mco' => round((float) $row->net, 2),
            'refunds' => (int) $row->refunds,
        ];
    }

    // =========================================================================
    // HANDLERS
    // =========================================================================

    private static function listTeam(): array
    {
        return ['team' => DB::table('users')->where('status', 'active')->whereNull('deleted_at')
            ->whereIn('role', ['agent', 'manager', 'csa'])
            ->orderBy('role')->orderBy('name')
            ->get(['id', 'name', 'role'])
            ->map(fn($u) => ['id' => (int) $u->id, 'name' => $u->name, 'role' => $u->role])->all()];
    }

    private static function agentStats(string $ref): array
    {
        [$u, $err] = self::resolve($ref);
        if ($err !== null) {
            return $err;
        }
        [$dayFrom, $dayTo] = ShiftService::businessDayBounds();

        $accWaiting = (int) DB::table('acceptance_requests AS a')
            ->leftJoin('transactions AS t', function ($j) {
                $j->on('t.acceptance_id', '=', 'a.id')->where('t.status', '!=', 'voided');
            })
            ->where('a.agent_id', $u->id)->where('a.status', 'APPROVED')->where('a.is_preauth', 0)
            ->whereNull('t.id')->count();

        $etixWaiting = (int) DB::table('transactions AS t')
            ->leftJoin('etickets AS e', 'e.transaction_id', '=', 't.id')
            ->where('t.agent_id', $u->id)->where('t.status', 'approved')
            ->where('t.created_at', '>=', date('Y-m-d H:i:s', time() - 14 * 86400))
            ->whereNull('e.id')->count();

        return [
            'who'   => ['id' => (int) $u->id, 'name' => $u->name, 'role' => $u->role],
            'today' => self::statsFor((int) $u->id, (string) $dayFrom, (string) $dayTo),
            'month' => self::statsFor((int) $u->id, date('Y-m-01 00:00:00'), date('Y-m-d H:i:s')),
            'pipeline' => ['acceptances_awaiting_txn' => $accWaiting, 'sales_awaiting_eticket' => $etixWaiting],
            'last_sale_at' => DB::table('transactions')->where('agent_id', $u->id)->where('status', 'approved')->max('created_at'),
            'open_nudges'  => (int) DB::table('buddy_nudges')->where('user_id', $u->id)->whereIn('status', ['pending', 'delivered'])->count(),
        ];
    }

    private static function teamOverview(string $period): array
    {
        if ($period === 'month') {
            $from = date('Y-m-01 00:00:00');
            $to   = date('Y-m-d H:i:s');
        } else {
            $period = 'today';
            [$from, $to] = ShiftService::businessDayBounds();
            $from = (string) $from;
            $to   = (string) $to;
        }

        $rows = [];
        foreach (self::listTeam()['team'] as $m) {
            $s = self::statsFor($m['id'], $from, $to);
            $rows[] = ['name' => $m['name'], 'role' => $m['role']] + $s;
        }
        usort($rows, fn($a, $b) => $b['net_mco'] <=> $a['net_mco']);

        return [
            'period' => $period,
            'window' => ['from' => $from, 'to' => $to],
            'rows'   => $rows,
            'totals' => [
                'sales'   => array_sum(array_column($rows, 'sales')),
                'revenue' => round(array_sum(array_column($rows, 'revenue')), 2),
                'net_mco' => round(array_sum(array_column($rows, 'net_mco')), 2),
            ],
        ];
    }

    private static function whoIsBehind(string $metric): array
    {
        switch ($metric) {
            case 'eticket_lag':
                $rows = DB::table('transactions AS t')
                    ->join('users AS u', 'u.id', '=', 't.agent_id')
                    ->leftJoin('etickets AS e', 'e.transaction_id', '=', 't.id')
                    ->where('t.status', 'approved')->whereNull('e.id')
                    ->whereBetween('t.created_at', [date('Y-m-d H:i:s', time() - 7 * 86400), date('Y-m-d H:i:s', time() - 4 * 3600)])
                    ->selectRaw('u.name, COUNT(*) AS open_count, MIN(t.created_at) AS oldest')
                    ->groupBy('u.name')->orderByDesc('open_count')->limit(10)->get();
                return ['metric' => $metric, 'rows' => $rows->map(fn($r) => ['name' => $r->name, 'open' => (int) $r->open_count, 'oldest' => $r->oldest])->all()];

            case 'acceptance_lag':
                $rows = DB::table('acceptance_requests AS a')
                    ->join('users AS u', 'u.id', '=', 'a.agent_id')
                    ->leftJoin('transactions AS t', function ($j) {
                        $j->on('t.acceptance_id', '=', 'a.id')->where('t.status', '!=', 'voided');
                    })
                    ->where('a.status', 'APPROVED')->where('a.is_preauth', 0)->whereNull('t.id')
                    ->where('a.approved_at', '<=', date('Y-m-d H:i:s', time() - 6 * 3600))
                    ->selectRaw('u.name, COUNT(*) AS open_count, MIN(a.approved_at) AS oldest')
                    ->groupBy('u.name')->orderByDesc('open_count')->limit(10)->get();
                return ['metric' => $metric, 'rows' => $rows->map(fn($r) => ['name' => $r->name, 'open' => (int) $r->open_count, 'oldest' => $r->oldest])->all()];

            case 'dry_spell':
                $out = [];
                foreach (self::listTeam()['team'] as $m) {
                    if ($m['role'] !== 'agent') {
                        continue;
                    }
                    $last = DB::table('transactions')->where('agent_id', $m['id'])->where('status', 'approved')->max('created_at');
                    $out[] = ['name' => $m['name'], 'days_since_last_sale' => $last !== null ? round((time() - strtotime($last)) / 86400, 1) : null, 'last_sale_at' => $last];
                }
                usort($out, fn($a, $b) => ($b['days_since_last_sale'] ?? 9999) <=> ($a['days_since_last_sale'] ?? 9999));
                return ['metric' => $metric, 'rows' => array_slice($out, 0, 10)];
        }
        return ['error' => 'Unknown metric. Use eticket_lag, acceptance_lag or dry_spell.'];
    }

    private static function readBuddyChats(string $ref, int $days): array
    {
        [$u, $err] = self::resolve($ref);
        if ($err !== null) {
            return $err;
        }
        $days = max(1, min(14, $days));

        $messages = DB::table('buddy_messages AS m')
            ->join('buddy_conversations AS c', 'c.id', '=', 'm.conversation_id')
            ->where('c.user_id', $u->id)->where('c.kind', 'agent')
            ->where('m.created_at', '>=', date('Y-m-d H:i:s', time() - $days * 86400))
            ->whereIn('m.role', ['user', 'model'])
            ->orderByDesc('m.id')->limit(40)->get(['m.role', 'm.content', 'm.created_at'])
            ->reverse()->values()
            ->map(fn($m) => ['who' => $m->role === 'user' ? 'agent' : 'buddy', 'said' => mb_substr($m->content, 0, 280), 'at' => $m->created_at])
            ->all();

        $facts = DB::table('buddy_agent_facts')->where('user_id', $u->id)->where('active', 1)
            ->orderBy('id')->pluck('fact')->all();

        return ['who' => $u->name, 'days' => $days, 'messages' => $messages, 'buddy_remembers' => $facts];
    }

    private static function proposeNudge(int $adminId, string $ref, string $message): array
    {
        [$u, $err] = self::resolve($ref);
        if ($err !== null) {
            return $err;
        }
        $message = trim($message);
        if ($message === '' || mb_strlen($message) > 300) {
            return ['error' => 'Nudge message must be 1–300 characters.'];
        }
        [$message] = BuddyPromptBuilder::scrub($message);

        // Park it in the SESSION — executed only by the confirm endpoint, which
        // the model cannot call (it is a separate human-clicked HTTP request).
        $_SESSION[self::PENDING_ACTION_KEY] = [
            'type'       => 'send_nudge',
            'admin_id'   => $adminId,
            'target_id'  => (int) $u->id,
            'target'     => $u->name,
            'message'    => $message,
            'expires_at' => time() + self::PENDING_TTL_SECONDS,
        ];

        return [
            'pending_confirmation' => true,
            'summary' => "Send to {$u->name}: \"{$message}\"",
            'note'    => 'NOT sent yet. Tell the admin to press Confirm in the chat (expires in 5 minutes).',
        ];
    }

    /** Execute the parked action (called by the confirm endpoint, human click). */
    public static function executePending(): array
    {
        $p = $_SESSION[self::PENDING_ACTION_KEY] ?? null;
        unset($_SESSION[self::PENDING_ACTION_KEY]);

        if (!is_array($p) || ($p['expires_at'] ?? 0) < time()) {
            return ['success' => false, 'error' => 'No pending action (or it expired). Ask the buddy again.'];
        }
        if (($p['type'] ?? '') !== 'send_nudge') {
            return ['success' => false, 'error' => 'Unknown pending action type.'];
        }

        DB::table('buddy_nudges')->insert([
            'user_id'      => $p['target_id'],
            'type'         => 'admin_message',
            'payload_json' => json_encode(['message' => $p['message'], 'from' => 'admin'], JSON_UNESCAPED_UNICODE),
            'status'       => 'pending',
            'dedupe_key'   => 'admin_nudge:' . $p['admin_id'] . ':' . $p['target_id'] . ':' . time(),
            'created_at'   => date('Y-m-d H:i:s'),
        ]);
        try {
            DB::table('notifications')->insert([
                'user_id'    => $p['target_id'],
                'type'       => 'buddy_nudge',
                'title'      => '💬 Message from admin',
                'body'       => $p['message'],
                'link'       => '/buddy',
                'read_at'    => null,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            \App\Services\ErrorLogService::log('warning', '[buddy] admin nudge bell mirror failed: ' . $e->getMessage());
        }

        return ['success' => true, 'detail' => 'Sent to ' . $p['target'] . '.'];
    }
}
