<?php

namespace App\Services;

use App\Models\ETicket;
use App\Models\Transaction;
use App\Models\AcceptanceRequest;
use App\Models\RecordNote;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * ETicketService — Core business logic for the E-Ticket Module.
 *
 * Gate: Transaction.status = 'approved' AND gateway_status = 'charge_successful'
 * Contains NO HTTP logic.
 */
class ETicketService
{
    const DEFAULT_POLICY =
        "1. TICKET RECEIPT: You have received your electronic travel ticket and all booking details are correct.\n\n"
        . "2. NON-REFUNDABLE: This ticket is 100% NON-REFUNDABLE and NON-TRANSFERABLE as per the airline's fare rules.\n\n"
        . "3. CHARGEBACK WAIVER: You explicitly acknowledge that all services described herein have been rendered by "
        . "Lets Fly Travel DBA Base Fare. Filing a credit card dispute or chargeback after receiving this e-ticket "
        . "constitutes Friendly Fraud. This acknowledgment, along with your previously signed authorization, IP address, "
        . "and device information will be submitted as conclusive evidence to your bank to contest any such claim.\n\n"
        . "4. TRAVEL DOCUMENTS: You are solely responsible for ensuring valid passport, visa, and health documentation. "
        . "Denied boarding due to missing documents does not constitute grounds for a refund or dispute.\n\n"
        . "5. CHECK-IN: Please check in online within the airline's check-in window. Missed check-in is not the "
        . "responsibility of Lets Fly Travel DBA Base Fare.\n\n"
        . "6. GOVERNING LAW: This agreement is governed by the laws of the State of New York, USA.";

    // =========================================================================
    // ELIGIBILITY
    // =========================================================================

    public function canIssue(Transaction $txn): bool
    {
        return $txn->status === Transaction::STATUS_APPROVED
            && $txn->gateway_status === Transaction::GATEWAY_SUCCESS;
    }

    public function existsForTransaction(int $transactionId): bool
    {
        return ETicket::where('transaction_id', $transactionId)->exists();
    }

    // =========================================================================
    // CREATE
    // =========================================================================

    public function create(array $data, int $agentId): ETicket
    {
        $txnId = (int)($data['transaction_id'] ?? 0);
        $txn   = Transaction::findOrFail($txnId);

        if (!$this->canIssue($txn)) {
            throw new \RuntimeException(
                'E-Ticket cannot be issued: Transaction must be Approved and Charged (gateway charge successful).'
            );
        }

        if ($this->existsForTransaction($txnId)) {
            throw new \RuntimeException('An e-ticket already exists for Transaction #' . $txnId . '. Use resend instead.');
        }

        return DB::connection()->transaction(function () use ($data, $agentId, $txn) {
            $ticketData = $data['ticket_data'] ?? [];
            foreach ($ticketData as $i => &$pax) {
                if (empty($pax['ticket_number'])) {
                    $pax['ticket_number'] = 'BF-' . str_pad($txn->id, 6, '0', STR_PAD_LEFT) . '-' . ($i + 1);
                }
            }
            unset($pax);

            $eticket = ETicket::create([
                'token'          => $this->generateToken(),
                'transaction_id' => $txn->id,
                'acceptance_id'  => $txn->acceptance_id ?: null,
                'agent_id'       => $agentId,
                'customer_name'  => trim($data['customer_name']),
                'customer_email' => strtolower(trim($data['customer_email'])),
                'customer_phone' => trim($data['customer_phone'] ?? ''),
                'pnr'            => strtoupper(trim($data['pnr'])),
                'airline'        => trim($data['airline'] ?? ''),
                'order_id'       => trim($data['order_id'] ?? ''),
                'ticket_data'    => $ticketData,
                'flight_data'    => $data['flight_data'] ?? null,
                'fare_breakdown' => $data['fare_breakdown'] ?? [],
                'total_amount'   => (float)($data['total_amount'] ?? 0),
                'currency'       => strtoupper($data['currency'] ?? 'USD'),
                'endorsements'   => trim($data['endorsements'] ?? ''),
                'baggage_info'   => trim($data['baggage_info'] ?? ''),
                'fare_rules'     => trim($data['fare_rules'] ?? ''),
                'policy_text'    => trim($data['policy_text'] ?? self::DEFAULT_POLICY),
                'extra_data'     => $data['extra_data'] ?? null,
                'agent_notes'    => trim($data['agent_notes'] ?? ''),
                'status'         => ETicket::STATUS_DRAFT,
                'email_status'   => ETicket::EMAIL_PENDING,
            ]);

            RecordNote::log('eticket', $eticket->id, $agentId,
                !empty($data['agent_notes']) ? $data['agent_notes'] : 'E-Ticket created.', 'created');

            return $eticket;
        });
    }

