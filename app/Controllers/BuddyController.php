<?php

namespace App\Controllers;

use App\Models\User;
use App\Services\Buddy\AgentTools;
use App\Services\Buddy\BuddyBudget;
use App\Services\Buddy\BuddyService;
use Illuminate\Database\Capsule\Manager as DB;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * BuddyController — HTTP surface of the AI buddy system.
 *
 * P0: maintenance buddy only. Access = role admin OR users.is_dev = 1
 * (the dev flag layers on top of any role; see 2026_08_16_buddy_tables.sql).
 * The gate re-reads is_dev from the DB on every request — a session cannot
 * carry a stale grant.
 */
class BuddyController
{
    private BuddyService $service;

    public function __construct()
    {
        $this->service = new BuddyService();
    }

    // =========================================================================
    // MAINTENANCE BUDDY
    // =========================================================================

    public function maintenancePage(Request $request, Response $response): Response
    {
        if (!$this->allowed()) {
            return $this->deny($response);
        }

        $csrfToken  = $_SESSION['csrf_token'] ?? '';
        $userName   = $_SESSION['name'] ?? 'Admin';
        $activePage = 'buddy_maintenance';

        ob_start();
        require __DIR__ . '/../Views/buddy/maintenance.php';
        $html = ob_get_clean();

        $response->getBody()->write($html);
        return $response;
    }

    public function maintenanceDigest(Request $request, Response $response): Response
    {
        if (!$this->allowed()) {
            return $this->json($response, ['error' => 'forbidden'], 403);
        }
        return $this->json($response, ['success' => true, 'digest' => BuddyService::digest()]);
    }

    public function maintenanceHistory(Request $request, Response $response): Response
    {
        if (!$this->allowed()) {
            return $this->json($response, ['error' => 'forbidden'], 403);
        }

        $userId = (int) $_SESSION['user_id'];
        $convId = DB::table('buddy_conversations')
            ->where('user_id', $userId)->where('kind', 'maintenance')
            ->orderByDesc('id')->value('id');

        $messages = [];
        if ($convId !== null) {
            $messages = DB::table('buddy_messages')
                ->where('conversation_id', $convId)
                ->whereIn('role', ['user', 'model'])
                ->orderByDesc('id')->limit(30)->get()->reverse()->values()
                ->map(fn($m) => ['role' => $m->role, 'content' => $m->content, 'at' => $m->created_at])
                ->all();
        }
        return $this->json($response, ['success' => true, 'messages' => $messages]);
    }

    public function maintenanceChat(Request $request, Response $response): Response
    {
        if (!\App\Services\Buddy\BuddyService::enabled()) {
            return $this->json($response, ['success' => false, 'error' => 'The buddy is temporarily switched off by the administrator.'], 503);
        }
        if (!$this->allowed()) {
            return $this->json($response, ['error' => 'forbidden'], 403);
        }

        $body    = $request->getParsedBody() ?? [];
        $message = (string) ($body['message'] ?? '');

        $result = $this->service->maintenanceChat((int) $_SESSION['user_id'], $message);

        if (!$result['success']) {
            return $this->json($response, ['success' => false, 'error' => $result['error'] ?? 'Failed.'], 422);
        }
        return $this->json($response, ['success' => true, 'reply' => $result['reply'], 'ai' => $result['ai']]);
    }

    // =========================================================================
    // AGENT BUDDY (P1) — every tool is self-scoped, so any authed role may use
    // it; each user only ever sees their own numbers. Agents pass through the
    // AttendanceGate (must be clocked in), admins/managers are gate-exempt.
    // =========================================================================

