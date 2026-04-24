<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <title>E-Ticket Acknowledged | Lets Fly Travel</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&display=swap" rel="stylesheet">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: 'Inter', sans-serif; background: linear-gradient(135deg, #0f1e3c 0%, #1a3a6b 100%); min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; }
    .card { background: #fff; border-radius: 20px; padding: 52px 48px; max-width: 500px; width: 100%; text-align: center; box-shadow: 0 24px 80px rgba(0,0,0,.3); }
    .icon { font-size: 64px; margin-bottom: 20px; display: block; animation: pop .4s cubic-bezier(.175,.885,.32,1.275); }
    @keyframes pop { 0%{transform:scale(0)} 100%{transform:scale(1)} }
    h1 { font-size: 26px; font-weight: 900; color: #0f1e3c; margin-bottom: 10px; }
    .sub { font-size: 15px; color: #64748b; line-height: 1.7; margin-bottom: 28px; }
    .detail-box { background: #f0fdf4; border: 1px solid #86efac; border-radius: 12px; padding: 18px 22px; margin-bottom: 24px; text-align: left; }
    .detail-box .row { display: flex; justify-content: space-between; padding: 5px 0; font-size: 13px; }
    .detail-box .key   { color: #94a3b8; font-weight: 600; }
    .detail-box .val   { color: #1e293b; font-weight: 700; }
    .legal-note { font-size: 11px; color: #94a3b8; line-height: 1.7; margin-bottom: 24px; }
    .footer { font-size: 11px; color: #cbd5e1; }
    .footer strong { color: #fff; }
  </style>
</head>
<body>
<?php
$et     = $eticket;
$status = $status ?? '';
$isInvalid = $status === 'invalid' || !$et;
?>
<div class="card">
  <?php if ($isInvalid): ?>
    <span class="icon">⚠️</span>
    <h1>Invalid Link</h1>
    <p class="sub">This e-ticket acknowledgment link is invalid or has already been processed.</p>
    <p class="legal-note">If you believe this is an error, please contact us at <strong>reservation@base-fare.com</strong>.</p>
  <?php else: ?>
    <span class="icon">✅</span>
    <h1>E-Ticket Acknowledged</h1>
    <p class="sub">Thank you, <strong><?= htmlspecialchars($et->customer_name) ?></strong>. Your e-ticket acknowledgment has been recorded successfully.</p>

    <div class="detail-box">
      <div class="row"><span class="key">PNR</span><span class="val" style="font-family:monospace;letter-spacing:2px;"><?= htmlspecialchars($et->pnr) ?></span></div>
      <div class="row"><span class="key">Airline</span><span class="val"><?= htmlspecialchars($et->airline ?? '—') ?></span></div>
      <div class="row"><span class="key">Status</span><span class="val" style="color:#15803d;">✓ Acknowledged</span></div>
      <div class="row"><span class="key">Date</span><span class="val"><?= ($et->acknowledged_at ?? now())->format('F j, Y \a\t g:i A') ?></span></div>
    </div>

    <p class="legal-note">
      A copy of this acknowledgment has been sent to our reservations team. This confirmation is legally binding
      and forms part of your booking agreement with Lets Fly Travel DBA Base Fare. Please keep your booking
      reference <strong><?= htmlspecialchars($et->pnr) ?></strong> for your records.
    </p>
  <?php endif; ?>

  <div class="footer" style="color:#94a3b8;">
    <strong style="color:#0f1e3c;">Lets Fly Travel DBA Base Fare</strong><br>
    reservation@base-fare.com
  </div>
</div>
</body>
</html>
