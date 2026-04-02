<?php

namespace App\Services;

use App\Models\AcceptanceRequest;
use Carbon\Carbon;


/**
 * AcceptanceService
 *
 * Core business logic for the Acceptance Module.
 * Handles: token generation, expiry management (12h),
 *          forensic data capture, status transitions, record management.
 *
 * Contains NO HTTP logic — all request/response handling is in AcceptanceController.
 */
class AcceptanceService
{
    // Token expiry: 12 hours
    const EXPIRY_HOURS = 12;

    // Default policy text shown to customers (editable per request)
    const DEFAULT_POLICY = "By digitally signing this authorization form, you confirm that:\n\n"
        . "1. FINAL SALE: All airline tickets purchased are 100% NON-REFUNDABLE and NON-TRANSFERABLE. "
        . "Making a purchase implies acceptance of the airline's fare rules.\n\n"
        . "2. CHARGEBACK WAIVER: You explicitly waive your right to file a credit card dispute or "
        . "chargeback for this transaction. You confirm that Lets Fly Travel DBA Base Fare has fulfilled its "
        . "obligation by processing your request as described above.\n\n"
        . "3. FRAUDULENT DISPUTES: You understand that filing a dispute after receiving services "
        . "constitutes Friendly Fraud. Lets Fly Travel DBA Base Fare will submit this signed authorization, "
        . "IP address, and device fingerprint as conclusive evidence to your bank to contest any such claim.\n\n"
        . "4. TRAVEL DOCUMENTS: Lets Fly Travel DBA Base Fare is not responsible for Visa, Passport, or Health "
        . "documentation requirements. Denied boarding due to missing documents does not constitute "
        . "grounds for a refund or dispute.\n\n"
        . "5. GOVERNING LAW: This agreement is governed by the laws of the State of New York, USA.";

    // Default endorsements
    const DEFAULT_ENDORSEMENTS = "NON END/NON REF";

    // =========================================================================
    // CREATE
    // =========================================================================

    /**
     * Create a new acceptance request record.
     *
     * @param array $data  Validated form data from the wizard
     * @param int   $agentId  Current logged-in user ID
     * @return AcceptanceRequest
     */
    public function create(array $data, int $agentId): AcceptanceRequest
    {
        $token     = $this->generateToken();
        $expiresAt = Carbon::now()->addHours(self::EXPIRY_HOURS);

        $acceptance = AcceptanceRequest::create([
            'token'               => $token,
            'transaction_id'      => $data['transaction_id'] ?? null,
            'type'                => $data['type'],
            'status'              => AcceptanceRequest::STATUS_PENDING,
            'customer_name'       => trim($data['customer_name']),
            'customer_email'      => strtolower(trim($data['customer_email'])),
            'customer_phone'      => trim($data['customer_phone'] ?? ''),
            'pnr'                 => strtoupper(trim($data['pnr'])),
            'airline'             => trim($data['airline'] ?? ''),
            'order_id'            => trim($data['order_id'] ?? ''),
            'passengers'          => $data['passengers'],           // array of {name, dob, type}
            'flight_data'         => $data['flight_data'] ?? null,  // JSON per type
            'fare_breakdown'      => $data['fare_breakdown'] ?? [],  // [{label, amount}]
            'total_amount'        => (float)($data['total_amount'] ?? 0),
            'currency'            => strtoupper($data['currency'] ?? 'USD'),
            'split_charge_note'   => trim($data['split_charge_note'] ?? ''),
            'extra_data'          => $data['extra_data'] ?? null,
            'statement_descriptor'=> trim($data['statement_descriptor'] ?? ''),
            'card_type'           => trim($data['card_type'] ?? ''),
            'cardholder_name'     => trim($data['cardholder_name'] ?? ''),
            'card_last_four'      => preg_replace('/\D/', '', $data['card_last_four'] ?? ''),
            'billing_address'     => trim($data['billing_address'] ?? ''),
            'additional_cards'    => $data['additional_cards'] ?? null,
            'endorsements'        => trim($data['endorsements'] ?? self::DEFAULT_ENDORSEMENTS),
            'baggage_info'        => trim($data['baggage_info'] ?? ''),
            'fare_rules'          => trim($data['fare_rules'] ?? ''),
            'policy_text'         => trim($data['policy_text'] ?? self::DEFAULT_POLICY),
            'req_passport'        => (bool)($data['req_passport'] ?? false),
            'req_cc_front'        => (bool)($data['req_cc_front'] ?? false),
            'agent_id'            => $agentId,
            'agent_notes'         => trim($data['agent_notes'] ?? ''),
            'expires_at'          => $expiresAt,
            'email_status'        => AcceptanceRequest::EMAIL_PENDING,
            'email_attempts'      => 0,
        ]);

        return $acceptance;
    }

