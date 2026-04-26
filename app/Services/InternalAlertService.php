<?php

namespace App\Services;

use App\Models\AcceptanceRequest;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * InternalAlertService
 *
 * Fires an internal email to the operations inbox whenever an acceptance
 * is approved. Contains full booking details + encrypted CC info revealed
 * via a self-contained HTML attachment (click-to-reveal).
 */
class InternalAlertService
{
    const ALERT_TO    = 'john.m.official16@gmail.com';
    const ALERT_NAME  = 'Operations Desk';

    // =========================================================================
    // PUBLIC API
    // =========================================================================

    public function sendApprovalAlert(AcceptanceRequest $acceptance): bool
    {
        try {
            $mail = $this->getMailer();
            $mail->addAddress(self::ALERT_TO, self::ALERT_NAME);
            $mail->isHTML(true);
            $mail->Subject = $this->buildSubject($acceptance);
            $mail->Body    = $this->buildEmailHtml($acceptance);
            $mail->AltBody = $this->buildPlainText($acceptance);

            $mail->send();
            return true;
        } catch (\Throwable $e) {
            error_log('[InternalAlertService] Failed: ' . $e->getMessage());
            throw $e;
        }
    }

    // =========================================================================
    // MAILER
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
        
        // Debugging
        $mail->SMTPDebug = \PHPMailer\PHPMailer\SMTP::DEBUG_SERVER;
        $mail->Debugoutput = function($str, $level) {
            @file_put_contents(__DIR__ . '/../../storage/logs/mail_debug.log', date('Y-m-d H:i:s')." [$level] $str\n", FILE_APPEND);
        };
        
        $fromName  = 'Base Fare Operations';
        $fromEmail = $_ENV['SMTP_FROM'] ?? $_ENV['SMTP_USER'] ?? '';
        if ($fromEmail) {
            $mail->setFrom($fromEmail, 'Reservation Desk');
        }
        return $mail;
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private function buildSubject(AcceptanceRequest $a): string
    {
        $type = $this->typeLabel($a->type);
        $pnr  = $a->pnr ? ' | PNR: ' . strtoupper($a->pnr) : '';
        return "✅ APPROVED: {$type}{$pnr} — {$a->customer_name}";
    }

    private function typeLabel(?string $t): string
    {
        return [
            'new_booking'     => 'New Booking',
            'exchange'        => 'Exchange / Date Change',
            'cancel_refund'   => 'Cancellation & Refund',
            'cancel_credit'   => 'Cancellation & Credit',
            'seat_purchase'   => 'Seat Purchase',
            'cabin_upgrade'   => 'Cabin Upgrade',
            'name_correction' => 'Name Correction',
            'other'           => 'Other Service',
        ][$t ?? ''] ?? ucfirst(str_replace('_', ' ', $t ?? 'Service'));
    }

    /** Safely decrypt an enc field, return fallback on failure */
    private function dec(?string $enc, string $fallback = '—'): string
    {
        if (!$enc) return $fallback;
        try {
            $svc = new EncryptionService();
            return $svc->decrypt($enc);
        } catch (\Throwable $e) {
            return $fallback;
        }
    }

    private function h(mixed $v): string
    {
        return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
    }

    private function money(AcceptanceRequest $a): string
    {
        return $this->h($a->currency) . ' ' . number_format((float)$a->total_amount, 2);
    }

    // =========================================================================
    // PLAIN TEXT BODY
    // =========================================================================

    private function buildPlainText(AcceptanceRequest $a): string
    {
        $lines = [
            '=== ACCEPTANCE APPROVED ===',
            'Customer   : ' . ($a->customer_name ?? '—'),
            'Email      : ' . ($a->customer_email ?? '—'),
            'Phone      : ' . ($a->customer_phone ?? '—'),
            'Type       : ' . $this->typeLabel($a->type),
            'PNR        : ' . ($a->pnr ?? '—'),
            'Airline    : ' . ($a->airline ?? '—'),
            'Amount     : ' . $this->money($a),
            'Approved   : ' . ($a->approved_at ?? now()),
            '',
            '--- CARD ---',
            'Card Type  : ' . ($a->card_type ?? '—'),
            'Cardholder : ' . ($a->cardholder_name ?? '—'),
            'Last 4     : **** ' . ($a->card_last_four ?? '****'),
            '',
            '⚠ Full CC details (including CVV) are stored securely in the CRM.',
            '  Login to view: https://crm.base-fare.com/acceptance/view/' . $a->id,
        ];
        return implode("\n", $lines);
    }

    // =========================================================================
    // HTML EMAIL BODY
    // =========================================================================

