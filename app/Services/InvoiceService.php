<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Transaction;
use App\Models\PaymentCard;
use App\Models\User;
use App\Models\RecordNote;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as DB;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * InvoiceService — business logic for the Invoice Generator module.
 *
 * Responsibilities:
 *  - Generate invoice numbers.
 *  - Prefill an invoice from an existing booking (transaction) — SAFE fields
 *    only (never the encrypted PAN/CVV).
 *  - Create invoices with the approval state machine:
 *      agents/supervisors → pending_approval
 *      managers/admins    → approved (and may send immediately)
 *  - Approve / reject pending invoices (manager/admin).
 *  - Send the invoice to the customer as a branded inline HTML email.
 *  - List with role scoping.
 *
 * Mirrors CustomerEmailService for the approval + SMTP patterns.
 */
class InvoiceService
{
    const SUPPORT_EMAIL = 'reservation@base-fare.com';
    const SUPPORT_NAME  = 'Reservation Desk';
    const TOLL_FREE     = '888 608 4011';
    const COMPANY_DBA   = 'Lets Fly Travel LLC DBA Base Fare';

    /** Roles allowed to approve, and to send their own invoice directly. */
    const APPROVER_ROLES = [User::ROLE_ADMIN, User::ROLE_MANAGER];

    public function canApprove(?string $role): bool
    {
        return in_array($role, self::APPROVER_ROLES, true);
    }

    // =========================================================================
    // NUMBERING
    // =========================================================================

    public function generateInvoiceNo(): string
    {
        // Short, unique, human-friendly. Collisions are vanishingly unlikely,
        // but the UNIQUE index is the real guard — retry a couple of times.
        for ($i = 0; $i < 5; $i++) {
            $no = 'INV-' . strtoupper(bin2hex(random_bytes(3))); // INV-A1B2C3
            if (!Invoice::where('invoice_no', $no)->exists()) {
                return $no;
            }
        }
        return 'INV-' . strtoupper(substr(uniqid(), -6));
    }

    // =========================================================================
    // PREFILL (from a booking) — SAFE whitelist only
    // =========================================================================

    /**
     * Build a prefill payload for the maker from a transaction. Maps:
     *   Airline Charge   ← agency cost   (cost_amount)
     *   Base Fare Charge ← markup/profit (total - cost)
     *   Total            ← total_amount
     * and pulls customer/card-holder/billing/itinerary. NEVER touches the
     * encrypted card number or CVV.
     */
    public function buildPrefill(Transaction $txn): array
    {
        $card = $txn->primaryCard ?? $txn->cards()->first();

        $cost  = (float) ($txn->cost_amount ?? 0);
        $total = (float) ($txn->total_amount ?? 0);
        $base  = max(0, $total - $cost);

        return [
            'transaction_id'  => $txn->id,
            'purpose'         => $this->mapPurpose($txn->type),
            'customer_name'   => $txn->customer_name ?? '',
            'customer_email'  => $txn->customer_email ?? '',
            'customer_phone'  => $txn->customer_phone ?? '',
            'pnr'             => $txn->pnr ?? '',
            'airline'         => $txn->airline ?? '',
            'cardholder_name' => $card?->holder_name ?? '',
            'card_billing'    => $card?->billing_address ?? '',
            'currency'        => $txn->currency ?: 'USD',
            'airline_charge'  => $cost > 0 ? number_format($cost, 2, '.', '') : '',
            'base_fare_charge'=> number_format($base, 2, '.', ''),
            'total_amount'    => number_format($total, 2, '.', ''),
            'itinerary'       => $this->extractItinerary($txn),
        ];
    }

    /** Map a transaction type onto an invoice "purpose of charge". */
    private function mapPurpose(?string $type): string
    {
        return match ($type) {
            Transaction::TYPE_NEW_BOOKING                          => Invoice::PURPOSE_NEW_BOOKING,
            Transaction::TYPE_EXCHANGE, Transaction::TYPE_CABIN_UPGRADE,
            Transaction::TYPE_NAME_CORRECTION                      => Invoice::PURPOSE_CHANGES,
            Transaction::TYPE_SEAT_PURCHASE                        => Invoice::PURPOSE_SEAT_TYPE,
            default                                                => Invoice::PURPOSE_OTHER,
        };
    }

