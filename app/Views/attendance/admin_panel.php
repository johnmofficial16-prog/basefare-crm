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

// Load washroom thresholds for live highlighting
$_wcRow        = Capsule::table('system_config')->where('key', 'abuse.single_washroom_max')->first();
$_wcSingleMax  = (int)($_wcRow->value ?? 15);
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
<!-- G5: Shared Admin Sidebar -->
<?php $activePage = 'attendance'; require __DIR__ . '/../partials/admin_sidebar.php'; ?>

<!-- Main Content -->
<main class="ml-60 pt-8 pb-20 px-10 min-h-screen">

  <h1 class="text-4xl font-headline font-extrabold text-primary tracking-tight mb-2">Attendance Panel</h1>
  <p class="text-on-surface-variant font-medium opacity-70 mb-8">Live monitoring for <?= date('l, F j, Y') ?></p>

  <!-- Status Cards -->
  <div class="grid grid-cols-5 gap-4 mb-10">
    <!-- Clocked In -->
    <div class="bg-green-50 rounded-2xl p-5">
      <div class="flex items-center justify-between mb-2">
        <span class="material-symbols-outlined text-green-600 text-2xl">check_circle</span>
        <span class="text-3xl font-headline font-extrabold text-green-700" id="count-in"><?= count($boardData['in']) ?></span>
      </div>
      <p class="text-sm font-semibold text-green-800">Clocked In</p>
    </div>
    <!-- On Break -->
    <div class="bg-amber-50 rounded-2xl p-5">
      <div class="flex items-center justify-between mb-2">
        <span class="material-symbols-outlined text-amber-600 text-2xl">coffee</span>
        <span class="text-3xl font-headline font-extrabold text-amber-700" id="count-break"><?= count($boardData['on_break']) ?></span>
      </div>
      <p class="text-sm font-semibold text-amber-800">On Break</p>
    </div>
    <!-- Completed Today -->
    <div class="bg-blue-50 rounded-2xl p-5">
      <div class="flex items-center justify-between mb-2">
        <span class="material-symbols-outlined text-blue-500 text-2xl">task_alt</span>
        <span class="text-3xl font-headline font-extrabold text-blue-700" id="count-completed"><?= count($boardData['completed']) ?></span>
      </div>
      <p class="text-sm font-semibold text-blue-800">Completed</p>
    </div>
    <!-- Absent -->
    <div class="bg-gray-100 rounded-2xl p-5">
      <div class="flex items-center justify-between mb-2">
        <span class="material-symbols-outlined text-gray-500 text-2xl">person_off</span>
        <span class="text-3xl font-headline font-extrabold text-gray-600" id="count-absent"><?= count($boardData['absent']) ?></span>
      </div>
      <p class="text-sm font-semibold text-gray-700">Absent / Not In</p>
    </div>
    <!-- Pending Override -->
    <div class="bg-red-50 rounded-2xl p-5">
      <div class="flex items-center justify-between mb-2">
        <span class="material-symbols-outlined text-red-600 text-2xl">warning</span>
        <span class="text-3xl font-headline font-extrabold text-red-700" id="count-override"><?= count($boardData['pending_override']) ?></span>
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
        <div class="flex-1">
          <p class="font-headline font-bold text-on-surface text-sm"><?= htmlspecialchars($item['agent']->name) ?></p>
          <p class="text-xs text-on-surface-variant">In since <?= date('g:i A', strtotime($item['session']->clock_in)) ?>
            <?php if ($item['session']->late_minutes > 0): ?>
              <span class="text-red-500 font-semibold"> • <?= $item['session']->late_minutes ?>m late</span>
            <?php endif; ?>
          </p>
        </div>
        <button onclick="manualClockOut(<?= $item['agent']->id ?>, '<?= addslashes($item['agent']->name) ?>')" class="px-3 py-1.5 bg-red-50 text-red-600 rounded-lg text-xs font-bold hover:bg-red-100 transition-all" title="Manual Clock Out">
          <span class="material-symbols-outlined text-sm">logout</span>
        </button>
      </div>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <!-- On Break -->
  <?php if (!empty($boardData['on_break'])): ?>
  <section class="mb-10" id="section-on-break">
    <h2 class="text-xl font-headline font-extrabold text-primary mb-4 flex items-center gap-2">
      <span class="material-symbols-outlined text-amber-500">coffee</span> On Break
    </h2>
    <div class="grid grid-cols-2 lg:grid-cols-3 gap-4" id="break-cards-grid">
      <?php foreach ($boardData['on_break'] as $item): ?>
      <?php
        $breakStartTs      = strtotime($item['break']->break_start);
        $breakElapsedMins  = (int) round((time() - $breakStartTs) / 60);
        $isWc              = $item['break']->break_type === 'washroom';
        $isOverrun         = $isWc && $breakElapsedMins > $_wcSingleMax;
        $cardBg            = $isOverrun ? 'bg-red-50 border border-red-300' : 'bg-amber-50';
        $avatarBg          = $isOverrun ? 'bg-red-200 text-red-800' : 'bg-amber-200 text-amber-800';
        $elapsedClass      = $isOverrun ? 'text-red-600 font-bold' : 'text-amber-700 font-semibold';
      ?>
      <div class="<?= $cardBg ?> rounded-xl p-4 shadow-sm flex items-center gap-4 break-card"
           data-agent-id="<?= $item['agent']->id ?>"
           data-break-start="<?= $breakStartTs ?>"
           data-break-type="<?= htmlspecialchars($item['break']->break_type) ?>"
           data-agent-name="<?= htmlspecialchars(addslashes($item['agent']->name)) ?>">
        <div class="w-10 h-10 rounded-full <?= $avatarBg ?> flex items-center justify-center font-bold text-sm">
          <?= strtoupper(substr($item['agent']->name, 0, 1)) ?>
        </div>
        <div class="flex-1 min-w-0">
          <div class="flex items-center gap-1.5">
            <p class="font-headline font-bold text-on-surface text-sm truncate"><?= htmlspecialchars($item['agent']->name) ?></p>
            <?php if ($isOverrun): ?>
            <span class="shrink-0 inline-flex items-center gap-0.5 px-1.5 py-0.5 bg-red-100 text-red-700 rounded-full text-[9px] font-extrabold uppercase tracking-wide">
              <span class="material-symbols-outlined text-[10px]">warning</span> Overrun
            </span>
            <?php endif; ?>
          </div>
          <p class="text-xs <?= $elapsedClass ?> break-elapsed-label">
            <?= ucfirst($item['break']->break_type) ?> break ·
            <span class="break-elapsed-mins"><?= $breakElapsedMins ?></span>m elapsed
            <?php if ($isWc): ?>
            <span class="text-slate-400">(max <?= $_wcSingleMax ?>m)</span>
            <?php endif; ?>
          </p>
        </div>
        <button onclick="adminForceEndBreak(<?= $item['agent']->id ?>, '<?= addslashes($item['agent']->name) ?>')"
                class="shrink-0 px-3 py-1.5 <?= $isOverrun ? 'bg-red-100 text-red-700 hover:bg-red-200' : 'bg-orange-100 text-orange-700 hover:bg-orange-200' ?> rounded-lg text-xs font-bold transition-all flex items-center gap-1" title="Force End Break">
          <span class="material-symbols-outlined text-xs">timer_off</span> End Break
        </button>
      </div>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>
  <?php if (empty($boardData['on_break'])): ?>
  <div id="section-on-break" class="hidden"></div>
  <?php endif; ?>

  <!-- Absent / Not In -->
  <?php if (!empty($boardData['absent'])): ?>
  <section class="mb-10">
    <h2 class="text-xl font-headline font-extrabold text-primary mb-4 flex items-center gap-2">
      <span class="material-symbols-outlined text-gray-500">person_off</span> Absent / Not Checked In
    </h2>
    <div class="grid grid-cols-2 lg:grid-cols-3 gap-4">
      <?php foreach ($boardData['absent'] as $agent): ?>
      <div class="bg-gray-50 rounded-xl p-4 shadow-sm flex items-center gap-4">
        <div class="w-10 h-10 rounded-full bg-gray-200 flex items-center justify-center text-gray-600 font-bold text-sm">
          <?= strtoupper(substr($agent->name, 0, 1)) ?>
        </div>
        <div class="flex-1">
          <p class="font-headline font-bold text-on-surface text-sm"><?= htmlspecialchars($agent->name) ?></p>
          <p class="text-xs text-on-surface-variant italic">Not on shift or missed gate</p>
        </div>
        <button onclick="manualClockIn(<?= $agent->id ?>, '<?= addslashes($agent->name) ?>')" 
                class="px-3 py-1.5 bg-blue-50 text-blue-600 rounded-lg text-xs font-bold hover:bg-blue-100 transition-all flex items-center gap-1">
          <span class="material-symbols-outlined text-xs">login</span> Clock In
        </button>
      </div>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <!-- Completed Today -->
  <?php if (!empty($boardData['completed'])): ?>
  <section class="mb-10">
    <h2 class="text-xl font-headline font-extrabold text-primary mb-4 flex items-center gap-2">
      <span class="material-symbols-outlined text-blue-500">task_alt</span> Completed Today
    </h2>
    <div class="grid grid-cols-2 lg:grid-cols-3 gap-4">
      <?php foreach ($boardData['completed'] as $item): ?>
      <div class="bg-blue-50 rounded-xl p-4 shadow-sm flex items-center gap-4">
        <div class="w-10 h-10 rounded-full bg-blue-100 flex items-center justify-center text-blue-700 font-bold text-sm">
          <?= strtoupper(substr($item['agent']->name, 0, 1)) ?>
        </div>
        <div class="flex-1">
          <p class="font-headline font-bold text-on-surface text-sm"><?= htmlspecialchars($item['agent']->name) ?></p>
          <p class="text-xs text-blue-600 font-semibold">
            <?= floor(($item['session']->total_work_mins ?? 0) / 60) ?>h <?= ($item['session']->total_work_mins ?? 0) % 60 ?>m worked
            <?php if ($item['session']->clock_out): ?>
              · Out at <?= date('g:i A', strtotime($item['session']->clock_out)) ?>
            <?php endif; ?>
          </p>
        </div>
        <button onclick="manualClockIn(<?= $item['agent']->id ?>, '<?= addslashes($item['agent']->name) ?>')" 
                class="px-3 py-1.5 bg-indigo-50 text-indigo-600 rounded-lg text-xs font-bold hover:bg-indigo-100 transition-all flex items-center gap-1" title="Re-Clock In">
          <span class="material-symbols-outlined text-xs">replay</span> Resume
        </button>
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

  <!-- Quick Links -->
  <div class="flex gap-4 mt-4">
    <a href="/attendance/admin/history" class="px-5 py-2 bg-surface-container text-on-surface-variant rounded-lg font-bold text-sm hover:bg-primary hover:text-white transition-all">
      <span class="material-symbols-outlined text-sm align-[-3px]">history</span> View History
    </a>
  </div>

