<?php

namespace App\Controllers;

use App\Models\CustomerEmailThread;
use App\Models\CustomerEmailMessage;
use App\Models\Transaction;
use App\Models\User;
use App\Models\RecordNote;
use App\Services\CustomerEmailService;
use App\Services\GeminiService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * CustomerEmailController
 *
 * Agent-facing /emails routes — behind AuthMiddleware + AttendanceGateMiddleware.
 *
 * Flow: agent composes an intent → AI drafts (draft, AJAX) → agent edits the
 * preview → submits. Agents/supervisors create messages in 'pending_approval';
 * managers/admins auto-send. Approvers work the /emails/approvals queue.
 */
class CustomerEmailController
{
    private CustomerEmailService $service;
    private GeminiService        $ai;

    public function __construct()
    {
        $this->service = new CustomerEmailService();
        $this->ai      = new GeminiService();
    }

    // =========================================================================
    // LIST (thread inbox)
    // =========================================================================

    public function index(Request $request, Response $response): Response
    {
        $role   = $_SESSION['role'] ?? 'agent';
        $userId = (int) ($_SESSION['user_id'] ?? 0);

        $params  = $request->getQueryParams();
        $page    = max(1, (int) ($params['page'] ?? 1));
        $filters = ['status' => $params['status'] ?? '', 'search' => $params['search'] ?? ''];

        if ($role === User::ROLE_MANAGER) {
            $result = $this->service->listThreads($page, 25, $filters, null, $this->getManagerTeamIds($userId));
        } elseif (in_array($role, [User::ROLE_ADMIN, User::ROLE_SUPERVISOR])) {
            $result = $this->service->listThreads($page, 25, $filters, null);
        } else {
            $result = $this->service->listThreads($page, 25, $filters, $userId);
        }

        $pendingCount = $this->service->canApprove($role)
            ? CustomerEmailMessage::pendingApproval()
                ->when($role === User::ROLE_MANAGER, fn($q) =>
                    $q->whereIn('created_by', $this->getManagerTeamIds($userId)))
                ->count()
            : 0;

        return $this->render($response, 'emails/list.php', $result + [
            'filters'      => $filters,
            'role'         => $role,
            'canApprove'   => $this->service->canApprove($role),
            'pendingCount' => $pendingCount,
        ]);
    }

    // =========================================================================
    // COMPOSE FORM
    // =========================================================================

    public function composeForm(Request $request, Response $response): Response
    {
        $role = $_SESSION['role'] ?? 'agent';
        if ($role === User::ROLE_CSA) {
            $_SESSION['flash_error'] = 'Access denied.';
            return $response->withHeader('Location', '/emails')->withStatus(302);
        }

        return $this->render($response, 'emails/compose.php', [
            'role'         => $role,
            'canApprove'   => $this->service->canApprove($role),
            'aiConfigured' => $this->ai->isConfigured(),
        ]);
    }

    // =========================================================================
    // AJAX — BOOKING OPTIONS (grounding picker)
    // =========================================================================

    public function bookingOptions(Request $request, Response $response): Response
    {
        $role   = $_SESSION['role'] ?? 'agent';
        $userId = (int) ($_SESSION['user_id'] ?? 0);

        $query = Transaction::where('status', Transaction::STATUS_APPROVED)
            ->orderByDesc('created_at')
            ->limit(100);

        // Agents see only their own bookings; managers their team; admins all.
        if ($role === User::ROLE_AGENT) {
            $query->where('agent_id', $userId);
        } elseif ($role === User::ROLE_MANAGER) {
            $query->whereIn('agent_id', $this->getManagerTeamIds($userId));
        }

        $options = $query->get(['id', 'customer_name', 'customer_email', 'pnr', 'type'])
            ->map(fn($t) => [
                'id'             => $t->id,
                'label'          => trim(($t->customer_name ?: 'Unknown') . ' — ' . ($t->pnr ?: 'no PNR')),
                'customer_name'  => $t->customer_name,
                'customer_email' => $t->customer_email,
            ])->toArray();

        return $this->json($response, ['success' => true, 'options' => $options]);
    }

    // =========================================================================
    // AJAX — ITINERARY (Markdown table from the linked booking's flight data)
    // =========================================================================

