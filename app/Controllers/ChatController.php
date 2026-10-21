<?php

namespace App\Controllers;

use App\Services\ChatError;
use App\Services\ChatService;
use Illuminate\Database\Capsule\Manager as DB;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * ChatController — Messages: staff writing to each other inside the CRM.
 *
 *   GET  /messages                 inbox + the open conversation (?c=ID) or a new message (?new=1&to=ID)
 *   POST /messages                 start a conversation (AJAX)
 *   POST /messages/broadcast       admin: one message to everyone or chosen groups (AJAX)
 *   POST /messages/{id}/reply      reply (AJAX)
 *   GET  /messages/{id}/poll       new messages since ?after=ID (AJAX, the open conversation polls)
 *   GET  /messages/all             admin: every conversation, searchable, read-only
 *   GET  /messages/all/{id}        admin: one conversation, read-only (logged to activity_log)
 */
class ChatController
{
    private ChatService $chat;

    public function __construct()
    {
        $this->chat = new ChatService();
    }

    private function uid(): int
    {
        return (int) ($_SESSION['user_id'] ?? 0);
    }

    private function isAdmin(): bool
    {
        return ($_SESSION['role'] ?? '') === 'admin';
    }

    public function index(Request $request, Response $response): Response
    {
        $q = $request->getQueryParams();
        $uid = $this->uid();
        $open = null;
        $error = null;
        if (!empty($q['c']) && ctype_digit((string) $q['c'])) {
            try {
                $open = $this->chat->thread($uid, (int) $q['c']);
            } catch (ChatError $e) {
                $error = $e->getMessage();
            }
        }
        $broadcasting = !$open && !empty($q['broadcast']) && $this->isAdmin();
        $composing = !$open && !$broadcasting && (!empty($q['new']) || !empty($q['to']));
        return $this->render($response, 'messages/index.php', [
            'threads'    => $this->chat->inbox($uid),
            'open'       => $open,
            'composing'  => $composing,
            'broadcasting' => $broadcasting,
            'groups'     => $broadcasting ? ['labels' => ChatService::GROUPS, 'counts' => $this->chat->groupCounts($uid)] : null,
            'preSubject' => isset($q['subject']) ? mb_substr((string) $q['subject'], 0, 160) : '',
            'preTo'      => isset($q['to']) && ctype_digit((string) $q['to']) ? (int) $q['to'] : null,
            'colleagues' => $this->chat->colleagues($uid),
            'maxTo'      => ChatService::MAX_TO,
            'isAdmin'    => $this->isAdmin(),
            'error'      => $error,
            'csrf'       => $_SESSION['csrf_token'] ?? '',
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        $b = (array) $request->getParsedBody();
        $to = $b['to'] ?? [];
        if (is_string($to)) {
            $to = array_filter(explode(',', $to));
        }
        try {
            $id = $this->chat->compose($this->uid(), (array) $to, $b['subject'] ?? null, $b['body'] ?? null, $this->ip());
            return $this->json($response, ['ok' => true, 'id' => $id]);
        } catch (ChatError $e) {
            return $this->json($response, ['ok' => false, 'error' => $e->getMessage(), 'field' => $e->field], $e->status);
        }
    }

    public function broadcast(Request $request, Response $response): Response
    {
        if (!$this->isAdmin()) {
            return $this->json($response, ['ok' => false, 'error' => 'Only admins can send a broadcast.'], 403);
        }
        $b = (array) $request->getParsedBody();
        $groups = $b['groups'] ?? [];
        if (is_string($groups)) {
            $groups = array_filter(explode(',', $groups));
        }
        try {
            [$id, $n] = $this->chat->broadcast($this->uid(), array_map('strval', (array) $groups), $b['subject'] ?? null, $b['body'] ?? null, $this->ip());
            return $this->json($response, ['ok' => true, 'id' => $id, 'recipients' => $n]);
        } catch (ChatError $e) {
            return $this->json($response, ['ok' => false, 'error' => $e->getMessage(), 'field' => $e->field], $e->status);
        }
    }

    public function reply(Request $request, Response $response, array $args): Response
    {
        $b = (array) $request->getParsedBody();
        $id = (int) $args['id'];
        try {
            $this->chat->reply($this->uid(), $id, $b['body'] ?? null, $this->ip());
            $after = isset($b['after']) && ctype_digit((string) $b['after']) ? (int) $b['after'] : 0;
            return $this->json($response, ['ok' => true] + $this->chat->since($this->uid(), $id, $after));
        } catch (ChatError $e) {
            return $this->json($response, ['ok' => false, 'error' => $e->getMessage(), 'field' => $e->field], $e->status);
        }
    }

    public function poll(Request $request, Response $response, array $args): Response
    {
        $after = (string) ($request->getQueryParams()['after'] ?? '0');
        try {
            return $this->json($response, ['ok' => true] + $this->chat->since($this->uid(), (int) $args['id'], ctype_digit($after) ? (int) $after : 0));
        } catch (ChatError $e) {
            return $this->json($response, ['ok' => false, 'error' => $e->getMessage()], $e->status);
        }
    }

    public function all(Request $request, Response $response): Response
    {
        if (!$this->isAdmin()) {
            return $response->withHeader('Location', '/messages')->withStatus(302);
        }
        $q = trim((string) ($request->getQueryParams()['q'] ?? ''));
        return $this->render($response, 'messages/all.php', ['list' => $this->chat->adminList(mb_substr($q, 0, 80)), 'q' => $q]);
    }

    public function allThread(Request $request, Response $response, array $args): Response
    {
        if (!$this->isAdmin()) {
            return $response->withHeader('Location', '/messages')->withStatus(302);
        }
        try {
            $t = $this->chat->adminThread((int) $args['id']);
        } catch (ChatError $e) {
            return $response->withHeader('Location', '/messages/all')->withStatus(302);
        }
        try {
            DB::table('activity_log')->insert([
                'user_id' => $this->uid(), 'action' => 'message_conversation_viewed', 'entity_type' => 'chat_threads', 'entity_id' => $t['id'],
                'details' => json_encode(['members' => array_column($t['members'], 'name')]), 'ip_address' => $this->ip(), 'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            error_log('[messages] view log failed: ' . $e->getMessage());
        }
        $opens = (int) DB::table('activity_log')->where('action', 'message_conversation_viewed')->where('entity_id', $t['id'])->count();
        return $this->render($response, 'messages/admin_thread.php', ['t' => $t, 'opens' => $opens]);
    }

    private function ip(): string
    {
        return mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    }

    private function render(Response $response, string $view, array $data = []): Response
    {
        extract($data);
        ob_start();
        require __DIR__ . '/../Views/' . $view;
        $response->getBody()->write(ob_get_clean());
        return $response;
    }

    private function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
