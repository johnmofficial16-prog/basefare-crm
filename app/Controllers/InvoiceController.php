<?php

namespace App\Controllers;

use App\Models\Invoice;
use App\Models\Transaction;
use App\Models\User;
use App\Models\RecordNote;
use App\Services\InvoiceService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * InvoiceController — agent-facing /invoices routes.
 * Behind AuthMiddleware + AttendanceGateMiddleware + IpRestrictionMiddleware.
 *
 * Flow: anyone (except CSA) fills the maker → agents/supervisors submit for
 * approval; managers/admins save (optionally sending immediately). Approvers
 * work the /invoices/approvals queue. Delivery is a branded inline HTML email.
 */
class InvoiceController
{
    private InvoiceService $service;

    public function __construct()
    {
        $this->service = new InvoiceService();
    }

    // =========================================================================
    // LIST
    // =========================================================================

    public function index(Request $request, Response $response): Response
    {
        $role   = $_SESSION['role'] ?? 'agent';
        $userId = (int) ($_SESSION['user_id'] ?? 0);

        $params  = $request->getQueryParams();
        $page    = max(1, (int) ($params['page'] ?? 1));
        $filters = ['status' => $params['status'] ?? '', 'search' => $params['search'] ?? ''];

        if ($role === User::ROLE_MANAGER) {
            $result = $this->service->list($page, 25, $filters, null, $this->getManagerTeamIds($userId));
        } elseif (in_array($role, [User::ROLE_ADMIN, User::ROLE_SUPERVISOR], true)) {
            $result = $this->service->list($page, 25, $filters, null);
        } else {
            $result = $this->service->list($page, 25, $filters, $userId);
        }

        $pendingCount = $this->service->canApprove($role)
            ? Invoice::pendingApproval()
                ->when($role === User::ROLE_MANAGER, fn($q) =>
                    $q->whereIn('created_by', $this->getManagerTeamIds($userId)))
                ->count()
            : 0;

        return $this->render($response, 'invoices/list.php', $result + [
            'filters'      => $filters,
            'role'         => $role,
            'canApprove'   => $this->service->canApprove($role),
            'pendingCount' => $pendingCount,
        ]);
    }

    // =========================================================================
    // MAKER (create) + EDIT
    // =========================================================================

    public function maker(Request $request, Response $response): Response
    {
        $role = $_SESSION['role'] ?? 'agent';
        if ($role === User::ROLE_CSA) {
            $_SESSION['flash_error'] = 'Access denied.';
            return $response->withHeader('Location', '/invoices')->withStatus(302);
        }

        return $this->render($response, 'invoices/maker.php', [
            'role'       => $role,
            'canApprove' => $this->service->canApprove($role),
            'invoice'    => null,
        ]);
    }

    public function editForm(Request $request, Response $response, array $args): Response
    {
        $role   = $_SESSION['role'] ?? 'agent';
        $userId = (int) ($_SESSION['user_id'] ?? 0);

        $invoice = Invoice::find((int) $args['id']);
        if (!$invoice || !$this->canAccess($invoice, $role, $userId)) {
            $_SESSION['flash_error'] = 'Access denied.';
            return $response->withHeader('Location', '/invoices')->withStatus(302);
        }
        if (!$invoice->isEditable($this->service->canApprove($role))) {
            $_SESSION['flash_error'] = 'This invoice can no longer be edited.';
            return $response->withHeader('Location', '/invoices/' . $invoice->id)->withStatus(302);
        }

        return $this->render($response, 'invoices/maker.php', [
            'role'       => $role,
            'canApprove' => $this->service->canApprove($role),
            'invoice'    => $invoice,
        ]);
    }

    // =========================================================================
    // AJAX — booking picker + prefill
    // =========================================================================

    public function bookingOptions(Request $request, Response $response): Response
    {
        $role   = $_SESSION['role'] ?? 'agent';
        $userId = (int) ($_SESSION['user_id'] ?? 0);

        $query = Transaction::where('status', Transaction::STATUS_APPROVED)
            ->orderByDesc('created_at')->limit(100);

        if ($role === User::ROLE_AGENT) {
            $query->where('agent_id', $userId);
        } elseif ($role === User::ROLE_MANAGER) {
            $query->whereIn('agent_id', $this->getManagerTeamIds($userId));
        }

        $options = $query->get(['id', 'customer_name', 'pnr', 'type'])
            ->map(fn($t) => [
                'id'    => $t->id,
                'label' => trim(($t->customer_name ?: 'Unknown') . ' — ' . ($t->pnr ?: 'no PNR')),
            ])->toArray();

        return $this->json($response, ['success' => true, 'options' => $options]);
    }