</main>

<script>
const csrfToken = '<?= $_SESSION['csrf_token'] ?? '' ?>';
let lastPendingCount = <?= count($boardData['pending_override']) ?>;
const WC_SINGLE_MAX = <?= $_wcSingleMax ?>; // from system_config

async function approveOverride(agentId) {
  const reason = document.getElementById('reason-' + agentId)?.value?.trim();
  if (!reason || reason.length < 5) {
    alert('Please enter a reason (at least 5 characters).');
    return;
  }
  const r = await fetch('/attendance/override', {
    method: 'POST',
    headers: {'Content-Type':'application/json', 'X-CSRF-Token': csrfToken},
    body: JSON.stringify({agent_id: agentId, date: '<?= $today ?>', reason: reason})
  });
  const data = await r.json();
  if (data.success) {
    document.getElementById('override-' + agentId).innerHTML = '<p class="text-green-700 font-semibold p-4">✓ Override approved</p>';
  } else {
    alert(data.message || 'Error');
  }
}

async function denyOverride(agentId) {
  const reason = prompt('Reason for denial (required):');
  if (!reason || reason.trim().length < 3) {
    alert('A reason of at least 3 characters is required.');
    return;
  }
  const r = await fetch('/attendance/deny', {
    method: 'POST',
    headers: {'Content-Type':'application/json', 'X-CSRF-Token': csrfToken},
    body: JSON.stringify({agent_id: agentId, date: '<?= $today ?>', reason: reason.trim()})
  });
  const data = await r.json();
  if (data.success) {
    document.getElementById('override-' + agentId).innerHTML = '<p class="text-red-500 font-semibold p-4">✗ Override denied — ' + reason.trim() + '</p>';
  } else {
    alert(data.message || 'Error');
  }
}

