<?php
/**
 * Call Logs — the agent's own page: quick log form, this shift's calls,
 * call-backs and a 7-day trend.
 *
 * @var array  $stats      calls, booked, conversion, follow_ups, streak
 * @var array  $logs       presented logs for $viewDate
 * @var array  $followUps  presented open call-backs
 * @var array  $week       [{date, n}] last 7 working days
 * @var string $viewDate
 * @var string $today
 * @var bool   $isOffDay
 * @var array  $favAirlines
 * @var array  $bookings
 * @var string $csrf
 */
use Carbon\Carbon;
$activePage = 'call_logs';
$isToday    = $viewDate === $today;
$maxWeek    = max(1, max(array_column($week, 'n')));
$firstName  = explode(' ', trim($_SESSION['user_name'] ?? 'there'))[0];
$isLead     = in_array($_SESSION['role'] ?? '', ['admin', 'manager', 'supervisor'], true);
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<title>Call Logs - Base Fare CRM</title>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
<link href="<?= \App\Services\Asset::url('assets/css/call-log.css') ?>" rel="stylesheet"/>
<script src="<?= \App\Services\Asset::url('assets/js/error-beacon.js') ?>"></script>
<script src="/assets/js/tailwind.js"></script>
<script src="<?= \App\Services\Asset::url('assets/js/buddy-widget.js') ?>" defer></script>
<script>
tailwind.config={darkMode:"class",theme:{extend:{colors:{primary:"#163274","primary-container":"#314a8d",background:"#f8f9fa",surface:"#f8f9fa","surface-container":"#edeeef","on-surface":"#191c1d","on-surface-variant":"#434653"},fontFamily:{headline:["Manrope"],body:["Inter"]}}}}
</script>
</head>
<body class="bg-background font-body text-on-surface antialiased min-h-screen">

<?php require __DIR__ . '/../layout/sidebar.php'; ?>

