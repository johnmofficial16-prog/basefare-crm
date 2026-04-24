<?php

namespace App\Controllers;

use App\Models\ETicket;
use App\Models\User;
use App\Models\RecordNote;
use App\Services\ETicketService;
use App\Services\ETicketEmailService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * ETicketController
 *
 * Agent-facing routes (/etickets) — behind AuthMiddleware + AttendanceGateMiddleware
 * Public customer routes (/eticket) — token-based, no auth
 */
class ETicketController
{
    private ETicketService      $service;
    private ETicketEmailService $emailService;

    public function __construct()
    {
        $this->service      = new ETicketService();
        $this->emailService = new ETicketEmailService();
    }

    // =========================================================================
    // LIST
    // =========================================================================

    public function index(Request $request, Response $response): Response
    {
        $role     = $_SESSION['role']    ?? 'agent';
        $userId   = (int)($_SESSION['user_id'] ?? 0);
        $isAdmin  = in_array($role, [User::ROLE_ADMIN, User::ROLE_MANAGER, User::ROLE_SUPERVISOR]);

        $params  = $request->getQueryParams();
        $page    = max(1, (int)($params['page'] ?? 1));
        $filters = [
            'status'    => $params['status']    ?? '',
            'pnr'       => $params['pnr']       ?? '',
            'search'    => $params['search']     ?? '',
            'date_from' => $params['date_from']  ?? '',
            'date_to'   => $params['date_to']    ?? '',
        ];

        // Supervisors scoped to team — simplified: use agentId for non-admins
        $agentId = $isAdmin ? null : $userId;

        $result = $this->service->list($page, 25, $filters, $agentId);

        $view = $result + ['filters' => $filters, 'role' => $role, 'isAdmin' => $isAdmin];
        return $this->render($response, 'eticket/list.php', $view);
    }

    // =========================================================================
    // CREATE FORM
    // =========================================================================

    public function createForm(Request $request, Response $response): Response
    {
        $role    = $_SESSION['role']    ?? 'agent';
        $userId  = (int)($_SESSION['user_id'] ?? 0);
        $isAdmin = in_array($role, [User::ROLE_ADMIN, User::ROLE_MANAGER]);

        $agentId = $isAdmin ? null : $userId;
        $options = $this->service->getAutofillOptions($agentId);

        return $this->render($response, 'eticket/create.php', [
            'autofill_options' => $options,
            'prefill'          => null,
            'role'             => $role,
            'defaultPolicy'    => ETicketService::DEFAULT_POLICY,
        ]);
    }

    // =========================================================================
    // STORE (POST /etickets/create)
    // =========================================================================

