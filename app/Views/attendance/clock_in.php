<?php
/**
 * Clock-In Lobby Page
 * Agents see this every morning before they can access the CRM.
 * 
 * @var array $stateInfo  From AttendanceService::getCurrentState()
 */

use App\Services\AttendanceService;
use App\Services\ShiftService;
use App\Models\AttendanceOverride;
use Illuminate\Database\Capsule\Manager as Capsule;

$userId = $_SESSION['user_id'];
$userName = $_SESSION['user_name'] ?? 'Agent';

// Get today's shift info — bust cache to ensure fresh data on every lobby load
$shiftService = new ShiftService();
$shiftCacheKey = "shift_cache_{$userId}_" . date('Y-m-d');
unset($_SESSION[$shiftCacheKey], $_SESSION[$shiftCacheKey . '_ts']);
$todayShift = $shiftService->getAgentShiftForDate($userId, date('Y-m-d'));
$shiftLabel = $todayShift && $todayShift->template
    ? $todayShift->template->name . ' (' . date('g:i A', strtotime($todayShift->shift_start)) . ' – ' . date('g:i A', strtotime($todayShift->shift_end)) . ')'
    : ($todayShift ? date('g:i A', strtotime($todayShift->shift_start)) . ' – ' . date('g:i A', strtotime($todayShift->shift_end)) : null);

// Determine status message
$flashError   = $_SESSION['flash_error'] ?? null;
$flashSuccess = $_SESSION['flash_success'] ?? null;
$flashInfo    = $_SESSION['flash_info'] ?? null;
unset($_SESSION['flash_error'], $_SESSION['flash_success'], $_SESSION['flash_info']);

$currentState = $stateInfo['state'] ?? AttendanceService::STATE_NOT_CLOCKED_IN;

// G3: Check if admin denied the override today so we can show the reason to agent
$denialReason = null;
if ($currentState === AttendanceService::STATE_NOT_CLOCKED_IN) {
    $denial = AttendanceOverride::where('agent_id', $userId)
        ->where('shift_date', date('Y-m-d'))
        ->where('override_type', AttendanceOverride::TYPE_DENIAL)
        ->orderBy('created_at', 'desc')
        ->first();
    if ($denial) {
        $denialReason = $denial->reason;
    }
}

?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<title>Clock In - Base Fare CRM</title>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
<script src="https://cdn.tailwindcss.com?plugins=forms"></script>
<script>
tailwind.config = {
  darkMode: "class",
  theme: {
    extend: {
      colors: {
        primary:"#163274","primary-container":"#314a8d",background:"#f8f9fa",surface:"#f8f9fa",
        "surface-container-lowest":"#ffffff","surface-container-low":"#f3f4f5","surface-container":"#edeeef",
        "on-surface":"#191c1d","on-surface-variant":"#434653","outline-variant":"#c3c6d5",
        "error":"#ba1a1a","error-container":"#ffdad6",
      },
      fontFamily: {headline:["Manrope"],body:["Inter"],label:["Inter"]}
    }
  }
}
</script>
<style>
.material-symbols-outlined{font-variation-settings:'FILL' 0,'wght' 400,'GRAD' 0,'opsz' 24}
@keyframes pulse-glow{0%,100%{box-shadow:0 0 0 0 rgba(22,50,116,0.4)}50%{box-shadow:0 0 0 15px rgba(22,50,116,0)}}
.pulse-glow{animation:pulse-glow 2s ease-in-out infinite}
</style>
</head>
<body class="bg-background font-body text-on-surface antialiased min-h-screen flex flex-col items-center justify-center p-4">

<!-- Top bar with logout -->
<div class="w-full max-w-md flex items-center justify-between mb-8">
  <div>
    <h2 class="text-2xl font-extrabold text-primary tracking-tighter font-headline">Base Fare CRM</h2>
    <p class="text-sm text-on-surface-variant mt-1">Clock-In Portal</p>
  </div>
  <a href="/logout" class="flex items-center gap-1 px-4 py-2 rounded-lg text-sm font-semibold text-on-surface-variant hover:text-red-600 hover:bg-red-50 transition-all">
    <span class="material-symbols-outlined text-base">logout</span> Sign Out
  </a>
</div>