<main class="ml-60 pt-6 pb-20 px-8 max-w-[1400px]">

  <!-- Header -->
  <div class="flex flex-wrap items-end justify-between gap-4 mb-5 pr-16">
    <div>
      <h1 class="text-2xl font-headline font-extrabold text-primary tracking-tight">
        <span class="material-symbols-outlined align-text-bottom">phone_in_talk</span> Call Logs
      </h1>
      <p class="text-sm text-on-surface-variant mt-0.5">
        <?= $isToday ? 'Hi ' . htmlspecialchars($firstName) . ' — number, reason, airline, outcome. About 10 seconds a call.' : 'Viewing ' . Carbon::parse($viewDate)->format('l, j M') . ' (read-only).' ?>
      </p>
    </div>
    <form method="get" class="flex items-center gap-2 text-sm">
      <?php if ($isLead): ?>
      <a href="/call-logs/team" class="px-3 py-2 rounded-lg border border-slate-200 bg-white font-semibold text-slate-600 hover:text-primary">Team view</a>
      <?php endif; ?>
      <?php if (!$isToday): ?>
      <a href="/call-logs" class="px-3 py-2 rounded-lg bg-primary text-white font-semibold">Back to today</a>
      <?php endif; ?>
      <label class="text-slate-500 font-semibold">Shift day</label>
      <input type="date" name="date" value="<?= htmlspecialchars($viewDate) ?>" max="<?= $today ?>" onchange="this.form.submit()"
             class="rounded-lg border border-slate-200 px-2 py-1.5 bg-white">
    </form>
  </div>

  <?php if ($isOffDay && $isToday): ?>
  <div class="mb-4 rounded-xl bg-sky-50 border border-sky-200 px-4 py-3 text-sm text-sky-900">
    <span class="material-symbols-outlined text-[18px] align-[-4px]">weekend</span>
    Weekend shift — nobody gets flagged today. If you're taking calls anyway, logging them still counts toward your streak.
  </div>
  <?php endif; ?>

  <!-- Stats -->
  <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-5" id="cl-stats">
    <div class="bg-white rounded-xl border border-slate-200 p-4">
      <div class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Calls this shift</div>
      <div class="text-3xl font-headline font-extrabold text-primary mt-1" data-stat="calls"><?= (int) $stats['calls'] ?></div>
    </div>
    <div class="bg-white rounded-xl border border-slate-200 p-4">
      <div class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Booked</div>
      <div class="text-3xl font-headline font-extrabold text-emerald-600 mt-1"><span data-stat="booked"><?= (int) $stats['booked'] ?></span>
        <span class="text-sm font-semibold text-slate-400" ><span data-stat="conversion"><?= (int) $stats['conversion'] ?></span>% of calls</span></div>
    </div>
    <div class="bg-white rounded-xl border border-slate-200 p-4">
      <div class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Call-backs due</div>
      <div class="text-3xl font-headline font-extrabold mt-1 <?= $stats['follow_ups'] ? 'text-amber-600' : 'text-slate-300' ?>" data-stat="follow_ups"><?= (int) $stats['follow_ups'] ?></div>
    </div>
    <div class="bg-gradient-to-br from-orange-50 to-amber-50 rounded-xl border border-amber-200 p-4">
      <div class="text-[11px] font-bold uppercase tracking-wider text-amber-700">Logging streak</div>
      <div class="text-3xl font-headline font-extrabold text-amber-600 mt-1">🔥 <span data-stat="streak"><?= (int) $stats['streak'] ?></span>
        <span class="text-sm font-semibold text-amber-700/70">working day<?= $stats['streak'] == 1 ? '' : 's' ?></span></div>
    </div>
  </div>

  <div class="grid grid-cols-1 xl:grid-cols-3 gap-5">

    <!-- Left: form + list -->
    <div class="xl:col-span-2 space-y-5">
      <?php if ($isToday): ?>
      <section id="cl-form-card" class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5">
        <?php $clFavAirlines = $favAirlines; $clBookings = $bookings; require __DIR__ . '/_form.php'; ?>
      </section>
      <?php endif; ?>

      <section class="bg-white rounded-2xl border border-slate-200 shadow-sm">
        <div class="flex items-center justify-between px-5 py-3 border-b border-slate-100">
          <h2 class="font-headline font-extrabold text-slate-800"><?= $isToday ? 'This shift' : Carbon::parse($viewDate)->format('D j M') ?>
            <span class="ml-1 text-sm font-semibold text-slate-400" id="cl-count"></span></h2>
          <input id="cl-search" type="search" placeholder="Search number, name, airline…" class="cl-input !w-64 !py-1.5 text-sm">
        </div>
        <ul id="cl-list" class="divide-y divide-slate-100"></ul>
        <div id="cl-empty" class="hidden px-5 py-10 text-center text-sm text-slate-400">
          <span class="material-symbols-outlined text-4xl text-slate-300">call</span><br>
          <?= $isToday ? 'No calls logged yet this shift. Your first one is a tap away ☝️' : 'No calls logged on this day.' ?>
        </div>
      </section>
    </div>

    <!-- Right: call-backs + trend -->
    <div class="space-y-5">
      <section id="followups" class="bg-white rounded-2xl border border-slate-200 shadow-sm">
        <div class="px-5 py-3 border-b border-slate-100 flex items-center justify-between">
          <h2 class="font-headline font-extrabold text-slate-800"><span class="material-symbols-outlined text-[20px] align-[-4px] text-amber-500">schedule</span> Call-backs</h2>
          <span class="text-xs text-slate-400">bell rings when due</span>
        </div>
        <ul id="cl-fu-list" class="divide-y divide-slate-100"></ul>
        <div id="cl-fu-empty" class="hidden px-5 py-6 text-center text-sm text-slate-400">Nothing pending. Pick <b>Call back later</b> on a call and we'll remind you.</div>
      </section>

      <section class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5">
        <h2 class="font-headline font-extrabold text-slate-800 mb-3">Last 7 working days</h2>
        <div class="flex items-end gap-2 h-28">
          <?php foreach ($week as $w): $h = (int) round($w['n'] / $maxWeek * 100); ?>
          <a href="/call-logs?date=<?= $w['date'] ?>" class="flex-1 flex flex-col items-center justify-end h-full group" title="<?= $w['n'] ?> calls">
            <span class="text-[11px] font-bold text-slate-500 mb-1"><?= $w['n'] ?></span>
            <span class="w-full rounded-t-md <?= $w['date'] === $viewDate ? 'bg-primary' : 'bg-primary/25 group-hover:bg-primary/50' ?>" style="height: <?= max(4, $h) ?>%"></span>
            <span class="text-[10px] font-semibold text-slate-400 mt-1"><?= Carbon::parse($w['date'])->format('D') ?></span>
          </a>
          <?php endforeach; ?>
        </div>
      </section>

      <section class="rounded-2xl border border-dashed border-slate-300 p-4 text-[13px] text-slate-500 leading-relaxed">
        <b class="text-slate-700">Tips</b><br>
        • <kbd class="cl-kbd">Alt</kbd> + <kbd class="cl-kbd">L</kbd> opens the quick log from any page.<br>
        • Type the number first — if they've called before, you'll see their history.<br>
        • Pick <b>Call back later</b> and your bell reminds you when it's time.
      </section>
    </div>
  </div>
