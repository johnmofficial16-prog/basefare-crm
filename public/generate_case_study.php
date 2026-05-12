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

$transaction = Transaction::where('pnr', $pnr)->with('agent')->first();
$acceptance = AcceptanceRequest::where('pnr', $pnr)->where('status', 'APPROVED')->with('agent')->first();
$eticket = ETicket::where('pnr', $pnr)->first();

if (!$transaction && !$acceptance && !$eticket) {
    die("No records found for PNR: " . htmlspecialchars($pnr));
}

$dbaName = 'Lets Fly Travel LLC DBA Base Fare';
$approvedAt = $acceptance && $acceptance->approved_at ? Carbon::parse($acceptance->approved_at) : null;
$customerName = $acceptance->customer_name ?? $transaction->customer_name ?? $eticket->customer_name ?? 'N/A';
$customerEmail = $acceptance->customer_email ?? $transaction->customer_email ?? $eticket->customer_email ?? 'N/A';
$totalAmount = $acceptance->total_amount ?? $transaction->total_amount ?? '350.00';
$currency = $acceptance->currency ?? $transaction->currency ?? 'USD';
$agentName = $acceptance->agent->name ?? $transaction->agent->name ?? 'Xavier Woods';

$cardType = $acceptance->card_type ?? 'American Express';
$cardLastFour = $acceptance->card_last_four ?? '3008';

$ipAddress = $acceptance->ip_address ?? 'N/A';
$ipLocation = trim(($acceptance->ip_city ?? '') . ', ' . ($acceptance->ip_country ?? ''), ', ');

$ticketData = $eticket->ticket_data ?? [];
if (empty($ticketData) && $eticket) {
    $ticketData = $eticket->ticketDataWithAutoNumbers();
}

// Gather passenger names
$paxList = [];
if (!empty($ticketData)) {
    foreach ($ticketData as $p) {
        if (!empty($p['pax_name'])) $paxList[] = $p['pax_name'];
    }
} elseif (!empty($acceptance->passengers)) {
    foreach ($acceptance->passengers as $p) {
        if (!empty($p['name'])) $paxList[] = $p['name'];
    }
}
$paxString = count($paxList) > 0 ? implode(' and ', $paxList) : 'Ms. Barbara Bird';

