<?php

namespace App\Services;

use App\Models\ETicket;
use Carbon\Carbon;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * ETicketEmailService
 *
 * Handles two types of emails:
 *  1. send()                    — E-ticket email to customer with "I have read" acknowledgment button
 *  2. sendAcknowledgmentNotice()— Auto-email to reservation@base-fare.com when customer acknowledges
 *
 * Follows AcceptanceEmailService pattern exactly (SMTP config, charset, logging).
 */
class ETicketEmailService
{
    const SUPPORT_EMAIL = 'reservation@base-fare.com';
    const SUPPORT_NAME  = 'Reservation Desk';

    // =========================================================================
    // MAILER SETUP
    // =========================================================================

    private function getMailer(): PHPMailer
    {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->CharSet    = 'UTF-8';
        $mail->Host       = $_ENV['SMTP_HOST']    ?? 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = $_ENV['SMTP_USER']    ?? '';
        $mail->Password   = $_ENV['SMTP_PASS']    ?? '';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = $_ENV['SMTP_PORT']    ?? 587;

        $fromName  = 'Reservation Desk';
        $fromEmail = $_ENV['SMTP_FROM']      ?? $_ENV['SMTP_USER'] ?? '';

        if ($fromEmail) {
            $mail->setFrom($fromEmail, $fromName);
        }

        return $mail;
    }

    // =========================================================================
    // SEND E-TICKET TO CUSTOMER
    // =========================================================================

    /**
     * Send the e-ticket email to the customer.
     *
     * @param  ETicket $eticket
     * @param  string|null $overrideEmail  Used on resend to a different address
     * @return array {success, link, error?}
     */
    public function send(ETicket $eticket, ?string $overrideEmail = null): array
    {
        $sendTo    = $overrideEmail ?? $eticket->customer_email;
        $sendToName = $eticket->customer_name;
        $subject   = $this->buildSubject($eticket);
        $link      = $eticket->publicUrl();

        try {
            $mail = $this->getMailer();
            $mail->addAddress($sendTo, $sendToName);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $this->buildHtmlEmail($eticket, $link);
            $mail->AltBody = $this->buildPlainText($eticket, $link);
            $mail->send();

            $this->log($eticket, $subject, $sendTo, 'SENT_OK');
            return ['success' => true, 'link' => $link, 'sent_to' => $sendTo];

        } catch (Exception $e) {
            $this->log($eticket, $subject, $sendTo, 'ERROR: ' . ($mail->ErrorInfo ?? $e->getMessage()));
            return ['success' => false, 'error' => $mail->ErrorInfo ?? $e->getMessage()];
        }
    }

    // =========================================================================
    // SEND ACKNOWLEDGMENT NOTICE TO FIRM
    // =========================================================================

    /**
     * Send automatic notification to reservation@base-fare.com when customer acknowledges.
     * This constitutes the legal receipt of acknowledgment.
     *
     * @param  ETicket $eticket
     * @return array {success}
     */
    public function sendAcknowledgmentNotice(ETicket $eticket): array
    {
        $subject = sprintf(
            'E-Ticket Acknowledged — %s | PNR: %s',
            $eticket->customer_name,
            $eticket->pnr
        );

        try {
            $mail = $this->getMailer();
            $mail->addAddress(self::SUPPORT_EMAIL, self::SUPPORT_NAME);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $this->buildAcknowledgmentHtml($eticket);
            $mail->AltBody = $this->buildAcknowledgmentPlain($eticket);
            $mail->send();

            $this->log($eticket, $subject, self::SUPPORT_EMAIL, 'ACK_NOTICE_SENT');
            return ['success' => true];

        } catch (Exception $e) {
            $this->log($eticket, $subject, self::SUPPORT_EMAIL, 'ACK_NOTICE_ERROR: ' . ($mail->ErrorInfo ?? $e->getMessage()));
            return ['success' => false, 'error' => $mail->ErrorInfo ?? $e->getMessage()];
        }
    }

