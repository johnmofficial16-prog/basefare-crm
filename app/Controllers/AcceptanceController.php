<?php

namespace App\Controllers;

use App\Models\AcceptanceRequest;
use App\Models\User;
use App\Services\AcceptanceService;
use App\Services\AcceptanceEmailService;
use Carbon\Carbon;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;

/**
 * AcceptanceController
 *
 * Handles all HTTP endpoints for the Acceptance Module.
 * Contains NO business logic — delegates to AcceptanceService.
 *
 * Agent routes:   /acceptance/*         (behind Auth + AttendanceGate)
 * Public route:   GET/POST /auth        (no auth — token-based)
 */
class AcceptanceController
{
    private AcceptanceService $service;
    private AcceptanceEmailService $emailService;

    public function __construct()
    {
        $this->service      = new AcceptanceService();
        $this->emailService = new AcceptanceEmailService();
    }

    // =========================================================================
    // LIST  —  GET /acceptance
    // =========================================================================

    public function index(Request $request, Response $response): Response
    {
        $userId   = $_SESSION['user_id'];
        $userRole = $_SESSION['role'] ?? 'agent';
        $params   = $request->getQueryParams();

        $page    = max(1, (int)($params['page'] ?? 1));
        $filters = [
            'status'    => $params['status'] ?? '',
            'type'      => $params['type'] ?? '',
            'pnr'       => $params['pnr'] ?? '',
            'email'     => $params['email'] ?? '',
            'date_from' => $params['date_from'] ?? '',
            'date_to'   => $params['date_to'] ?? '',
        ];

        // Agents see only their own; admins/managers see all
        $agentFilter = in_array($userRole, [User::ROLE_ADMIN, User::ROLE_MANAGER])
            ? null
            : $userId;

        $data = $this->service->list($page, 25, $filters, $agentFilter);

        ob_start();
        require __DIR__ . '/../Views/acceptance/list.php';
        $html = ob_get_clean();
        $response->getBody()->write($html);
        return $response;
    }

    // =========================================================================
    // CREATE FORM  —  GET /acceptance/create
    // =========================================================================

    public function createForm(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();

        // Pre-fill support: if opened from Transaction Recorder
        $maxId = AcceptanceRequest::max('id') ?? 0;
        $nextId = $maxId + 1;
        $autoOrderId = 'BF-REC-' . str_pad($nextId, 5, '0', STR_PAD_LEFT);

        $prefill = [
            'transaction_id' => $params['transaction_id'] ?? null,
            'pnr'            => $params['pnr'] ?? '',
            'customer_name'  => $params['customer_name'] ?? '',
            'customer_email' => $params['customer_email'] ?? '',
            'type'           => $params['type'] ?? '',
            'order_id'       => $params['order_id'] ?? $autoOrderId,
        ];

        // Pre-auth promotion: if ?from_preauth=ID, load the pre-auth record to pre-fill
        $fromPreauthId = (int)($params['from_preauth'] ?? 0);
        $preauthRecord = null;
        if ($fromPreauthId > 0) {
            $preauthRecord = AcceptanceRequest::find($fromPreauthId);
            // Only allow promoting approved pre-auths
            if ($preauthRecord && $preauthRecord->is_preauth && $preauthRecord->isApproved()) {
                $prefill = [
                    'transaction_id' => $preauthRecord->transaction_id,
                    'pnr'            => $preauthRecord->pnr,
                    'customer_name'  => $preauthRecord->customer_name,
                    'customer_email' => $preauthRecord->customer_email,
                    'type'           => $preauthRecord->type,
                    'customer_phone' => $preauthRecord->customer_phone,
                    'total_amount'   => $preauthRecord->total_amount,
                    'currency'       => $preauthRecord->currency,
                    'passengers'     => $preauthRecord->passengers,
                    'flight_data'    => $preauthRecord->flight_data,
                    'agent_notes'    => $preauthRecord->agent_notes,
                ];
            } else {
                $preauthRecord = null; // Invalid — ignore
            }
        }

        // Flash messages
        $flashError = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash_error']);

        ob_start();
        require __DIR__ . '/../Views/acceptance/create.php';
        $html = ob_get_clean();
        $response->getBody()->write($html);
        return $response;
    }