    public function itinerary(Request $request, Response $response, array $args): Response
    {
        $role   = $_SESSION['role'] ?? 'agent';
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        if ($role === User::ROLE_CSA) {
            return $this->json($response, ['success' => false, 'error' => 'Access denied.'], 403);
        }

        $txn = $this->findAccessibleTransaction((int) $args['id'], $role, $userId);
        if (!$txn) {
            return $this->json($response, ['success' => false, 'error' => 'Booking not found or not accessible.'], 404);
        }

        $md = $this->service->buildItineraryMarkdown($txn);
        if (!$md) {
            return $this->json($response, ['success' => false, 'error' => 'No flight itinerary is stored on this booking.'], 422);
        }

        return $this->json($response, ['success' => true, 'markdown' => $md]);
    }

    // =========================================================================
    // AJAX — DRAFT (call the AI)
    // =========================================================================

    public function draft(Request $request, Response $response): Response
    {
        $role = $_SESSION['role'] ?? 'agent';
        if ($role === User::ROLE_CSA) {
            return $this->json($response, ['success' => false, 'error' => 'Access denied.'], 403);
        }

        $body = $request->getParsedBody() ?? [];
        if (!$this->csrfOk($body)) {
            return $this->json($response, ['success' => false, 'error' => 'CSRF validation failed.'], 403);
        }

        $intent   = trim($body['intent'] ?? '');
        $category = $body['category'] ?? 'custom';
        if ($intent === '') {
            return $this->json($response, ['success' => false, 'error' => 'Please describe what you want to send.'], 422);
        }

        // Optional grounding from a linked, access-checked transaction.
        $grounding = [];
        if (!empty($body['transaction_id'])) {
            $txn = $this->findAccessibleTransaction((int) $body['transaction_id'], $role, (int) ($_SESSION['user_id'] ?? 0));
            if ($txn) {
                $grounding = $this->service->buildGrounding($txn);
            }
        }

        // Use the customer name the agent already typed (if a booking didn't
        // already supply one) so the AI greets them by name instead of emitting
        // a [[PLACEHOLDER: Customer Name]].
        if (empty($grounding['Customer name']) && !empty($body['customer_name'])) {
            $grounding['Customer name'] = trim($body['customer_name']);
        }

        $result = $this->ai->draftEmail($intent, $grounding, $category);
        if (!$result['success']) {
            return $this->json($response, ['success' => false, 'error' => $result['error']], 502);
        }

        return $this->json($response, [
            'success'  => true,
            'subject'  => $result['subject'],
            'body'     => $result['body'],
            'ai_model' => $_ENV['VERTEX_MODEL'] ?? 'gemini-2.5-flash',
        ]);
    }

    // =========================================================================
    // STORE (create thread + first message)
    // =========================================================================

    public function store(Request $request, Response $response): Response
    {
        $role   = $_SESSION['role'] ?? 'agent';
        $userId = (int) ($_SESSION['user_id'] ?? 0);

        if ($role === User::ROLE_CSA) {
            return $response->withHeader('Location', '/emails')->withStatus(302);
        }

        $body = $request->getParsedBody() ?? [];
        if (!$this->csrfOk($body)) {
            return $this->json($response, ['success' => false, 'error' => 'CSRF validation failed.'], 403);
        }

        $customerEmail = strtolower(trim($body['customer_email'] ?? ''));
        $finalSubject  = trim($body['final_subject'] ?? '');
        $finalBody     = trim($body['final_body'] ?? '');

        if (!filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
            return $this->backWithError($response, '/emails/compose', 'A valid customer email is required.');
        }
        if ($finalSubject === '' || $finalBody === '') {
            return $this->backWithError($response, '/emails/compose', 'Subject and body cannot be empty.');
        }

        $creator = User::find($userId);
        if (!$creator) {
            return $this->backWithError($response, '/emails/compose', 'Session expired. Please sign in again.');
        }

        $outcome = $this->service->createThread([
            'transaction_id' => $body['transaction_id'] ?? null,
            'customer_name'  => trim($body['customer_name'] ?? '') ?: 'Customer',
            'customer_email' => $customerEmail,
            'subject'        => $finalSubject,
            'category'       => $body['category'] ?? 'custom',
            'intent_prompt'  => $body['intent'] ?? '',
            'ai_model'       => $body['ai_model'] ?? null,
            'ai_subject'     => $body['ai_subject'] ?? null,
            'ai_body'        => $body['ai_body'] ?? null,
            'final_subject'  => $finalSubject,
            'final_body'     => $finalBody,
        ], $creator);

        $thread = $outcome['thread'];
        $flash  = $outcome['sent']
            ? 'sent=1'
            : (isset($outcome['send_error']) && $outcome['send_error'] ? 'send_error=1' : 'submitted=1');

        return $response->withHeader('Location', '/emails/' . $thread->id . '?' . $flash)->withStatus(302);
    }

