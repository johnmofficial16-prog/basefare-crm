<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\TransactionPassenger;
use App\Models\PaymentCard;
use App\Models\AcceptanceRequest;
use App\Models\RecordNote;
use App\Services\EncryptionService;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * TransactionService — Core business logic for the Transaction Recorder.
 *
 * Responsibilities:
 *  - CRUD with immutability enforcement
 *  - Encrypted card storage via EncryptionService
 *  - Void / reversal workflow
 *  - Acceptance autofill data mapping
 *  - Admin card reveal (password-gated, logged)
 *  - List with filtering, sorting, pagination
 */
class TransactionService
{
    private EncryptionService $encryption;

    public function __construct()
    {
        $this->encryption = new EncryptionService();
    }

    // =========================================================================
    // CREATE
    // =========================================================================

    /**
     * Create a new transaction with passengers and encrypted cards.
     *
     * @param  array $data     Validated form data
     * @param  int   $agentId  Current logged-in user ID
     * @return Transaction
     */
    public function create(array $data, int $agentId): Transaction
    {
        return DB::connection()->transaction(function () use ($data, $agentId) {

            // ── Core transaction record ──────────────────────────────────
            $txn = Transaction::create([
                'agent_id'        => $agentId,
                'acceptance_id'   => $data['acceptance_id'] ?? null,
                'type'            => $data['type'],
                'customer_name'   => trim($data['customer_name']),
                'customer_email'  => strtolower(trim($data['customer_email'])),
                'customer_phone'  => trim($data['customer_phone'] ?? ''),
                'pnr'             => strtoupper(trim($data['pnr'])),
                'airline'         => trim($data['airline'] ?? ''),
                'order_id'        => trim($data['order_id'] ?? ''),
                'travel_date'     => $data['travel_date'] ?: null,
                'departure_time'  => $data['departure_time'] ?: null,
                'return_date'     => $data['return_date'] ?: null,
                'total_amount'    => (float)($data['total_amount'] ?? 0),
                'cost_amount'     => (float)($data['cost_amount'] ?? 0),
                'profit_mco'      => isset($data['profit_mco']) ? (float)$data['profit_mco'] : 0.00,
                'currency'        => strtoupper($data['currency'] ?? 'USD'),
                'payment_method'  => $data['payment_method'] ?? Transaction::PAY_CREDIT_CARD,
                'payment_status'  => $data['payment_status'] ?? Transaction::PAYMENT_PENDING,
                'data'            => $data['type_specific_data'] ?? null,
                'status'          => Transaction::STATUS_PENDING,
                'agent_notes'     => trim($data['agent_notes'] ?? ''),
                'proof_of_sale_path' => $data['proof_of_sale_path'] ?? null,
            ]);

            // ── Passengers ───────────────────────────────────────────────
            $passengers = $data['passengers'] ?? [];
            foreach ($passengers as $pax) {
                if (empty($pax['first_name']) && empty($pax['last_name'])) {
                    continue;
                }
                TransactionPassenger::create([
                    'transaction_id' => $txn->id,
                    'first_name'     => trim($pax['first_name'] ?? ''),
                    'last_name'      => trim($pax['last_name'] ?? ''),
                    'dob'            => !empty($pax['dob']) ? $pax['dob'] : null,
                    'pax_type'       => $pax['pax_type'] ?? 'adult',
                    'ticket_number'  => trim($pax['ticket_number'] ?? ''),
                    'frequent_flyer' => trim($pax['frequent_flyer'] ?? ''),
                ]);
            }

            // ── Payment cards (encrypted) ────────────────────────────────
            $this->saveCards($txn->id, $data);

            // ── Log creation note ────────────────────────────────────────
            RecordNote::log(
                'transaction',
                $txn->id,
                $agentId,
                !empty($data['agent_notes']) ? $data['agent_notes'] : 'Transaction recorded.',
                'created'
            );

            return $txn;
        });
    }

    // =========================================================================
    // UPDATE (only if pending_review)
    // =========================================================================

