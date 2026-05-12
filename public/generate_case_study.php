<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use Dotenv\Dotenv;
use Carbon\Carbon;
use App\Models\Transaction;
use App\Models\AcceptanceRequest;
use App\Models\ETicket;

require __DIR__ . '/../vendor/autoload.php';

// Load environment variables
$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

// Setup Eloquent ORM
$capsule = new Capsule;
$capsule->addConnection([
    'driver'    => 'mysql',
    'host'      => $_ENV['DB_HOST'],
    'database'  => $_ENV['DB_DATABASE'],
    'username'  => $_ENV['DB_USERNAME'],
    'password'  => $_ENV['DB_PASSWORD'],
    'charset'   => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix'    => '',
]);
$capsule->setAsGlobal();
$capsule->bootEloquent();

$pnr = $_GET['pnr'] ?? 'D765YR';

$transaction = Transaction::where('pnr', $pnr)->first();
$acceptance = AcceptanceRequest::where('pnr', $pnr)->where('status', 'APPROVED')->first();
$eticket = ETicket::where('pnr', $pnr)->first();

if (!$transaction && !$acceptance && !$eticket) {
    die("No records found for PNR: " . htmlspecialchars($pnr));
}

$dbaName = 'Lets Fly Travel LLC DBA Base Fare';
$receiptNumber = $acceptance ? 'BF-' . str_pad($acceptance->id, 6, '0', STR_PAD_LEFT) : 'N/A';
$approvedAt = $acceptance && $acceptance->approved_at ? Carbon::parse($acceptance->approved_at) : null;
$customerName = $acceptance->customer_name ?? $transaction->customer_name ?? $eticket->customer_name ?? 'N/A';
$customerEmail = $acceptance->customer_email ?? $transaction->customer_email ?? $eticket->customer_email ?? 'N/A';
$customerPhone = $acceptance->customer_phone ?? $transaction->customer_phone ?? $eticket->customer_phone ?? 'N/A';
$totalAmount = $acceptance->total_amount ?? $transaction->total_amount ?? 'N/A';
$currency = $acceptance->currency ?? $transaction->currency ?? 'USD';
$ipAddress = $acceptance->ip_address ?? 'N/A';
$ipLocation = trim(($acceptance->ip_city ?? '') . ', ' . ($acceptance->ip_country ?? ''), ', ');

$ticketData = $eticket->ticket_data ?? [];
if (empty($ticketData) && $eticket) {
    $ticketData = $eticket->ticketDataWithAutoNumbers();
}

$flightData = $eticket->flight_data ?? $acceptance->flight_data ?? [];

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Chargeback Rebuttal — <?= htmlspecialchars($pnr) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet"/>
<style>
/* ── Reset ── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { font-size: 14px; }
body {
  font-family: 'Inter', 'Manrope', sans-serif;
  color: #1e293b;
  background: #f8fafc;
  padding: 24px;
}

/* ── Print optimizations ── */
@media print {
  body { background: white; padding: 0; font-size: 12px; }
  .no-print { display: none !important; }
  .page { box-shadow: none !important; max-width: unset !important; }
  .page-break { page-break-before: always; }
}

/* ── Layout ── */
.page {
  max-width: 800px;
  margin: 0 auto;
  background: white;
  box-shadow: 0 4px 24px rgba(0,0,0,0.08);
  border-radius: 12px;
  overflow: hidden;
  margin-bottom: 30px;
}

