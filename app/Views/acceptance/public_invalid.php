<?php
$dbaName = 'Lets Fly Travel LLC DBA Base Fare';
if (isset($acceptance)) {
    $fareBreakdown = $acceptance->fare_breakdown ?? [];
    if (!empty($fareBreakdown) && is_array($fareBreakdown)) {
        $firstItem = reset($fareBreakdown);
        if ($firstItem && isset($firstItem['label']) && strtolower(trim($firstItem['label'])) === 'airline tickets') {
            $dbaName = 'Airline Tickets';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Invalid Link | <?= htmlspecialchars($dbaName) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@400,0&display=swap" rel="stylesheet"/>
<style>
  * { margin:0; padding:0; box-sizing:border-box; }
  body { font-family:'Inter','Manrope',sans-serif; background:linear-gradient(135deg,#f8fafb 0%,#f1f5f9 100%); min-height:100vh; display:flex; align-items:center; justify-content:center; padding:24px; }
  .card { background:#fff; border-radius:20px; box-shadow:0 20px 60px rgba(15,30,60,0.08),0 2px 8px rgba(0,0,0,0.04); max-width:480px; width:100%; overflow:hidden; }
  .card-header { background:linear-gradient(135deg,#0f1e3c 0%,#1a3a6b 100%); padding:32px 24px; text-align:center; }
  .logo-text { color:#fff; font-family:'Manrope',sans-serif; font-size:20px; font-weight:800; letter-spacing:1px; }
  .card-body { padding:40px 32px; text-align:center; }
  .icon-circle { width:80px; height:80px; border-radius:50%; background:linear-gradient(135deg,#fee2e2,#fecaca); display:inline-flex; align-items:center; justify-content:center; margin-bottom:20px; }
  .icon-circle .material-symbols-outlined { font-size:40px; color:#ef4444; font-variation-settings:'FILL' 1; }
  .card-body h2 { font-family:'Manrope',sans-serif; font-size:22px; font-weight:800; color:#1e293b; margin-bottom:8px; }
  .card-body .sub { font-size:14px; color:#64748b; line-height:1.7; margin-bottom:24px; }
  .btn-primary { display:inline-flex; align-items:center; justify-content:center; gap:8px; background:linear-gradient(135deg,#2563eb 0%,#1d4ed8 100%); color:#fff; font-weight:600; font-size:14px; text-decoration:none; padding:12px 24px; border-radius:12px; transition:all 0.2s; box-shadow:0 4px 12px rgba(37,99,235,0.2); }
  .btn-primary:hover { transform:translateY(-2px); box-shadow:0 8px 16px rgba(37,99,235,0.3); }
</style>
</head>
<body>

<div class="card">
  <div class="card-header">
    <div class="logo-text"><?= htmlspecialchars(strtoupper($dbaName)) ?></div>
  </div>
  <div class="card-body">
    <div class="icon-circle"><span class="material-symbols-outlined">error</span></div>
    <h2>Link Invalid or Expired</h2>
    <p class="sub">This authorization link is no longer valid. It may have expired or already been processed.</p>
    <a href="mailto:support@letsflytravel.com" class="btn-primary">
      <span class="material-symbols-outlined" style="font-size:18px;">mail</span>
      Contact Support
    </a>
  </div>
</div>

</body>
</html>