    // =========================================================================
    // AUTOFILL
    // =========================================================================

    public function getTransactionAutofill(int $transactionId): ?array
    {
        $txn = Transaction::with(['passengers', 'acceptance'])->find($transactionId);
        if (!$txn) return null;

        if (!$this->canIssue($txn)) {
            throw new \RuntimeException('not_eligible');
        }
        if ($this->existsForTransaction($transactionId)) {
            throw new \RuntimeException('already_issued');
        }

        // Build ticket_data from passengers
        $ticketData = [];
        foreach ($txn->passengers as $pax) {
            $ticketData[] = [
                'pax_name'      => trim($pax->first_name . ' ' . $pax->last_name),
                'pax_type'      => $pax->pax_type ?? 'adult',
                'dob'           => $pax->dob ?? '',
                'ticket_number' => $pax->ticket_number ?? '',
                'seat'          => '',
            ];
        }

        // Pull richer data from linked Acceptance
        $acc = $txn->acceptance;
        $flightData = $fareBreakdown = $endorsements = $baggageInfo = $fareRules = $extraData = null;

        if ($acc) {
            $flightData    = is_array($acc->flight_data)    ? $acc->flight_data    : (json_decode((string)$acc->flight_data, true) ?: null);
            $fareBreakdown = is_array($acc->fare_breakdown) ? $acc->fare_breakdown : (json_decode((string)$acc->fare_breakdown, true) ?: []);
            $endorsements  = $acc->endorsements ?? '';
            $baggageInfo   = $acc->baggage_info  ?? '';
            $fareRules     = $acc->fare_rules    ?? '';
            $extraData     = is_array($acc->extra_data) ? $acc->extra_data : (json_decode((string)$acc->extra_data, true) ?: null);

            // Pre-fill ticket numbers from acceptance etkt_list
            foreach ($extraData['etkt_list'] ?? [] as $etktRow) {
                $paxName = strtolower(trim($etktRow['pax_name'] ?? ''));
                foreach ($ticketData as &$td) {
                    if (strtolower(trim($td['pax_name'])) === $paxName && empty($td['ticket_number'])) {
                        $td['ticket_number'] = $etktRow['etkt'] ?? '';
                    }
                }
                unset($td);
            }

            // Pre-fill seats from seat_assignments
            foreach ($extraData['seat_assignments'] ?? [] as $sa) {
                $paxName = strtolower(trim($sa['passenger'] ?? ''));
                foreach ($ticketData as &$td) {
                    if (strtolower(trim($td['pax_name'])) === $paxName) {
                        $td['seat'] = $sa['seat'] ?? '';
                    }
                }
                unset($td);
            }
        }

        if (!$flightData && is_array($txn->data)) {
            $flightData = $txn->data['flight_data'] ?? null;
        }

        return [
            'transaction_id' => $txn->id,
            'acceptance_id'  => $txn->acceptance_id,
            'customer_name'  => $txn->customer_name,
            'customer_email' => $txn->customer_email,
            'customer_phone' => $txn->customer_phone ?? '',
            'pnr'            => $txn->pnr,
            'airline'        => $txn->airline ?? ($acc?->airline ?? ''),
            'order_id'       => $txn->order_id ?? '',
            'total_amount'   => $txn->total_amount,
            'currency'       => $txn->currency ?? 'USD',
            'ticket_data'    => $ticketData,
            'flight_data'    => $flightData,
            'fare_breakdown' => $fareBreakdown ?? [],
            'endorsements'   => $endorsements ?? '',
            'baggage_info'   => $baggageInfo  ?? '',
            'fare_rules'     => $fareRules    ?? '',
            'extra_data'     => $extraData,
            'policy_text'    => self::DEFAULT_POLICY,
        ];
    }