    public function transactionData(Request $request, Response $response, array $args): Response
    {
        $role   = $_SESSION['role'] ?? 'agent';
        $userId = (int) ($_SESSION['user_id'] ?? 0);

        $txn = $this->findAccessibleTransaction((int) $args['id'], $role, $userId);
        if (!$txn) {
            return $this->json($response, ['success' => false, 'error' => 'Booking not found or not accessible.'], 404);
        }

        return $this->json($response, ['success' => true, 'data' => $this->service->buildPrefill($txn)]);
    }

    // =========================================================================
    // STORE / UPDATE
    // =========================================================================

    public function store(Request $request, Response $response): Response
    {
        $role   = $_SESSION['role'] ?? 'agent';
        $userId = (int) ($_SESSION['user_id'] ?? 0);

        if ($role === User::ROLE_CSA) {
            return $response->withHeader('Location', '/invoices')->withStatus(302);
        }

        $body = $request->getParsedBody() ?? [];
        if (!$this->csrfOk($body)) {
            return $this->backWithError($response, '/invoices/create', 'CSRF validation failed.');
        }
        if (trim($body['customer_name'] ?? '') === '') {
            return $this->backWithError($response, '/invoices/create', 'Passenger name is required.');
        }

        $creator = User::find($userId);
        if (!$creator) {
            return $this->backWithError($response, '/invoices/create', 'Session expired. Please sign in again.');
        }

        // Only approvers can send straight away; "send" requires a valid email.
        $sendNow = $this->service->canApprove($role) && ($body['action'] ?? '') === 'send';
        if ($sendNow && !filter_var(trim($body['customer_email'] ?? ''), FILTER_VALIDATE_EMAIL)) {
            return $this->backWithError($response, '/invoices/create', 'A valid customer email is required to send the invoice.');
        }

        $outcome = $this->service->create($body, $creator, $sendNow);
        $invoice = $outcome['invoice'];

        if ($outcome['sent']) {
            $flash = 'sent=1';
        } elseif (!empty($outcome['send_error'])) {
            $flash = 'send_error=1';
        } elseif ($this->service->canApprove($role)) {
            $flash = 'saved=1';
        } else {
            $flash = 'submitted=1';
        }

        return $response->withHeader('Location', '/invoices/' . $invoice->id . '?' . $flash)->withStatus(302);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $role   = $_SESSION['role'] ?? 'agent';
        $userId = (int) ($_SESSION['user_id'] ?? 0);

        $invoice = Invoice::find((int) $args['id']);
        if (!$invoice || !$this->canAccess($invoice, $role, $userId)) {
            return $this->backWithError($response, '/invoices', 'Access denied.');
        }
        $body = $request->getParsedBody() ?? [];
        if (!$this->csrfOk($body)) {
            return $this->backWithError($response, '/invoices/' . $invoice->id . '/edit', 'CSRF validation failed.');
        }
        if (!$invoice->isEditable($this->service->canApprove($role))) {
            return $this->backWithError($response, '/invoices/' . $invoice->id, 'This invoice can no longer be edited.');
        }

        $editor = User::find($userId);
        $this->service->update($invoice, $body, $editor);

        return $response->withHeader('Location', '/invoices/' . $invoice->id . '?updated=1')->withStatus(302);
    }

    // =========================================================================
    // VIEW
    // =========================================================================

    public function view(Request $request, Response $response, array $args): Response
    {
        $role   = $_SESSION['role'] ?? 'agent';
        $userId = (int) ($_SESSION['user_id'] ?? 0);

        $invoice = Invoice::with(['creator', 'approver'])->find((int) $args['id']);
        if (!$invoice || !$this->canAccess($invoice, $role, $userId)) {
            $_SESSION['flash_error'] = 'Access denied.';
            return $response->withHeader('Location', '/invoices')->withStatus(302);
        }

        $notes = RecordNote::where('entity_type', 'invoice')
            ->where('entity_id', $invoice->id)
            ->orderBy('created_at', 'asc')->get();

        $p = $request->getQueryParams();
        return $this->render($response, 'invoices/view.php', [
            'invoice'    => $invoice,
            'notes'      => $notes,
            'role'       => $role,
            'canApprove' => $this->service->canApprove($role),
            'flash'      => [
                'sent'       => isset($p['sent']),
                'saved'      => isset($p['saved']),
                'submitted'  => isset($p['submitted']),
                'updated'    => isset($p['updated']),
                'approved'   => isset($p['approved']),
                'rejected'   => isset($p['rejected']),
                'send_error' => isset($p['send_error']),
            ],
        ]);
    }

    // =========================================================================
    // APPROVAL QUEUE + ACTIONS
    // =========================================================================

