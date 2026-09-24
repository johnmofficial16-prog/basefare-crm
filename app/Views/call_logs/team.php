<?php
/**
 * Call Logs — team view for admins, managers and supervisors.
 *
 * @var array  $board      per-agent rows (CallLogService::board), problems first
 * @var \Illuminate\Support\Collection $logs
 * @var \Illuminate\Support\Collection $agents
 * @var array  $filters    agent, reason, outcome, phone
 * @var string $viewDate
 * @var string $today
 * @var bool   $isOffDay
 * @var array  $byReason, $byOutcome, $byAirline
 * @var array  $settings   admin only
 * @var string $userRole
 * @var bool   $canExport
 * @var \App\Services\CallLogService $svc
 * @var string $csrf
 */
use App\Models\CallLog;
use Carbon\Carbon;
$activePage = 'call_logs';
$isToday   = $viewDate === $today;
$isAdmin   = $userRole === 'admin';

$totalCalls  = array_sum(array_column($board, 'calls'));
$totalBooked = array_sum(array_column($board, 'booked'));
$clockedIn   = count(array_filter($board, fn ($r) => $r['clocked_in'] !== null));
$logging     = count(array_filter($board, fn ($r) => $r['calls'] > 0));
$attention   = count(array_filter($board, fn ($r) => in_array($r['status'], ['no_logs', 'quiet'], true)));

$STATUS = [
    'no_logs'    => ['Not logging',     'bg-rose-100 text-rose-700'],
    'quiet'      => ['Gone quiet',      'bg-amber-100 text-amber-800'],
    'warming_up' => ['Just clocked in', 'bg-sky-100 text-sky-700'],
    'logging'    => ['Logging',         'bg-emerald-100 text-emerald-700'],
    'not_in'     => ['Not clocked in',  'bg-slate-100 text-slate-500'],
    'off_day'    => ['Off day',         'bg-slate-100 text-slate-500'],
];
$OUTCLS = ['emerald' => 'bg-emerald-50 text-emerald-700', 'amber' => 'bg-amber-50 text-amber-700', 'blue' => 'bg-blue-50 text-blue-700', 'slate' => 'bg-slate-100 text-slate-600', 'rose' => 'bg-rose-50 text-rose-700'];

$qs = function (array $over = []) use ($viewDate, $filters) {
    return http_build_query(array_filter(array_merge(['date' => $viewDate], $filters, $over), fn ($v) => $v !== '' && $v !== 0 && $v !== null));
};

$bars = function (array $data, callable $labelFn, string $colour) {
    if (!$data) {
        echo '<p class="text-sm text-slate-400">No calls yet.</p>';
        return;
    }
    $max = max($data);
    foreach ($data as $k => $n) {
        $w = max(3, (int) round($n / $max * 100));
        echo '<div class="flex items-center gap-2 text-sm mb-1.5"><span class="w-36 truncate text-slate-600">' . htmlspecialchars($labelFn($k)) . '</span>'
           . '<span class="flex-1 h-2.5 rounded-full bg-slate-100 overflow-hidden"><span class="block h-full rounded-full ' . $colour . '" style="width:' . $w . '%"></span></span>'
           . '<span class="w-8 text-right font-bold text-slate-700">' . (int) $n . '</span></div>';
    }
};
$DAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<title>Call Logs — Team - Base Fare CRM</title>
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

