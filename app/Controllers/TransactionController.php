<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TransactionService;

/**
 * TransactionController — Handles all transaction recorder HTTP endpoints.
 *
 * Routes:
 *   GET  /transactions              → index  (list)
 *   GET  /transactions/create       → createForm
 *   POST /transactions/create       → store
 *   GET  /transactions/{id}         → view
 *   GET  /transactions/{id}/edit    → editForm
 *   POST /transactions/{id}/edit    → update
 *   POST /transactions/{id}/approve → approve
 *   POST /transactions/{id}/void   → void
 *   POST /transactions/reveal-card  → revealCard (AJAX)
 *   GET  /transactions/acceptance-data/{id} → acceptanceData (AJAX)
 *   GET  /transactions/autofill-options     → autofillOptions (AJAX)
 */
class TransactionController
{
    private TransactionService $service;

    public function __construct()
    {
        $this->service = new TransactionService();
    }

    // =========================================================================
    // LIST  —  GET /transactions
    // =========================================================================

    public function index(Request $request, Response $response): Response
    {
        $params   = $request->getQueryParams();
        $userId   = $_SESSION['user_id'];
        $userRole = $_SESSION['role'] ?? 'agent';
        $page     = max(1, (int)($params['page'] ?? 1));

        $filters = [
            'search'         => trim($params['search'] ?? ''),
            'type'           => $params['type'] ?? '',
            'status'         => $params['status'] ?? '',
            'pnr'            => $params['pnr'] ?? '',
            'payment_status' => $params['payment_status'] ?? '',
            'date_from'      => $params['date_from'] ?? '',
            'date_to'        => $params['date_to'] ?? '',
        ];

        $isAdmin = in_array($userRole, [User::ROLE_ADMIN, User::ROLE_MANAGER]);

        // Agents with a universal search bypass the agentFilter — they can search all records
        // (read-only; edit/approve routes enforce ownership separately).
        // Without a search query, agents only see their own transactions.
        $agentFilter = null;
        if (!$isAdmin) {
            $agentFilter = !empty($filters['search']) ? null : $userId;
        }

        $data = $this->service->list($page, 25, $filters, $agentFilter);

        ob_start();
        require __DIR__ . '/../Views/transactions/list.php';
        $html = ob_get_clean();
        $response->getBody()->write($html);
        return $response;
    }

    // =========================================================================
    // CREATE FORM  —  GET /transactions/create
    // =========================================================================

    public function createForm(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $userId = $_SESSION['user_id'];
        $userRole = $_SESSION['role'] ?? 'agent';

        // Pre-fill from acceptance if autofill/acceptance_id provided
        $prefill = null;
        $autofillId = $params['autofill'] ?? $params['acceptance_id'] ?? null;
        if (!empty($autofillId)) {
            $prefill = $this->service->getAcceptanceAutofill((int)$autofillId);
        }

        // Autofill options for the import dropdown
        $isAdmin = in_array($userRole, [User::ROLE_ADMIN, User::ROLE_MANAGER]);
        $autofillOptions = $this->service->getAutofillOptions($isAdmin ? null : $userId);

        // Flash messages
        $flashError   = $_SESSION['flash_error'] ?? null;
        $flashSuccess = $_SESSION['flash_success'] ?? null;
        unset($_SESSION['flash_error'], $_SESSION['flash_success']);

        ob_start();
        require __DIR__ . '/../Views/transactions/create.php';
        $html = ob_get_clean();
        $response->getBody()->write($html);
        return $response;
    }

    // =========================================================================
    // STORE  —  POST /transactions/create
    // =========================================================================

    public function store(Request $request, Response $response): Response
    {
        $agentId = $_SESSION['user_id'];
        $body    = $request->getParsedBody();

        // ── Validate required fields ───────────────────────────────────
        $required = ['type', 'customer_name', 'customer_email', 'pnr', 'total_amount'];
        foreach ($required as $field) {
            if (empty($body[$field])) {
                $_SESSION['flash_error'] = "Missing required field: {$field}";
                return $response->withHeader('Location', '/transactions/create')->withStatus(302);
            }
        }

        // ── Validate email ─────────────────────────────────────────────
        if (!filter_var($body['customer_email'], FILTER_VALIDATE_EMAIL)) {
            $_SESSION['flash_error'] = 'Invalid customer email address.';
            return $response->withHeader('Location', '/transactions/create')->withStatus(302);
        }

        // ── Parse passengers JSON ──────────────────────────────────────
        $passengers = json_decode($body['passengers_json'] ?? '[]', true);
        if (empty($passengers)) {
            $_SESSION['flash_error'] = 'At least one passenger is required.';
            return $response->withHeader('Location', '/transactions/create')->withStatus(302);
        }

        // ── Parse type-specific data JSON ──────────────────────────────
        $typeData = null;
        if (!empty($body['type_specific_data_json'])) {
            $typeData = json_decode($body['type_specific_data_json'], true);
        }

        // ── Build data array for service ───────────────────────────────
        $data = array_merge($body, [
            'passengers'         => $passengers,
            'type_specific_data' => $typeData,
        ]);

        try {
            $txn = $this->service->create($data, $agentId);
        } catch (\Exception $e) {
            $_SESSION['flash_error'] = 'Error creating transaction: ' . $e->getMessage();
            return $response->withHeader('Location', '/transactions/create')->withStatus(302);
        }

        $_SESSION['flash_success'] = 'Transaction #' . $txn->id . ' recorded successfully.';
        return $response->withHeader('Location', '/transactions/' . $txn->id)->withStatus(302);
    }

    // =========================================================================
    // VIEW  —  GET /transactions/{id}
    // =========================================================================