    // =========================================================================
    // VIEW (thread)
    // =========================================================================

    public function view(Request $request, Response $response, array $args): Response
    {
        $role   = $_SESSION['role'] ?? 'agent';
        $userId = (int) ($_SESSION['user_id'] ?? 0);

        $thread = CustomerEmailThread::with(['messages.author', 'messages.approver', 'agent'])
            ->find((int) $args['id']);
        if (!$thread || !$this->canAccessThread($thread, $role, $userId)) {
            $_SESSION['flash_error'] = 'Access denied.';
            return $response->withHeader('Location', '/emails')->withStatus(302);
        }

        $notes = RecordNote::where('entity_type', 'customer_email')
            ->where('entity_id', $thread->id)
            ->orderBy('created_at', 'asc')->get();

        $p = $request->getQueryParams();
        return $this->render($response, 'emails/view.php', [
            'thread'     => $thread,
            'notes'      => $notes,
            'role'       => $role,
            'canApprove' => $this->service->canApprove($role),
            'flash'      => [
                'sent'       => isset($p['sent']),
                'submitted'  => isset($p['submitted']),
                'send_error' => isset($p['send_error']),
                'approved'   => isset($p['approved']),
                'rejected'   => isset($p['rejected']),
            ],
        ]);
    }

    // =========================================================================
    // REPLY (add outbound message to an existing thread)
    // =========================================================================

    public function reply(Request $request, Response $response, array $args): Response
    {
        $role   = $_SESSION['role'] ?? 'agent';
        $userId = (int) ($_SESSION['user_id'] ?? 0);

        if ($role === User::ROLE_CSA) {
            return $response->withHeader('Location', '/emails')->withStatus(302);
        }

        $thread = CustomerEmailThread::find((int) $args['id']);
        if (!$thread || !$this->canAccessThread($thread, $role, $userId)) {
            $_SESSION['flash_error'] = 'Access denied.';
            return $response->withHeader('Location', '/emails')->withStatus(302);
        }

        $body = $request->getParsedBody() ?? [];
        if (!$this->csrfOk($body)) {
            return $this->json($response, ['success' => false, 'error' => 'CSRF validation failed.'], 403);
        }

        $finalSubject = trim($body['final_subject'] ?? '');
        $finalBody    = trim($body['final_body'] ?? '');
        if ($finalSubject === '' || $finalBody === '') {
            return $this->backWithError($response, '/emails/' . $thread->id, 'Subject and body cannot be empty.');
        }

        $creator = User::find($userId);
        // Thread replies link to the most recent SENT outbound message for proper email threading.
        $lastSent = $thread->messages()
            ->where('direction', CustomerEmailMessage::DIR_OUTBOUND)
            ->where('status', CustomerEmailMessage::STATUS_SENT)
            ->orderByDesc('id')->first();

        $outcome = $this->service->addOutboundMessage($thread, [
            'category'      => $body['category'] ?? 'custom',
            'intent_prompt' => $body['intent'] ?? '',
            'ai_model'      => $body['ai_model'] ?? null,
            'ai_subject'    => $body['ai_subject'] ?? null,
            'ai_body'       => $body['ai_body'] ?? null,
            'final_subject' => $finalSubject,
            'final_body'    => $finalBody,
        ], $creator, $lastSent);

        $flash = $outcome['sent'] ? 'sent=1'
            : (isset($outcome['send_error']) && $outcome['send_error'] ? 'send_error=1' : 'submitted=1');
        return $response->withHeader('Location', '/emails/' . $thread->id . '?' . $flash)->withStatus(302);
    }

    // =========================================================================
    // APPROVAL QUEUE + ACTIONS (manager/admin)
    // =========================================================================

    public function approvals(Request $request, Response $response): Response
    {
        $role   = $_SESSION['role'] ?? 'agent';
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        if (!$this->service->canApprove($role)) {
            $_SESSION['flash_error'] = 'Access denied.';
            return $response->withHeader('Location', '/emails')->withStatus(302);
        }

        $query = CustomerEmailMessage::with(['thread', 'author'])
            ->pendingApproval()->orderBy('created_at', 'asc');
        if ($role === User::ROLE_MANAGER) {
            $query->whereIn('created_by', $this->getManagerTeamIds($userId));
        }

        return $this->render($response, 'emails/approvals.php', [
            'messages' => $query->get(),
            'role'     => $role,
        ]);
    }

    public function approve(Request $request, Response $response, array $args): Response
    {
        return $this->handleApprovalAction($request, $response, $args, 'approve');
    }