<main class="ml-60 pt-6 pb-20 px-8 max-w-[1500px]">

  <!-- Header -->
  <div class="flex flex-wrap items-end justify-between gap-4 mb-5 pr-16">
    <div>
      <h1 class="text-2xl font-headline font-extrabold text-primary tracking-tight">
        <span class="material-symbols-outlined align-text-bottom">phone_in_talk</span> Call Logs — Team
      </h1>
      <p class="text-sm text-on-surface-variant mt-0.5">
        Shift of <?= Carbon::parse($viewDate)->format('l, j M Y') ?> (<?= Carbon::parse($viewDate)->format('j M') ?> 6 PM onwards)<?= $isToday ? ' · live' : '' ?>
      </p>
    </div>
    <div class="flex flex-wrap items-center gap-2 text-sm">
      <a href="/call-logs" class="px-3 py-2 rounded-lg border border-slate-200 bg-white font-semibold text-slate-600 hover:text-primary">My calls</a>
      <a href="/call-logs/team?date=<?= Carbon::parse($viewDate)->subDay()->toDateString() ?>" class="px-2 py-2 rounded-lg border border-slate-200 bg-white text-slate-600 hover:text-primary" title="Previous day"><span class="material-symbols-outlined text-[18px] align-[-4px]">chevron_left</span></a>
      <form method="get" class="inline">
        <input type="date" name="date" value="<?= htmlspecialchars($viewDate) ?>" max="<?= $today ?>" onchange="this.form.submit()" class="rounded-lg border border-slate-200 px-2 py-1.5 bg-white">
      </form>
      <?php if (!$isToday): ?>
      <a href="/call-logs/team?date=<?= Carbon::parse($viewDate)->addDay()->toDateString() ?>" class="px-2 py-2 rounded-lg border border-slate-200 bg-white text-slate-600 hover:text-primary" title="Next day"><span class="material-symbols-outlined text-[18px] align-[-4px]">chevron_right</span></a>
      <a href="/call-logs/team" class="px-3 py-2 rounded-lg bg-primary text-white font-semibold">Today</a>
      <?php endif; ?>
      <?php if ($canExport): ?>
      <button type="button" onclick="document.getElementById('cl-export').classList.toggle('hidden')" class="px-3 py-2 rounded-lg border border-slate-200 bg-white font-semibold text-slate-600 hover:text-primary">
        <span class="material-symbols-outlined text-[18px] align-[-4px]">download</span> Export
      </button>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($canExport): ?>
  <form id="cl-export" method="get" action="/call-logs/export" class="hidden mb-4 flex flex-wrap items-end gap-3 rounded-xl border border-slate-200 bg-white p-4 text-sm">
    <label class="flex flex-col gap-1 font-semibold text-slate-600">From <input type="date" name="from" value="<?= htmlspecialchars($viewDate) ?>" class="rounded-lg border border-slate-200 px-2 py-1.5"></label>
    <label class="flex flex-col gap-1 font-semibold text-slate-600">To <input type="date" name="to" value="<?= htmlspecialchars($viewDate) ?>" class="rounded-lg border border-slate-200 px-2 py-1.5"></label>
    <?php foreach (['agent', 'reason', 'outcome', 'phone'] as $f): if ($filters[$f]) : ?>
    <input type="hidden" name="<?= $f ?>" value="<?= htmlspecialchars((string) $filters[$f]) ?>">
    <?php endif; endforeach; ?>
    <button class="px-4 py-2 rounded-lg bg-primary text-white font-bold">Download CSV</button>
    <span class="text-xs text-slate-400">Uses the filters below · max 92 days</span>
  </form>
  <?php endif; ?>

  <?php if ($isOffDay): ?>
  <div class="mb-4 rounded-xl bg-sky-50 border border-sky-200 px-4 py-3 text-sm text-sky-900">
    <span class="material-symbols-outlined text-[18px] align-[-4px]">weekend</span>
    <?= Carbon::parse($viewDate)->format('l') ?> is an off day — no compliance alerts are sent for this shift.
  </div>
  <?php endif; ?>

  <!-- KPIs -->
  <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-5">
    <div class="bg-white rounded-xl border border-slate-200 p-4">
      <div class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Calls logged</div>
      <div class="text-3xl font-headline font-extrabold text-primary mt-1"><?= $totalCalls ?></div>
    </div>
    <div class="bg-white rounded-xl border border-slate-200 p-4">
      <div class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Booked on call</div>
      <div class="text-3xl font-headline font-extrabold text-emerald-600 mt-1"><?= $totalBooked ?>
        <span class="text-sm font-semibold text-slate-400"><?= $totalCalls ? round($totalBooked * 100 / $totalCalls) : 0 ?>%</span></div>
    </div>
    <div class="bg-white rounded-xl border border-slate-200 p-4">
      <div class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Agents logging</div>
      <div class="text-3xl font-headline font-extrabold text-slate-800 mt-1"><?= $logging ?>
        <span class="text-sm font-semibold text-slate-400">of <?= $clockedIn ?> clocked in</span></div>
    </div>
    <div class="rounded-xl border p-4 <?= $attention ? 'bg-rose-50 border-rose-200' : 'bg-white border-slate-200' ?>">
      <div class="text-[11px] font-bold uppercase tracking-wider <?= $attention ? 'text-rose-600' : 'text-slate-500' ?>">Need attention</div>
      <div class="text-3xl font-headline font-extrabold mt-1 <?= $attention ? 'text-rose-600' : 'text-slate-300' ?>"><?= $attention ?></div>
    </div>
  </div>

  <div class="grid grid-cols-1 xl:grid-cols-3 gap-5 mb-5">
    <!-- Board -->
    <section class="xl:col-span-2 bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
      <div class="px-5 py-3 border-b border-slate-100 flex items-center justify-between">
        <h2 class="font-headline font-extrabold text-slate-800">Agents</h2>
        <span class="text-xs text-slate-400">Flagged after <?= (int) $svc->config('call_log_grace_hours') ?>h clocked in with no calls, or <?= (int) $svc->config('call_log_quiet_hours') ?>h without a new one</span>
      </div>
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="bg-slate-50 text-left text-[11px] font-bold uppercase text-slate-500">
              <th class="px-5 py-2.5">Agent</th><th class="px-3 py-2.5">Status</th><th class="px-3 py-2.5">Clocked in</th>
              <th class="px-3 py-2.5 text-right">Calls</th><th class="px-3 py-2.5 text-right">Booked</th><th class="px-5 py-2.5">Last logged</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <?php if (!$board): ?>
            <tr><td colspan="6" class="px-5 py-8 text-center text-slate-400">No agents in your team.</td></tr>
            <?php endif; ?>
            <?php foreach ($board as $r): [$sl, $sc] = $STATUS[$r['status']] ?? [$r['status'], 'bg-slate-100']; ?>
            <tr class="hover:bg-slate-50/60">
              <td class="px-5 py-2.5 font-semibold text-slate-800">
                <a href="/call-logs/team?<?= $qs(['agent' => $r['id']]) ?>#log-list" class="hover:text-primary"><?= htmlspecialchars($r['name']) ?></a>
              </td>
              <td class="px-3 py-2.5"><span class="whitespace-nowrap text-xs font-bold px-2 py-0.5 rounded-full <?= $sc ?>"><?= $sl ?></span></td>
              <td class="px-3 py-2.5 text-slate-600">
                <?php if ($r['clocked_in']): ?>
                  <?= $r['clocked_in']->format('g:i A') ?> <span class="text-slate-400">· <?= $svc->minsText($r['in_mins']) ?><?= $r['clocked_now'] ? '' : ' (out)' ?></span>
                <?php else: ?><span class="text-slate-300">—</span><?php endif; ?>
              </td>
              <td class="px-3 py-2.5 text-right font-extrabold <?= $r['calls'] ? 'text-slate-800' : 'text-slate-300' ?>"><?= $r['calls'] ?></td>
              <td class="px-3 py-2.5 text-right font-bold <?= $r['booked'] ? 'text-emerald-600' : 'text-slate-300' ?>"><?= $r['booked'] ?></td>
              <td class="px-5 py-2.5 text-slate-500"><?= $r['last_at'] ? $r['last_at']->format('g:i A') . ' <span class="text-slate-400">· ' . $r['last_at']->diffForHumans(null, true) . ' ago</span>' : '<span class="text-slate-300">—</span>' ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>

    <!-- Breakdown -->
    <section class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5 space-y-5">
      <div>
        <h3 class="text-[11px] font-bold uppercase tracking-wider text-slate-500 mb-2">Why customers called</h3>
        <?php $bars($byReason, fn ($k) => CallLog::REASONS[$k][0] ?? $k, 'bg-primary'); ?>
      </div>
      <div>
        <h3 class="text-[11px] font-bold uppercase tracking-wider text-slate-500 mb-2">Outcomes</h3>
        <?php $bars($byOutcome, fn ($k) => CallLog::OUTCOMES[$k][0] ?? $k, 'bg-emerald-500'); ?>
      </div>
      <div>
        <h3 class="text-[11px] font-bold uppercase tracking-wider text-slate-500 mb-2">Top airlines</h3>
        <?php $bars($byAirline, fn ($k) => $k, 'bg-indigo-400'); ?>
      </div>
    </section>
  </div>

  <!-- Log list -->
  <section id="log-list" class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden mb-5">
    <form method="get" class="px-5 py-3 border-b border-slate-100 flex flex-wrap items-center gap-2 text-sm">
      <h2 class="font-headline font-extrabold text-slate-800 mr-2">Calls <span class="text-slate-400 font-semibold text-sm">· <?= $logs->count() ?><?= $logs->count() >= 300 ? '+' : '' ?></span></h2>
      <input type="hidden" name="date" value="<?= htmlspecialchars($viewDate) ?>">
      <select name="agent" class="rounded-lg border border-slate-200 px-2 py-1.5 bg-white" onchange="this.form.submit()">
        <option value="">All agents</option>
        <?php foreach ($agents as $a): ?>
        <option value="<?= $a->id ?>" <?= $filters['agent'] === (int) $a->id ? 'selected' : '' ?>><?= htmlspecialchars($a->name) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="reason" class="rounded-lg border border-slate-200 px-2 py-1.5 bg-white" onchange="this.form.submit()">
        <option value="">All reasons</option>
        <?php foreach (CallLog::REASONS as $k => [$l]): ?>
        <option value="<?= $k ?>" <?= $filters['reason'] === $k ? 'selected' : '' ?>><?= $l ?></option>
        <?php endforeach; ?>
      </select>
      <select name="outcome" class="rounded-lg border border-slate-200 px-2 py-1.5 bg-white" onchange="this.form.submit()">
        <option value="">All outcomes</option>
        <?php foreach (CallLog::OUTCOMES as $k => [$l]): ?>
        <option value="<?= $k ?>" <?= $filters['outcome'] === $k ? 'selected' : '' ?>><?= $l ?></option>
        <?php endforeach; ?>
      </select>
      <input type="search" name="phone" value="<?= htmlspecialchars($filters['phone']) ?>" placeholder="Phone number" class="rounded-lg border border-slate-200 px-2 py-1.5 w-40">
      <button class="px-3 py-1.5 rounded-lg bg-slate-100 font-semibold text-slate-700 hover:bg-slate-200">Filter</button>
      <?php if (array_filter($filters)): ?><a href="/call-logs/team?date=<?= $viewDate ?>#log-list" class="text-xs font-semibold text-slate-500 underline">Clear</a><?php endif; ?>
    </form>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="bg-slate-50 text-left text-[11px] font-bold uppercase text-slate-500">
            <th class="px-5 py-2.5">Time</th><th class="px-3 py-2.5">Agent</th><th class="px-3 py-2.5">Customer</th>
            <th class="px-3 py-2.5">Reason</th><th class="px-3 py-2.5">Airline</th><th class="px-3 py-2.5">Outcome</th><th class="px-3 py-2.5">Notes</th>
            <?php if ($isAdmin): ?><th class="px-3 py-2.5"></th><?php endif; ?>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <?php if ($logs->isEmpty()): ?>
          <tr><td colspan="8" class="px-5 py-10 text-center text-slate-400">No calls match.</td></tr>
          <?php endif; ?>
          <?php foreach ($logs as $l): ?>
          <tr class="align-top hover:bg-slate-50/60" data-row="<?= $l->id ?>">
            <td class="px-5 py-2.5 whitespace-nowrap text-slate-500">
              <span class="material-symbols-outlined text-[16px] align-[-3px] <?= $l->direction === 'outbound' ? 'text-violet-500' : 'text-sky-500' ?>"><?= $l->direction === 'outbound' ? 'call_made' : 'call_received' ?></span>
              <?= $l->created_at->format('g:i A') ?>
            </td>
            <td class="px-3 py-2.5 font-semibold text-slate-700 whitespace-nowrap"><?= htmlspecialchars($l->agent->name ?? '#' . $l->agent_id) ?></td>
            <td class="px-3 py-2.5 whitespace-nowrap">
              <div class="font-semibold text-slate-800"><?= htmlspecialchars($l->phone) ?></div>
              <?php if ($l->customer_name): ?><div class="text-xs text-slate-500"><?= htmlspecialchars($l->customer_name) ?></div><?php endif; ?>
            </td>
            <td class="px-3 py-2.5 whitespace-nowrap"><?= htmlspecialchars($l->reasonLabel()) ?></td>
            <td class="px-3 py-2.5 whitespace-nowrap"><?= htmlspecialchars($l->airline) ?></td>
            <td class="px-3 py-2.5 whitespace-nowrap">
              <span class="text-xs font-bold px-2 py-0.5 rounded-full <?= $OUTCLS[$l->outcomeColour()] ?? $OUTCLS['slate'] ?>"><?= htmlspecialchars($l->outcomeLabel()) ?></span>
              <?php if ($l->transaction_id): ?>
              <a href="/transactions/<?= $l->transaction_id ?>" class="ml-1 text-xs font-bold text-primary underline"><?= htmlspecialchars($l->transaction->pnr ?? '#' . $l->transaction_id) ?></a>
              <?php endif; ?>
              <?php if ($l->follow_up_at): ?>
              <div class="text-xs mt-0.5 <?= $l->follow_up_done ? 'text-emerald-600' : 'text-amber-700' ?>">⏰ <?= $l->follow_up_at->format('D g:i A') ?><?= $l->follow_up_done ? ' · done' : '' ?></div>
              <?php endif; ?>
            </td>
            <td class="px-3 py-2.5 text-slate-500 max-w-xs"><?= nl2br(htmlspecialchars((string) $l->notes)) ?></td>
            <?php if ($isAdmin): ?>
            <td class="px-3 py-2.5"><button type="button" data-del="<?= $l->id ?>" class="text-slate-300 hover:text-rose-600" title="Delete"><span class="material-symbols-outlined text-[18px]">delete</span></button></td>
            <?php endif; ?>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>

  <?php if ($isAdmin): ?>
  <!-- Admin settings -->
  <details class="bg-white rounded-2xl border border-slate-200 shadow-sm">
    <summary class="px-5 py-3 cursor-pointer font-headline font-extrabold text-slate-800">
      <span class="material-symbols-outlined text-[20px] align-[-4px]">tune</span> Alert settings
      <span class="ml-2 text-xs font-semibold text-slate-400">off days, thresholds, summary time</span>
    </summary>
    <form id="cl-settings" class="px-5 pb-5 pt-2 grid grid-cols-1 md:grid-cols-2 gap-5 text-sm">
      <div>
        <div class="cl-label">Off days (shift that starts on…)</div>
        <div class="flex flex-wrap gap-2">
          <?php $off = $svc->offDays(); foreach ($DAYS as $i => $d): ?>
          <label class="cl-chip !py-1.5"><input type="checkbox" name="off_days[]" value="<?= $i ?>" <?= in_array($i, $off, true) ? 'checked' : '' ?>> <?= $d ?></label>
          <?php endforeach; ?>
        </div>
        <p class="text-xs text-slate-400 mt-1.5">Saturday = the shift starting Sat 6 PM (US Saturday). No alerts on off days.</p>
      </div>
      <div class="grid grid-cols-2 gap-3">
        <label><span class="cl-label">Flag after (hours clocked in, 0 calls)</span>
          <input type="number" min="1" max="12" name="grace_hours" value="<?= (int) $settings['call_log_grace_hours'] ?>" class="cl-input"></label>
        <label><span class="cl-label">Flag if no new call for (hours)</span>
          <input type="number" min="1" max="12" name="quiet_hours" value="<?= (int) $settings['call_log_quiet_hours'] ?>" class="cl-input"></label>
        <label><span class="cl-label">Repeat the same alert after (hours)</span>
          <input type="number" min="1" max="24" name="realert_hours" value="<?= (int) $settings['call_log_realert_hours'] ?>" class="cl-input"></label>
        <label><span class="cl-label">Shift summary at (hour, IST)</span>
          <input type="number" min="0" max="23" name="summary_hour" value="<?= (int) $settings['call_log_summary_hour'] ?>" class="cl-input"></label>
      </div>
      <div class="md:col-span-2 flex items-center gap-3">
        <button class="px-5 py-2 rounded-lg bg-primary text-white font-bold">Save settings</button>
        <span id="cl-settings-msg" class="text-sm font-semibold"></span>
      </div>
    </form>
  </details>
  <?php endif; ?>
