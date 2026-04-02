<?php
/**
 * Public Confirmation Page — shown after customer successfully signs
 *
 * @var AcceptanceRequest|null $acceptance
 */
use App\Models\AcceptanceRequest;

$pnr          = $acceptance ? htmlspecialchars($acceptance->pnr) : '—';
$customerName = $acceptance ? htmlspecialchars($acceptance->customer_name) : 'Customer';
$typeLabel    = $acceptance ? htmlspecialchars($acceptance->typeLabel()) : 'Authorization';
$approvedAt   = ($acceptance && $acceptance->approved_at) ? $acceptance->approved_at->format('F j, Y \a\t g:i A T') : date('F j, Y \a\t g:i A T');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Authorization Confirmed — Lets Fly Travel</title>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@400,0&display=swap" rel="stylesheet"/>
<style>
  * { margin:0; padding:0; box-sizing:border-box; }
  body { font-family:'Inter','Manrope',sans-serif; background:linear-gradient(135deg,#f0fdf4 0%,#ecfdf5 50%,#f8fafb 100%); min-height:100vh; display:flex; align-items:center; justify-content:center; padding:24px; }
  .card { background:#fff; border-radius:20px; box-shadow:0 20px 60px rgba(15,30,60,0.08),0 2px 8px rgba(0,0,0,0.04); max-width:520px; width:100%; overflow:hidden; }
  .card-header { background:linear-gradient(135deg,#0f1e3c 0%,#1a3a6b 100%); padding:32px 24px; text-align:center; }
  .logo-text { color:#fff; font-family:'Manrope',sans-serif; font-size:20px; font-weight:800; letter-spacing:1px; }
  .logo-sub { color:#c9a84c; font-size:11px; font-weight:700; letter-spacing:0.5px; margin-top:2px; }
  .card-body { padding:40px 32px; text-align:center; }
  .check-circle { width:80px; height:80px; border-radius:50%; background:linear-gradient(135deg,#dcfce7,#bbf7d0); display:inline-flex; align-items:center; justify-content:center; margin-bottom:20px; animation: popIn 0.5s ease; }
  .check-circle .material-symbols-outlined { font-size:40px; color:#16a34a; font-variation-settings:'FILL' 1; }
  @keyframes popIn { 0% { transform:scale(0); opacity:0; } 60% { transform:scale(1.15); } 100% { transform:scale(1); opacity:1; } }
  .card-body h2 { font-family:'Manrope',sans-serif; font-size:22px; font-weight:800; color:#1e293b; margin-bottom:8px; }
  .card-body .sub { font-size:14px; color:#64748b; line-height:1.7; margin-bottom:24px; }
  .detail-row { display:flex; justify-content:space-between; align-items:center; padding:10px 16px; border-bottom:1px solid #f1f5f9; font-size:13px; }
  .detail-row:last-child { border-bottom:none; }
  .detail-label { color:#94a3b8; font-weight:600; text-transform:uppercase; font-size:10px; letter-spacing:0.5px; }
  .detail-value { color:#1e293b; font-weight:700; font-family:'Courier New',monospace; }
  .info-box { background:#f0fdf4; border:1px solid #bbf7d0; border-radius:12px; padding:16px; margin:24px 0 0; text-align:left; }
  .info-box p { font-size:12px; color:#166534; line-height:1.6; margin:0; }
  .footer-note { font-size:11px; color:#94a3b8; margin-top:24px; padding-top:16px; border-top:1px solid #f1f5f9; }
</style>
</head>
<body>

<div class="card">
  <div class="card-header">
    <div class="logo-text">LETS FLY TRAVEL</div>
    <div class="logo-sub">DBA BASE FARE</div>
  </div>
  <div class="card-body">
    <div class="check-circle">
      <span class="material-symbols-outlined">check_circle</span>
    </div>
    <h2>Authorization Confirmed</h2>
    <p class="sub">
      Thank you, <?= $customerName ?>. Your authorization has been received and recorded.
    </p>

    <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; overflow:hidden;">
      <div class="detail-row">
        <span class="detail-label">Booking Reference</span>
        <span class="detail-value" style="letter-spacing:2px;"><?= $pnr ?></span>
      </div>
      <div class="detail-row">
        <span class="detail-label">Authorization Type</span>
        <span class="detail-value" style="font-family:Inter,sans-serif; font-size:12px;"><?= $typeLabel ?></span>
      </div>
      <div class="detail-row">
        <span class="detail-label">Confirmed At</span>
        <span class="detail-value" style="font-family:Inter,sans-serif; font-size:11px;"><?= $approvedAt ?></span>
      </div>
    </div>

    <div class="info-box">
      <p>
        <strong>What happens next?</strong><br>
        Your travel agent will now process your request. You will receive a confirmation email with your booking details once everything is finalized. Please keep this page for your records.
      </p>
    </div>

    <div class="footer-note">
      <strong style="color:#0f1e3c;">Lets Fly Travel DBA Base Fare</strong><br>
      This is an official confirmation of your digital authorization.<br>
      A copy has been saved with your digital signature and forensic data for security purposes.
    </div>
  </div>
</div>

</body>
</html>