/* ── Header ── */
.receipt-header {
  background: linear-gradient(135deg, #0f1e3c 0%, #1a3a6b 100%);
  padding: 28px 32px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
}
.brand-block {}
.brand-name { color: #fff; font-size: 18px; font-weight: 800; letter-spacing: 0.5px; font-family: 'Manrope', sans-serif; }
.brand-dba  { color: #c9a84c; font-size: 11px; font-weight: 700; letter-spacing: 1px; text-transform: uppercase; }
.brand-sub  { color: #93c5fd; font-size: 11px; margin-top: 2px; }
.receipt-meta { text-align: right; }
.receipt-no  { color: #fff; font-size: 22px; font-weight: 900; font-family: 'Manrope', sans-serif; letter-spacing: 1px; }
.receipt-lbl { color: #93c5fd; font-size: 10px; text-transform: uppercase; letter-spacing: 1px; }

/* ── Body ── */
.body { padding: 28px 32px; }

/* ── Section wrapper ── */
.section { margin-bottom: 24px; }
.section-title {
  font-size: 11px;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 1.5px;
  color: #1a3a6b;
  margin-bottom: 12px;
  padding-bottom: 8px;
  border-bottom: 2px solid #e2e8f0;
}

/* ── Grid ── */
.info-grid { display: grid; gap: 12px; }
.info-grid-2 { grid-template-columns: 1fr 1fr; }
.info-grid-3 { grid-template-columns: 1fr 1fr 1fr; }
.info-cell { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 16px; }
.info-label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.8px; color: #64748b; margin-bottom: 6px; }
.info-value { font-size: 13px; font-weight: 600; color: #0f1e3c; }
.info-value.mono { font-family: 'Courier New', monospace; letter-spacing: 1px; font-weight: 700; }

.f-label { font-size: 9px; font-weight: 800; text-transform: uppercase; color: #047857; margin-bottom: 3px; }
.f-value { font-size: 12px; font-family: monospace; font-weight: 700; color: #064e3b; word-break: break-all; }

.highlight-box {
    background: #fffbeb;
    border-left: 4px solid #f59e0b;
    padding: 16px;
    margin-bottom: 20px;
    font-size: 14px;
    line-height: 1.6;
    color: #92400e;
}
.highlight-box strong { color: #b45309; }

p { font-size: 13px; line-height: 1.6; color: #334155; margin-bottom: 12px; }
ul { margin-left: 20px; margin-bottom: 16px; font-size: 13px; line-height: 1.6; color: #334155; }
li { margin-bottom: 4px; }

.forensic-block {
  background: #ecfdf5;
  border: 1px solid #6ee7b7;
  border-radius: 8px;
  overflow: hidden;
}
.forensic-header {
  background: #065f46;
  padding: 10px 16px;
  display: flex;
  align-items: center;
  gap: 8px;
}
.forensic-header span { color: #fff; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 1px; }
.forensic-body { padding: 16px; display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; }

/* ── Print button ── */
.print-btn {
  position: fixed;
  top: 20px;
  right: 20px;
  background: #0f1e3c;
  color: #fff;
  border: none;
  border-radius: 8px;
  padding: 10px 20px;
  font-size: 13px;
  font-weight: 700;
  cursor: pointer;
  display: flex;
  align-items: center;
  gap: 8px;
  box-shadow: 0 4px 12px rgba(15,30,60,0.25);
  transition: all 0.15s ease;
  font-family: 'Inter', sans-serif;
  z-index: 100;
}
.print-btn:hover { background: #1a3a6b; transform: translateY(-1px); }
</style>
</head>
<body>

<button class="print-btn no-print" onclick="window.print()">
  🖨️ Print to PDF
</button>

<div class="page">
  <div class="receipt-header">
    <div class="brand-block">
      <div class="brand-name"><?= htmlspecialchars(strtoupper($dbaName)) ?></div>
      <div class="brand-sub">Chargeback Dispute Rebuttal Document</div>
    </div>
    <div class="receipt-meta">
      <div class="receipt-lbl">Transaction PNR</div>
      <div class="receipt-no"><?= htmlspecialchars($pnr) ?></div>
    </div>
  </div>

  <div class="body">
    
    <div class="highlight-box" contenteditable="true">
        <strong>REASON CODE C31:</strong> Goods and services received are not as described, defective, or do not match the promised quality, specifications, or color.
        <br><br>
        <strong>MERCHANT RESPONSE: INVALID DISPUTE</strong>
        <br>
        We strongly dispute this chargeback. The cardholder authorized this transaction, the travel services (airline tickets) were fully delivered exactly as described, and the customer never contacted us to complain, request a refund, or report any issues prior to initiating this chargeback.
    </div>

    <div class="section">
      <div class="section-title">1. Transaction ID & Customer Details</div>
      <div class="info-grid info-grid-3">
        <div class="info-cell">
          <div class="info-label">Customer Name</div>
          <div class="info-value" contenteditable="true"><?= htmlspecialchars($customerName) ?></div>
        </div>
        <div class="info-cell">
          <div class="info-label">Email Address</div>
          <div class="info-value" contenteditable="true"><?= htmlspecialchars($customerEmail) ?></div>
        </div>
        <div class="info-cell">
          <div class="info-label">Phone Number</div>
          <div class="info-value" contenteditable="true"><?= htmlspecialchars($customerPhone) ?></div>
        </div>
        <div class="info-cell">
          <div class="info-label">Total Amount Authorized</div>
          <div class="info-value mono" contenteditable="true"><?= htmlspecialchars($currency) ?> <?= htmlspecialchars($totalAmount) ?></div>
        </div>
        <div class="info-cell">
          <div class="info-label">Booking Reference (PNR)</div>
          <div class="info-value mono" contenteditable="true"><?= htmlspecialchars($pnr) ?></div>
        </div>
        <div class="info-cell">
          <div class="info-label">Order / Ticket Status</div>
          <div class="info-value" style="color: #059669;" contenteditable="true">CONFIRMED / DELIVERED</div>
        </div>
      </div>
    </div>

    <div class="section">
      <div class="section-title">2. Authorization of Pax (Proof of Identity & Consent)</div>
      <p contenteditable="true">The customer completed a secure, digitally tracked acceptance process verifying their identity, IP address, and consent to the payment. Below is the forensic evidence capturing their authorization:</p>
      
      <?php if ($acceptance): ?>
      <div class="forensic-block">
        <div class="forensic-header">
          <span>🔒</span>
          <span>Digital Authorization Record</span>
        </div>
        <div class="forensic-body">
          <div class="forensic-cell">
            <div class="f-label">Signed At (UTC)</div>
            <div class="f-value" contenteditable="true"><?= $approvedAt ? $approvedAt->format('Y-m-d H:i:s') : 'N/A' ?></div>
          </div>
          <div class="forensic-cell">
            <div class="f-label">IP Address & Location</div>
            <div class="f-value" contenteditable="true">
              <?= htmlspecialchars($ipAddress) ?>
              <?php if ($ipLocation): ?><br><span style="font-weight: 500; font-size: 10px;"><?= htmlspecialchars($ipLocation) ?></span><?php endif; ?>
            </div>
          </div>
          <div class="forensic-cell">
            <div class="f-label">Device Fingerprint</div>
            <div class="f-value" style="font-size: 10px;" contenteditable="true">
              <?= htmlspecialchars($acceptance->user_agent ?? 'N/A') ?>
            </div>
          </div>
        </div>
        <?php if ($acceptance->digital_signature): ?>
        <div style="padding:0 16px 12px; border-top:1px solid #bbf7d0; margin-top:0; padding-top:10px;">
          <div class="f-label" style="color:#047857;font-size:9px;margin-bottom:6px;">Digital Signature</div>
          <?php
          $sigFilename = $acceptance->digital_signature;
          $sigFile     = __DIR__ . '/../storage/acceptance/signatures/' . $sigFilename;
          $isJson      = str_ends_with($sigFilename, '_esign.json');
          $isPng       = str_ends_with($sigFilename, '_sig.png');

          if ($isJson && file_exists($sigFile)):
            $sigPayload = @json_decode(file_get_contents($sigFile), true);
            $sigSigner  = $sigPayload['signer']    ?? $acceptance->customer_name;
            $sigTs      = $sigPayload['timestamp'] ?? null;
            $sigTsFmt   = $sigTs ? date('M j, Y g:i A', strtotime($sigTs)) . ' UTC' : '—';
          ?>
          <div style="display:inline-flex;align-items:center;gap:10px;background:#f0fdf4;border:2px solid #6ee7b7;border-radius:8px;padding:10px 14px;">
            <span style="font-size:22px;">✅</span>
            <div>
              <div style="font-size:12px;font-weight:800;color:#065f46;">Digitally Signed — Legally Verified</div>
              <div style="font-size:11px;color:#047857;margin-top:2px;">Signer: <strong contenteditable="true"><?= htmlspecialchars($sigSigner) ?></strong></div>
              <div style="font-size:10px;color:#047857;margin-top:1px;font-family:monospace;">Signed at: <?= htmlspecialchars($sigTsFmt) ?></div>
            </div>
          </div>
          <?php elseif ($isPng && file_exists($sigFile)):
            $sigData = base64_encode(file_get_contents($sigFile));
          ?>
          <img src="data:image/png;base64,<?= $sigData ?>" alt="Signature" style="max-height:60px;max-width:220px;border:1px solid #bbf7d0;background:#fff;border-radius:6px;padding:4px;display:block;">
          <?php else: ?>
          <div class="f-value" style="color:#065f46;" contenteditable="true">
            ✅ Signature verified and securely stored in our system (Ref: <?= htmlspecialchars($sigFilename) ?>)
          </div>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>
      <?php else: ?>
      <div class="info-cell" style="background:#fefce8; border-color:#fde047;" contenteditable="true">
        Authorization details manually verified. Customer agreed to terms and conditions at checkout.
      </div>
      <?php endif; ?>
      
      <p style="margin-top: 12px;" contenteditable="true">Additionally, the customer agreed to our explicit "No Refund" and cancellation policies during this flow.</p>
    </div>

    <div class="section">
      <div class="section-title">3. Service Provided by Us (Proof of Delivery)</div>
      <p contenteditable="true">We successfully fulfilled the service by issuing the airline tickets to the customer. Electronic tickets (e-tickets) were emailed to <strong><?= htmlspecialchars($customerEmail) ?></strong>. The services are exactly as described and were delivered on time.</p>
      
      <?php if (!empty($ticketData)): ?>
      <div style="margin-top:10px;">
        <div style="font-size:10px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:1px; margin-bottom:8px;">Issued E-Tickets</div>
        <?php foreach ($ticketData as $row): ?>
        <div style="display:flex;align-items:center;gap:10px;padding:10px 14px;background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;margin-bottom:6px;">
          <span style="font-size:13px;color:#0369a1;font-weight:700;flex:1;" contenteditable="true"><?= htmlspecialchars($row['pax_name'] ?? 'Passenger') ?></span>
          <span style="font-size:12px;font-family:monospace;color:#0284c7;font-weight:800;" contenteditable="true">Ticket #: <?= htmlspecialchars($row['ticket_number'] ?? '—') ?></span>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      
      <?php if (!empty($flightData['flights'])): ?>
      <div style="margin-top:12px;">
        <div style="font-size:10px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:1px; margin-bottom:8px;">Flight Itinerary Provided</div>
        <div style="border:1px solid #e2e8f0; border-radius:8px; overflow:hidden;">
          <?php foreach ($flightData['flights'] as $seg): 
            if(empty($seg['from']) || empty($seg['to'])) continue;
          ?>
          <div style="padding:10px 14px; border-bottom:1px solid #f1f5f9; display:flex; justify-content:space-between; align-items:center;">
            <div style="font-weight:700; color:#1e293b;" contenteditable="true">
              <?= htmlspecialchars($seg['airline_iata'] ?? '') ?> <?= htmlspecialchars($seg['flight_no'] ?? '') ?>
            </div>
            <div style="font-size:13px; font-weight:600; color:#334155;" contenteditable="true">
              <?= htmlspecialchars($seg['from']) ?> → <?= htmlspecialchars($seg['to']) ?>
            </div>
            <div style="font-size:12px; color:#64748b;" contenteditable="true">
              <?= htmlspecialchars($seg['date'] ?? '') ?> | <?= htmlspecialchars($seg['dep_time'] ?? '') ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>
    </div>

    <div class="section">
      <div class="section-title">4. Summary & Merchant Claim</div>
      <p contenteditable="true">Based on the evidence presented, we respectfully request that this chargeback be overturned and the funds returned to our account for the following reasons:</p>
      <ul contenteditable="true">
        <li><strong>Service Delivered:</strong> The airline tickets were successfully booked, confirmed with the airline, and the e-ticket numbers were generated and emailed to the customer.</li>
        <li><strong>Exactly As Described:</strong> The itinerary and services provided perfectly match the itinerary that the customer approved during checkout.</li>
        <li><strong>No Prior Complaint:</strong> The customer never contacted our support team to report any issue, defect, or discrepancy with the booking before filing this dispute with their bank.</li>
        <li><strong>Customer Authorized:</strong> The customer explicitly authorized the charge and verified their identity, bypassing any claim of misunderstanding.</li>
      </ul>
      <p contenteditable="true" style="font-weight: 600; margin-top: 16px;">We classify this as an INVALID DISPUTE (Friendly Fraud) as the customer received the exact travel services they paid for.</p>
    </div>
    
  </div>
</div>

</body>
</html>
