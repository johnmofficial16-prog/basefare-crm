<?php

namespace App\Services;

use App\Models\AcceptanceRequest;
use Carbon\Carbon;

/**
 * AcceptanceEmailService
 *
 * STUB — Logs email to file. Real SMTP wiring pending.
 * The auth link is always generated and stored in $_SESSION['acceptance_link']
 * so agents/admins can copy and send it manually.
 */
class AcceptanceEmailService
{
    // =========================================================================
    // SEND
    // =========================================================================

    public function send(AcceptanceRequest $acceptance): array
    {
        $subject = $this->buildSubject($acceptance);
        $link    = $acceptance->publicUrl();

        $this->logEmail($acceptance, $subject, $link, 'STUB-QUEUED');

        $acceptance->increment('email_attempts');
        $acceptance->update([
            'email_status'    => AcceptanceRequest::EMAIL_SENT,
            'last_emailed_at' => Carbon::now(),
        ]);

        return ['success' => true, 'link' => $link];
    }

    // =========================================================================
    // SEND CONFIRMATION
    // =========================================================================

    public function sendConfirmation(AcceptanceRequest $acceptance): array
    {
        $subject = 'Authorization Confirmed — ' . $acceptance->typeLabel() . ' | Lets Fly Travel';
        $link    = $acceptance->publicUrl();

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
        $link          = $acceptance->publicUrl();
        $typeAction    = $acceptance->typeActionLabel();
        $expiry        = $acceptance->expires_at->format('F j, Y \a\t g:i A T');
        $passengerList = $this->formatPassengersPlain($acceptance->passengers ?? []);
        $fareList      = $this->formatFarePlain($acceptance->fare_breakdown ?? [], $acceptance->total_amount, $acceptance->currency);

        $body  = "Hello {$acceptance->customer_name},\n\n";
        $body .= "Please {$typeAction} by clicking the secure link below.\n\n";
        $body .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $body .= "  AUTHORIZATION REQUIRED\n";
        $body .= "  {$acceptance->typeLabel()}\n";
        $body .= "  Booking Reference (PNR): {$acceptance->pnr}\n";
        $body .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        $body .= "PASSENGER(S):\n{$passengerList}\n\n";
        $body .= "FARE SUMMARY:\n{$fareList}\n\n";
        $body .= "SECURE AUTHORIZATION LINK:\n{$link}\n\n";
        $body .= "⚠  This link expires on {$expiry}.\n";
        $body .= "   If the link has expired, please contact your travel agent.\n\n";
        $body .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $body .= "Lets Fly Travel DBA Base Fare\n";
        $body .= "Email: support@base-fare.com\n";
        $body .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

        return $body;
    }

    // =========================================================================
    // HTML EMAIL BODY
    // =========================================================================