    // =========================================================================
    // STORE  —  POST /acceptance/create
    // =========================================================================

    public function store(Request $request, Response $response): Response
    {
        $agentId = $_SESSION['user_id'];
        $body    = $request->getParsedBody();

        // ── Validate required fields ───────────────────────────────────────
        $required = ['type', 'customer_name', 'customer_email', 'total_amount'];
        if (($body['is_preauth'] ?? '0') !== '1') {
            $required[] = 'pnr';
        }
        
        foreach ($required as $field) {
            if (empty($body[$field])) {
                $_SESSION['flash_error'] = "Missing required field: {$field}";
                return $response->withHeader('Location', '/acceptance/create')->withStatus(302);
            }
        }

        // ── Validate email ─────────────────────────────────────────────────
        if (!filter_var($body['customer_email'], FILTER_VALIDATE_EMAIL)) {
            $_SESSION['flash_error'] = 'Invalid customer email address.';
            return $response->withHeader('Location', '/acceptance/create')->withStatus(302);
        }

        // ── Validate agent notes (mandatory) ───────────────────────────────
        if (empty(trim($body['agent_notes'] ?? ''))) {
            $_SESSION['flash_error'] = 'Agent notes are required. Please describe what you are authorizing.';
            return $response->withHeader('Location', '/acceptance/create')->withStatus(302);
        }

        // ── Parse passengers JSON ─────────────────────────────────────────
        $passengers = json_decode($body['passengers_json'] ?? '[]', true);
        if (empty($passengers)) {
            $_SESSION['flash_error'] = 'At least one passenger is required.';
            return $response->withHeader('Location', '/acceptance/create')->withStatus(302);
        }

        // ── Parse fare breakdown JSON ─────────────────────────────────────
        $fareBreakdown = json_decode($body['fare_breakdown_json'] ?? '[]', true) ?: [];

        // ── Parse flight data JSON ────────────────────────────────────────
        $flightData = null;
        if (!empty($body['flight_data_json'])) {
            $flightData = json_decode($body['flight_data_json'], true);
        }

        // ── Parse extra data JSON ─────────────────────────────────────────
        $extraData = null;
        if (!empty($body['extra_data_json'])) {
            $extraData = json_decode($body['extra_data_json'], true);
        }

        // ── Parse additional cards ────────────────────────────────────────
        $additionalCards = null;
        if (!empty($body['additional_cards_json'])) {
            $additionalCards = json_decode($body['additional_cards_json'], true);
        }

        // ── Build data array for service ──────────────────────────────────
        $data = array_merge($body, [
            'passengers'      => $passengers,
            'flight_data'     => $flightData,
            'fare_breakdown'  => $fareBreakdown,
            'extra_data'      => $extraData,
            'additional_cards'=> $additionalCards,
        ]);

        // ── Create record ─────────────────────────────────────────────────
        $acceptance = $this->service->create($data, $agentId);

        // ── If this was a pre-auth promotion, mark original as PROMOTED ───
        // The hidden field preauth_id was submitted and is_preauth is 0 (full acceptance)
        $promotedFromId = (int)($body['preauth_id'] ?? 0);
        if ($promotedFromId > 0 && !$acceptance->is_preauth) {
            $originalPreauth = AcceptanceRequest::find($promotedFromId);
            if ($originalPreauth && $originalPreauth->is_preauth) {
                $originalPreauth->update([
                    'status' => AcceptanceRequest::STATUS_PROMOTED,
                ]);
            }
        }

        // ── Send email (stub for now) ─────────────────────────────────────
        $emailResult = $this->emailService->send($acceptance);

        $label = $acceptance->is_preauth ? 'Pre-Authorization request' : 'Acceptance request';
        $_SESSION['flash_success'] = $label . ' created. ' . ($emailResult['note'] ?? 'Email sent.');
        $_SESSION['acceptance_link'] = $acceptance->publicUrl();

        return $response->withHeader('Location', '/acceptance/' . $acceptance->id)->withStatus(302);
    }

