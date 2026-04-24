<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <title>Electronic Ticket — <?= htmlspecialchars($eticket->pnr) ?> | Lets Fly Travel</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@600;700&display=swap" rel="stylesheet">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    body {
      font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
      background: #f0f4f8;
      color: #1e293b;
      min-height: 100vh;
      padding: 20px 16px 60px;
    }

    .page-wrap { max-width: 820px; margin: 0 auto; }

    /* ──────────────────────────────── TICKET CARD */
    .ticket {
      background: #fff;
      border-radius: 16px;
      overflow: hidden;
      box-shadow: 0 8px 40px rgba(0,0,0,.12);
      margin-bottom: 20px;
    }

    /* Header */
    .ticket-header {
      background: linear-gradient(135deg, #0f1e3c 0%, #1a3a6b 100%);
      padding: 30px 36px;
      position: relative;
      overflow: hidden;
    }
    .ticket-header::before {
      content: '✈';
      position: absolute;
      right: 30px;
      top: 50%;
      transform: translateY(-50%);
      font-size: 80px;
      opacity: .05;
    }
    .company-name { color: #fff; font-size: 24px; font-weight: 900; letter-spacing: 1.5px; }
    .company-dba  { color: #c9a84c; font-size: 11px; font-weight: 700; letter-spacing: .5px; margin-top: 2px; }
    .ticket-type-label {
      display: inline-block;
      margin-top: 16px;
      background: rgba(255,255,255,.12);
      color: #fff;
      font-size: 11px;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: 2px;
      padding: 6px 16px;
      border-radius: 999px;
      border: 1px solid rgba(255,255,255,.2);
    }

    /* Status bar */
    .ticket-status-bar {
      background: #d1fae5;
      border-bottom: 2px solid #6ee7b7;
      padding: 12px 36px;
      display: flex;
      align-items: center;
      gap: 12px;
    }
    .ticket-status-bar.acked { background: #d1fae5; border-color: #6ee7b7; }
    .status-icon { font-size: 22px; }
    .status-text { font-weight: 800; color: #065f46; font-size: 14px; }
    .status-sub  { font-size: 11px; color: #047857; margin-top: 1px; }

    /* Body section */
    .ticket-body { padding: 30px 36px; }

    /* PNR + Airline hero */
    .booking-hero {
      display: flex;
      align-items: center;
      justify-content: space-between;
      background: linear-gradient(135deg, #f8fafc, #f0f9ff);
      border: 1px solid #bae6fd;
      border-radius: 12px;
      padding: 20px 28px;
      margin-bottom: 28px;
    }
    .pnr-block .label { font-size: 10px; text-transform: uppercase; letter-spacing: 1px; color: #94a3b8; font-weight: 700; }
    .pnr-block .value { font-family: 'JetBrains Mono', monospace; font-size: 32px; font-weight: 700; color: #0f1e3c; letter-spacing: 4px; }
    .airline-logo { text-align: right; }
    .airline-logo img { height: 48px; border-radius: 6px; }
    .airline-name { font-size: 14px; font-weight: 700; color: #1e293b; margin-top: 4px; }

    /* Section headings */
    .section-title {
      font-size: 10px;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: 1.2px;
      color: #94a3b8;
      margin-bottom: 16px;
      padding-bottom: 8px;
      border-bottom: 1px solid #f1f5f9;
      display: flex;
      align-items: center;
      gap: 8px;
    }

    /* Itinerary */
    .flight-segment {
      display: flex;
      align-items: center;
      gap: 20px;
      padding: 20px 0;
      border-bottom: 1px solid #f1f5f9;
    }
    .flight-segment:last-child { border-bottom: none; }
    .airport-block { text-align: center; min-width: 72px; }
    .airport-iata  { font-family: 'JetBrains Mono', monospace; font-size: 26px; font-weight: 700; color: #0f1e3c; }
    .airport-date  { font-size: 10px; color: #94a3b8; margin-top: 2px; }
    .airport-time  { font-size: 14px; font-weight: 700; color: #475569; margin-top: 1px; }
    .flight-connector { flex: 1; text-align: center; }
    .flight-line {
      display: flex;
      align-items: center;
      gap: 6px;
      margin-bottom: 4px;
    }
    .flight-line div { flex: 1; height: 1px; background: #cbd5e1; }
    .flight-line span { font-size: 16px; color: #94a3b8; }
    .flight-info { font-size: 11px; color: #94a3b8; }

    /* Passengers table */
    .pax-table { width: 100%; border-collapse: collapse; }
    .pax-table th {
      padding: 8px 14px;
      text-align: left;
      font-size: 10px;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: .8px;
      color: #94a3b8;
      background: #f8fafc;
      border-bottom: 2px solid #e2e8f0;
    }
    .pax-table td {
      padding: 12px 14px;
      border-bottom: 1px solid #f1f5f9;
      font-size: 13px;
      color: #1e293b;
    }
    .pax-table tr:last-child td { border-bottom: none; }
    .ticket-num { font-family: 'JetBrains Mono', monospace; font-weight: 700; color: #1e40af; font-size: 13px; }
    .seat-badge { background: #f5f3ff; color: #6d28d9; font-weight: 700; font-size: 12px; padding: 3px 10px; border-radius: 5px; display: inline-block; }

    /* Fare summary */
    .fare-row { display: flex; justify-content: space-between; padding: 7px 0; font-size: 13px; color: #64748b; border-bottom: 1px solid #f8fafc; }
    .fare-row.total { font-size: 16px; font-weight: 800; color: #065f46; border-top: 2px solid #e2e8f0; border-bottom: none; padding-top: 14px; margin-top: 8px; }

    /* Conditions */
    .condition-box {
      background: #f8fafc;
      border: 1px solid #e2e8f0;
      border-radius: 8px;
      padding: 14px 18px;
      margin-bottom: 14px;
      font-size: 12px;
      line-height: 1.8;
      color: #475569;
    }
    .condition-box strong { color: #1e293b; font-weight: 700; }

    /* Policy / Legal */
    .policy-box {
      background: #fff8ed;
      border: 1.5px solid #fcd34d;
      border-radius: 10px;
      padding: 20px 24px;
      margin-bottom: 28px;
    }
    .policy-title {
      font-size: 12px;
      font-weight: 800;
      color: #92400e;
      text-transform: uppercase;
      letter-spacing: .8px;
      margin-bottom: 12px;
    }
    .policy-text {
      font-size: 12px;
      line-height: 1.85;
      color: #78350f;
      white-space: pre-line;
    }

    /* Acknowledgment section */
    .ack-section {
      background: linear-gradient(135deg, #0f1e3c, #1a3a6b);
      border-radius: 14px;
      padding: 32px;
      text-align: center;
      margin-top: 28px;
    }
    .ack-section.done {
      background: linear-gradient(135deg, #064e3b, #065f46);
    }
    .ack-title { color: #fff; font-size: 17px; font-weight: 800; margin-bottom: 8px; }
    .ack-sub   { color: rgba(255,255,255,.7); font-size: 12px; margin-bottom: 24px; line-height: 1.7; }

    .ack-checkbox-wrap {
      display: flex;
      align-items: flex-start;
      gap: 14px;
      background: rgba(255,255,255,.1);
      border: 1px solid rgba(255,255,255,.2);
      border-radius: 10px;
      padding: 16px 20px;
      text-align: left;
      margin-bottom: 20px;
      cursor: pointer;
    }
    .ack-checkbox-wrap input[type="checkbox"] {
      width: 20px;
      height: 20px;
      flex-shrink: 0;
      margin-top: 1px;
      accent-color: #c9a84c;
      cursor: pointer;
    }
    .ack-checkbox-label { color: rgba(255,255,255,.9); font-size: 13px; line-height: 1.65; cursor: pointer; }

    .btn-ack {
      display: inline-block;
      background: linear-gradient(135deg, #c9a84c, #d4b86a);
      color: #0f1e3c;
      font-weight: 900;
      font-size: 16px;
      padding: 17px 52px;
      border-radius: 10px;
      border: none;
      cursor: pointer;
      transition: opacity .15s, transform .1s;
      letter-spacing: .3px;
    }
    .btn-ack:hover  { opacity: .92; transform: translateY(-1px); }
    .btn-ack:active { transform: translateY(0); }
    .btn-ack:disabled { opacity: .4; cursor: not-allowed; transform: none; }

    .ack-done-banner {
      background: rgba(255,255,255,.15);
      border: 1px solid rgba(255,255,255,.3);
      border-radius: 10px;
      padding: 18px 24px;
      color: #fff;
    }
    .ack-done-title { font-size: 20px; font-weight: 800; margin-bottom: 4px; }
    .ack-done-sub   { font-size: 13px; opacity: .8; }

    /* Footer */
    .ticket-footer {
      background: #f8fafc;
      border-top: 1px solid #e2e8f0;
      padding: 16px 36px;
      text-align: center;
      font-size: 10px;
      color: #94a3b8;
    }

    @media (max-width: 600px) {
      .ticket-header { padding: 22px 20px; }
      .ticket-body   { padding: 20px; }
      .booking-hero  { flex-direction: column; gap: 12px; text-align: center; }
      .pnr-block .value { font-size: 24px; }
      .flight-segment { flex-direction: column; gap: 8px; text-align: center; }
      .flight-connector { width: 100%; }
      .ack-section   { padding: 20px 16px; }
    }
  </style>
</head>
<body>
<?php
$et       = $eticket;
$pax      = $et->ticketDataWithAutoNumbers();
$isAcked  = $et->isAcknowledged();
$iataCode = $et->resolvedIataCode();
$logoUrl  = $iataCode ? \App\Models\ETicket::airlineLogoUrl($iataCode) : '';
$etId     = 'ET-' . str_pad($et->id, 6, '0', STR_PAD_LEFT);

// CSRF for the acknowledge form
if (empty($_SESSION['csrf_token'])) {
    session_start();
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>

<div class="page-wrap">
  <div class="ticket">

    <!-- ── HEADER ─────────────────────────────────────────── -->
    <div class="ticket-header">
      <div class="company-name">LETS FLY TRAVEL</div>
      <div class="company-dba">DBA BASE FARE</div>
      <span class="ticket-type-label">✈ Electronic Ticket</span>
    </div>

    <!-- ── STATUS BAR ──────────────────────────────────────── -->
    <div class="ticket-status-bar <?= $isAcked ? 'acked' : '' ?>">
      <span class="status-icon"><?= $isAcked ? '✅' : '📄' ?></span>
      <div>
        <div class="status-text"><?= $isAcked ? 'Acknowledged — Booking Confirmed' : 'Please Review & Acknowledge Your E-Ticket' ?></div>
        <div class="status-sub">
          <?php if ($isAcked): ?>
            Acknowledged on <?= $et->acknowledged_at->format('F j, Y \a\t g:i A') ?>
          <?php else: ?>
            Scroll down to review all details, then click "I have read and acknowledged this e-ticket"
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- ── BODY ───────────────────────────────────────────── -->
    <div class="ticket-body">

      <!-- Booking Hero -->
      <div class="booking-hero">
        <div class="pnr-block">
          <div class="label">Booking Reference (PNR)</div>
          <div class="value"><?= htmlspecialchars($et->pnr) ?></div>
          <?php if ($et->order_id): ?>
          <div style="font-size:11px;color:#94a3b8;margin-top:4px;">Confirmation: <?= htmlspecialchars($et->order_id) ?></div>
          <?php endif; ?>
        </div>
        <div class="airline-logo">
          <?php if ($logoUrl): ?>
          <img src="<?= htmlspecialchars($logoUrl) ?>" alt="<?= htmlspecialchars($et->airline ?? '') ?>" onerror="this.style.display='none'">
          <?php endif; ?>
          <div class="airline-name"><?= htmlspecialchars($et->airline ?? '') ?></div>
        </div>
      </div>

      <!-- ── FLIGHT ITINERARY ──────────────────────────────── -->
      <?php
      $flightDataArr = (array)$et->flight_data;
      $flightsToRender = [];
      if (isset($flightDataArr['flights']) && is_array($flightDataArr['flights'])) {
          $flightsToRender = $flightDataArr['flights'];
      } elseif (!empty($flightDataArr) && is_array(reset($flightDataArr)) && !isset($flightDataArr['flights'])) {
          $flightsToRender = $flightDataArr; // It's already a sequential array
      }
      ?>
      <?php if (!empty($flightsToRender)): ?>
      <div style="margin-bottom:28px;">
        <div class="section-title">🛫 Flight Itinerary</div>
        <?php foreach ($flightsToRender as $f): ?>
        <div class="flight-segment">
          <div class="airport-block">
            <div class="airport-iata"><?= htmlspecialchars($f['departure_airport'] ?? $f['from'] ?? '???') ?></div>
            <div class="airport-date"><?= htmlspecialchars($f['departure_date'] ?? $f['date'] ?? '') ?></div>
            <div class="airport-time"><?= htmlspecialchars($f['departure_time'] ?? $f['time'] ?? '') ?></div>
          </div>
          <div class="flight-connector">
            <div class="flight-line">
              <div></div>
              <span>✈</span>
              <div></div>
            </div>
            <div class="flight-info">
              <?= htmlspecialchars($f['flight_number'] ?? $f['flight'] ?? '') ?>
              <?php if (!empty($f['cabin_class'] ?? $f['class'] ?? '')): ?>&nbsp;·&nbsp;<?= htmlspecialchars($f['cabin_class'] ?? $f['class'] ?? '') ?><?php endif; ?>
              <?php if (!empty($f['duration'])): ?>&nbsp;·&nbsp;<?= htmlspecialchars($f['duration']) ?><?php endif; ?>
            </div>
          </div>
          <div class="airport-block">
            <div class="airport-iata"><?= htmlspecialchars($f['arrival_airport'] ?? $f['to'] ?? '???') ?></div>
            <div class="airport-date"><?= htmlspecialchars($f['arrival_date'] ?? '') ?></div>
            <div class="airport-time"><?= htmlspecialchars($f['arrival_time'] ?? '') ?></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <!-- ── PASSENGERS & TICKET NUMBERS ──────────────────── -->
      <div style="margin-bottom:28px;">
        <div class="section-title">🎫 Passengers & Ticket Numbers</div>
        <table class="pax-table">
          <thead>
            <tr>
              <th>#</th>
              <th>Passenger Name</th>
              <th>Type</th>
              <th>E-Ticket Number</th>
              <th>Seat</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($pax as $i => $p): ?>
            <tr>
              <td style="color:#94a3b8;font-size:12px;"><?= $i+1 ?></td>
              <td style="font-weight:700;"><?= htmlspecialchars($p['pax_name'] ?? '') ?></td>
              <td style="color:#64748b;text-transform:capitalize;font-size:12px;"><?= htmlspecialchars($p['pax_type'] ?? 'adult') ?></td>
              <td><span class="ticket-num"><?= htmlspecialchars($p['ticket_number'] ?? '—') ?></span></td>
              <td><?php if (!empty($p['seat'])): ?><span class="seat-badge"><?= htmlspecialchars($p['seat']) ?></span><?php else: ?>—<?php endif; ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <!-- ── FARE SUMMARY ──────────────────────────────────── -->
      <?php if (!empty($et->fare_breakdown)): ?>
      <div style="margin-bottom:28px;">
        <div class="section-title">💰 Fare Summary</div>
        <?php foreach ((array)$et->fare_breakdown as $item): ?>
        <div class="fare-row">
          <span><?= htmlspecialchars($item['label'] ?? $item['description'] ?? '') ?></span>
          <span><?= htmlspecialchars($et->currency) ?> <?= number_format((float)($item['amount'] ?? 0), 2) ?></span>
        </div>
        <?php endforeach; ?>
        <div class="fare-row total">
          <span>Total Charged</span>
          <span><?= htmlspecialchars($et->currency) ?> <?= number_format($et->total_amount, 2) ?></span>
        </div>
      </div>
      <?php else: ?>
      <div style="margin-bottom:28px;">
        <div class="section-title">💰 Amount</div>
        <div class="fare-row total">
          <span>Total Charged</span>
          <span><?= htmlspecialchars($et->currency) ?> <?= number_format($et->total_amount, 2) ?></span>
        </div>
      </div>
      <?php endif; ?>

      <!-- ── TICKET CONDITIONS ─────────────────────────────── -->
      <?php if ($et->endorsements || $et->baggage_info || $et->fare_rules): ?>
      <div style="margin-bottom:28px;">
        <div class="section-title">📋 Ticket Conditions</div>
        <?php if ($et->endorsements): ?>
        <div class="condition-box">
          <strong>Endorsements / Restrictions:</strong><br><?= nl2br(htmlspecialchars($et->endorsements)) ?>
        </div>
        <?php endif; ?>
        <?php if ($et->baggage_info): ?>
        <div class="condition-box">
          <strong>Baggage Allowance:</strong><br><?= nl2br(htmlspecialchars($et->baggage_info)) ?>
        </div>
        <?php endif; ?>
        <?php if ($et->fare_rules): ?>
        <div class="condition-box">
          <strong>Fare Rules (Exchange / Cancellation):</strong><br><?= nl2br(htmlspecialchars($et->fare_rules)) ?>
        </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <!-- ── LEGAL POLICY ──────────────────────────────────── -->
      <?php if ($et->policy_text): ?>
      <div class="policy-box">
        <div class="policy-title">⚖ Terms & Acknowledgment Policy</div>
        <div class="policy-text"><?= htmlspecialchars($et->policy_text) ?></div>
      </div>
      <?php endif; ?>

      <!-- ── ACKNOWLEDGMENT SECTION ────────────────────────── -->
      <?php if ($isAcked): ?>
      <div class="ack-section done">
        <div class="ack-done-banner">
          <div class="ack-done-title">✅ Acknowledged</div>
          <div class="ack-done-sub">
            You have confirmed receipt of this e-ticket on<br>
            <strong><?= $et->acknowledged_at->format('F j, Y \a\t g:i:s A') ?></strong>
          </div>
        </div>
      </div>
      <?php else: ?>
      <div class="ack-section">
        <div class="ack-title">Ready to Acknowledge Your Ticket?</div>
        <div class="ack-sub">
          Please read all the details above carefully before acknowledging.<br>
          Your acknowledgment constitutes a legally binding confirmation of receipt.
        </div>

        <form method="POST" action="/eticket/acknowledge" id="ack-form">
          <input type="hidden" name="token" value="<?= htmlspecialchars($et->token) ?>">

          <label class="ack-checkbox-wrap" for="ack-checkbox">
            <input type="checkbox" id="ack-checkbox" onchange="updateAckButton(this)">
            <span class="ack-checkbox-label">
              I, <strong style="color:#c9a84c;"><?= htmlspecialchars($et->customer_name) ?></strong>, confirm that I have reviewed all booking details,
              flight itinerary, ticket conditions, and the policy above. I acknowledge that this e-ticket is
              <strong style="color:#c9a84c;">non-refundable and non-transferable</strong>. I understand that acknowledging
              this ticket constitutes a legal receipt and waives any right to dispute charges for services rendered.
            </span>
          </label>

          <button type="submit" id="btn-ack" class="btn-ack" disabled onclick="return confirmAck()">
            ✓ I Have Read and Acknowledged This E-Ticket
          </button>
        </form>
      </div>
      <?php endif; ?>

    </div><!-- /ticket-body -->

    <!-- ── FOOTER ─────────────────────────────────────────── -->
    <div class="ticket-footer">
      <strong style="color:#0f1e3c;">Lets Fly Travel DBA Base Fare</strong>
      &nbsp;&middot;&nbsp; reservation@base-fare.com<br>
      E-Ticket Ref: <?= $etId ?> &nbsp;&middot;&nbsp; PNR: <?= htmlspecialchars($et->pnr) ?><br>
      <span style="font-size:9px;">This is an official electronic travel document. Do not forward or share this link.</span>
    </div>

  </div><!-- /ticket -->
</div>

<script>
function updateAckButton(chk) {
    document.getElementById('btn-ack').disabled = !chk.checked;
}

function confirmAck() {
    const confirmed = confirm(
        'By clicking OK, you confirm that you have read and understood your e-ticket and all associated terms.\n\n' +
        'This acknowledgment is legally binding and will be recorded.\n\n' +
        'Do you wish to proceed?'
    );
    if (!confirmed) return false;

    const btn = document.getElementById('btn-ack');
    btn.disabled = true;
    btn.textContent = 'Processing...';
    return true;
}
</script>

</body>
</html>
