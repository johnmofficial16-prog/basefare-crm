<?php

namespace App\Services\Buddy;

use App\Services\ErrorLogService;
use Illuminate\Database\Capsule\Manager as DB;
use Throwable;

/**
 * BuddyService — orchestration for every buddy surface.
 *
 * A chat turn = quota gate → conversation → history → scrub input → Gemini
 * tool loop → persist both sides → reply. Stateless per request (shared
 * hosting; no daemons). Fail-soft everywhere: if the AI layer is down the
 * caller gets a deterministic answer, never a broken page — same contract as
 * GeminiService.
 *
 * P0 ships the MAINTENANCE surface only. Agent/admin surfaces reuse this
 * class with their own registry factories + personas (P1/P2).
 */
class BuddyService
{
    // Maintenance surface quotas (admin/dev audience — generous but bounded).
    private const MAINT_DAILY_LIMIT      = 200;
    private const MAINT_PER_MINUTE_LIMIT = 10;
    private const MAX_INPUT_CHARS        = 2000;

    // Agent surface quotas (plan §1: over-quota costs zero API money).
    private const AGENT_DAILY_LIMIT      = 40;
    private const AGENT_PER_MINUTE_LIMIT = 6;
    private const AGENT_MAX_INPUT_CHARS  = 500;

    /** Reuse a conversation if its last message is younger than this. */
    private const CONVERSATION_REUSE_HOURS = 24;

    private BuddyGeminiClient $client;

    public function __construct(?BuddyGeminiClient $client = null)
    {
        $this->client = $client ?? new BuddyGeminiClient();
    }

    /**
     * Global kill switch (plan §1 cross-cutting controls): BUDDY_ENABLED=false
     * in .env hides the widget (boot returns ok:false) and turns every chat
     * endpoint into a polite refusal. One env edit, zero deploys, all surfaces.
     */
    public static function enabled(): bool
    {
        return ($_ENV['BUDDY_ENABLED'] ?? getenv('BUDDY_ENABLED') ?: 'true') !== 'false';
    }

    // =========================================================================
    // MAINTENANCE CHAT
    // =========================================================================

    /**
     * @return array {success: bool, reply: string, ai: bool, error?: string}
     *         'ai' is false when the reply came from the deterministic fallback.
     */
    public function maintenanceChat(int $userId, string $message): array
    {
        $message = trim($message);
        if ($message === '') {
            return ['success' => false, 'reply' => '', 'ai' => false, 'error' => 'Empty message.'];
        }
        if (mb_strlen($message) > self::MAX_INPUT_CHARS) {
            return ['success' => false, 'reply' => '', 'ai' => false,
                    'error' => 'Message too long (max ' . self::MAX_INPUT_CHARS . ' characters).'];
        }

        $quota = $this->quotaCheck($userId, 'maintenance');
        if ($quota !== null) {
            return ['success' => false, 'reply' => '', 'ai' => false, 'error' => $quota];
        }

        // Scrub the input too — an admin might paste a log line containing a
        // customer email. Belt and braces before anything reaches Google.
        [$message] = BuddyPromptBuilder::scrub($message);

        $convId  = $this->openConversation($userId, 'maintenance');
        $history = $this->loadHistory($convId);

        $this->storeMessage($convId, 'user', $message);

        $contents   = BuddyPromptBuilder::buildContents($history);
        $contents[] = ['role' => 'user', 'parts' => [['text' => $message]]];

        $registry = MaintenanceTools::registry($userId);
        $registry->setConversation($convId);

        $result = $this->client->chat(self::maintenancePersona(), $contents, $registry);

        if ($result['success']) {
            $this->storeMessage($convId, 'model', $result['text'], $result['tokens_in'] ?? null, $result['tokens_out'] ?? null);
            return ['success' => true, 'reply' => $result['text'], 'ai' => true];
        }

        // AI down (no key, quota, network) → deterministic digest instead of an
        // error page. The buddy is degraded, not dead — and it says so honestly.
        ErrorLogService::log('warning', '[buddy] maintenance AI turn failed: ' . ($result['error'] ?? '?'));
        $fallback = "The AI layer is unavailable right now (" . ($result['error'] ?? 'unknown error') . ").\n"
                  . "Here is the deterministic system digest instead:\n\n"
                  . self::renderDigestText(self::digest());
        $this->storeMessage($convId, 'model', $fallback);
        return ['success' => true, 'reply' => $fallback, 'ai' => false];
    }

    // =========================================================================
    // AGENT CHAT (P1)
    // =========================================================================