    // =========================================================================
    // SUBJECT LINE
    // =========================================================================

    public function buildSubject(ETicket $eticket): string
    {
        // Resolve type label from the linked transaction (if available)
        $typeLabel = $this->resolveTypeLabel($eticket);
        return sprintf(
            '%s — PNR: %s | %s | Lets Fly Travel',
            $typeLabel,
            $eticket->pnr,
            $eticket->customer_name
        );
    }

    /**
     * Resolve a human-readable type label from the linked Transaction.
     * Falls back gracefully if the transaction is not loaded or has no type.
     */
    private function resolveTypeLabel(ETicket $eticket): string
    {
        $typeMap = [
            'new_booking'      => 'Booking Confirmation',
            'exchange'         => 'Exchange Confirmation',
            'seat_purchase'    => 'Seat Purchase Confirmation',
            'cabin_upgrade'    => 'Cabin Upgrade Confirmation',
            'cancel_refund'    => 'Cancellation & Refund Notice',
            'cancel_credit'    => 'Cancellation & Credit Notice',
            'name_correction'  => 'Name Correction Confirmation',
            'other'            => 'Service Confirmation',
        ];
        // Try the eager-loaded relationship first (avoids extra query if loaded)
        $txn = $eticket->relationLoaded('transaction')
            ? $eticket->transaction
            : $eticket->transaction()->first();
        if ($txn && !empty($txn->type)) {
            return $typeMap[$txn->type] ?? 'Service Confirmation';
        }
        // Absolute fallback — generic
        return 'Booking Confirmation';
    }

    /**
     * Short type badge text for email header banner.
     */
    private function resolveTypeBadge(ETicket $eticket): string
    {
        $badgeMap = [
            'new_booking'      => '✈ ELECTRONIC TICKET',
            'exchange'         => '🔄 EXCHANGE / DATE CHANGE',
            'seat_purchase'    => '💺 SEAT PURCHASE',
            'cabin_upgrade'    => '⬆ CABIN UPGRADE',
            'cancel_refund'    => '✕ CANCELLATION & REFUND',
            'cancel_credit'    => '✕ CANCELLATION & CREDIT',
            'name_correction'  => '✎ NAME CORRECTION',
            'other'            => '📋 SERVICE CONFIRMATION',
        ];
        $txn = $eticket->relationLoaded('transaction')
            ? $eticket->transaction
            : $eticket->transaction()->first();
        if ($txn && !empty($txn->type)) {
            return $badgeMap[$txn->type] ?? '📋 CONFIRMATION';
        }
        return '✈ ELECTRONIC TICKET';
    }

    // =========================================================================
    // PLAIN TEXT
    // =========================================================================

    public function buildPlainText(ETicket $eticket, string $link): string
    {
        $paxList = implode(', ', array_map(fn($p) => $p['pax_name'] ?? '', $eticket->ticket_data ?? []));
        $ackUrl  = $eticket->acknowledgeUrl();
        $body  = "Dear {$eticket->customer_name},\n\n";
        $body .= "Your electronic travel ticket is ready. All details are below.\n\n";
        $body .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $body .= "BOOKING REFERENCE (PNR): {$eticket->pnr}\n";
        if ($paxList)           $body .= "Passenger(s): {$paxList}\n";
        if ($eticket->airline)  $body .= "Airline: {$eticket->airline}\n";
        if ($eticket->order_id) $body .= "Confirmation: {$eticket->order_id}\n";
        $body .= "Total Charged: {$eticket->currency} " . number_format($eticket->total_amount, 2) . "\n";
        $body .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        $body .= "TO ACKNOWLEDGE RECEIPT — CLICK THIS LINK:\n{$ackUrl}\n\n";
        $body .= "Clicking the link above confirms you have received your e-ticket. This is legally binding.\n\n";
        $body .= "View full e-ticket online:\n{$link}\n\n";
        $body .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $body .= self::SUPPORT_NAME . "\n";
        $body .= "Email: " . self::SUPPORT_EMAIL . "\n";
        $body .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        return $body;
    }