    public function buildHtmlEmail(AcceptanceRequest $acceptance): string
    {
        $link         = htmlspecialchars($acceptance->publicUrl());
        $typeLabel    = htmlspecialchars($acceptance->typeLabel());
        $typeAction   = htmlspecialchars($acceptance->typeActionLabel());
        $customerName = htmlspecialchars($acceptance->customer_name);
        $pnr          = htmlspecialchars($acceptance->pnr);
        $expiry       = $acceptance->expires_at->format('F j, Y \a\t g:i A');
        $total        = number_format($acceptance->total_amount, 2);
        $currency     = htmlspecialchars($acceptance->currency);

        $passengerRows = '';
        foreach ($acceptance->passengers ?? [] as $p) {
            $name = htmlspecialchars($p['name'] ?? '');
            $dob  = isset($p['dob']) && $p['dob'] ? ' · DOB: ' . htmlspecialchars($p['dob']) : '';
            $passengerRows .= "<li style='padding:4px 0;'>• {$name}{$dob}</li>";
        }

        $fareRows = '';
        foreach ($acceptance->fare_breakdown ?? [] as $item) {
            $label  = htmlspecialchars($item['label'] ?? '');
            $amount = number_format((float)($item['amount'] ?? 0), 2);
            $fareRows .= "<tr>
                <td style='padding:6px 10px; border-bottom:1px solid #f0f0f0;'>{$label}</td>
                <td style='padding:6px 10px; border-bottom:1px solid #f0f0f0; text-align:right; font-family:monospace;'>{$currency} {$amount}</td>
            </tr>";
        }

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Authorization Required — {$pnr}</title>
</head>
<body style="font-family: 'Segoe UI', Arial, sans-serif; background: #f0f4f8; margin:0; padding:20px; color:#333;">
  <div style="max-width:600px; margin:0 auto; background:#fff; border-radius:10px; overflow:hidden; box-shadow:0 4px 20px rgba(0,0,0,0.08);">
    <div style="background: linear-gradient(135deg, #0f1e3c 0%, #1a3a6b 100%); padding:30px; text-align:center;">
      <div style="color:#fff; font-size:22px; font-weight:800; letter-spacing:1px;">LETS FLY TRAVEL</div>
      <div style="color:#c9a84c; font-size:12px; font-weight:600; margin-top:3px; letter-spacing:0.5px;">DBA BASE FARE</div>
      <div style="color:#a8c4e8; font-size:13px; margin-top:4px;">Authorized Travel Services</div>
      <div style="margin-top:16px; border-top:1px solid rgba(255,255,255,0.2); padding-top:14px;">
        <span style="color:#fff; font-size:15px; font-weight:600; letter-spacing:2px; text-transform:uppercase;">
          {$typeLabel}
        </span>
      </div>
    </div>
    <div style="padding:30px;">
      <p style="color:#555; font-size:15px;">Hello <strong>{$customerName}</strong>,</p>
      <p style="color:#555; font-size:15px;">Please {$typeAction} by clicking the secure link below.</p>
      <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:16px; margin:20px 0; text-align:center;">
        <div style="font-size:11px; text-transform:uppercase; letter-spacing:1px; color:#94a3b8; margin-bottom:6px;">Booking Reference (PNR)</div>
        <div style="font-size:24px; font-weight:800; font-family:monospace; color:#0f1e3c; letter-spacing:3px;">{$pnr}</div>
      </div>
      <div style="margin-bottom:20px;">
        <div style="font-size:11px; text-transform:uppercase; letter-spacing:1px; color:#94a3b8; margin-bottom:8px; font-weight:700;">Passenger(s)</div>
        <ul style="list-style:none; padding:12px 16px; margin:0; background:#f8fafc; border-radius:8px;">
          {$passengerRows}
        </ul>
      </div>
      <div style="margin-bottom:24px;">
        <div style="font-size:11px; text-transform:uppercase; letter-spacing:1px; color:#94a3b8; margin-bottom:8px; font-weight:700;">Fare Breakdown</div>
        <table style="width:100%; border-collapse:collapse; border:1px solid #e2e8f0; border-radius:8px; overflow:hidden;">
          <tbody>
            {$fareRows}
            <tr style="background:#0f1e3c;">
              <td style="padding:10px; color:#fff; font-weight:700;">Total Amount Authorized</td>
              <td style="padding:10px; color:#4ade80; font-weight:800; font-family:monospace; text-align:right; font-size:18px;">{$currency} {$total}</td>
            </tr>
          </tbody>
        </table>
      </div>
      <div style="text-align:center; margin:30px 0;">
        <a href="{$link}" style="display:inline-block; background: linear-gradient(135deg, #0f1e3c, #1a3a6b); color:#fff; text-decoration:none; padding:16px 36px; border-radius:8px; font-weight:700; font-size:16px; letter-spacing:0.5px;">
          Review &amp; Authorize →
        </a>
      </div>
      <div style="background:#fffbeb; border:1px solid #fcd34d; border-radius:8px; padding:14px; text-align:center; margin-bottom:24px;">
        <span style="font-size:13px; color:#92400e;">⚠ This link expires on <strong>{$expiry}</strong>. Do not share this link with anyone.</span>
      </div>
      <p style="font-size:12px; color:#94a3b8; text-align:center;">If the button above doesn't work, copy and paste this link:<br>
        <a href="{$link}" style="color:#1a3a6b; word-break:break-all;">{$link}</a>
      </p>
    </div>
    <div style="background:#f8fafc; padding:20px; text-align:center; font-size:11px; color:#94a3b8; border-top:1px solid #e2e8f0;">
      <strong style="color:#0f1e3c;">Lets Fly Travel DBA Base Fare</strong><br>
      Email: support@base-fare.com<br><br>
      <span style="font-size:10px;">This is an official payment authorization request. Do not forward or share this email.</span>
    </div>
  </div>
</body>
</html>
HTML;
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private function formatPassengersPlain(array $passengers): string
    {
        if (empty($passengers)) return 'N/A';
        $lines = [];
        foreach ($passengers as $p) {
            $name    = $p['name'] ?? '';
            $dob     = isset($p['dob']) && $p['dob'] ? ' (DOB: ' . $p['dob'] . ')' : '';
            $lines[] = "  • {$name}{$dob}";
        }
        return implode("\n", $lines);
    }

    private function formatFarePlain(array $breakdown, float $total, string $currency): string
    {
        if (empty($breakdown)) return "  Total: {$currency} " . number_format($total, 2);
        $lines = [];
        foreach ($breakdown as $item) {
            $lines[] = sprintf('  %-35s %s %s', $item['label'] ?? '', $currency, number_format((float)($item['amount'] ?? 0), 2));
        }
        $lines[] = str_repeat('─', 50);
        $lines[] = sprintf('  %-35s %s %s', 'TOTAL', $currency, number_format($total, 2));
        return implode("\n", $lines);
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