</main>

<script src="<?= \App\Services\Asset::url('assets/js/call-log.js') ?>"></script>
<script>
(function () {
  var CSRF     = <?= json_encode($csrf) ?>;
  var EDITABLE = <?= $isToday ? 'true' : 'false' ?>;
  var logs     = <?= json_encode($logs) ?>;
  var fus      = <?= json_encode($followUps) ?>;
  var esc      = CallLog.esc;
  var list     = document.getElementById('cl-list');
  var search   = document.getElementById('cl-search');
  var OUT = { emerald: 'bg-emerald-50 text-emerald-700 ring-emerald-200', amber: 'bg-amber-50 text-amber-700 ring-amber-200',
              blue: 'bg-blue-50 text-blue-700 ring-blue-200', slate: 'bg-slate-100 text-slate-600 ring-slate-200', rose: 'bg-rose-50 text-rose-700 ring-rose-200' };

  function row(l) {
    return '<li class="px-5 py-3 flex items-start gap-3 hover:bg-slate-50/70" data-id="' + l.id + '">' +
      '<div class="w-14 shrink-0 text-xs font-bold text-slate-400 pt-0.5">' + esc(l.time) + '</div>' +
      '<span class="material-symbols-outlined text-[20px] ' + (l.direction === 'outbound' ? 'text-violet-500' : 'text-sky-500') + '" title="' + (l.direction === 'outbound' ? 'Outgoing' : 'Incoming') + '">' +
        (l.direction === 'outbound' ? 'call_made' : 'call_received') + '</span>' +
      '<div class="flex-1 min-w-0">' +
        '<div class="flex flex-wrap items-center gap-x-2 gap-y-1">' +
          '<span class="font-bold text-slate-800 tracking-wide">' + esc(l.phone) + '</span>' +
          (l.customer_name ? '<span class="text-sm text-slate-500">' + esc(l.customer_name) + '</span>' : '') +
          '<span class="text-xs font-semibold px-2 py-0.5 rounded-full bg-slate-100 text-slate-700"><span class="material-symbols-outlined text-[14px] align-[-2px]">' + esc(l.reason_icon) + '</span> ' + esc(l.reason_label) + '</span>' +
          '<span class="text-xs font-semibold px-2 py-0.5 rounded-full bg-indigo-50 text-indigo-700">✈ ' + esc(l.airline) + '</span>' +
          '<span class="text-xs font-bold px-2 py-0.5 rounded-full ring-1 ' + (OUT[l.outcome_colour] || OUT.slate) + '">' + esc(l.outcome_label) + '</span>' +
          (l.pnr ? '<a href="/transactions/' + l.transaction_id + '" class="text-xs font-bold text-primary underline">' + esc(l.pnr) + '</a>' : '') +
          (l.follow_up_label ? '<span class="text-xs text-amber-700">⏰ ' + esc(l.follow_up_label) + '</span>' : '') +
        '</div>' +
        (l.notes ? '<div class="text-[13px] text-slate-500 mt-1 whitespace-pre-line">' + esc(l.notes) + '</div>' : '') +
      '</div>' +
      (EDITABLE ? '<button type="button" data-edit="' + l.id + '" class="text-slate-400 hover:text-primary" title="Edit"><span class="material-symbols-outlined text-[20px]">edit</span></button>' : '') +
    '</li>';
  }
  function renderList() {
    var q = (search.value || '').toLowerCase().trim();
    var qd = q.replace(/\D+/g, '');
    var shown = logs.filter(function (l) {
      if (!q) return true;
      if (qd.length >= 3 && String(l.phone).replace(/\D+/g, '').indexOf(qd) !== -1) return true;
      return [l.customer_name, l.airline, l.reason_label, l.outcome_label, l.notes, l.pnr].join(' ').toLowerCase().indexOf(q) !== -1;
    });
    list.innerHTML = shown.map(row).join('');
    document.getElementById('cl-empty').classList.toggle('hidden', logs.length > 0);
    document.getElementById('cl-count').textContent = logs.length ? '· ' + logs.length + ' call' + (logs.length === 1 ? '' : 's') : '';
  }

  function fuRow(l) {
    var due = l.follow_up_ts <= Date.now();
    return '<li class="px-5 py-3 flex items-start gap-3 ' + (due ? 'bg-amber-50/70' : '') + '" data-fu="' + l.id + '">' +
      '<div class="flex-1 min-w-0">' +
        '<div class="text-xs font-bold ' + (due ? 'text-rose-600' : 'text-amber-700') + '">' + (due ? 'Due · ' : '') + esc(l.follow_up_label) + '</div>' +
        '<div class="font-bold text-slate-800 tracking-wide">' + esc(l.phone) + (l.customer_name ? ' <span class="font-normal text-slate-500 text-sm">' + esc(l.customer_name) + '</span>' : '') + '</div>' +
        '<div class="text-xs text-slate-500">' + esc(l.reason_label) + ' · ' + esc(l.airline) + (l.notes ? ' · ' + esc(l.notes.slice(0, 80)) : '') + '</div>' +
      '</div>' +
      '<button type="button" data-copy="' + esc(l.phone) + '" title="Copy number" class="text-slate-400 hover:text-primary"><span class="material-symbols-outlined text-[20px]">content_copy</span></button>' +
      '<button type="button" data-done="' + l.id + '" class="text-xs font-bold px-2.5 py-1.5 rounded-lg bg-emerald-50 text-emerald-700 hover:bg-emerald-100">Done ✓</button>' +
    '</li>';
  }
  function renderFus() {
    fus.sort(function (a, b) { return a.follow_up_ts - b.follow_up_ts; });
    document.getElementById('cl-fu-list').innerHTML = fus.map(fuRow).join('');
    document.getElementById('cl-fu-empty').classList.toggle('hidden', fus.length > 0);
  }
  function setStats(s) {
    Object.keys(s).forEach(function (k) {
      var el = document.querySelector('[data-stat="' + k + '"]');
      if (el) el.textContent = s[k];
    });
  }

  var formApi = null;
  var root = document.querySelector('#cl-form-card [data-cl-root]');
  if (root) {
    formApi = CallLog.mount(root, {
      csrf: CSRF,
      bookings: <?= json_encode($bookings) ?>,
      onSaved: function (log, stats, wasEdit) {
        if (wasEdit) {
          logs = logs.map(function (l) { return l.id === log.id ? log : l; });
        } else {
          logs.unshift(log);
        }
        fus = fus.filter(function (f) { return f.id !== log.id; });
        if (log.outcome === 'follow_up' && log.follow_up_ts && !log.follow_up_done) fus.push(log);
        renderList(); renderFus(); setStats(stats);
        var sb = document.getElementById('clq-count');
        if (sb) sb.textContent = stats.calls;
        var li = list.querySelector('[data-id="' + log.id + '"]');
        if (li) { li.classList.add('bg-emerald-50'); setTimeout(function () { li.classList.remove('bg-emerald-50'); }, 1500); }
      }
    });
  }

  list.addEventListener('click', function (e) {
    var b = e.target.closest('[data-edit]');
    if (!b || !formApi) return;
    var id = parseInt(b.getAttribute('data-edit'), 10);
    var l = logs.filter(function (x) { return x.id === id; })[0];
    if (l) formApi.load(l);
  });
  document.getElementById('cl-fu-list').addEventListener('click', function (e) {
    var c = e.target.closest('[data-copy]');
    if (c) {
      navigator.clipboard && navigator.clipboard.writeText(c.getAttribute('data-copy'));
      CallLog.toast('Number copied');
      return;
    }
    var d = e.target.closest('[data-done]');
    if (!d) return;
    var id = parseInt(d.getAttribute('data-done'), 10);
    fetch('/call-logs/' + id + '/follow-up-done', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': CSRF },
      body: 'csrf_token=' + encodeURIComponent(CSRF)
    }).then(function (r) { return r.json(); }).then(function (res) {
      if (!res.success) { CallLog.toast(res.error || 'Could not update', 'err'); return; }
      fus = fus.filter(function (f) { return f.id !== id; });
      renderFus(); setStats(res.stats);
      CallLog.toast('Call-back done ✓');
    }).catch(function () { CallLog.toast('Network error', 'err'); });
  });
  search.addEventListener('input', renderList);

  renderList(); renderFus();
  if (location.hash === '#followups') document.getElementById('followups').scrollIntoView();
  // Due badges tick over without a reload.
  setInterval(renderFus, 60000);
})();
</script>
</body>
</html>