    // =========================================================================
    // TOKEN & EXPIRY
    // =========================================================================

    /**
     * Generate a cryptographically secure 64-character token.
     * Ensures uniqueness against the DB (extremely unlikely collision, but protected).
     */
    public function generateToken(): string
    {
        do {
            $token = bin2hex(random_bytes(32)); // 64 hex chars
        } while (AcceptanceRequest::where('token', $token)->exists());

        return $token;
    }

    /**
     * Reset expiry to 12 hours from now.
     * Called on resend.
     */
    public function resetExpiry(AcceptanceRequest $acceptance): void
    {
        $acceptance->update([
            'expires_at' => Carbon::now()->addHours(self::EXPIRY_HOURS),
        ]);
    }

    /**
     * Extend expiry by N hours (admin manual extension).
     */
    public function extendExpiry(AcceptanceRequest $acceptance, int $hours = 12): void
    {
        // Extend from now or from current expiry, whichever is later
        $base = Carbon::now()->gt($acceptance->expires_at) ? Carbon::now() : $acceptance->expires_at;

        $acceptance->update([
            'expires_at' => $base->addHours($hours),
        ]);
    }

    /**
     * Mark all PENDING records that are past their expiry as EXPIRED.
     * Should be called from a scheduled cron job or on-demand.
     *
     * @return int  Number of records updated
     */
    public function runExpiryJob(): int
    {
        return AcceptanceRequest::expiredAndPending()
            ->update(['status' => AcceptanceRequest::STATUS_EXPIRED]);
    }

    // =========================================================================
    // VALIDATION (Public Page)
    // =========================================================================

    /**
     * Validate a token for the public customer-facing page.
     * Returns the acceptance record or null if invalid/expired.
     */
    public function findValidByToken(string $token): ?AcceptanceRequest
    {
        $acceptance = AcceptanceRequest::where('token', $token)->first();

        if (!$acceptance) {
            return null;
        }

        // If it's already approved or cancelled, return as-is (customer sees a status page)
        if (!$acceptance->isPending()) {
            return $acceptance;
        }

        // Check if expired — if so, update status
        if (Carbon::now()->gt($acceptance->expires_at)) {
            $acceptance->update(['status' => AcceptanceRequest::STATUS_EXPIRED]);
            $acceptance->refresh();
        }

        return $acceptance;
    }

    /**
     * Record that the customer has viewed the link (first visit only).
     */
    public function recordViewed(AcceptanceRequest $acceptance): void
    {
        if ($acceptance->viewed_at === null) {
            $acceptance->update(['viewed_at' => Carbon::now()]);
        }
    }

    // =========================================================================
    // PROCESS SUBMISSION (Customer Signs)
    // =========================================================================

    /**
     * Process the customer's signed acceptance submission.
     *
     * @param AcceptanceRequest $acceptance
     * @param array             $forensicData  {ip, fingerprint, user_agent}
     * @param string|null       $signaturePath Saved signature filename
     * @param string|null       $passportPath  Saved passport filename (if uploaded)
     * @param string|null       $cardPath      Saved CC front filename (if uploaded)
     * @return bool
     */
    public function processApproval(
        AcceptanceRequest $acceptance,
        array $forensicData,
        ?string $signaturePath,
        ?string $passportPath,
        ?string $cardPath
    ): bool {
        if (!$acceptance->isActionable()) {
            return false;
        }

        $acceptance->update([
            'status'             => AcceptanceRequest::STATUS_APPROVED,
            'approved_at'        => Carbon::now(),
            'ip_address'         => $forensicData['ip'] ?? null,
            'device_fingerprint' => $forensicData['fingerprint'] ?? null,
            'user_agent'         => $forensicData['user_agent'] ?? null,
            'digital_signature'  => $signaturePath,
            'passport_image'     => $passportPath,
            'card_image_front'   => $cardPath,
        ]);

        return true;
    }

