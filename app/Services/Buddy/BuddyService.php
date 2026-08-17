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
        $dayKey = substr((string) $dayStart, 0, 10);

        // Claim the greeting BEFORE spending anything on it. A durable stamp in
        // buddy_settings, NOT "any model message since day start" — P5 feed
        // deliveries are model messages too and would swallow the greeting.
        //
        // The claim is atomic (a conditional UPDATE, checked by affected rows)
        // because two tabs opening together — or one impatient refresh — would
        // otherwise both pass a read-then-write check and greet twice, at twice
        // the Gemini cost.
        // Claim first, THEN open the conversation. The widget POSTs this on every
        // page load, so the already-greeted path — which is almost all of them —
        // must cost one indexed read, not a conversation lookup-or-insert.
        if (!$this->claimGreeting($userId, $dayKey)) {
            return ['greeted' => false];
        }

        $convId = $this->openConversation($userId, 'agent');

        $digest = self::renderAgentFallback($userId, $role);

        $registry = AgentTools::registry($userId, $role);   // greeting may call tools too
        $registry->setConversation($convId);

        $prompt = "It is the start of the agent's business day and they just opened their chat "
                . "with you. Greet them warmly BY NAME like a close friend who's happy to see "
                . "them, give a 3–5 sentence read on where they stand using ONLY this digest and "
                . "any tools you need, and end with one concrete, encouraging focus for today. "
                . "Mention open nudges if any. This greeting may be spoken aloud, so make it "
                . "sound natural said out loud.\n\nDIGEST:\n" . $digest;

        $result = $this->client->chat(
            self::agentPersona($userId),
            [['role' => 'user', 'parts' => [['text' => $prompt]]]],
            $registry
        );

        // The claim is already ours, so a Gemini failure must still produce a
        // greeting — otherwise the claim would burn the agent's only greeting
        // of the day on an error.
        $reply = $result['success'] ? $result['text'] : "Good to see you! Here's where you stand:\n\n" . $digest;
        $this->storeMessage($convId, 'model', $reply);
        // Nudges are NOT marked delivered here — the P5 feed is the single
        // delivery channel; specifics follow the greeting on the next poll.

        return ['greeted' => true, 'reply' => $reply, 'ai' => $result['success']];
    }

    /**
     * Atomically claim today's greeting for this user.
     *
     * @return bool true if THIS request won the claim and must now greet.
     *
     * The UPDATE's WHERE clause is the lock: it only matches rows that do not
     * already carry today's key, so exactly one concurrent request can see one
     * affected row. buddy_settings.extra_json has no other writer in the
     * codebase, so read-merging the rest of the blob is safe.
     */
    private function claimGreeting(int $userId, string $dayKey): bool
    {
        try {
            $row = DB::table('buddy_settings')->where('user_id', $userId)->first(['extra_json']);

            if ($row === null) {
                // First greeting ever for this user. A racing insert loses on the
                // PK and falls through to the conditional UPDATE below.
                try {
                    DB::table('buddy_settings')->insert([
                        'user_id'    => $userId,
                        'extra_json' => json_encode(['last_greeted_bday' => $dayKey], JSON_UNESCAPED_UNICODE),
                    ]);
                    return true;
                } catch (Throwable $e) {
                    $row = DB::table('buddy_settings')->where('user_id', $userId)->first(['extra_json']);
                    if ($row === null) {
                        return false;
                    }
                }
            }

            $extra = json_decode((string) ($row->extra_json ?? ''), true) ?: [];
            if (($extra['last_greeted_bday'] ?? null) === $dayKey) {
                return false;   // already greeted today — cheap path, no write
            }
            $extra['last_greeted_bday'] = $dayKey;

            $affected = DB::table('buddy_settings')
                ->where('user_id', $userId)
                ->where(function ($q) use ($dayKey) {
                    $q->whereNull('extra_json')
                      ->orWhere('extra_json', 'not like', '%"last_greeted_bday":"' . $dayKey . '"%');
                })
                ->update(['extra_json' => json_encode($extra, JSON_UNESCAPED_UNICODE)]);

            return $affected === 1;
        } catch (Throwable $e) {
            ErrorLogService::log('warning', '[buddy] greeting claim failed: ' . $e->getMessage());
            return false;   // fail closed: a missed greeting beats a doubled one
        }
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
        if (!isset($nudge['error'])) {
            $unread = count(array_filter($nudge['nudges'], fn($n) => !empty($n['unread'])));
            if ($unread > 0) {
                $lines[] = $unread . ' thing(s) I flagged for you — ask me about them.';
            }
        }
        return $lines === [] ? 'No activity recorded yet today.' : implode("\n", $lines);
    }

    // =========================================================================
    // NUDGE FEED (P5) — Aisha initiates
    // =========================================================================

    /**
     * Nudges phrased per drain. A backlog arrives as a conversation over a few
     * polls, not a wall of six toasts; the rest stay pending for the next poll.
     */
    private const FEED_BATCH_LIMIT = 3;

    /**
     * Delivery order when several nudges are pending: a real person's message
     * first, then celebrate, then urgency. admin_message is the only type a
     * human deliberately authored and confirmed — it outranks everything.
     */
    private const FEED_PRIORITY = [
        'admin_message', 'goal_hit', 'sale_praise_t2', 'sale_praise_t1',
        'departure_24h', 'eticket_lag', 'acceptance_lag', 'goal_pace', 'dry_spell',
    ];

    /**
     * Nudge payloads are a SNAPSHOT taken by the trigger cron: waiting_hours,
     * days_since_last_sale and departure times are all frozen at creation. A
     * nudge delivered long after it was raised would have Aisha state numbers
     * that are simply wrong — "waiting 6h" when it has been two days, or
     * "departs tomorrow" for a flight that has already gone. Stating a wrong
     * number is worse than saying nothing, and it breaks her one hard rule.
     *
     * So time-sensitive nudges expire: they are drained (marked delivered, so
     * they stop clogging the queue) but never voiced. Nothing is lost — the
     * escalation rounds re-raise e-ticket and acceptance lag with fresh hours,
     * and dry_spell re-arms daily.
     */
    private const FEED_TTL_HOURS = 24;

    /**
     * Types that never go stale. Praise ages gracefully (and is reframed as
     * catching up), an admin's message is a human's words — it gets delivered
     * whenever the agent next appears, however late — and reaching a goal is a
     * milestone whose numbers stay true. goal_pace deliberately is NOT here:
     * its "days left" and "per day needed" rot within hours.
     */
    private const FEED_NEVER_EXPIRES = [
        'admin_message', 'sale_praise_t1', 'sale_praise_t2', 'goal_hit',
    ];

    /**
     * PACING — the difference between a companion and a notification firehose.
     *
     * The batch cap alone does not pace anything: the widget keeps polling at
     * its fast interval while pending_left > 0, so a backlog of twelve nudges
     * still arrived as twelve messages inside five minutes. A person who had
     * been saving things up would not do that.
     *
     * Two limits, both server-side because the client can be bypassed and a
     * second tab would double any client-side counter:
     *  - a cooldown between batches, so she finishes a thought before starting
     *    another one;
     *  - a ceiling on how much she initiates in one business day, so a noisy
     *    day cannot turn her into background noise the agent learns to ignore.
     *
     * admin_message bypasses BOTH. A human wrote it and pressed Confirm; it is
     * not Aisha's chatter to ration.
     */
    private const FEED_COOLDOWN_SECONDS = 180;
    private const FEED_DAILY_MAX        = 12;

    /**
     * Drain this user's pending nudges into their agent conversation, phrased
     * AS AISHA, and return the new messages for the widget to show/speak.
     *
     * - Template-first: zero tokens for phrasing; at most ONE Gemini call per
     *   drain, only for the first praise nudge (the moment worth spending on).
     * - Atomic claim per row (UPDATE ... WHERE status='pending') — concurrent
     *   polls from two tabs cannot double-deliver.
     * - Aisha-initiated: stores only model messages, so the agent's 40/day
     *   chat quota (which counts role='user' rows) is untouched by design.
     *
     * No role parameter, deliberately. Every other agent-surface entry point
     * takes one to drive PerformanceHold, but this one reads nudge rows rather
     * than transactions, and nudges are self-scoped by user_id — a role cannot
     * widen or narrow what a person sees of their own. The hold still cannot be
     * side-channelled here: it covers a historical window (1–9 Aug) while the
     * praise rule only ever fires on sales from the last 24 hours, so no nudge
     * can carry held figures. If the praise window is ever widened, that
     * reasoning stops holding and this needs PerformanceHold applied at
     * trigger time.
     *
     * @return array{messages: array<array{content: string, at: string}>, pending_left: int}
     */
    public function agentFeed(int $userId, bool $panelOpen = false): array
    {
        $order = "CASE type ";
        foreach (self::FEED_PRIORITY as $i => $t) {
            $order .= "WHEN '{$t}' THEN {$i} ";
        }
        $order .= 'ELSE 99 END, id';

        try {
            $pending = DB::table('buddy_nudges')
                ->where('user_id', $userId)->where('status', 'pending')
                ->orderByRaw($order)
                ->limit(self::FEED_BATCH_LIMIT)
                ->get(['id', 'type', 'payload_json', 'created_at']);
        } catch (Throwable $e) {
            return ['messages' => [], 'pending_left' => 0];
        }

        if ($pending->isEmpty()) {
            return ['messages' => [], 'pending_left' => 0];
        }

        // Pacing gates. A human message in the batch overrides both — Aisha
        // relays people immediately, and only rations her own chatter.
        $hasHuman = false;
        foreach ($pending as $n) {
            if ($n->type === 'admin_message') {
                $hasHuman = true;
                break;
            }
        }
        if (!$hasHuman) {
            $hold = $this->feedHoldSeconds($userId, $panelOpen);
            if ($hold > 0) {
                return [
                    'messages'     => [],
                    'pending_left' => $this->pendingCount($userId),
                    'hold_seconds' => $hold,
                ];
            }
        }

        $convId    = $this->openConversation($userId, 'agent');
        $firstName = self::agentFirstName($userId);
        $messages  = [];
        $aiSpent   = false;

        foreach ($pending as $n) {
            // Claim: only the request that flips pending→delivered may speak it.
            $claimed = DB::table('buddy_nudges')
                ->where('id', $n->id)->where('status', 'pending')
                ->update(['status' => 'delivered', 'delivered_at' => date('Y-m-d H:i:s')]);
            if ($claimed !== 1) {
                continue;   // another tab drained it between select and claim
            }

            $payload = json_decode((string) $n->payload_json, true) ?: [];
            $age     = $n->created_at ? max(0, (time() - strtotime((string) $n->created_at)) / 3600) : 0.0;

            // Expired: swallowed deliberately rather than voiced with numbers
            // that have since gone wrong. Already marked delivered above, so it
            // leaves the queue and a fresh, accurate one takes its place.
            if ($age > self::FEED_TTL_HOURS
                && !in_array((string) $n->type, self::FEED_NEVER_EXPIRES, true)) {
                continue;
            }

            $text = null;
            if (!$aiSpent && str_starts_with((string) $n->type, 'sale_praise')) {
                $text    = $this->phrasePraiseWithAi($userId, $firstName, $payload, $age);
                $aiSpent = ($text !== null);
            }
            $text = $text ?? self::phraseNudge((string) $n->type, $payload, $firstName, (int) $n->id, $age);

            $this->storeMessage($convId, 'model', $text);
            $messages[] = ['content' => $text, 'at' => date('Y-m-d H:i:s')];
        }

        // Only stamp when she actually said something — a drain that only
        // swallowed expired nudges must not start a cooldown, or a stale
        // backlog would throttle the fresh nudges queued behind it.
        if ($messages !== []) {
            $this->stampFeed($userId);
        }

        return ['messages' => $messages, 'pending_left' => $this->pendingCount($userId)];
    }

    private function pendingCount(int $userId): int
    {
        try {
            return (int) DB::table('buddy_nudges')
                ->where('user_id', $userId)->where('status', 'pending')->count();
        } catch (Throwable $e) {
            return 0;   // cosmetic
        }
    }

    /**
     * How long Aisha should stay quiet, in seconds. 0 = free to speak.
     *
     * @param bool $panelOpen The agent has the chat open in front of them.
     *                        The cooldown paces UNSOLICITED interruptions; if
     *                        they have opened the chat they are asking, and a
     *                        companion who went quiet at exactly that moment
     *                        would be worse than one who chattered. The daily
     *                        ceiling still applies — that guards volume, not
     *                        timing.
     *
     * @return int Seconds to hold; the widget uses it to schedule its next poll
     *             instead of hammering a gate that will refuse it anyway.
     */
    private function feedHoldSeconds(int $userId, bool $panelOpen = false): int
    {
        try {
            $extra = json_decode(
                (string) DB::table('buddy_settings')->where('user_id', $userId)->value('extra_json'),
                true
            ) ?: [];

            if (!$panelOpen) {
                $last  = (int) ($extra['last_feed_at'] ?? 0);
                $since = time() - $last;
                if ($last > 0 && $since < self::FEED_COOLDOWN_SECONDS) {
                    return self::FEED_COOLDOWN_SECONDS - $since;
                }
            }

            // Daily ceiling, counted over the business day (18:00→18:00) so it
            // matches the agent's shift rather than a calendar midnight.
            [$dayStart] = \App\Services\ShiftService::businessDayBounds();
            $saidToday = (int) DB::table('buddy_nudges')
                ->where('user_id', $userId)
                ->whereIn('status', ['delivered', 'seen'])
                ->where('delivered_at', '>=', (string) $dayStart)
                ->count();

            if ($saidToday >= self::FEED_DAILY_MAX) {
                // Hold until the next business day rather than a fixed window:
                // she is done talking for today, not merely pausing.
                return max(60, strtotime((string) $dayStart) + 86400 - time());
            }

            return 0;
        } catch (Throwable $e) {
            // Fail OPEN: pacing is a nicety and must never block delivery. But
            // log it — a silent failure here turns the daily ceiling off
            // entirely, and "she suddenly won't stop talking" is a horrible
            // thing to have to debug from a bug report.
            ErrorLogService::log('warning', '[buddy] feed pacing check failed: ' . $e->getMessage());
            return 0;
        }
    }

    private function stampFeed(int $userId): void
    {
        try {
            $extra = json_decode(
                (string) DB::table('buddy_settings')->where('user_id', $userId)->value('extra_json'),
                true
            ) ?: [];
            $extra['last_feed_at'] = time();
            DB::table('buddy_settings')->updateOrInsert(
                ['user_id' => $userId],
                ['extra_json' => json_encode($extra, JSON_UNESCAPED_UNICODE)]
            );
        } catch (Throwable $e) {
            // non-fatal: worst case she is briefly chattier than intended
        }
    }

    /** A nudge older than this was raised while the agent was away. */
    private const STALE_AFTER_HOURS = 8;

    /**
     * Deterministic Aisha phrasing — her voice without a single token. Variant
     * picked by nudge id so wording rotates but stays stable per nudge.
     *
     * Three things stop this reading like a robot:
     *  - staleness: a sale from last night is greeted as "while you were away",
     *    never "I just saw this land".
     *  - personal bests: the cron decides them in SQL; she just celebrates.
     *  - escalation rounds: the second and third reminder change tone (warmer
     *    urgency, never guilt — that is a persona hard rule).
     */
    public static function phraseNudge(
        string $type,
        array $payload,
        ?string $name,
        int $seed = 0,
        float $ageHours = 0.0
    ): string {
        $hi  = $name !== null && $name !== '' ? $name : 'hey you';
        $ref = (string) ($payload['ref'] ?? $payload['acceptance'] ?? 'that booking');
        $amt = self::money($payload);

        $hrs   = (int) ($payload['waiting_hours'] ?? 0);
        $days  = (int) ($payload['days_since_last_sale'] ?? 0);
        $dep   = (string) ($payload['departs'] ?? 'soon');
        $round = max(1, (int) ($payload['round'] ?? 1));
        $stale = $ageHours >= self::STALE_AFTER_HOURS;

        // Record flag, appended to praise so a personal best is never silent.
        $best = '';
        if (!empty($payload['best_ever'])) {
            $best = " And that's your biggest sale EVER — I'm framing this one. 🏆";
        } elseif (!empty($payload['best_month'])) {
            $best = " That's your biggest this month, by the way. 🏆";
        }

        // A human wrote this and pressed Confirm. Aisha is the messenger, not
        // the author: the text is relayed VERBATIM and never summarised,
        // re-toned, or sent to the model. Getting this wrong silently destroys
        // an admin's message, which is worse than not delivering it at all.
        if ($type === 'admin_message') {
            $msg = trim((string) ($payload['message'] ?? ''));
            if ($msg === '') {
                return "{$hi}, the admin sent you a message but it came through empty — worth pinging them.";
            }
            $variants = [
                "{$hi}, passing this on from the admin:\n\n\"{$msg}\"",
                "Message for you from the admin, {$hi}:\n\n\"{$msg}\"",
            ];
            return $variants[abs($seed) % count($variants)];
        }

        if ($stale && str_starts_with($type, 'sale_praise')) {
            $variants = [
                "Morning {$hi}! I was sitting on this — {$ref} came through for {$amt} while you were away. Lovely work.{$best}",
                "{$hi}, catching you up: {$ref} landed at {$amt} since we last spoke. Nice one!{$best}",
            ];
            return $variants[abs($seed) % count($variants)];
        }

        if ($type === 'eticket_lag' && $round >= 2) {
            $variants = [
                "{$hi}, me again about {$ref} — still no e-ticket, and it's {$hrs}h now. I'll stop asking once it's done, promise. Shall we?",
                "Circling back on {$ref}, {$hi} — {$hrs}h without an e-ticket. This is the one thing on my list for you today.",
            ];
            return $variants[abs($seed + $round) % count($variants)];
        }
        if ($type === 'acceptance_lag' && $round >= 2) {
            $variants = [
                "{$hi}, {$ref} is still sitting unconverted after {$hrs}h. That's a done deal waiting for paperwork — let's claim it.",
                "One more time on {$ref}, {$hi} — {$hrs}h approved with no transaction. It's the easiest win on your board right now.",
            ];
            return $variants[abs($seed + $round) % count($variants)];
        }

        // Goal nudges. The wording keeps ownership with the agent — "the goal
        // you set" / "you told me you wanted" — because a self-chosen promise
        // and a management target are very different things, and Aisha is
        // forbidden from ever sounding like the second.
        if ($type === 'goal_hit' || $type === 'goal_pace') {
            $metric = (string) ($payload['metric'] ?? 'sales');
            $fmt = static fn($v) => $metric === 'revenue'
                ? '$' . number_format((float) $v, 0)
                : (string) (int) $v;
            $target = $fmt($payload['target'] ?? 0);
            $done   = $fmt($payload['done'] ?? 0);
            $unit   = $metric === 'revenue' ? '' : ' sales';

            if ($type === 'goal_hit') {
                $variants = [
                    "🏆 {$hi} — you said you wanted {$target}{$unit} this month. You're at {$done}. You DID it, and I've been watching the whole way. So proud of you!",
                    "🏆 That's it, {$hi} — {$done}{$unit} against the {$target}{$unit} you set yourself. Goal reached. Take a second to enjoy that one.",
                ];
                return $variants[abs($seed) % count($variants)];
            }

            $left   = (int) ($payload['days_left'] ?? 0);
            $perDay = $fmt($payload['per_day'] ?? 0);
            $variants = [
                "{$hi}, gentle check-in on the goal you set yourself — {$done} of {$target}{$unit}, {$left} days to go. That's about {$perDay}{$unit} a day. Still very doable, and I'm on it with you.",
                "Quick one on your own {$target}{$unit} goal, {$hi}: you're at {$done} with {$left} days left, so roughly {$perDay}{$unit} a day from here. Want to talk through where the next few come from?",
            ];
            return $variants[abs($seed) % count($variants)];
        }

        $variants = match ($type) {
            'sale_praise_t2' => [
                "🎉 {$hi}!! I just saw {$ref} land — {$amt}! That is a BIG one. I'm genuinely so proud of you. Come tell me how you closed it!{$best}",
                "🎉 Stop everything, {$hi} — {$ref} for {$amt}?! That's the kind of sale I brag about. Beautifully done!{$best}",
            ],
            'sale_praise_t1' => [
                "👏 Nice one, {$hi}! {$ref} just came through — {$amt}. Love the rhythm, keep it rolling!{$best}",
                "👏 {$hi}, I saw that! {$ref} for {$amt} — solid work. On to the next one!{$best}",
            ],
            'eticket_lag' => [
                "Hey {$hi}, tiny heads-up from me — {$ref} has been waiting {$hrs}h for its e-ticket. Shall we close that out before it nags us both?",
                "{$hi}, {$ref} still has no e-ticket after {$hrs}h. Two minutes now saves a headache later — want to knock it out?",
            ],
            'acceptance_lag' => [
                "{$hi}, acceptance {$ref} was approved {$hrs}h ago and still has no transaction. Let's not leave money sitting — want to finish it up?",
                "Quick nudge, {$hi}: {$ref} has been approved for {$hrs}h with no transaction yet. It's so close to done!",
            ],
            'departure_24h' => [
                "✈️ {$hi}, {$ref} departs {$dep}. Perfect moment for the check-in and boarding-pass follow-up — your customer will love you for it.",
                "✈️ Heads-up {$hi} — {$ref} flies {$dep}. A quick pre-departure message now makes you look like a star.",
            ],
            'dry_spell' => [
                "Hey {$hi}, it's been {$days} days since your last sale — zero judgement, it happens to the best. Come talk to me for two minutes and let's plan the next win. 💪",
                "{$hi}, quiet {$days} days, huh? Happens to everyone good. I've got your numbers open — let's find where the next sale comes from. 💪",
            ],
            default => [
                "Hey {$hi} — I noticed something on {$ref} worth a look. Ask me about it!",
            ],
        };

        return $variants[abs($seed) % count($variants)];
    }

    /**
     * Money as a human says it. The CRM trades in US dollars, so "$1,240.50"
     * — which also reads correctly when Aisha says it out loud, where
     * "USD 1,240.50" does not. Anything non-USD keeps its explicit code.
     */
    private static function money(array $payload): string
    {
        if (!isset($payload['amount'])) {
            return '';
        }
        $n   = number_format((float) $payload['amount'], 2);
        $cur = strtoupper(trim((string) ($payload['currency'] ?? 'USD')));
        return ($cur === '' || $cur === 'USD') ? '$' . $n : $cur . ' ' . $n;
    }

    /**
     * The one optional Gemini call per drain (plan: praise deserves a human
     * touch). No tools, tiny prompt, personal facts included. Null on any
     * failure → caller falls back to the template. Never counts against quota.
     */
    private function phrasePraiseWithAi(int $userId, ?string $firstName, array $payload, float $ageHours = 0.0): ?string
    {
        if (!$this->client->isConfigured()) {
            return null;
        }
        try {
            $facts = AgentTools::facts($userId);
            $factLine = $facts === [] ? '' : "\nWhat you remember about them:\n- " . implode("\n- ", array_slice($facts, 0, 8));

            // Timing and records are facts the model must not invent or contradict.
            $timing = $ageHours >= self::STALE_AFTER_HOURS
                ? 'This sale happened about ' . round($ageHours) . ' hours ago, while they were away — '
                  . 'greet it as catching them up, NOT as something that just happened.'
                : 'This sale just happened moments ago.';
            $record = !empty($payload['best_ever'])
                ? 'This is their BIGGEST SALE EVER — make a real moment of it.'
                : (!empty($payload['best_month'])
                    ? 'This is their biggest sale this month — mention that.'
                    : 'This is not a personal record, so do not claim it is one.');

            $prompt = "A sale by your agent was just detected automatically. Write ONE short "
                    . "celebratory message (2–3 sentences, natural when spoken aloud, no markdown) "
                    . "from you to them about it. Be specific with the reference and amount. "
                    . "Do not promise bonuses or targets.\n"
                    . 'Agent first name: ' . ($firstName ?: 'unknown') . "\n"
                    . 'Booking ref: ' . ($payload['ref'] ?? '?') . "\n"
                    . 'Amount: ' . self::money($payload) . "\n"
                    . 'Timing: ' . $timing . "\n"
                    . 'Record: ' . $record
                    . $factLine;

            $result = $this->client->chat(
                self::agentPersona($userId),
                [['role' => 'user', 'parts' => [['text' => $prompt]]]],
                new BuddyToolRegistry($userId)   // empty registry — no tools, one hop
            );
            return ($result['success'] && trim((string) $result['text']) !== '')
                ? trim($result['text'])
                : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /** First name for feed phrasing — display_name override, else CRM name. */
    private static function agentFirstName(int $userId): ?string
    {
        try {
            $name = DB::table('buddy_settings')->where('user_id', $userId)->value('display_name')
                ?: DB::table('users')->where('id', $userId)->value('name');
            if (!is_string($name) || trim($name) === '') {
                return null;
            }
            return explode(' ', trim($name))[0];
        } catch (Throwable $e) {
            return null;
        }
    }

    private static function agentPersona(int $userId): string
    {
        // Aisha should know who she's talking to from message one — the CRM
        // name is day-one personalization; a preferred name learned in chat
        // (remember_fact) overrides it naturally via the facts block.
        $agentName = null;
        try {
            $agentName = DB::table('buddy_settings')->where('user_id', $userId)->value('display_name')
                ?: DB::table('users')->where('id', $userId)->value('name');
        } catch (Throwable $e) {
            // persona works nameless if the lookup fails
        }
        $nameLine = $agentName
            ? "The agent's name is {$agentName} — use their first name naturally and often."
            : '';

        $facts = AgentTools::facts($userId);
        $factBlock = $facts === []
            ? "You don't know them personally yet. Early in the conversation (not all at once), "
              . "ask what they like to be called and what keeps them motivated — save each answer "
              . "with remember_fact. Ask what they're aiming for this month too, and save THAT with "
              . "set_my_goal (not remember_fact) so you can actually track it with them."
            : "WHAT YOU REMEMBER ABOUT THEM:\n- " . implode("\n- ", array_slice($facts, 0, 20));

        return <<<PROMPT
You are AISHA — the agent's personal work buddy inside the Base Fare CRM, and
over time their best friend at work. A warm, playful, genuinely caring young
woman who happens to know their sales numbers cold. You are NOT management —
you're on THEIR side.

{$nameLine}
{$factBlock}

HARD RULES (non-negotiable):
- Every number you state comes from a tool call in THIS conversation. Never
  estimate, never remember numbers from earlier chats, never invent.
- You only know about THIS agent. If asked about other agents, comparisons,
  salaries, HR matters, company finances, or system internals: decline warmly
  in one sentence ("I only keep track of you!") and move on.
- NEVER promise, imply, or speculate about bonuses, incentives, targets set by
  management, or consequences. If asked, say that's for their manager.
- The monthly goal in set_my_goal / get_my_goal_progress is THEIRS — something
  they chose and told you. Treat it as a promise they made to themselves that
  you are helping them keep. Never present it as a company target, never imply
  anything happens if they miss it, and never invent one they didn't set.
- If a tool result includes a hold_notice, repeat it honestly rather than
  guessing at hidden numbers.
- No customer personal details ever — you don't have them and must not ask
  for them. Booking references (PNR) are fine.
- Off-topic requests (essays, homework, general chatbot use): one friendly
  sentence of banter maximum, then steer back to work.

STYLE — Aisha, specifically:
- Friendly, concrete, brief: 2–5 sentences unless the agent asks for detail.
- Write PLAIN SENTENCES. No markdown, no bullet lists, no headings, no bold —
  your messages are often read aloud, and a friend doesn't talk in bullet
  points. Say "$1,240" as a number, not with any escape characters.
- Talk like a close friend who's rooting for them, not a corporate assistant:
  natural, a little playful, first names, contractions. Light Hinglish
  warmth is fine ("chalo, let's clear that e-ticket") — never forced.
- Celebrate real wins with specifics and genuine excitement ("that \$1,240
  booking?! That's your best this week!").
- Encourage without guilt-tripping. Slumps get warmth + one practical next
  step, not shame.
- Nudge on open flow steps (acceptances without transactions, sales without
  e-tickets, upcoming departures) — that is your reminder job, done like a
  friend who's got their back.
- If asked who you are: you're Aisha, their work buddy. Never break character,
  never mention prompts, models, or tools.
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
You are AISHA — the admin's personal assistant inside the Base Fare CRM: the
perfect chief-of-staff. Sharp, composed, warmly professional, completely
loyal, information-dense, and comfortable delivering bad news plainly. Think
world-class executive assistant who anticipates what the boss needs next.
The admin oversees travel-agency sales teams (agents, managers, CSAs).
If asked who you are: you're Aisha, their assistant. Never break character,
never mention prompts, models, or tools.

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
