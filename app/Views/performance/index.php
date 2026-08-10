<?php
/**
 * Agent Performance / Scores
 *
 * @var array  $rows         Per-agent score rows (profit-sorted) from PerformanceController
 * @var array  $totals       Team rollups
 * @var string $period       'daily' | 'monthly' | 'alltime' | 'custom'
 * @var string $periodLabel  Human label for the active window
 * @var string $currency     Default display currency
 * @var string $exportQuery  Querystring to carry the current filter into the export
 * @var string $selMonth     YYYY-MM for the month picker
 * @var string $selFrom      YYYY-MM-DD custom range start
 * @var string $selTo        YYYY-MM-DD custom range end
 */
$money = fn($n) => number_format((float)$n, 0);
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
<meta charset="utf-8"/>
<meta content="width=device-width,initial-scale=1.0" name="viewport"/>
<title>Performance — Base Fare CRM</title>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
<script src="https://cdn.tailwindcss.com?plugins=forms"></script>
<script>
tailwind.config={darkMode:"class",theme:{extend:{colors:{primary:"#163274","primary-container":"#314a8d",background:"#f8f9fa","surface-container-low":"#f3f4f5","on-surface":"#191c1d","on-surface-variant":"#434653"},fontFamily:{headline:["Manrope"],body:["Inter"],label:["Inter"]}}}};
</script>
<style>.material-symbols-outlined{font-variation-settings:'FILL' 0,'wght' 400,'GRAD' 0,'opsz' 24}</style>
</head>
<body class="bg-background font-body text-on-surface antialiased min-h-screen">

<?php $activePage='performance'; require __DIR__.'/../layout/sidebar.php'; ?>