    public function getAutofillOptions(?int $agentId = null): array
    {
        $issuedIds = ETicket::pluck('transaction_id')->toArray();

        $query = Transaction::where('status', Transaction::STATUS_APPROVED)
            ->where('gateway_status', Transaction::GATEWAY_SUCCESS)
            ->when($issuedIds, fn($q) => $q->whereNotIn('id', $issuedIds))
            ->orderBy('created_at', 'desc')
            ->limit(100);

        if ($agentId) $query->where('agent_id', $agentId);

        return $query->get(['id', 'customer_name', 'pnr', 'type', 'total_amount', 'currency'])
            ->map(fn($t) => [
                'id'            => $t->id,
                'label'         => $t->customer_name . ' — ' . $t->pnr . ' (' . $t->typeLabel() . ')',
                'customer_name' => $t->customer_name,
                'pnr'           => $t->pnr,
                'type'          => $t->type,
                'total_amount'  => $t->total_amount,
                'currency'      => $t->currency,
            ])
            ->toArray();
    }

    // =========================================================================
    // PUBLIC PAGE
    // =========================================================================

    public function findByToken(string $token): ?ETicket
    {
        return ETicket::where('token', $token)->first();
    }

    public function processAcknowledgment(ETicket $eticket, array $forensicData): bool
    {
        if ($eticket->isAcknowledged()) return true;

        $eticket->update([
            'status'          => ETicket::STATUS_ACKNOWLEDGED,
            'acknowledged_at' => Carbon::now(),
            'acknowledged_ip' => $forensicData['ip']         ?? null,
            'acknowledged_ua' => $forensicData['user_agent'] ?? null,
        ]);

        RecordNote::log('eticket', $eticket->id, 0,
            'E-Ticket acknowledged by customer. IP: ' . ($forensicData['ip'] ?? 'unknown'), 'acknowledged');

        return true;
    }

    // =========================================================================
    // EMAIL STATUS
    // =========================================================================

    public function markSent(ETicket $eticket, string $sentTo): void
    {
        $wasAlreadySent = $eticket->status !== ETicket::STATUS_DRAFT;
        $eticket->increment('email_attempts');
        $eticket->update([
            'status'          => ETicket::STATUS_SENT,
            'email_status'    => $wasAlreadySent ? ETicket::EMAIL_RESENT : ETicket::EMAIL_SENT,
            'last_emailed_at' => Carbon::now(),
            'sent_to_email'   => $sentTo,
        ]);
    }

    public function markEmailFailed(ETicket $eticket): void
    {
        $eticket->increment('email_attempts');
        $eticket->update(['email_status' => ETicket::EMAIL_FAILED]);
    }

    // =========================================================================
    // LIST
    // =========================================================================

    public function list(int $page = 1, int $perPage = 25, array $filters = [], ?int $agentId = null, ?array $agentIds = null): array
    {
        $query = ETicket::with(['agent', 'transaction'])->orderBy('created_at', 'desc');

        if ($agentId !== null)       $query->forAgent($agentId);
        elseif ($agentIds !== null)  $query->whereIn('agent_id', $agentIds);

        if (!empty($filters['status']))    $query->byStatus($filters['status']);
        if (!empty($filters['pnr']))       $query->byPnr($filters['pnr']);
        if (!empty($filters['search'])) {
            $term = '%' . $filters['search'] . '%';
            $query->where(fn($q) => $q->where('customer_name', 'LIKE', $term)
                ->orWhere('customer_email', 'LIKE', $term)
                ->orWhere('pnr', 'LIKE', $term));
        }
        if (!empty($filters['date_from'])) $query->where('created_at', '>=', $filters['date_from'] . ' 00:00:00');
        if (!empty($filters['date_to']))   $query->where('created_at', '<=', $filters['date_to'] . ' 23:59:59');

        $total   = $query->count();
        $pages   = (int)ceil($total / $perPage);
        $page    = max(1, min($page, $pages ?: 1));
        $records = $query->skip(($page - 1) * $perPage)->take($perPage)->get();

        return ['records' => $records, 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'total_pages' => $pages];
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    public function generateToken(): string
    {
        do { $token = bin2hex(random_bytes(32)); }
        while (ETicket::where('token', $token)->exists());
        return $token;
    }

    public function collectForensicData(): array
    {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        if (str_contains($ip, ',')) $ip = trim(explode(',', $ip)[0]);
        return ['ip' => $ip, 'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'];
    }
}