    // =========================================================================
    // HTML EMAIL — E-TICKET TO CUSTOMER
    // =========================================================================

    public function buildHtmlEmail(ETicket $eticket, string $link): string
    {
        $name     = htmlspecialchars($eticket->customer_name);
        $pnr      = htmlspecialchars($eticket->pnr);
        $airline  = htmlspecialchars($eticket->airline ?? '');
        $orderId  = htmlspecialchars($eticket->order_id ?? '');
        $currency = htmlspecialchars($eticket->currency);
        $total    = $currency . ' ' . number_format($eticket->total_amount, 2);
        $ackUrl   = htmlspecialchars($eticket->acknowledgeUrl());
        $viewUrl  = htmlspecialchars($link);
        $etId     = 'ET-' . str_pad($eticket->id, 6, '0', STR_PAD_LEFT);

        // ── Passenger rows ────────────────────────────────────────────────────
        $paxRows = '';
        foreach ($eticket->ticketDataWithAutoNumbers() as $i => $p) {
            $paxName  = htmlspecialchars($p['pax_name']      ?? '');
            $ticketNo = htmlspecialchars($p['ticket_number'] ?? '—');
            $seat     = htmlspecialchars($p['seat']          ?? '');
            $type     = ucfirst(htmlspecialchars($p['pax_type'] ?? 'adult'));
            $seatHtml = $seat
                ? "<span style='background:#f5f3ff;color:#6d28d9;font-weight:700;font-size:11px;padding:2px 8px;border-radius:4px;'>&#128186;&nbsp;{$seat}</span>"
                : '<span style="color:#94a3b8;">&#8212;</span>';
            $bg = $i % 2 === 0 ? '#ffffff' : '#f8fafc';
            $paxRows .= "<tr style='background:{$bg};'>
              <td style='padding:10px 12px;font-size:13px;font-weight:600;color:#1e293b;border-bottom:1px solid #f1f5f9;'>{$paxName}<br><span style='font-size:10px;color:#94a3b8;font-weight:400;'>{$type}</span></td>
              <td style='padding:10px 12px;font-size:12px;font-family:monospace;color:#1e40af;font-weight:700;border-bottom:1px solid #f1f5f9;'>{$ticketNo}</td>
              <td style='padding:10px 12px;border-bottom:1px solid #f1f5f9;'>{$seatHtml}</td>
            </tr>";
        }

        // ── Flight itinerary rows ─────────────────────────────────────────────
        $flightRows = '';
        $flightData = $eticket->flight_data ?? [];
        $segs = [];
        if (isset($flightData['flights']) && is_array($flightData['flights'])) {
            $segs = $flightData['flights'];
        } elseif (!empty($flightData) && is_array(reset($flightData))) {
            $segs = array_values($flightData);
        }
        foreach ($segs as $seg) {
            $from  = strtoupper($seg['from'] ?? $seg['departure_airport'] ?? '');
            $to    = strtoupper($seg['to']   ?? $seg['arrival_airport']   ?? '');
            if (!$from || !$to) continue;
            $iata  = strtoupper($seg['airline_iata'] ?? $seg['airline'] ?? '');
            $fltNo = htmlspecialchars($seg['flight_no']   ?? $seg['flight']         ?? '');
            $cabin = htmlspecialchars($seg['cabin_class'] ?? $seg['class']          ?? '');
            $date  = htmlspecialchars($seg['date']        ?? $seg['departure_date'] ?? '');
            $dep   = htmlspecialchars($seg['dep_time']    ?? $seg['time']           ?? '');
            $arr   = htmlspecialchars($seg['arr_time']    ?? $seg['arrival_time']   ?? '');
            $nd    = !empty($seg['arr_next_day']) ? " <span style='color:#e11d48;font-size:10px;font-weight:700;'>(+1d)</span>" : '';
            $logo  = $iata ? "<img src='https://www.gstatic.com/flights/airline_logos/70px/{$iata}.png' width='20' height='20' style='border-radius:3px;vertical-align:middle;margin-right:5px;'>" : '';
            $flightRows .= "<tr style='border-bottom:1px solid #f1f5f9;'>
              <td style='padding:10px 12px;font-size:13px;'>{$logo}<strong style='color:#0f1e3c;'>{$from}</strong>&nbsp;&#8594;&nbsp;<strong style='color:#0f1e3c;'>{$to}</strong></td>
              <td style='padding:10px 12px;font-size:11px;color:#64748b;font-family:monospace;'>{$fltNo}<br>{$cabin}</td>
              <td style='padding:10px 12px;font-size:11px;color:#475569;'>{$date}<br><span style='font-weight:700;'>{$dep}&nbsp;&#8594;&nbsp;{$arr}{$nd}</span></td>
            </tr>";
        }

        // ── Fare rows ─────────────────────────────────────────────────────────
        $fareRows = '';
        foreach ($eticket->fare_breakdown ?? [] as $item) {
            $label  = htmlspecialchars($item['label'] ?? $item['description'] ?? '');
            $amount = $currency . ' ' . number_format((float)($item['amount'] ?? 0), 2);
            $fareRows .= "<tr><td style='padding:6px 0;font-size:13px;color:#64748b;'>{$label}</td><td style='padding:6px 0;font-size:13px;color:#1e293b;text-align:right;font-family:monospace;'>{$amount}</td></tr>";
        }

        $itinSection = $flightRows ? "
      <div style='margin:0 0 24px;'>
        <div style='font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:1px;color:#94a3b8;margin-bottom:8px;padding-bottom:6px;border-bottom:1px solid #f1f5f9;'>&#9992; Flight Itinerary</div>
        <table style='width:100%;border-collapse:collapse;'><tbody>{$flightRows}</tbody></table>
      </div>" : '';

        $fareSection = $fareRows ? "
      <div style='margin:0 0 24px;'>
        <div style='font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:1px;color:#94a3b8;margin-bottom:8px;padding-bottom:6px;border-bottom:1px solid #f1f5f9;'>&#128176; Fare Summary</div>
        <table style='width:100%;border-collapse:collapse;'><tbody>{$fareRows}</tbody></table>
        <div style='display:flex;justify-content:space-between;padding:10px 0 0;border-top:2px solid #e2e8f0;margin-top:8px;'><span style='font-size:14px;font-weight:800;color:#065f46;'>Total Charged</span><span style='font-size:14px;font-weight:800;font-family:monospace;color:#065f46;'>{$total}</span></div>
      </div>" : "<div style='padding:0 0 24px;text-align:right;font-size:15px;font-weight:800;color:#065f46;'>Total: {$total}</div>";

        $orderLine = $orderId ? "<div style='font-size:11px;color:#94a3b8;margin-top:4px;'>Conf: {$orderId}</div>" : '';

        // ── Resolve type label + badge for this e-ticket ──────────────────
        $typeLabel = $this->resolveTypeLabel($eticket);
        $typeBadge = $this->resolveTypeBadge($eticket);

        // ── Resolve airline IATA from flight segments (most reliable) ────────
        $flightDataForIata = $eticket->flight_data ?? [];
        $allSegsForIata = array_merge(
            (array)($flightDataForIata['flights']     ?? []),
            (array)($flightDataForIata['old_flights'] ?? []),
            (array)($flightDataForIata['new_flights'] ?? [])
        );
        $headerIataCode = null;
        foreach ($allSegsForIata as $seg) {
            $cand = strtoupper(trim($seg['airline_iata'] ?? ''));
            if (preg_match('/^[A-Z0-9]{2,3}$/', $cand)) { $headerIataCode = $cand; break; }
        }
        // Fallback: airline field itself may be a code
        if (!$headerIataCode) {
            $airlineUpper = strtoupper(trim($eticket->airline ?? ''));
            if (preg_match('/^[A-Z0-9]{2,3}$/', $airlineUpper)) { $headerIataCode = $airlineUpper; }
        }
        // Resolve full name (so "AA" becomes "American Airlines")
        $iataFullNames = [
            'AC'=>'Air Canada','WS'=>'WestJet','TS'=>'Air Transat','PD'=>'Porter Airlines','WG'=>'Sunwing',
            'AA'=>'American Airlines','DL'=>'Delta Air Lines','UA'=>'United Airlines',
            'WN'=>'Southwest Airlines','B6'=>'JetBlue Airways','AS'=>'Alaska Airlines',
            'F9'=>'Frontier Airlines','NK'=>'Spirit Airlines','G4'=>'Allegiant Air','HA'=>'Hawaiian Airlines',
            'BA'=>'British Airways','LH'=>'Lufthansa','AF'=>'Air France','KL'=>'KLM',
            'LX'=>'Swiss International','OS'=>'Austrian Airlines','SN'=>'Brussels Airlines',
            'IB'=>'Iberia','VY'=>'Vueling','TP'=>'TAP Portugal','FR'=>'Ryanair',
            'U2'=>'easyJet','DY'=>'Norwegian Air','TK'=>'Turkish Airlines','LO'=>'LOT Polish Airlines',
            'EK'=>'Emirates','QR'=>'Qatar Airways','EY'=>'Etihad Airways',
            'FZ'=>'flydubai','G9'=>'Air Arabia','WY'=>'Oman Air','GF'=>'Gulf Air',
            'SQ'=>'Singapore Airlines','CX'=>'Cathay Pacific','JL'=>'Japan Airlines',
            'NH'=>'All Nippon Airways','KE'=>'Korean Air','OZ'=>'Asiana Airlines',
            'TG'=>'Thai Airways','MH'=>'Malaysia Airlines','SV'=>'Saudia',
            '6E'=>'IndiGo','SG'=>'SpiceJet','AI'=>'Air India','UK'=>'Vistara',
            'AM'=>'Aeromexico','LA'=>'LATAM Airlines','AV'=>'Avianca','CM'=>'Copa Airlines',
            'QF'=>'Qantas Airways','NZ'=>'Air New Zealand',
            'MU'=>'China Eastern','CA'=>'Air China','CZ'=>'China Southern',
            'ET'=>'Ethiopian Airlines','KQ'=>'Kenya Airways','AT'=>'Royal Air Maroc',
            'UL'=>'SriLankan Airlines','KU'=>'Kuwait Airways','MS'=>'EgyptAir',
        ];
        $airlineRaw = trim($eticket->airline ?? '');
        $airlineUpper2 = strtoupper($airlineRaw);
        if (preg_match('/^[A-Z0-9]{2,3}$/', $airlineUpper2) && isset($iataFullNames[$airlineUpper2])) {
            $headerAirlineName = $iataFullNames[$airlineUpper2];
        } else {
            $headerAirlineName = $airlineRaw ?: ($headerIataCode ? ($iataFullNames[$headerIataCode] ?? $headerIataCode) : '');
        }
        $headerAirlineName = htmlspecialchars($headerAirlineName);
        // Build logo + name block (table layout — Gmail safe, no flex)
        $headerAirlineHtml = '';
        if ($headerAirlineName) {
            $logoCell = $headerIataCode
                ? "<td style='padding:0 8px 0 0;vertical-align:middle;'><img src='https://www.gstatic.com/flights/airline_logos/70px/{$headerIataCode}.png' alt='{$headerAirlineName}' width='40' height='40' style='display:block;border-radius:6px;background:#fff;padding:2px;'></td>"
                : '';
            $headerAirlineHtml = "<div style='margin-top:14px;text-align:center;'>"
                . "<table cellpadding='0' cellspacing='0' border='0' style='display:inline-table;margin:0 auto;'><tr>"
                . $logoCell
                . "<td style='vertical-align:middle;'><span style='color:rgba(255,255,255,0.95);font-size:16px;font-weight:700;letter-spacing:0.5px;'>{$headerAirlineName}</span></td>"
                . "</tr></table></div>";
        }

        return <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Your E-Ticket &mdash; {$pnr}</title></head>
<body style="font-family:'Segoe UI',Arial,sans-serif;background:#f0f4f8;margin:0;padding:20px;color:#333;">
  <div style="max-width:600px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.08);">

    <!-- Header: Airline logo + name + type badge -->
    <div style="background:linear-gradient(135deg,#0f1e3c 0%,#1a3a6b 100%);padding:28px 30px;text-align:center;">
      {$headerAirlineHtml}
      <div style="margin-top:14px;border-top:1px solid rgba(255,255,255,.2);padding-top:12px;">
        <span style="color:#fff;font-size:12px;font-weight:700;letter-spacing:2px;text-transform:uppercase;">{$typeBadge}</span>
      </div>
    </div>

    <!-- Status Banner -->
    <div style="background:#d1fae5;border-bottom:2px solid #6ee7b7;padding:12px 30px;">
      <div style="font-size:13px;font-weight:800;color:#064e3b;">&#10003; {$typeLabel} &mdash; {$etId}</div>
      <div style="font-size:11px;color:#047857;margin-top:1px;">Your confirmation is ready. Please review the details below.</div>
    </div>

    <!-- Body -->
    <div style="padding:28px 30px;">
      <p style="color:#1e293b;font-size:15px;margin:0 0 20px;">Dear <strong>{$name}</strong>,</p>
      <p style="color:#555;font-size:13px;line-height:1.7;margin:0 0 24px;">
        Your {$typeLabel} is ready. All details are included below for your records.
      </p>

      <!-- PNR Card -->
      <div style="background:#f8fafc;border:1px solid #bae6fd;border-radius:10px;padding:16px 20px;margin:0 0 24px;">
        <table style="width:100%;border-collapse:collapse;">
          <tr>
            <td style="padding-right:20px;white-space:nowrap;border-right:1px solid #e2e8f0;">
              <div style="font-size:10px;text-transform:uppercase;letter-spacing:1px;color:#94a3b8;margin-bottom:4px;">Booking Ref (PNR)</div>
              <div style="font-size:26px;font-weight:800;font-family:monospace;color:#0f1e3c;letter-spacing:3px;">{$pnr}</div>
            </td>
            <td style="padding-left:20px;">
              <div style="font-size:10px;text-transform:uppercase;letter-spacing:1px;color:#94a3b8;margin-bottom:4px;">Airline</div>
              <div style="font-size:14px;font-weight:700;color:#1e293b;">{$airline}</div>
              {$orderLine}
            </td>
          </tr>
        </table>
      </div>

      <!-- Passengers -->
      <div style="margin:0 0 24px;">
        <div style="font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:1px;color:#94a3b8;margin-bottom:8px;padding-bottom:6px;border-bottom:1px solid #f1f5f9;">&#127903; Passengers &amp; Ticket Numbers</div>
        <table style="width:100%;border-collapse:collapse;">
          <thead><tr style="background:#f8fafc;">
            <th style="padding:8px 12px;text-align:left;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:#94a3b8;">Passenger</th>
            <th style="padding:8px 12px;text-align:left;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:#94a3b8;">E-Ticket #</th>
            <th style="padding:8px 12px;text-align:left;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:#94a3b8;">Seat</th>
          </tr></thead>
          <tbody>{$paxRows}</tbody>
        </table>
      </div>

      {$itinSection}
      {$fareSection}

    </div>

    <!-- Acknowledge Section -->
    <div style="background:linear-gradient(135deg,#0f1e3c,#1a3a6b);padding:32px 30px;text-align:center;">
      <div style="color:#fff;font-size:17px;font-weight:800;margin-bottom:8px;">Ready to Acknowledge?</div>
      <div style="color:rgba(255,255,255,.7);font-size:12px;line-height:1.7;margin-bottom:24px;">
        By clicking the button below, you confirm that you have received<br>your e-ticket and all details are correct.
      </div>
      <a href="{$ackUrl}"
         style="display:inline-block;background:linear-gradient(135deg,#c9a84c,#d4b86a);color:#0f1e3c;text-decoration:none;padding:18px 52px;border-radius:10px;font-weight:900;font-size:16px;letter-spacing:.3px;">
        &#10003; I Acknowledge Receipt of My E-Ticket
      </a>
      <div style="margin-top:14px;font-size:11px;color:rgba(255,255,255,.45);">
        This acknowledgment is legally binding and is recorded with your IP address &amp; timestamp.
      </div>
      <div style="margin-top:10px;">
        <a href="{$viewUrl}" style="color:rgba(255,255,255,.45);font-size:11px;text-decoration:none;">View full e-ticket online &rarr;</a>
      </div>
    </div>

    <!-- Footer -->
    <div style="background:#f8fafc;padding:16px 30px;text-align:center;font-size:11px;color:#94a3b8;border-top:1px solid #e2e8f0;">
      <strong style="color:#0f1e3c;">Lets Fly Travel DBA Base Fare</strong> &nbsp;&middot;&nbsp; reservation@base-fare.com<br>
      <span style="font-size:10px;">This is an official electronic travel document. Do not forward or share this email.</span>
    </div>

  </div>
</body>
</html>
HTML;
    }

