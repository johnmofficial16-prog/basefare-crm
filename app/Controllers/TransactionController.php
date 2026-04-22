<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TransactionService;

/**
 * TransactionController — Handles all transaction recorder HTTP endpoints.
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

        $isElevated   = in_array($userRole, [User::ROLE_ADMIN, User::ROLE_MANAGER]);
        $isSupervisor = ($userRole === User::ROLE_SUPERVISOR);

        $agentIds = null;
        $agentFilter = null;

        if ($isElevated) {
            // unrestricted
        } elseif ($isSupervisor) {
            $actor = \App\Models\User::find($userId);
            $teamIds = $actor ? $actor->getTeamAgentIds() : [];
            if (empty($filters['search'])) {
                $agentIds = count($teamIds) ? $teamIds : [-1];
            }
        } else {
            $agentFilter = !empty($filters['search']) ? null : $userId;
        }

        $data = $this->service->list($page, 25, $filters, $agentFilter, $agentIds);

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

        $prefill = null;
        $autofillId = $params['autofill'] ?? $params['acceptance_id'] ?? null;
        if (!empty($autofillId)) {
            $prefill = $this->service->getAcceptanceAutofill((int)$autofillId);
        }

        $isAdmin = in_array($userRole, [User::ROLE_ADMIN, User::ROLE_MANAGER]);
        $autofillOptions = $this->service->getAutofillOptions($isAdmin ? null : $userId);

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

        $required = ['type', 'customer_name', 'customer_email', 'pnr', 'total_amount', 'profit_mco'];
        foreach ($required as $field) {
            if (!isset($body[$field]) || trim((string)$body[$field]) === '') {
                $_SESSION['flash_error'] = "Missing required field: {$field}";
                return $response->withHeader('Location', '/transactions/create')->withStatus(302);
            }
        }

        if ((float)$body['profit_mco'] <= 0) {
            $_SESSION['flash_error'] = "MCO — Profit Margin must be greater than 0.";
            return $response->withHeader('Location', '/transactions/create')->withStatus(302);
        }

        if (!filter_var($body['customer_email'], FILTER_VALIDATE_EMAIL)) {
            $_SESSION['flash_error'] = 'Invalid customer email address.';
            return $response->withHeader('Location', '/transactions/create')->withStatus(302);
        }

        // Mandatory notes for audit
        if (empty(trim($body['agent_notes'] ?? ''))) {
            $_SESSION['flash_error'] = 'Agent notes are required. Please describe what you did.';
            return $response->withHeader('Location', '/transactions/create')->withStatus(302);
        }

        $passengers = json_decode($body['passengers_json'] ?? '[]', true);
        if (empty($passengers)) {
            $_SESSION['flash_error'] = 'At least one passenger is required.';
            return $response->withHeader('Location', '/transactions/create')->withStatus(302);
        }

        // Handle Proof of Sale upload
        $uploadedFiles = $request->getUploadedFiles();
        if (empty($uploadedFiles['proof_of_sale']) || $uploadedFiles['proof_of_sale']->getError() !== UPLOAD_ERR_OK) {
            $_SESSION['flash_error'] = 'Proof of sale document is missing or invalid.';
            return $response->withHeader('Location', '/transactions/create')->withStatus(302);
        }

        $proofFile  = $uploadedFiles['proof_of_sale'];
        $clientName = $proofFile->getClientFilename();
        $extension  = strtolower(pathinfo($clientName, PATHINFO_EXTENSION));

        // ── Server-side format whitelist ─────────────────────────────────────
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'heic', 'heif', 'pdf', 'eml', 'msg'];
        $allowedMimes      = [
            'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp',
            'image/heic', 'image/heif',
            'application/pdf',
            'message/rfc822',                    // .eml
            'application/vnd.ms-outlook',        // .msg
            'application/octet-stream',          // generic fallback some servers use for .eml/.msg
        ];

        if (!in_array($extension, $allowedExtensions, true)) {
            $_SESSION['flash_error'] = 'Invalid file type. Allowed formats: JPG, PNG, GIF, WEBP, BMP, HEIC, PDF, EML, MSG.';
            return $response->withHeader('Location', '/transactions/create')->withStatus(302);
        }

        // ── File size check (15 MB max) ──────────────────────────────────────
        $maxBytes = 15 * 1024 * 1024;
        if ($proofFile->getSize() > $maxBytes) {
            $_SESSION['flash_error'] = 'Proof of sale file is too large. Maximum allowed size is 15 MB. Please compress or export as PDF.';
            return $response->withHeader('Location', '/transactions/create')->withStatus(302);
        }

        // Write to a temp path first so we can finfo-check the real MIME
        $uploadDir = __DIR__ . '/../../storage/proofs';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        $filename    = uniqid('proof_') . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
        $targetPath  = $uploadDir . '/' . $filename;
        $proofFile->moveTo($targetPath);

        // Validate actual MIME after move (prevents extension spoofing)
        $finfo    = finfo_open(FILEINFO_MIME_TYPE);
        $realMime = finfo_file($finfo, $targetPath);
        finfo_close($finfo);

        // .eml files often read as text/plain — allow that too
        if ($extension === 'eml' && $realMime === 'text/plain') {
            $realMime = 'message/rfc822';
        }
        // .heic files may be detected as application/octet-stream on some hosts
        if (in_array($extension, ['heic', 'heif']) && $realMime === 'application/octet-stream') {
            $realMime = 'image/heic';
        }

        if (!in_array($realMime, $allowedMimes, true)) {
            @unlink($targetPath); // remove the rejected file
            $_SESSION['flash_error'] = 'File content does not match the expected format. Allowed: JPG, PNG, BMP, HEIC, PDF, EML, MSG.';
            return $response->withHeader('Location', '/transactions/create')->withStatus(302);
        }

        $body['proof_of_sale_path'] = 'storage/proofs/' . $filename;

        $typeData = null;
        if (!empty($body['type_specific_data_json'])) {
            $typeData = json_decode($body['type_specific_data_json'], true);
        }

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
        $isAdmin  = in_array($userRole, [User::ROLE_ADMIN, User::ROLE_MANAGER, User::ROLE_SUPERVISOR]);

        $txn = Transaction::with(['agent', 'passengers', 'cards', 'acceptance', 'voidOf', 'reversal', 'voidedByUser', 'notes.user'])
            ->find($id);

        if (!$txn) {
            $_SESSION['flash_error'] = 'Transaction not found.';
            return $response->withHeader('Location', '/transactions')->withStatus(302);
        }

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
    // VIEW PROOF  —  GET /transactions/{id}/proof
    // =========================================================================

    public function viewProof(Request $request, Response $response, array $args): Response
    {
        $id       = (int)$args['id'];
        $userRole = $_SESSION['role'] ?? 'agent';
        $isAdmin  = in_array($userRole, [User::ROLE_ADMIN, User::ROLE_MANAGER]);

        if (!$isAdmin) {
            $response->getBody()->write('Access denied. Only managers and admins can view proof of sale documents.');
            return $response->withStatus(403);
        }

        $txn = Transaction::find($id);
        if (!$txn || empty($txn->proof_of_sale_path)) {
            $response->getBody()->write('Proof of sale document not found.');
            return $response->withStatus(404);
        }

        $filePath = __DIR__ . '/../../' . $txn->proof_of_sale_path;
        if (!file_exists($filePath)) {
            $response->getBody()->write('File not found on server.');
            return $response->withStatus(404);
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $filePath);
        finfo_close($finfo);

        $stream = new \Slim\Psr7\Stream(fopen($filePath, 'r'));
        return $response->withHeader('Content-Type', $mimeType)
                        ->withHeader('Content-Disposition', 'inline; filename="proof_of_sale_' . $txn->id . '"')
                        ->withBody($stream);
    }

    // =========================================================================
    // EDIT FORM  —  GET /transactions/{id}/edit
    // =========================================================================

    public function editForm(Request $request, Response $response, array $args): Response
    {
        $id       = (int)$args['id'];
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

        $passengers = json_decode($body['passengers_json'] ?? '[]', true);
        $typeData   = !empty($body['type_specific_data_json'])
            ? json_decode($body['type_specific_data_json'], true)
            : null;

        $userRole = $_SESSION['role'] ?? 'agent';
        $isAdmin  = in_array($userRole, [User::ROLE_ADMIN, User::ROLE_MANAGER]);

        // Mandatory notes for audit on edit
        if (empty(trim($body['agent_notes'] ?? ''))) {
            $_SESSION['flash_error'] = 'Agent notes are required for auditing. Please describe what you changed.';
            return $response->withHeader('Location', '/transactions/' . $id . '/edit')->withStatus(302);
        }

        // ── Handle Proof of Sale replacement (admin/manager only) ────────────
        $uploadedFiles = $request->getUploadedFiles();
        if (
            $isAdmin &&
            !empty($uploadedFiles['proof_of_sale']) &&
            $uploadedFiles['proof_of_sale']->getError() === UPLOAD_ERR_OK
        ) {
            $proofFile  = $uploadedFiles['proof_of_sale'];
            $clientName = $proofFile->getClientFilename();
            $extension  = strtolower(pathinfo($clientName, PATHINFO_EXTENSION));

            $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'heic', 'heif', 'pdf', 'eml', 'msg'];
            $allowedMimes      = [
                'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp',
                'image/heic', 'image/heif', 'application/pdf',
                'message/rfc822', 'application/vnd.ms-outlook', 'application/octet-stream',
            ];

            $maxBytes = 15 * 1024 * 1024;
            if ($proofFile->getSize() > $maxBytes) {
                $_SESSION['flash_error'] = 'Proof of sale file is too large. Maximum allowed size is 15 MB.';
                return $response->withHeader('Location', '/transactions/' . $id . '/edit')->withStatus(302);
            }

            if (!in_array($extension, $allowedExtensions, true)) {
                $_SESSION['flash_error'] = 'Invalid file type for proof of sale. Allowed: JPG, PNG, BMP, HEIC, PDF, EML, MSG.';
                return $response->withHeader('Location', '/transactions/' . $id . '/edit')->withStatus(302);
            }

            $uploadDir = __DIR__ . '/../../storage/proofs';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            $filename   = uniqid('proof_') . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
            $targetPath = $uploadDir . '/' . $filename;
            $proofFile->moveTo($targetPath);

            $finfo    = finfo_open(FILEINFO_MIME_TYPE);
            $realMime = finfo_file($finfo, $targetPath);
            finfo_close($finfo);
            if ($extension === 'eml' && $realMime === 'text/plain') $realMime = 'message/rfc822';
            if (in_array($extension, ['heic', 'heif']) && $realMime === 'application/octet-stream') $realMime = 'image/heic';

            if (!in_array($realMime, $allowedMimes, true)) {
                @unlink($targetPath);
                $_SESSION['flash_error'] = 'Proof file content does not match the expected format.';
                return $response->withHeader('Location', '/transactions/' . $id . '/edit')->withStatus(302);
            }

            // Delete old proof file if it exists
            $txnOld = Transaction::find($id);
            if ($txnOld && !empty($txnOld->proof_of_sale_path)) {
                $oldPath = __DIR__ . '/../../' . $txnOld->proof_of_sale_path;
                if (file_exists($oldPath)) @unlink($oldPath);
            }

            $body['proof_of_sale_path'] = 'storage/proofs/' . $filename;
        }

        $data = array_merge($body, [
            'passengers'         => $passengers,
            'type_specific_data' => $typeData,
        ]);

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
            return $this->jsonResponse($response, ['error' => 'Admin access required.'], 403);
        }

        try {
            $cardData = $this->service->revealCard($cardId, $adminId, $password);
            $payload  = ['success' => true, 'data' => $cardData];
        } catch (\RuntimeException $e) {
            return $this->jsonResponse($response, ['error' => $e->getMessage()], 400);
        }

        return $this->jsonResponse($response, $payload);
    }

    // =========================================================================
    // ACCEPTANCE DATA  —  GET /transactions/acceptance-data/{id}  (AJAX)
    // =========================================================================

    public function acceptanceData(Request $request, Response $response, array $args): Response
    {
        $acceptanceId = (int)$args['id'];

        try {
            $data = $this->service->getAcceptanceAutofill($acceptanceId);
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'pre_auth') {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'error'   => 'This is a pre-authorization hold. Import is only allowed after the full signed acceptance has been received and approved.',
                ], 422);
            }
            return $this->jsonResponse($response, ['success' => false, 'error' => $e->getMessage()], 400);
        }

        $payload = $data
            ? ['success' => true, 'data' => $data]
            : ['success' => false, 'error' => 'Acceptance not found or not yet approved by the customer.'];

        return $this->jsonResponse($response, $payload);
    }

    // =========================================================================
    // APPROVE  —  POST /transactions/{id}/approve
    // =========================================================================

    public function approve(Request $request, Response $response, array $args): Response
    {
        $userId    = (int)$_SESSION['user_id'];
        $userRole  = $_SESSION['role'] ?? 'agent';

        if (!in_array($userRole, [User::ROLE_ADMIN, User::ROLE_MANAGER, User::ROLE_SUPERVISOR])) {
            $_SESSION['flash_error'] = 'You do not have permission to approve transactions.';
            return $response->withHeader('Location', '/transactions')->withStatus(302);
        }

        $txnId = (int)($args['id'] ?? 0);

        try {
            $this->service->approve($txnId, $userId, $userRole);
            $_SESSION['flash_success'] = 'Transaction #' . $txnId . ' has been approved.';
        } catch (\RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }

        return $response->withHeader('Location', '/transactions/' . $txnId)->withStatus(302);
    }

    // =========================================================================
    // VOID  —  POST /transactions/{id}/void
    // =========================================================================

    public function void(Request $request, Response $response, array $args): Response
    {
        $userId   = (int)$_SESSION['user_id'];
        $userRole = $_SESSION['role'] ?? 'agent';

        if (!in_array($userRole, [User::ROLE_ADMIN, User::ROLE_MANAGER])) {
            $_SESSION['flash_error'] = 'Only managers and admins can void transactions.';
            return $response->withHeader('Location', '/transactions')->withStatus(302);
        }

        $txnId  = (int)($args['id'] ?? 0);
        $body   = (array)$request->getParsedBody();
        $reason = trim($body['void_reason'] ?? '');

        try {
            $reversal = $this->service->void($txnId, $reason, $userId);
            $_SESSION['flash_success'] = 'Transaction #' . $txnId . ' voided. Reversal #' . $reversal->id . ' created.';
        } catch (\RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }

        return $response->withHeader('Location', '/transactions/' . $txnId)->withStatus(302);
    }

    // =========================================================================
    // UPDATE DISPUTE  —  POST /transactions/{id}/dispute  (Admin/Manager only)
    // =========================================================================

    public function updateDispute(Request $request, Response $response, array $args): Response
    {
        $userId   = (int)$_SESSION['user_id'];
        $userRole = $_SESSION['role'] ?? 'agent';

        if (!in_array($userRole, [User::ROLE_ADMIN, User::ROLE_MANAGER])) {
            return $this->jsonResponse($response, ['error' => 'Admin access required.'], 403);
        }

        $id            = (int)($args['id'] ?? 0);
        $body          = (array)$request->getParsedBody();
        $disputeStatus = trim($body['dispute_status'] ?? '');
        $disputeNotes  = trim($body['dispute_notes'] ?? '');

        $allowed = [
            Transaction::DISPUTE_OPENED,
            Transaction::DISPUTE_CHARGEBACK,
            Transaction::DISPUTE_REFUNDED,
            Transaction::DISPUTE_RESOLVED,
        ];

        if (!in_array($disputeStatus, $allowed)) {
            return $this->jsonResponse($response, ['error' => 'Invalid dispute status.'], 422);
        }

        $txn = Transaction::find($id);
        if (!$txn) {
            return $this->jsonResponse($response, ['error' => 'Transaction not found.'], 404);
        }

        $txn->dispute_status     = $disputeStatus;
        $txn->dispute_notes      = $disputeNotes ?: null;
        $txn->dispute_flagged_at = date('Y-m-d H:i:s');
        $txn->dispute_flagged_by = $userId;
        $txn->save();

        $noteText = "[Dispute] Status changed to: {$disputeStatus}";
        if ($disputeNotes) { $noteText .= " — Notes: {$disputeNotes}"; }
        \App\Models\RecordNote::log('transaction', $id, $userId, $noteText, 'dispute');

        [$label, $class] = $txn->disputeBadge();

        return $this->jsonResponse($response, [
            'success'        => true,
            'dispute_status' => $disputeStatus,
            'label'          => $label,
            'class'          => $class,
        ]);
    }

    // =========================================================================
    // UPDATE GATEWAY  —  POST /transactions/{id}/gateway  (Admin/Manager only)
    // =========================================================================

    public function updateGateway(Request $request, Response $response, array $args): Response
    {
        $userId   = (int)$_SESSION['user_id'];
        $userRole = $_SESSION['role'] ?? 'agent';

        if (!in_array($userRole, [User::ROLE_ADMIN, User::ROLE_MANAGER])) {
            return $this->jsonResponse($response, ['error' => 'Admin access required.'], 403);
        }

        $id            = (int)($args['id'] ?? 0);
        $body          = (array)$request->getParsedBody();
        $gatewayStatus = trim($body['gateway_status'] ?? '');
        $gatewayTxnId  = trim($body['gateway_transaction_id'] ?? '');

        if (!in_array($gatewayStatus, [Transaction::GATEWAY_SUCCESS, Transaction::GATEWAY_DECLINED])) {
            return $this->jsonResponse($response, ['error' => 'Invalid gateway status.'], 422);
        }

        if ($gatewayStatus === Transaction::GATEWAY_SUCCESS && empty($gatewayTxnId)) {
            return $this->jsonResponse($response, ['error' => 'Gateway Transaction ID is required when charge is successful.'], 422);
        }

        $txn = Transaction::find($id);
        if (!$txn) {
            return $this->jsonResponse($response, ['error' => 'Transaction not found.'], 404);
        }

        $txn->gateway_status         = $gatewayStatus;
        $txn->gateway_transaction_id = ($gatewayStatus === Transaction::GATEWAY_SUCCESS) ? $gatewayTxnId : null;
        $txn->gateway_actioned_at    = date('Y-m-d H:i:s');
        $txn->gateway_actioned_by    = $userId;
        $txn->save();

        $noteText = "[Gateway] Charge marked as: {$gatewayStatus}";
        if (!empty($gatewayTxnId)) { $noteText .= " — Gateway Txn ID: {$gatewayTxnId}"; }
        \App\Models\RecordNote::log('transaction', $id, $userId, $noteText, 'gateway');

        [$label, $class] = $txn->gatewayBadge();

        return $this->jsonResponse($response, [
            'success'                => true,
            'gateway_status'         => $gatewayStatus,
            'gateway_transaction_id' => $txn->gateway_transaction_id,
            'label'                  => $label,
            'class'                  => $class,
        ]);
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

        return $this->jsonResponse($response, ['success' => true, 'options' => $options]);
    }

    // =========================================================================
    // ADD NOTE  —  POST /transactions/{id}/note  (AJAX)
    // =========================================================================

    public function addNote(Request $request, Response $response, array $args): Response
    {
        $id     = (int)($args['id'] ?? 0);
        $userId = $_SESSION['user_id'];
        $body   = $request->getParsedBody();
        $note   = trim($body['note'] ?? '');
        $action = trim($body['action'] ?? 'note');

        if (empty($note)) {
            return $this->jsonResponse($response, ['error' => 'Note cannot be empty.'], 422);
        }

        $txn = Transaction::find($id);
        if (!$txn) {
            return $this->jsonResponse($response, ['error' => 'Transaction not found.'], 404);
        }

        $userRole = $_SESSION['role'] ?? 'agent';
        if ($userRole === User::ROLE_AGENT && $txn->agent_id !== $userId) {
            return $this->jsonResponse($response, ['error' => 'Access denied.'], 403);
        }

        $rn = \App\Models\RecordNote::log('transaction', $id, $userId, $note, $action);
        $rn->load('user');

        return $this->jsonResponse($response, [
            'success'    => true,
            'id'         => $rn->id,
            'user_name'  => $rn->user->name ?? 'Unknown',
            'user_role'  => $rn->user->role ?? '',
            'action'     => $rn->action,
            'note'       => $rn->note,
            'created_at' => $rn->created_at->format('M d, Y g:i A'),
        ]);
    }

    // =========================================================================
    // CSV EXPORT  —  GET /transactions/export  (admin/manager only)
    // =========================================================================

    public function exportCsv(Request $request, Response $response): Response
    {
        $userRole = $_SESSION['role'] ?? 'agent';
        if (!in_array($userRole, [User::ROLE_ADMIN, User::ROLE_MANAGER])) {
            $response->getBody()->write('Access denied.');
            return $response->withStatus(403);
        }

        $params  = $request->getQueryParams();
        $filters = [
            'search'         => trim($params['search'] ?? ''),
            'type'           => $params['type'] ?? '',
            'status'         => $params['status'] ?? '',
            'pnr'            => $params['pnr'] ?? '',
            'payment_status' => $params['payment_status'] ?? '',
            'date_from'      => $params['date_from'] ?? '',
            'date_to'        => $params['date_to'] ?? '',
        ];

        // All records matching filters, no pagination cap
        $all   = $this->service->list(1, 99999, $filters, null, null);
        $items = $all['items'];

        $headers = [
            'ID', 'Date', 'Type', 'Customer Name', 'Phone', 'Email',
            'PNR', 'Amount', 'Currency', 'Cost', 'Profit/MCO',
            'Payment Method', 'Payment Status', 'Status', 'Agent',
        ];

        $rows = $items->map(fn($t) => [
            $t->id,
            $t->created_at,
            $t->type,
            $t->customer_name,
            $t->customer_phone,
            $t->customer_email,
            $t->pnr,
            $t->total_amount,
            $t->currency,
            $t->cost_amount,
            $t->profit_mco,
            $t->payment_method,
            $t->payment_status,
            $t->status,
            $t->agent?->name ?? '—',
        ]);

        $filename = 'transactions_' . date('Y-m-d') . '.csv';
        return $this->csvResponse($response, $headers, $rows, $filename);
    }

    // =========================================================================
    // HELPER
    // =========================================================================

    private function jsonResponse(Response $response, mixed $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }

    private function csvResponse(Response $response, array $headers, iterable $rows, string $filename): Response
    {
        $tmp = fopen('php://temp', 'r+');
        fputcsv($tmp, $headers);
        foreach ($rows as $row) {
            fputcsv($tmp, array_values((array)$row));
        }
        rewind($tmp);
        $csv = stream_get_contents($tmp);
        fclose($tmp);

        $response->getBody()->write("\xEF\xBB\xBF" . $csv); // UTF-8 BOM for Excel
        return $response
            ->withHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Cache-Control', 'no-cache, no-store')
            ->withHeader('Pragma', 'no-cache');
    }
}
