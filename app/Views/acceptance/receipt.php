<?php
/**
 * Acceptance Request — Printable Receipt
 *
 * @var \App\Models\AcceptanceRequest $acceptance
 *
 * Only rendered when status === APPROVED (enforced by AcceptanceController::receipt())
 * Accessed via: GET /acceptance/{id}/receipt
 * Opens in new tab, print-optimized layout.
 */

use App\Models\AcceptanceRequest;
use Carbon\Carbon;

if (!isset($acceptance) || !$acceptance->isApproved()) {
    http_response_code(404);
    die('<p style="font-family:sans-serif;padding:2rem;">Receipt not available.</p>');
}

$fareBreakdown   = $acceptance->fare_breakdown   ?? [];

$dbaName = 'Lets Fly Travel LLC DBA Base Fare';
if (!empty($fareBreakdown) && is_array($fareBreakdown)) {
    $firstItem = reset($fareBreakdown);
    if ($firstItem && isset($firstItem['label']) && strtolower(trim($firstItem['label'])) === 'airline tickets') {
        $dbaName = 'Airline Tickets';
    }
}

$passengers      = $acceptance->passengers       ?? [];
$flightData      = $acceptance->flight_data      ?? [];
$additionalCards = $acceptance->additional_cards ?? [];
$extraData       = $acceptance->extra_data       ?? [];
if (is_string($extraData)) $extraData = json_decode($extraData, true) ?: [];

// Type-specific extra_data fields
$crRefundAmt  = $extraData['refund_amount']   ?? null;
$crCancelFee  = $extraData['cancel_fee']      ?? null;
$crMethod     = $extraData['refund_method']   ?? null;
$crTimeline   = $extraData['refund_timeline'] ?? null;
$ccCreditAmt  = $extraData['credit_amount']   ?? null;
$ccValidUntil = $extraData['valid_until']     ?? null;
$ccEtktList   = $extraData['etkt_list']       ?? [];
$ccInstructions = $extraData['instructions']  ?? null;
$otherTitle   = $extraData['other_title']     ?? '';
$otherNotes   = $extraData['other_notes']     ?? '';
$seatNumber   = $extraData['seat_number']     ?? '';

// Collect all segment groups
$segGroups = [];
$filterSegs = fn($arr) => is_array($arr)
    ? array_values(array_filter($arr, fn($s) => !empty($s['from']) && !empty($s['to'])))
    : [];

if (!empty($flightData['flights']))     $segGroups[] = ['title' => 'Flight Itinerary',           'segs' => $filterSegs($flightData['flights']),     'accent' => '#1a3a6b'];
if (!empty($flightData['old_flights'])) $segGroups[] = ['title' => 'Original Flights',           'segs' => $filterSegs($flightData['old_flights']), 'accent' => '#9f1239'];
if (!empty($flightData['new_flights'])) $segGroups[] = ['title' => 'New Flights (After Change)', 'segs' => $filterSegs($flightData['new_flights']), 'accent' => '#065f46'];

$segGroups = array_filter($segGroups, fn($grp) => !empty($grp['segs']));


// Primary airline for logo
$primaryIata = '';
foreach ($segGroups as $g) {
    if (!empty($g['segs'][0]['airline_iata'])) {
        $primaryIata = strtoupper($g['segs'][0]['airline_iata']);
        break;
    }
}
$logoUrl = $primaryIata ? AcceptanceRequest::airlineLogoUrl($primaryIata, 70) : '';

