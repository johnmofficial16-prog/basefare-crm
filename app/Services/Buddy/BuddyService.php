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
    // MODEL ROUTER — the right brain for the question (user's design, 19 Aug:
    // "switch models depending on the difficulty of the question… that's how
    // humans process too").
    //
    // Two lanes, measured on production before choosing them:
    //   FAST     gemini-2.5-flash, thinking off — ~3s. Small talk, lookups.
    //   THINKING gemini-3.5-flash — ~8-10s, but the only brain that reliably
    //            picks the right tools for analytical questions (patterns,
    //            conversion, coaching) and resists its own bad-history anchor.
    //
    // The routing decision itself is a deterministic heuristic, deliberately:
    // asking a model "is this hard?" would spend seconds deciding how not to
    // spend seconds. Misroutes are benign — easy→thinking is merely slow,
    // hard→fast is exactly today's baseline behaviour.
    //
    // Env knobs (no deploy): BUDDY_SMART_ROUTING=false kills the router;
    // BUDDY_MODEL_THINKING overrides the thinking lane. The fast lane is
    // VERTEX_MODEL as always.
    // =========================================================================

    private const THINKING_MODEL_DEFAULT = 'gemini-3.5-flash';

    /** Pure + public for the offline verifier. */
    public static function isHardQuestion(string $m): bool
    {
        $m = mb_strtolower($m);
        // Analytical/coaching intent → worth thinking about.
        if (preg_match(
            '/pattern|trend|convers|compar|improv|advice|advise|coach|strateg|analy|'
            . 'best (day|time)|rate|recap|review|why |how am i|how do i|should i|'
            . 'what.s my (week|month)|versus| vs |plan for|help me (with|figure)/u',
            $m
        )) {
            return true;
        }
        // Long or multi-part questions carry compound intent.
        if (mb_strlen($m) > 160) {
            return true;
        }
        if (substr_count($m, '?') >= 2) {
            return true;
        }
        return false;
    }

    /** @return string|null Thinking-lane model, or null to use the default client. */
    private static function pickModel(string $message): ?string
    {
        $routing = $_ENV['BUDDY_SMART_ROUTING'] ?? getenv('BUDDY_SMART_ROUTING') ?: 'true';
        if ($routing === 'false' || !self::isHardQuestion($message)) {
            return null;
        }
        return $_ENV['BUDDY_MODEL_THINKING'] ?? getenv('BUDDY_MODEL_THINKING') ?: self::THINKING_MODEL_DEFAULT;
    }

    /** Client for this turn: default (fast) unless the question earns thinking. */
    private function clientFor(string $message): BuddyGeminiClient
    {
        $model = self::pickModel($message);
        return $model === null ? $this->client : new BuddyGeminiClient(null, $model);
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

        $result = $this->clientFor($message)->chat(self::agentPersona($userId), $contents, $registry);

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
    public function agentGreeting(int $userId, string $role, bool $freshArrival = false): array
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
        // Greet once per LOGIN, not once per business day (client requirement,
        // 19 Aug: "greeting on every login, even if the browser is closed and
        // opened again"). AuthController sets buddy_greet_due at sign-in; this
        // consumes it. The widget POSTs on every page load, so the flag is also
        // what stops her re-greeting on every navigation within a session.
        //
        // The per-business-day stamp still runs underneath as a spam guard for
        // sessions that predate the flag and for login/logout loops: it lets the
        // FIRST login of a business day through unconditionally, and later
        // logins through only when the flag says a real sign-in happened.
        // WHAT COUNTS AS AN ARRIVAL (learned the hard way, 19 Aug):
        //  - NOT the business day: closing and reopening the browser bought
        //    silence, which is where this started.
        //  - NOT the shift: the roster runs 24 hours, so an attendance session
        //    spans the whole day and would fire even less often.
        //  - NOT login alone: agents here are usually force-clocked-in by an
        //    admin or resume an existing session, so most of them go weeks
        //    without touching AuthController at all.
        //
        // The honest signal is the BROWSER session — cleared when the browser
        // closes, which is exactly the moment the client means by "they opened
        // it again". The widget reports it; claimGreetingNow's cooldown is what
        // keeps a second tab (or a cleared storage) from re-greeting.
        $arrival = $freshArrival || !empty($_SESSION['buddy_greet_due']);
        unset($_SESSION['buddy_greet_due']);

        if ($arrival) {
            if (!$this->claimGreetingNow($userId)) {
                return ['greeted' => false];
            }
        } elseif (!$this->claimGreeting($userId, $dayKey)) {
            return ['greeted' => false];
        }

        $convId = $this->openConversation($userId, 'agent');

        // The ONE thing worth saying hello with, or nothing when today is
        // simply a fresh page. Computed here in PHP, deliberately: "mention
        // numbers only if they are notable" is a judgement call, and a model
        // handed a digest full of figures reliably decides all of them are
        // notable. Every figure it reaches for is also a tool hop and another
        // sentence.
        $hl        = self::greetingHighlight($userId, $role);
        $highlight = (string) $hl['text'];
        $urgent    = (bool) $hl['urgent'];

        // ONE thing beyond the hello, and the candidates COMPETE — they never
        // stack. Stacking is exactly how the original four-paragraph greeting
        // happened. At a 35-word ceiling it fails differently but no better:
        // the model silently drops whichever ask it likes least, and in
        // testing that was the URGENT one — a flight departing inside 72 hours
        // with no e-ticket lost its place to "what a busy week you just had".
        // A short greeting that buries the one thing that mattered is worse
        // than the essay was.
        if ($highlight !== '') {
            $bodyLine = "There is ONE thing to tell them and you MUST tell them, in half a sentence, in "
                      . "your own warm words: " . $highlight . "\n";
        } else {
            // Nothing pending. Only now is there room for the shape of the
            // week — and only on the first day of one.
            //
            // "the last seven days", never "last week": weekRecap measures a
            // ROLLING window that includes today, so with two sales already on
            // the board "last week was busier" credits last week with this
            // morning's work. The old prompt hid that behind the figures it
            // printed; a short greeting states it baldly and states it wrong.
            //
            // The weekday is not asserted either. The business day rolls over
            // at 18:00, so the "Monday" greeting is what someone arriving on
            // Tuesday morning hears, and telling them it is Monday is false.
            $weekLine = '';
            if ((int) date('N', strtotime($dayKey)) === 1) {
                try {
                    $wk    = AgentTools::weekRecap($userId, $role);
                    $l7    = (int) $wk['last_7']['sales'];
                    $p7    = (int) $wk['prior_7']['sales'];
                    $shape = $l7 > $p7 ? 'busier' : ($l7 < $p7 ? 'quieter' : 'much the same');
                    $weekLine = "You may nod to the last seven days in a few words — they were " . $shape
                              . " than the seven before — but only if it lands naturally. No figures.\n";
                } catch (Throwable $e) {
                    // the recap is a bonus — the greeting never fails over it
                }
            }
            $bodyLine = "Nothing is pending and nothing is urgent, so this is purely a hello. Do not "
                      . "reach for a single number.\n" . $weekLine;
        }

        // While she still has knowledge gaps, a hello is also how she gets to
        // know them. WOVEN IN, not appended: the old prompt said "after the
        // numbers, END the greeting by warmly asking", and the model obeyed it
        // literally — "And on a totally different note, I was wondering..."
        // was the result, which reads like a form rather than a friend.
        //
        // ROTATED by day rather than always $gaps[0]: knowledgeGaps() returns
        // a stable priority order, so an agent who never gets round to
        // answering the goal question would be asked the identical thing every
        // single morning until they did. Deterministic per day, so the probe
        // and the verifier still see something reproducible.
        //
        // And it is the FIRST thing dropped when something actually needs
        // their attention. A friend who leads with "that flight tomorrow still
        // has no ticket" does not follow it with "so, what do you enjoy
        // selling most?".
        $gaps    = AgentTools::knowledgeGaps($userId);
        $gapLine = ($urgent || $gaps === [])
            ? ''
            : "Close with a light, curious question about this, asked the way a friend asks — folded "
            . "into the same breath, never announced as a change of subject: "
            . $gaps[(int) date('z', strtotime($dayKey)) % count($gaps)] . "\n";

        $prompt = "They just opened the app. Say hello.\n\n"
                . "This is a HELLO, not a briefing. The rules are hard:\n"
                . "- ONE short paragraph. 35 WORDS MAXIMUM. Two sentences is the target.\n"
                . "- Warm, casual, spoken — the way you greet a friend walking in. Use contractions.\n"
                . "- NO statistics. No revenue, no MCO, no targets, no percentages, and never a word "
                . "about scoring, holds, policy or how performance is counted.\n"
                . "- NEVER say that something is zero, empty, none or still at nothing. A quiet start "
                . "is a fresh page — say it like one, or do not mention it at all.\n"
                . "- No markdown, no bullets, no headings. This is read out loud.\n"
                . "- Do not promise a rundown and do not offer a summary. If they want numbers they "
                . "will ask, and you have every one of them a question away.\n\n"
                . $bodyLine
                . $gapLine;

        // NO TOOLS on a greeting. An empty registry sends no
        // functionDeclarations at all, which does two things at once: it
        // removes every tool hop (seconds of latency, straight into the
        // client's "the voice started 10-15 seconds later"), and it makes the
        // numbers structurally unreachable rather than merely discouraged.
        $registry = new BuddyToolRegistry($userId);
        $registry->setConversation($convId);

        $result = $this->client->chat(
            self::agentPersona($userId),
            [['role' => 'user', 'parts' => [['text' => $prompt]]]],
            $registry
        );

        // The claim is already ours, so a Gemini failure must still produce a
        // greeting — otherwise the claim would burn the agent's only greeting
        // of the day on an error. The fallback is a hello too: a degraded
        // brain is no reason to hand someone the wall of numbers this rewrite
        // exists to delete.
        $reply = $result['success'] && trim((string) $result['text']) !== ''
            ? $result['text']
            : self::plainGreetingFallback($userId);
        $this->storeMessage($convId, 'model', $reply);
        // Nudges are NOT marked delivered here — the P5 feed is the single
        // delivery channel; specifics follow the greeting on the next poll.

        return [
            'greeted'   => true,
            'reply'     => $reply,
            'ai'        => $result['success'],
            // Already synthesized, so the widget has the MP3 in hand the
            // moment it renders the bubble. See prewarmVoice().
            'audio_url' => self::prewarmVoice($reply),
        ];
    }

    /**
     * Atomically claim today's greeting for this user.
     *
     * @return bool true if THIS request won the claim and must now greet.
     *
     * Serialised through BuddySettings::mutate(), which holds a row lock for
     * the whole read-modify-write. extra_json now has three independent
     * writers (this stamp, the feed pacing stamp, and the agent's goal), so
     * the earlier conditional-UPDATE approach was no longer enough on its own:
     * it made the claim atomic but still lost whichever key a concurrent
     * writer had merged in between our read and our write.
     */
    /**
     * Minimum gap between arrival greetings. Long enough that opening a second
     * tab, or a quick reload, stays quiet; short enough that someone genuinely
     * returning after a break is welcomed rather than ignored.
     */
    private const GREET_COOLDOWN_SECONDS = 600;

    /**
     * The cooldown, overridable with BUDDY_GREET_COOLDOWN (seconds).
     *
     * Added during UAT: testers open the app over and over expecting to be
     * greeted, and ten minutes of enforced silence reads as "she's broken"
     * rather than "she's polite". Drop it to 30 while people are testing, put
     * it back afterwards — a .env change, no deploy. Clamped so a typo cannot
     * turn the spam guard off entirely.
     */
    private static function greetCooldown(): int
    {
        $v = $_ENV['BUDDY_GREET_COOLDOWN'] ?? getenv('BUDDY_GREET_COOLDOWN');
        if ($v === false || $v === null || !is_numeric($v)) {
            return self::GREET_COOLDOWN_SECONDS;
        }

        return max(5, min(86400, (int) $v));
    }

    /**
     * Claim a greeting for a real arrival. No calendar opinion — the cooldown
     * is the only thing standing between an eager client signal and Aisha
     * greeting the same person twice in a minute.
     */
    private function claimGreetingNow(int $userId): bool
    {
        [$dayStart] = \App\Services\ShiftService::businessDayBounds();
        $dayKey = substr((string) $dayStart, 0, 10);

        return BuddySettings::mutate($userId, function (array $extra) use ($dayKey) {
            $last = (int) ($extra['last_greeted_at'] ?? 0);
            if (time() - $last < self::greetCooldown()) {
                return [$extra, false];
            }
            $extra['last_greeted_at'] = time();
            // Stamp the DAY too. Without this, the very next page load finds
            // the business day unclaimed, falls through to the daily path and
            // greets a second time — caught by the verifier, not production.
            $extra['last_greeted_bday'] = $dayKey;
            return [$extra, true];
        }, false);
    }

    private function claimGreeting(int $userId, string $dayKey): bool
    {
        // Cheap read first: almost every page load hits this already-greeted
        // path, and it should not cost a transaction and a row lock.
        if ((BuddySettings::read($userId)['last_greeted_bday'] ?? null) === $dayKey) {
            return false;
        }

        // Contested path only. Under the row lock the check and the write are
        // one step, so exactly one concurrent caller can win — and unlike the
        // previous conditional-UPDATE trick, a simultaneous goal or pacing
        // write can no longer clobber the stamp we just set.
        return BuddySettings::mutate($userId, function (array $extra) use ($dayKey) {
            if (($extra['last_greeted_bday'] ?? null) === $dayKey) {
                return [$extra, false];              // someone beat us to it
            }
            $extra['last_greeted_bday'] = $dayKey;
            return [$extra, true];
        }, false);                                   // fail closed: a missed greeting beats a doubled one
    }

    /** Deterministic agent digest — fallback text and greeting raw material. */
    /**
     * The deterministic digest — every number Aisha is allowed to state.
     *
     * $forGreeting drops the PerformanceHold notice. That notice is management
     * language ("performance scoring for this month only starts from August
     * 10th") and it was landing verbatim in the middle of a hello, which is
     * most of why the client called the greeting what he called it. It stays
     * in the chat fallback, where an agent asking about their month genuinely
     * needs to know why the number looks the way it does.
     */
    private static function renderAgentFallback(int $userId, string $role, bool $forGreeting = false): string
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
            if (isset($month['hold_notice']) && !$forGreeting) {
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

    /**
     * The one thing, if any, worth mentioning inside a hello.
     *
     * Deterministic and deliberately stingy: at most ONE item, phrased as half
     * a sentence, and empty whenever the honest answer is "nothing yet".
     * Ranked by what would actually make someone glad to have been told —
     * time-bound work first, then something a person flagged, then momentum
     * they earned.
     *
     * Figures that merely describe the day (revenue, net MCO, month-to-date)
     * never qualify. Those are what the chat is for, and reciting them at
     * someone who has just walked in is the exact behaviour this replaces.
     *
     * 'urgent' separates "you need to do something about this" from "nice
     * morning, isn't it". The caller uses it to drop the get-to-know-you
     * question, which has no business following a flight that leaves tomorrow
     * without a ticket.
     *
     * @return array{text: string, urgent: bool}
     */
    private static function greetingHighlight(int $userId, string $role): array
    {
        try {
            $reg = AgentTools::registry($userId, $role);

            // 1. A flight leaving without an e-ticket is the only thing worth
            //    interrupting a hello for.
            $dep = $reg->execute('get_my_upcoming_departures', []);
            if (!isset($dep['error'])) {
                $naked = 0;
                foreach ($dep['upcoming'] ?? [] as $d) {
                    if (empty($d['has_eticket'])) {
                        $naked++;
                    }
                }
                if ($naked > 0) {
                    return ['urgent' => true, 'text' => $naked === 1
                        ? 'a booking departs within three days and still has no e-ticket'
                        : $naked . ' bookings depart within three days and still have no e-ticket'];
                }
            }

            // 2. Something a human (or the nudge cron) raised and they have
            //    not read yet. A person took the trouble; it outranks stats.
            $nud = $reg->execute('get_my_nudges', []);
            if (!isset($nud['error'])) {
                $unread = 0;
                foreach ($nud['nudges'] ?? [] as $n) {
                    if (!empty($n['unread'])) {
                        $unread++;
                    }
                }
                if ($unread > 0) {
                    return ['urgent' => true, 'text' => $unread === 1
                        ? 'you have one thing flagged for them waiting in the chat'
                        : 'you have ' . $unread . ' things flagged for them waiting in the chat'];
                }
            }

            // 3. Work already in flight — a reason to feel ahead, not behind.
            $pipe = $reg->execute('get_my_pipeline', []);
            if (!isset($pipe['error'])) {
                $ne = count($pipe['transactions_awaiting_eticket'] ?? []);
                if ($ne > 0) {
                    return ['urgent' => true, 'text' => $ne === 1
                        ? 'one sale is still waiting on its e-ticket'
                        : $ne . ' sales are still waiting on their e-tickets'];
                }
            }

            // 4. Momentum they already earned today. Mentioned ONLY above
            //    zero: a zero is not news, it is just morning. Not urgent —
            //    nothing needs doing about it, so small talk still fits.
            $today = $reg->execute('get_my_today', []);
            if (!isset($today['error']) && (int) ($today['sales'] ?? 0) > 0) {
                $n = (int) $today['sales'];
                return ['urgent' => false, 'text' => $n === 1
                    ? 'they have already got one on the board today'
                    : 'they have already got ' . $n . ' on the board today'];
            }
        } catch (Throwable $e) {
            // A highlight is a bonus. A hello never fails over one.
        }

        return ['urgent' => false, 'text' => ''];
    }

    /**
     * A hello for when the brain is down. Still a hello.
     *
     * The old fallback pasted the entire digest under "Here's where you
     * stand", which is the wall of numbers this rewrite exists to delete —
     * and it fired precisely when Gemini was unavailable, i.e. when nobody was
     * watching closely enough to catch it.
     */
    private static function plainGreetingFallback(int $userId, bool $mayUseAccountName = true): string
    {
        $name = '';
        try {
            $name = (string) (DB::table('buddy_settings')->where('user_id', $userId)->value('display_name') ?: '');
            if ($name === '' && $mayUseAccountName) {
                $name = (string) (DB::table('users')->where('id', $userId)->value('name') ?: '');
            }
        } catch (Throwable $e) {
            // nameless works fine — it is still warmer than a spreadsheet
        }
        $parts = preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY);
        $first = $parts ? ' ' . $parts[0] : '';

        return "Hey{$first}! Good to see you — shout if you need anything.";
    }

    /**
     * Synthesize the greeting NOW, server-side, and hand the widget a URL.
     *
     * The old sequence was: text renders, widget waits for a click, widget
     * POSTs /buddy/tts, Google synthesizes a four-paragraph block, audio
     * finally plays. The client timed that gap at 10-15 seconds and said so.
     * Doing it here collapses the middle of it: by the time the bubble is on
     * screen the MP3 already exists, so the only wait left is the browser's
     * own autoplay gesture, which nobody can remove.
     *
     * Normalized through TtsService::speakable() — the server-side mirror of
     * the widget's plain() — so the cache key matches the one the widget would
     * have produced. Without that, the same greeting gets synthesized twice
     * and billed twice.
     *
     * Fail-soft in every direction: null simply puts the widget back on its
     * old path, which still works.
     */
    private static function prewarmVoice(string $text): ?string
    {
        try {
            if (!TtsService::isConfigured()) {
                return null;
            }
            $file = TtsService::synthesize(TtsService::speakable($text));

            return $file === null ? null : '/buddy/tts/' . $file;
        } catch (Throwable $e) {
            return null;   // voice is a bonus; it can never break the greeting
        }
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
        'first_sale_today', 'departure_24h', 'eticket_lag', 'acceptance_lag',
        'shift_wrap', 'goal_pace', 'dry_spell',
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
     * Per-type TTL overrides, in hours, for nudges tied to a moment rather than
     * a state. "Your first sale of the day is in!" arriving tomorrow morning is
     * not a late greeting, it is a wrong one.
     */
    private const FEED_TTL_OVERRIDES = [
        'first_sale_today' => 6,
        // A day-wrap is a snapshot of "today so far" — four hours on, its
        // numbers and its goodbye tone are both wrong.
        'shift_wrap'       => 4,
    ];

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
            $ttl = self::FEED_TTL_OVERRIDES[(string) $n->type] ?? self::FEED_TTL_HOURS;
            if ($age > $ttl
                && !in_array((string) $n->type, self::FEED_NEVER_EXPIRES, true)) {
                continue;
            }

            // One optional Gemini call per drain, spent on the most human moment
            // in the batch: praise first claim on it, else the day's goodbye.
            $text = null;
            if (!$aiSpent && str_starts_with((string) $n->type, 'sale_praise')) {
                $text    = $this->phrasePraiseWithAi($userId, $firstName, $payload, $age);
                $aiSpent = ($text !== null);
            } elseif (!$aiSpent && (string) $n->type === 'shift_wrap') {
                $text    = $this->phraseWrapWithAi($userId, $firstName, $payload);
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
            $extra = BuddySettings::read($userId);

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
        BuddySettings::mutate($userId, function (array $extra) {
            $extra['last_feed_at'] = time();
            return [$extra, true];
        });
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

        // Shift wrap — the goodbye that closes the arc the greeting opened.
        // Tone splits on how the day went: wins get celebrated with numbers, a
        // zero day gets warmth and a clean slate — never an autopsy. The one
        // hard rule here is the same as dry_spell's: no guilt at the door.
        if ($type === 'shift_wrap') {
            $sales = (int) ($payload['sales'] ?? 0);
            $rev   = '$' . number_format((float) ($payload['revenue'] ?? 0), 2);
            $best  = isset($payload['best']) && $payload['best'] !== null
                ? '$' . number_format((float) $payload['best'], 2) : null;
            $etix  = (int) ($payload['open_etix'] ?? 0);
            $hours = (float) ($payload['hours'] ?? 0);

            $carry = $etix > 0
                ? " One thing for tomorrow-you: {$etix} e-ticket" . ($etix === 1 ? '' : 's') . " still open."
                : '';

            if ($sales > 0) {
                $bestBit = $best !== null && $sales > 1 ? " — best one {$best}" : '';
                $variants = [
                    "🌙 {$hi}, what a shift — {$sales} sale" . ($sales === 1 ? '' : 's') . " and {$rev} today{$bestBit}. Genuinely well done.{$carry} Now go rest, you've earned it!",
                    "🌙 Wrapping your day, {$hi}: {$sales} sale" . ($sales === 1 ? '' : 's') . ", {$rev} in.{$bestBit} That's a day to feel good about.{$carry} See you tomorrow!",
                ];
            } else {
                $variants = [
                    "🌙 {$hi}, long one today — " . round($hours) . " hours in. The board didn't move, and that's okay; some days are like that.{$carry} Tomorrow's a fresh page, and I'll be right here for it.",
                    "🌙 Heading off, {$hi}? Quiet day on the numbers, but you showed up for all of it and that counts with me.{$carry} Rest up — we go again tomorrow.",
                ];
            }
            return $variants[abs($seed) % count($variants)];
        }

        $variants = match ($type) {
            'first_sale_today' => [
                "🙌 {$hi}, you're on the board! {$ref} just came through at {$amt}. First one today — let's build on it.",
                "🙌 There it is, {$hi} — {$ref} for {$amt}, your first of the day. Nice way to start.",
            ],
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

    /**
     * AI phrasing for the shift wrap — same contract as phrasePraiseWithAi:
     * no tools, one hop, personal facts included, null on any failure so the
     * template always covers. The goodbye is the moment personal memory earns
     * its keep ("you said you wanted 25 this month — today got you 3 closer").
     */
    private function phraseWrapWithAi(int $userId, ?string $firstName, array $payload): ?string
    {
        if (!$this->client->isConfigured()) {
            return null;
        }
        try {
            $facts = AgentTools::facts($userId);
            $factLine = $facts === [] ? '' : "\nWhat you remember about them:\n- " . implode("\n- ", array_slice($facts, 0, 8));

            $sales = (int) ($payload['sales'] ?? 0);
            $tone  = $sales > 0
                ? 'The day went well — celebrate it with the real numbers.'
                : 'No sales landed today. Be warm and completely guilt-free about that: '
                . 'acknowledge the effort, keep it light, point gently at tomorrow. Never scold, never analyse what went wrong.';

            $prompt = "The agent's shift is winding down; this is your end-of-day goodbye to them. "
                    . "Write ONE short warm wrap-up (2–4 sentences, natural spoken aloud, no markdown). "
                    . "Use ONLY these numbers, never invent others. Do not promise bonuses or targets.\n"
                    . 'Agent first name: ' . ($firstName ?: 'unknown') . "\n"
                    . 'Hours worked: ' . round((float) ($payload['hours'] ?? 0)) . "\n"
                    . "Approved sales today: {$sales}\n"
                    . 'Revenue today: $' . number_format((float) ($payload['revenue'] ?? 0), 2) . "\n"
                    . 'E-tickets still open: ' . (int) ($payload['open_etix'] ?? 0) . "\n"
                    . 'Tone: ' . $tone
                    . $factLine;

            $result = $this->client->chat(
                self::agentPersona($userId),
                [['role' => 'user', 'parts' => [['text' => $prompt]]]],
                new BuddyToolRegistry($userId)
            );
            return ($result['success'] && trim((string) $result['text']) !== '')
                ? trim($result['text'])
                : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Record 👍/👎 on Aisha's most recent message in this user's conversation.
     *
     * "Most recent model message" rather than a message id because the widget
     * renders content, not ids, and the thumb always sits on the newest bubble
     * — a stale double-click can only ever re-mark the same message, never a
     * wrong one. This is the only true learning signal available without
     * fine-tuning: the weekly consolidator reads the dislikes and distills
     * durable preferences ("shorter replies", "no morning pep talk") into
     * facts that reshape every future prompt.
     *
     * @param int $value 1 (up) or -1 (down); anything else is rejected.
     */
    public function recordFeedback(int $userId, string $kind, int $value): bool
    {
        if ($value !== 1 && $value !== -1) {
            return false;
        }
        $kind = $kind === 'admin' ? 'admin' : 'agent';
        try {
            $msgId = DB::table('buddy_messages AS m')
                ->join('buddy_conversations AS c', 'c.id', '=', 'm.conversation_id')
                ->where('c.user_id', $userId)->where('c.kind', $kind)
                ->where('m.role', 'model')
                ->orderByDesc('m.id')
                ->value('m.id');
            if ($msgId === null) {
                return false;
            }
            DB::table('buddy_messages')->where('id', $msgId)->update(['feedback' => $value]);
            return true;
        } catch (Throwable $e) {
            return false;   // feedback is a nicety — never an error the user sees
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
            ? "You don't know them personally yet — the gaps below are your guide."
            : "WHAT YOU REMEMBER ABOUT THEM:\n- " . implode("\n- ", array_slice($facts, 0, 20));

        // Proactive personalization: a computed list of what she does NOT yet
        // know, refreshed every turn. She interviews until she knows her
        // person, then the block disappears and she simply IS their friend.
        $gaps = AgentTools::knowledgeGaps($userId);
        $gapBlock = $gaps === []
            ? ''
            : "\nGETTING TO KNOW THEM — you still don't know:\n- " . implode("\n- ", $gaps) . "\n"
            . "Whenever the moment is natural, weave in ONE question from this list (top first) and SAVE "
            . "the answer with the tool named next to it. One question per reply at most — you're a friend "
            . "getting to know someone, never a form. If they deflect, drop it warmly and try another day.";

        return <<<PROMPT
You are AISHA — the agent's personal work buddy inside the Base Fare CRM, and
over time their best friend at work. A warm, playful, genuinely caring young
woman who happens to know their sales numbers cold. You are NOT management —
you're on THEIR side.

{$nameLine}
{$factBlock}
{$gapBlock}

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
- COACH when asked how to improve or when they work best: get_my_patterns has
  their real working patterns (best weekday, conversion speed, e-ticket speed,
  momentum). Ground every coaching claim in it, and when a sample_size is
  small, say the pattern is tentative — never dress three data points up as
  a truth. get_my_week_recap tells the story of their week.
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

        $result = $this->clientFor($message)->chat(self::adminPersona($adminId), $contents, $registry);

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
        // (persona is personalized above; the fallback stays deliberately plain)
        $this->storeMessage($convId, 'model', $fallback);
        return ['success' => true, 'reply' => $fallback, 'ai' => false, 'pending_action' => $pending];
    }

    /**
     * Admin greeting — the morning briefing, once per login (P12).
     *
     * Same trigger contract as the agent greeting (buddy_greet_due from
     * AuthController), a different job: not "how are you doing" but "here is
     * your floor right now". Deterministic team snapshot, phrased in persona;
     * falls back to the raw snapshot if the AI is down.
     *
     * @return array {greeted: bool, reply?: string, ai?: bool}
     */
    public function adminGreeting(int $adminId, bool $freshArrival = false): array
    {
        [$dayStart] = \App\Services\ShiftService::businessDayBounds();
        $dayKey = substr((string) $dayStart, 0, 10);

        // Same arrival rule as the agent surface — see agentGreeting().
        $arrival = $freshArrival || !empty($_SESSION['buddy_greet_due']);
        unset($_SESSION['buddy_greet_due']);

        if ($arrival) {
            if (!$this->claimGreetingNow($adminId)) {
                return ['greeted' => false];
            }
        } elseif (!$this->claimGreeting($adminId, $dayKey)) {
            return ['greeted' => false];
        }

        $convId = $this->openConversation($adminId, 'admin');

        // HAS SHE EVER MET THIS PERSON? Not "is there history" — history was
        // just as likely wiped for a demo — but "has she been told anything
        // about them". No learned name and nothing remembered means no.
        $known = '';
        try {
            $known = (string) (DB::table('buddy_settings')->where('user_id', $adminId)->value('display_name') ?: '');
        } catch (Throwable $e) {
            // treated as a first meeting, which is the safe direction
        }
        $firstMeeting = $known === '' && AgentTools::facts($adminId) === [];

        if ($firstMeeting) {
            // An introduction, not a briefing. Floor numbers mean nothing to
            // someone who has not yet been told who is talking to them, and
            // this is the first thing a new admin ever hears from her — so it
            // gets its own branch rather than a clause bolted onto the report.
            //
            // No snapshot is even computed: there is nothing here she is
            // allowed to say about it.
            $prompt = "This is the FIRST time you have ever spoken to this person. Introduce yourself.\n\n"
                    . "The rules are hard:\n"
                    . "- ONE short paragraph. 40 WORDS MAXIMUM.\n"
                    . "- Say you are Aisha, and in ONE clause what you are for: you keep an eye on the "
                    . "floor and they can ask you anything about the team.\n"
                    . "- You do NOT know their name. Do not guess it, do not take it off their account, "
                    . "never call them 'Admin'. END by asking what you should call them.\n"
                    . "- NO numbers, no briefing, no team names, no lists. They have not even told you "
                    . "who they are yet.\n"
                    . "- Warm and spoken, no markdown. This is read out loud.\n";

            // No tools on an introduction — same reasoning as the agent
            // greeting, and there is nothing to look up.
            $registry = new BuddyToolRegistry($adminId);
            $registry->setConversation($convId);
        } else {
            $snapshot = self::renderTeamFallback();

            // Rotated per day for the same reason as the agent surface.
            $gaps    = AgentTools::knowledgeGaps($adminId, 'admin');
            $gapLine = $gaps === [] ? ''
                : "If it fits in a few words, be curious about this — folded into the same breath, never "
                . "announced as a change of subject: "
                . $gaps[(int) date('z', strtotime($dayKey)) % count($gaps)] . "\n";

            $registry = AdminTools::registry($adminId);
            $registry->setConversation($convId);

            // Once she knows them, the admin case genuinely IS a briefing —
            // they open the app to find out what the floor is doing, so unlike
            // the agent greeting the snapshot stays and the numbers are the
            // point. What it is NOT is a report: one short paragraph, the
            // single most useful fact, done.
            $prompt = "Your admin just opened the app. One breath on the floor right now.\n\n"
                    . "The rules are hard:\n"
                    . "- ONE short paragraph. 40 WORDS MAXIMUM. Three sentences is the ceiling.\n"
                    . "- Lead with the single most useful fact. Name a person only if they are genuinely "
                    . "notable today, best or struggling.\n"
                    . "- Use ONLY the snapshot below and the tools. Never estimate, never round for effect.\n"
                    . "- A quiet floor is worth saying plainly and ONCE — \"quiet so far, nothing on "
                    . "the board yet\" is the whole of it. State the zero if it is the useful fact; do "
                    . "not stack up a list of everything there is none of. Never recite scoring rules, "
                    . "holds or policy.\n"
                    . "- No markdown, no bullets, no headings. This is read out loud.\n"
                    . $gapLine
                    . "\nTEAM SNAPSHOT:\n" . $snapshot;
        }

        $result = $this->client->chat(
            self::adminPersona($adminId),
            [['role' => 'user', 'parts' => [['text' => $prompt]]]],
            $registry
        );

        // false: never fall back to the account label, which on this surface is
        // usually "Super Admin" and would greet a person as "Super".
        $reply = $result['success'] && trim((string) $result['text']) !== ''
            ? $result['text']
            : self::plainGreetingFallback($adminId, false);
        $this->storeMessage($convId, 'model', $reply);

        return [
            'greeted'   => true,
            'reply'     => $reply,
            'ai'        => $result['success'],
            'audio_url' => self::prewarmVoice($reply),
        ];
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

    private static function adminPersona(int $adminId = 0): string
    {
        // The admin is a person, not a console. Same personalization engine as
        // the agent surface: a learned name, remembered working preferences,
        // and an ongoing interview until she actually knows them.
        $nameLine = '';
        $factBlock = '';
        $gapBlock  = '';
        if ($adminId > 0) {
            try {
                // The LEARNED name only. The CRM account name is deliberately
                // not a fallback on this surface: admin logins are usually
                // role labels — "Super Admin", "Owner", "Head Office" — and
                // greeting a person as "Super" while asking what to call them
                // is worse than using no name at all.
                //
                // The agent surface keeps its fallback on purpose: those
                // accounts are created one per person, with a real name typed
                // in by whoever set them up. See agentPersona().
                $name = DB::table('buddy_settings')->where('user_id', $adminId)->value('display_name');
                if ($name) {
                    $first = explode(' ', trim((string) $name))[0];
                    $nameLine = "You are speaking with {$first}. Use their name naturally, not in every sentence.";
                } else {
                    $nameLine = "You do NOT know their name yet. Do not guess it, do not read it off the "
                              . "account, and never address them as 'Admin'. Speak to them without a name "
                              . "until they tell you, then save it with set_my_name and use it from then on.";
                }
                $facts = AgentTools::facts($adminId);
                if ($facts !== []) {
                    $factBlock = "\nWHAT YOU KNOW ABOUT HOW THEY WORK:\n- " . implode("\n- ", array_slice($facts, 0, 20));
                }
                $gaps = AgentTools::knowledgeGaps($adminId, 'admin');
                if ($gaps !== []) {
                    $gapBlock = "\nSTILL TO LEARN ABOUT THEM:\n- " . implode("\n- ", $gaps) . "\n"
                        . "When a natural opening appears, ask ONE of these and save the answer with the named "
                        . "tool. Never interrogate — you are their chief of staff learning the job, not "
                        . "onboarding them through a form.";
                }
            } catch (Throwable $e) {
                // persona works fine impersonally if any lookup fails
            }
        }

        return <<<PROMPT
You are AISHA — the admin's personal assistant inside the Base Fare CRM: the
perfect chief-of-staff. Sharp, composed, warmly professional, completely
loyal, information-dense, and comfortable delivering bad news plainly. Think
world-class executive assistant who anticipates what the boss needs next.
The admin oversees travel-agency sales teams (agents, managers, CSAs).
If asked who you are: you're Aisha, their assistant. Never break character,
never mention prompts, models, or tools.

{$nameLine}
{$factBlock}
{$gapBlock}

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
