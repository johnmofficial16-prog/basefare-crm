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
            '%s — PNR: %s | %s',
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

        // ── Reply-style mailto for Contact Us button ───────────────────────────
        // Uses the exact same subject as the outgoing email with "Re:" prefix,
        // no body pre-fill — opens clean like a native email reply dialog.
        $mailSubject = rawurlencode('Re: ' . $this->buildSubject($eticket));
        $mailtoHref  = "mailto:reservation@base-fare.com?subject={$mailSubject}";

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

        // ── City name lookup ──────────────────────────────────────────────────
        $cityNames = [
            'YYZ'=>'Toronto','YVR'=>'Vancouver','YUL'=>'Montreal','YYC'=>'Calgary','YEG'=>'Edmonton','YOW'=>'Ottawa',
            'LHR'=>'London Heathrow','LGW'=>'London Gatwick','CDG'=>'Paris CDG','ORY'=>'Paris Orly',
            'FRA'=>'Frankfurt','MUC'=>'Munich','AMS'=>'Amsterdam','MAD'=>'Madrid','BCN'=>'Barcelona',
            'FCO'=>'Rome','MXP'=>'Milan','ZRH'=>'Zurich','VIE'=>'Vienna','BRU'=>'Brussels',
            'IST'=>'Istanbul','DXB'=>'Dubai','DOH'=>'Doha','AUH'=>'Abu Dhabi','MCT'=>'Muscat','BAH'=>'Bahrain',
            'BOM'=>'Mumbai','DEL'=>'Delhi','BLR'=>'Bangalore','MAA'=>'Chennai','HYD'=>'Hyderabad','CCU'=>'Kolkata','COK'=>'Kochi',
            'JFK'=>'New York JFK','EWR'=>'Newark','LAX'=>'Los Angeles','SFO'=>'San Francisco',
            'ORD'=>'Chicago','MIA'=>'Miami','DFW'=>'Dallas','SEA'=>'Seattle','BOS'=>'Boston',
            'ATL'=>'Atlanta','DEN'=>'Denver','IAH'=>'Houston','PHX'=>'Phoenix','LAS'=>'Las Vegas',
            'SIN'=>'Singapore','HKG'=>'Hong Kong','BKK'=>'Bangkok','KUL'=>'Kuala Lumpur',
            'NRT'=>'Tokyo Narita','HND'=>'Tokyo Haneda','ICN'=>'Seoul','PVG'=>'Shanghai',
            'SYD'=>'Sydney','MEL'=>'Melbourne','AKL'=>'Auckland',
            'JNB'=>'Johannesburg','NBO'=>'Nairobi','CMN'=>'Casablanca','CAI'=>'Cairo','ADD'=>'Addis Ababa',
        ];

        $flightRows = '';
        $flightData = $eticket->flight_data ?? [];
        $segs = [];
        if (isset($flightData['flights']) && is_array($flightData['flights'])) {
            $segs = $flightData['flights'];
        } elseif (!empty($flightData) && is_array(reset($flightData))) {
            $segs = array_values($flightData);
        }
        $segs = array_values(array_filter($segs, fn($s) =>
            (!empty($s['from']) || !empty($s['departure_airport'])) &&
            (!empty($s['to'])   || !empty($s['arrival_airport']))
        ));
        $segCount = count($segs);
        foreach ($segs as $idx => $seg) {
            $from    = strtoupper($seg['from']   ?? $seg['departure_airport'] ?? '');
            $to      = strtoupper($seg['to']     ?? $seg['arrival_airport']   ?? '');
            $iata    = strtoupper($seg['airline_iata'] ?? $seg['airline']     ?? '');
            $fltNo   = htmlspecialchars($seg['flight_no']   ?? $seg['flight']         ?? '');
            $cabin   = htmlspecialchars($seg['cabin_class'] ?? $seg['class']          ?? '');
            $date    = htmlspecialchars($seg['date']        ?? $seg['departure_date'] ?? '');
            $arrDate = htmlspecialchars($seg['arrival_date'] ?? '');
            $dep     = htmlspecialchars($seg['dep_time']    ?? $seg['time']           ?? '');
            $arr     = htmlspecialchars($seg['arr_time']    ?? $seg['arrival_time']   ?? '');
            $nd      = !empty($seg['arr_next_day']) || ($arrDate && $arrDate !== $date);
            $ndBadge = $nd ? "<span style='background:#fee2e2;color:#b91c1c;font-size:9px;font-weight:700;padding:1px 5px;border-radius:3px;margin-left:4px;'>+1d</span>" : '';
            $fromCity = htmlspecialchars($cityNames[$from] ?? '');
            $toCity   = htmlspecialchars($cityNames[$to]   ?? '');
            $logoTag  = $iata ? "<img src='https://www.gstatic.com/flights/airline_logos/70px/{$iata}.png' width='18' height='18' style='border-radius:3px;vertical-align:middle;margin-right:5px;' onerror=\"this.style.display='none'\">" : '';

            $flightRows .= "<tr style='border-bottom:1px solid #f1f5f9;'>"
                . "<td style='padding:10px 12px;font-size:13px;'>"
                .   "<div style='font-weight:800;color:#0f1e3c;'>{$from}</div>"
                .   ($fromCity ? "<div style='font-size:10px;color:#94a3b8;'>{$fromCity}</div>" : '')
                . "</td>"
                . "<td style='padding:10px 8px;text-align:center;font-size:11px;color:#64748b;'>"
                .   "{$logoTag}<span style='font-family:monospace;font-weight:700;'>{$fltNo}</span>"
                .   ($cabin ? "<br><span style='font-size:9px;background:#f1f5f9;color:#475569;padding:1px 4px;border-radius:3px;'>{$cabin}</span>" : '')
                .   "<br><span style='font-size:9px;color:#94a3b8;'>{$date}</span>"
                . "</td>"
                . "<td style='padding:10px 8px;text-align:center;font-size:11px;color:#64748b;'>"
                .   ($dep ? "<strong style='color:#1e293b;'>" . $dep . "</strong> &rarr; <strong style='color:#1e293b;'>" . $arr . "</strong>" . $ndBadge : '&mdash;')
                . "</td>"
                . "<td style='padding:10px 12px;font-size:13px;text-align:right;'>"
                .   "<div style='font-weight:800;color:#0f1e3c;'>{$to}</div>"
                .   ($toCity ? "<div style='font-size:10px;color:#94a3b8;'>{$toCity}</div>" : '')
                . "</td>"
                . "</tr>";

            // Layover indicator
            if ($idx < $segCount - 1) {
                $nextSeg  = $segs[$idx + 1];
                $arrT = $arr;
                $depT = htmlspecialchars($nextSeg['dep_time'] ?? $nextSeg['time'] ?? $nextSeg['departure_time'] ?? '');
                
                $nextDate = htmlspecialchars($nextSeg['date'] ?? $nextSeg['departure_date'] ?? '');
                $sameDay  = (trim($date) !== '' && trim($nextDate) !== '' && trim($date) === trim($nextDate));
                $isReturn = !$sameDay;

                $layStr   = 'Connection in ' . ($cityNames[$to] ?? $to);
                $layColor = '#fffbeb'; $layBorder = '#fde68a'; $layText = '#92400e';
                
                if ($arrT && $depT && strpos($arrT,':') !== false && strpos($depT,':') !== false) {
                    [$ah,$am] = array_map('intval', explode(':',$arrT));
                    [$dh,$dm] = array_map('intval', explode(':',$depT));
                    $arrM = $ah*60+$am + ($nd ? 1440 : 0);
                    
                    $months = ['JAN'=>0,'FEB'=>1,'MAR'=>2,'APR'=>3,'MAY'=>4,'JUN'=>5,'JUL'=>6,'AUG'=>7,'SEP'=>8,'OCT'=>9,'NOV'=>10,'DEC'=>11];
                    $dateDelta = 0;
                    if (strlen($date) >= 5 && strlen($nextDate) >= 5) {
                        $md1 = $months[strtoupper(substr($date,2,3))] ?? null;
                        $md2 = $months[strtoupper(substr($nextDate,2,3))] ?? null;
                        if ($md1 !== null && $md2 !== null) {
                            $y1 = (int)date('Y'); $y2 = $y1;
                            if ($md2 < $md1) $y2++;
                            $d1 = mktime(0,0,0,$md1+1,intval($date),$y1);
                            $d2 = mktime(0,0,0,$md2+1,intval($nextDate),$y2);
                            $dateDelta = (int)round(($d2 - $d1) / 86400);
                        }
                    }
                    
                    $depM = $dh * 60 + $dm + $dateDelta * 1440;
                    $layMins = $depM - $arrM;
                    
                    $nextFrom = strtoupper($nextSeg['from'] ?? $nextSeg['departure_airport'] ?? '');
                    
                    if ($layMins < 0 || $layMins >= 1440 || ($to !== $nextFrom && $nextFrom !== '')) {
                        $isReturn = true;
                    } else {
                        $isReturn = false;
                        if ($layMins < 45) {
                            $h=intdiv($layMins,60);$m=$layMins%60;
                            $layStr = ($h?"{$h}h ":'') . "{$m}m connection ⚠ Very tight — " . ($cityNames[$to] ?? $to);
                            $layColor='#fff7ed';$layBorder='#fdba74';$layText='#c2410c';
                        } else {
                            $h=intdiv($layMins,60);$m=$layMins%60;
                            $layStr = ($h?"{$h}h ":'') . ($m?"{$m}m ":'') . 'connection — ' . ($cityNames[$to] ?? $to);
                        }
                    }
                }
                
                if ($isReturn) {
                    $flightRows .= "<tr style='background:#eff6ff;'>"
                        . "<td colspan='4' style='padding:6px 12px;font-size:10px;font-weight:700;color:#1e40af;border-bottom:1px solid #bfdbfe;border-top:1px solid #bfdbfe;'>"
                        . "✈&nbsp; Return Leg — {$nextDate}"
                        . "</td></tr>";
                } else {
                    $flightRows .= "<tr style='background:{$layColor};'>"
                        . "<td colspan='4' style='padding:6px 12px;font-size:10px;font-weight:700;color:{$layText};border-bottom:1px solid {$layBorder};border-top:1px solid {$layBorder};'>"
                        . "✈&nbsp; {$layStr}"
                        . "</td></tr>";
                }
            }
        }

        $itinSection = $flightRows ? "
      <div style='margin:0 0 24px;'>
        <div style='font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:1px;color:#94a3b8;margin-bottom:8px;padding-bottom:6px;border-bottom:1px solid #f1f5f9;'>&#9992; Flight Itinerary</div>
        <table style='width:100%;border-collapse:collapse;border:1px solid #f1f5f9;border-radius:8px;overflow:hidden;'><thead><tr style='background:#f8fafc;'>
          <th style='padding:8px 12px;text-align:left;font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:#94a3b8;'>From</th>
          <th style='padding:8px 8px;text-align:center;font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:#94a3b8;'>Flight</th>
          <th style='padding:8px 8px;text-align:center;font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:#94a3b8;'>Times</th>
          <th style='padding:8px 12px;text-align:right;font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:#94a3b8;'>To</th>
        </tr></thead><tbody>{$flightRows}</tbody></table>
      </div>" : '';

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
        <table style='width:100%;border-collapse:collapse;margin-top:8px;border-top:2px solid #e2e8f0;'><tr>
          <td style='padding:10px 0 0;font-size:14px;font-weight:800;color:#065f46;'>Total Charged</td>
          <td style='padding:10px 0 0;font-size:14px;font-weight:800;font-family:monospace;color:#065f46;text-align:right;'>{$total}</td>
        </tr></table>
      </div>" : "<div style='padding:0 0 24px;text-align:right;font-size:15px;font-weight:800;color:#065f46;'>Total: {$total}</div>";

        // ── Ticket Conditions (endorsements, baggage, fare rules, policy) ───
        $condParts = [];
        if (!empty($eticket->endorsements)) {
            $condParts[] = "<div style='margin-bottom:12px;'><div style='font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:1px;color:#94a3b8;margin-bottom:4px;'>Endorsements</div><div style='font-size:12px;font-family:monospace;color:#334155;white-space:pre-line;'>" . htmlspecialchars($eticket->endorsements) . "</div></div>";
        }
        if (!empty($eticket->baggage_info)) {
            $condParts[] = "<div style='margin-bottom:12px;'><div style='font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:1px;color:#94a3b8;margin-bottom:4px;'>Baggage Allowance</div><div style='font-size:12px;color:#334155;white-space:pre-line;'>" . htmlspecialchars($eticket->baggage_info) . "</div></div>";
        }
        if (!empty($eticket->fare_rules)) {
            $condParts[] = "<div style='margin-bottom:12px;'><div style='font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:1px;color:#94a3b8;margin-bottom:4px;'>Fare Rules</div><div style='font-size:12px;color:#334155;white-space:pre-line;'>" . htmlspecialchars($eticket->fare_rules) . "</div></div>";
        }
        if (!empty($eticket->policy_text)) {
            $condParts[] = "<div><div style='font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:1px;color:#94a3b8;margin-bottom:4px;'>Policy</div><div style='font-size:12px;color:#334155;white-space:pre-line;'>" . htmlspecialchars($eticket->policy_text) . "</div></div>";
        }
        $condSection = !empty($condParts) ? "
      <div style='margin:0 0 24px;background:#fafafa;border:1px solid #e2e8f0;border-radius:8px;padding:16px 18px;'>
        <div style='font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:1px;color:#94a3b8;margin-bottom:12px;padding-bottom:6px;border-bottom:1px solid #f1f5f9;'>&#128203; Ticket Conditions</div>"
            . implode('', $condParts) . "
      </div>" : '';

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
      {$condSection}

    </div>

    <!-- Closing Note -->
    <div style="background:linear-gradient(135deg,#0f1e3c,#1a3a6b);padding:28px 30px;text-align:center;">
      <div style="color:#c9a84c;font-size:11px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;margin-bottom:10px;">&#10003; Your Documents Are Ready</div>
      <div style="color:rgba(255,255,255,0.85);font-size:13px;line-height:1.8;margin-bottom:20px;">
        Please review all details carefully. If you have any questions or notice<br>any discrepancies, contact us immediately.
      </div>
      <a href="{$mailtoHref}"
         style="display:inline-block;background:rgba(255,255,255,0.12);border:1px solid rgba(255,255,255,0.25);color:#fff;text-decoration:none;padding:11px 32px;border-radius:8px;font-weight:700;font-size:13px;letter-spacing:0.3px;">
        &#9993;&nbsp; Contact Us
      </a>
    </div>

    <!-- Footer -->
    <div style="background:#f8fafc;padding:18px 30px;text-align:center;font-size:11px;color:#94a3b8;border-top:1px solid #e2e8f0;">
      <strong style="color:#0f1e3c;">Reservation Desk</strong> &nbsp;&middot;&nbsp; reservation@base-fare.com<br>
      <span style="font-size:10px;">This is an official travel confirmation. Please retain this email for your records.</span>
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