    // =========================================================================
    // VIEW  —  GET /acceptance/{id}
    // =========================================================================

    public function view(Request $request, Response $response, array $args): Response
    {
        $id         = (int)($args['id'] ?? 0);
        $userId     = $_SESSION['user_id'];
        $userRole   = $_SESSION['role'] ?? 'agent';

        $acceptance = AcceptanceRequest::with(['agent', 'notes.user'])->find($id);

        if (!$acceptance) {
            $_SESSION['flash_error'] = 'Acceptance request not found.';
            return $response->withHeader('Location', '/acceptance')->withStatus(302);
        }

        // Agents can only view their own records
        if ($userRole === User::ROLE_AGENT && $acceptance->agent_id !== $userId) {
            $_SESSION['flash_error'] = 'Access denied.';
            return $response->withHeader('Location', '/acceptance')->withStatus(302);
        }

        // Mark expired if needed
        if ($acceptance->isPending() && Carbon::now()->gt($acceptance->expires_at)) {
            $acceptance->update(['status' => AcceptanceRequest::STATUS_EXPIRED]);
            $acceptance->refresh();
        }

        $flashSuccess = $_SESSION['flash_success'] ?? null;
        $acceptanceLink = $_SESSION['acceptance_link'] ?? null;
        unset($_SESSION['flash_success'], $_SESSION['acceptance_link']);

        ob_start();
        require __DIR__ . '/../Views/acceptance/view.php';
        $html = ob_get_clean();
        $response->getBody()->write($html);
        return $response;
    }

    // =========================================================================
    // ADD NOTE  —  POST /acceptance/{id}/note  (AJAX)
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

        $acc = AcceptanceRequest::find($id);
        if (!$acc) {
            return $this->jsonResponse($response, ['error' => 'Acceptance not found.'], 404);
        }

        // Agents can only add notes to their own records
        $userRole = $_SESSION['role'] ?? 'agent';
        if ($userRole === \App\Models\User::ROLE_AGENT && $acc->agent_id !== $userId) {
            return $this->jsonResponse($response, ['error' => 'Access denied.'], 403);
        }

        $rn = \App\Models\RecordNote::log('acceptance', $id, $userId, $note, $action);
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
    // REVEAL CC  —  POST /acceptance/{id}/reveal-cc  (AJAX, admin/manager only)
    // =========================================================================

    public function revealCC(Request $request, Response $response, array $args): Response
    {
        $role = $_SESSION['role'] ?? '';
        if (!in_array($role, [User::ROLE_ADMIN, User::ROLE_MANAGER])) {
            return $this->jsonResponse($response, ['error' => 'Access denied.'], 403);
        }

        $id         = (int)($args['id'] ?? 0);
        $acceptance = AcceptanceRequest::find($id);

        if (!$acceptance) {
            return $this->jsonResponse($response, ['error' => 'Not found.'], 404);
        }

        if (empty($acceptance->card_number_enc)) {
            return $this->jsonResponse($response, ['error' => 'No full card details stored for this record.'], 422);
        }

        try {
            $enc        = new \App\Services\EncryptionService();
            $cardNumber = $enc->decrypt($acceptance->card_number_enc);
            $cardExpiry = !empty($acceptance->card_expiry_enc) ? $enc->decrypt($acceptance->card_expiry_enc) : null;
            $cardCvv    = !empty($acceptance->card_cvv_enc)    ? $enc->decrypt($acceptance->card_cvv_enc)    : null;
        } catch (\Throwable $e) {
            return $this->jsonResponse($response, ['error' => 'Decryption failed.'], 500);
        }

        return $this->jsonResponse($response, [
            'card_number' => $cardNumber,
            'card_expiry' => $cardExpiry,
            'card_cvv'    => $cardCvv,
        ]);
    }

    // =========================================================================
    // RECEIPT  —  GET /acceptance/{id}/receipt
    // =========================================================================