async function adminForceEndBreak(agentId, agentName) {
  if (!confirm('Force-end break for ' + agentName + '?')) return;
  const r = await fetch('/attendance/admin/force-end-break', {
    method: 'POST',
    headers: {'Content-Type':'application/json', 'X-CSRF-Token': csrfToken},
    body: JSON.stringify({agent_id: agentId})
  });
  const data = await r.json();
  alert(data.message);
  if (data.success) refreshBoard();
}

async function manualClockIn(agentId, agentName) {
  if (!confirm('Manually clock in ' + agentName + '?')) return;
  const r = await fetch('/attendance/admin/clock-in', {
    method: 'POST',
    headers: {'Content-Type':'application/json', 'X-CSRF-Token': csrfToken},
    body: JSON.stringify({agent_id: agentId})
  });
  const data = await r.json();
  alert(data.message);
  if (data.success) refreshBoard();
}

async function manualClockOut(agentId, agentName) {
  if (!confirm('Manually clock out ' + agentName + '?')) return;
  const r = await fetch('/attendance/admin/clock-out', {
    method: 'POST',
    headers: {'Content-Type':'application/json', 'X-CSRF-Token': csrfToken},
    body: JSON.stringify({agent_id: agentId})
  });
  const data = await r.json();
  alert(data.message);
  if (data.success) refreshBoard();
}