    public function view(Request $request, Response $response, array $args): Response
    {
        $id       = (int)$args['id'];
        $userId   = $_SESSION['user_id'];
        $userRole = $_SESSION['role'] ?? 'agent';
        $isAdmin  = in_array($userRole, [User::ROLE_ADMIN, User::ROLE_MANAGER]);

        $txn = Transaction::with(['agent', 'passengers', 'cards', 'acceptance', 'voidOf', 'reversal', 'voidedByUser'])
            ->find($id);

        if (!$txn) {
            $_SESSION['flash_error'] = 'Transaction not found.';
            return $response->withHeader('Location', '/transactions')->withStatus(302);
        }

        // Agents can only view their own
        if (!$isAdmin && $txn->agent_id !== $userId) {
            $_SESSION['flash_error'] = 'Access denied.';
            return $response->withHeader('Location', '/transactions')->withStatus(302);
        }

        $flashSuccess = $_SESSION['flash_success'] ?? null;
        $flashError   = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash_success'], $_SESSION['flash_error']);

        ob_start();
        require __DIR__ . '/../Views/transactions/view.php';
        $html = ob_get_clean();
        $response->getBody()->write($html);
        return $response;
    }

    // =========================================================================
    // EDIT FORM  —  GET /transactions/{id}/edit
    // =========================================================================

    public function editForm(Request $request, Response $response, array $args): Response
    {
        $id       = (int)$args['id'];
        $userId   = $_SESSION['user_id'];
        $userRole = $_SESSION['role'] ?? 'agent';
        $isAdmin  = in_array($userRole, [User::ROLE_ADMIN, User::ROLE_MANAGER]);

        $txn = Transaction::with(['passengers', 'cards'])->find($id);

        if (!$txn) {
            $_SESSION['flash_error'] = 'Transaction not found.';
            return $response->withHeader('Location', '/transactions')->withStatus(302);
        }

        if (!$txn->isEditable($isAdmin)) {
            $_SESSION['flash_error'] = 'This transaction cannot be edited by your role.';
            return $response->withHeader('Location', '/transactions/' . $id)->withStatus(302);
        }

        $flashError = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash_error']);

        ob_start();
        require __DIR__ . '/../Views/transactions/edit.php';
        $html = ob_get_clean();
        $response->getBody()->write($html);
        return $response;
    }

    // =========================================================================
    // UPDATE  —  POST /transactions/{id}/edit
    // =========================================================================

    public function update(Request $request, Response $response, array $args): Response
    {
        $id   = (int)$args['id'];
        $body = $request->getParsedBody();

        // Parse passengers + type data
        $passengers = json_decode($body['passengers_json'] ?? '[]', true);
        $typeData   = !empty($body['type_specific_data_json'])
            ? json_decode($body['type_specific_data_json'], true)
            : null;

        $data = array_merge($body, [
            'passengers'         => $passengers,
            'type_specific_data' => $typeData,
        ]);

        $userRole = $_SESSION['role'] ?? 'agent';
        $isAdmin  = in_array($userRole, [User::ROLE_ADMIN, User::ROLE_MANAGER]);

        try {
            $txn = $this->service->update($id, $data, $isAdmin);
        } catch (\RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
            return $response->withHeader('Location', '/transactions/' . $id . '/edit')->withStatus(302);
        }

        $_SESSION['flash_success'] = 'Transaction #' . $id . ' updated.';
        return $response->withHeader('Location', '/transactions/' . $id)->withStatus(302);
    }


    // =========================================================================
    // REVEAL CARD  —  POST /transactions/reveal-card  (AJAX)
    // =========================================================================

    public function revealCard(Request $request, Response $response): Response
    {
        $body     = $request->getParsedBody();
        $cardId   = (int)($body['card_id'] ?? 0);
        $password = $body['password'] ?? '';
        $adminId  = $_SESSION['user_id'];
        $userRole = $_SESSION['role'] ?? 'agent';

        $payload = ['success' => false];

        if (!in_array($userRole, [User::ROLE_ADMIN, User::ROLE_MANAGER])) {
            $payload['error'] = 'Admin access required.';
            $response->getBody()->write(json_encode($payload));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
        }

        try {
            $cardData = $this->service->revealCard($cardId, $adminId, $password);
            $payload  = ['success' => true, 'data' => $cardData];
        } catch (\RuntimeException $e) {
            $payload['error'] = $e->getMessage();
            $response->getBody()->write(json_encode($payload));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        $response->getBody()->write(json_encode($payload));
        return $response->withHeader('Content-Type', 'application/json');
    }

    // =========================================================================
    // ACCEPTANCE DATA  —  GET /transactions/acceptance-data/{id}  (AJAX)
    // =========================================================================

    public function acceptanceData(Request $request, Response $response, array $args): Response
    {
        $acceptanceId = (int)$args['id'];
        $data = $this->service->getAcceptanceAutofill($acceptanceId);

        $payload = $data
            ? ['success' => true, 'data' => $data]
            : ['success' => false, 'error' => 'Acceptance not found or not approved.'];

        $response->getBody()->write(json_encode($payload));
        return $response->withHeader('Content-Type', 'application/json');
    }

    // =========================================================================
    // AUTOFILL OPTIONS  —  GET /transactions/autofill-options  (AJAX)
    // =========================================================================

    public function autofillOptions(Request $request, Response $response): Response
    {
        $userId   = $_SESSION['user_id'];
        $userRole = $_SESSION['role'] ?? 'agent';
        $isAdmin  = in_array($userRole, [User::ROLE_ADMIN, User::ROLE_MANAGER]);

        $options = $this->service->getAutofillOptions($isAdmin ? null : $userId);

        $response->getBody()->write(json_encode(['success' => true, 'options' => $options]));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
