<?php
/**
 * Public Customer Authorization Page
 * Token-based access — no CRM login required
 *
 * @var AcceptanceRequest $acceptance  The loaded acceptance request record
 */

use App\Models\AcceptanceRequest;

// Guard: should always have $acceptance from controller
if (!isset($acceptance) || !$acceptance) {
    require __DIR__ . '/public_invalid.php';
    return;
}

$pnr           = htmlspecialchars($acceptance->pnr);
$customerName  = htmlspecialchars($acceptance->customer_name);
$typeLabel     = htmlspecialchars($acceptance->typeLabel());
$typeAction    = htmlspecialchars($acceptance->typeActionLabel());
$total         = number_format($acceptance->total_amount, 2);
$currency      = htmlspecialchars($acceptance->currency);
$maskedCard    = htmlspecialchars($acceptance->maskedCard());
$cardholderName = htmlspecialchars($acceptance->cardholder_name ?? '');
$cardType      = htmlspecialchars($acceptance->card_type ?? '');
$token         = htmlspecialchars($acceptance->token);
$expiryLabel   = $acceptance->expiryLabel();
$policyText    = nl2br(htmlspecialchars($acceptance->policy_text ?? ''));
$endorsements  = htmlspecialchars($acceptance->endorsements ?? '');
$descriptor    = htmlspecialchars($acceptance->statement_descriptor ?? '');
$passengers    = $acceptance->passengers ?? [];
$flightData    = $acceptance->flight_data ?? [];
$fareBreakdown = $acceptance->fare_breakdown ?? [];
$companyName   = AcceptanceRequest::COMPANY_NAME;

// Status checks
$isActionable  = $acceptance->isActionable();
$isApproved    = $acceptance->isApproved();
$isExpired     = $acceptance->isExpired();
$isCancelled   = $acceptance->isCancelled();