</main>

<script>
(function () {
  var CSRF = <?= json_encode($csrf) ?>;
  function post(url, body) {
    body.append('csrf_token', CSRF);
    return fetch(url, { method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': CSRF }, body: body.toString() })
      .then(function (r) { return r.json(); });
  }
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-del]');
    if (!b) return;
    if (!confirm('Delete this call log? This cannot be undone.')) return;
    var id = b.getAttribute('data-del');
    post('/call-logs/' + id + '/delete', new URLSearchParams()).then(function (d) {
      if (!d.success) { alert(d.error || 'Could not delete.'); return; }
      var tr = document.querySelector('[data-row="' + id + '"]');
      if (tr) tr.remove();
    }).catch(function () { alert('Network error.'); });
  });
  var sf = document.getElementById('cl-settings');
  if (sf) sf.addEventListener('submit', function (e) {
    e.preventDefault();
    var msg = document.getElementById('cl-settings-msg');
    post('/call-logs/settings', new URLSearchParams(new FormData(sf))).then(function (d) {
      msg.textContent = d.success ? 'Saved ✓' : (d.error || 'Could not save.');
      msg.className = 'text-sm font-semibold ' + (d.success ? 'text-emerald-600' : 'text-rose-600');
      if (d.success) setTimeout(function () { location.reload(); }, 600);
    }).catch(function () { msg.textContent = 'Network error.'; });
  });
  <?php if ($isToday): ?>
  // Live board: refresh every 2 minutes unless someone is typing in a filter.
  setInterval(function () {
    var a = document.activeElement;
    if (a && /INPUT|SELECT|TEXTAREA/.test(a.tagName)) return;
    if (document.querySelector('details[open]')) return;
    location.reload();
  }, 120000);
  <?php endif; ?>
})();
</script>
</body>
</html>