    /**
     * Update a transaction. Throws if immutable.
     *
     * @param  int   $id
     * @param  array $data
     * @return Transaction
     * @throws \RuntimeException if transaction is not editable
     */
    public function update(int $id, array $data, bool $isAdmin = false): Transaction
    {
        $txn = Transaction::findOrFail($id);

        if (!$txn->isEditable($isAdmin)) {
            throw new \RuntimeException(
                'Transaction #' . $id . ' is locked and cannot be edited by your role.'
            );
        }

        return DB::connection()->transaction(function () use ($txn, $data, $isAdmin) {

            $txn->update([
                'status'          => $isAdmin ? ($data['status'] ?? $txn->status) : $txn->status,
                'type'            => $data['type'] ?? $txn->type,
                'customer_name'   => trim($data['customer_name'] ?? $txn->customer_name),
                'customer_email'  => strtolower(trim($data['customer_email'] ?? $txn->customer_email)),
                'customer_phone'  => trim($data['customer_phone'] ?? $txn->customer_phone),
                'pnr'             => strtoupper(trim($data['pnr'] ?? $txn->pnr)),
                'airline'         => trim($data['airline'] ?? $txn->airline),
                'order_id'        => trim($data['order_id'] ?? $txn->order_id),
                'travel_date'     => $data['travel_date'] ?: $txn->travel_date,
                'departure_time'  => $data['departure_time'] ?: $txn->departure_time,
                'return_date'     => $data['return_date'] ?: $txn->return_date,
                'total_amount'    => (float)($data['total_amount'] ?? $txn->total_amount),
                'cost_amount'     => (float)($data['cost_amount'] ?? $txn->cost_amount),
                'profit_mco'      => isset($data['profit_mco']) ? (float)$data['profit_mco'] : $txn->profit_mco,
                'currency'        => strtoupper($data['currency'] ?? $txn->currency),
                'payment_method'  => $data['payment_method'] ?? $txn->payment_method,
                'payment_status'  => $data['payment_status'] ?? $txn->payment_status,
                'data'            => $data['type_specific_data'] ?? $txn->data,
                'agent_notes'     => trim($data['agent_notes'] ?? $txn->agent_notes),
            ]);

            // Re-save passengers (delete + re-insert)
            if (isset($data['passengers'])) {
                TransactionPassenger::where('transaction_id', $txn->id)->delete();
                foreach ($data['passengers'] as $pax) {
                    if (empty($pax['first_name']) && empty($pax['last_name'])) {
                        continue;
                    }
                    TransactionPassenger::create([
                        'transaction_id' => $txn->id,
                        'first_name'     => trim($pax['first_name'] ?? ''),
                        'last_name'      => trim($pax['last_name'] ?? ''),
                        'dob'            => !empty($pax['dob']) ? $pax['dob'] : null,
                        'pax_type'       => $pax['pax_type'] ?? 'adult',
                        'ticket_number'  => trim($pax['ticket_number'] ?? ''),
                        'frequent_flyer' => trim($pax['frequent_flyer'] ?? ''),
                    ]);
                }
            }

            // Re-save cards if provided
            if (isset($data['primary_card_number']) || isset($data['additional_cards_json'])) {
                PaymentCard::where('transaction_id', $txn->id)->delete();
                $this->saveCards($txn->id, $data);
            }

            // Log the edit
            RecordNote::log(
                'transaction',
                $txn->id,
                $_SESSION['user_id'] ?? 0,
                $data['agent_notes'] ?? 'Transaction updated.',
                'edited'
            );

            return $txn->fresh();
        });
    }

    // =========================================================================
    // APPROVE — lock immutably
    // =========================================================================