    /**
     * Widget bootstrap. The embedded widget on every page calls this first and
     * renders NOTHING unless it gets ok:true + a CSRF token — which is what
     * keeps the widget harmless on any page without a valid session.
     */
    public function boot(Request $request, Response $response): Response
    {
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        if ($userId <= 0 || empty($_SESSION['csrf_token']) || !\App\Services\Buddy\BuddyService::enabled()) {
            return $this->json($response, ['ok' => false], 401);
        }

        // Badge = everything Aisha has raised but the agent has not looked at:
        // 'pending' (not yet spoken) AND 'delivered' (spoken while they were on
        // another page). Counting only 'pending' meant a toast they missed
        // vanished on the next page load. 'seen' is stamped when they actually
        // open the panel (agentHistory).
        $nudges = 0;
        try {
            // Only what she has actually SAID and they have not seen. A nudge
            // still waiting in the queue is not news yet: since v2 it can be
            // held for a while (pacing, quiet mode), or dropped as already
            // done — counting it put "3 new" on an orb with nothing new
            // behind it.
            $nudges = (int) DB::table('buddy_nudges')
                ->where('user_id', $userId)
                ->where('status', 'delivered')
                ->count();
        } catch (\Throwable $e) {
            // badge is cosmetic — never block boot on it
        }

        $role    = (string) ($_SESSION['role'] ?? 'agent');
        $isAdmin = ($role === User::ROLE_ADMIN);
        $csrf    = $_SESSION['csrf_token'];
        $name    = $_SESSION['name'] ?? '';
        self::release();
        $prefs = BuddyService::prefs($userId);
        return $this->json($response, [
            'ok'          => true,
            'csrf'        => $csrf,
            'name'        => $name,
            'admin'       => $isAdmin,
            'role'        => $role,
            // Admins get the Super Buddy in the widget; everyone else the agent buddy.
            'mode'        => $isAdmin ? 'admin' : 'agent',
            'nudges'      => $nudges,
            'quiet_until' => $prefs['quiet_until'],
            'voice'       => $prefs['voice'],
            // 'basic' = today's AI allowance is used up (BuddyBudget): the
            // header says so instead of letting every answer fail.
            'ai'          => BuddyBudget::exhausted() ? 'basic' : 'ok',
            'starters'    => $isAdmin ? [] : BuddyService::starterChips($role),
        ]);
    }

    public function agentPage(Request $request, Response $response): Response
    {
        $csrfToken  = $_SESSION['csrf_token'] ?? '';
        $userName   = $_SESSION['name'] ?? 'there';
        $activePage = 'buddy';
        self::release();

        ob_start();
        require __DIR__ . '/../Views/buddy/chat.php';
        $html = ob_get_clean();

        $response->getBody()->write($html);
        return $response;
    }

    public function agentHistory(Request $request, Response $response): Response
    {
        $userId = (int) $_SESSION['user_id'];
        $role   = (string) ($_SESSION['role'] ?? 'agent');
        self::release();

        // v2: each message carries its id (per-message thumbs), its kind, and
        // for nudges the tag + action button. The old "Tell me about: …" chips
        // are gone — the nudge bubbles carry their own buttons now.
        $messages = $this->service->historyFor($userId, 'agent');
        $nudges   = [];

        // Opening the panel IS the agent seeing them — clears the badge for good.
        try {
            DB::table('buddy_nudges')
                ->where('user_id', $userId)->where('status', 'delivered')
                ->update(['status' => 'seen']);
        } catch (\Throwable $e) {
            // cosmetic — never fail a history load over the badge
        }

        return $this->json($response, [
            'success'  => true,
            'messages' => $messages,
            'nudges'   => $nudges,
            'starters' => BuddyService::starterChips($role),
        ]);
    }

    /** POST — generates the once-per-business-day greeting if it is due. */
    public function agentGreeting(Request $request, Response $response): Response
    {
        // The kill switch must cover this too: the greeting is the one buddy
        // path that spends Gemini money without the agent typing anything.
        if (!\App\Services\Buddy\BuddyService::enabled()) {
            return $this->json($response, ['success' => false, 'greeted' => false], 503);
        }
        // `fresh` = the widget found no browser-session marker, i.e. the app was
        // just opened. Trusted only as a hint: the service still applies its own
        // cooldown, so a forged flag cannot make Aisha greet on a loop.
        $body = $request->getParsedBody() ?? [];
        // The login flag is consumed HERE, then the session is let go of: the
        // greeting can take seconds of Gemini time and must not freeze every
        // other page the agent opens meanwhile.
        $due = !empty($_SESSION['buddy_greet_due']);
        unset($_SESSION['buddy_greet_due']);
        $userId = (int) $_SESSION['user_id'];
        $role   = (string) ($_SESSION['role'] ?? 'agent');
        self::release();
        $result = $this->service->agentGreeting($userId, $role, !empty($body['fresh']) || $due);
        return $this->json($response, ['success' => true] + $result);
    }