// Error from redirect
$error = $_GET['error'] ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Authorization — <?= $pnr ?> | Lets Fly Travel</title>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@400,0&display=swap" rel="stylesheet"/>
<style>
  * { margin:0; padding:0; box-sizing:border-box; }
  body { font-family:'Inter','Manrope',sans-serif; background:linear-gradient(135deg,#f0f4ff 0%,#e8ecf4 50%,#f8f9fc 100%); min-height:100vh; padding:20px; }
  .page { max-width:680px; margin:0 auto; }
  .card { background:#fff; border-radius:16px; box-shadow:0 8px 32px rgba(15,30,60,0.06),0 2px 6px rgba(0,0,0,0.03); overflow:hidden; margin-bottom:16px; }
  .header { background:linear-gradient(135deg,#0f1e3c 0%,#1a3a6b 100%); padding:28px 24px; text-align:center; }
  .header .logo { color:#fff; font-family:'Manrope',sans-serif; font-size:18px; font-weight:800; letter-spacing:1px; }
  .header .dba { color:#c9a84c; font-size:10px; font-weight:700; letter-spacing:0.5px; margin-top:2px; }
  .header .type-badge { display:inline-block; margin-top:14px; background:rgba(255,255,255,0.12); border:1px solid rgba(255,255,255,0.2); padding:6px 16px; border-radius:8px; color:#fff; font-size:13px; font-weight:700; letter-spacing:1px; text-transform:uppercase; }
  .pnr-bar { background:#f8fafc; border-bottom:1px solid #e2e8f0; padding:14px 24px; display:flex; justify-content:space-between; align-items:center; }
  .pnr-bar .label { font-size:10px; color:#94a3b8; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; }
  .pnr-bar .value { font-size:22px; font-weight:800; font-family:'Courier New',monospace; color:#0f1e3c; letter-spacing:3px; }
  .section { padding:20px 24px; border-bottom:1px solid #f1f5f9; }
  .section:last-child { border-bottom:none; }
  .section-title { font-size:10px; font-weight:700; color:#94a3b8; text-transform:uppercase; letter-spacing:0.8px; margin-bottom:10px; }
  .pax-chip { display:inline-flex; align-items:center; gap:4px; background:#f1f5f9; padding:5px 10px; border-radius:6px; font-size:12px; font-weight:600; color:#334155; font-family:'Courier New',monospace; margin:2px; }
  .flight-seg { display:flex; align-items:center; gap:12px; padding:10px 12px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; margin-bottom:6px; }
  .flight-seg img { width:28px; height:28px; object-fit:contain; flex-shrink:0; }
  .flight-seg .fno { font-size:12px; font-weight:800; font-family:monospace; color:#1e293b; }
  .flight-seg .route { font-size:12px; font-weight:700; color:#334155; }
  .flight-seg .time { font-size:11px; color:#64748b; }
  .fare-table { width:100%; border-collapse:collapse; font-size:13px; }
  .fare-table td { padding:8px 0; }
  .fare-table .label { color:#64748b; }
  .fare-table .amt { text-align:right; font-family:monospace; font-weight:600; color:#1e293b; }
  .fare-table .total-row { border-top:2px solid #0f1e3c; }
  .fare-table .total-row td { padding-top:10px; font-weight:800; font-size:16px; color:#0f1e3c; }
  .fare-table .total-row .amt { color:#16a34a; }
  .card-info { display:flex; align-items:center; gap:10px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:12px 16px; }
  .card-info .icon { font-size:28px; color:#0f1e3c; }
  .card-info .details { flex:1; }
  .card-info .holder { font-size:13px; font-weight:700; color:#1e293b; }
  .card-info .number { font-size:12px; color:#64748b; font-family:monospace; letter-spacing:1px; }
  .policy-box { background:#fffbeb; border:1px solid #fcd34d; border-radius:10px; padding:14px 16px; font-size:12px; color:#92400e; line-height:1.7; max-height:200px; overflow-y:auto; }
  .check-item { display:flex; align-items:flex-start; gap:8px; padding:8px 0; font-size:13px; color:#334155; line-height:1.5; }
  .check-item input[type=checkbox] { width:18px; height:18px; accent-color:#0f1e3c; margin-top:2px; flex-shrink:0; }
  .sig-canvas { width:100%; height:160px; border:2px dashed #cbd5e1; border-radius:10px; cursor:crosshair; background:#fafbfc; touch-action:none; }
  .sig-canvas.signing { border-color:#0f1e3c; border-style:solid; }
  .btn-primary { display:inline-flex; align-items:center; justify-content:center; gap:8px; width:100%; background:linear-gradient(135deg,#0f1e3c,#1a3a6b); color:#fff; font-size:15px; font-weight:800; padding:16px; border:none; border-radius:10px; cursor:pointer; transition:opacity 0.2s; letter-spacing:0.5px; }
  .btn-primary:hover { opacity:0.92; }
  .btn-primary:disabled { opacity:0.5; cursor:not-allowed; }
  .btn-clear { display:inline-flex; align-items:center; gap:4px; background:none; border:1px solid #e2e8f0; padding:6px 12px; border-radius:6px; font-size:11px; font-weight:600; color:#64748b; cursor:pointer; }
  .btn-clear:hover { background:#f8fafc; }
  .alert-error { background:#fef2f2; border:1px solid #fecaca; color:#991b1b; padding:12px 16px; border-radius:10px; font-size:13px; margin-bottom:12px; display:flex; align-items:center; gap:8px; }
  .status-banner { padding:20px 24px; text-align:center; }
  .status-banner .icon { font-size:48px; margin-bottom:8px; }
  .status-banner h3 { font-size:18px; font-weight:800; margin-bottom:4px; }
  .status-banner p { font-size:13px; color:#64748b; }
  .expiry-badge { display:inline-flex; align-items:center; gap:4px; background:#fef3c7; color:#92400e; font-size:11px; font-weight:700; padding:4px 10px; border-radius:6px; }
  .upload-area { border:2px dashed #cbd5e1; border-radius:10px; padding:16px; text-align:center; cursor:pointer; transition:border-color 0.2s; }
  .upload-area:hover { border-color:#0f1e3c; }
  .upload-area input { display:none; }
  .upload-area .icon { font-size:28px; color:#94a3b8; }
  .upload-area p { font-size:12px; color:#94a3b8; margin-top:4px; }
  .footer { text-align:center; padding:20px; font-size:10px; color:#94a3b8; }
</style>
</head>
<body>

<div class="page">

  <!-- Header Card -->
  <div class="card">
    <div class="header">
      <div class="logo">LETS FLY TRAVEL</div>
      <div class="dba">DBA BASE FARE</div>
      <div class="type-badge"><?= $typeLabel ?></div>
    </div>

    <?php if (!$isActionable): ?>
      <!-- STATUS BANNER (non-actionable) -->
      <div class="status-banner">
        <?php if ($isApproved): ?>
          <div class="icon" style="color:#16a34a;">✓</div>
          <h3 style="color:#16a34a;">Already Authorized</h3>
          <p>This authorization was completed on <?= $acceptance->approved_at ? $acceptance->approved_at->format('M j, Y \a\t g:i A') : '—' ?>.</p>
        <?php elseif ($isExpired): ?>
          <div class="icon" style="color:#dc2626;">⏰</div>
          <h3 style="color:#dc2626;">Link Expired</h3>
          <p>This authorization link has expired. Please contact your travel agent for a new link.</p>
        <?php elseif ($isCancelled): ?>
          <div class="icon" style="color:#64748b;">✕</div>
          <h3 style="color:#64748b;">Request Cancelled</h3>
          <p>This authorization request has been cancelled by your travel agent.</p>
        <?php endif; ?>
      </div>

    <?php else: ?>
      <!-- ACTIONABLE FORM -->
      <div class="pnr-bar">
        <div>
          <div class="label">Booking Reference (PNR)</div>
          <div class="value"><?= $pnr ?></div>
        </div>
        <?php if ($expiryLabel): ?>
          <span class="expiry-badge">
            <span class="material-symbols-outlined" style="font-size:14px;">schedule</span>
            <?= htmlspecialchars($expiryLabel) ?>
          </span>
        <?php endif; ?>
      </div>

      <?php if ($error): ?>
        <div class="section" style="padding-bottom:0;">
          <div class="alert-error">
            <span class="material-symbols-outlined" style="font-size:18px;">error</span>
            <?php
              echo match($error) {
                'expired'      => 'This link has expired. Please contact your agent.',
                'incomplete'   => 'Please check all required boxes before submitting.',
                'no_signature' => 'Please draw your signature in the box provided.',
                'failed'       => 'Submission failed. Please try again.',
                default        => 'An error occurred. Please try again.',
              };
            ?>
          </div>
        </div>
      <?php endif; ?>

      <form id="authForm" method="POST" action="/auth" enctype="multipart/form-data">
        <input type="hidden" name="token" value="<?= $token ?>">
        <input type="hidden" name="device_fingerprint" id="deviceFingerprint" value="">
        <input type="hidden" name="signature_data" id="signatureData" value="">

        <!-- Greeting -->
        <div class="section">
          <p style="font-size:14px; color:#334155; line-height:1.7;">
            Hello <strong><?= $customerName ?></strong>,<br>
            Please <?= $typeAction ?>. Review the details below, then sign to confirm.
          </p>
        </div>

        <!-- Passengers -->
        <?php if (!empty($passengers)): ?>
        <div class="section">
          <div class="section-title">Passenger(s)</div>
          <div>
            <?php foreach ($passengers as $p): ?>
              <span class="pax-chip"><?= htmlspecialchars($p['name'] ?? '') ?></span>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>

        <!-- Flight Details -->
        <?php
          $flights = $flightData['flights'] ?? $flightData['old_flights'] ?? [];
          $newFlights = $flightData['new_flights'] ?? [];
        ?>
        <?php if (!empty($flights)): ?>
        <div class="section">
          <div class="section-title"><?= !empty($newFlights) ? 'Original Flights' : 'Flight Itinerary' ?></div>
          <?php foreach ($flights as $seg): ?>
            <div class="flight-seg">
              <?php $iata = $seg['airline_iata'] ?? ''; ?>
              <?php if ($iata): ?>
                <img src="https://www.gstatic.com/flights/airline_logos/35px/<?= htmlspecialchars($iata) ?>.png"
                     alt="<?= htmlspecialchars($iata) ?>" onerror="this.style.display='none'">
              <?php endif; ?>
              <span class="fno"><?= htmlspecialchars($seg['flight_no'] ?? '') ?></span>
              <span class="route"><?= htmlspecialchars($seg['from'] ?? '') ?> → <?= htmlspecialchars($seg['to'] ?? '') ?></span>
              <span class="time"><?= htmlspecialchars($seg['date'] ?? '') ?> · <?= htmlspecialchars($seg['dep_time'] ?? '') ?> → <?= htmlspecialchars($seg['arr_time'] ?? '') ?><?= !empty($seg['arr_next_day']) ? ' (+1)' : '' ?></span>
            </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($newFlights)): ?>
        <div class="section">
          <div class="section-title" style="color:#16a34a;">New Flights (After Change)</div>
          <?php foreach ($newFlights as $seg): ?>
            <div class="flight-seg" style="border-color:#bbf7d0; background:#f0fdf4;">
              <?php $iata = $seg['airline_iata'] ?? ''; ?>
              <?php if ($iata): ?>
                <img src="https://www.gstatic.com/flights/airline_logos/35px/<?= htmlspecialchars($iata) ?>.png"
                     alt="<?= htmlspecialchars($iata) ?>" onerror="this.style.display='none'">
              <?php endif; ?>
              <span class="fno"><?= htmlspecialchars($seg['flight_no'] ?? '') ?></span>
              <span class="route"><?= htmlspecialchars($seg['from'] ?? '') ?> → <?= htmlspecialchars($seg['to'] ?? '') ?></span>
              <span class="time"><?= htmlspecialchars($seg['date'] ?? '') ?> · <?= htmlspecialchars($seg['dep_time'] ?? '') ?> → <?= htmlspecialchars($seg['arr_time'] ?? '') ?><?= !empty($seg['arr_next_day']) ? ' (+1)' : '' ?></span>
            </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Fare Breakdown -->
        <div class="section">
          <div class="section-title">Fare Summary</div>
          <table class="fare-table">
            <?php foreach ($fareBreakdown as $item): ?>
            <tr>
              <td class="label"><?= htmlspecialchars($item['label'] ?? '') ?></td>
              <td class="amt"><?= $currency ?> <?= number_format((float)($item['amount'] ?? 0), 2) ?></td>
            </tr>
            <?php endforeach; ?>
            <tr class="total-row">
              <td>Total Amount Authorized</td>
              <td class="amt"><?= $currency ?> <?= $total ?></td>
            </tr>
          </table>
        </div>

        <!-- Card Info -->
        <div class="section">
          <div class="section-title">Payment Method</div>
          <div class="card-info">
            <span class="material-symbols-outlined icon">credit_card</span>
            <div class="details">
              <div class="holder"><?= $cardholderName ?></div>
              <div class="number"><?= $cardType ? $cardType . ' · ' : '' ?><?= $maskedCard ?></div>
            </div>
          </div>
          <?php if ($descriptor): ?>
            <p style="font-size:11px; color:#94a3b8; margin-top:8px;">Statement will show as: <strong><?= $descriptor ?></strong></p>
          <?php endif; ?>
        </div>

        <?php if (!empty($acceptance->split_charge_note)): ?>
        <div class="section" style="background:#fffbeb; border-top:1px solid #fcd34d;">
          <div style="display:flex; align-items:flex-start; gap:10px;">
            <span class="material-symbols-outlined" style="color:#d97706; font-size:20px; flex-shrink:0; margin-top:1px;">info</span>
            <div>
              <div class="section-title" style="color:#92400e; margin-bottom:4px;">Important — Split Charge Notice</div>
              <p style="font-size:13px; color:#78350f; line-height:1.6;"><?= htmlspecialchars($acceptance->split_charge_note) ?></p>
            </div>
          </div>
        </div>
        <?php endif; ?>

        <!-- Endorsements -->
        <?php if ($endorsements): ?>
        <div class="section">
          <div class="section-title">Endorsements / Restrictions</div>
          <p style="font-size:13px; color:#dc2626; font-weight:700; font-family:monospace;"><?= $endorsements ?></p>
        </div>
        <?php endif; ?>

        <!-- Policy -->
        <div class="section">
          <div class="section-title">Terms & Conditions</div>
          <div class="policy-box"><?= $policyText ?></div>
        </div>

        <!-- Confirmations -->
        <div class="section">
          <div class="section-title">Confirmation Checkboxes</div>
          <div class="check-item">
            <input type="checkbox" name="confirm_details" id="chk1" value="1" required>
            <label for="chk1">I confirm all details above (passenger names, flight information, and fare) are correct.</label>
          </div>
          <div class="check-item">
            <input type="checkbox" name="confirm_charge" id="chk2" value="1" required>
            <label for="chk2">I authorize <?= htmlspecialchars($companyName) ?> to charge <strong><?= $currency ?> <?= $total ?></strong> to my credit card ending in <strong><?= htmlspecialchars($acceptance->card_last_four ?? '****') ?></strong>.</label>
          </div>
          <div class="check-item">
            <input type="checkbox" name="confirm_nonrefundable" id="chk3" value="1" required>
            <label for="chk3">I understand this purchase is <strong>NON-REFUNDABLE</strong> and <strong>NON-TRANSFERABLE</strong> once issued.</label>
          </div>
          <div class="check-item">
            <input type="checkbox" name="confirm_chargeback" id="chk4" value="1" required>
            <label for="chk4">I acknowledge that filing a credit card dispute after services are rendered constitutes Friendly Fraud, and this signed authorization will be submitted as evidence.</label>
          </div>
        </div>

        <!-- Document Uploads -->
        <?php if ($acceptance->req_passport || $acceptance->req_cc_front): ?>
        <div class="section">
          <div class="section-title">Required Documents</div>
          <?php if ($acceptance->req_passport): ?>
          <div style="margin-bottom:12px;">
            <label style="font-size:12px; font-weight:600; color:#334155; margin-bottom:6px; display:block;">Passport / Government ID</label>
            <div class="upload-area" onclick="this.querySelector('input').click()">
              <input type="file" name="passport_file" accept="image/*,.pdf">
              <span class="material-symbols-outlined icon">upload_file</span>
              <p>Click to upload (JPG, PNG, or PDF)</p>
            </div>
          </div>
          <?php endif; ?>
          <?php if ($acceptance->req_cc_front): ?>
          <div>
            <label style="font-size:12px; font-weight:600; color:#334155; margin-bottom:6px; display:block;">Credit Card Front (masked)</label>
            <div class="upload-area" onclick="this.querySelector('input').click()">
              <input type="file" name="card_file" accept="image/*,.pdf">
              <span class="material-symbols-outlined icon">upload_file</span>
              <p>Click to upload (JPG, PNG, or PDF)</p>
            </div>
          </div>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Signature -->
        <div class="section">
          <div class="section-title">Digital Signature</div>
          <p style="font-size:12px; color:#64748b; margin-bottom:10px;">Draw your signature below using your mouse or finger.</p>
          <canvas id="sigCanvas" class="sig-canvas"></canvas>
          <div style="display:flex; justify-content:flex-end; margin-top:8px;">
            <button type="button" class="btn-clear" onclick="clearSignature()">
              <span class="material-symbols-outlined" style="font-size:14px;">refresh</span> Clear
            </button>
          </div>
        </div>

        <!-- Submit -->
        <div class="section" style="border-bottom:none;">
          <button type="submit" id="btnSubmit" class="btn-primary" onclick="return prepareSubmit()">
            <span class="material-symbols-outlined" style="font-size:20px;">verified</span>
            I Authorize This Transaction
          </button>
          <p style="font-size:10px; color:#94a3b8; text-align:center; margin-top:10px;">
            By clicking above, you digitally sign this authorization form. Your IP address, browser fingerprint, and signature will be recorded for security purposes.
          </p>
        </div>
      </form>
    <?php endif; ?>
  </div>

  <!-- Footer -->
  <div class="footer">
    <strong style="color:#0f1e3c;">Lets Fly Travel DBA Base Fare</strong><br>
    Authorized Travel Services · This is an official payment authorization document.<br>
    Do not share this link. &copy; <?= date('Y') ?> All rights reserved.
  </div>
</div>

<?php if ($isActionable): ?>
<script>
// ── Device Fingerprint (basic) ──────────────────────────────────────────
(function() {
  const fp = [
    navigator.userAgent,
    navigator.language,
    screen.width + 'x' + screen.height,
    screen.colorDepth,
    Intl.DateTimeFormat().resolvedOptions().timeZone,
    new Date().getTimezoneOffset()
  ].join('|');
  // Simple hash
  let hash = 0;
  for (let i = 0; i < fp.length; i++) {
    hash = ((hash << 5) - hash) + fp.charCodeAt(i);
    hash |= 0;
  }
  document.getElementById('deviceFingerprint').value = 'fp-' + Math.abs(hash).toString(36) + '-' + Date.now().toString(36);
})();

// ── Signature Canvas ────────────────────────────────────────────────────
const canvas = document.getElementById('sigCanvas');
const ctx = canvas.getContext('2d');
let drawing = false;
let hasSigned = false;

function resizeCanvas() {
  const rect = canvas.getBoundingClientRect();
  canvas.width = rect.width * 2;
  canvas.height = rect.height * 2;
  ctx.scale(2, 2);
  ctx.strokeStyle = '#0f1e3c';
  ctx.lineWidth = 2;
  ctx.lineCap = 'round';
  ctx.lineJoin = 'round';
}
resizeCanvas();
window.addEventListener('resize', resizeCanvas);

function getPos(e) {
  const rect = canvas.getBoundingClientRect();
  const t = e.touches ? e.touches[0] : e;
  return { x: t.clientX - rect.left, y: t.clientY - rect.top };
}

canvas.addEventListener('mousedown', (e) => { drawing = true; hasSigned = true; canvas.classList.add('signing'); const p = getPos(e); ctx.beginPath(); ctx.moveTo(p.x, p.y); });
canvas.addEventListener('mousemove', (e) => { if (!drawing) return; const p = getPos(e); ctx.lineTo(p.x, p.y); ctx.stroke(); });
canvas.addEventListener('mouseup', () => { drawing = false; });
canvas.addEventListener('mouseleave', () => { drawing = false; });

canvas.addEventListener('touchstart', (e) => { e.preventDefault(); drawing = true; hasSigned = true; canvas.classList.add('signing'); const p = getPos(e); ctx.beginPath(); ctx.moveTo(p.x, p.y); }, {passive:false});
canvas.addEventListener('touchmove', (e) => { e.preventDefault(); if (!drawing) return; const p = getPos(e); ctx.lineTo(p.x, p.y); ctx.stroke(); }, {passive:false});
canvas.addEventListener('touchend', () => { drawing = false; });

function clearSignature() {
  ctx.clearRect(0, 0, canvas.width, canvas.height);
  hasSigned = false;
  canvas.classList.remove('signing');
}

// ── File upload preview ─────────────────────────────────────────────────
document.querySelectorAll('.upload-area input[type=file]').forEach(input => {
  input.addEventListener('change', function() {
    const area = this.closest('.upload-area');
    if (this.files.length) {
      area.querySelector('p').textContent = '✓ ' + this.files[0].name;
      area.style.borderColor = '#16a34a';
    }
  });
});

// ── Submit ───────────────────────────────────────────────────────────────
function prepareSubmit() {
  // Validate checkboxes
  const checks = ['chk1','chk2','chk3','chk4'];
  for (const id of checks) {
    if (!document.getElementById(id).checked) {
      alert('Please check all required confirmation boxes.');
      return false;
    }
  }

  // Validate signature
  if (!hasSigned) {
    alert('Please draw your signature in the box provided.');
    return false;
  }

  // Export signature as data URL
  document.getElementById('signatureData').value = canvas.toDataURL('image/png');

  // Disable button
  const btn = document.getElementById('btnSubmit');
  btn.disabled = true;
  btn.innerHTML = '<span class="material-symbols-outlined" style="font-size:20px;animation:spin 1s linear infinite;">progress_activity</span> Processing...';

  return true;
}
</script>
<style>
@keyframes spin { from { transform:rotate(0deg); } to { transform:rotate(360deg); } }
</style>
<?php endif; ?>

</body>
</html>
