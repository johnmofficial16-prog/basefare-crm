<?php

namespace App\Controllers;

use App\Models\Notification;
use App\Services\ReminderService;
use Carbon\Carbon;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * NotificationController — the in-app bell for every authenticated user.
 *
 * The poll endpoint also lazily dispatches any overdue reminders, so alerts
 * still surface even if the cron dispatcher missed a run.
 */
class NotificationController
{
    // =========================================================================
    // POLL — GET /api/notifications  (bell badge + recent list)
    // =========================================================================

    public function feed(Request $request, Response $response): Response
    {
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        if (!$userId) {
            return $this->json($response, ['success' => false, 'error' => 'Not authenticated.'], 401);
        }

        // Belt-and-suspenders: fire any due reminders before reading the feed so
        // a missed cron run doesn't hide time-critical alerts.
        try {
            (new ReminderService())->dispatchDue();
        } catch (\Throwable $e) {
            error_log('[NotificationController] lazy dispatch failed: ' . $e->getMessage());
        }

        $unread = Notification::forUser($userId)->unread()->count();

        $recent = Notification::forUser($userId)
            ->orderByDesc('created_at')
            ->limit(15)
            ->get()
            ->map(fn (Notification $n) => $this->present($n))
            ->toArray();

        return $this->json($response, [
            'success' => true,
            'unread'  => $unread,
            'items'   => $recent,
        ]);
    }

    // =========================================================================
    // FULL LIST — GET /notifications
    // =========================================================================

    public function index(Request $request, Response $response): Response
    {
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        if (!$userId) {
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        $notifications = Notification::forUser($userId)
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();

        extract([
            'notifications' => $notifications,
            'csrf'          => $_SESSION['csrf_token'] ?? '',
        ]);
        ob_start();
        require __DIR__ . '/../Views/notifications/index.php';
        $html = ob_get_clean();
        $response->getBody()->write($html);
        return $response;
    }

    // =========================================================================
    // MARK ONE READ — POST /notifications/{id}/read  (AJAX)
    // =========================================================================

    public function markRead(Request $request, Response $response, array $args): Response
    {
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        $body   = (array) $request->getParsedBody();
        if (!$this->csrfOk($body)) {
            return $this->json($response, ['success' => false, 'error' => 'Session expired.'], 403);
        }

        $n = Notification::forUser($userId)->find((int) $args['id']);
        if ($n && !$n->isRead()) {
            $n->update(['read_at' => Carbon::now()->format('Y-m-d H:i:s')]);
        }
        return $this->json($response, ['success' => true, 'unread' => Notification::forUser($userId)->unread()->count()]);
    }

    // =========================================================================
    // MARK ALL READ — POST /notifications/read-all  (AJAX)
    // =========================================================================

    public function markAllRead(Request $request, Response $response): Response
    {
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        $body   = (array) $request->getParsedBody();
        if (!$this->csrfOk($body)) {
            return $this->json($response, ['success' => false, 'error' => 'Session expired.'], 403);
        }

        Notification::forUser($userId)->unread()->update(['read_at' => Carbon::now()->format('Y-m-d H:i:s')]);
        return $this->json($response, ['success' => true, 'unread' => 0]);
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private function present(Notification $n): array
    {
        $created = $n->created_at ? Carbon::parse($n->created_at) : null;
        return [
            'id'       => $n->id,
            'title'    => $n->title,
            'body'     => $n->body,
            'link'     => $n->link,
            'is_read'  => $n->isRead(),
            'ago'      => $created ? $created->diffForHumans() : '',
            'when'     => $created ? $created->format('M j, g:i A') : '',
        ];
    }

    private function csrfOk(array $body): bool
    {
        return !empty($body['csrf_token']) && $body['csrf_token'] === ($_SESSION['csrf_token'] ?? '');
    }

    // =========================================================================
    // EDGE-BLOCK BEACON — GET /api/edge-log
    // =========================================================================

    /**
     * Client-side beacon fired when the hosting layer 403s a request before it
     * reaches PHP. Blocked requests leave no server-side trace (they never
     * execute our code, and shared hosting hides the web server's own logs), so
     * the page that observed the block reports it here. GET because only POSTs
     * are being eaten. This builds the audit trail we otherwise cannot get:
     * who was blocked, when, on which URL, and whether the retry got through.
     */
    public function edgeLog(Request $request, Response $response): Response
    {
        $q = $request->getQueryParams();
        try {
            \Illuminate\Database\Capsule\Manager::table('activity_log')->insert([
                'user_id'     => (int) ($_SESSION['user_id'] ?? 0) ?: null,
                'action'      => 'edge_block_reported',
                'entity_type' => 'http',
                'entity_id'   => null,
                'details'     => json_encode([
                    'status'  => mb_substr((string) ($q['s'] ?? ''), 0, 12),
                    'url'     => mb_substr((string) ($q['u'] ?? ''), 0, 150),
                    'attempt' => (int) ($q['a'] ?? 0),
                ]),
                'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? null,
                'created_at'  => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            error_log('[NotificationController] edgeLog insert failed: ' . $e->getMessage());
        }
        return $this->json($response, ['ok' => true]);
    }

    private function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