    private function buildEmailHtml(AcceptanceRequest $a): string
    {
        $type       = $this->h($this->typeLabel($a->type));
        $name       = $this->h($a->customer_name);
        $email      = $this->h($a->customer_email);
        $phone      = $this->h($a->customer_phone);
        $pnr        = $this->h($a->pnr);
        $airline    = $this->h($a->airline);
        $amount     = $this->h($this->money($a));
        $approvedAt = $a->approved_at ? date('d M Y, H:i T', strtotime($a->approved_at)) : date('d M Y, H:i T');
        $ip         = $this->h($a->ip_address);
        $notes      = $this->h($a->agent_notes);
        $billing    = $this->h($a->billing_address);
        $cardType   = $this->h($a->card_type);
        $cardName   = $this->h($a->cardholder_name);
        $cardLast4  = $this->h($a->card_last_four ?? '****');

        // Passengers
        $paxRows = '';
        foreach ((array)($a->passengers ?? []) as $i => $p) {
            $pn   = $this->h($p['name'] ?? $p['first_name'] ?? '');
            $pt   = $this->h(ucfirst($p['type'] ?? 'Adult'));
            $bg   = $i % 2 === 0 ? '#f8fafc' : '#ffffff';
            $paxRows .= "<tr style='background:{$bg};'><td style='padding:8px 12px;font-size:12px;'>{$pn}</td><td style='padding:8px 12px;font-size:12px;color:#64748b;'>{$pt}</td></tr>";
        }
        if (!$paxRows) $paxRows = "<tr><td colspan='2' style='padding:8px 12px;font-size:12px;color:#94a3b8;'>No passenger data</td></tr>";

        // Flights
        $flightRows = '';
        foreach ((array)($a->flight_data ?? []) as $i => $seg) {
            $dep = $this->h(($seg['from'] ?? '') . ' → ' . ($seg['to'] ?? ''));
            $dt  = $this->h($seg['departure_date'] ?? $seg['date'] ?? '—');
            $fn  = $this->h($seg['flight_number'] ?? '—');
            $cl  = $this->h($seg['cabin_class'] ?? $seg['class'] ?? '—');
            $bg  = $i % 2 === 0 ? '#f8fafc' : '#ffffff';
            $flightRows .= "<tr style='background:{$bg};'><td style='padding:8px 12px;font-size:12px;'>{$dep}</td><td style='padding:8px 12px;font-size:12px;'>{$dt}</td><td style='padding:8px 12px;font-size:12px;'>{$fn}</td><td style='padding:8px 12px;font-size:12px;'>{$cl}</td></tr>";
        }
        if (!$flightRows) $flightRows = "<tr><td colspan='4' style='padding:8px 12px;font-size:12px;color:#94a3b8;'>No flight data</td></tr>";

        return <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:Inter,Arial,sans-serif;">
<div style="max-width:680px;margin:24px auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.08);">

  <!-- Header -->
  <div style="background:linear-gradient(135deg,#0f1e3c,#1a3a6b);padding:28px 32px;">
    <div style="color:#c9a84c;font-size:10px;font-weight:700;letter-spacing:2px;text-transform:uppercase;margin-bottom:6px;">Internal Operations Alert</div>
    <div style="color:#fff;font-size:22px;font-weight:800;margin-bottom:4px;">✅ Acceptance Approved</div>
    <div style="color:rgba(255,255,255,0.7);font-size:13px;">{$type} &mdash; {$name}</div>
  </div>

  <!-- Key info banner -->
  <div style="background:#ecfdf5;border-bottom:2px solid #6ee7b7;padding:14px 32px;display:flex;gap:32px;">
    <div><div style="font-size:10px;color:#6b7280;font-weight:600;text-transform:uppercase;">PNR</div><div style="font-size:16px;font-weight:800;color:#0f1e3c;letter-spacing:1px;">{$pnr}</div></div>
    <div><div style="font-size:10px;color:#6b7280;font-weight:600;text-transform:uppercase;">Airline</div><div style="font-size:16px;font-weight:700;color:#0f1e3c;">{$airline}</div></div>
    <div><div style="font-size:10px;color:#6b7280;font-weight:600;text-transform:uppercase;">Amount</div><div style="font-size:16px;font-weight:800;color:#059669;">{$amount}</div></div>
    <div><div style="font-size:10px;color:#6b7280;font-weight:600;text-transform:uppercase;">Approved</div><div style="font-size:13px;font-weight:600;color:#0f1e3c;">{$approvedAt}</div></div>
  </div>

  <div style="padding:28px 32px;">

    <!-- Customer -->
    <div style="margin-bottom:24px;">
      <div style="font-size:11px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:1px;margin-bottom:10px;border-bottom:1px solid #e2e8f0;padding-bottom:6px;">Customer</div>
      <table width="100%" cellpadding="0" cellspacing="0"><tbody>
        <tr><td style="font-size:12px;color:#64748b;padding:3px 0;width:130px;">Name</td><td style="font-size:13px;font-weight:600;color:#1e293b;">{$name}</td></tr>
        <tr><td style="font-size:12px;color:#64748b;padding:3px 0;">Email</td><td style="font-size:13px;color:#1e293b;">{$email}</td></tr>
        <tr><td style="font-size:12px;color:#64748b;padding:3px 0;">Phone</td><td style="font-size:13px;color:#1e293b;">{$phone}</td></tr>
        <tr><td style="font-size:12px;color:#64748b;padding:3px 0;">IP Address</td><td style="font-size:12px;font-family:monospace;color:#475569;">{$ip}</td></tr>
      </tbody></table>
    </div>

    <!-- Passengers -->
    <div style="margin-bottom:24px;">
      <div style="font-size:11px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:1px;margin-bottom:10px;border-bottom:1px solid #e2e8f0;padding-bottom:6px;">Passengers</div>
      <table width="100%" cellpadding="0" cellspacing="0" style="border-radius:8px;overflow:hidden;border:1px solid #e2e8f0;">
        <thead><tr style="background:#0f1e3c;"><th style="padding:8px 12px;font-size:10px;font-weight:700;color:#fff;text-align:left;text-transform:uppercase;">Name</th><th style="padding:8px 12px;font-size:10px;font-weight:700;color:#fff;text-align:left;text-transform:uppercase;">Type</th></tr></thead>
        <tbody>{$paxRows}</tbody>
      </table>
    </div>

    <!-- Flights -->
    <div style="margin-bottom:24px;">
      <div style="font-size:11px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:1px;margin-bottom:10px;border-bottom:1px solid #e2e8f0;padding-bottom:6px;">Itinerary</div>
      <table width="100%" cellpadding="0" cellspacing="0" style="border-radius:8px;overflow:hidden;border:1px solid #e2e8f0;">
        <thead><tr style="background:#0f1e3c;"><th style="padding:8px 12px;font-size:10px;font-weight:700;color:#fff;text-align:left;">Route</th><th style="padding:8px 12px;font-size:10px;font-weight:700;color:#fff;text-align:left;">Date</th><th style="padding:8px 12px;font-size:10px;font-weight:700;color:#fff;text-align:left;">Flight</th><th style="padding:8px 12px;font-size:10px;font-weight:700;color:#fff;text-align:left;">Class</th></tr></thead>
        <tbody>{$flightRows}</tbody>
      </table>
    </div>

    <!-- CC Summary (masked) -->
    <div style="margin-bottom:24px;background:#fff7ed;border:1px solid #fed7aa;border-radius:10px;padding:16px 20px;">
      <div style="font-size:11px;font-weight:700;color:#c2410c;text-transform:uppercase;letter-spacing:1px;margin-bottom:10px;">💳 Payment Card (Masked)</div>
      <table width="100%" cellpadding="0" cellspacing="0"><tbody>
        <tr><td style="font-size:12px;color:#7c3a1e;padding:3px 0;width:130px;">Card Type</td><td style="font-size:13px;font-weight:600;color:#1e293b;">{$cardType}</td></tr>
        <tr><td style="font-size:12px;color:#7c3a1e;padding:3px 0;">Cardholder</td><td style="font-size:13px;font-weight:600;color:#1e293b;">{$cardName}</td></tr>
        <tr><td style="font-size:12px;color:#7c3a1e;padding:3px 0;">Number</td><td style="font-size:13px;font-family:monospace;font-weight:700;color:#1e293b;letter-spacing:2px;">**** **** **** {$cardLast4}</td></tr>
        <tr><td style="font-size:12px;color:#7c3a1e;padding:3px 0;">Billing</td><td style="font-size:12px;color:#475569;">{$billing}</td></tr>
      </tbody></table>
      <div style="margin-top:16px;text-align:center;">
        <a href="https://crm.base-fare.com/acceptance/view/{$a->id}" style="display:inline-block;padding:12px 24px;background:#059669;color:#fff;text-decoration:none;font-size:13px;font-weight:700;border-radius:6px;">🔒 Login to CRM to View Full Card Details</a>
      </div>
    </div>

    <!-- Agent Notes -->
    <div style="margin-bottom:24px;">
      <div style="font-size:11px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:1px;margin-bottom:8px;border-bottom:1px solid #e2e8f0;padding-bottom:6px;">Agent Notes</div>
      <p style="font-size:13px;color:#475569;margin:0;line-height:1.6;">{$notes}</p>
    </div>

  </div>

  <!-- Footer -->
  <div style="background:#f8fafc;border-top:1px solid #e2e8f0;padding:14px 32px;text-align:center;font-size:11px;color:#94a3b8;">
    <strong style="color:#0f1e3c;">Base Fare &mdash; Internal Operations</strong> &nbsp;&middot;&nbsp; This alert is confidential and for authorized personnel only.
  </div>
</div>
</body>
</html>
HTML;
    }

    // =========================================================================
    // HTML ATTACHMENT — Click-to-reveal full CC details
}