// Helpers
function rh(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$CITIES_R = [
    'YYZ'=>'Toronto','YVR'=>'Vancouver','YUL'=>'Montreal','YYC'=>'Calgary',
    'LHR'=>'London','LGW'=>'Gatwick','CDG'=>'Paris','FRA'=>'Frankfurt','AMS'=>'Amsterdam',
    'MAD'=>'Madrid','FCO'=>'Rome','ZRH'=>'Zurich','IST'=>'Istanbul',
    'DXB'=>'Dubai','DOH'=>'Doha','AUH'=>'Abu Dhabi',
    'BOM'=>'Mumbai','DEL'=>'New Delhi','BLR'=>'Bangalore','MAA'=>'Chennai','HYD'=>'Hyderabad',
    'JFK'=>'New York JFK','EWR'=>'Newark','LAX'=>'Los Angeles','SFO'=>'San Francisco',
    'ORD'=>'Chicago','MIA'=>'Miami','DFW'=>'Dallas','SEA'=>'Seattle','BOS'=>'Boston',
    'ATL'=>'Atlanta','DEN'=>'Denver','NRT'=>'Tokyo','HND'=>'Tokyo Haneda',
    'ICN'=>'Seoul','SIN'=>'Singapore','HKG'=>'Hong Kong','BKK'=>'Bangkok',
    'SYD'=>'Sydney','MEL'=>'Melbourne','QF'=>'Qantas',
];

$AIRLINES_R = [
    'AC'=>'Air Canada','WS'=>'WestJet','AA'=>'American Airlines','DL'=>'Delta Air Lines','UA'=>'United Airlines',
    'BA'=>'British Airways','LH'=>'Lufthansa','AF'=>'Air France','KL'=>'KLM Royal Dutch','EK'=>'Emirates',
    'QR'=>'Qatar Airways','SQ'=>'Singapore Airlines','CX'=>'Cathay Pacific','JL'=>'Japan Airlines',
    'NH'=>'All Nippon Airways','TK'=>'Turkish Airlines','EY'=>'Etihad Airways','LX'=>'Swiss International','OS'=>'Austrian Airlines',
    'AI'=>'Air India','TP'=>'TAP Air Portugal','VS'=>'Virgin Atlantic','KE'=>'Korean Air',
    'TG'=>'Thai Airways','MH'=>'Malaysia Airlines','B6'=>'JetBlue Airways','AS'=>'Alaska Airlines',
    'F9'=>'Frontier Airlines','NK'=>'Spirit Airlines','WN'=>'Southwest Airlines','AM'=>'Aeromexico',
    'CM'=>'Copa Airlines','AV'=>'Avianca','LA'=>'LATAM Airlines','QF'=>'Qantas Airways','NZ'=>'Air New Zealand',
    'BR'=>'EVA Air','CI'=>'China Airlines','CZ'=>'China Southern','MU'=>'China Eastern','CA'=>'Air China',
    'HU'=>'Hainan Airlines','VN'=>'Vietnam Airlines','PR'=>'Philippine Airlines','GA'=>'Garuda Indonesia',
    'UL'=>'SriLankan Airlines','SV'=>'Saudia','MS'=>'EgyptAir','ET'=>'Ethiopian Airlines',
    'AT'=>'Royal Air Maroc','HA'=>'Hawaiian Airlines','G4'=>'Allegiant Air','AD'=>'Azul Brazilian Airlines',
];

$receiptNumber = 'BF-' . str_pad($acceptance->id, 6, '0', STR_PAD_LEFT);
$approvedAt    = Carbon::parse($acceptance->approved_at);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Receipt <?= rh($receiptNumber) ?> — <?= rh($acceptance->customer_name) ?> — <?= rh($dbaName) ?></title>
<meta name="robots" content="noindex, nofollow"/>
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
  max-width: 750px;
  margin: 0 auto;
  background: white;
  box-shadow: 0 4px 24px rgba(0,0,0,0.08);
  border-radius: 12px;
  overflow: hidden;
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
.receipt-date { color: #cbd5e1; font-size: 11px; margin-top: 2px; }

/* ── Status badge ── */
.status-strip {
  background: #d1fae5;
  border-bottom: 2px solid #6ee7b7;
  padding: 10px 32px;
  display: flex;
  align-items: center;
  gap: 10px;
}
.status-icon { font-size: 20px; }
.status-text { font-weight: 800; font-size: 13px; color: #064e3b; font-family: Manrope,sans-serif; }
.status-sub  { font-size: 11px; color: #047857; margin-top: 1px; }
.status-time { margin-left: auto; font-size: 11px; color: #065f46; font-weight: 600; }

/* ── Body ── */
.body { padding: 28px 32px; }

/* ── Section wrapper ── */
.section { margin-bottom: 22px; }
.section-title {
  font-size: 10px;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 1.5px;
  color: #94a3b8;
  margin-bottom: 10px;
  padding-bottom: 6px;
  border-bottom: 1px solid #f1f5f9;
}

/* ── Grid ── */
.info-grid { display: grid; gap: 10px; }
.info-grid-2 { grid-template-columns: 1fr 1fr; }
.info-grid-3 { grid-template-columns: 1fr 1fr 1fr; }
.info-cell { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 10px 14px; }
.info-label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.8px; color: #94a3b8; margin-bottom: 4px; }
.info-value { font-size: 13px; font-weight: 600; color: #1e293b; }
.info-value.mono { font-family: 'Courier New', monospace; letter-spacing: 1px; font-weight: 700; color: #0f1e3c; font-size: 14px; }
.info-value.large { font-size: 18px; font-weight: 900; color: #0f1e3c; }
.info-value.gold { color: #92400e; }

/* ── Passengers ── */
.pax-chip {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  background: #f1f5f9;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  padding: 6px 12px;
  font-size: 12px;
  font-weight: 700;
  font-family: 'Courier New', monospace;
  margin: 3px;
  color: #1e293b;
}
.pax-type { font-size: 9px; font-weight: 700; color: #64748b; text-transform: uppercase; }

/* ── Flight segment card ── */
.seg-card {
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  overflow: hidden;
  margin-bottom: 8px;
  display: flex;
}
.seg-airline-bar {
  width: 72px;
  flex-none;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 12px 8px;
  gap: 4px;
}
.seg-airline-code { font-size: 11px; font-weight: 900; color: #fff; }
.seg-airline-name { font-size: 8px; color: rgba(255,255,255,0.7); text-align: center; line-height: 1.2; }
.seg-body {
  flex: 1;
  display: grid;
  grid-template-columns: 1fr auto 1fr auto;
  align-items: center;
  gap: 8px;
  padding: 14px 16px;
}
.seg-port { }
.seg-time { font-size: 20px; font-weight: 900; color: #0f1e3c; line-height: 1; }
.seg-code { font-size: 13px; font-weight: 800; color: #1a3a6b; }
.seg-city { font-size: 10px; color: #94a3b8; }
.seg-arrow { font-size: 18px; color: #cbd5e1; text-align: center; }
.seg-meta { text-align: right; }
.seg-flight { font-size: 11px; font-weight: 800; color: #475569; font-family: monospace; }
.seg-class  { font-size: 9px; background: #f1f5f9; border: 1px solid #e2e8f0; color: #64748b; padding: 2px 6px; border-radius: 4px; font-family: monospace; }
.seg-date   { font-size: 10px; color: #94a3b8; margin-top: 4px; }
.next-day   { font-size: 9px; font-weight: 700; background: #fee2e2; color: #991b1b; padding: 1px 4px; border-radius: 3px; margin-left: 3px; }
.layover-bar { background: #fffbeb; border: 1px solid #fde68a; border-radius: 5px; padding: 5px 12px; font-size: 10px; font-weight: 700; color: #92400e; margin-bottom: 8px; display: flex; align-items: center; gap: 6px; }

/* ── Segment group header ── */
.group-header {
  font-size: 10px;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 1px;
  padding: 5px 10px;
  border-radius: 5px;
  margin-bottom: 8px;
  margin-top: 2px;
}
.group-header.blue    { background: #eff6ff; color: #1e40af; }
.group-header.rose    { background: #fff1f2; color: #9f1239; }
.group-header.emerald { background: #ecfdf5; color: #065f46; }

/* ── Fare table ── */
.fare-table { width: 100%; border-collapse: collapse; }
.fare-table td { padding: 9px 14px; border-bottom: 1px solid #f1f5f9; }
.fare-table td:last-child { text-align: right; font-family: 'Courier New', monospace; font-weight: 700; }
.fare-total { background: #0f1e3c; }
.fare-total td { color: #fff; font-weight: 800; border: none; font-size: 15px; }
.fare-total td:last-child { color: #4ade80; font-size: 17px; }

/* ── Card pills ── */
.card-pill {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 8px 14px;
}
.card-label { font-size: 11px; font-weight: 700; color: #475569; }
.card-mask  { font-family: monospace; font-size: 12px; color: #94a3b8; }

/* ── Forensic block ── */
.forensic-block {
  background: #ecfdf5;
  border: 1px solid #6ee7b7;
  border-radius: 8px;
  overflow: hidden;
}
.forensic-header {
  background: #065f46;
  padding: 8px 16px;
  display: flex;
  align-items: center;
  gap: 8px;
}
.forensic-header span { color: #fff; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 1px; }
.forensic-body { padding: 14px 16px; display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; }
.forensic-cell .f-label { font-size: 9px; font-weight: 800; text-transform: uppercase; color: #047857; margin-bottom: 3px; }
.forensic-cell .f-value { font-size: 11px; font-family: monospace; font-weight: 700; color: #064e3b; word-break: break-all; }

/* ── Policy ── */
.policy-text { font-size: 10px; color: #64748b; line-height: 1.7; white-space: pre-wrap; background: #fefce8; border: 1px solid #fde68a; border-radius: 8px; padding: 12px 14px; }

/* ── Footer ── */
.receipt-footer {
  background: #f8fafc;
  border-top: 1px solid #e2e8f0;
  padding: 16px 32px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  font-size: 10px;
  color: #94a3b8;
}
.receipt-footer .brand { font-weight: 700; color: #0f1e3c; }
.receipt-footer .lock { color: #10b981; font-size: 12px; margin-right: 4px; }

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
@media print { .print-btn { display: none !important; } }
</style>
</head>
<body>

<!-- Print Button (visible on screen only) -->
<button class="print-btn no-print" onclick="window.print()">
  🖨️ Print / Save PDF
</button>

<div class="page">

  <!-- ── HEADER ── -->
  <div class="receipt-header">
    <div class="brand-block">
      <div style="display:flex;align-items:center;gap:12px;margin-bottom:6px;">

        <div>
          <div class="brand-name"><?= htmlspecialchars(strtoupper($dbaName)) ?></div>
        </div>
      </div>
      <div class="brand-sub">Authorized Travel Services &mdash; <?= rh(AcceptanceRequest::COMPANY_EMAIL) ?></div>
    </div>
    <div class="receipt-meta">
      <div class="receipt-lbl">Receipt No.</div>
      <div class="receipt-no"><?= rh($receiptNumber) ?></div>
      <div class="receipt-date"><?= $approvedAt->format('F j, Y') ?></div>
    </div>
  </div>

  <!-- ── STATUS STRIP ── -->
  <div class="status-strip">
    <div class="status-icon">✅</div>
    <div>
      <div class="status-text">Authorization Confirmed</div>
      <div class="status-sub"><?= rh($acceptance->typeLabel()) ?><?= $acceptance->pnr ? ' &mdash; PNR: <strong>' . rh($acceptance->pnr) . '</strong>' : '' ?></div>
    </div>
    <div class="status-time">
      Signed: <?= $approvedAt->format('M j, Y') ?> at <?= $approvedAt->format('g:i:s A') ?> UTC
    </div>
  </div>

  <!-- ── BODY ── -->
  <div class="body">

    <!-- ── CUSTOMER INFO ── -->
    <div class="section">
      <div class="section-title">Customer Information</div>
      <div class="info-grid info-grid-3">
        <div class="info-cell">
          <div class="info-label">Customer Name</div>
          <div class="info-value"><?= rh($acceptance->customer_name) ?></div>
        </div>
        <div class="info-cell">
          <div class="info-label">Email Address</div>
          <div class="info-value" style="font-size:12px;"><?= rh($acceptance->customer_email) ?></div>
        </div>
        <?php if ($acceptance->pnr): ?>
        <div class="info-cell">
          <div class="info-label">PNR / Booking Ref</div>
          <div class="info-value mono"><?= rh($acceptance->pnr) ?></div>
        </div>
        <?php endif; ?>
        <?php if ($acceptance->order_id): ?>
        <div class="info-cell">
          <div class="info-label">Order ID</div>
          <div class="info-value mono"><?= rh($acceptance->order_id) ?></div>
        </div>
        <?php endif; ?>
        <?php if ($acceptance->airline): ?>
        <div class="info-cell">
          <div class="info-label">Airline</div>
          <div class="info-value"><?= rh($acceptance->airline) ?></div>
        </div>
        <?php endif; ?>
        <?php if ($acceptance->customer_phone): ?>
        <div class="info-cell">
          <div class="info-label">Phone</div>
          <div class="info-value"><?= rh($acceptance->customer_phone) ?></div>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- ── PASSENGERS ── -->
    <?php if (!empty($passengers)): ?>
    <div class="section">
      <div class="section-title">Passengers (<?= count($passengers) ?>)</div>
      <div>
        <?php foreach ($passengers as $pax): ?>
        <span class="pax-chip">
          <?= rh(strtoupper($pax['name'] ?? '')) ?>
          <span class="pax-type"><?= rh(strtoupper($pax['type'] ?? 'ADT')) ?></span>
          <?php if (!empty($pax['dob'])): ?>
          <span style="font-size:10px;color:#94a3b8;font-family:sans-serif;font-weight:400;"><?= rh($pax['dob']) ?></span>
          <?php endif; ?>
        </span>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- ── FLIGHT SEGMENTS ── -->
    <?php if (!empty($segGroups)): ?>
    <div class="section">
      <div class="section-title">Flight Details</div>
      <?php foreach ($segGroups as $grp):
        $colorClass = match($grp['accent']) { '#9f1239' => 'rose', '#065f46' => 'emerald', default => 'blue' };
        if (count($segGroups) > 1): ?>
      <div class="group-header <?= $colorClass ?>"><?= rh($grp['title']) ?></div>
      <?php endif;
        foreach ($grp['segs'] as $idx => $seg):
          $iata   = strtoupper($seg['airline_iata'] ?? '');
          $aName  = $AIRLINES_R[$iata] ?? '';
          $from   = strtoupper($seg['from'] ?? '');
          $to     = strtoupper($seg['to'] ?? '');
          $fCity  = $CITIES_R[$from] ?? '';
          $tCity  = $CITIES_R[$to] ?? '';
          $logo35 = $iata ? "https://www.gstatic.com/flights/airline_logos/35px/{$iata}.png" : '';
          $nd     = !empty($seg['arr_next_day']);
      ?>
      <div class="seg-card">
        <div class="seg-airline-bar" style="background:<?= rh($grp['accent']) ?>;">
          <?php if ($logo35): ?>
          <img src="<?= rh($logo35) ?>" alt="<?= rh($iata) ?>"
            style="width:28px;height:28px;object-fit:contain;margin-bottom:4px;"
            onerror="this.style.display='none'">
          <?php endif; ?>
          <div class="seg-airline-code"><?= rh($iata) ?></div>
          <?php if ($aName): ?>
          <div class="seg-airline-name"><?= rh($aName) ?></div>
          <?php endif; ?>
        </div>
        <div class="seg-body">
          <div class="seg-port">
            <div class="seg-time"><?= rh($seg['dep_time'] ?? '') ?></div>
            <div class="seg-code"><?= rh($from) ?></div>
            <?php if ($fCity): ?><div class="seg-city"><?= rh($fCity) ?></div><?php endif; ?>
          </div>
          <div class="seg-arrow">→</div>
          <div class="seg-port">
            <div class="seg-time">
              <?= rh($seg['arr_time'] ?? '') ?>
              <?php if ($nd): ?><span class="next-day">+1d</span><?php endif; ?>
            </div>
            <div class="seg-code"><?= rh($to) ?></div>
            <?php if ($tCity): ?><div class="seg-city"><?= rh($tCity) ?></div><?php endif; ?>
          </div>
          <div class="seg-meta" style="text-align:right;">
            <div class="seg-flight"><?= rh($seg['flight_no'] ?? '') ?></div>
            <?php if (!empty($seg['cabin_class'])): ?>
            <span class="seg-class"><?= rh($seg['cabin_class']) ?></span>
            <?php endif; ?>
            <div class="seg-date"><?= rh($seg['date'] ?? '') ?></div>
          </div>
        </div>
      </div>
      <?php if ($idx < count($grp['segs']) - 1): ?>
      <div class="layover-bar">⏱ Layover in <?= rh($CITIES_R[$to] ?? $to) ?></div>
      <?php endif; ?>
      <?php endforeach; ?>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Name Correction -->
    <?php if ($acceptance->type === 'name_correction' && !empty($flightData['old_name'])): ?>
    <div class="section">
      <div class="section-title">Name Correction</div>
      <div style="display:flex;align-items:center;gap:12px;">
        <div class="info-cell" style="background:#fff1f2;border-color:#fecdd3;flex:1;">
          <div class="info-label" style="color:#9f1239;">Original Name</div>
          <div class="info-value mono"><?= rh($flightData['old_name']) ?></div>
        </div>
        <div style="font-size:20px;color:#94a3b8;">→</div>
        <div class="info-cell" style="background:#ecfdf5;border-color:#bbf7d0;flex:1;">
          <div class="info-label" style="color:#065f46;">Corrected Name</div>
          <div class="info-value mono"><?= rh($flightData['new_name'] ?? '') ?></div>
        </div>
      </div>
      <?php if (!empty($flightData['reason'])): ?>
      <div style="margin-top:8px;font-size:12px;color:#78350f;background:#fef3c7;border:1px solid #fde68a;border-radius:6px;padding:8px 10px;">
        <strong>Reason:</strong> <?= rh($flightData['reason']) ?>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Cabin Upgrade -->
    <?php if (!$acceptance->is_preauth && $acceptance->type === 'cabin_upgrade' && !empty($flightData['old_cabin'])): ?>
    <div class="section">
      <div class="section-title">Cabin Upgrade</div>
      <div style="display:flex;align-items:center;gap:12px;">
        <div class="info-cell" style="background:#fff1f2;border-color:#fecdd3;flex:1;text-align:center;">
          <div class="info-label" style="color:#9f1239;">From Class</div>
          <div class="info-value"><?= rh($flightData['old_cabin']) ?></div>
        </div>
        <div style="font-size:20px;color:#94a3b8;">→</div>
        <div class="info-cell" style="background:#ecfdf5;border-color:#bbf7d0;flex:1;text-align:center;">
          <div class="info-label" style="color:#065f46;">To Class</div>
          <div class="info-value"><?= rh($flightData['new_cabin'] ?? '') ?></div>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Other description -->
    <?php if ($acceptance->type === 'other' && ($otherTitle || $otherNotes)): ?>
    <div class="section">
      <div class="section-title">Authorization Details</div>
      <?php if ($otherTitle): ?>
      <div style="font-size:14px;font-weight:700;color:#1e293b;margin-bottom:6px;"><?= rh($otherTitle) ?></div>
      <?php endif; ?>
      <?php if ($otherNotes): ?>
      <div class="policy-text" style="background:#f8fafc;border-color:#e2e8f0;"><?= rh($otherNotes) ?></div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Cancel / Refund details -->
    <?php if (!$acceptance->is_preauth && $acceptance->type === 'cancel_refund' && ($crRefundAmt !== null || $crCancelFee || $crMethod || $crTimeline)): ?>
    <div class="section">
      <div class="section-title" style="color:#9f1239;">Cancellation &amp; Refund Summary</div>
      <div class="info-grid info-grid-2">
        <?php if ($crRefundAmt !== null): ?>
        <div class="info-cell" style="background:#fff1f2;border-color:#fecdd3;">
          <div class="info-label" style="color:#9f1239;">Refund Amount</div>
          <div class="info-value" style="color:#dc2626;"><?= rh($acceptance->currency) ?> <?= number_format((float)$crRefundAmt, 2) ?></div>
        </div>
        <?php endif; ?>
        <?php if ($crCancelFee): ?>
        <div class="info-cell">
          <div class="info-label">Cancellation Fee</div>
          <div class="info-value"><?= rh($acceptance->currency) ?> <?= number_format((float)$crCancelFee, 2) ?></div>
        </div>
        <?php endif; ?>
        <?php if ($crMethod): ?>
        <div class="info-cell">
          <div class="info-label">Refund Method</div>
          <div class="info-value"><?= rh(ucwords(str_replace('_', ' ', $crMethod))) ?></div>
        </div>
        <?php endif; ?>
        <?php if ($crTimeline): ?>
        <div class="info-cell">
          <div class="info-label">Expected Timeline</div>
          <div class="info-value"><?= rh($crTimeline) ?></div>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Cancel / Credit details -->
    <?php if (!$acceptance->is_preauth && $acceptance->type === 'cancel_credit' && ($ccCreditAmt !== null || $ccValidUntil || !empty($ccEtktList) || $ccInstructions)): ?>
    <div class="section">
      <div class="section-title" style="color:#4c1d95;">Future Travel Credit Summary</div>
      <div class="info-grid info-grid-2">
        <?php if ($ccCreditAmt !== null): ?>
        <div class="info-cell" style="background:#f5f3ff;border-color:#ddd6fe;">
          <div class="info-label" style="color:#4c1d95;">Credit Value</div>
          <div class="info-value" style="color:#7c3aed;"><?= rh($acceptance->currency) ?> <?= number_format((float)$ccCreditAmt, 2) ?></div>
        </div>
        <?php endif; ?>
        <?php if ($ccValidUntil): ?>
        <div class="info-cell">
          <div class="info-label">Valid Until</div>
          <div class="info-value"><?= rh(date('M d, Y', strtotime($ccValidUntil))) ?></div>
        </div>
        <?php endif; ?>
      </div>
      <?php if (!empty($ccEtktList)): ?>
      <div style="margin-top:10px;">
        <div class="section-title" style="color:#6d28d9;">E-Ticket Numbers</div>
        <?php foreach ($ccEtktList as $row): ?>
        <div style="display:flex;align-items:center;gap:10px;padding:8px 10px;background:#ede9fe;border-radius:8px;margin-bottom:6px;">
          <span style="font-size:13px;color:#4c1d95;font-weight:600;flex:1;"><?= rh($row['pax_name'] ?? '') ?></span>
          <span style="font-size:12px;font-family:monospace;color:#6d28d9;font-weight:700;"><?= rh($row['etkt'] ?? '—') ?></span>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <?php if ($ccInstructions): ?>
      <p style="font-size:12px;color:#5b21b6;margin-top:10px;line-height:1.6;padding:8px 10px;background:#ede9fe;border-radius:8px;">
        <strong>Note:</strong> <?= nl2br(rh($ccInstructions)) ?>
      </p>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- ── FARE BREAKDOWN + PAYMENT ── -->
    <div class="section">
      <div class="section-title">Fare Breakdown & Payment Authorization</div>
      <table class="fare-table">
        <tbody>
          <?php foreach ($fareBreakdown as $item): ?>
          <tr>
            <td style="font-size:13px;color:#475569;"><?= rh($item['label'] ?? '') ?></td>
            <td style="font-size:13px;"><?= rh($acceptance->currency) ?> <?= number_format((float)($item['amount'] ?? 0), 2) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr class="fare-total">
            <td>Total Amount Authorized</td>
            <td><?= rh($acceptance->currency) ?> <?= number_format($acceptance->total_amount, 2) ?></td>
          </tr>
        </tfoot>
      </table>
    </div>

    <!-- Cards -->
    <div class="section" style="margin-top:14px;">
      <div class="section-title">Payment Card(s) &amp; Billing</div>
      <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:4px;">
        <div class="card-pill">
          <span style="font-size:18px;">💳</span>
          <div>
            <div class="card-label"><?= rh($acceptance->cardholder_name) ?></div>
            <div class="card-mask"><?= rh($acceptance->card_type) ?> &middot; **** **** **** <?= rh($acceptance->card_last_four ?? '****') ?></div>
            <?php if ($acceptance->billing_address): ?>
            <div style="font-size:10px;color:#64748b;margin-top:3px;"><?= rh($acceptance->billing_address) ?></div>
            <?php endif; ?>
          </div>
        </div>
        <?php foreach ($additionalCards as $c): ?>
        <div class="card-pill">
          <span style="font-size:18px;">💳</span>
          <div>
            <div class="card-label"><?= rh($c['cardholder_name'] ?? '') ?></div>
            <div class="card-mask"><?= rh($c['card_type'] ?? '') ?> &middot; *<?= rh($c['card_last_four'] ?? '***') ?></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php if ($acceptance->statement_descriptor): ?>
      <p style="font-size:11px;color:#94a3b8;margin-top:8px;">Statement descriptor: <strong style="color:#475569;"><?= rh($acceptance->statement_descriptor) ?></strong></p>
      <?php endif; ?>
      <?php if ($acceptance->split_charge_note): ?>
      <p style="font-size:11px;color:#92400e;background:#fffbeb;border:1px solid #fde68a;border-radius:6px;padding:6px 10px;margin-top:8px;"><?= rh($acceptance->split_charge_note) ?></p>
      <?php endif; ?>
    </div>

    <!-- ── TICKET CONDITIONS ── -->
    <?php if ($acceptance->endorsements || $acceptance->baggage_info || $acceptance->fare_rules || $seatNumber): ?>
    <div class="section">
      <div class="section-title">Ticket Conditions</div>
      <div class="info-grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));">
        <?php if ($acceptance->endorsements): ?>
        <div class="info-cell">
          <div class="info-label">Endorsements</div>
          <div class="info-value mono" style="font-size:12px;"><?= rh($acceptance->endorsements) ?></div>
        </div>
        <?php endif; ?>
        <?php if ($acceptance->baggage_info): ?>
        <div class="info-cell">
          <div class="info-label">Baggage Allowance</div>
          <div class="info-value" style="font-size:12px;"><?= rh($acceptance->baggage_info) ?></div>
        </div>
        <?php endif; ?>
        <?php if ($seatNumber): ?>
        <div class="info-cell">
          <div class="info-label">Seat Number(s)</div>
          <div class="info-value" style="font-size:12px; font-weight: 700;"><?= rh($seatNumber) ?></div>
        </div>
        <?php endif; ?>
      </div>
      <?php if ($acceptance->fare_rules): ?>
      <div class="info-cell" style="margin-top:8px;">
        <div class="info-label">Fare Rules</div>
        <div style="font-size:11px;color:#64748b;white-space:pre-wrap;line-height:1.6;margin-top:4px;"><?= rh($acceptance->fare_rules) ?></div>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- ── UPLOADED EVIDENCE DOCUMENTS ── -->
    <?php if ($acceptance->passport_image || $acceptance->card_image_front): ?>
    <div class="section">
      <div class="section-title">📎 Uploaded Evidence Documents</div>
      <div style="display:flex;flex-wrap:wrap;gap:12px;margin-top:4px;">

        <?php if ($acceptance->passport_image): ?>
        <a href="/acceptance/<?= $acceptance->id ?>/download/passport"
           style="display:inline-flex;align-items:center;gap:10px;padding:12px 16px;background:#eff6ff;border:1.5px solid #bfdbfe;border-radius:10px;text-decoration:none;color:#1e40af;font-size:12px;font-weight:700;transition:background 0.15s;"
           onmouseover="this.style.background='#dbeafe'" onmouseout="this.style.background='#eff6ff'">
          <span style="font-size:22px;">🪪</span>
          <div>
            <div style="font-size:11px;font-weight:800;color:#1d4ed8;">Passport / Government ID</div>
            <div style="font-size:10px;color:#3b82f6;font-family:monospace;margin-top:2px;"><?= rh($acceptance->passport_image) ?></div>
            <div style="font-size:10px;color:#6b7280;margin-top:2px;">⬇ Click to download</div>
          </div>
        </a>
        <?php endif; ?>

        <?php if ($acceptance->card_image_front): ?>
        <a href="/acceptance/<?= $acceptance->id ?>/download/cc_front"
           style="display:inline-flex;align-items:center;gap:10px;padding:12px 16px;background:#f0fdf4;border:1.5px solid #bbf7d0;border-radius:10px;text-decoration:none;color:#065f46;font-size:12px;font-weight:700;transition:background 0.15s;"
           onmouseover="this.style.background='#dcfce7'" onmouseout="this.style.background='#f0fdf4'">
          <span style="font-size:22px;">💳</span>
          <div>
            <div style="font-size:11px;font-weight:800;color:#047857;">Credit Card (Front)</div>
            <div style="font-size:10px;color:#10b981;font-family:monospace;margin-top:2px;"><?= rh($acceptance->card_image_front) ?></div>
            <div style="font-size:10px;color:#6b7280;margin-top:2px;">⬇ Click to download</div>
          </div>
        </a>
        <?php endif; ?>

      </div>
      <div style="margin-top:8px;font-size:10px;color:#94a3b8;font-style:italic;">
        🔒 These files are securely stored and accessible to authorized personnel only.
      </div>
    </div>
    <?php endif; ?>

    <!-- ── FORENSIC AUDIT ── -->
    <div class="section">
      <div class="section-title">Forensic Authorization Record</div>
      <div class="forensic-block">
        <div class="forensic-header">
          <span>🔒</span>
          <span>Chargeback Defense Evidence — Signed &amp; Timestamped</span>
        </div>
        <div class="forensic-body">
          <div class="forensic-cell">
            <div class="f-label">Signed At (UTC)</div>
            <div class="f-value"><?= $approvedAt->format('Y-m-d H:i:s') ?></div>
          </div>
          <div class="forensic-cell">
            <div class="f-label">IP Address</div>
            <div class="f-value"><?= rh($acceptance->ip_address ?? 'not captured') ?></div>
          </div>
          <div class="forensic-cell">
            <div class="f-label">Link First Viewed</div>
            <div class="f-value">
              <?= $acceptance->viewed_at ? Carbon::parse($acceptance->viewed_at)->format('Y-m-d H:i') : 'N/A' ?>
            </div>
          </div>
        </div>
        <?php if ($acceptance->user_agent): ?>
        <div style="padding:0 16px 12px; font-size:10px; color:#065f46; border-top:1px solid #bbf7d0; margin-top:0; padding-top:10px;">
          <strong>Device:</strong> <?= rh($acceptance->user_agent) ?>
        </div>
        <?php endif; ?>
        <?php if ($acceptance->digital_signature): ?>
        <div style="padding:0 16px 12px; border-top:1px solid #bbf7d0; margin-top:0; padding-top:10px;">
          <div class="f-label" style="color:#047857;font-size:9px;margin-bottom:6px;">Digital Signature</div>
          <?php
          $sigFilename = $acceptance->digital_signature;
          $sigFile     = __DIR__ . '/../../../storage/acceptance/signatures/' . $sigFilename;
          $isJson      = str_ends_with($sigFilename, '_esign.json');
          $isPng       = str_ends_with($sigFilename, '_sig.png');

          if ($isJson && file_exists($sigFile)):
            // New e-sign consent system — decode and show verified badge
            $sigPayload = @json_decode(file_get_contents($sigFile), true);
            $sigSigner  = $sigPayload['signer']    ?? $acceptance->customer_name;
            $sigTs      = $sigPayload['timestamp'] ?? null;
            $sigTsFmt   = $sigTs ? date('M j, Y g:i A', strtotime($sigTs)) . ' UTC' : '—';
          ?>
          <div style="display:inline-flex;align-items:center;gap:10px;background:#f0fdf4;border:2px solid #6ee7b7;border-radius:8px;padding:10px 14px;">
            <span style="font-size:22px;">✅</span>
            <div>
              <div style="font-size:12px;font-weight:800;color:#065f46;">Digitally Signed — Legally Verified</div>
              <div style="font-size:11px;color:#047857;margin-top:2px;">Signer: <strong><?= rh($sigSigner) ?></strong></div>
              <div style="font-size:10px;color:#047857;margin-top:1px;font-family:monospace;">Signed at: <?= rh($sigTsFmt) ?></div>
            </div>
          </div>
          <div style="font-size:9px;color:#6ee7b7;margin-top:5px;font-family:monospace;">ref: <?= rh($sigFilename) ?></div>

          <?php elseif ($isPng && file_exists($sigFile)):
            // Legacy canvas PNG signature
            $sigData = base64_encode(file_get_contents($sigFile));
          ?>
          <img src="data:image/png;base64,<?= $sigData ?>"
            alt="Customer Signature" style="max-height:60px;max-width:220px;border:1px solid #bbf7d0;background:#fff;border-radius:6px;padding:4px;display:block;">

          <?php else: ?>
          <div class="f-value" style="color:#6ee7b7;">
            ✅ Signature on file — <?= rh($sigFilename) ?>
          </div>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- ── POLICY AGREED TO ── -->
    <?php if ($acceptance->policy_text): ?>
    <div class="section">
      <div class="section-title">Authorization Policy (Customer Agreed To)</div>
      <div class="policy-text"><?= rh(str_ireplace(['Lets Fly Travel LLC DBA Base Fare', 'Lets Fly Travel DBA Base Fare'], $dbaName, $acceptance->policy_text)) ?></div>
    </div>
    <?php endif; ?>

    <!-- ── AGENT / INTERNAL NOTES (Chargeback Defense) ── -->
    <?php if ($acceptance->agent_notes): ?>
    <div class="section">
      <div class="section-title" style="color:#92400e;border-color:#fde68a;">⚠ Internal Transaction Notes (Chargeback Defense Record)</div>
      <div style="background:#fffbeb;border:2px solid #f59e0b;border-radius:8px;padding:14px 16px;">
        <div style="font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:1px;color:#92400e;margin-bottom:6px;">Agent Notes — NOT shared with customer</div>
        <div style="font-size:12px;color:#1e293b;line-height:1.7;white-space:pre-wrap;"><?= rh($acceptance->agent_notes) ?></div>
      </div>
    </div>
    <?php endif; ?>

  </div><!-- /body -->

  <!-- ── FOOTER ── -->
  <div class="receipt-footer">
    <div>
      <span class="brand"><?= htmlspecialchars($dbaName) ?></span><br>
      <?= rh(AcceptanceRequest::COMPANY_EMAIL) ?> | Receipt: <strong><?= rh($receiptNumber) ?></strong>
    </div>
    <div style="text-align:right;">
      <span class="lock">🔒</span> Digitally verified authorization<br>
      Generated: <?= Carbon::now()->format('M j, Y g:i A') ?>
    </div>
  </div>

</div><!-- /page -->

<script>
// Auto-open print dialog if ?print=1 is in URL (e.g. from controller redirect)
if (new URLSearchParams(window.location.search).get('print') === '1') {
  window.addEventListener('load', () => setTimeout(() => window.print(), 300));
}
</script>

</body>
</html>