// ── Live break elapsed ticker ────────────────────────────────────────────────
// Runs every 60s client-side to update elapsed minutes and flip cards red
// without waiting for a full AJAX board refresh.
function tickBreakCards() {
  const now = Math.floor(Date.now() / 1000);
  document.querySelectorAll('.break-card').forEach(card => {
    const start     = parseInt(card.dataset.breakStart, 10);
    const type      = card.dataset.breakType;
    const elapsed   = Math.round((now - start) / 60);
    const isWc      = type === 'washroom';
    const isOverrun = isWc && elapsed > WC_SINGLE_MAX;

    // Update minutes label
    const minsEl = card.querySelector('.break-elapsed-mins');
    if (minsEl) minsEl.textContent = elapsed;

    // Flip styling if newly overrun
    if (isOverrun && !card.classList.contains('border-red-300')) {
      card.className = card.className
        .replace('bg-amber-50', 'bg-red-50')
        .replace('bg-white', 'bg-red-50');
      card.classList.add('border', 'border-red-300');

      const avatar = card.querySelector('.w-10');
      if (avatar) {
        avatar.className = avatar.className
          .replace('bg-amber-200 text-amber-800', 'bg-red-200 text-red-800');
      }

      const label = card.querySelector('.break-elapsed-label');
      if (label) {
        label.className = label.className
          .replace('text-amber-700 font-semibold', 'text-red-600 font-bold');
      }

      // Inject overrun badge if not already present
      const nameEl = card.querySelector('.font-headline');
      if (nameEl && !card.querySelector('.overrun-badge')) {
        const badge = document.createElement('span');
        badge.className = 'overrun-badge shrink-0 inline-flex items-center gap-0.5 px-1.5 py-0.5 bg-red-100 text-red-700 rounded-full text-[9px] font-extrabold uppercase tracking-wide';
        badge.innerHTML = '<span class="material-symbols-outlined text-[10px]">warning</span> Overrun';
        nameEl.parentElement.insertBefore(badge, nameEl.nextSibling);
      }

      const btn = card.querySelector('button');
      if (btn) {
        btn.className = btn.className
          .replace('bg-orange-100 text-orange-700 hover:bg-orange-200', 'bg-red-100 text-red-700 hover:bg-red-200');
      }
    }
  });
}

