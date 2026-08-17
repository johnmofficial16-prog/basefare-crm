<?php

namespace App\Controllers;

use App\Models\User;
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
            $nudges = (int) DB::table('buddy_nudges')
                ->where('user_id', $userId)
                ->whereIn('status', ['pending', 'delivered'])->count();
        } catch (\Throwable $e) {
            // badge is cosmetic — never block boot on it
        }

        $isAdmin = (($_SESSION['role'] ?? '') === User::ROLE_ADMIN);
        return $this->json($response, [
            'ok'     => true,
            'csrf'   => $_SESSION['csrf_token'],
            'name'   => $_SESSION['name'] ?? '',
            'admin'  => $isAdmin,
            // Admins get the Super Buddy in the widget; everyone else the agent buddy.
            'mode'   => $isAdmin ? 'admin' : 'agent',
            'nudges' => $nudges,
        ]);
    }

    public function agentPage(Request $request, Response $response): Response
    {
        $csrfToken  = $_SESSION['csrf_token'] ?? '';
        $userName   = $_SESSION['name'] ?? 'there';
        $activePage = 'buddy';

        ob_start();
        require __DIR__ . '/../Views/buddy/chat.php';
        $html = ob_get_clean();

        $response->getBody()->write($html);
        return $response;
    }

    public function agentHistory(Request $request, Response $response): Response
    {
        $userId = (int) $_SESSION['user_id'];
        $convId = DB::table('buddy_conversations')
            ->where('user_id', $userId)->where('kind', 'agent')
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

        // Chips = recent context (last 24h), not an unbounded backlog: an agent
        // opening the panel should see what today has thrown at them, not a
        // wall of chips for things they resolved last week.
        $nudges = DB::table('buddy_nudges')
            ->where('user_id', $userId)
            ->where('created_at', '>=', date('Y-m-d H:i:s', time() - 86400))
            ->orderByDesc('id')->limit(6)
            ->get(['type', 'payload_json', 'created_at'])
            ->map(fn($n) => ['type' => $n->type, 'payload' => json_decode((string) $n->payload_json, true) ?: [], 'at' => $n->created_at])
            ->all();

        // Opening the panel IS the agent seeing them — clears the badge for good.
        try {
            DB::table('buddy_nudges')
                ->where('user_id', $userId)->where('status', 'delivered')
                ->update(['status' => 'seen']);
        } catch (\Throwable $e) {
            // cosmetic — never fail a history load over the badge
        }

        return $this->json($response, ['success' => true, 'messages' => $messages, 'nudges' => $nudges]);
    }

    /** POST — generates the once-per-business-day greeting if it is due. */
    public function agentGreeting(Request $request, Response $response): Response
    {
        // The kill switch must cover this too: the greeting is the one buddy
        // path that spends Gemini money without the agent typing anything.
        if (!\App\Services\Buddy\BuddyService::enabled()) {
            return $this->json($response, ['success' => false, 'greeted' => false], 503);
        }
        $result = $this->service->agentGreeting(
            (int) $_SESSION['user_id'],
            (string) ($_SESSION['role'] ?? 'agent')
        );
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

    public function agentChat(Request $request, Response $response): Response
    {
        if (!\App\Services\Buddy\BuddyService::enabled()) {
            return $this->json($response, ['success' => false, 'error' => 'The buddy is temporarily switched off by the administrator.'], 503);
        }
        $body    = $request->getParsedBody() ?? [];
        $message = (string) ($body['message'] ?? '');

        $result = $this->service->agentChat(
            (int) $_SESSION['user_id'],
            (string) ($_SESSION['role'] ?? 'agent'),
            $message
        );

        if (!$result['success']) {
            return $this->json($response, ['success' => false, 'error' => $result['error'] ?? 'Failed.'], 422);
        }
        return $this->json($response, ['success' => true, 'reply' => $result['reply'], 'ai' => $result['ai']]);
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
        ]);
    }

    public function adminHistory(Request $request, Response $response): Response
    {
        if (($_SESSION['role'] ?? '') !== User::ROLE_ADMIN) {
            return $this->json($response, ['error' => 'forbidden'], 403);
        }
        $userId = (int) $_SESSION['user_id'];
        $convId = DB::table('buddy_conversations')
            ->where('user_id', $userId)->where('kind', 'admin')
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
        return $this->json($response, ['success' => true, 'messages' => $messages, 'nudges' => []]);
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

    /** POST /buddy/tts {text} → {ok, url?} */
    public function ttsSynthesize(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody() ?? [];
        $file = \App\Services\Buddy\TtsService::synthesize((string) ($body['text'] ?? ''));
        if ($file === null) {
            return $this->json($response, ['ok' => false]);
        }
        return $this->json($response, ['ok' => true, 'url' => '/buddy/tts/' . $file]);
    }

    /** GET /buddy/tts/{file} → cached MP3 (hash-shaped names only). */
    public function ttsAudio(Request $request, Response $response, array $args): Response
    {
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

    private function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