    public function store(Request $request, Response $response): Response
    {
        $userId  = (int)($_SESSION['user_id'] ?? 0);
        $body    = $request->getParsedBody() ?? [];

        // CSRF
        if (empty($body['csrf_token']) || $body['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
            return $this->jsonError($response, 'CSRF validation failed.', 403);
        }

        // Parse ticket_data from form POST (array of rows)
        $ticketData = [];
        $paxNames   = $body['pax_name']      ?? [];
        $paxTypes   = $body['pax_type']      ?? [];
        $paxDobs    = $body['pax_dob']       ?? [];
        $ticketNos  = $body['ticket_number'] ?? [];
        $seats      = $body['seat']          ?? [];

        foreach ($paxNames as $i => $paxName) {
            if (empty(trim($paxName))) continue;
            $ticketData[] = [
                'pax_name'      => trim($paxName),
                'pax_type'      => trim($paxTypes[$i] ?? 'adult'),
                'dob'           => trim($paxDobs[$i]  ?? ''),
                'ticket_number' => trim($ticketNos[$i] ?? ''),
                'seat'          => trim($seats[$i]    ?? ''),
            ];
        }

        // Parse flight_data from hidden JSON field
        $flightData    = null;
        $fareBreakdown = [];
        $extraData     = null;

        if (!empty($body['flight_data_json'])) {
            $flightData = json_decode($body['flight_data_json'], true) ?: null;
        }
        if (!empty($body['fare_breakdown_json'])) {
            $fareBreakdown = json_decode($body['fare_breakdown_json'], true) ?: [];
        }
        if (!empty($body['extra_data_json'])) {
            $extraData = json_decode($body['extra_data_json'], true) ?: null;
        }

        $data = [
            'transaction_id' => (int)($body['transaction_id'] ?? 0),
            'customer_name'  => $body['customer_name']  ?? '',
            'customer_email' => $body['customer_email'] ?? '',
            'customer_phone' => $body['customer_phone'] ?? '',
            'pnr'            => $body['pnr']            ?? '',
            'airline'        => $body['airline']        ?? '',
            'order_id'       => $body['order_id']       ?? '',
            'total_amount'   => $body['total_amount']   ?? 0,
            'currency'       => $body['currency']       ?? 'USD',
            'ticket_data'    => $ticketData,
            'flight_data'    => $flightData,
            'fare_breakdown' => $fareBreakdown,
            'extra_data'     => $extraData,
            'endorsements'   => $body['endorsements']   ?? '',
            'baggage_info'   => $body['baggage_info']   ?? '',
            'fare_rules'     => $body['fare_rules']     ?? '',
            'policy_text'    => $body['policy_text']    ?? ETicketService::DEFAULT_POLICY,
            'agent_notes'    => $body['agent_notes']    ?? '',
        ];

        try {
            $eticket = $this->service->create($data, $userId);
        } catch (\RuntimeException $e) {
            // Redirect back with error
            return $response->withHeader('Location', '/etickets/create?error=' . urlencode($e->getMessage()))->withStatus(302);
        }

        // Auto-send email if requested
        if (!empty($body['send_now'])) {
            $result = $this->emailService->send($eticket);
            if ($result['success']) {
                $this->service->markSent($eticket, $eticket->customer_email);
            } else {
                $this->service->markEmailFailed($eticket);
            }
        }

        return $response->withHeader('Location', '/etickets/' . $eticket->id . '?created=1')->withStatus(302);
    }

    // =========================================================================
    // VIEW
    // =========================================================================

    public function view(Request $request, Response $response, array $args): Response
    {
        $eticket = ETicket::with(['agent', 'transaction'])->findOrFail((int)$args['id']);
        $notes   = RecordNote::where('entity_type', 'eticket')
            ->where('entity_id', $eticket->id)
            ->orderBy('created_at', 'asc')
            ->get();

        return $this->render($response, 'eticket/view.php', [
            'eticket' => $eticket,
            'notes'   => $notes,
            'created' => (bool)($_GET['created'] ?? false),
            'sent'    => (bool)($_GET['sent']    ?? false),
        ]);
    }

    // =========================================================================
    // SEND EMAIL (POST /etickets/{id}/send)
    // =========================================================================

    public function sendEmail(Request $request, Response $response, array $args): Response
    {
        $eticket = ETicket::findOrFail((int)$args['id']);
        $body    = $request->getParsedBody() ?? [];

        if (empty($body['csrf_token']) || $body['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
            return $this->jsonError($response, 'CSRF validation failed.', 403);
        }

        // Optional override email for resend
        $overrideEmail = !empty($body['resend_email']) ? trim($body['resend_email']) : null;
        $sendTo        = $overrideEmail ?? $eticket->customer_email;

        $result = $this->emailService->send($eticket, $overrideEmail);

        if ($result['success']) {
            $this->service->markSent($eticket, $sendTo);
            RecordNote::log('eticket', $eticket->id, (int)($_SESSION['user_id'] ?? 0),
                'E-Ticket emailed to ' . $sendTo, 'sent');
            return $response->withHeader('Location', '/etickets/' . $eticket->id . '?sent=1')->withStatus(302);
        }

        return $response->withHeader('Location', '/etickets/' . $eticket->id . '?send_error=1')->withStatus(302);
    }

    // =========================================================================
    // ADD NOTE (POST /etickets/{id}/note)
    // =========================================================================

    public function addNote(Request $request, Response $response, array $args): Response
    {
        $eticket = ETicket::findOrFail((int)$args['id']);
        $body    = $request->getParsedBody() ?? [];

        if (empty($body['csrf_token']) || $body['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
            return $this->jsonError($response, 'CSRF validation failed.', 403);
        }

        $note = trim($body['note'] ?? '');
        if (!$note) {
            return $response->withHeader('Location', '/etickets/' . $eticket->id)->withStatus(302);
        }

        RecordNote::log('eticket', $eticket->id, (int)($_SESSION['user_id'] ?? 0), $note, 'note');

        return $response->withHeader('Location', '/etickets/' . $eticket->id)->withStatus(302);
    }

    // =========================================================================
    // AJAX — AUTOFILL OPTIONS (GET /etickets/autofill-options)
    // =========================================================================

    public function autofillOptions(Request $request, Response $response): Response
    {
        $role    = $_SESSION['role']    ?? 'agent';
        $userId  = (int)($_SESSION['user_id'] ?? 0);
        $isAdmin = in_array($role, [User::ROLE_ADMIN, User::ROLE_MANAGER]);
        $agentId = $isAdmin ? null : $userId;

        $options = $this->service->getAutofillOptions($agentId);

        $response->getBody()->write(json_encode(['success' => true, 'options' => $options]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    // =========================================================================
    // AJAX — TRANSACTION DATA (GET /etickets/transaction-data/{id})
    // =========================================================================

    public function transactionData(Request $request, Response $response, array $args): Response
    {
        $txnId = (int)$args['id'];

        try {
            $data = $this->service->getTransactionAutofill($txnId);
            if (!$data) {
                return $this->jsonError($response, 'Transaction not found.', 404);
            }
        } catch (\RuntimeException $e) {
            $msg = match($e->getMessage()) {
                'not_eligible'  => 'This transaction has not been approved and charged yet.',
                'already_issued'=> 'An e-ticket has already been issued for this transaction.',
                default         => $e->getMessage(),
            };
            return $this->jsonError($response, $msg);
        }

        $response->getBody()->write(json_encode(['success' => true, 'data' => $data]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    // =========================================================================
    // PUBLIC — VIEW (GET /eticket?token=xxx)
    // =========================================================================

    public function publicView(Request $request, Response $response): Response
    {
        $token   = $request->getQueryParams()['token'] ?? '';
        $eticket = $this->service->findByToken($token);

        if (!$eticket) {
            return $this->renderPublic($response, 'eticket/public_invalid.php', []);
        }

        return $this->renderPublic($response, 'eticket/public_eticket.php', ['eticket' => $eticket]);
    }

    // =========================================================================
    // PUBLIC — ACKNOWLEDGE (POST /eticket/acknowledge)
    // =========================================================================

    public function publicAcknowledge(Request $request, Response $response): Response
    {
        $body  = $request->getParsedBody() ?? [];
        $token = $body['token'] ?? '';

        $eticket = $this->service->findByToken($token);

        if (!$eticket) {
            return $response->withHeader('Location', '/eticket/confirmed?status=invalid')->withStatus(302);
        }

        if (!$eticket->isAcknowledged()) {
            $forensic = $this->service->collectForensicData();
            $this->service->processAcknowledgment($eticket, $forensic);

            // Send acknowledgment notice to firm
            $this->emailService->sendAcknowledgmentNotice($eticket->fresh());
        }

        return $response->withHeader('Location', '/eticket/confirmed?token=' . urlencode($token))->withStatus(302);
    }

    // =========================================================================
    // PUBLIC — CONFIRMED (GET /eticket/confirmed)
    // =========================================================================

    public function publicConfirmed(Request $request, Response $response): Response
    {
        $token   = $request->getQueryParams()['token'] ?? '';
        $status  = $request->getQueryParams()['status'] ?? '';
        $eticket = $token ? $this->service->findByToken($token) : null;

        return $this->renderPublic($response, 'eticket/public_confirmed.php', [
            'eticket' => $eticket,
            'status'  => $status,
        ]);
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

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

    private function renderPublic(Response $response, string $view, array $data = []): Response
    {
        // Ensure CSRF token exists for public pages that may have forms
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $this->render($response, $view, $data);
    }

    private function jsonError(Response $response, string $message, int $status = 422): Response
    {
        $response->getBody()->write(json_encode(['success' => false, 'error' => $message]));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