    public function receipt(Request $request, Response $response, array $args): Response
    {
        $id         = (int)($args['id'] ?? 0);
        $userId     = $_SESSION['user_id'];
        $userRole   = $_SESSION['role'] ?? 'agent';

        $acceptance = AcceptanceRequest::find($id);

        if (!$acceptance || !$acceptance->isApproved()) {
            $response->getBody()->write('<p style="padding:2rem;font-family:sans-serif;">Receipt not available — request has not been approved.</p>');
            return $response->withStatus(404);
        }

        // Agents can only access their own receipts
        if ($userRole === User::ROLE_AGENT && $acceptance->agent_id !== $userId) {
            $response->getBody()->write('<p style="padding:2rem;font-family:sans-serif;">Access denied.</p>');
            return $response->withStatus(403);
        }

        ob_start();
        require __DIR__ . '/../Views/acceptance/receipt.php';
        $html = ob_get_clean();
        $response->getBody()->write($html);
        return $response;
    }

    // =========================================================================
    // RESEND  —  POST /acceptance/{id}/resend
    // =========================================================================

    public function resend(Request $request, Response $response, array $args): Response
    {
        $id         = (int)($args['id'] ?? 0);
        $acceptance = AcceptanceRequest::find($id);

        if (!$acceptance) {
            return $this->jsonResponse($response, ['success' => false, 'error' => 'Not found.'], 404);
        }

        if ($acceptance->isApproved()) {
            return $this->jsonResponse($response, ['success' => false, 'error' => 'Already approved — no need to resend.'], 422);
        }

        if ($acceptance->isCancelled()) {
            return $this->jsonResponse($response, ['success' => false, 'error' => 'Request is cancelled.'], 422);
        }

        // Reset expiry to 12 hours from now
        $this->service->resetExpiry($acceptance);
        $acceptance->refresh();

        // Resend email
        $result = $this->emailService->send($acceptance);

        // Update email status
        $acceptance->update(['email_status' => AcceptanceRequest::EMAIL_RESENT]);

        return $this->jsonResponse($response, [
            'success'    => true,
            'link'       => $acceptance->publicUrl(),
            'expires_at' => $acceptance->expires_at->format('M j, Y g:i A'),
            'note'       => $result['note'] ?? null,
        ]);
    }

    // =========================================================================
    // CANCEL  —  POST /acceptance/{id}/cancel
    // =========================================================================

    public function cancel(Request $request, Response $response, array $args): Response
    {
        $id         = (int)($args['id'] ?? 0);
        $agentId    = $_SESSION['user_id'];
        $acceptance = AcceptanceRequest::find($id);

        if (!$acceptance) {
            return $this->jsonResponse($response, ['success' => false, 'error' => 'Not found.'], 404);
        }

        $success = $this->service->cancel($acceptance, $agentId);

        return $this->jsonResponse($response, [
            'success' => $success,
            'error'   => $success ? null : 'Cannot cancel — request is not in PENDING status.',
        ], $success ? 200 : 422);
    }

    // =========================================================================
    // PUBLIC VIEW  —  GET /auth
    // Customer-facing: no CRM auth, token-based access
    // =========================================================================

    public function publicView(Request $request, Response $response): Response
    {
        $token = trim($request->getQueryParams()['token'] ?? '');

        if (empty($token)) {
            ob_start();
            require __DIR__ . '/../Views/acceptance/public_invalid.php';
            $html = ob_get_clean();
            $response->getBody()->write($html);
            return $response->withStatus(400);
        }

        $acceptance = $this->service->findValidByToken($token);

        if (!$acceptance) {
            ob_start();
            require __DIR__ . '/../Views/acceptance/public_invalid.php';
            $html = ob_get_clean();
            $response->getBody()->write($html);
            return $response->withStatus(404);
        }

        // Record first-view timestamp
        $this->service->recordViewed($acceptance);

        ob_start();
        require __DIR__ . '/../Views/acceptance/public_auth.php';
        $html = ob_get_clean();
        $response->getBody()->write($html);
        return $response;
    }

    // =========================================================================
    // PUBLIC SUBMIT  —  POST /auth
    // Customer submits the signed acceptance form
    // =========================================================================

