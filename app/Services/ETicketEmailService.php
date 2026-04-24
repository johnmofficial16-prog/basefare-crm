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
    const SUPPORT_NAME  = 'Lets Fly Travel DBA Base Fare';

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

        $fromName  = $_ENV['SMTP_FROM_NAME'] ?? self::SUPPORT_NAME;
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
        return sprintf(
            'Your E-Ticket — PNR: %s | %s | Lets Fly Travel',
            $eticket->pnr,
            $eticket->customer_name
        );
    }

    // =========================================================================
    // PLAIN TEXT
    // =========================================================================

    public function buildPlainText(ETicket $eticket, string $link): string
    {
        $paxList = implode(', ', array_map(fn($p) => $p['pax_name'] ?? '', $eticket->ticket_data ?? []));
        $body  = "Dear {$eticket->customer_name},\n\n";
        $body .= "Your electronic travel ticket is ready. Please review and acknowledge receipt by clicking the link below.\n\n";
        $body .= "Booking Reference (PNR): {$eticket->pnr}\n";
        if ($paxList) $body .= "Passenger(s): {$paxList}\n";
        $body .= "Airline: {$eticket->airline}\n";
        $body .= "Total Amount: {$eticket->currency} " . number_format($eticket->total_amount, 2) . "\n\n";
        $body .= "View & Acknowledge E-Ticket:\n{$link}\n\n";
        $body .= "By clicking the acknowledgment button on that page, you confirm receipt of your e-ticket.\n\n";
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
        $name      = htmlspecialchars($eticket->customer_name);
        $pnr       = htmlspecialchars($eticket->pnr);
        $airline   = htmlspecialchars($eticket->airline ?? '');
        $total     = htmlspecialchars($eticket->currency) . ' ' . number_format($eticket->total_amount, 2);
        $safeLink  = htmlspecialchars($link);

        $paxRows = '';
        foreach ($eticket->ticket_data ?? [] as $p) {
            $paxName  = htmlspecialchars($p['pax_name'] ?? '');
            $ticketNo = htmlspecialchars($p['ticket_number'] ?? '');
            $seat     = htmlspecialchars($p['seat'] ?? '');
            $paxRows .= "<tr>
              <td style='padding:8px 12px;font-size:13px;color:#374151;border-bottom:1px solid #f1f5f9;'>{$paxName}</td>
              <td style='padding:8px 12px;font-size:12px;font-family:monospace;color:#1e40af;font-weight:700;border-bottom:1px solid #f1f5f9;'>{$ticketNo}</td>
              <td style='padding:8px 12px;font-size:12px;color:#6366f1;font-weight:600;border-bottom:1px solid #f1f5f9;'>{$seat}</td>
            </tr>";
        }

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Your E-Ticket — {$pnr}</title>
</head>
<body style="font-family:'Segoe UI',Arial,sans-serif;background:#f0f4f8;margin:0;padding:20px;color:#333;">
  <div style="max-width:560px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,0.08);">

    <!-- Header -->
    <div style="background:linear-gradient(135deg,#0f1e3c 0%,#1a3a6b 100%);padding:28px 30px;text-align:center;">
      <div style="color:#fff;font-size:22px;font-weight:800;letter-spacing:1px;">LETS FLY TRAVEL</div>
      <div style="color:#c9a84c;font-size:11px;font-weight:600;margin-top:3px;letter-spacing:0.5px;">DBA BASE FARE</div>
      <div style="margin-top:14px;border-top:1px solid rgba(255,255,255,0.2);padding-top:12px;">
        <span style="color:#fff;font-size:13px;font-weight:700;letter-spacing:2px;text-transform:uppercase;">✈ ELECTRONIC TICKET</span>
      </div>
    </div>

    <!-- Status Banner -->
    <div style="background:#d1fae5;border-bottom:2px solid #6ee7b7;padding:12px 30px;display:flex;align-items:center;gap:10px;">
      <span style="font-size:20px;">✅</span>
      <div>
        <div style="font-size:13px;font-weight:800;color:#064e3b;">E-Ticket Ready</div>
        <div style="font-size:11px;color:#047857;margin-top:1px;">Your booking is confirmed. Please review and acknowledge below.</div>
      </div>
    </div>

    <!-- Body -->
    <div style="padding:28px 30px;">
      <p style="color:#1e293b;font-size:15px;margin:0 0 20px;">Dear <strong>{$name}</strong>,</p>
      <p style="color:#555;font-size:14px;line-height:1.7;margin:0 0 24px;">
        Your electronic ticket is ready. Please click the button below to view your full itinerary, ticket numbers, and to acknowledge receipt.
      </p>

      <!-- Booking card -->
      <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:16px 20px;margin:0 0 24px;">
        <table style="width:100%;border-collapse:collapse;">
          <tr>
            <td style="padding-right:16px;white-space:nowrap;">
              <div style="font-size:10px;text-transform:uppercase;letter-spacing:1px;color:#94a3b8;margin-bottom:4px;">Booking Ref (PNR)</div>
              <div style="font-size:22px;font-weight:800;font-family:monospace;color:#0f1e3c;letter-spacing:3px;">{$pnr}</div>
            </td>
            <td style="border-left:1px solid #e2e8f0;padding-left:16px;">
              <div style="font-size:10px;text-transform:uppercase;letter-spacing:1px;color:#94a3b8;margin-bottom:4px;">Airline</div>
              <div style="font-size:14px;font-weight:700;color:#1e293b;">{$airline}</div>
              <div style="font-size:11px;color:#94a3b8;margin-top:4px;">{$total}</div>
            </td>
          </tr>
        </table>
      </div>

      <!-- Passenger / Ticket # table -->
      {$this->passengerTableHtml($eticket)}

      <!-- CTA Button -->
      <div style="text-align:center;margin:28px 0;">
        <a href="{$safeLink}"
           style="display:inline-block;background:linear-gradient(135deg,#0f1e3c,#1a3a6b);color:#fff;text-decoration:none;padding:18px 56px;border-radius:10px;font-weight:800;font-size:16px;letter-spacing:0.5px;">
          View & Acknowledge E-Ticket &rarr;
        </a>
      </div>

      <div style="background:#fffbeb;border:1px solid #fcd34d;border-radius:8px;padding:12px 16px;text-align:center;">
        <span style="font-size:13px;color:#92400e;">⚠ Please review all details carefully before acknowledging. Your acknowledgment is legally binding.</span>
      </div>
    </div>

    <!-- Footer -->
    <div style="background:#f8fafc;padding:16px 30px;text-align:center;font-size:11px;color:#94a3b8;border-top:1px solid #e2e8f0;">
      <strong style="color:#0f1e3c;">Lets Fly Travel DBA Base Fare</strong> &nbsp;&middot;&nbsp; {$this->supportEmail()}<br>
      <span style="font-size:10px;">This is an official e-ticket document. Do not forward or share this email.</span>
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