    // =========================================================================
    // CANCEL
    // =========================================================================

    /**
     * Cancel a pending acceptance request (agent-initiated).
     */
    public function cancel(AcceptanceRequest $acceptance, int $agentId): bool
    {
        if (!$acceptance->isPending()) {
            return false;
        }

        $acceptance->update([
            'status' => AcceptanceRequest::STATUS_CANCELLED,
        ]);

        return true;
    }

    // =========================================================================
    // FILE HANDLING
    // =========================================================================

    /**
     * Save base64-encoded signature image to storage.
     *
     * @param string $token       The acceptance token (used for filename)
     * @param string $base64Data  Raw base64 data URI from canvas
     * @return string|null  Saved filename (relative), or null on failure
     */
    public function saveSignature(string $token, string $base64Data): ?string
    {
        // Strip data URI header
        $data = preg_replace('/^data:image\/png;base64,/', '', $base64Data);
        $data = base64_decode($data);

        if (!$data) {
            return null;
        }

        $dir = __DIR__ . '/../../storage/acceptance/signatures/';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $filename = $token . '_sig.png';
        file_put_contents($dir . $filename, $data);

        return $filename;
    }

    /**
     * Save an uploaded evidence file (passport or CC front).
     *
     * @param string $token     The acceptance token
     * @param array  $file      $_FILES entry
     * @param string $type      'passport' or 'card'
     * @return string|null  Saved filename (relative), or null on failure
     */
    public function saveEvidenceFile(string $token, array $file, string $type): ?string
    {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return null;
        }

        $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf'];
        $mimeType     = mime_content_type($file['tmp_name']);

        if (!in_array($mimeType, $allowedMimes)) {
            return null;
        }

        // Max 10MB
        if ($file['size'] > 10 * 1024 * 1024) {
            return null;
        }

        $ext = match($mimeType) {
            'image/jpeg'        => 'jpg',
            'image/png'         => 'png',
            'image/gif'         => 'gif',
            'application/pdf'   => 'pdf',
            default             => 'bin',
        };

        $dir = __DIR__ . '/../../storage/acceptance/evidence/';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $filename = $token . '_' . $type . '.' . $ext;
        move_uploaded_file($file['tmp_name'], $dir . $filename);

        return $filename;
    }

    // =========================================================================
    // FORENSIC DATA
    // =========================================================================

    /**
     * Collect forensic data from the current server context.
     * Called server-side during the acceptance submission.
     * Device fingerprint comes from client-side JS and is passed in POST.
     */
    public function collectForensicData(string $fingerprint = ''): array
    {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR']
            ?? $_SERVER['HTTP_X_REAL_IP']
            ?? $_SERVER['REMOTE_ADDR']
            ?? 'unknown';

        // Handle comma-separated IPs (proxy chains) — take first (original client)
        if (str_contains($ip, ',')) {
            $ip = trim(explode(',', $ip)[0]);
        }

        return [
            'ip'          => $ip,
            'fingerprint' => htmlspecialchars(strip_tags($fingerprint)),
            'user_agent'  => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
        ];
    }

    // =========================================================================
    // LIST & SEARCH
    // =========================================================================

    /**
     * Paginated list of acceptance requests for the admin/agent list view.
     *
     * @param int         $page
     * @param int         $perPage
     * @param array       $filters  {status, type, pnr, email, agent_id, date_from, date_to}
     * @param int|null    $agentId  Restrict to agent (null = show all, admin sees all)
     */
    public function list(int $page = 1, int $perPage = 25, array $filters = [], ?int $agentId = null): array
    {
        $query = AcceptanceRequest::with('agent')->orderBy('id', 'desc');

        // Agent restriction
        if ($agentId !== null) {
            $query->forAgent($agentId);
        }

        // Filters
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (!empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }
        if (!empty($filters['pnr'])) {
            $query->byPnr($filters['pnr']);
        }
        if (!empty($filters['email'])) {
            $query->byEmail($filters['email']);
        }
        if (!empty($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from'] . ' 00:00:00');
        }
        if (!empty($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to'] . ' 23:59:59');
        }

        $total   = $query->count();
        $records = $query->skip(($page - 1) * $perPage)->take($perPage)->get();

        return [
            'records'     => $records,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => (int) ceil($total / $perPage),
        ];
    }
}