    public function publicSubmit(Request $request, Response $response): Response
    {
        $body  = $request->getParsedBody();
        $files = $request->getUploadedFiles();
        $token = trim($body['token'] ?? '');

        if (empty($token)) {
            return $response->withHeader('Location', '/auth?error=invalid_token')->withStatus(302);
        }

        $acceptance = $this->service->findValidByToken($token);

        if (!$acceptance || !$acceptance->isActionable()) {
            return $response->withHeader('Location', '/auth?token=' . urlencode($token) . '&error=expired')->withStatus(302);
        }

        // ── Validate all checkboxes are checked ───────────────────────────
        $requiredChecks = ['confirm_details', 'confirm_charge', 'confirm_nonrefundable', 'confirm_chargeback'];
        foreach ($requiredChecks as $check) {
            if (empty($body[$check])) {
                return $response->withHeader('Location', '/auth?token=' . urlencode($token) . '&error=incomplete')->withStatus(302);
            }
        }

        // ── Validate signature ────────────────────────────────────────────
        $signatureData = $body['signature_data'] ?? '';
        $isConsentSig  = str_starts_with($signatureData, 'consent:');
        $isCanvasSig   = str_starts_with($signatureData, 'data:image/png;base64,');
        if (empty($signatureData) || (!$isConsentSig && !$isCanvasSig)) {
            return $response->withHeader('Location', '/auth?token=' . urlencode($token) . '&error=no_signature')->withStatus(302);
        }

        // ── Save signature ────────────────────────────────────────────────
        $signaturePath = $this->service->saveSignature($token, $signatureData);

        // ── Save uploaded documents (if uploaded) ─────────────────────────
        $passportPath = null;
        $cardPath     = null;

        if (!empty($files['passport_file']) && $files['passport_file']->getError() === UPLOAD_ERR_OK) {
            $tmpFile = [
                'error'    => $files['passport_file']->getError(),
                'tmp_name' => $files['passport_file']->getStream()->getMetadata('uri'),
                'size'     => $files['passport_file']->getSize(),
                'name'     => $files['passport_file']->getClientFilename(),
            ];
            $passportPath = $this->service->saveEvidenceFile($token, $tmpFile, 'passport');
        }

        if (!empty($files['card_file']) && $files['card_file']->getError() === UPLOAD_ERR_OK) {
            $tmpFile = [
                'error'    => $files['card_file']->getError(),
                'tmp_name' => $files['card_file']->getStream()->getMetadata('uri'),
                'size'     => $files['card_file']->getSize(),
                'name'     => $files['card_file']->getClientFilename(),
            ];
            $cardPath = $this->service->saveEvidenceFile($token, $tmpFile, 'card');
        }

        // ── Collect forensic data ─────────────────────────────────────────
        $forensic = $this->service->collectForensicData($body['device_fingerprint'] ?? '');

        // ── Process approval ──────────────────────────────────────────────
        $success = $this->service->processApproval(
            $acceptance,
            $forensic,
            $signaturePath,
            $passportPath,
            $cardPath
        );

        if (!$success) {
            return $response->withHeader('Location', '/auth?token=' . urlencode($token) . '&error=failed')->withStatus(302);
        }

        // Redirect to thank-you/confirmation page
        return $response->withHeader('Location', '/auth/confirmed?token=' . urlencode($token))->withStatus(302);
    }

    // =========================================================================
    // PUBLIC CONFIRMED  —  GET /auth/confirmed
    // =========================================================================

    public function publicConfirmed(Request $request, Response $response): Response
    {
        $token      = trim($request->getQueryParams()['token'] ?? '');
        $acceptance = $token ? AcceptanceRequest::where('token', $token)->first() : null;

        ob_start();
        require __DIR__ . '/../Views/acceptance/public_confirmed.php';
        $html = ob_get_clean();
        $response->getBody()->write($html);
        return $response;
    }

    // =========================================================================
    // DOWNLOAD EVIDENCE  —  GET /acceptance/{id}/download/{type}
    // Serves uploaded evidence files (passport, cc_front, signature) as downloads
    // =========================================================================