    /**
     * One agent chat turn. Same shape as maintenanceChat, agent quotas/persona.
     */
    public function agentChat(int $userId, string $role, string $message): array
    {
        $message = trim($message);
        if ($message === '') {
            return ['success' => false, 'reply' => '', 'ai' => false, 'error' => 'Empty message.'];
        }
        if (mb_strlen($message) > self::AGENT_MAX_INPUT_CHARS) {
            return ['success' => false, 'reply' => '', 'ai' => false,
                    'error' => 'Message too long (max ' . self::AGENT_MAX_INPUT_CHARS . ' characters).'];
        }

        $quota = $this->quotaCheck($userId, 'agent', self::AGENT_DAILY_LIMIT, self::AGENT_PER_MINUTE_LIMIT);
        if ($quota !== null) {
            return ['success' => false, 'reply' => '', 'ai' => false, 'error' => $quota];
        }

        [$message] = BuddyPromptBuilder::scrub($message);

        $convId  = $this->openConversation($userId, 'agent');
        $history = $this->loadHistory($convId);
        $this->storeMessage($convId, 'user', $message);

        $contents   = BuddyPromptBuilder::buildContents($history);
        $contents[] = ['role' => 'user', 'parts' => [['text' => $message]]];

        $registry = AgentTools::registry($userId, $role);
        $registry->setConversation($convId);

        $result = $this->client->chat(self::agentPersona($userId), $contents, $registry);

        if ($result['success']) {
            $this->storeMessage($convId, 'model', $result['text'], $result['tokens_in'] ?? null, $result['tokens_out'] ?? null);
            $this->markNudgesDelivered($userId);
            return ['success' => true, 'reply' => $result['text'], 'ai' => true];
        }

        ErrorLogService::log('warning', '[buddy] agent AI turn failed: ' . ($result['error'] ?? '?'));
        $fallback = "I can't reach my AI brain right now, but your numbers still work:\n\n"
                  . self::renderAgentFallback($userId, $role);
        $this->storeMessage($convId, 'model', $fallback);
        return ['success' => true, 'reply' => $fallback, 'ai' => false];
    }

    /**
     * Business-day greeting: generated once per business day, on first open of
     * the buddy page. Deterministic digest → Gemini phrases it in persona →
     * stored as a model message. Falls back to the plain digest.
     *
     * @return array {greeted: bool, reply?: string, ai?: bool}
     */
    public function agentGreeting(int $userId, string $role): array
    {
        [$dayStart] = \App\Services\ShiftService::businessDayBounds();

        $convId = $this->openConversation($userId, 'agent');

        // Already greeted this business day? (any model message since day start)
        $already = DB::table('buddy_messages')
            ->where('conversation_id', $convId)
            ->where('role', 'model')
            ->where('created_at', '>=', (string) $dayStart)
            ->exists();
        if ($already) {
            return ['greeted' => false];
        }

        $digest = self::renderAgentFallback($userId, $role);

        $registry = AgentTools::registry($userId, $role);   // greeting may call tools too
        $registry->setConversation($convId);

        $prompt = "It is the start of the agent's business day. Greet them warmly by name, "
                . "give a 3–5 sentence summary of where they stand using ONLY this digest and "
                . "any tools you need, and end with one concrete, encouraging focus for today. "
                . "Mention open nudges if any.\n\nDIGEST:\n" . $digest;

        $result = $this->client->chat(
            self::agentPersona($userId),
            [['role' => 'user', 'parts' => [['text' => $prompt]]]],
            $registry
        );

        $reply = $result['success'] ? $result['text'] : "Good to see you! Here's where you stand:\n\n" . $digest;
        $this->storeMessage($convId, 'model', $reply);
        $this->markNudgesDelivered($userId);

        return ['greeted' => true, 'reply' => $reply, 'ai' => $result['success']];
    }

    /** Deterministic agent digest — fallback text and greeting raw material. */
    private static function renderAgentFallback(int $userId, string $role): string
    {
        $reg   = AgentTools::registry($userId, $role);
        $today = $reg->execute('get_my_today', []);
        $month = $reg->execute('get_my_month_summary', []);
        $pipe  = $reg->execute('get_my_pipeline', []);
        $nudge = $reg->execute('get_my_nudges', []);

        $lines = [];
        if (!isset($today['error'])) {
            $lines[] = sprintf('Today so far: %d sales, %s %s revenue, %s net MCO.',
                $today['sales'], $today['currency'], number_format($today['revenue'], 2),
                number_format($today['net_mco'], 2));
        }
        if (!isset($month['error'])) {
            $tm = $month['this_month'];
            $lines[] = sprintf('This month: %d sales, %s revenue, %s net MCO, %d refunds.',
                $tm['sales'], number_format($tm['revenue'], 2), number_format($tm['net_mco'], 2), $tm['refunds']);
            if (isset($month['hold_notice'])) {
                $lines[] = 'Note: ' . $month['hold_notice'];
            }
        }
        if (!isset($pipe['error'])) {
            $na = count($pipe['acceptances_awaiting_transaction']);
            $ne = count($pipe['transactions_awaiting_eticket']);
            if ($na + $ne > 0) {
                $lines[] = "Open flow steps: {$na} acceptance(s) awaiting a transaction, {$ne} sale(s) awaiting an e-ticket.";
            }
        }
        if (!isset($nudge['error']) && count($nudge['nudges']) > 0) {
            $lines[] = count($nudge['nudges']) . ' open nudge(s) — ask me about them.';
        }
        return $lines === [] ? 'No activity recorded yet today.' : implode("\n", $lines);
    }