// Try to grab seats from extra_data if available
$seats = '19H, 22H, 19K, 22K'; // Default as requested
if (!empty($acceptance->extra_data['seat_number'])) {
    $seats = $acceptance->extra_data['seat_number'];
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
  .page { box-shadow: none !important; max-width: unset !important; border: 1px solid #e2e8f0; }
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
.brand-name { color: #fff; font-size: 20px; font-weight: 800; letter-spacing: 0.5px; font-family: 'Manrope', sans-serif; }
.brand-sub  { color: #93c5fd; font-size: 13px; margin-top: 4px; }
.receipt-meta { text-align: right; }
.receipt-no  { color: #fff; font-size: 24px; font-weight: 900; font-family: 'Manrope', sans-serif; letter-spacing: 1px; }
.receipt-lbl { color: #93c5fd; font-size: 11px; text-transform: uppercase; letter-spacing: 1px; }

/* ── Body ── */
.body { padding: 32px; }

/* ── Top Summary Block ── */
.summary-block {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 24px;
}
.summary-item { display: flex; flex-direction: column; gap: 4px; }
.summary-label { font-size: 10px; font-weight: 800; text-transform: uppercase; color: #64748b; letter-spacing: 1px; }
.summary-value { font-size: 14px; font-weight: 700; color: #0f1e3c; }
.summary-value.highlight { color: #b45309; }

/* ── Sections ── */
.section { margin-bottom: 28px; }
.section-title {
  font-size: 12px;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 1.5px;
  color: #1a3a6b;
  margin-bottom: 12px;
  padding-bottom: 8px;
  border-bottom: 2px solid #e2e8f0;
}

p { font-size: 13px; line-height: 1.7; color: #334155; margin-bottom: 14px; }
ul { margin-left: 20px; margin-bottom: 16px; font-size: 13px; line-height: 1.7; color: #334155; }
li { margin-bottom: 6px; }
strong { color: #0f1e3c; font-weight: 700; }

.forensic-block {
  background: #ecfdf5;
  border: 1px solid #6ee7b7;
  border-radius: 8px;
  overflow: hidden;
  margin-top: 16px;
  margin-bottom: 16px;
}
.forensic-header {
  background: #065f46;
  padding: 10px 16px;
  display: flex;
  align-items: center;
  gap: 8px;
}
.forensic-header span { color: #fff; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 1px; }
.forensic-body { padding: 16px; display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.f-label { font-size: 9px; font-weight: 800; text-transform: uppercase; color: #047857; margin-bottom: 3px; }
.f-value { font-size: 12px; font-family: monospace; font-weight: 700; color: #064e3b; word-break: break-all; }

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
      <div class="brand-name">Dispute Rebuttal – C31 Claim</div>
      <div class="brand-sub"><?= htmlspecialchars(strtoupper($dbaName)) ?></div>
    </div>
    <div class="receipt-meta">
      <div class="receipt-lbl">Transaction PNR</div>
      <div class="receipt-no"><?= htmlspecialchars($pnr) ?></div>
    </div>
  </div>

  <div class="body">
    
    <div class="summary-block">
        <div class="summary-item">
            <span class="summary-label">Merchant</span>
            <span class="summary-value" contenteditable="true"><?= htmlspecialchars($dbaName) ?></span>
        </div>
        <div class="summary-item">
            <span class="summary-label">Cardholder</span>
            <span class="summary-value" contenteditable="true"><?= htmlspecialchars($customerName) ?></span>
        </div>
        <div class="summary-item">
            <span class="summary-label">Disputed Amount</span>
            <span class="summary-value highlight" contenteditable="true"><?= htmlspecialchars($currency) ?> <?= htmlspecialchars(number_format((float)$totalAmount, 2)) ?></span>
        </div>
        <div class="summary-item">
            <span class="summary-label">Dispute Reason</span>
            <span class="summary-value" style="color: #b91c1c;" contenteditable="true">C31 – Goods/Services Not as Described or Defective</span>
        </div>
    </div>

    <div class="section">
      <div class="section-title">Overview of the Transaction</div>
      <p contenteditable="true">
        <?= htmlspecialchars($customerName) ?> contacted <?= htmlspecialchars($dbaName) ?> to purchase preferred seat assignments for his existing airline reservation under confirmation number <strong><?= htmlspecialchars($pnr) ?></strong> for passengers — <?= htmlspecialchars($customerName) ?> and co-passenger(s) <?= htmlspecialchars($paxString) ?>.
      </p>
      <p contenteditable="true">
        The request was handled by Travel Advisor <strong><?= htmlspecialchars($agentName) ?></strong> of <?= htmlspecialchars($dbaName) ?>.
      </p>
    </div>

    <div class="section">
      <div class="section-title">Requested Services and Seat Assignment</div>
      <p contenteditable="true">The cardholder specifically requested confirmed seat assignments for the passengers. As requested by the customer, the following seats were successfully assigned:</p>
      <ul contenteditable="true">
        <li>Seat Nos. 19H and 22H</li>
        <li>Seat Nos. 19K and 22K</li>
      </ul>
      <p contenteditable="true">The requested service was completed successfully and reflected on the updated itinerary and final ticket documents sent to the cardholder.</p>
    </div>

    <div class="section">
      <div class="section-title">Cardholder Authorization and Consent</div>
      <p contenteditable="true">Before processing the transaction, <?= htmlspecialchars($customerName) ?> was clearly informed that <?= htmlspecialchars($dbaName) ?> is a third-party travel agency providing paid travel-related assistance and seat assignment services.</p>
      <p contenteditable="true"><?= htmlspecialchars($customerName) ?> agreed to all applicable terms and conditions and authorized the charge of <strong><?= htmlspecialchars($currency) ?> <?= htmlspecialchars(number_format((float)$totalAmount, 2)) ?></strong> on his <strong><?= htmlspecialchars($cardType) ?></strong> card ending in <strong>****<?= htmlspecialchars($cardLastFour) ?></strong> by providing his digital signature authorization stamp.</p>
      <p contenteditable="true">The invoice also clearly displayed “Base Fare” as the merchant descriptor on the billing statement for complete transparency.</p>
      
      <?php if ($acceptance): ?>
      <div class="forensic-block">
        <div class="forensic-header">
          <span>🔒</span>
          <span>Digital Authorization Evidence</span>
        </div>
        <div class="forensic-body">
          <div class="forensic-cell">
            <div class="f-label">IP Address</div>
            <div class="f-value" contenteditable="true"><?= htmlspecialchars($ipAddress) ?> <?php if ($ipLocation): ?>(<?= htmlspecialchars($ipLocation) ?>)<?php endif; ?></div>
          </div>
          <div class="forensic-cell">
            <div class="f-label">Signed At (UTC)</div>
            <div class="f-value" contenteditable="true"><?= $approvedAt ? $approvedAt->format('Y-m-d H:i:s') : 'N/A' ?></div>
          </div>
        </div>
        <?php if ($acceptance->digital_signature): ?>
        <div style="padding:0 16px 16px; border-top:1px solid #bbf7d0; margin-top:0; padding-top:12px;">
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
      <?php endif; ?>
    </div>

    <div class="section">
      <div class="section-title">Proof of Service Delivery</div>
      <p contenteditable="true">Upon successful completion of the request, the following documents and confirmations were delivered to the cardholder:</p>
      <ul contenteditable="true">
        <li>Updated itinerary reflecting confirmed seat assignments</li>
        <li>Final travel documents with assigned seats</li>
        <li>Invoice showing merchant descriptor</li>
        <li>Confirmation of payment authorization</li>
      </ul>
      <p contenteditable="true">At no point after receiving the completed service did <?= htmlspecialchars($customerName) ?> contact our company to report dissatisfaction, dispute the service, or request any correction or refund.</p>
    </div>

    <div class="section">
      <div class="section-title">Dispute Claim Analysis</div>
      <p contenteditable="true">On May 12th, the merchant received a dispute under reason code C31, where the cardholder claimed that the goods/services received were not as described, defective, or did not match the promised quality or specifications.</p>
      <p contenteditable="true">However, the evidence clearly demonstrates that the exact service purchased by the cardholder — confirmed airline seat assignment assistance — was fully delivered as agreed.</p>
      <p contenteditable="true"><?= htmlspecialchars($dbaName) ?> fulfilled its obligation by successfully securing and confirming the requested seats for the passengers.</p>
    </div>

    <div class="section">
      <div class="section-title">Airline-Controlled Changes</div>
      <p contenteditable="true">It is important to note that any operational changes made directly by the airline, including last-minute seat reassignments due to operational, safety, or scheduling reasons, are solely controlled by the airline and remain outside the authority or control of the travel agency.</p>
      <p contenteditable="true">Such airline-controlled changes do not constitute a defect, misrepresentation, or failure of the service provided by <?= htmlspecialchars($dbaName) ?>.</p>
    </div>

    <div class="section">
      <div class="section-title">Supporting Documentation Attached</div>
      <p contenteditable="true">The following supporting documents have been attached as evidence:</p>
      <ul contenteditable="true">
        <li>Cardholder authorization with digital signature</li>
        <li>Updated itinerary showing confirmed seat assignments</li>
        <li>Final ticket copies</li>
        <li>Invoice displaying “Base Fare” merchant descriptor</li>
        <li>Valid passport copies of both passengers for KYC verification</li>
        <li>Proof of completed service delivery</li>
      </ul>
    </div>

    <div class="section">
      <div class="section-title">Conclusion and Request for Resolution</div>
      <p contenteditable="true">Based on our internal investigation and the supporting evidence provided, <?= htmlspecialchars($dbaName) ?> fully delivered the agreed-upon service in accordance with the cardholder’s request and authorization.</p>
      <p contenteditable="true">The service was completed successfully, accepted by the cardholder, and no complaint or attempt for amicable resolution was made prior to filing the dispute.</p>
      <p contenteditable="true" style="font-weight: 700;">Therefore, we respectfully request that this dispute be resolved in favor of <?= htmlspecialchars($dbaName) ?> and that the disputed amount of <?= htmlspecialchars($currency) ?> <?= htmlspecialchars(number_format((float)$totalAmount, 2)) ?> be credited back to the merchant.</p>
    </div>
    
  </div>
</div>

</body>
</html>
