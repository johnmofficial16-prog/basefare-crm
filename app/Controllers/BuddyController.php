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
        if ($userId <= 0 || empty($_SESSION['csrf_token'])) {
            return $this->json($response, ['ok' => false], 401);
        }

        $nudges = 0;
        try {
            $nudges = (int) DB::table('buddy_nudges')
                ->where('user_id', $userId)->where('status', 'pending')->count();
        } catch (\Throwable $e) {
            // badge is cosmetic — never block boot on it
        }

        return $this->json($response, [
            'ok'     => true,
            'csrf'   => $_SESSION['csrf_token'],
            'name'   => $_SESSION['name'] ?? '',
            'admin'  => (($_SESSION['role'] ?? '') === User::ROLE_ADMIN),
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

        $nudges = DB::table('buddy_nudges')
            ->where('user_id', $userId)
            ->whereIn('status', ['pending', 'delivered'])
            ->orderByDesc('id')->limit(6)
            ->get(['type', 'payload_json', 'created_at'])
            ->map(fn($n) => ['type' => $n->type, 'payload' => json_decode((string) $n->payload_json, true) ?: [], 'at' => $n->created_at])
            ->all();

        return $this->json($response, ['success' => true, 'messages' => $messages, 'nudges' => $nudges]);
    }

    /** POST — generates the once-per-business-day greeting if it is due. */
    public function agentGreeting(Request $request, Response $response): Response
    {
        $result = $this->service->agentGreeting(
            (int) $_SESSION['user_id'],
            (string) ($_SESSION['role'] ?? 'agent')
        );
        return $this->json($response, ['success' => true] + $result);
    }

    public function agentChat(Request $request, Response $response): Response
    {
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