    public function reject(Request $request, Response $response, array $args): Response
    {
        return $this->handleApprovalAction($request, $response, $args, 'reject');
    }

    private function handleApprovalAction(Request $request, Response $response, array $args, string $action): Response
    {
        $role   = $_SESSION['role'] ?? 'agent';
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        if (!$this->service->canApprove($role)) {
            return $response->withHeader('Location', '/emails')->withStatus(302);
        }

        $body = $request->getParsedBody() ?? [];
        if (!$this->csrfOk($body)) {
            return $this->json($response, ['success' => false, 'error' => 'CSRF validation failed.'], 403);
        }

        $message = CustomerEmailMessage::with('thread')->find((int) $args['mid']);
        if (!$message || $message->direction !== CustomerEmailMessage::DIR_OUTBOUND) {
            return $response->withHeader('Location', '/emails/approvals')->withStatus(302);
        }

        // Managers may only action their own team's drafts.
        if ($role === User::ROLE_MANAGER
            && !in_array($message->created_by, $this->getManagerTeamIds($userId), true)) {
            $_SESSION['flash_error'] = 'Access denied.';
            return $response->withHeader('Location', '/emails/approvals')->withStatus(302);
        }

        $approver = User::find($userId);
        $threadId = $message->thread_id;

        if ($action === 'approve') {
            $res = $this->service->approveAndSend($message, $approver);
            $flash = $res['success'] ? 'approved=1' : 'send_error=1';
        } else {
            $this->service->reject($message, $approver, trim($body['reason'] ?? 'No reason given'));
            $flash = 'rejected=1';
        }

        return $response->withHeader('Location', '/emails/' . $threadId . '?' . $flash)->withStatus(302);
    }

    // =========================================================================
    // CLOSE THREAD
    // =========================================================================

    public function close(Request $request, Response $response, array $args): Response
    {
        $role   = $_SESSION['role'] ?? 'agent';
        $userId = (int) ($_SESSION['user_id'] ?? 0);

        $thread = CustomerEmailThread::find((int) $args['id']);
        if (!$thread || !$this->canAccessThread($thread, $role, $userId)) {
            return $response->withHeader('Location', '/emails')->withStatus(302);
        }
        $body = $request->getParsedBody() ?? [];
        if (!$this->csrfOk($body)) {
            return $this->json($response, ['success' => false, 'error' => 'CSRF validation failed.'], 403);
        }

        $thread->update(['status' => CustomerEmailThread::STATUS_CLOSED]);
        RecordNote::log('customer_email', $thread->id, $userId, 'Thread closed.', 'note');
        return $response->withHeader('Location', '/emails/' . $thread->id)->withStatus(302);
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    private function canAccessThread(CustomerEmailThread $thread, string $role, int $userId): bool
    {
        if (in_array($role, [User::ROLE_ADMIN, User::ROLE_SUPERVISOR], true)) return true;
        if ($role === User::ROLE_MANAGER) {
            return in_array($thread->agent_id, $this->getManagerTeamIds($userId), true)
                || $thread->agent_id === $userId;
        }
        return $thread->agent_id === $userId;
    }

    private function findAccessibleTransaction(int $id, string $role, int $userId): ?Transaction
    {
        $txn = Transaction::find($id);
        if (!$txn) return null;
        if (in_array($role, [User::ROLE_ADMIN, User::ROLE_SUPERVISOR], true)) return $txn;
        if ($role === User::ROLE_MANAGER) {
            return in_array($txn->agent_id, $this->getManagerTeamIds($userId), true) ? $txn : null;
        }
        return $txn->agent_id === $userId ? $txn : null;
    }

    private function getManagerTeamIds(int $managerId): array
    {
        $manager = User::find($managerId);
        if (!$manager) return [-1];
        $ids = $manager->getTeamAgentIds(true); // include suspended ex-reports for record access
        return empty($ids) ? [-1] : $ids;
    }

    private function csrfOk(array $body): bool
    {
        return !empty($body['csrf_token']) && $body['csrf_token'] === ($_SESSION['csrf_token'] ?? '');
    }

    private function backWithError(Response $response, string $to, string $msg): Response
    {
        $_SESSION['flash_error'] = $msg;
        return $response->withHeader('Location', $to)->withStatus(302);
    }

    private function render(Response $response, string $view, array $data = []): Response
    {
        $viewPath = __DIR__ . '/../Views/' . $view;
        extract($data);
        ob_start();
        require $viewPath;
        $html = ob_get_clean();
        $response->getBody()->write($html);
        return $response;
    }

    private function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
