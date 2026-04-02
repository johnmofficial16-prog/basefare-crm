<?php
/**
 * Public Invalid Token Page
 * Shown when: no token provided, token not found, or token expired
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Invalid Link — Lets Fly Travel</title>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@400,0&display=swap" rel="stylesheet"/>
<style>
  * { margin:0; padding:0; box-sizing:border-box; }
  body { font-family:'Inter','Manrope',sans-serif; background:linear-gradient(135deg,#f0f4ff 0%,#e8ecf4 50%,#f8f9fc 100%); min-height:100vh; display:flex; align-items:center; justify-content:center; padding:24px; }
  .card { background:#fff; border-radius:20px; box-shadow:0 20px 60px rgba(15,30,60,0.08),0 2px 8px rgba(0,0,0,0.04); max-width:480px; width:100%; overflow:hidden; text-align:center; }
  .card-header { background:linear-gradient(135deg,#0f1e3c 0%,#1a3a6b 100%); padding:32px 24px; }
  .logo-text { color:#fff; font-family:'Manrope',sans-serif; font-size:20px; font-weight:800; letter-spacing:1px; }
  .logo-sub { color:#c9a84c; font-size:11px; font-weight:700; letter-spacing:0.5px; margin-top:2px; }
  .card-body { padding:40px 32px; }
  .icon-circle { width:72px; height:72px; border-radius:50%; background:linear-gradient(135deg,#fef2f2,#fecaca); display:inline-flex; align-items:center; justify-content:center; margin-bottom:20px; }
  .icon-circle .material-symbols-outlined { font-size:36px; color:#dc2626; }
  .card-body h2 { font-family:'Manrope',sans-serif; font-size:22px; font-weight:800; color:#1e293b; margin-bottom:8px; }
  .card-body p { font-size:14px; color:#64748b; line-height:1.7; margin-bottom:24px; }
  .contact-box { background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:16px; margin-top:16px; }
  .contact-box p { font-size:12px; color:#94a3b8; margin:0; }
  .contact-box a { color:#0f1e3c; font-weight:600; text-decoration:none; }
  .contact-box a:hover { text-decoration:underline; }
</style>
</head>
<body>

<div class="card">
  <div class="card-header">
    <div class="logo-text">LETS FLY TRAVEL</div>
    <div class="logo-sub">DBA BASE FARE</div>
  </div>
  <div class="card-body">
    <div class="icon-circle">
      <span class="material-symbols-outlined">link_off</span>
    </div>
    <h2>Invalid or Expired Link</h2>
    <p>
      This authorization link is no longer valid. It may have expired, already been used, or the URL may be incorrect.
    </p>
    <p style="font-size:13px; color:#94a3b8;">
      If you believe this is an error, please contact your travel agent or our support team for a new authorization link.
    </p>
    <div class="contact-box">
      <p>Need help? Contact us at<br>
        <a href="mailto:support@base-fare.com">support@base-fare.com</a>
      </p>
    </div>
  </div>
</div>

</body>
</html>