    /**
     * Flatten a booking's flight segments into the invoice itinerary shape:
     *   {from, to, flight, date, dep, arr, cabin}
     * Reads the linked acceptance first, then the transaction's own data —
     * the same source the e-ticket / customer-email itinerary uses.
     */
    public function extractItinerary(Transaction $txn): array
    {
        $fd = $this->resolveFlightData($txn);

        $segs = [];
        if (!empty($fd['flights']) && is_array($fd['flights']))             $segs = $fd['flights'];
        elseif (!empty($fd['new_flights']) && is_array($fd['new_flights'])) $segs = $fd['new_flights'];
        elseif (!empty($fd['old_flights']) && is_array($fd['old_flights'])) $segs = $fd['old_flights'];
        elseif ($fd && is_array(reset($fd)))                               $segs = array_values($fd);

        $rows = [];
        foreach ($segs as $s) {
            if (!is_array($s)) continue;
            $from = strtoupper(trim($s['from'] ?? $s['departure_airport'] ?? ''));
            $to   = strtoupper(trim($s['to']   ?? $s['arrival_airport']   ?? ''));
            if ($from === '' && $to === '') continue;

            $iata   = strtoupper(trim($s['airline_iata'] ?? $s['airline'] ?? ''));
            $flight = trim($iata . ' ' . trim($s['flight_no'] ?? $s['flight'] ?? ''));

            $rows[] = [
                'from'   => $from,
                'to'     => $to,
                'flight' => $flight,
                'date'   => trim($s['date'] ?? $s['departure_date'] ?? ''),
                'dep'    => trim($s['dep_time'] ?? $s['time'] ?? ''),
                'arr'    => trim($s['arr_time'] ?? $s['arrival_time'] ?? ''),
                'cabin'  => trim($s['cabin_class'] ?? $s['class'] ?? ''),
            ];
        }
        return $rows;
    }

    /** Resolve flight_data: linked acceptance first, then txn data. */
    private function resolveFlightData(Transaction $txn): array
    {
        $acc = $txn->acceptance;
        if ($acc) {
            $afd = is_array($acc->flight_data) ? $acc->flight_data
                 : (json_decode((string) $acc->flight_data, true) ?: []);
            if ($afd) return $afd;
        }
        $data = is_array($txn->data) ? $txn->data : (json_decode((string) $txn->data, true) ?: []);
        if (isset($data['flights']) || isset($data['old_flights']) || isset($data['new_flights'])) {
            return [
                'flights'     => $data['flights']     ?? [],
                'old_flights' => $data['old_flights'] ?? [],
                'new_flights' => $data['new_flights'] ?? [],
            ];
        }
        return is_array($data['flight_data'] ?? null) ? $data['flight_data'] : [];
    }

    // =========================================================================
    // CREATE / UPDATE
    // =========================================================================

    /**
     * Create an invoice. Approvers create as 'approved'; everyone else as
     * 'pending_approval'. If $sendNow is true and the creator can approve, the
     * invoice is emailed to the customer immediately.
     *
     * @return array {invoice, sent:bool, send_error?:string}
     */
    public function create(array $data, User $creator, bool $sendNow = false): array
    {
        return DB::connection()->transaction(function () use ($data, $creator, $sendNow) {
            $isApprover = $this->canApprove($creator->role);
            $fields     = $this->sanitize($data);

            $fields['invoice_no']  = $this->generateInvoiceNo();
            $fields['agent_id']    = $creator->id;
            $fields['created_by']  = $creator->id;
            $fields['status']      = $isApprover ? Invoice::STATUS_APPROVED : Invoice::STATUS_PENDING;
            if ($isApprover) {
                $fields['approved_by'] = $creator->id;
                $fields['approved_at'] = Carbon::now();
            }

            $invoice = Invoice::create($fields);

            RecordNote::log('invoice', $invoice->id, $creator->id,
                'Invoice ' . $invoice->invoice_no . ' created'
                . ($isApprover ? ' (auto-approved).' : ' and submitted for approval.'),
                'created');

            // Manager/admin who asked to send → email immediately.
            if ($isApprover && $sendNow) {
                $res = $this->send($invoice, $creator);
                return ['invoice' => $invoice->fresh(), 'sent' => $res['success'],
                        'send_error' => $res['error'] ?? null];
            }

            return ['invoice' => $invoice, 'sent' => false];
        });
    }

    /**
     * Update an editable invoice (pending; or approved-but-unsent by an approver).
     */
    public function update(Invoice $invoice, array $data, User $editor): Invoice
    {
        $invoice->update($this->sanitize($data));
        RecordNote::log('invoice', $invoice->id, $editor->id,
            'Invoice ' . $invoice->invoice_no . ' edited.', 'edited');
        return $invoice->fresh();
    }