    /**
     * Approve a transaction. Once approved, it becomes immutable.
     * Supervisors can only approve transactions for agents in their team.
     *
     * @param  int    $id         Transaction ID
     * @param  int    $actorId    User performing the approval
     * @param  string $actorRole  'admin' | 'manager' | 'supervisor'
     * @return Transaction
     * @throws \RuntimeException if not pending_review or team check fails
     */
    public function approve(int $id, int $actorId = 0, string $actorRole = 'admin'): Transaction
    {
        $txn = Transaction::findOrFail($id);

        if ($txn->status !== Transaction::STATUS_PENDING) {
            throw new \RuntimeException(
                'Only pending_review transactions can be approved.'
            );
        }

        // Supervisors may only approve transactions belonging to their team
        if ($actorRole === \App\Models\User::ROLE_SUPERVISOR && $actorId > 0) {
            $actor = \App\Models\User::find($actorId);
            if (!$actor || !$actor->isInMyTeam($txn->agent_id)) {
                throw new \RuntimeException('You can only approve transactions for agents in your team.');
            }
        }

        $txn->update(['status' => Transaction::STATUS_APPROVED]);

        // Log approval note
        RecordNote::log(
            'transaction',
            $txn->id,
            $actorId,
            'Transaction approved.',
            'approved'
        );

        return $txn;
    }

    // =========================================================================
    // VOID — immutable void with mandatory reason + reversal
    // =========================================================================

    /**
     * Void a transaction. Creates a linked reversal record.
     *
     * @param  int    $id       Transaction to void
     * @param  string $reason   Mandatory reason
     * @param  int    $adminId  Admin performing the void
     * @return Transaction      The void/reversal transaction
     * @throws \RuntimeException if already voided or if reason is too short
     */
    public function void(int $id, string $reason, int $adminId): Transaction
    {
        $reason = trim($reason);
        if (strlen($reason) < 10) {
            throw new \RuntimeException('Void reason must be at least 10 characters.');
        }

        $txn = Transaction::findOrFail($id);

        if ($txn->isVoided()) {
            throw new \RuntimeException('Transaction #' . $id . ' is already voided.');
        }

        return DB::connection()->transaction(function () use ($txn, $reason, $adminId) {

            // Mark original as voided
            $txn->update([
                'status'      => Transaction::STATUS_VOIDED,
                'void_reason' => $reason,
                'voided_at'   => date('Y-m-d H:i:s'),
                'voided_by'   => $adminId,
            ]);

            // Create reversal record (negative amounts)
            $reversal = Transaction::create([
                'agent_id'               => $txn->agent_id,
                'type'                   => $txn->type,
                'customer_name'          => $txn->customer_name,
                'customer_email'         => $txn->customer_email,
                'customer_phone'         => $txn->customer_phone,
                'pnr'                    => $txn->pnr,
                'airline'                => $txn->airline,
                'order_id'               => $txn->order_id,
                'total_amount'           => -$txn->total_amount,
                'cost_amount'            => -$txn->cost_amount,
                'currency'               => $txn->currency,
                'payment_method'         => $txn->payment_method,
                'payment_status'         => 'refunded',
                'data'                   => $txn->data,
                'status'                 => Transaction::STATUS_VOIDED,
                'void_reason'            => 'Reversal of Transaction #' . $txn->id . ': ' . $reason,
                'voided_at'              => date('Y-m-d H:i:s'),
                'voided_by'              => $adminId,
                'void_of_transaction_id' => $txn->id,
                'agent_notes'            => 'Auto-generated reversal for void of #' . $txn->id,
            ]);

            // Log void notes on both records
            RecordNote::log('transaction', $txn->id,      $adminId, 'Voided: ' . $reason, 'voided');
            RecordNote::log('transaction', $reversal->id, $adminId, 'Auto-reversal created for void of Transaction #' . $txn->id, 'voided');

            return $reversal;
        });
    }

    // =========================================================================
    // LIST — paginated, filtered
    // =========================================================================