// ── AJAX board refresh ───────────────────────────────────────────────────────
// Re-renders break cards section and updates all counter chips.
function renderBreakCard(agent) {
  const now     = Math.floor(Date.now() / 1000);
  const startTs = Math.floor(new Date(agent.start).getTime() / 1000);
  const elapsed = Math.round((now - startTs) / 60);
  const isWc      = agent.type === 'washroom';
  const isOverrun = isWc && elapsed > WC_SINGLE_MAX;

  const cardBg    = isOverrun ? 'bg-red-50 border border-red-300' : 'bg-amber-50';
  const avatarBg  = isOverrun ? 'bg-red-200 text-red-800' : 'bg-amber-200 text-amber-800';
  const elClass   = isOverrun ? 'text-red-600 font-bold' : 'text-amber-700 font-semibold';
  const btnClass  = isOverrun ? 'bg-red-100 text-red-700 hover:bg-red-200' : 'bg-orange-100 text-orange-700 hover:bg-orange-200';
  const overrun   = isOverrun ? `<span class="overrun-badge shrink-0 inline-flex items-center gap-0.5 px-1.5 py-0.5 bg-red-100 text-red-700 rounded-full text-[9px] font-extrabold uppercase tracking-wide"><span class="material-symbols-outlined text-[10px]">warning</span> Overrun</span>` : '';
  const maxHint   = isWc ? `<span class="text-slate-400">(max ${WC_SINGLE_MAX}m)</span>` : '';

  return `
    <div class="${cardBg} rounded-xl p-4 shadow-sm flex items-center gap-4 break-card"
         data-agent-id="${agent.id}" data-break-start="${startTs}"
         data-break-type="${agent.type}" data-agent-name="${agent.name.replace(/"/g,'&quot;')}">
      <div class="w-10 h-10 rounded-full ${avatarBg} flex items-center justify-center font-bold text-sm">
        ${agent.name.charAt(0).toUpperCase()}
      </div>
      <div class="flex-1 min-w-0">
        <div class="flex items-center gap-1.5">
          <p class="font-headline font-bold text-on-surface text-sm truncate">${agent.name}</p>
          ${overrun}
        </div>
        <p class="text-xs ${elClass} break-elapsed-label">
          ${agent.type.charAt(0).toUpperCase()+agent.type.slice(1)} break ·
          <span class="break-elapsed-mins">${elapsed}</span>m elapsed ${maxHint}
        </p>
      </div>
      <button onclick="adminForceEndBreak(${agent.id}, '${agent.name.replace(/'/g,"\\'")}')"
              class="shrink-0 px-3 py-1.5 ${btnClass} rounded-lg text-xs font-bold transition-all flex items-center gap-1">
        <span class="material-symbols-outlined text-xs">timer_off</span> End Break
      </button>
    </div>`;
}

async function refreshBoard() {
  try {
    const r = await fetch('/attendance/admin/data');
    const d = await r.json();

    // Update counter chips
    const map = {
      'count-in': d.in_count, 'count-break': d.break_count,
      'count-completed': d.completed_count ?? 0,
      'count-absent': d.absent_count, 'count-override': d.pending_count
    };
    Object.entries(map).forEach(([id, val]) => {
      const el = document.getElementById(id);
      if (el) el.textContent = val;
    });

    // Re-render break cards
    const grid = document.getElementById('break-cards-grid');
    const section = document.getElementById('section-on-break');
    if (grid && d.break_agents) {
      grid.innerHTML = d.break_agents.map(renderBreakCard).join('');
    }
    if (section) {
      section.classList.toggle('hidden', (d.break_agents?.length ?? 0) === 0);
    }

    // Override notification
    if (d.pending_count > lastPendingCount) {
      if (Notification.permission === 'granted') {
        new Notification('New Override Request', {body: 'An agent needs your approval to clock in.'});
      } else if (Notification.permission !== 'denied') {
        Notification.requestPermission();
      }
    }
    lastPendingCount = d.pending_count;
  } catch(e) {
    console.error('Board refresh error:', e);
  }
}

// Tick elapsed labels every 60s; full board refresh every 60s (offset by 30s)
tickBreakCards();
setInterval(tickBreakCards, 60000);
setInterval(refreshBoard, 60000);
</script>

</body>
</html>

