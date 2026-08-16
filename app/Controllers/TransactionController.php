<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TransactionService;
use App\Services\ShiftService;

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

        $isAdmin      = ($userRole === User::ROLE_ADMIN);
        $isManager    = ($userRole === User::ROLE_MANAGER);
        $isSupervisor = ($userRole === User::ROLE_SUPERVISOR);
        $isCsa        = ($userRole === User::ROLE_CSA);

        $agentIds = null;
        $agentFilter = null;

        // "Universal search" is a DELIBERATE feature: an agent taking a call must be
        // able to find a customer's booking that another agent created (read-only —
        // view() still blocks opening someone else's record).
        //
        // The defect (fixed 2026-08-12) was that ANY non-empty term unlocked the whole
        // company: `?search=a` matched nearly every customer and dumped names, phones,
        // emails, PNRs and amounts as a browsable list. Cross-scope search now requires
        // a term specific enough to be a genuine customer lookup, so the feature keeps
        // working while bulk enumeration does not.
        $searchTerm     = trim((string) ($filters['search'] ?? ''));
        $isTargetedLookup = self::isTargetedLookup($searchTerm);

        if ($isAdmin || $isCsa) {
            // unrestricted
        } elseif ($isManager) {
            // Manager sees their own transactions plus their team's
            $teamIds  = $this->getManagerTeamIds((int)$userId);
            // Include the manager's own records
            $teamIds  = array_unique(array_merge($teamIds, [(int)$userId]));
            if (!$isTargetedLookup) {
                $agentIds = $teamIds;
            }
        } elseif ($isSupervisor) {
            $actor   = \App\Models\User::find($userId);
            $teamIds = $actor ? $actor->getTeamAgentIds() : [];
            if (!$isTargetedLookup) {
                $agentIds = count($teamIds) ? $teamIds : [-1];
            }
        } else {
            $agentFilter = $isTargetedLookup ? null : $userId;
        }

        // Drives the "Showing results across all agents" banner so the UI never
        // claims a wider scope than was actually applied.
        $crossAgentSearch = $isTargetedLookup && !$isAdmin;

        $data = $this->service->list($page, 25, $filters, $agentFilter, $agentIds);

        // ── "Today" quick stats ──────────────────────────────────────────────
        // Counted over the operations business day (6 PM → 6 PM by default), not
        // the calendar day, so the overnight shift's totals don't reset at midnight.
        // Computed server-side over the whole dataset (not just the visible page)
        // and scoped to what this role is responsible for. Excludes voided records.
        [$dayStart, $dayEnd] = ShiftService::businessDayBounds();

        if ($isAdmin || $isCsa) {
            $statScope = null;                                  // unrestricted
        } elseif ($isManager) {
            $statScope = array_unique(array_merge($this->getManagerTeamIds((int)$userId), [(int)$userId]));
        } elseif ($isSupervisor) {
            $supTeam   = (\App\Models\User::find($userId)?->getTeamAgentIds()) ?: [];
            $statScope = count($supTeam) ? $supTeam : [-1];
        } else {
            $statScope = [(int)$userId];                        // agent: own only
        }

        $statBase = fn() => Transaction::whereBetween('created_at', [$dayStart, $dayEnd])
            ->where('status', '!=', Transaction::STATUS_VOIDED)
            ->when($statScope !== null, fn($q) => $q->whereIn('agent_id', $statScope));

        $todayStats = [
            'count'   => $statBase()->count(),
            'revenue' => (float) $statBase()->sum('total_amount'),
            'mco'     => (float) $statBase()->sum('profit_mco') - (float) $statBase()->sum('refund_mco_impact'), // Net MCO (refund-adjusted)
        ];

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

        if ($userRole === User::ROLE_CSA) {
            $_SESSION['flash_error'] = 'Access denied. CSA cannot create transactions.';
            return $response->withHeader('Location', '/transactions')->withStatus(302);
        }

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
        $userRole = $_SESSION['role'] ?? 'agent';
        $body    = (array) $request->getParsedBody();

        if ($userRole === User::ROLE_CSA) {
            $_SESSION['flash_error'] = 'Access denied. CSA cannot create transactions.';
            return $response->withHeader('Location', '/transactions')->withStatus(302);
        }

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

        // Handle Proof of Sale upload — supports multiple files
        $uploadedFiles = $request->getUploadedFiles();
        $rawProofs = $uploadedFiles['proof_of_sale'] ?? [];
        if (!is_array($rawProofs)) {
            $rawProofs = [$rawProofs];
        }
        $rawProofs = array_filter($rawProofs, fn($f) => $f && $f->getError() === UPLOAD_ERR_OK);

        if (empty($rawProofs)) {
            $_SESSION['flash_error'] = 'Proof of sale document is missing or invalid.';
            return $response->withHeader('Location', '/transactions/create')->withStatus(302);
        }

        $savedPaths = $this->saveProofFiles($rawProofs, '/transactions/create');
        if (is_string($savedPaths)) {
            return $response->withHeader('Location', $savedPaths)->withStatus(302);
        }
        $body['proof_of_sale_path'] = json_encode($savedPaths);

        $typeData = null;
        if (!empty($body['type_specific_data_json'])) {
            $typeData = json_decode($body['type_specific_data_json'], true);
        }

        if (!empty($body['fare_breakdown_json'])) {
            $fareData = json_decode($body['fare_breakdown_json'], true);
            if (is_array($typeData)) {
                $typeData['fare_breakdown'] = $fareData;
            } else {
                $typeData = ['fare_breakdown' => $fareData];
            }
        }

        $data = array_merge($body, [
            'passengers'         => $passengers,
            'type_specific_data' => $typeData,
            'is_miles_booking'   => ($body['type'] ?? '') === 'award_booking',
            'miles_used'         => !empty($body['miles_used']) ? (int)$body['miles_used'] : null,
            'miles_program'      => !empty($body['miles_program']) ? trim($body['miles_program']) : null,
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

        $txn = Transaction::with(['agent', 'passengers', 'cards', 'acceptance', 'voidOf', 'reversal', 'voidedByUser', 'refundedByUser', 'notes.user', 'reminders.creator'])
            ->find($id);

        if (!$txn) {
            $_SESSION['flash_error'] = 'Transaction not found.';
            return $response->withHeader('Location', '/transactions')->withStatus(302);
        }

        // Manager: only allow access to own team's transactions (including ones they created themselves)
        if ($userRole === User::ROLE_MANAGER) {
            $teamIds = $this->getManagerTeamIds((int)$userId);
            if (!in_array($txn->agent_id, $teamIds) && $txn->agent_id !== (int)$userId) {
                $_SESSION['flash_error'] = 'Access denied.';
                return $response->withHeader('Location', '/transactions')->withStatus(302);
            }
        } elseif (!$isAdmin && $userRole !== User::ROLE_CSA && $txn->agent_id !== $userId) {
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
        $userId   = $_SESSION['user_id'];
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

        // Manager: only allow access to own team's transactions (including ones they created themselves)
        if ($userRole === User::ROLE_MANAGER) {
            $teamIds = $this->getManagerTeamIds((int)$userId);
            if (!in_array($txn->agent_id, $teamIds) && $txn->agent_id !== (int)$userId) {
                $response->getBody()->write('Access denied.');
                return $response->withStatus(403);
            }
        }

        // Normalise — may be legacy string or JSON array
        $raw   = $txn->proof_of_sale_path;
        $paths = is_array($raw) ? $raw : (json_decode((string)$raw, true) ?: [$raw]);

        $index    = max(0, (int)($request->getQueryParams()['index'] ?? 0));
        // Confine to storage/proofs — a stored path is not trusted input.
        $filePath = isset($paths[$index]) ? $this->safeProofPath((string) $paths[$index]) : null;

        if (!$filePath || !file_exists($filePath)) {
            $response->getBody()->write('File not found on server.');
            return $response->withStatus(404);
        }

        $finfo    = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $filePath);
        finfo_close($finfo);

        $stream = new \Slim\Psr7\Stream(fopen($filePath, 'r'));
        return $response->withHeader('Content-Type', $mimeType)
                        ->withHeader('Content-Disposition', 'inline; filename="proof_' . $txn->id . '_' . ($index + 1) . '"')
                        ->withBody($stream);
    }

    // =========================================================================
    // EDIT FORM  —  GET /transactions/{id}/edit
    // =========================================================================

    public function editForm(Request $request, Response $response, array $args): Response
    {
        $id       = (int)$args['id'];
        $userRole = $_SESSION['role'] ?? 'agent';
        $isAdmin  = ($userRole === User::ROLE_ADMIN);

        // CSA cannot edit transactions
        if ($userRole === User::ROLE_CSA) {
            $_SESSION['flash_error'] = 'Access denied.';
            return $response->withHeader('Location', '/transactions/' . $id)->withStatus(302);
        }

        $txn = Transaction::with(['passengers', 'cards'])->find($id);
        if (!$txn) {
            $_SESSION['flash_error'] = 'Transaction not found.';
            return $response->withHeader('Location', '/transactions')->withStatus(302);
        }

        // Scope: agents edit only their own; managers only their team (or own).
        $userId = (int)($_SESSION['user_id'] ?? 0);
        if ($userRole === User::ROLE_AGENT && (int)$txn->agent_id !== $userId) {
            $_SESSION['flash_error'] = 'You can only edit your own transactions.';
            return $response->withHeader('Location', '/transactions/' . $id)->withStatus(302);
        }
        if ($userRole === User::ROLE_MANAGER
            && !in_array((int)$txn->agent_id, $this->getManagerTeamIds($userId), true)
            && (int)$txn->agent_id !== $userId) {
            $_SESSION['flash_error'] = "You can only edit your team's transactions.";
            return $response->withHeader('Location', '/transactions/' . $id)->withStatus(302);
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
        $body = (array) $request->getParsedBody();
        $userRole = $_SESSION['role'] ?? 'agent';
        $isAdmin  = ($userRole === User::ROLE_ADMIN);

        // CSA cannot edit transactions
        if ($userRole === User::ROLE_CSA) {
            $_SESSION['flash_error'] = 'Access denied.';
            return $response->withHeader('Location', '/transactions/' . $id)->withStatus(302);
        }

        // Scope: agents edit only their own; managers only their team (or own).
        if ($userRole === User::ROLE_AGENT || $userRole === User::ROLE_MANAGER) {
            $myId    = (int)($_SESSION['user_id'] ?? 0);
            $ownerId = (int) (Transaction::where('id', $id)->value('agent_id') ?? 0);
            $allowed = ($ownerId === $myId)
                || ($userRole === User::ROLE_MANAGER && in_array($ownerId, $this->getManagerTeamIds($myId), true));
            if (!$allowed) {
                $_SESSION['flash_error'] = $userRole === User::ROLE_MANAGER
                    ? "You can only edit your team's transactions."
                    : 'You can only edit your own transactions.';
                return $response->withHeader('Location', '/transactions/' . $id)->withStatus(302);
            }
        }

        $passengers = json_decode($body['passengers_json'] ?? '[]', true);
        $typeData   = !empty($body['type_specific_data_json'])
            ? json_decode($body['type_specific_data_json'], true)
            : null;

        // Mandatory notes for audit on edit
        if (empty(trim($body['agent_notes'] ?? ''))) {
            $_SESSION['flash_error'] = 'Agent notes are required for auditing. Please describe what you changed.';
            return $response->withHeader('Location', '/transactions/' . $id . '/edit')->withStatus(302);
        }

        // ── Handle Proof of Sale — admin may remove; anyone (per scope) may append ─
        // SECURITY: never trust a client-supplied proof list. The stored value is
        // used to build filesystem paths (serve + delete), so a forged entry like
        // "../.env" would become an arbitrary file read/unlink. Drop whatever the
        // request sent and rebuild the list from the database every time.
        unset($body['proof_of_sale_path']);

        // Load the current stored proof list once (needed for both removal & append).
        $txnOld      = Transaction::find($id);
        $existingRaw = $txnOld->proof_of_sale_path ?? null;
        $existing    = [];
        if ($existingRaw) {
            $dec      = is_array($existingRaw) ? $existingRaw : json_decode((string)$existingRaw, true);
            $existing = is_array($dec) ? $dec : [$existingRaw];
        }
        // Defence in depth: discard any stored entry that isn't a well-formed
        // proof path, so a value poisoned before this fix can't be acted on.
        $existing = array_values(array_filter(
            $existing,
            fn($p) => is_string($p) && preg_match('#^storage/proofs/[A-Za-z0-9_.-]+$#', $p) === 1
        ));
        $proofChanged = false;

        // Admin-only removal. Once uploaded, proof of sale is locked for everyone
        // EXCEPT admins, who may delete documents from any transaction (including
        // historical ones). Gated server-side so a non-admin can't forge the field.
        $removeProofs = $body['remove_proofs'] ?? [];
        if ($isAdmin && !empty($removeProofs)) {
            if (!is_array($removeProofs)) {
                $removeProofs = [$removeProofs];
            }
            $removeSet = array_map('strval', $removeProofs);
            $kept      = [];
            foreach ($existing as $p) {
                if (in_array((string) $p, $removeSet, true)) {
                    // Best-effort physical delete so removed docs don't linger on
                    // disk. Confined to storage/proofs via realpath so a poisoned
                    // path can never unlink anything outside it.
                    $abs = $this->safeProofPath((string) $p);
                    if ($abs !== null && is_file($abs)) {
                        @unlink($abs);
                    }
                } else {
                    $kept[] = $p;
                }
            }
            if (count($kept) !== count($existing)) {
                $existing     = array_values($kept);
                $proofChanged = true;
            }
        }

        // Append newly uploaded proofs (subject to normal edit scope).
        $uploadedFiles = $request->getUploadedFiles();
        $rawProofsEdit = $uploadedFiles['proof_of_sale'] ?? [];
        if (!is_array($rawProofsEdit)) {
            $rawProofsEdit = [$rawProofsEdit];
        }
        $rawProofsEdit = array_filter($rawProofsEdit, fn($f) => $f && $f->getError() === UPLOAD_ERR_OK);

        if (!empty($rawProofsEdit)) {
            $savedNew = $this->saveProofFiles($rawProofsEdit, '/transactions/' . $id . '/edit');
            if (is_string($savedNew)) {
                return $response->withHeader('Location', $savedNew)->withStatus(302);
            }
            $existing     = array_values(array_merge($existing, $savedNew));
            $proofChanged = true;
        }

        if ($proofChanged) {
            $body['proof_of_sale_path'] = json_encode($existing);
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

        // Manager: verify the card belongs to a transaction of their team (or their own)
        if ($userRole === User::ROLE_MANAGER) {
            $card = \App\Models\PaymentCard::find($cardId);
            if ($card) {
                $txn = Transaction::find($card->transaction_id);
                if ($txn) {
                    $teamIds = $this->getManagerTeamIds((int)$adminId);
                    if (!in_array($txn->agent_id, $teamIds) && $txn->agent_id !== (int)$adminId) {
                        return $this->jsonResponse($response, ['error' => 'Access denied.'], 403);
                    }
                }
            }
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

        // AUTHORIZATION — this endpoint returns DECRYPTED card data (PAN, expiry,
        // CVV) so the agent can key the charge into Amadeus/the merchant portal.
        // It previously had no role or ownership check at all, so any logged-in
        // user could walk /transactions/acceptance-data/1..N and harvest every
        // stored card. Scope it, and log every access to cardholder data.
        $acc = \App\Models\AcceptanceRequest::find($acceptanceId);
        if (!$acc) {
            return $this->jsonResponse($response, [
                'success' => false,
                'error'   => 'Acceptance not found or not yet approved by the customer.',
            ], 404);
        }
        if (!$this->canAccessAcceptance($acc)) {
            return $this->jsonResponse($response, [
                'success' => false,
                'error'   => "Access denied — you can only import your own team's acceptances.",
            ], 403);
        }
        $this->logCardDataAccess($acc);

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

        // Managers are read-only — no approval
        if ($userRole === User::ROLE_MANAGER) {
            $_SESSION['flash_error'] = 'Managers cannot approve transactions.';
            return $response->withHeader('Location', '/transactions')->withStatus(302);
        }

        if (!in_array($userRole, [User::ROLE_ADMIN, User::ROLE_SUPERVISOR])) {
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

        if (!in_array($userRole, [User::ROLE_ADMIN])) {
            $_SESSION['flash_error'] = 'Only admins can void transactions.';
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
    // REFUND  —  POST /transactions/{id}/refund  (Admin only)
    // =========================================================================

    public function refund(Request $request, Response $response, array $args): Response
    {
        $userId   = (int)$_SESSION['user_id'];
        $userRole = $_SESSION['role'] ?? 'agent';

        if ($userRole !== User::ROLE_ADMIN) {
            $_SESSION['flash_error'] = 'Only admins can refund transactions.';
            return $response->withHeader('Location', '/transactions')->withStatus(302);
        }

        $txnId  = (int)($args['id'] ?? 0);
        $body   = (array)$request->getParsedBody();
        $isFull = (($body['refund_mode'] ?? 'full') === 'full');
        $amount = (float)($body['refund_amount'] ?? 0);
        $reason = trim($body['refund_reason'] ?? '');

        try {
            $txn = $this->service->refund($txnId, $isFull, $amount, $reason, $userId);
            $_SESSION['flash_success'] = 'Refund recorded. '
                . ($txn->isFullyRefunded() ? 'Transaction fully refunded.' : 'Partial refund applied.');
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

        // Managers are read-only — dispute management is admin-only
        if ($userRole !== User::ROLE_ADMIN) {
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

        // Managers are read-only — gateway management is admin-only
        if ($userRole !== User::ROLE_ADMIN) {
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
        $isAdmin  = ($userRole === User::ROLE_ADMIN);

        if ($userRole === User::ROLE_MANAGER) {
            // Manager sees only their team agents in autofill
            $teamIds = $this->getManagerTeamIds((int)$userId);
            $options = $this->service->getAutofillOptions(null, $teamIds);
        } else {
            $options = $this->service->getAutofillOptions($isAdmin ? null : $userId);
        }

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

        // Note scope must mirror view(): an agent only annotates their own records,
        // a manager only their team's. Previously only ROLE_AGENT was checked, so a
        // manager could attach notes to any transaction in the company, including
        // other centres' — notes are part of the audit trail and surface to admins.
        if ($userRole === User::ROLE_AGENT && $txn->agent_id !== $userId) {
            return $this->jsonResponse($response, ['error' => 'Access denied.'], 403);
        }
        if ($userRole === User::ROLE_MANAGER) {
            $teamIds = array_unique(array_merge($this->getManagerTeamIds($userId), [$userId]));
            if (!in_array((int)$txn->agent_id, array_map('intval', $teamIds), true)) {
                return $this->jsonResponse($response, ['error' => 'Access denied.'], 403);
            }
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
        $userId   = (int)$_SESSION['user_id'];
        $userRole = $_SESSION['role'] ?? 'agent';
        if ($userRole !== User::ROLE_ADMIN) {
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

        // Manager: scope export to their team + own records
        $agentIds = null;
        if ($userRole === User::ROLE_MANAGER) {
            $teamIds  = $this->getManagerTeamIds($userId);
            $agentIds = array_unique(array_merge($teamIds, [(int)$userId]));
        }

        $all   = $this->service->list(1, 99999, $filters, null, $agentIds);
        $items = $all['items'];

        // Profit/MCO is the GROSS figure. Without the refund columns beside it a
        // finance sheet built from this export overstates profit on every refunded
        // booking — the refund loss lives in refund_mco_impact and only Net MCO
        // (profit_mco − refund_mco_impact) reflects reality.
        $headers = [
            'ID', 'Date', 'Type', 'Customer Name', 'Phone', 'Email',
            'PNR', 'Amount', 'Currency', 'Cost', 'Profit/MCO',
            'Refund Status', 'Refunded Amount', 'Refund MCO Impact', 'Net MCO (After Refunds)',
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
            $t->refund_status ?? 'none',
            $t->refunded_amount ?? 0,
            $t->refund_mco_impact ?? 0,
            $t->netMco(),
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

    /**
     * Returns the IDs of agents directly reporting to the given manager.
     * Returns [-1] if the manager has no assigned agents.
     */
    /**
     * Is this search term specific enough to be a real customer lookup?
     *
     * Gate for the cross-agent "universal search" (see index()). The service
     * matches the term with LIKE %term% against customer_name, customer_phone,
     * customer_email and pnr, so a short fragment like "a" or "gmail" would
     * return effectively the whole customer base. A targeted lookup is one of:
     *
     *   - an email fragment with a domain      (contains "@" and 5+ chars)
     *   - a phone number                       (7+ digits, ignoring formatting)
     *   - a PNR / booking reference            (5+ chars, letters AND digits)
     *   - a name                               (5+ chars, and not a bare
     *                                           common-domain word like "gmail")
     *
     * Anything less falls back to the caller's own scope — the search still
     * works, it just doesn't reach beyond their records.
     */
    private static function isTargetedLookup(string $term): bool
    {
        $term = trim($term);
        if ($term === '') {
            return false;
        }

        // Email: needs a local part and something after the "@"
        if (str_contains($term, '@') && strlen($term) >= 5) {
            return true;
        }

        // Phone: 7+ digits once separators are stripped
        $digits = preg_replace('/\D/', '', $term);
        if (strlen($digits) >= 7) {
            return true;
        }

        if (strlen($term) < 5) {
            return false;
        }

        // Block bare high-frequency fragments that would match huge swathes of
        // the customer base while technically clearing the length bar.
        $generic = ['gmail', 'yahoo', 'hotmail', 'outlook', 'email', '.com', 'admin', 'test'];
        if (in_array(strtolower($term), $generic, true)) {
            return false;
        }

        return true;
    }

    private function getManagerTeamIds(int $managerId): array
    {
        $manager = \App\Models\User::find($managerId);
        if (!$manager) return [-1];
        $ids = $manager->getTeamAgentIds(true); // include suspended ex-reports for record access
        return empty($ids) ? [-1] : $ids;
    }

    /**
     * Whether the current user may read an acceptance (and therefore its card
     * data) for import. Admin: any. Manager/supervisor: own team, or their own.
     * Everyone else: only their own records.
     */
    private function canAccessAcceptance(\App\Models\AcceptanceRequest $acc): bool
    {
        $role    = $_SESSION['role'] ?? 'agent';
        $userId  = (int) ($_SESSION['user_id'] ?? 0);
        $ownerId = (int) ($acc->agent_id ?? 0);

        if ($role === User::ROLE_ADMIN) {
            return true;
        }
        if ($ownerId === $userId) {
            return true;
        }
        if ($role === User::ROLE_MANAGER || $role === User::ROLE_SUPERVISOR) {
            return in_array($ownerId, $this->getManagerTeamIds($userId), true);
        }
        return false;
    }

    /**
     * Audit every read of decrypted cardholder data. Records who, what and when
     * — never the PAN itself (last four only).
     */
    private function logCardDataAccess(\App\Models\AcceptanceRequest $acc): void
    {
        try {
            \Illuminate\Database\Capsule\Manager::table('activity_log')->insert([
                'user_id'     => (int) ($_SESSION['user_id'] ?? 0) ?: null,
                'action'      => 'card_data_accessed',
                'entity_type' => 'acceptance_request',
                'entity_id'   => $acc->id,
                'details'     => json_encode([
                    'via'        => 'transaction_import_autofill',
                    'pnr'        => $acc->pnr,
                    'card_last4' => $acc->card_last_four,
                ]),
                'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
                'created_at'  => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            error_log('[TransactionController] card access log failed: ' . $e->getMessage());
        }
    }

    /**
     * Resolve a stored proof path to an absolute path, confined to storage/proofs.
     *
     * Stored paths are treated as untrusted: they are used to build filesystem
     * paths for serving and deleting, so a poisoned entry such as "../.env" must
     * not resolve. Returns null when the path escapes the proofs directory,
     * fails the filename pattern, or does not exist.
     */
    private function safeProofPath(string $storedPath): ?string
    {
        if (preg_match('#^storage/proofs/[A-Za-z0-9_.-]+$#', $storedPath) !== 1) {
            return null;
        }
        $base = realpath(__DIR__ . '/../../storage/proofs');
        $real = realpath(__DIR__ . '/../../' . $storedPath);
        if ($base === false || $real === false) {
            return null;
        }
        return str_starts_with($real, $base . DIRECTORY_SEPARATOR) ? $real : null;
    }

    // =========================================================================
    // PRIVATE — Proof-of-sale multi-file validation & save helper
    // Returns: array of 'storage/proofs/...' paths on success,
    //          string (redirect URL) on first validation failure.
    // =========================================================================

    private function saveProofFiles(array $files, string $redirectOnError): array|string
    {
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'heic', 'heif', 'pdf', 'eml', 'msg'];
        $allowedMimes      = [
            'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp',
            'image/heic', 'image/heif', 'application/pdf',
            'message/rfc822', 'application/vnd.ms-outlook', 'application/octet-stream',
        ];
        $maxBytes  = 15 * 1024 * 1024;
        $uploadDir = __DIR__ . '/../../storage/proofs';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $saved = [];
        foreach ($files as $file) {
            $clientName = $file->getClientFilename();
            $extension  = strtolower(pathinfo($clientName, PATHINFO_EXTENSION));

            if (!in_array($extension, $allowedExtensions, true)) {
                $_SESSION['flash_error'] = 'Invalid file type for "' . $clientName . '". Allowed: JPG, PNG, BMP, HEIC, PDF, EML, MSG.';
                return $redirectOnError;
            }
            if ($file->getSize() > $maxBytes) {
                $mb = round($file->getSize() / 1048576, 1);
                $_SESSION['flash_error'] = '"' . $clientName . '" is too large (' . $mb . ' MB). Max 15 MB per file.';
                return $redirectOnError;
            }

            $filename   = uniqid('proof_') . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
            $targetPath = $uploadDir . '/' . $filename;
            $file->moveTo($targetPath);

            $finfo    = finfo_open(FILEINFO_MIME_TYPE);
            $realMime = finfo_file($finfo, $targetPath);
            finfo_close($finfo);

            if ($extension === 'eml' && $realMime === 'text/plain')                                  $realMime = 'message/rfc822';
            if (in_array($extension, ['heic', 'heif']) && $realMime === 'application/octet-stream') $realMime = 'image/heic';

            if (!in_array($realMime, $allowedMimes, true)) {
                @unlink($targetPath);
                $_SESSION['flash_error'] = '"' . $clientName . '" content does not match its extension. Allowed: JPG, PNG, BMP, HEIC, PDF, EML, MSG.';
                return $redirectOnError;
            }

            $saved[] = 'storage/proofs/' . $filename;
        }
        return $saved;
    }
}