    // =========================================================================
    // ACKNOWLEDGMENT NOTICE HTML (to reservation@base-fare.com)
    // =========================================================================


    public function buildAcknowledgmentHtml(ETicket $eticket): string
    {
        $name   = htmlspecialchars($eticket->customer_name);
        $email  = htmlspecialchars($eticket->customer_email);
        $pnr    = htmlspecialchars($eticket->pnr);
        $ackedAt = $eticket->acknowledged_at
            ? Carbon::parse($eticket->acknowledged_at)->format('F j, Y \a\t g:i:s A T')
            : Carbon::now()->format('F j, Y \a\t g:i:s A T');
        $ip     = htmlspecialchars($eticket->acknowledged_ip ?? 'N/A');
        $id     = 'ET-' . str_pad($eticket->id, 6, '0', STR_PAD_LEFT);

        return <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><title>E-Ticket Acknowledged</title></head>
<body style="font-family:'Segoe UI',Arial,sans-serif;background:#f0f4f8;margin:0;padding:20px;">
  <div style="max-width:520px;margin:0 auto;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 4px 16px rgba(0,0,0,0.08);">

    <div style="background:#065f46;padding:20px 28px;">
      <div style="color:#fff;font-size:16px;font-weight:800;">✅ E-Ticket Acknowledged</div>
      <div style="color:#6ee7b7;font-size:12px;margin-top:3px;">Customer has read and acknowledged their e-ticket.</div>
    </div>

    <div style="padding:24px 28px;">
      <table style="width:100%;border-collapse:collapse;font-size:13px;">
        <tr><td style="padding:8px 0;color:#94a3b8;width:140px;">E-Ticket ID</td><td style="padding:8px 0;font-weight:700;color:#0f1e3c;font-family:monospace;">{$id}</td></tr>
        <tr><td style="padding:8px 0;color:#94a3b8;">Customer</td><td style="padding:8px 0;font-weight:600;color:#1e293b;">{$name}</td></tr>
        <tr><td style="padding:8px 0;color:#94a3b8;">Email</td><td style="padding:8px 0;color:#1e293b;">{$email}</td></tr>
        <tr><td style="padding:8px 0;color:#94a3b8;">PNR</td><td style="padding:8px 0;font-weight:700;font-family:monospace;color:#0f1e3c;">{$pnr}</td></tr>
        <tr><td style="padding:8px 0;color:#94a3b8;">Acknowledged At</td><td style="padding:8px 0;font-weight:600;color:#065f46;">{$ackedAt}</td></tr>
        <tr><td style="padding:8px 0;color:#94a3b8;">IP Address</td><td style="padding:8px 0;font-family:monospace;font-size:12px;color:#475569;">{$ip}</td></tr>
      </table>

      <div style="margin-top:20px;background:#ecfdf5;border:1px solid #6ee7b7;border-radius:8px;padding:14px 16px;font-size:12px;color:#064e3b;line-height:1.7;">
        This acknowledgment constitutes legal proof that the customer has received and read their e-ticket. This record, combined with the original signed authorization, provides comprehensive evidence for chargeback defense.
      </div>
    </div>

    <div style="background:#f8fafc;padding:12px 28px;text-align:center;font-size:10px;color:#94a3b8;border-top:1px solid #e2e8f0;">
      Lets Fly Travel DBA Base Fare — Internal CRM Notification
    </div>
  </div>
</body>
</html>
HTML;
    }