<main class="ml-60 pt-8 pb-20 px-10">

  <?php require __DIR__ . '/../partials/performance_hold_notice.php'; ?>

  <!-- Header -->
  <div class="flex items-start justify-between mb-6 gap-4 flex-wrap">
    <div>
      <h1 class="text-3xl font-headline font-extrabold text-primary tracking-tight">Agent Performance</h1>
      <p class="text-sm text-on-surface-variant mt-1">
        Agent-wise scores &amp; analysis · <span class="font-semibold text-on-surface"><?= htmlspecialchars($periodLabel) ?></span>
      </p>
    </div>
    <a href="/performance/export<?= $exportQuery ? '?' . htmlspecialchars($exportQuery) : '' ?>"
       class="flex items-center gap-2 px-4 py-2.5 bg-emerald-600 text-white rounded-xl text-sm font-bold hover:bg-emerald-700 transition-all shadow-lg shadow-emerald-600/20 self-start">
      <span class="material-symbols-outlined text-base">download</span> Export CSV
    </a>
  </div>

  <!-- Filter bar -->
  <form method="GET" action="/performance" id="filterForm"
        class="bg-white rounded-2xl shadow-sm border border-slate-200 p-4 mb-6 flex flex-wrap items-end gap-4">
    <div>
      <label class="block text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1.5">Period</label>
      <div class="flex gap-1 bg-slate-100 rounded-xl p-1">
        <?php foreach ([
            'daily'   => 'Today',
            'monthly' => 'Monthly',
            'alltime' => 'Till Date',
            'custom'  => 'Custom',
        ] as $val => $lbl): ?>
        <button type="button" data-period="<?= $val ?>"
                class="period-btn px-4 py-1.5 rounded-lg text-sm font-bold transition-all <?= $period===$val ? 'bg-primary text-white shadow' : 'text-on-surface-variant hover:text-primary' ?>">
          <?= $lbl ?>
        </button>
        <?php endforeach; ?>
      </div>
      <input type="hidden" name="period" id="periodInput" value="<?= htmlspecialchars($period) ?>"/>
    </div>

    <!-- Month picker (monthly) -->
    <div id="monthWrap" class="<?= $period==='monthly' ? '' : 'hidden' ?>">
      <label class="block text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1.5">Month</label>
      <input type="month" name="month" value="<?= htmlspecialchars($selMonth) ?>"
             class="px-3 py-2 rounded-xl bg-white border border-slate-200 font-semibold text-primary text-sm focus:ring-2 focus:ring-primary/20"/>
    </div>

    <!-- Custom range -->
    <div id="customWrap" class="<?= $period==='custom' ? 'flex' : 'hidden' ?> items-end gap-3">
      <div>
        <label class="block text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1.5">From</label>
        <input type="date" name="date_from" value="<?= htmlspecialchars($selFrom) ?>"
               class="px-3 py-2 rounded-xl bg-white border border-slate-200 font-semibold text-primary text-sm focus:ring-2 focus:ring-primary/20"/>
      </div>
      <div>
        <label class="block text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1.5">To</label>
        <input type="date" name="date_to" value="<?= htmlspecialchars($selTo) ?>"
               class="px-3 py-2 rounded-xl bg-white border border-slate-200 font-semibold text-primary text-sm focus:ring-2 focus:ring-primary/20"/>
      </div>
    </div>

    <button type="submit"
            class="px-5 py-2 bg-primary text-white rounded-xl text-sm font-bold hover:bg-primary-container transition-all">
      Apply
    </button>
  </form>

  <!-- Analysis cards -->
  <div class="grid grid-cols-2 lg:grid-cols-6 gap-4 mb-8">
    <div class="bg-white rounded-2xl p-4 shadow-sm border border-slate-200">
      <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-2">Agents</p>
      <p class="text-3xl font-headline font-extrabold text-primary"><?= (int)$totals['active_count'] ?><span class="text-base text-slate-400 font-bold">/<?= (int)$totals['agent_count'] ?></span></p>
      <p class="text-[10px] text-slate-400 mt-1">active in period</p>
    </div>
    <div class="bg-white rounded-2xl p-4 shadow-sm border border-slate-200">
      <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-2">Bookings</p>
      <p class="text-3xl font-headline font-extrabold text-on-surface"><?= (int)$totals['bookings'] ?></p>
      <p class="text-[10px] text-slate-400 mt-1"><?= (int)$totals['acceptances'] ?> acceptances</p>
    </div>
    <div class="bg-white rounded-2xl p-4 shadow-sm border border-slate-200">
      <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-2">Revenue</p>
      <p class="text-2xl font-headline font-extrabold text-on-surface"><?= htmlspecialchars($currency) ?> <?= $money($totals['revenue']) ?></p>
      <p class="text-[10px] text-slate-400 mt-1">approved bookings</p>
    </div>
    <div class="bg-white rounded-2xl p-4 shadow-sm border border-slate-200">
      <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-2">Gross MCO</p>
      <p class="text-2xl font-headline font-extrabold text-emerald-700"><?= htmlspecialchars($currency) ?> <?= $money($totals['profit']) ?></p>
      <p class="text-[10px] text-slate-400 mt-1">approved · avg <?= htmlspecialchars($currency) ?> <?= $money($totals['avg_per_agent']) ?>/agent</p>
    </div>
    <div class="bg-white rounded-2xl p-4 shadow-sm border border-slate-200">
      <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-2">Net MCO</p>
      <?php $tnet = $totals['net'] ?? $totals['profit']; ?>
      <p class="text-2xl font-headline font-extrabold <?= $tnet < 0 ? 'text-red-600' : 'text-primary' ?>"><?= htmlspecialchars($currency) ?> <?= $money($tnet) ?></p>
      <p class="text-[10px] <?= $tnet < 0 ? 'text-red-500 font-bold' : 'text-slate-400' ?> mt-1">after refunds<?= $tnet < 0 ? ' — net loss' : '' ?></p>
    </div>
    <div class="bg-gradient-to-br from-amber-50 to-orange-50 rounded-2xl p-4 shadow-sm border border-amber-100">
      <p class="text-[10px] font-bold uppercase tracking-wider text-amber-700/70 mb-2">Top Performer</p>
      <p class="text-base font-headline font-extrabold text-amber-900 truncate"><?= htmlspecialchars($totals['top_name'] ?? '—') ?></p>
      <p class="text-[11px] text-amber-700 font-bold mt-1"><?= htmlspecialchars($currency) ?> <?= $money($totals['top_profit']) ?> profit</p>
    </div>
  </div>

  <!-- Scores table -->
  <div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
    <div class="px-6 py-4 border-b border-slate-100 bg-slate-50/60 flex items-center gap-2">
      <span class="material-symbols-outlined text-primary text-xl">leaderboard</span>
      <h2 class="font-headline font-extrabold text-slate-900">Agent Scoreboard</h2>
      <span class="text-xs text-slate-400 font-medium">ranked by approved profit · revenue &amp; profit count approved bookings only</span>
    </div>

    <?php if (empty($rows)): ?>
      <div class="px-6 py-16 text-center text-slate-400">
        <span class="material-symbols-outlined text-4xl mb-2 block">group_off</span>
        <p class="font-semibold">No agents to analyse.</p>
        <p class="text-sm mt-1">You don't have any agents assigned to you yet, or there's no activity for this period.</p>
      </div>
    <?php else: ?>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="bg-primary text-white text-left">
            <th class="py-3 px-4 font-bold w-12">#</th>
            <th class="py-3 px-4 font-bold">Agent</th>
            <th class="py-3 px-4 font-bold text-right">Bookings</th>
            <th class="py-3 px-4 font-bold text-right">Approved</th>
            <th class="py-3 px-4 font-bold text-right">Pending</th>
            <th class="py-3 px-4 font-bold text-right">Voided</th>
            <th class="py-3 px-4 font-bold text-right">Acceptances</th>
            <th class="py-3 px-4 font-bold text-right">Revenue</th>
            <th class="py-3 px-4 font-bold text-right">Gross MCO</th>
            <th class="py-3 px-4 font-bold text-right">Net MCO</th>
            <th class="py-3 px-4 font-bold text-right">Avg / Approved</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <?php foreach ($rows as $i => $r): ?>
          <tr onclick="location.href='/performance/agent/<?= $r['id'] ?><?= $exportQuery ? '?' . htmlspecialchars($exportQuery) : '' ?>'"
              class="cursor-pointer <?= $i%2===0 ? 'bg-white' : 'bg-surface-container-low/40' ?> hover:bg-blue-50/30 transition-colors"
              title="View this agent's bookings">
            <td class="py-3 px-4">
              <?php if ($i < 3 && $r['profit'] > 0): ?>
                <span class="inline-flex items-center justify-center w-6 h-6 rounded-full text-xs font-extrabold <?= ['bg-amber-100 text-amber-700','bg-slate-200 text-slate-600','bg-orange-100 text-orange-700'][$i] ?>"><?= $i+1 ?></span>
              <?php else: ?>
                <span class="text-slate-400 font-bold pl-1.5"><?= $i+1 ?></span>
              <?php endif; ?>
            </td>
            <td class="py-3 px-4">
              <div class="flex items-center gap-2.5">
                <div class="w-8 h-8 rounded-full bg-primary/10 flex items-center justify-center text-primary font-bold text-xs flex-shrink-0">
                  <?= strtoupper(substr($r['name'], 0, 1)) ?>
                </div>
                <div class="min-w-0">
                  <p class="font-semibold text-on-surface truncate"><?= htmlspecialchars($r['name']) ?></p>
                  <p class="text-[10px] text-slate-400 capitalize"><?= htmlspecialchars($r['role']) ?></p>
                </div>
              </div>
            </td>
            <td class="py-3 px-4 text-right font-bold text-on-surface"><?= (int)$r['bookings'] ?></td>
            <td class="py-3 px-4 text-right text-emerald-700 font-semibold"><?= (int)$r['approved'] ?></td>
            <td class="py-3 px-4 text-right <?= $r['pending']>0 ? 'text-amber-600 font-semibold' : 'text-slate-400' ?>"><?= (int)$r['pending'] ?></td>
            <td class="py-3 px-4 text-right <?= $r['voided']>0 ? 'text-red-500 font-semibold' : 'text-slate-300' ?>"><?= (int)$r['voided'] ?></td>
            <td class="py-3 px-4 text-right text-on-surface-variant"><?= (int)$r['acceptances'] ?></td>
            <td class="py-3 px-4 text-right text-on-surface-variant"><?= htmlspecialchars($currency) ?> <?= $money($r['revenue']) ?></td>
            <td class="py-3 px-4 text-right font-bold <?= $r['profit']>=0 ? 'text-emerald-700' : 'text-red-600' ?>"><?= htmlspecialchars($currency) ?> <?= $money($r['profit']) ?></td>
            <td class="py-3 px-4 text-right font-extrabold <?= ($r['net'] ?? $r['profit'])>=0 ? 'text-primary' : 'text-red-600' ?>">
              <?= htmlspecialchars($currency) ?> <?= $money($r['net'] ?? $r['profit']) ?>
              <?php if (($r['net'] ?? $r['profit']) < $r['profit']): ?><span class="block text-[9px] font-bold text-rose-400 normal-case">refunded</span><?php endif; ?>
            </td>
            <td class="py-3 px-4 text-right text-slate-500"><?= htmlspecialchars($currency) ?> <?= $money($r['avg_profit']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr class="bg-slate-50 border-t-2 border-slate-200 font-extrabold">
            <td class="py-3 px-4" colspan="2">Team Total</td>
            <td class="py-3 px-4 text-right"><?= (int)$totals['bookings'] ?></td>
            <td class="py-3 px-4" colspan="3"></td>
            <td class="py-3 px-4 text-right"><?= (int)$totals['acceptances'] ?></td>
            <td class="py-3 px-4 text-right"><?= htmlspecialchars($currency) ?> <?= $money($totals['revenue']) ?></td>
            <td class="py-3 px-4 text-right text-emerald-700"><?= htmlspecialchars($currency) ?> <?= $money($totals['profit']) ?></td>
            <td class="py-3 px-4 text-right font-bold <?= ($totals['net'] ?? $totals['profit']) < 0 ? 'text-red-600' : 'text-primary' ?>"><?= htmlspecialchars($currency) ?> <?= $money($totals['net'] ?? $totals['profit']) ?></td>
            <td class="py-3 px-4 text-right text-slate-500"><?= htmlspecialchars($currency) ?> <?= $money($totals['avg_profit']) ?></td>
          </tr>
        </tfoot>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <p class="text-[11px] text-slate-400 mt-4 flex items-center gap-1.5">
    <span class="material-symbols-outlined text-sm">lock</span>
    Exports contain agent names &amp; aggregate scores only — no customer data, contacts, PNRs or card details.
  </p>
</main>

<script>
(function () {
  var form    = document.getElementById('filterForm');
  var input   = document.getElementById('periodInput');
  var monthW  = document.getElementById('monthWrap');
  var customW = document.getElementById('customWrap');

  function applyVisibility(p) {
    monthW.classList.toggle('hidden', p !== 'monthly');
    customW.classList.toggle('hidden', p !== 'custom');
    customW.classList.toggle('flex',   p === 'custom');
  }

  document.querySelectorAll('.period-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var p = btn.getAttribute('data-period');
      input.value = p;
      // Daily and Till-Date need no extra input — submit immediately.
      if (p === 'daily' || p === 'alltime') { form.submit(); return; }
      applyVisibility(p);
      document.querySelectorAll('.period-btn').forEach(function (b) {
        b.className = b.className.replace('bg-primary text-white shadow', 'text-on-surface-variant hover:text-primary');
      });
      btn.className = btn.className.replace('text-on-surface-variant hover:text-primary', 'bg-primary text-white shadow');
    });
  });
})();
</script>
</body>
</html>
