<?php
// B5 FIX: Inject the current state server-side so the widget renders immediately
// without waiting for the first async poll (which causes a visible flash of 'hidden').
$_aw_state   = $stateInfo['state'] ?? ($_SESSION['att_state_' . ($_SESSION['user_id'] ?? 0)]['state'] ?? 'not_clocked_in');
$_aw_clockIn = $stateInfo['session']->clock_in ?? null;
?>
<div id="attWidget" class="fixed bottom-6 right-6 z-50 bg-white/90 backdrop-blur-xl rounded-2xl shadow-2xl shadow-blue-900/15 p-4 w-72 font-body text-on-surface transition-all"
     style="<?= in_array($_aw_state, ['clocked_in','on_break']) ? '' : 'display:none' ?>">
  <!-- Header -->
  <div class="flex items-center justify-between mb-3">
    <span class="text-xs font-label font-bold text-on-surface-variant uppercase tracking-wider">Attendance</span>
    <button onclick="document.getElementById('attWidget').classList.toggle('opacity-30')" class="text-on-surface-variant hover:text-primary text-xs">
      <span class="material-symbols-outlined text-sm">minimize</span>
    </button>
  </div>

  <!-- Elapsed Timer -->
  <div id="aw-timer" class="text-2xl font-headline font-extrabold text-primary mb-2">00:00:00</div>
  <div id="aw-status" class="text-xs font-semibold text-green-600 mb-3">● Working</div>

  <!-- Break Status (visible when on break) -->
  <div id="aw-break-bar" class="hidden mb-3 p-3 bg-amber-50 rounded-xl">
    <div class="flex items-center justify-between">
      <span id="aw-break-label" class="text-xs font-bold text-amber-700">ON BREAK</span>
      <span id="aw-break-timer" class="text-sm font-headline font-bold text-amber-800">00:00</span>
    </div>
  </div>

  <!-- P2 #23: Open-break warning (shown on re-login when a break was left open) -->
  <div id="aw-open-break-alert" class="hidden mb-3 p-3 bg-orange-50 border border-orange-200 rounded-xl">
    <p class="text-xs font-bold text-orange-700 flex items-center gap-1">
      <span class="material-symbols-outlined text-sm">warning</span>
      You have an open break from before. Please end it.
    </p>
  </div>

  <!-- Break Remaining Badges (P1 #6) -->
  <div id="aw-breaks-remaining" class="flex gap-2 mb-3 text-[10px] font-bold">
    <span id="aw-lunch-badge" class="px-2 py-1 bg-blue-50 text-blue-700 rounded">Lunch: 1</span>
    <span id="aw-short-badge" class="px-2 py-1 bg-purple-50 text-purple-700 rounded">Short: 2</span>
    <span class="px-2 py-1 bg-gray-100 text-gray-500 rounded">WC: ∞</span>
  </div>

  <!-- Quick Actions -->
  <div id="aw-actions" class="flex gap-2">
    <button onclick="awStartBreak('lunch')" id="aw-btn-lunch" class="flex-1 px-2 py-2 bg-blue-50 text-blue-700 rounded-lg text-xs font-bold hover:bg-blue-100 transition-all">🍽 Lunch</button>
    <button onclick="awStartBreak('short')" id="aw-btn-short" class="flex-1 px-2 py-2 bg-purple-50 text-purple-700 rounded-lg text-xs font-bold hover:bg-purple-100 transition-all">☕ Short</button>
    <button onclick="awStartBreak('washroom')" id="aw-btn-wc" class="flex-1 px-2 py-2 bg-gray-100 text-gray-600 rounded-lg text-xs font-bold hover:bg-gray-200 transition-all">🚻 WC</button>
  </div>
  <div id="aw-end-break" class="hidden mt-2">
    <button onclick="awEndBreak()" class="w-full px-4 py-2 bg-green-100 text-green-800 rounded-lg text-xs font-bold hover:bg-green-200 transition-all">✓ End Break</button>
  </div>

  <!-- Clock Out -->
  <button onclick="awClockOut()" class="mt-3 w-full px-4 py-2 bg-red-50 text-red-600 rounded-lg text-xs font-bold hover:bg-red-100 transition-all">
    <span class="material-symbols-outlined text-sm align-[-3px]">logout</span> Clock Out
  </button>
</div>

<script>
(function() {
  const widget = document.getElementById('attWidget');
  // B5 FIX: use PHP-injected initial state so the widget shows immediately
  let clockInTime = <?= $_aw_clockIn ? 'new Date("' . str_replace(' ', 'T', $_aw_clockIn) . '")' : 'null' ?>;
  let breakStartTime = null;
  let currentState = <?= json_encode($_aw_state) ?>;

  let isFirstPoll = true;

  async function pollStatus() {
    try {
      const r = await fetch('/attendance/status');
      const d = await r.json();

      if (d.state === 'not_clocked_in' || d.state === 'clocked_out') {
        widget.style.display = 'none';
        return;
      }

      widget.style.display = 'block';
      currentState = d.state;
      clockInTime = d.clock_in ? new Date(d.clock_in.replace(' ', 'T')) : null;

      // Update break remaining badges (P1 #6)
      if (d.breaks_remaining) {
        const lb = d.breaks_remaining.lunch;
        const sb = d.breaks_remaining.short;
        document.getElementById('aw-lunch-badge').textContent = 'Lunch: ' + lb;
        document.getElementById('aw-short-badge').textContent = 'Short: ' + sb;
        document.getElementById('aw-btn-lunch').disabled = (lb <= 0);
        document.getElementById('aw-btn-short').disabled = (sb <= 0);
        if (lb <= 0) document.getElementById('aw-btn-lunch').classList.add('opacity-40');
        if (sb <= 0) document.getElementById('aw-btn-short').classList.add('opacity-40');
      }

      if (d.state === 'on_break') {
        breakStartTime = d.break_start ? new Date(d.break_start.replace(' ', 'T')) : null;
        document.getElementById('aw-status').innerHTML = '<span class="text-amber-600">☕ On ' + (d.break_type ? d.break_type.charAt(0).toUpperCase() + d.break_type.slice(1) : '') + ' Break</span>';
        document.getElementById('aw-break-bar').classList.remove('hidden');
        document.getElementById('aw-break-label').textContent = (d.break_type || 'break').toUpperCase() + ' BREAK';
        document.getElementById('aw-actions').classList.add('hidden');
        document.getElementById('aw-end-break').classList.remove('hidden');

        // P2 #23: Open-break warning on re-login
        if (isFirstPoll) {
          const alertEl = document.getElementById('aw-open-break-alert');
          if (alertEl) alertEl.classList.remove('hidden');
        }
      } else {
        breakStartTime = null;
        document.getElementById('aw-status').innerHTML = '<span class="text-green-600">● Working</span>';
        document.getElementById('aw-break-bar').classList.add('hidden');
        document.getElementById('aw-actions').classList.remove('hidden');
        document.getElementById('aw-end-break').classList.add('hidden');
        const alertEl = document.getElementById('aw-open-break-alert');
        if (alertEl) alertEl.classList.add('hidden');
      }

      isFirstPoll = false;
    } catch(e) {
      console.error('Widget poll error:', e);
    }
  }

  function updateTimers() {
    if (clockInTime) {
      const diff = Math.floor((Date.now() - clockInTime.getTime()) / 1000);
      const h = Math.floor(diff / 3600);
      const m = Math.floor((diff % 3600) / 60);
      const s = diff % 60;
      document.getElementById('aw-timer').textContent =
        String(h).padStart(2,'0') + ':' + String(m).padStart(2,'0') + ':' + String(s).padStart(2,'0');
    }
    if (breakStartTime) {
      const bd = Math.floor((Date.now() - breakStartTime.getTime()) / 1000);
      const bm = Math.floor(bd / 60);
      const bs = bd % 60;
      document.getElementById('aw-break-timer').textContent =
        String(bm).padStart(2,'0') + ':' + String(bs).padStart(2,'0');
    }
  }

  window.awStartBreak = async function(type) {
    const r = await fetch('/break/start', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({type})});
    const d = await r.json();
    if (!d.success) alert(d.message);
    pollStatus();
  };

  window.awEndBreak = async function() {
    const r = await fetch('/break/end', {method:'POST', headers:{'Content-Type':'application/json'}, body:'{}'});
    const d = await r.json();
    if (d.flagged) alert('⚠️ Your break usage has been flagged for review.');
    pollStatus();
  };

  window.awClockOut = async function() {
    if (!confirm('Are you sure you want to clock out? This cannot be undone.')) return;
    const r = await fetch('/clock-out', {method:'POST', headers:{'Content-Type':'application/json'}, body:'{}'});
    const d = await r.json();
    if (d.success) {
      window.location.href = '/clock-in';
    } else {
      alert(d.message);
    }
  };

  // Initial poll + intervals
  pollStatus();
  setInterval(pollStatus, 30000);
  setInterval(updateTimers, 1000);
})();
</script>