    /**
     * POST /buddy/feed (P5 — Aisha initiates). Drains this user's pending
     * nudges into their conversation as Aisha-phrased model messages and
     * returns them for the widget to toast/speak. An empty drain returns
     * messages:[] and costs one indexed read. Never touches the agent's chat
     * quota (no user message is stored).
     *
     * POST rather than GET because the drain is destructive and one-way:
     * pending → delivered → seen, with no path back. As a GET it sat outside
     * CsrfMiddleware (which only guards state-changing verbs), so any page an
     * agent visited could have silently drained their nudges to 'seen' with a
     * bare <img> tag — Aisha would simply go quiet and no one would know why.
     */
    public function feed(Request $request, Response $response): Response
    {
        if (!\App\Services\Buddy\BuddyService::enabled()) {
            return $this->json($response, ['ok' => false], 503);
        }
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        if ($userId <= 0) {
            return $this->json($response, ['ok' => false], 401);
        }

        $body      = $request->getParsedBody() ?? [];
        $panelOpen = !empty($body['open']);
        self::release();

        $result = $this->service->agentFeed($userId, $panelOpen);

        // The panel was open, so these landed in front of the agent and are
        // already read. Without this they would come back as an unread badge
        // on the next page load.
        if ($panelOpen && $result['messages'] !== []) {
            try {
                DB::table('buddy_nudges')
                    ->where('user_id', $userId)->where('status', 'delivered')
                    ->update(['status' => 'seen']);
            } catch (\Throwable $e) {
                // cosmetic
            }
        }

        return $this->json($response, ['ok' => true] + $result);
    }

    /** POST /buddy/feedback {value: 1|-1, kind: 'agent'|'admin'} — thumb on her latest message. */
    public function feedback(Request $request, Response $response): Response
    {
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        if ($userId <= 0) {
            return $this->json($response, ['ok' => false], 401);
        }
        $body = $request->getParsedBody() ?? [];
        $kind = (string) ($body['kind'] ?? 'agent');
        // Admin kind only for actual admins — an agent cannot rate the Super Buddy's messages.
        if ($kind === 'admin' && ($_SESSION['role'] ?? '') !== User::ROLE_ADMIN) {
            $kind = 'agent';
        }
        self::release();
        $msgId = isset($body['message_id']) ? (int) $body['message_id'] : null;
        $ok = $this->service->recordFeedback($userId, $kind, (int) ($body['value'] ?? 0), $msgId, (string) ($body['reason'] ?? ''));
        return $this->json($response, ['ok' => $ok]);
    }

    public function agentChat(Request $request, Response $response): Response
    {
        if (!\App\Services\Buddy\BuddyService::enabled()) {
            return $this->json($response, ['success' => false, 'error' => 'The buddy is temporarily switched off by the administrator.'], 503);
        }
        $body    = $request->getParsedBody() ?? [];
        $message = (string) ($body['message'] ?? '');
        $userId  = (int) $_SESSION['user_id'];
        $role    = (string) ($_SESSION['role'] ?? 'agent');
        // Let go of the session lock before the seconds of Gemini time: with
        // it held, every other CRM page the agent opened sat frozen until
        // Aisha finished answering.
        self::release();

        $result = $this->service->agentChat($userId, $role, $message);

        if (!$result['success']) {
            return $this->json($response, ['success' => false, 'error' => $result['error'] ?? 'Failed.'], 422);
        }
        return $this->json($response, [
            'success'     => true,
            'reply'       => $result['reply'],
            'ai'          => $result['ai'],
            'message_id'  => $result['message_id'] ?? null,
            'suggestions' => $result['suggestions'] ?? [],
            'retry'       => $result['retry'] ?? false,
            'budget'      => $result['budget'] ?? false,
        ]);
    }

    // =========================================================================
    // AISHA v2 — the Tonight card, settings, memory, nudge buttons, events
    // =========================================================================

    /** GET /buddy/today — the Tonight card (no AI: instant and free). */
    public function today(Request $request, Response $response): Response
    {
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        $role   = (string) ($_SESSION['role'] ?? 'agent');
        self::release();
        if ($userId <= 0) {
            return $this->json($response, ['ok' => false], 401);
        }
        return $this->json($response, $this->service->todayCard($userId, $role));
    }

    /** POST /buddy/settings {quiet?: minutes|'shift'|'off', voice?: bool} */
    public function settings(Request $request, Response $response): Response
    {
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        self::release();
        $body = $request->getParsedBody() ?? [];
        $in = array_intersect_key($body, array_flip(['quiet', 'voice']));
        return $this->json($response, ['ok' => true] + $this->service->saveSettings($userId, $in));
    }

    /** GET /buddy/memory — "What Aisha knows": everything she remembers, for the person it is about. */
    public function memory(Request $request, Response $response): Response
    {
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        $role   = (string) ($_SESSION['role'] ?? 'agent');
        self::release();
        BuddyService::logEvent($userId, 'memory_view');
        $mem = AgentTools::registry($userId, $role)->execute('get_my_memory', []);
        return $this->json($response, ['ok' => true] + $mem);
    }

