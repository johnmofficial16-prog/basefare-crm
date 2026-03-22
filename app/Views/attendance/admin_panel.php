<?php
/**
 * Admin Attendance Panel
 * Shows live board, override queue, and break abuse alerts.
 * 
 * @var array $boardData  From AttendanceService::getLiveBoardData()
 */
use Illuminate\Database\Capsule\Manager as Capsule;

// Get pending overrides count and break abuse alerts
$today = date('Y-m-d');
$abuseAlerts = Capsule::table('activity_log')
    ->where('action', 'break_abuse_detected')
    ->where('created_at', '>=', $today . ' 00:00:00')
    ->orderBy('created_at', 'desc')
    ->get();
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<title>Attendance Panel - Base Fare CRM</title>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
<script src="https://cdn.tailwindcss.com?plugins=forms"></script>
<script>
tailwind.config={darkMode:"class",theme:{extend:{colors:{primary:"#163274","primary-container":"#314a8d",background:"#f8f9fa",surface:"#f8f9fa","surface-container-lowest":"#ffffff","surface-container-low":"#f3f4f5","surface-container":"#edeeef","on-surface":"#191c1d","on-surface-variant":"#434653","outline-variant":"#c3c6d5",error:"#ba1a1a","error-container":"#ffdad6","secondary-container":"#c6e4f4","on-secondary-container":"#4a6774"},fontFamily:{headline:["Manrope"],body:["Inter"],label:["Inter"]}}}}
</script>
<style>
.material-symbols-outlined{font-variation-settings:'FILL' 0,'wght' 400,'GRAD' 0,'opsz' 24}
.glass-effect{backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px)}
</style>
</head>
<body class="bg-background font-body text-on-surface antialiased overflow-x-hidden">

<!-- Top Nav -->
<nav class="fixed top-0 w-full z-50 bg-white/70 backdrop-blur-md flex items-center justify-between px-8 py-4 shadow-sm shadow-blue-900/5">
  <span class="text-xl font-extrabold text-primary tracking-tighter font-headline">Base Fare CRM</span>
  <div class="flex items-center gap-6">
    <span class="font-semibold font-headline"><?= htmlspecialchars($_SESSION['user_name'] ?? 'Admin') ?></span>
    <a href="/logout" class="text-slate-500 hover:text-primary font-headline font-bold text-sm uppercase tracking-wider">Logout</a>
  </div>
</nav>

<!-- Sidebar -->
<aside class="fixed left-0 top-0 h-full w-64 z-40 bg-white/60 backdrop-blur-xl flex flex-col pt-24 shadow-[4px_0_24px_rgba(22,50,116,0.05)]">
  <div class="px-6 mb-8">
    <h2 class="text-primary font-headline font-extrabold text-xs uppercase tracking-[0.2em]">Admin Portal</h2>
  </div>
  <nav class="flex flex-col gap-1 font-label text-sm font-medium tracking-wide">
    <a class="flex items-center gap-3 px-6 py-4 text-slate-500 hover:bg-slate-50/50" href="/dashboard"><span class="material-symbols-outlined">dashboard</span> Dashboard</a>
    <a class="flex items-center gap-3 px-6 py-4 text-slate-500 hover:bg-slate-50/50" href="/shifts/week"><span class="material-symbols-outlined">calendar_month</span> Shift Scheduling</a>
    <a class="flex items-center gap-3 px-6 py-4 bg-[#163274]/10 text-primary border-r-4 border-primary" href="/attendance/admin"><span class="material-symbols-outlined">how_to_reg</span> Attendance</a>
    <a class="flex items-center gap-3 px-6 py-4 text-slate-500 hover:bg-slate-50/50" href="#"><span class="material-symbols-outlined">payments</span> Transactions</a>
    <a class="flex items-center gap-3 px-6 py-4 text-slate-500 hover:bg-slate-50/50" href="#"><span class="material-symbols-outlined">receipt_long</span> Payroll</a>
    <a class="flex items-center gap-3 px-6 py-4 text-slate-500 hover:bg-slate-50/50" href="#"><span class="material-symbols-outlined">settings</span> Settings</a>
  </nav>
</aside>