    public function downloadEvidence(Request $request, Response $response, array $args): Response
    {
        $id   = (int)($args['id'] ?? 0);
        $type = $args['type'] ?? '';
        $userId   = $_SESSION['user_id'];
        $userRole = $_SESSION['role'] ?? 'agent';

        $acceptance = AcceptanceRequest::find($id);

        if (!$acceptance) {
            $response->getBody()->write('Not found.');
            return $response->withStatus(404);
        }

        // Agents can only download their own records
        if ($userRole === User::ROLE_AGENT && $acceptance->agent_id !== $userId) {
            $response->getBody()->write('Access denied.');
            return $response->withStatus(403);
        }

        // Map type to filename
        $filename = match ($type) {
            'passport' => $acceptance->passport_image,
            'cc_front' => $acceptance->card_image_front,
            'signature' => $acceptance->digital_signature,
            default => null,
        };

        if (!$filename) {
            $response->getBody()->write('File not available.');
            return $response->withStatus(404);
        }

        // Determine file path
        $subdir = ($type === 'signature') ? 'signatures' : 'evidence';
        $path   = __DIR__ . '/../../storage/acceptance/' . $subdir . '/' . $filename;

        if (!file_exists($path)) {
            $response->getBody()->write('File not found on server.');
            return $response->withStatus(404);
        }

        // Detect MIME type — use finfo if available, else fall back to magic bytes
        if (\class_exists('finfo')) {
            $fi   = new \finfo(FILEINFO_MIME_TYPE);
            $mime = $fi->file($path) ?: 'application/octet-stream';
        } elseif (\function_exists('mime_content_type')) {
            $mime = \mime_content_type($path) ?: 'application/octet-stream';
        } else {
            $handle = \fopen($path, 'rb');
            $magic  = \fread($handle, 8);
            \fclose($handle);
            if (\str_starts_with($magic, "\xFF\xD8\xFF"))           { $mime = 'image/jpeg'; }
            elseif (\str_starts_with($magic, "\x89PNG\r\n\x1A\n")) { $mime = 'image/png';  }
            elseif (\str_starts_with($magic, 'GIF8'))               { $mime = 'image/gif';  }
            elseif (\str_starts_with($magic, '%PDF'))               { $mime = 'application/pdf'; }
            else                                                     { $mime = 'application/octet-stream'; }
        }

        $response->getBody()->write(file_get_contents($path));
        return $response
            ->withHeader('Content-Type', $mime)
            ->withHeader('Content-Disposition', 'attachment; filename="' . basename($filename) . '"')
            ->withHeader('Content-Length', (string)filesize($path));
    }

    // =========================================================================

    private function jsonResponse(Response $response, mixed $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }

    // =========================================================================
    // CSV EXPORT  —  GET /acceptance/export  (admin/manager only)
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
            'status'    => $params['status'] ?? '',
            'type'      => $params['type'] ?? '',
            'pnr'       => $params['pnr'] ?? '',
            'email'     => $params['email'] ?? '',
            'date_from' => $params['date_from'] ?? '',
            'date_to'   => $params['date_to'] ?? '',
        ];

        $all     = $this->service->list(1, 99999, $filters, null);
        $records = $all['records'];

        $headers = [
            'ID', 'Date', 'Type', 'Customer Name', 'Email', 'Phone',
            'PNR', 'Amount', 'Currency', 'Is Pre-Auth',
            'Status', 'Email Status', 'Agent', 'Approved At',
        ];

        $rows = $records->map(fn($r) => [
            $r->id,
            $r->created_at ? $r->created_at->format('Y-m-d H:i:s') : '',
            $r->type,
            $r->customer_name,
            $r->customer_email,
            $r->customer_phone ?? '',
            $r->pnr,
            $r->total_amount,
            $r->currency,
            $r->is_preauth ? 'Yes' : 'No',
            $r->status,
            $r->email_status,
            $r->agent?->name ?? '—',
            $r->approved_at ?? '',
        ]);

        $filename = 'acceptances_' . date('Y-m-d') . '.csv';
        return $this->csvResponse($response, $headers, $rows, $filename);
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

        $response->getBody()->write("\xEF\xBB\xBF" . $csv);
        return $response
            ->withHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Cache-Control', 'no-cache, no-store')
            ->withHeader('Pragma', 'no-cache');
    }
}