    public function approvals(Request $request, Response $response): Response
    {
        $role   = $_SESSION['role'] ?? 'agent';
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        if (!$this->service->canApprove($role)) {
            $_SESSION['flash_error'] = 'Access denied.';
            return $response->withHeader('Location', '/invoices')->withStatus(302);
        }

        $query = Invoice::with(['creator'])->pendingApproval()->orderBy('created_at', 'asc');
        if ($role === User::ROLE_MANAGER) {
            $query->whereIn('created_by', $this->getManagerTeamIds($userId));
        }

        return $this->render($response, 'invoices/approvals.php', [
            'invoices' => $query->get(),
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
            return $response->withHeader('Location', '/invoices')->withStatus(302);
        }

        $body = $request->getParsedBody() ?? [];
        if (!$this->csrfOk($body)) {
            return $this->json($response, ['success' => false, 'error' => 'CSRF validation failed.'], 403);
        }

        $invoice = Invoice::find((int) $args['id']);
        if (!$invoice) {
            return $response->withHeader('Location', '/invoices/approvals')->withStatus(302);
        }
        // Managers may only action their own team's invoices.
        if ($role === User::ROLE_MANAGER
            && !in_array($invoice->created_by, $this->getManagerTeamIds($userId), true)) {
            $_SESSION['flash_error'] = 'Access denied.';
            return $response->withHeader('Location', '/invoices/approvals')->withStatus(302);
        }

        $approver = User::find($userId);

        if ($action === 'approve') {
            $res   = $this->service->approveAndSend($invoice, $approver);
            $flash = $res['success'] ? 'approved=1' : 'send_error=1';
        } else {
            $this->service->reject($invoice, $approver, trim($body['reason'] ?? 'No reason given'));
            $flash = 'rejected=1';
        }

        return $response->withHeader('Location', '/invoices/' . $invoice->id . '?' . $flash)->withStatus(302);
    }

    // =========================================================================
    // SEND (an approved-but-unsent invoice) + NOTE
    // =========================================================================

    public function send(Request $request, Response $response, array $args): Response
    {
        $role   = $_SESSION['role'] ?? 'agent';
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        if (!$this->service->canApprove($role)) {
            $_SESSION['flash_error'] = 'Only a manager or admin can send an invoice.';
            return $response->withHeader('Location', '/invoices')->withStatus(302);
        }

        $body = $request->getParsedBody() ?? [];
        if (!$this->csrfOk($body)) {
            return $this->json($response, ['success' => false, 'error' => 'CSRF validation failed.'], 403);
        }

        $invoice = Invoice::find((int) $args['id']);
        if (!$invoice || !$this->canAccess($invoice, $role, $userId)) {
            $_SESSION['flash_error'] = 'Access denied.';
            return $response->withHeader('Location', '/invoices')->withStatus(302);
        }
        if (!in_array($invoice->status, [Invoice::STATUS_APPROVED, Invoice::STATUS_SENT], true)) {
            return $this->backWithError($response, '/invoices/' . $invoice->id, 'Only an approved invoice can be sent.');
        }

        $res   = $this->service->send($invoice, User::find($userId));
        $flash = $res['success'] ? 'sent=1' : 'send_error=1';
        return $response->withHeader('Location', '/invoices/' . $invoice->id . '?' . $flash)->withStatus(302);
    }

    public function addNote(Request $request, Response $response, array $args): Response
    {
        $role   = $_SESSION['role'] ?? 'agent';
        $userId = (int) ($_SESSION['user_id'] ?? 0);

        $invoice = Invoice::find((int) $args['id']);
        if (!$invoice || !$this->canAccess($invoice, $role, $userId)) {
            return $this->backWithError($response, '/invoices', 'Access denied.');
        }
        $body = $request->getParsedBody() ?? [];
        if (!$this->csrfOk($body)) {
            return $this->backWithError($response, '/invoices/' . $invoice->id, 'CSRF validation failed.');
        }
        $note = trim($body['note'] ?? '');
        if ($note !== '') {
            RecordNote::log('invoice', $invoice->id, $userId, $note, 'note');
        }
        return $response->withHeader('Location', '/invoices/' . $invoice->id)->withStatus(302);
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    private function canAccess(Invoice $invoice, string $role, int $userId): bool
    {
        if (in_array($role, [User::ROLE_ADMIN, User::ROLE_SUPERVISOR], true)) return true;
        if ($role === User::ROLE_MANAGER) {
            return in_array($invoice->created_by, $this->getManagerTeamIds($userId), true)
                || $invoice->created_by === $userId;
        }
        return $invoice->created_by === $userId;
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
        $ids = $manager->getTeamAgentIds(true);
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