    /**
     * Normalise + recompute the authoritative total from submitted form data.
     * The client total is never trusted.
     */
    private function sanitize(array $data): array
    {
        $purpose = in_array($data['purpose'] ?? '', array_keys(Invoice::purposeOptions()), true)
            ? $data['purpose'] : Invoice::PURPOSE_NEW_BOOKING;

        // Airline charge is optional/removable: blank/unchecked → NULL (row omitted).
        $airlineRaw = trim((string) ($data['airline_charge'] ?? ''));
        $airline    = ($airlineRaw === '' || !is_numeric($airlineRaw)) ? null : round((float) $airlineRaw, 2);
        $base       = round((float) ($data['base_fare_charge'] ?? 0), 2);
        $total      = round(($airline ?? 0) + $base, 2);

        $itinerary = $this->cleanItinerary($data['itinerary'] ?? []);

        return [
            'purpose'          => $purpose,
            'purpose_other'    => $purpose === Invoice::PURPOSE_OTHER
                                    ? (trim($data['purpose_other'] ?? '') ?: null) : null,
            'customer_name'    => trim($data['customer_name'] ?? '') ?: 'Customer',
            'customer_email'   => ($e = strtolower(trim($data['customer_email'] ?? ''))) !== '' ? $e : null,
            'customer_phone'   => trim($data['customer_phone'] ?? '') ?: null,
            'pnr'              => trim($data['pnr'] ?? '') ?: null,
            'airline'          => trim($data['airline'] ?? '') ?: null,
            'cardholder_name'  => trim($data['cardholder_name'] ?? '') ?: null,
            'card_billing'     => trim($data['card_billing'] ?? '') ?: null,
            'itinerary'        => $itinerary,
            'airline_charge'   => $airline,
            'base_fare_charge' => $base,
            'total_amount'     => $total,
            'currency'         => strtoupper(trim($data['currency'] ?? 'USD')) ?: 'USD',
            'issue_date'       => !empty($data['issue_date']) ? $data['issue_date'] : date('Y-m-d'),
            'notes'            => trim($data['notes'] ?? '') ?: null,
            'transaction_id'   => !empty($data['transaction_id']) ? (int) $data['transaction_id'] : null,
        ];
    }