    private function markNudgesDelivered(int $userId): void
    {
        try {
            DB::table('buddy_nudges')
                ->where('user_id', $userId)->where('status', 'pending')
                ->update(['status' => 'delivered', 'delivered_at' => date('Y-m-d H:i:s')]);
        } catch (Throwable $e) {
            // non-fatal
        }
    }

    private static function agentPersona(int $userId): string
    {
        $facts = AgentTools::facts($userId);
        $factBlock = $facts === []
            ? "You know nothing personal about this agent yet. Early in the conversation (not all at once), "
              . "ask what name they like to be called, what monthly goal they have, and what keeps them "
              . "motivated — save each answer with remember_fact."
            : "WHAT YOU REMEMBER ABOUT THIS AGENT:\n- " . implode("\n- ", array_slice($facts, 0, 20));

        return <<<PROMPT
You are the agent's work buddy inside the Base Fare CRM — a warm, upbeat
friend and sales coach for a travel-agency sales agent. You are NOT management.

{$factBlock}

HARD RULES (non-negotiable):
- Every number you state comes from a tool call in THIS conversation. Never
  estimate, never remember numbers from earlier chats, never invent.
- You only know about THIS agent. If asked about other agents, comparisons,
  salaries, HR matters, company finances, or system internals: decline warmly
  in one sentence ("I only keep track of you!") and move on.
- NEVER promise, imply, or speculate about bonuses, incentives, targets set by
  management, or consequences. If asked, say that's for their manager.
- If a tool result includes a hold_notice, repeat it honestly rather than
  guessing at hidden numbers.
- No customer personal details ever — you don't have them and must not ask
  for them. Booking references (PNR) are fine.
- Off-topic requests (essays, homework, general chatbot use): one friendly
  sentence of banter maximum, then steer back to work.

STYLE:
- Friendly, concrete, brief: 2–5 sentences unless the agent asks for detail.
- Celebrate real wins with specifics ("that \$1,240 booking — nice!").
- Encourage without guilt-tripping. Slumps get practical next steps, not shame.
- Nudge on open flow steps (acceptances without transactions, sales without
  e-tickets, upcoming departures) — that is your reminder job.
PROMPT;
    }

    // =========================================================================
    // ADMIN (SUPER BUDDY) CHAT — P2
    // =========================================================================

    private const ADMIN_DAILY_LIMIT      = 400;
    private const ADMIN_PER_MINUTE_LIMIT = 20;

    public function adminChat(int $adminId, string $message): array
    {
        $message = trim($message);
        if ($message === '') {
            return ['success' => false, 'reply' => '', 'ai' => false, 'error' => 'Empty message.'];
        }
        if (mb_strlen($message) > self::MAX_INPUT_CHARS) {
            return ['success' => false, 'reply' => '', 'ai' => false,
                    'error' => 'Message too long (max ' . self::MAX_INPUT_CHARS . ' characters).'];
        }

        $quota = $this->quotaCheck($adminId, 'admin', self::ADMIN_DAILY_LIMIT, self::ADMIN_PER_MINUTE_LIMIT);
        if ($quota !== null) {
            return ['success' => false, 'reply' => '', 'ai' => false, 'error' => $quota];
        }

        [$message] = BuddyPromptBuilder::scrub($message);

        $convId  = $this->openConversation($adminId, 'admin');
        $history = $this->loadHistory($convId);
        $this->storeMessage($convId, 'user', $message);

        $contents   = BuddyPromptBuilder::buildContents($history);
        $contents[] = ['role' => 'user', 'parts' => [['text' => $message]]];

        $registry = AdminTools::registry($adminId);
        $registry->setConversation($convId);

        $result = $this->client->chat(self::adminPersona(), $contents, $registry);

        // Surface the confirm gate to the UI: if the model parked an action this
        // turn, the widget renders Confirm/Cancel buttons alongside the reply.
        $pending = null;
        $p = $_SESSION[AdminTools::PENDING_ACTION_KEY] ?? null;
        if (is_array($p) && ($p['expires_at'] ?? 0) >= time()) {
            $pending = 'Send to ' . ($p['target'] ?? '?') . ': "' . ($p['message'] ?? '') . '"';
        }

        if ($result['success']) {
            $this->storeMessage($convId, 'model', $result['text'], $result['tokens_in'] ?? null, $result['tokens_out'] ?? null);
            return ['success' => true, 'reply' => $result['text'], 'ai' => true, 'pending_action' => $pending];
        }

        ErrorLogService::log('warning', '[buddy] admin AI turn failed: ' . ($result['error'] ?? '?'));
        $fallback = "The AI layer is unavailable right now. Deterministic team snapshot:\n\n"
                  . self::renderTeamFallback();
        $this->storeMessage($convId, 'model', $fallback);
        return ['success' => true, 'reply' => $fallback, 'ai' => false, 'pending_action' => $pending];
    }