    /**
     * Get a filtered, paginated list of transactions.
     *
     * @param  int        $page
     * @param  int        $perPage
     * @param  array      $filters     [type, status, pnr, date_from, date_to, payment_status, search]
     * @param  int|null   $agentId     Restrict to this single agent (null = all)
     * @param  array|null $agentIds    Restrict to a set of agent IDs (for team scoping)
     * @return array      ['items' => Collection, 'total' => int, 'pages' => int, 'page' => int]
     */
    public function list(
        int $page = 1,
        int $perPage = 25,
        array $filters = [],
        ?int $agentId = null,
        ?array $agentIds = null
    ): array {
        $query = Transaction::with(['agent', 'primaryCard'])
            ->orderBy('created_at', 'desc');

        if ($agentId !== null) {
            $query->forAgent($agentId);
        } elseif ($agentIds !== null) {
            $query->whereIn('agent_id', $agentIds);
        }

        if (!empty($filters['type'])) {
            $query->byType($filters['type']);
        }
        if (!empty($filters['status'])) {
            $query->byStatus($filters['status']);
        }
        if (!empty($filters['pnr'])) {
            $query->byPnr($filters['pnr']);
        }
        // Universal search — matches name, phone, email, or PNR
        if (!empty($filters['search'])) {
            $term = '%' . $filters['search'] . '%';
            $query->where(function ($q) use ($term) {
                $q->where('customer_name', 'LIKE', $term)
                  ->orWhere('customer_phone', 'LIKE', $term)
                  ->orWhere('customer_email', 'LIKE', $term)
                  ->orWhere('pnr', 'LIKE', $term);
            });
        }
        if (!empty($filters['payment_status'])) {
            $query->where('payment_status', $filters['payment_status']);
        }
        if (!empty($filters['date_from']) || !empty($filters['date_to'])) {
            $query->byDateRange($filters['date_from'] ?? null, $filters['date_to'] ?? null);
        }

        $total = $query->count();
        $pages = (int)ceil($total / $perPage);
        $page  = max(1, min($page, $pages ?: 1));

        $items = $query->skip(($page - 1) * $perPage)->take($perPage)->get();

        return [
            'items' => $items,
            'total' => $total,
            'pages' => $pages,
            'page'  => $page,
        ];
    }

    // =========================================================================
    // ACCEPTANCE AUTOFILL
    // =========================================================================