<!-- Main Glass Card -->
<div class="w-full max-w-md bg-white/70 backdrop-blur-xl rounded-3xl shadow-[0_20px_60px_rgba(22,50,116,0.08)] p-10 text-center">
  
  <!-- Live Clock -->
  <div class="mb-8">
    <div id="liveClock" class="text-6xl font-headline font-extrabold text-primary tracking-tight"></div>
    <div id="liveDate" class="text-sm font-medium text-on-surface-variant mt-2"></div>
  </div>

  <!-- Flash Messages -->
  <?php if ($flashError): ?>
  <div class="mb-6 p-4 bg-red-50 text-red-800 rounded-xl text-sm font-semibold flex items-center gap-2">
    <span class="material-symbols-outlined text-red-500 text-lg">error</span>
    <?= htmlspecialchars($flashError) ?>
  </div>
  <?php endif; ?>

  <?php if ($flashSuccess): ?>
  <div class="mb-6 p-4 bg-green-50 text-green-800 rounded-xl text-sm font-semibold flex items-center gap-2">
    <span class="material-symbols-outlined text-green-500 text-lg">check_circle</span>
    <?= htmlspecialchars($flashSuccess) ?>
  </div>
  <?php endif; ?>

  <?php if ($flashInfo): ?>
  <div class="mb-6 p-4 bg-blue-50 text-blue-800 rounded-xl text-sm font-semibold flex items-center gap-2">
    <span class="material-symbols-outlined text-blue-500 text-lg">info</span>
    <?= htmlspecialchars($flashInfo) ?>
  </div>
  <?php endif; ?>

  <?php if ($denialReason): ?>
  <!-- G3: Show denial reason from admin -->
  <div class="mb-6 p-4 bg-red-50 border border-red-200 text-red-800 rounded-xl text-sm">
    <p class="font-bold flex items-center gap-2 mb-1">
      <span class="material-symbols-outlined text-red-500 text-lg">block</span>
      Override Request Denied
    </p>
    <p class="text-red-700">Admin reason: <strong><?= htmlspecialchars($denialReason) ?></strong></p>
    <p class="text-red-500 text-xs mt-1">Please contact your admin if you believe this was an error.</p>
  </div>
  <?php endif; ?>

  <!-- Status Message -->
  <?php if ($currentState === AttendanceService::STATE_NOT_CLOCKED_IN): ?>
    <?php if ($shiftLabel): ?>
    <p class="text-on-surface-variant mb-2">Good morning, <strong class="text-on-surface"><?= htmlspecialchars($userName) ?></strong>!</p>
    <div class="inline-block px-4 py-2 bg-blue-50 text-blue-700 rounded-lg text-sm font-semibold mb-8">
      <span class="material-symbols-outlined text-sm align-[-3px]">schedule</span>
      Your shift: <?= htmlspecialchars($shiftLabel) ?>
    </div>
    <?php else: ?>
    <div class="mb-8 p-4 bg-gray-100 text-gray-600 rounded-xl text-sm font-semibold">
      <span class="material-symbols-outlined text-sm align-[-3px]">event_busy</span>
      You are not scheduled today. Contact your admin.
    </div>
    <?php endif; ?>
  <?php elseif ($currentState === AttendanceService::STATE_CLOCKED_OUT): ?>
    <div class="mb-8 p-4 bg-gray-100 text-gray-600 rounded-xl text-sm font-semibold">
      <span class="material-symbols-outlined text-sm align-[-3px]">check_circle</span>
      You have clocked out for the day. Good work!
    </div>
  <?php endif; ?>

  <!-- Clock In Button -->
  <?php if ($currentState === AttendanceService::STATE_NOT_CLOCKED_IN && $shiftLabel): ?>
  <form method="POST" action="/clock-in" id="clockInForm">
    <button type="submit" id="clockInBtn" onclick="this.disabled=true;this.textContent='CLOCKING IN...';document.getElementById('clockInForm').submit();" class="w-48 h-48 rounded-full bg-gradient-to-br from-primary to-primary-container text-white font-headline font-extrabold text-xl shadow-2xl shadow-primary/30 hover:opacity-90 active:scale-95 transition-all pulse-glow mx-auto flex items-center justify-center">
      CLOCK IN
    </button>
  </form>
  <?php endif; ?>

  <!-- Already clocked in — redirect to dashboard -->
  <?php if ($currentState === AttendanceService::STATE_CLOCKED_IN || $currentState === AttendanceService::STATE_ON_BREAK): ?>
  <div class="mb-4 p-4 bg-green-50 text-green-800 rounded-xl text-sm font-semibold">
    You are already clocked in.
  </div>
  <a href="/dashboard" class="inline-block px-8 py-3 bg-primary text-white rounded-lg font-bold text-sm hover:opacity-90 transition-all">Go to Dashboard →</a>
  <?php endif; ?>
</div>

<!-- Footer -->
<footer class="mt-10 text-on-surface-variant text-xs font-label uppercase tracking-widest">
  © 2026 Base Fare CRM. All rights reserved.
</footer>

<script>
// Live clock
function updateClock() {
  const now = new Date();
  document.getElementById('liveClock').textContent = now.toLocaleTimeString('en-US', {hour:'2-digit',minute:'2-digit',second:'2-digit',hour12:true});
  document.getElementById('liveDate').textContent = now.toLocaleDateString('en-US', {weekday:'long',year:'numeric',month:'long',day:'numeric'});
}
updateClock();
setInterval(updateClock, 1000);
</script>
</body>
</html>