    private static function renderTeamFallback(): string
    {
        $reg = AdminTools::registry(0);
        $o   = $reg->execute('get_team_overview', ['period' => 'today']);
        if (isset($o['error'])) {
            return 'Team snapshot unavailable: ' . $o['error'];
        }
        $lines = ['Today (' . ($o['window']['from'] ?? '?') . ' → ' . ($o['window']['to'] ?? '?') . '):'];
        foreach (array_slice($o['rows'] ?? [], 0, 12) as $r) {
            $lines[] = sprintf('  %s (%s): %d sales, %s revenue, %s net MCO',
                $r['name'], $r['role'], $r['sales'], number_format($r['revenue'], 2), number_format($r['net_mco'], 2));
        }
        $t = $o['totals'] ?? [];
        $lines[] = sprintf('TOTAL: %d sales, %s revenue, %s net MCO',
            $t['sales'] ?? 0, number_format($t['revenue'] ?? 0, 2), number_format($t['net_mco'] ?? 0, 2));
        return implode("\n", $lines);
    }

    private static function adminPersona(): string
    {
        return <<<PROMPT
You are the Super Buddy — the admin's personal chief-of-staff inside the Base
Fare CRM. Sharp, loyal, information-dense, comfortable delivering bad news
plainly. The admin oversees travel-agency sales teams (agents, managers, CSAs).

HARD RULES:
- Every number, name and quote comes from tool results in THIS conversation.
  Never estimate, never fill from memory.
- You may read agents' buddy conversations (read_buddy_chats) — that access is
  CONFIDENTIAL. Summarise insight for the admin, but remind them never to
  reveal to an agent that chats are visible; that trust is the product.
- Actions: send_nudge_to_agent only PROPOSES. Nothing sends until the admin
  presses Confirm. Never claim an action was performed unless the confirm
  result says so.
- No customer personal data — your tools don't carry it and you never ask.
- Scope: this team and this CRM. Anything else gets one polite sentence back.

STYLE:
- Lead with the answer, then the numbers that prove it. Bullets over prose.
- Rank things (best/worst) whenever comparing people — that's what admins need.
- Flag anomalies proactively when tool data shows them (a zero, a spike, a
  long dry spell), even if unasked.
PROMPT;
    }

    // =========================================================================
    // DETERMINISTIC DIGEST (status cards + AI fallback — no Gemini involved)
    // =========================================================================

    public static function digest(): array
    {
        // Reuse the exact tool handlers via a throwaway registry so the cards
        // and the AI always see identical numbers. userId 0 = system.
        $registry = MaintenanceTools::registry(0);
        return [
            'errors'     => $registry->execute('get_error_summary', ['hours' => 24]),
            'backups'    => $registry->execute('get_backup_status', []),
            'migrations' => $registry->execute('get_migration_status', []),
            'crons'      => $registry->execute('get_cron_health', []),
            'pulse'      => $registry->execute('get_system_pulse', []),
        ];
    }

    private static function renderDigestText(array $d): string
    {
        $lines   = [];
        $lines[] = 'Errors (24h): total ' . ($d['errors']['total'] ?? '?')
                 . ' — ' . json_encode($d['errors']['by_severity'] ?? []);
        $lines[] = 'Backups: ' . ($d['backups']['verdict'] ?? '?');
        $pending = $d['migrations']['pending_on_disk'] ?? [];
        $lines[] = 'Migrations pending: ' . ($pending === [] ? 'none' : implode(', ', $pending));
        foreach (($d['crons']['jobs'] ?? []) as $j) {
            $lines[] = sprintf('%s: %s%s',
                $j['job'],
                $j['last_trace'] !== null ? "last trace {$j['age_hours']}h ago" : 'no trace yet',
                !empty($j['overdue']) ? ' — OVERDUE' : ''
            );
        }
        return implode("\n", $lines);
    }