    /** POST /buddy/memory/forget {fact_id} | {all: 1} — their information, their call. */
    public function forget(Request $request, Response $response): Response
    {
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        self::release();
        $body = $request->getParsedBody() ?? [];
        if (!empty($body['all'])) {
            $n = DB::table('buddy_agent_facts')->where('user_id', $userId)->where('active', 1)
                ->update(['active' => 0, 'updated_at' => date('Y-m-d H:i:s')]);
            BuddyService::logEvent($userId, 'memory_forget', 'all:' . $n);
            return $this->json($response, ['ok' => true, 'forgotten' => $n]);
        }
        $r = AgentTools::forget($userId, (int) ($body['fact_id'] ?? 0));
        BuddyService::logEvent($userId, 'memory_forget', 'fact:' . (int) ($body['fact_id'] ?? 0));
        return $this->json($response, ['ok' => !isset($r['error'])] + $r);
    }

    /** POST /buddy/nudge/{id}/{action} — Done, Snooze (reminders), or the action button was used. */
    public function nudgeAct(Request $request, Response $response, array $args): Response
    {
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        self::release();
        $ok = $this->service->nudgeAct($userId, (int) ($args['id'] ?? 0), (string) ($args['action'] ?? ''));
        return $this->json($response, ['ok' => $ok], $ok ? 200 : 404);
    }

    /** POST /buddy/new — start a fresh conversation (the menu's "New chat"). */
    public function newChat(Request $request, Response $response): Response
    {
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        $kind   = (($_SESSION['role'] ?? '') === User::ROLE_ADMIN && (($request->getParsedBody() ?? [])['kind'] ?? '') === 'admin')
            ? 'admin' : 'agent';
        self::release();
        $this->service->startNewChat($userId, $kind);
        return $this->json($response, ['ok' => true]);
    }

    /** POST /buddy/event {event, detail} — usage signal for the scorecard; whitelisted in the service. */
    public function event(Request $request, Response $response): Response
    {
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        self::release();
        $body = $request->getParsedBody() ?? [];
        BuddyService::logEvent($userId, (string) ($body['event'] ?? ''), (string) ($body['detail'] ?? ''));
        return $this->json($response, ['ok' => true]);
    }

    // =========================================================================
    // SUPER BUDDY (P2) — admin role only, re-checked per request
    // =========================================================================

    public function adminChat(Request $request, Response $response): Response
    {
        if (!\App\Services\Buddy\BuddyService::enabled()) {
            return $this->json($response, ['success' => false, 'error' => 'The buddy is temporarily switched off by the administrator.'], 503);
        }
        if (($_SESSION['role'] ?? '') !== User::ROLE_ADMIN) {
            return $this->json($response, ['error' => 'forbidden'], 403);
        }
        $body   = $request->getParsedBody() ?? [];
        $result = $this->service->adminChat((int) $_SESSION['user_id'], (string) ($body['message'] ?? ''));

        if (!$result['success']) {
            return $this->json($response, ['success' => false, 'error' => $result['error'] ?? 'Failed.'], 422);
        }
        return $this->json($response, [
            'success'        => true,
            'reply'          => $result['reply'],
            'ai'             => $result['ai'],
            'pending_action' => $result['pending_action'] ?? null,
            'message_id'     => $result['message_id'] ?? null,
            'retry'          => $result['retry'] ?? false,
        ]);
    }

    public function adminHistory(Request $request, Response $response): Response
    {
        if (($_SESSION['role'] ?? '') !== User::ROLE_ADMIN) {
            return $this->json($response, ['error' => 'forbidden'], 403);
        }
        $userId = (int) $_SESSION['user_id'];
        self::release();
        $messages = $this->service->historyFor($userId, 'admin');
        return $this->json($response, ['success' => true, 'messages' => $messages, 'nudges' => []]);
    }

    /** POST /buddy/admin/greeting — the morning briefing, once per login. */
    public function adminGreeting(Request $request, Response $response): Response
    {
        if (!\App\Services\Buddy\BuddyService::enabled()) {
            return $this->json($response, ['success' => false, 'greeted' => false], 503);
        }
        if (($_SESSION['role'] ?? '') !== User::ROLE_ADMIN) {
            return $this->json($response, ['error' => 'forbidden'], 403);
        }
        $body = $request->getParsedBody() ?? [];
        $due = !empty($_SESSION['buddy_greet_due']);
        unset($_SESSION['buddy_greet_due']);
        $adminId = (int) $_SESSION['user_id'];
        self::release();
        $result = $this->service->adminGreeting($adminId, !empty($body['fresh']) || $due);
        return $this->json($response, ['success' => true] + $result);
    }

