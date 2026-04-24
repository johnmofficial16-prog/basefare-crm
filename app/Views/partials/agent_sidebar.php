<?php
/**
 * Agent Sidebar Partial
 * Shown to users with role = 'agent'.
 * Mirrors admin_sidebar.php styling for UI consistency.
 *
 * @var string $activePage  e.g. 'dashboard', 'transactions', 'acceptance', 'attendance'
 */
$activePage = $activePage ?? '';

$navItems = [
    ['href' => '/dashboard',     'icon' => 'dashboard',      'label' => 'Dashboard',    'key' => 'dashboard'],
    ['href' => '/transactions',  'icon' => 'payments',       'label' => 'Transactions', 'key' => 'transactions'],
    ['href' => '/acceptance',    'icon' => 'verified',       'label' => 'Acceptance',   'key' => 'acceptance'],
    ['href' => '/etickets',      'icon' => 'airplane_ticket','label' => 'E-Tickets',    'key' => 'etickets'],
    ['href' => '/attendance/my', 'icon' => 'calendar_month', 'label' => 'My Attendance','key' => 'attendance'],
];
?>
<aside class="fixed left-0 top-0 h-full w-60 bg-white border-r border-gray-100 flex flex-col z-30 shadow-sm">
  <!-- Logo -->
  <div class="px-6 py-5 border-b border-gray-100">
    <a href="/dashboard" class="flex items-center gap-2 no-underline">
      <div class="w-8 h-8 rounded-lg bg-primary flex items-center justify-center">
        <span class="material-symbols-outlined text-white text-sm">flight_takeoff</span>
      </div>
      <span class="font-headline font-extrabold text-primary text-sm leading-tight">Base Fare<br><span class="text-on-surface-variant font-medium text-xs">CRM Agent</span></span>
    </a>
  </div>

  <!-- Navigation -->
  <nav class="flex-1 py-4 overflow-y-auto">
    <?php foreach ($navItems as $item): ?>
    <a href="<?= $item['href'] ?>"
       class="flex items-center gap-3 px-5 py-3 text-sm font-semibold transition-all
       <?= $activePage === $item['key']
           ? 'bg-primary/10 text-primary border-r-4 border-primary'
           : 'text-on-surface-variant hover:bg-gray-50 hover:text-primary' ?>">
      <span class="material-symbols-outlined text-[20px]"><?= $item['icon'] ?></span>
      <?= $item['label'] ?>
    </a>
    <?php endforeach; ?>
  </nav>

  <!-- Bottom: Attendance + User Info -->
  <div class="px-4 py-4 border-t border-gray-100 space-y-3">

    <?php
    // Resolve current attendance state for sidebar (safe for pages that don't inject $stateInfo)
    $_sb_state   = (isset($stateInfo['state']) ? $stateInfo['state'] : null)
                    ?? ($_SESSION['att_state_' . ($_SESSION['user_id'] ?? 0)]['state'] ?? 'not_clocked_in');
    $_sb_clockIn = (isset($stateInfo['session']) && isset($stateInfo['session']->clock_in))
                    ? $stateInfo['session']->clock_in : null;
    ?>

    <!-- Attendance Status Strip -->
    <div id="sb-att" class="<?= in_array($_sb_state, ['clocked_in','on_break']) ? '' : 'hidden' ?> rounded-xl overflow-hidden border border-primary/20">
      <!-- Dark header: status + timer -->
      <div class="px-3 py-2.5 flex items-center justify-between" style="background:#0f1e3c;">
        <span id="sb-state-label" class="text-xs font-bold <?= $_sb_state === 'on_break' ? 'text-amber-300' : 'text-green-300' ?>">
          <?= $_sb_state === 'on_break' ? '☕ On Break' : '● Clocked In' ?>
        </span>
        <span id="sb-timer" class="font-mono font-bold text-white text-sm tracking-widest">00:00:00</span>
      </div>
      <!-- Controls -->
      <div class="bg-primary/5 p-3 space-y-2">
        <!-- Break buttons -->
        <div id="sb-break-btns" class="<?= $_sb_state === 'on_break' ? 'hidden' : '' ?> grid grid-cols-3 gap-1.5">
          <button onclick="awStartBreak('lunch')" id="sb-btn-lunch"
            class="flex flex-col items-center justify-center py-3 text-[11px] font-bold bg-blue-50 text-blue-700 rounded-lg hover:bg-blue-100 transition-colors gap-0.5">
            <span class="text-base leading-none">🍽</span>Lunch
          </button>
          <button onclick="awStartBreak('short')" id="sb-btn-short"
            class="flex flex-col items-center justify-center py-3 text-[11px] font-bold bg-purple-50 text-purple-700 rounded-lg hover:bg-purple-100 transition-colors gap-0.5">
            <span class="text-base leading-none">☕</span>Short
          </button>
          <button onclick="awStartBreak('washroom')"
            class="flex flex-col items-center justify-center py-3 text-[11px] font-bold bg-gray-100 text-gray-600 rounded-lg hover:bg-gray-200 transition-colors gap-0.5">
            <span class="text-base leading-none">🚻</span>WC
          </button>
        </div>
        <!-- End break -->
        <div id="sb-end-break" class="<?= $_sb_state === 'on_break' ? '' : 'hidden' ?> text-center space-y-2">
          <div class="text-amber-800 font-bold bg-amber-100/60 rounded-lg py-2 border border-amber-200 shadow-inner flex flex-col items-center justify-center">
            <span class="text-[10px] uppercase tracking-wider opacity-70 mb-0.5">Break Duration</span>
            <span id="sb-break-timer" class="font-mono tracking-widest text-base">00:00</span>
          </div>
          <button onclick="awEndBreak()" class="w-full py-3 text-xs font-bold bg-green-100 text-green-800 rounded-lg hover:bg-green-200 transition-colors">
            ✓ End Break &amp; Resume
          </button>
        </div>
        <!-- Clock out -->
        <button onclick="awClockOut()" class="w-full py-2.5 text-xs font-bold bg-red-50 text-red-600 rounded-lg hover:bg-red-100 transition-colors flex items-center justify-center gap-1.5">
          <span class="material-symbols-outlined text-base">logout</span> Clock Out
        </button>
      </div>
    </div>

    <!-- User info -->
    <div class="flex items-center gap-3">
      <div class="w-8 h-8 rounded-full bg-primary/20 flex items-center justify-center text-primary font-bold text-xs shrink-0">
        <?= strtoupper(substr($_SESSION['user_name'] ?? 'A', 0, 1)) ?>
      </div>
      <div class="flex-1 min-w-0">
        <p class="text-xs font-bold text-on-surface truncate"><?= htmlspecialchars($_SESSION['user_name'] ?? 'Agent') ?></p>
        <p class="text-[10px] text-on-surface-variant capitalize"><?= htmlspecialchars($_SESSION['role'] ?? 'agent') ?></p>
      </div>
    </div>
    <a href="/logout" class="flex items-center gap-2 text-xs text-on-surface-variant hover:text-red-600 font-semibold transition-colors">
      <span class="material-symbols-outlined text-sm">logout</span> Sign Out
    </a>
  </div>