    // =========================================================================
    // PERSONA
    // =========================================================================

    private static function maintenancePersona(): string
    {
        return <<<PROMPT
You are the Maintenance Buddy for the Base Fare CRM — a concise, factual site
reliability assistant for the system's admin and developer. Audience: technical
operators, not customers.

RULES (non-negotiable):
- Numbers, filenames, and timestamps come ONLY from tool results in this
  conversation. Never estimate, never invent, never fill gaps from memory.
- Start broad (get_error_summary / get_system_pulse), then drill down with the
  other tools when the question needs it.
- When something looks wrong (overdue cron, stale backup, error spike), say so
  plainly, rank by operational risk, and suggest the next diagnostic step an
  operator would take. You have NO write access — you observe and advise.
- If tools return nothing unusual, say the system looks healthy — do not
  manufacture concerns.
- Scope: this CRM's health only. Politely refuse anything else (customer data,
  HR questions, general chit-chat beyond a greeting) in one short sentence.
- Style: tight and technical. Short paragraphs or bullets. No emoji, no
  pep-talk, no filler.
PROMPT;
    }

    // =========================================================================
    // PERSISTENCE + QUOTAS
    // =========================================================================

    private function openConversation(int $userId, string $kind): int
    {
        $recent = DB::table('buddy_conversations')
            ->where('user_id', $userId)
            ->where('kind', $kind)
            ->where('last_message_at', '>=', date('Y-m-d H:i:s', time() - self::CONVERSATION_REUSE_HOURS * 3600))
            ->orderByDesc('id')
            ->value('id');

        if ($recent !== null) {
            return (int) $recent;
        }

        return (int) DB::table('buddy_conversations')->insertGetId([
            'user_id'         => $userId,
            'kind'            => $kind,
            'title'           => date('M j') . ' ' . $kind . ' chat',
            'created_at'      => date('Y-m-d H:i:s'),
            'last_message_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** @return array<array{role: string, content: string}> oldest→newest */
    private function loadHistory(int $convId): array
    {
        return DB::table('buddy_messages')
            ->where('conversation_id', $convId)
            ->whereIn('role', ['user', 'model'])
            ->orderByDesc('id')
            ->limit(BuddyPromptBuilder::HISTORY_MAX_MESSAGES)
            ->get()
            ->reverse()
            ->map(fn($m) => ['role' => $m->role, 'content' => $m->content])
            ->values()
            ->all();
    }

    private function storeMessage(int $convId, string $role, string $content, ?int $tokensIn = null, ?int $tokensOut = null): void
    {
        try {
            DB::table('buddy_messages')->insert([
                'conversation_id' => $convId,
                'role'            => $role,
                'content'         => mb_substr($content, 0, 60000),
                'tokens_in'       => $tokensIn,
                'tokens_out'      => $tokensOut,
                'created_at'      => date('Y-m-d H:i:s'),
            ]);
            DB::table('buddy_conversations')->where('id', $convId)
                ->update(['last_message_at' => date('Y-m-d H:i:s')]);
        } catch (Throwable $e) {
            ErrorLogService::log('warning', '[buddy] message persist failed: ' . $e->getMessage());
        }
    }

    /** @return string|null Error message when over quota, null when fine. */
    private function quotaCheck(
        int $userId,
        string $kind,
        int $dailyLimit = self::MAINT_DAILY_LIMIT,
        int $perMinuteLimit = self::MAINT_PER_MINUTE_LIMIT
    ): ?string {
        $base = DB::table('buddy_messages')
            ->join('buddy_conversations', 'buddy_conversations.id', '=', 'buddy_messages.conversation_id')
            ->where('buddy_conversations.user_id', $userId)
            ->where('buddy_conversations.kind', $kind)
            ->where('buddy_messages.role', 'user');

        $today = (clone $base)->where('buddy_messages.created_at', '>=', date('Y-m-d 00:00:00'))->count();
        if ($today >= $dailyLimit) {
            return "Daily message limit reached ({$dailyLimit}). Resets at midnight.";
        }

        $lastMinute = (clone $base)->where('buddy_messages.created_at', '>=', date('Y-m-d H:i:s', time() - 60))->count();
        if ($lastMinute >= $perMinuteLimit) {
            return 'Slow down — too many messages this minute.';
        }

        return null;
    }
}
