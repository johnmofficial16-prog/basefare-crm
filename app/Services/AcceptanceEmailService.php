<?php

namespace App\Services;

use App\Models\AcceptanceRequest;
use Carbon\Carbon;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * AcceptanceEmailService
 *
 * Sends authorization requests using PHPMailer via Google Workspace SMTP.
 */
class AcceptanceEmailService
{
    // =========================================================================
    // MAILER SETUP
    // =========================================================================

    private function getMailer(): PHPMailer
    {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->CharSet    = 'UTF-8';  // Prevent em-dash / unicode corruption in subject
        $mail->Host       = $_ENV['SMTP_HOST'] ?? 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = $_ENV['SMTP_USER'] ?? '';
        $mail->Password   = $_ENV['SMTP_PASS'] ?? '';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = $_ENV['SMTP_PORT'] ?? 587;
        
        $fromName  = $_ENV['SMTP_FROM_NAME'] ?? 'Lets Fly Travel DBA Base Fare';
        $fromEmail = $_ENV['SMTP_FROM'] ?? $_ENV['SMTP_USER'] ?? '';
        
        if ($fromEmail) {
            $mail->setFrom($fromEmail, $fromName);
        }

        return $mail;
    }

    // =========================================================================
    // SEND
    // =========================================================================

    public function send(AcceptanceRequest $acceptance): array
    {
        $subject = $this->buildSubject($acceptance);
        $link    = $acceptance->publicUrl();

        try {
            $mail = $this->getMailer();
            $mail->addAddress($acceptance->customer_email, $acceptance->customer_name);
            
            // Add Agent CC if requested in the future, or generic bcc
            // $mail->addBCC('booking@base-fare.com');

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $this->buildHtmlEmail($acceptance);
            $mail->AltBody = $this->buildPlainText($acceptance);

            $mail->send();
            
            $this->logEmail($acceptance, $subject, $link, 'SENT_OK');

            $acceptance->increment('email_attempts');
            $acceptance->update([
                'email_status'    => AcceptanceRequest::EMAIL_SENT,
                'last_emailed_at' => Carbon::now(),
            ]);

            return ['success' => true, 'link' => $link];

        } catch (Exception $e) {
            $this->logEmail($acceptance, $subject, $link, 'ERROR: ' . $mail->ErrorInfo);
            return ['success' => false, 'error' => $mail->ErrorInfo];
        }
    }

    // =========================================================================
    // SEND CONFIRMATION
    // =========================================================================

    public function sendConfirmation(AcceptanceRequest $acceptance): array
    {
        $subject = 'Authorization Confirmed — ' . $acceptance->typeLabel() . ' | Lets Fly Travel';
        $link    = $acceptance->publicUrl();

        // Stubbed for now as requested. Can wire PHPMailer here later if needed.
        $this->logEmail($acceptance, $subject, $link, 'CONFIRMATION-STUB');

        return ['success' => true];
    }

    // =========================================================================
    // SUBJECT LINE
    // =========================================================================

    public function buildSubject(AcceptanceRequest $acceptance): string
    {
        return sprintf(
            'Authorization Required — %s | PNR: %s | Lets Fly Travel',
            $acceptance->typeLabel(),
            $acceptance->pnr
        );
    }

    // =========================================================================
    // PLAIN TEXT BODY
    // =========================================================================

    public function buildPlainText(AcceptanceRequest $acceptance): string
    {
        $link       = $acceptance->publicUrl();
        $typeAction = $acceptance->typeActionLabel();
        $expiry     = $acceptance->expires_at->format('F j, Y \a\t g:i A T');
        $paxList    = implode(', ', array_map(fn($p) => $p['name'] ?? '', $acceptance->passengers ?? []));

        $extraData    = $acceptance->extra_data ?? [];
        $seatNumber   = $extraData['seat_number']      ?? '';
        $seatAssigns  = $extraData['seat_assignments'] ?? [];

        $body  = "Hello {$acceptance->customer_name},\n\n";
        $body .= "To proceed with your {$typeAction}, kindly click the link below to review and authorize your booking.\n\n";
        $body .= "Booking Reference (PNR): {$acceptance->pnr}\n";
        if ($paxList) {
            $body .= "Passenger(s): {$paxList}\n";
        }
        if (!empty($seatAssigns)) {
            $body .= "Seat Assignments:\n";
            foreach ($seatAssigns as $sa) {
                $paxName = $sa['passenger'] ?? '';
                $seatVal = $sa['seat']      ?? '—';
                $body .= "  • {$paxName}: Seat {$seatVal}\n";
            }
        } elseif ($seatNumber) {
            $body .= "Seat Number(s): {$seatNumber}\n";
        }
        $body .= "\nAuthorization Link:\n{$link}\n\n";
        $body .= "This link expires on {$expiry}.\n\n";
        $body .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $body .= "Lets Fly Travel DBA Base Fare\n";
        $body .= "Email: reservation@base-fare.com\n";
        $body .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

        return $body;
    }

