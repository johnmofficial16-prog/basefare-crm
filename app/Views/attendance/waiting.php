<?php
/**
 * Waiting for Admin Override page
 * Shown when agent is too late and needs admin approval.
 * 
 * @var array $stateInfo  From AttendanceService::getCurrentState()
 */
$userName = $_SESSION['user_name'] ?? 'Agent';
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<title>Waiting for Override - Base Fare CRM</title>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
<script src="https://cdn.tailwindcss.com?plugins=forms"></script>
<script>
tailwind.config = { theme:{ extend:{ colors:{primary:"#163274","primary-container":"#314a8d",background:"#f8f9fa","on-surface":"#191c1d","on-surface-variant":"#434653"}, fontFamily:{headline:["Manrope"],body:["Inter"]} }}}
</script>
<style>
.material-symbols-outlined{font-variation-settings:'FILL' 0,'wght' 400,'GRAD' 0,'opsz' 24}
@keyframes spin-slow{to{transform:rotate(360deg)}}.spin-slow{animation:spin-slow 3s linear infinite}
</style>
</head>
<body class="bg-background font-body text-on-surface antialiased min-h-screen flex flex-col items-center justify-center p-4">

<div class="mb-8 text-center">
  <h2 class="text-2xl font-extrabold text-primary tracking-tighter font-headline">Base Fare CRM</h2>
</div>

<div class="w-full max-w-md bg-white/70 backdrop-blur-xl rounded-3xl shadow-[0_20px_60px_rgba(22,50,116,0.08)] p-10 text-center">
  
  <!-- Warning Icon with Spinner -->
  <div class="mb-6">
    <div class="w-20 h-20 mx-auto rounded-full bg-amber-50 flex items-center justify-center">
      <span class="material-symbols-outlined text-amber-500 text-4xl spin-slow">hourglass_top</span>
    </div>
  </div>

  <h1 class="text-2xl font-headline font-extrabold text-on-surface mb-2">Waiting for Admin Override</h1>
  <p class="text-on-surface-variant mb-6">
    You arrived past the grace period, <strong><?= htmlspecialchars($userName) ?></strong>.
    An admin has been notified and will review your request.
  </p>

  <!-- Status Indicator -->
  <div class="p-4 bg-amber-50 rounded-xl mb-6">
    <div class="flex items-center justify-center gap-2 text-amber-700 font-semibold text-sm">
      <div class="w-2 h-2 rounded-full bg-amber-500 animate-pulse"></div>
      <span id="statusText">Checking for approval...</span>
    </div>
  </div>

  <p class="text-xs text-on-surface-variant">This page will automatically redirect when your admin approves.</p>
</div>

<footer class="mt-10 text-on-surface-variant text-xs uppercase tracking-widest">
  © 2026 Base Fare CRM
</footer>

<!-- Poll for override approval every 10 seconds -->
<script>
async function checkOverride() {
  try {
    const r = await fetch('/attendance/status');
    const data = await r.json();
    if (data.state === 'clocked_in' || data.state === 'on_break') {
      // Admin approved and agent somehow got clocked in
      window.location.href = '/dashboard';
    }
    // Check if override was just created — try clocking in again
    const r2 = await fetch('/clock-in', {method:'POST'});
    if (r2.redirected) {
      window.location.href = r2.url;
    } else if (r2.status === 302) {
      window.location.href = '/dashboard';
    }
  } catch(e) {
    document.getElementById('statusText').textContent = 'Connection lost. Retrying...';
  }
}
setInterval(checkOverride, 10000);
</script>
</body>
</html>