    public function buildAcknowledgmentPlain(ETicket $eticket): string
    {
        $id = 'ET-' . str_pad($eticket->id, 6, '0', STR_PAD_LEFT);
        $body  = "E-TICKET ACKNOWLEDGED\n";
        $body .= "=====================\n\n";
        $body .= "E-Ticket ID: {$id}\n";
        $body .= "Customer:    {$eticket->customer_name}\n";
        $body .= "Email:       {$eticket->customer_email}\n";
        $body .= "PNR:         {$eticket->pnr}\n";
        $body .= "IP Address:  " . ($eticket->acknowledged_ip ?? 'N/A') . "\n";
        $body .= "Acknowledged: " . ($eticket->acknowledged_at ?? Carbon::now()->format('Y-m-d H:i:s')) . "\n\n";
        $body .= "This is an internal CRM notification.\n";
        return $body;
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    private function passengerTableHtml(ETicket $eticket): string
    {
        $rows = '';
        foreach ($eticket->ticket_data ?? [] as $p) {
            $paxName  = htmlspecialchars($p['pax_name'] ?? '');
            $ticketNo = htmlspecialchars($p['ticket_number'] ?? '—');
            $seat     = htmlspecialchars($p['seat'] ?? '—');
            $rows .= "<tr>
              <td style='padding:8px 12px;font-size:13px;color:#374151;border-bottom:1px solid #f1f5f9;'>{$paxName}</td>
              <td style='padding:8px 12px;font-size:12px;font-family:monospace;color:#1e40af;font-weight:700;border-bottom:1px solid #f1f5f9;'>{$ticketNo}</td>
              <td style='padding:8px 12px;font-size:12px;color:#6366f1;font-weight:600;border-bottom:1px solid #f1f5f9;'>{$seat}</td>
            </tr>";
        }
        if (!$rows) return '';
        return <<<HTML
<div style="border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;margin-bottom:24px;">
  <table style="width:100%;border-collapse:collapse;">
    <thead>
      <tr style="background:#f8fafc;">
        <th style="padding:8px 12px;text-align:left;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#94a3b8;">Passenger</th>
        <th style="padding:8px 12px;text-align:left;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#94a3b8;">Ticket Number</th>
        <th style="padding:8px 12px;text-align:left;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#94a3b8;">Seat</th>
      </tr>
    </thead>
    <tbody>{$rows}</tbody>
  </table>
</div>
HTML;
    }

    private function supportEmail(): string
    {
        return self::SUPPORT_EMAIL;
    }

    private function log(ETicket $eticket, string $subject, string $recipient, string $status): void
    {
        $logDir = __DIR__ . '/../../storage/etickets/';
        if (!is_dir($logDir)) mkdir($logDir, 0755, true);

        $entry = sprintf(
            "[%s] %s | ET-ID:%d | TO:%s | PNR:%s | SUBJ:%s\n",
            Carbon::now()->format('Y-m-d H:i:s'),
            $status, $eticket->id, $recipient, $eticket->pnr, $subject
        );

        file_put_contents($logDir . 'email_log.txt', $entry, FILE_APPEND | LOCK_EX);
    }
}