    /** Drop empty itinerary rows and keep only known keys. */
    private function cleanItinerary($raw): array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true) ?: [];
        }
        if (!is_array($raw)) return [];

        $rows = [];
        foreach ($raw as $s) {
            if (!is_array($s)) continue;
            $row = [
                'from'   => strtoupper(trim($s['from']   ?? '')),
                'to'     => strtoupper(trim($s['to']     ?? '')),
                'flight' => trim($s['flight'] ?? ''),
                'date'   => trim($s['date']   ?? ''),
                'dep'    => trim($s['dep']    ?? ''),
                'arr'    => trim($s['arr']    ?? ''),
                'cabin'  => trim($s['cabin']  ?? ''),
            ];
            // Keep the row if it has any meaningful content.
            if (implode('', $row) !== '') {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    // =========================================================================
    // APPROVE / REJECT
    // =========================================================================

    /**
     * Approve a pending invoice and send it to the customer.
     *
     * @return array {success:bool, error?:string}
     */
    public function approveAndSend(Invoice $invoice, User $approver): array
    {
        if (!$invoice->isPendingApproval()) {
            return ['success' => false, 'error' => 'This invoice is not pending approval.'];
        }

        $invoice->update([
            'status'      => Invoice::STATUS_APPROVED,
            'approved_by' => $approver->id,
            'approved_at' => Carbon::now(),
        ]);

        $res = $this->send($invoice, $approver);

        RecordNote::log('invoice', $invoice->id, $approver->id,
            $res['success'] ? 'Invoice approved and emailed to customer.'
                            : 'Invoice approved but send FAILED: ' . ($res['error'] ?? 'unknown'),
            'approved');

        return $res;
    }

    public function reject(Invoice $invoice, User $approver, string $reason): bool
    {
        if (!$invoice->isPendingApproval()) {
            return false;
        }
        $invoice->update([
            'status'          => Invoice::STATUS_REJECTED,
            'approved_by'     => $approver->id,
            'approved_at'     => Carbon::now(),
            'rejected_reason' => mb_substr(trim($reason), 0, 500),
        ]);
        RecordNote::log('invoice', $invoice->id, $approver->id,
            'Invoice rejected: ' . $reason, 'voided');
        return true;
    }

    // =========================================================================
    // SEND (SMTP — branded inline HTML)
    // =========================================================================

    /**
     * Email the invoice to the customer as a branded inline HTML document.
     * Marks the invoice sent/failed.
     *
     * @return array {success:bool, error?:string}
     */
    public function send(Invoice $invoice, User $actor): array
    {
        $to = $invoice->customer_email;
        if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => 'No valid customer email address on this invoice.'];
        }

        try {
            $mail = $this->getMailer();
            $mail->addAddress($to, $invoice->customer_name);
            $mail->addReplyTo(self::SUPPORT_EMAIL, self::SUPPORT_NAME);
            $mail->Subject = 'Invoice ' . $invoice->invoice_no . ' — Base Fare';
            $mail->isHTML(true);
            $mail->Body    = $this->buildEmailHtml($invoice);
            $mail->AltBody = $this->buildPlainText($invoice);
            $mail->send();

            $invoice->update([
                'status'     => Invoice::STATUS_SENT,
                'sent_at'    => Carbon::now(),
                'sent_to'    => $to,
                'send_error' => null,
            ]);
            RecordNote::log('invoice', $invoice->id, $actor->id,
                'Invoice emailed to ' . $to . '.', 'emailed');
            return ['success' => true];

        } catch (Exception $e) {
            $err = $mail->ErrorInfo ?? $e->getMessage();
            error_log('[Invoice] send failed (invoice ' . $invoice->id . '): ' . $err);
            $invoice->update(['send_error' => $err]);
            RecordNote::log('invoice', $invoice->id, $actor->id,
                'Invoice email FAILED: ' . $err, 'note');
            return ['success' => false, 'error' => $err];
        }
    }

    // =========================================================================
    // LIST
    // =========================================================================

    public function list(int $page = 1, int $perPage = 25, array $filters = [], ?int $createdBy = null, ?array $createdByIds = null): array
    {
        $query = Invoice::with(['creator'])->orderByDesc('created_at')->orderByDesc('id');

        if ($createdBy !== null)        $query->where('created_by', $createdBy);
        elseif ($createdByIds !== null) $query->whereIn('created_by', $createdByIds);

        if (!empty($filters['status'])) $query->where('status', $filters['status']);
        if (!empty($filters['search'])) {
            $term = '%' . $filters['search'] . '%';
            $query->where(fn($q) => $q->where('customer_name', 'LIKE', $term)
                ->orWhere('invoice_no', 'LIKE', $term)
                ->orWhere('pnr', 'LIKE', $term)
                ->orWhere('customer_email', 'LIKE', $term));
        }

        $total = $query->count();
        $pages = (int) ceil($total / $perPage);
        $page  = max(1, min($page, $pages ?: 1));
        $records = $query->skip(($page - 1) * $perPage)->take($perPage)->get();

        return ['records' => $records, 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'total_pages' => $pages];
    }

    // =========================================================================
    // SMTP + RENDERING HELPERS
    // =========================================================================

    private function getMailer(): PHPMailer
    {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->CharSet    = 'UTF-8';
        $mail->Host       = $_ENV['SMTP_HOST'] ?? 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = $_ENV['SMTP_USER'] ?? '';
        $mail->Password   = $_ENV['SMTP_PASS'] ?? '';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = $_ENV['SMTP_PORT'] ?? 587;
        $mail->Timeout    = 30; // fail fast — don't hang the synchronous send.

        $fromEmail = $_ENV['SMTP_FROM'] ?? $_ENV['SMTP_USER'] ?? '';
        if ($fromEmail) {
            $mail->setFrom($fromEmail, self::SUPPORT_NAME);
        }
        return $mail;
    }

    private function money(?float $v, string $currency): string
    {
        return $currency . ' ' . number_format((float) $v, 2);
    }

    /**
     * Email-safe HTML invoice — table-based layout with inline styles so it
     * renders consistently across mail clients. Mirrors the on-screen
     * receipt-style document (navy/gold brand, charge breakdown, total).
     */
    public function buildEmailHtml(Invoice $invoice): string
    {
        $esc = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $cur = $invoice->currency;

        $no       = $esc($invoice->invoice_no);
        $date     = $esc(date('F j, Y', strtotime((string) $invoice->issue_date)));
        $pax      = $esc($invoice->customer_name);
        $purpose  = $esc($invoice->purposeLabel());
        $email    = $esc($invoice->customer_email);
        $phone    = $esc($invoice->customer_phone);
        $pnr      = $esc($invoice->pnr);
        $airline  = $esc($invoice->airline);
        $cch      = $esc($invoice->cardholder_name);
        $billing  = $esc($invoice->card_billing);
        $tollFree = self::TOLL_FREE;
        $support  = self::SUPPORT_EMAIL;
        $dba      = self::COMPANY_DBA;

        // ── Customer info rows ──
        $infoRows = '';
        $info = array_filter([
            'Passenger' => $pax,
            'Purpose'   => $purpose,
            'PNR'       => $pnr,
            'Airline'   => $airline,
            'Phone'     => $phone,
            'Email'     => $email,
        ], fn($v) => $v !== '');
        foreach ($info as $label => $val) {
            $infoRows .= '<tr>'
                . '<td style="padding:6px 0;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:#94a3b8;width:120px;">' . $esc($label) . '</td>'
                . '<td style="padding:6px 0;font-size:13px;font-weight:600;color:#1e293b;">' . $val . '</td>'
                . '</tr>';
        }

        // ── Itinerary table ──
        $itinHtml = '';
        $segments = is_array($invoice->itinerary) ? $invoice->itinerary : [];
        if ($segments) {
            $segRows = '';
            foreach ($segments as $s) {
                $flight = trim((string) ($s['flight'] ?? ''));
                if (!empty($s['cabin'])) {
                    $flight = trim($flight . ($flight !== '' ? ' · ' : '') . $s['cabin']);
                }
                $time = trim(($s['dep'] ?? '') . (!empty($s['arr']) ? ' – ' . $s['arr'] : ''), ' –');
                $segRows .= '<tr>'
                    . '<td style="padding:8px 10px;border-bottom:1px solid #f1f5f9;font-weight:700;color:#0f1e3c;font-size:12px;">' . $esc(strtoupper($s['from'] ?? '')) . ' &rarr; ' . $esc(strtoupper($s['to'] ?? '')) . '</td>'
                    . '<td style="padding:8px 10px;border-bottom:1px solid #f1f5f9;font-size:12px;color:#475569;">' . $esc($flight) . '</td>'
                    . '<td style="padding:8px 10px;border-bottom:1px solid #f1f5f9;font-size:12px;color:#475569;">' . $esc($s['date'] ?? '') . '</td>'
                    . '<td style="padding:8px 10px;border-bottom:1px solid #f1f5f9;font-size:12px;color:#475569;">' . $esc($time) . '</td>'
                    . '</tr>';
            }
            $itinHtml = '<div style="font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:1px;color:#94a3b8;margin:22px 0 8px;">Itinerary</div>'
                . '<table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;border:1px solid #e2e8f0;border-radius:8px;">'
                . '<tr style="background:#f8fafc;">'
                . '<td style="padding:6px 10px;font-size:10px;text-transform:uppercase;color:#64748b;font-weight:700;">Route</td>'
                . '<td style="padding:6px 10px;font-size:10px;text-transform:uppercase;color:#64748b;font-weight:700;">Flight</td>'
                . '<td style="padding:6px 10px;font-size:10px;text-transform:uppercase;color:#64748b;font-weight:700;">Date</td>'
                . '<td style="padding:6px 10px;font-size:10px;text-transform:uppercase;color:#64748b;font-weight:700;">Time</td>'
                . '</tr>' . $segRows . '</table>';
        }

        // ── Charge rows ──
        $chargeRows = '';
        if ($invoice->airline_charge !== null) {
            $chargeRows .= '<tr>'
                . '<td style="padding:9px 14px;border-bottom:1px solid #f1f5f9;font-size:13px;color:#475569;">Airline Charge</td>'
                . '<td style="padding:9px 14px;border-bottom:1px solid #f1f5f9;font-size:13px;text-align:right;font-family:monospace;font-weight:700;color:#1e293b;">' . $esc($this->money($invoice->airline_charge, $cur)) . '</td>'
                . '</tr>';
        }
        $chargeRows .= '<tr>'
            . '<td style="padding:9px 14px;border-bottom:1px solid #f1f5f9;font-size:13px;color:#475569;">Base Fare Service Charge</td>'
            . '<td style="padding:9px 14px;border-bottom:1px solid #f1f5f9;font-size:13px;text-align:right;font-family:monospace;font-weight:700;color:#1e293b;">' . $esc($this->money($invoice->base_fare_charge, $cur)) . '</td>'
            . '</tr>';

        $totalStr = $esc($this->money($invoice->total_amount, $cur));

        $cardBlock = '';
        if ($cch || $billing) {
            $cardBlock = '<div style="font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:1px;color:#94a3b8;margin:22px 0 8px;">Payment</div>'
                . '<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px 14px;font-size:12px;color:#475569;">'
                . ($cch ? '<strong style="color:#1e293b;">' . $cch . '</strong><br>' : '')
                . ($billing ? $billing : '')
                . '</div>';
        }

        return <<<HTML
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="font-family:'Segoe UI',Arial,sans-serif;background:#f0f4f8;margin:0;padding:20px;color:#333;">
  <div style="max-width:640px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.08);">
    <table width="100%" cellpadding="0" cellspacing="0" style="background:linear-gradient(135deg,#0f1e3c 0%,#1a3a6b 100%);">
      <tr>
        <td style="padding:24px 30px;">
          <div style="color:#fff;font-size:18px;font-weight:800;letter-spacing:.5px;">Base Fare</div>
          <div style="color:#c9a84c;font-size:11px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;margin-top:2px;">{$dba}</div>
        </td>
        <td style="padding:24px 30px;text-align:right;">
          <div style="color:#93c5fd;font-size:10px;text-transform:uppercase;letter-spacing:1px;">Invoice</div>
          <div style="color:#fff;font-size:20px;font-weight:900;letter-spacing:1px;">{$no}</div>
          <div style="color:#cbd5e1;font-size:11px;margin-top:2px;">{$date}</div>
        </td>
      </tr>
    </table>

    <div style="padding:26px 30px;">
      <table width="100%" cellpadding="0" cellspacing="0">{$infoRows}</table>

      {$itinHtml}

      <div style="font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:1px;color:#94a3b8;margin:22px 0 8px;">Charges</div>
      <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;">
        {$chargeRows}
        <tr style="background:#0f1e3c;">
          <td style="padding:12px 14px;font-size:15px;font-weight:800;color:#fff;">Total Amount</td>
          <td style="padding:12px 14px;font-size:17px;font-weight:800;text-align:right;font-family:monospace;color:#4ade80;">{$totalStr}</td>
        </tr>
      </table>

      {$cardBlock}
    </div>

    <table width="100%" cellpadding="0" cellspacing="0" style="background:linear-gradient(135deg,#0f1e3c,#1a3a6b);">
      <tr><td style="padding:22px 30px;text-align:center;">
        <div style="color:rgba(255,255,255,0.85);font-size:13px;line-height:1.7;">Questions about this invoice? Just reply to this email — we're here to help.</div>
        <div style="margin-top:10px;color:#fff;font-size:14px;font-weight:700;">&#9742;&nbsp; Toll-Free: <a href="tel:+1{$tollFree}" style="color:#fff;text-decoration:none;">{$tollFree}</a></div>
      </td></tr>
    </table>
    <div style="background:#f8fafc;padding:16px 30px;text-align:center;font-size:11px;color:#94a3b8;border-top:1px solid #e2e8f0;">
      <strong style="color:#0f1e3c;">{$dba}</strong> &nbsp;&middot;&nbsp; {$support}
    </div>
  </div>
</body></html>
HTML;
    }

    private function buildPlainText(Invoice $invoice): string
    {
        $cur   = $invoice->currency;
        $lines = [];
        $lines[] = 'INVOICE ' . $invoice->invoice_no . ' — Base Fare';
        $lines[] = 'Date: ' . date('F j, Y', strtotime((string) $invoice->issue_date));
        $lines[] = '';
        $lines[] = 'Passenger: ' . $invoice->customer_name;
        $lines[] = 'Purpose: ' . $invoice->purposeLabel();
        if ($invoice->pnr)     $lines[] = 'PNR: ' . $invoice->pnr;
        if ($invoice->airline) $lines[] = 'Airline: ' . $invoice->airline;
        $lines[] = '';
        if ($invoice->airline_charge !== null) {
            $lines[] = 'Airline Charge: ' . $this->money($invoice->airline_charge, $cur);
        }
        $lines[] = 'Base Fare Service Charge: ' . $this->money($invoice->base_fare_charge, $cur);
        $lines[] = 'TOTAL: ' . $this->money($invoice->total_amount, $cur);
        $lines[] = '';
        $lines[] = 'Questions? Reply to this email or call toll-free ' . self::TOLL_FREE . '.';
        $lines[] = self::COMPANY_DBA;
        return implode("\n", $lines);
    }
}