<!-- Main Content -->
<main class="ml-64 pt-24 pb-20 px-10 min-h-screen">

  <h1 class="text-4xl font-headline font-extrabold text-primary tracking-tight mb-2">Attendance Panel</h1>
  <p class="text-on-surface-variant font-medium opacity-70 mb-8">Live monitoring for <?= date('l, F j, Y') ?></p>

  <!-- Status Cards -->
  <div class="grid grid-cols-4 gap-6 mb-10">
    <!-- Clocked In -->
    <div class="bg-green-50 rounded-2xl p-6">
      <div class="flex items-center justify-between mb-2">
        <span class="material-symbols-outlined text-green-600 text-2xl">check_circle</span>
        <span class="text-3xl font-headline font-extrabold text-green-700"><?= count($boardData['in']) ?></span>
      </div>
      <p class="text-sm font-semibold text-green-800">Clocked In</p>
    </div>
    <!-- On Break -->
    <div class="bg-amber-50 rounded-2xl p-6">
      <div class="flex items-center justify-between mb-2">
        <span class="material-symbols-outlined text-amber-600 text-2xl">coffee</span>
        <span class="text-3xl font-headline font-extrabold text-amber-700"><?= count($boardData['on_break']) ?></span>
      </div>
      <p class="text-sm font-semibold text-amber-800">On Break</p>
    </div>
    <!-- Absent -->
    <div class="bg-gray-100 rounded-2xl p-6">
      <div class="flex items-center justify-between mb-2">
        <span class="material-symbols-outlined text-gray-500 text-2xl">person_off</span>
        <span class="text-3xl font-headline font-extrabold text-gray-600"><?= count($boardData['absent']) ?></span>
      </div>
      <p class="text-sm font-semibold text-gray-700">Absent / Not In</p>
    </div>
    <!-- Pending Override -->
    <div class="bg-red-50 rounded-2xl p-6">
      <div class="flex items-center justify-between mb-2">
        <span class="material-symbols-outlined text-red-600 text-2xl">warning</span>
        <span class="text-3xl font-headline font-extrabold text-red-700"><?= count($boardData['pending_override']) ?></span>
      </div>
      <p class="text-sm font-semibold text-red-800">Pending Override</p>
    </div>
  </div>

  <!-- Override Queue -->
  <?php if (!empty($boardData['pending_override'])): ?>
  <section class="mb-10">
    <h2 class="text-xl font-headline font-extrabold text-primary mb-4 flex items-center gap-2">
      <span class="material-symbols-outlined text-red-500">priority_high</span> Override Queue
    </h2>
    <div class="space-y-3">
      <?php foreach ($boardData['pending_override'] as $agent): ?>
      <div class="bg-white rounded-xl p-5 shadow-sm flex items-center justify-between" id="override-<?= $agent->id ?>">
        <div class="flex items-center gap-4">
          <div class="w-10 h-10 rounded-full bg-red-100 flex items-center justify-center text-red-700 font-bold text-sm">
            <?= strtoupper(substr($agent->name, 0, 1)) ?>
          </div>
          <div>
            <p class="font-headline font-bold text-on-surface"><?= htmlspecialchars($agent->name) ?></p>
            <p class="text-xs text-on-surface-variant">Blocked — too late to clock in</p>
          </div>
        </div>
        <div class="flex items-center gap-3">
          <input type="text" id="reason-<?= $agent->id ?>" placeholder="Override reason..."
                 class="px-3 py-2 rounded-lg bg-surface-container-low text-sm font-medium w-56 border-0 focus:ring-2 focus:ring-primary/20"/>
          <button onclick="approveOverride(<?= $agent->id ?>)" 
                  class="px-5 py-2 bg-gradient-to-r from-primary to-primary-container text-white rounded-lg font-bold text-sm hover:opacity-90 active:scale-95 transition-all">
            Approve
          </button>
          <button onclick="denyOverride(<?= $agent->id ?>)"
                  class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg font-bold text-sm hover:bg-gray-300 transition-all">
            Deny
          </button>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <!-- Live Board: Who's In -->
  <?php if (!empty($boardData['in'])): ?>
  <section class="mb-10">
    <h2 class="text-xl font-headline font-extrabold text-primary mb-4 flex items-center gap-2">
      <span class="material-symbols-outlined text-green-500">groups</span> Currently Working
    </h2>
    <div class="grid grid-cols-2 lg:grid-cols-3 gap-4">
      <?php foreach ($boardData['in'] as $item): ?>
      <div class="bg-white rounded-xl p-4 shadow-sm flex items-center gap-4">
        <div class="w-10 h-10 rounded-full bg-green-100 flex items-center justify-center text-green-700 font-bold text-sm">
          <?= strtoupper(substr($item['agent']->name, 0, 1)) ?>
        </div>
        <div>
          <p class="font-headline font-bold text-on-surface text-sm"><?= htmlspecialchars($item['agent']->name) ?></p>
          <p class="text-xs text-on-surface-variant">In since <?= date('g:i A', strtotime($item['session']->clock_in)) ?>
            <?php if ($item['session']->late_minutes > 0): ?>
              <span class="text-red-500 font-semibold"> • <?= $item['session']->late_minutes ?>m late</span>
            <?php endif; ?>
          </p>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <!-- On Break -->
  <?php if (!empty($boardData['on_break'])): ?>
  <section class="mb-10">
    <h2 class="text-xl font-headline font-extrabold text-primary mb-4 flex items-center gap-2">
      <span class="material-symbols-outlined text-amber-500">coffee</span> On Break
    </h2>
    <div class="grid grid-cols-2 lg:grid-cols-3 gap-4">
      <?php foreach ($boardData['on_break'] as $item): ?>
      <div class="bg-amber-50 rounded-xl p-4 shadow-sm flex items-center gap-4">
        <div class="w-10 h-10 rounded-full bg-amber-200 flex items-center justify-center text-amber-800 font-bold text-sm">
          <?= strtoupper(substr($item['agent']->name, 0, 1)) ?>
        </div>
        <div>
          <p class="font-headline font-bold text-on-surface text-sm"><?= htmlspecialchars($item['agent']->name) ?></p>
          <p class="text-xs text-amber-700 font-semibold"><?= ucfirst($item['break']->break_type) ?> since <?= date('g:i A', strtotime($item['break']->break_start)) ?></p>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <!-- Break Abuse Alerts -->
  <?php if ($abuseAlerts->count() > 0): ?>
  <section class="mb-10">
    <h2 class="text-xl font-headline font-extrabold text-primary mb-4 flex items-center gap-2">
      <span class="material-symbols-outlined text-red-500">flag</span> Break Abuse Alerts (Today)
    </h2>
    <div class="space-y-3">
      <?php foreach ($abuseAlerts as $alert):
        $details = json_decode($alert->details, true);
      ?>
      <div class="bg-red-50 rounded-xl p-4 shadow-sm flex items-center gap-4">
        <span class="material-symbols-outlined text-red-500">warning</span>
        <div>
          <p class="font-semibold text-red-800 text-sm"><?= htmlspecialchars($details['agent_name'] ?? 'Agent') ?></p>
          <p class="text-xs text-red-600">
            <?php foreach ($details['reasons'] ?? [] as $r): ?>
              <span class="inline-block px-2 py-0.5 bg-red-100 rounded text-[10px] font-bold mr-1"><?= htmlspecialchars($r) ?></span>
            <?php endforeach; ?>
          </p>
        </div>
        <span class="ml-auto text-xs text-red-400"><?= date('g:i A', strtotime($alert->created_at)) ?></span>
      </div>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

</main>

<script>
async function approveOverride(agentId) {
  const reason = document.getElementById('reason-' + agentId).value.trim();
  if (!reason || reason.length < 5) {
    alert('Please enter a reason (at least 5 characters).');
    return;
  }

  const r = await fetch('/attendance/override', {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify({agent_id: agentId, date: '<?= $today ?>', reason: reason})
  });
  const data = await r.json();
  if (data.success) {
    document.getElementById('override-' + agentId).innerHTML = '<p class="text-green-700 font-semibold p-4">✓ Override approved</p>';
  } else {
    alert(data.message || 'Error');
  }
}

function denyOverride(agentId) {
  document.getElementById('override-' + agentId).innerHTML = '<p class="text-red-500 font-semibold p-4">Override denied</p>';
}

// Auto-refresh the page every 60 seconds
setTimeout(() => window.location.reload(), 60000);
</script>

</body>
</html>