</aside>

<script>
// ── Sidebar attendance mini-widget ──────────────────────────────────────
(function() {
  const att   = document.getElementById('sb-att');
  if (!att) return;

  let clockInTime    = <?= $_sb_clockIn ? 'new Date("' . str_replace(' ', 'T', $_sb_clockIn) . '")' : 'null' ?>;
  let breakStartTime = null;
  let currentState   = <?= json_encode($_sb_state) ?>;

  function setState(state, breakStart) {
    currentState   = state;
    breakStartTime = breakStart ? new Date(breakStart.replace(' ','T')) : null;

    const label   = document.getElementById('sb-state-label');
    const breakBts= document.getElementById('sb-break-btns');
    const endBrk  = document.getElementById('sb-end-break');

    if (state === 'not_clocked_in' || state === 'clocked_out') {
      att.classList.add('hidden');
      return;
    }
    att.classList.remove('hidden');

    if (state === 'on_break') {
      label.className   = 'font-bold text-amber-600';
      label.textContent = '☕ On Break';
      breakBts.classList.add('hidden');
      endBrk.classList.remove('hidden');
    } else {
      label.className   = 'font-bold text-green-600';
      label.textContent = '● Active';
      breakBts.classList.remove('hidden');
      endBrk.classList.add('hidden');
    }
  }

  // Timer tick
  setInterval(function() {
    const timer = document.getElementById('sb-timer');
    if (timer && clockInTime) {
      const diff = Math.floor((Date.now() - clockInTime.getTime()) / 1000);
      const h  = Math.floor(diff / 3600);
      const m  = Math.floor((diff % 3600) / 60);
      const s  = diff % 60;
      timer.textContent = String(h).padStart(2,'0') + ':' + String(m).padStart(2,'0') + ':' + String(s).padStart(2,'0');
    }

    if (currentState === 'on_break' && breakStartTime) {
      const breakTimer = document.getElementById('sb-break-timer');
      if (breakTimer) {
        const bd = Math.floor((Date.now() - breakStartTime.getTime()) / 1000);
        const bm = Math.floor(bd / 60);
        const bs = bd % 60;
        breakTimer.textContent = String(bm).padStart(2,'0') + ':' + String(bs).padStart(2,'0');
      }
    }
  }, 1000);

  // Poll
  async function poll() {
    try {
      const r = await fetch('/attendance/status');
      const d = await r.json();
      if (d.clock_in) clockInTime = new Date(d.clock_in.replace(' ','T'));
      setState(d.state, d.break_start || null);
      if (d.breaks_remaining) {
        const lBtn = document.getElementById('sb-btn-lunch');
        const sBtn = document.getElementById('sb-btn-short');
        if (lBtn) { lBtn.disabled = d.breaks_remaining.lunch <= 0; lBtn.style.opacity = d.breaks_remaining.lunch <= 0 ? '0.4' : '1'; }
        if (sBtn) { sBtn.disabled = d.breaks_remaining.short <= 0; sBtn.style.opacity = d.breaks_remaining.short <= 0 ? '0.4' : '1'; }
      }
    } catch(e) {}
  }

  // Expose same global functions used by the old widget so any other js still works
  window.awStartBreak = async function(type) {
    const r = await fetch('/break/start', {
      method: 'POST', 
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': '<?= $_SESSION['csrf_token'] ?? '' ?>'
      }, 
      body: JSON.stringify({type})
    });
    const d = await r.json();
    if (!d.success) alert(d.message);
    poll();
  };
  window.awEndBreak = async function() {
    const r = await fetch('/break/end', {
      method: 'POST', 
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': '<?= $_SESSION['csrf_token'] ?? '' ?>'
      }, 
      body: '{}'
    });
    const d = await r.json();
    if (d.flagged) alert('⚠️ Your break usage has been flagged for review.');
    poll();
  };
  window.awClockOut = async function() {
    if (!confirm('Are you sure you want to clock out?')) return;
    const r = await fetch('/clock-out', {
      method: 'POST', 
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': '<?= $_SESSION['csrf_token'] ?? '' ?>'
      }, 
      body: '{}'
    });
    const d = await r.json();
    if (d.success) window.location.href = '/clock-in';
    else alert(d.message);
  };

  poll();
  setInterval(poll, 30000);
})();
</script>