    // =========================================================================
    // HTML EMAIL BODY — lean, action-focused
    // =========================================================================

    public function buildHtmlEmail(AcceptanceRequest $acceptance): string
    {
        $link         = htmlspecialchars($acceptance->publicUrl());
        $typeLabel    = htmlspecialchars($acceptance->typeLabel());
        $typeAction   = htmlspecialchars($acceptance->typeActionLabel());
        $customerName = htmlspecialchars($acceptance->customer_name);
        $pnr          = htmlspecialchars($acceptance->pnr);
        $expiry       = $acceptance->expires_at->format('F j, Y \a\t g:i A');

        $passengerRows = '';
        foreach ($acceptance->passengers ?? [] as $p) {
            $name = htmlspecialchars($p['name'] ?? '');
            if ($name) {
                $passengerRows .= "<li style='padding:2px 0; font-size:13px; color:#374151;'>&#8226; {$name}</li>";
            }
        }

        // Seat data
        $extraData    = $acceptance->extra_data ?? [];
        $seatNumber   = $extraData['seat_number']      ?? '';
        $seatAssigns  = $extraData['seat_assignments'] ?? [];
        $seatCardHtml = '';
        if (!empty($seatAssigns)) {
            $rows = '';
            foreach ($seatAssigns as $sa) {
                $paxHtml  = htmlspecialchars($sa['passenger'] ?? '');
                $seatHtml = htmlspecialchars($sa['seat']      ?? '—');
                $rows .= "<tr><td style='padding:4px 8px; font-size:12px; font-family:monospace; color:#312e81; background:#ede9fe; border-radius:4px; white-space:nowrap;'>{$paxHtml}</td>"
                       . "<td style='padding:4px 8px 4px 14px; font-size:14px; font-weight:800; font-family:monospace; color:#1e1b4b;'>&#128186; {$seatHtml}</td></tr>";
            }
            $seatCardHtml = "<div style='background:#f5f3ff; border:1px solid #c4b5fd; border-radius:8px; padding:12px 16px; margin:0 0 20px;'>"
                          . "<div style='font-size:10px; text-transform:uppercase; letter-spacing:1px; color:#7c3aed; margin-bottom:8px; font-weight:700;'>&#9992; Seat Assignments</div>"
                          . "<table style='border-collapse:separate; border-spacing:4px;'>{$rows}</table>"
                          . "</div>";
        } elseif ($seatNumber) {
            $seatHtml     = htmlspecialchars($seatNumber);
            $seatCardHtml = "<div style='background:#f5f3ff; border:1px solid #c4b5fd; border-radius:8px; padding:12px 16px; margin:0 0 20px;'>"
                          . "<div style='font-size:10px; text-transform:uppercase; letter-spacing:1px; color:#7c3aed; margin-bottom:4px; font-weight:700;'>&#9992; Seat Number(s)</div>"
                          . "<div style='font-size:16px; font-weight:800; font-family:monospace; color:#1e1b4b;'>&#128186; {$seatHtml}</div>"
                          . "</div>";
        }

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Authorization Required &mdash; {$pnr}</title>
</head>
<body style="font-family:'Segoe UI',Arial,sans-serif; background:#f0f4f8; margin:0; padding:20px; color:#333;">
  <div style="max-width:520px; margin:0 auto; background:#fff; border-radius:10px; overflow:hidden; box-shadow:0 4px 20px rgba(0,0,0,0.08);">

    <!-- Header -->
    <div style="background:linear-gradient(135deg,#0f1e3c 0%,#1a3a6b 100%); padding:26px 30px; text-align:center;">
      <div style="color:#fff; font-size:21px; font-weight:800; letter-spacing:1px;">LETS FLY TRAVEL</div>
      <div style="color:#c9a84c; font-size:11px; font-weight:600; margin-top:3px; letter-spacing:0.5px;">DBA BASE FARE</div>
      <div style="margin-top:14px; border-top:1px solid rgba(255,255,255,0.2); padding-top:12px;">
        <span style="color:#fff; font-size:13px; font-weight:600; letter-spacing:2px; text-transform:uppercase;">{$typeLabel}</span>
      </div>
    </div>

    <!-- Body -->
    <div style="padding:30px 28px;">

      <p style="color:#1e293b; font-size:15px; margin:0 0 12px;">Hello <strong>{$customerName}</strong>,</p>
      <p style="color:#555; font-size:14px; line-height:1.7; margin:0 0 24px;">
        To proceed with your {$typeAction}, kindly click the button below to review and authorize your booking.
      </p>

      <!-- PNR + Passengers card -->
      <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:14px 18px; margin:0 0 20px;">
        <table style="width:100%; border-collapse:collapse;">
          <tr>
            <td style="vertical-align:top; padding-right:16px; white-space:nowrap;">
              <div style="font-size:10px; text-transform:uppercase; letter-spacing:1px; color:#94a3b8; margin-bottom:4px;">Booking Ref (PNR)</div>
              <div style="font-size:20px; font-weight:800; font-family:monospace; color:#0f1e3c; letter-spacing:3px;">{$pnr}</div>
            </td>
            <td style="vertical-align:top; border-left:1px solid #e2e8f0; padding-left:16px;">
              <div style="font-size:10px; text-transform:uppercase; letter-spacing:1px; color:#94a3b8; margin-bottom:4px;">Passenger(s)</div>
              <ul style="list-style:none; padding:0; margin:0;">
                {$passengerRows}
              </ul>
            </td>
          </tr>
        </table>
      </div>

      {$seatCardHtml}

      <!-- CTA Button -->
      <div style="text-align:center; margin:0 0 22px;">
        <a href="{$link}"
           style="display:inline-block; background:linear-gradient(135deg,#0f1e3c,#1a3a6b); color:#fff; text-decoration:none; padding:17px 52px; border-radius:8px; font-weight:700; font-size:16px; letter-spacing:0.5px;">
          Review &amp; Authorize &rarr;
        </a>
      </div>

      <!-- Expiry warning -->
      <div style="background:#fffbeb; border:1px solid #fcd34d; border-radius:8px; padding:11px 16px; text-align:center;">
        <span style="font-size:13px; color:#92400e;">&#9888;&nbsp; This link expires on <strong>{$expiry}</strong>.</span>
      </div>

    </div>

    <!-- Footer -->
    <div style="background:#f8fafc; padding:16px 28px; text-align:center; font-size:11px; color:#94a3b8; border-top:1px solid #e2e8f0;">
      <strong style="color:#0f1e3c;">Lets Fly Travel DBA Base Fare</strong> &nbsp;&middot;&nbsp; reservation@base-fare.com<br>
      <span style="font-size:10px;">This is an official payment authorization request. Do not forward or share this email.</span>
    </div>

  </div>
</body>
</html>
HTML;
    }

    // =========================================================================
    // LOG
    // =========================================================================

    private function logEmail(AcceptanceRequest $acceptance, string $subject, string $link, string $status = 'QUEUED'): void
    {
        $logDir = __DIR__ . '/../../storage/acceptance/';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $entry = sprintf(
            "[%s] %s | ID:%d | TO:%s | PNR:%s | LINK:%s\n",
            Carbon::now()->format('Y-m-d H:i:s'),
            $status,
            $acceptance->id,
            $acceptance->customer_email,
            $acceptance->pnr,
            $link
        );

        file_put_contents($logDir . 'email_log.txt', $entry, FILE_APPEND | LOCK_EX);
    }
}