    /**
     * Map an approved AcceptanceRequest's data into a pre-fill array
     * that the create form can use.
     *
     * @param  int $acceptanceId
     * @return array|null  Null if not found or not approved
     */
    public function getAcceptanceAutofill(int $acceptanceId): ?array
    {
        $acc = AcceptanceRequest::find($acceptanceId);

        if (!$acc) {
            return null;
        }

        // Pre-auth acceptances are payment holds only — the full signed acceptance
        // must be received before a transaction can be recorded against it.
        if ($acc->is_preauth) {
            throw new \RuntimeException('pre_auth');
        }

        if ($acc->status !== 'APPROVED') {
            return null;
        }

        // Parse passengers JSON → form-ready array (field may already be decoded by Eloquent cast)
        $passengers = [];
        $rawPax = $acc->passengers;
        if (is_string($rawPax)) {
            $rawPax = json_decode($rawPax, true);
        }
        if (is_array($rawPax)) {
            foreach ($rawPax as $p) {
                $nameParts = explode(' ', trim($p['name'] ?? ''), 2);
                $passengers[] = [
                    'first_name' => $nameParts[0] ?? '',
                    'last_name'  => $nameParts[1] ?? '',
                    'dob'        => $p['dob'] ?? '',
                    'pax_type'   => strtolower($p['type'] ?? 'adult'),
                ];
            }
        }

        // Parse fare breakdown
        $fareBreakdown = is_array($acc->fare_breakdown)
            ? $acc->fare_breakdown
            : (json_decode((string)$acc->fare_breakdown, true) ?: []);

        // Parse flight data
        $flightData = is_array($acc->flight_data)
            ? $acc->flight_data
            : (json_decode((string)$acc->flight_data, true) ?: []);

        // Parse additional cards
        $additionalCards = is_array($acc->additional_cards)
            ? $acc->additional_cards
            : (json_decode((string)$acc->additional_cards, true) ?: []);

        // ── Derive travel dates from flight_data ──────────────────────────
        $travelDate    = null;
        $departureTime = null;
        $returnDate    = null;

        // Try main flights first (new_booking, seat_purchase, etc.)
        $firstFlight = $flightData['flights'][0] ?? null;
        $lastFlight  = null;
        if (!empty($flightData['flights'])) {
            $lastFlight = end($flightData['flights']);
        }
        // For exchanges/cancels, fall back to old_flights
        if (!$firstFlight && !empty($flightData['old_flights'])) {
            $firstFlight = $flightData['old_flights'][0] ?? null;
            if (!empty($flightData['old_flights'])) {
                $lastFlight = end($flightData['old_flights']);
            }
        }
        if ($firstFlight) {
            $travelDate    = $firstFlight['date']    ?? ($firstFlight['dep_date'] ?? null);
            $departureTime = $firstFlight['dep_time'] ?? ($firstFlight['time'] ?? null);
        }
        if ($lastFlight && $lastFlight !== $firstFlight) {
            $returnDate = $lastFlight['date'] ?? ($lastFlight['arr_date'] ?? null);
        }

        return [
            'acceptance_id'        => $acc->id,
            'type'                 => $acc->type,
            'customer_name'        => $acc->customer_name,
            'customer_email'       => $acc->customer_email,
            'customer_phone'       => $acc->customer_phone ?? '',
            'pnr'                  => $acc->pnr,
            'airline'              => $acc->airline ?? '',
            'order_id'             => $acc->order_id ?? '',
            'total_amount'         => $acc->total_amount,
            'currency'             => $acc->currency ?? 'USD',
            // Derived travel dates
            'travel_date'          => $travelDate,
            'departure_time'       => $departureTime,
            'return_date'          => $returnDate,
            // Passengers
            'passengers'           => $passengers,
            'flight_data'          => $flightData,
            'fare_breakdown'       => $fareBreakdown,
            // Card info — partial only (last 4, type, holder)
            'card_type'            => $acc->card_type ?? '',
            'card_last_4'          => $acc->card_last_four ?? '',
            'cardholder_name'      => $acc->cardholder_name ?? '',
            'billing_address'      => $acc->billing_address ?? '',
            'additional_cards'     => $additionalCards,
            // Payment extra fields
            'statement_descriptor' => $acc->statement_descriptor ?? '',
            'split_charge_note'    => $acc->split_charge_note ?? '',
            // Ticket conditions
            'endorsements'         => $acc->endorsements ?? '',
            'baggage_info'         => $acc->baggage_info ?? '',
            'fare_rules'           => $acc->fare_rules ?? '',
        ];
    }

    /**
     * Get list of approved acceptance requests for autofill dropdown.
     * Returns recent first, limited set of fields.
     *
     * @param  int|null $agentId  Restrict to agent's acceptances
     * @return array
     */
    public function getAutofillOptions(?int $agentId = null): array
    {
        $query = AcceptanceRequest::where('status', 'APPROVED')
            ->doesntHave('transaction')
            ->orderBy('approved_at', 'desc')
            ->limit(50);

        if ($agentId) {
            $query->where('agent_id', $agentId);
        }

        return $query->get(['id', 'customer_name', 'pnr', 'type', 'total_amount', 'approved_at'])
            ->map(function ($acc) {
                return [
                    'id'            => $acc->id,
                    'label'         => $acc->customer_name . ' — ' . $acc->pnr . ' (' . ucfirst(str_replace('_', ' ', $acc->type)) . ')',
                    'customer_name' => $acc->customer_name,
                    'pnr'           => $acc->pnr,
                    'type'          => $acc->type,
                    'total_amount'  => $acc->total_amount,
                    'approved_at'   => $acc->approved_at,
                ];
            })
            ->toArray();
    }

    // =========================================================================
    // ADMIN CARD REVEAL (password-gated)
    // =========================================================================