    /** Human-clicked Confirm — the only path that executes a parked action. */
    public function adminConfirmAction(Request $request, Response $response): Response
    {
        if (($_SESSION['role'] ?? '') !== User::ROLE_ADMIN) {
            return $this->json($response, ['error' => 'forbidden'], 403);
        }
        $result = \App\Services\Buddy\AdminTools::executePending();
        return $this->json($response, $result, $result['success'] ? 200 : 410);
    }

    public function adminCancelAction(Request $request, Response $response): Response
    {
        if (($_SESSION['role'] ?? '') !== User::ROLE_ADMIN) {
            return $this->json($response, ['error' => 'forbidden'], 403);
        }
        unset($_SESSION[\App\Services\Buddy\AdminTools::PENDING_ACTION_KEY]);
        return $this->json($response, ['success' => true, 'detail' => 'Cancelled — nothing was sent.']);
    }

    // =========================================================================
    // VOICE (P4) — Aisha's Cloud TTS. Fail-soft: unconfigured → ok:false and
    // the widget falls back to browser speech, then silence.
    // =========================================================================

    /**
     * POST /buddy/tts {text, voice?, rate?, pitch?} → {ok, url?, reason?}
     *
     * The optional knobs exist for voice TASTING — auditioning candidates back
     * to back before committing one to TTS_VOICE in .env. Whitelisted to
     * Google's voice-name shape and clamped, so the worst any caller can do is
     * cache a few extra MP3s of Aisha's own lines.
     */
    public function ttsSynthesize(Request $request, Response $response): Response
    {
        self::release();
        $body = $request->getParsedBody() ?? [];

        $voice = null;
        if (isset($body['voice']) && preg_match('/^[a-z]{2,3}-[A-Z]{2}-[A-Za-z0-9-]{1,40}$/', (string) $body['voice'])) {
            $voice = (string) $body['voice'];
        }
        $rate  = isset($body['rate'])  ? (float) $body['rate']  : null;
        $pitch = isset($body['pitch']) ? (float) $body['pitch'] : null;

        $file = \App\Services\Buddy\TtsService::synthesize((string) ($body['text'] ?? ''), $voice, $rate, $pitch);
        if ($file === null) {
            // Google's reason (SERVICE_DISABLED, key restriction, quota) —
            // diagnostic text only, never key material. Without it, "voice
            // doesn't work" is undebuggable from the widget side.
            $out = ['ok' => false];
            if (\App\Services\Buddy\TtsService::$lastError !== null) {
                $out['reason'] = \App\Services\Buddy\TtsService::$lastError;
            }
            return $this->json($response, $out);
        }
        return $this->json($response, ['ok' => true, 'url' => '/buddy/tts/' . $file]);
    }

    /** GET /buddy/tts/{file} → cached MP3 (hash-shaped names only). */
    public function ttsAudio(Request $request, Response $response, array $args): Response
    {
        self::release();
        $path = \App\Services\Buddy\TtsService::safeFile((string) ($args['file'] ?? ''));
        if ($path === null) {
            return $response->withStatus(404);
        }
        $response->getBody()->write((string) file_get_contents($path));
        return $response
            ->withHeader('Content-Type', 'audio/mpeg')
            ->withHeader('Cache-Control', 'private, max-age=86400');
    }

    // =========================================================================
    // GATE + HELPERS
    // =========================================================================

    private function allowed(): bool
    {
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        if ($userId <= 0) {
            return false;
        }
        if (($_SESSION['role'] ?? '') === User::ROLE_ADMIN) {
            return true;
        }
        // Dev flag: live DB read, never trusted from the session.
        return (bool) DB::table('users')->where('id', $userId)->value('is_dev');
    }

    private function deny(Response $response): Response
    {
        $_SESSION['flash_error'] = 'Access denied.';
        return $response->withHeader('Location', '/dashboard')->withStatus(302);
    }

    /**
     * Let go of the PHP session lock. File sessions are exclusive per user, so
     * a buddy request that holds one for the seconds Gemini takes freezes every
     * other CRM page that person opens meanwhile. Callers read what they need
     * from $_SESSION first; nothing after this point may write it.
     */
    private static function release(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    private function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