    /**
     * Decrypt a payment card's full number and CVV for admin viewing.
     * Requires password re-validation. Logs every reveal.
     *
     * @param  int    $cardId    PaymentCard ID
     * @param  int    $adminId   Admin user ID
     * @param  string $password  Admin's password for re-validation
     * @return array  ['card_number' => string, 'cvv' => string, 'expiry' => string]
     * @throws \RuntimeException on password failure or missing card
     */
    public function revealCard(int $cardId, int $adminId, string $password): array
    {
        // Re-validate admin password (skip password check for session-based reveal)
        if ($password !== '__session__') {
            $admin = \App\Models\User::findOrFail($adminId);
            if (!password_verify($password, $admin->password_hash)) {
                throw new \RuntimeException('Invalid password. Card reveal denied.');
            }
        }

        $card = PaymentCard::findOrFail($cardId);

        // Decrypt
        $fullNumber = $card->decryptNumber($this->encryption);
        $cvv        = $card->decryptCvv($this->encryption);

        // Log the reveal on the card itself
        $card->recordReveal($adminId);

        // Log to activity_log
        DB::table('activity_log')->insert([
            'user_id'     => $adminId,
            'action'      => 'card_revealed',
            'entity_type' => 'payment_cards',
            'entity_id'   => $cardId,
            'details'     => json_encode([
                'transaction_id' => $card->transaction_id,
                'card_type'      => $card->card_type,
                'card_last_4'    => $card->card_last_4,
                'reveal_count'   => $card->reveal_count,
            ]),
            'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
            'created_at'  => date('Y-m-d H:i:s'),
        ]);

        return [
            'card_number' => $fullNumber,
            'cvv'         => $cvv,
            'expiry'      => $card->expiry,
            'holder_name' => $card->holder_name,
        ];
    }

    // =========================================================================
    // PRIVATE — Card encryption & storage
    // =========================================================================

    /**
     * Encrypt and persist payment cards from form data.
     */
    private function saveCards(int $txnId, array $data): void
    {
        // ── Primary card ─────────────────────────────────────────────
        if (!empty($data['primary_card_number'])) {
            $fullNumber = preg_replace('/\D/', '', $data['primary_card_number']);
            $last4      = substr($fullNumber, -4);

            PaymentCard::create([
                'transaction_id'  => $txnId,
                'card_type'       => trim($data['primary_card_type'] ?? 'Visa'),
                'card_last_4'     => $last4,
                'holder_name'     => trim($data['primary_cardholder_name'] ?? ''),
                'expiry'          => trim($data['primary_card_expiry'] ?? ''),
                'billing_address' => trim($data['primary_billing_address'] ?? ''),
                'card_number_enc' => $this->encryption->encrypt($fullNumber),
                'cvv_enc'         => !empty($data['primary_card_cvv'])
                    ? $this->encryption->encrypt($data['primary_card_cvv'])
                    : null,
                'amount'          => (float)($data['primary_card_amount'] ?? $data['total_amount'] ?? 0),
                'is_primary'      => true,
            ]);
        }

        // ── Additional cards (split charge) ──────────────────────────
        $additionalCards = [];
        if (!empty($data['additional_cards_json'])) {
            $additionalCards = json_decode($data['additional_cards_json'], true) ?: [];
        }

        foreach ($additionalCards as $card) {
            if (empty($card['card_number'])) {
                continue;
            }
            $fullNum = preg_replace('/\D/', '', $card['card_number']);
            $last4   = substr($fullNum, -4);

            PaymentCard::create([
                'transaction_id'  => $txnId,
                'card_type'       => trim($card['card_type'] ?? 'Visa'),
                'card_last_4'     => $last4,
                'holder_name'     => trim($card['cardholder_name'] ?? ''),
                'expiry'          => trim($card['expiry'] ?? ''),
                'billing_address' => trim($card['billing_address'] ?? ''),
                'card_number_enc' => $this->encryption->encrypt($fullNum),
                'cvv_enc'         => !empty($card['cvv'])
                    ? $this->encryption->encrypt($card['cvv'])
                    : null,
                'amount'          => (float)($card['amount'] ?? 0),
                'is_primary'      => false,
            ]);
        }
    }
}
