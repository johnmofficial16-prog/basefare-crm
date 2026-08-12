<?php
/**
 * My Performance — agent self-view (own bookings, day-wise) + peer rank.
 *
 * @var array  $rows        Per-booking rows: id,date,customer_name,pnr,gross,net,refund_status,...
 * @var int    $rank        This agent's rank by Net MCO (1 = best; 0 = unranked)
 * @var int    $rankTotal   Number of agents ranked
 * @var float  $myGross     My Gross MCO total for the window
 * @var float  $myNet       My Net MCO total for the window
 * @var int    $myBookings  My approved-booking count
 * @var string $period      'daily' | 'monthly' | 'alltime' | 'custom'
 * @var string $periodLabel
 * @var string $currency
 * @var string $selMonth $selFrom $selTo
 */
$m2 = fn($n) => number_format((float)$n, 2);
$h  = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
<meta charset="utf-8"/>
<meta content="width=device-width,initial-scale=1.0" name="viewport"/>
<title>My Performance — Base Fare CRM</title>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
<script src="/assets/js/tailwind.js"></script>
<script>
tailwind.config={darkMode:"class",theme:{extend:{colors:{primary:"#163274","primary-container":"#314a8d",background:"#f8f9fa","surface-container-low":"#f3f4f5","on-surface":"#191c1d","on-surface-variant":"#434653"},fontFamily:{headline:["Manrope"],body:["Inter"]}}}};
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
      <h1 class="text-3xl font-headline font-extrabold text-primary tracking-tight">My Performance</h1>
      <p class="text-sm text-on-surface-variant mt-1">
        Your bookings &amp; MCO · <span class="font-semibold text-on-surface"><?= $h($periodLabel) ?></span>
      </p>
    </div>
  </div>

  <!-- Filter bar -->
  <form method="GET" action="/performance" id="filterForm"
        class="bg-white rounded-2xl shadow-sm border border-slate-200 p-4 mb-6 flex flex-wrap items-end gap-4">
    <div>
      <label class="block text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1.5">Period</label>
      <div class="flex gap-1 bg-slate-100 rounded-xl p-1">
        <?php foreach (['daily'=>'Today','monthly'=>'Monthly','alltime'=>'Till Date','custom'=>'Custom'] as $val=>$lbl): ?>
        <button type="button" data-period="<?= $val ?>"
                class="period-btn px-4 py-1.5 rounded-lg text-sm font-bold transition-all <?= $period===$val ? 'bg-primary text-white shadow' : 'text-on-surface-variant hover:text-primary' ?>">
          <?= $lbl ?>
        </button>
        <?php endforeach; ?>
      </div>
      <input type="hidden" name="period" id="periodInput" value="<?= $h($period) ?>"/>
    </div>
    <div id="monthWrap" class="<?= $period==='monthly' ? '' : 'hidden' ?>">
      <label class="block text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1.5">Month</label>
      <input type="month" name="month" value="<?= $h($selMonth) ?>" class="px-3 py-2 rounded-xl bg-white border border-slate-200 font-semibold text-primary text-sm focus:ring-2 focus:ring-primary/20"/>
    </div>
    <div id="customWrap" class="<?= $period==='custom' ? 'flex' : 'hidden' ?> items-end gap-3">
      <div>
        <label class="block text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1.5">From</label>
        <input type="date" name="date_from" value="<?= $h($selFrom) ?>" class="px-3 py-2 rounded-xl bg-white border border-slate-200 font-semibold text-primary text-sm focus:ring-2 focus:ring-primary/20"/>
      </div>
      <div>
        <label class="block text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1.5">To</label>
        <input type="date" name="date_to" value="<?= $h($selTo) ?>" class="px-3 py-2 rounded-xl bg-white border border-slate-200 font-semibold text-primary text-sm focus:ring-2 focus:ring-primary/20"/>
      </div>
    </div>
    <button type="submit" class="px-5 py-2 bg-primary text-white rounded-xl text-sm font-bold hover:bg-primary-container transition-all">Apply</button>
  </form>

  <!-- Stat cards -->
  <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
    <div class="bg-gradient-to-br from-primary to-primary-container rounded-2xl p-5 shadow-sm text-white">
      <p class="text-[10px] font-bold uppercase tracking-wider text-white/70 mb-2">My Rank · Net MCO</p>
      <?php if ($rank > 0): ?>
      <p class="text-3xl font-headline font-extrabold">#<?= (int)$rank ?><span class="text-base text-white/60 font-bold"> / <?= (int)$rankTotal ?></span></p>
      <p class="text-[11px] text-white/70 mt-1">among all agents this period</p>
      <?php else: ?>
      <p class="text-2xl font-headline font-extrabold">—</p>
      <p class="text-[11px] text-white/70 mt-1">no ranked activity yet</p>
      <?php endif; ?>
    </div>
    <div class="bg-white rounded-2xl p-5 shadow-sm border border-slate-200">
      <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-2">My Bookings</p>
      <p class="text-3xl font-headline font-extrabold text-on-surface"><?= (int)$myBookings ?></p>
      <p class="text-[10px] text-slate-400 mt-1">approved</p>
    </div>
    <div class="bg-white rounded-2xl p-5 shadow-sm border border-slate-200">
      <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-2">Gross MCO</p>
      <p class="text-2xl font-headline font-extrabold text-emerald-700"><?= $h($currency) ?> <?= $m2($myGross) ?></p>
    </div>
    <div class="bg-white rounded-2xl p-5 shadow-sm border border-slate-200">
      <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-2">Net MCO</p>
      <p class="text-2xl font-headline font-extrabold text-primary"><?= $h($currency) ?> <?= $m2($myNet) ?></p>
      <p class="text-[10px] text-slate-400 mt-1">after refunds</p>
    </div>
  </div>

  <!-- Bookings table -->
  <div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
    <div class="px-6 py-4 border-b border-slate-100 bg-slate-50/60 flex items-center gap-2">
      <span class="material-symbols-outlined text-primary text-xl">receipt_long</span>
      <h2 class="font-headline font-extrabold text-slate-900">My Bookings</h2>
      <span class="text-xs text-slate-400 font-medium">approved only · Net MCO reflects refunds</span>
    </div>
    <?php if (empty($rows)): ?>
      <div class="px-6 py-16 text-center text-slate-400">
        <span class="material-symbols-outlined text-4xl mb-2 block">inbox</span>
        <p class="font-semibold">No approved bookings in this period.</p>
      </div>
    <?php else: ?>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="bg-primary text-white text-left">
            <th class="py-3 px-4 font-bold">Date</th>
            <th class="py-3 px-4 font-bold">Passenger</th>
            <th class="py-3 px-4 font-bold">PNR</th>
            <th class="py-3 px-4 font-bold text-right">Gross MCO</th>
            <th class="py-3 px-4 font-bold text-right">Net MCO</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <?php foreach ($rows as $i => $r): $refunded = ($r['net'] < $r['gross']); ?>
          <tr onclick="location.href='/transactions/<?= (int)$r['id'] ?>'"
              class="cursor-pointer <?= $i%2===0 ? 'bg-white' : 'bg-surface-container-low/40' ?> hover:bg-blue-50/30 transition-colors">
            <td class="py-3 px-4 text-on-surface-variant whitespace-nowrap"><?= date('M d, Y g:i A', strtotime((string)$r['date'])) ?></td>
            <td class="py-3 px-4 font-semibold text-on-surface"><?= $h($r['customer_name']) ?></td>
            <td class="py-3 px-4 font-mono text-xs font-bold tracking-wider"><?= $h($r['pnr']) ?></td>
            <td class="py-3 px-4 text-right font-bold text-emerald-700"><?= $h($r['currency']) ?> <?= $m2($r['gross']) ?></td>
            <td class="py-3 px-4 text-right font-extrabold <?= $refunded ? 'text-rose-600' : 'text-primary' ?>">
              <?= $h($r['currency']) ?> <?= $m2($r['net']) ?>
              <?php if ($refunded): ?><span class="block text-[9px] font-bold text-rose-400">refunded</span><?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr class="bg-slate-50 border-t-2 border-slate-200 font-extrabold">
            <td class="py-3 px-4" colspan="3">Total</td>
            <td class="py-3 px-4 text-right text-emerald-700"><?= $h($currency) ?> <?= $m2($myGross) ?></td>
            <td class="py-3 px-4 text-right text-primary"><?= $h($currency) ?> <?= $m2($myNet) ?></td>
          </tr>
        </tfoot>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <p class="text-[11px] text-slate-400 mt-4 flex items-center gap-1.5">
    <span class="material-symbols-outlined text-sm">lock</span>
    Your rank compares Net MCO across agents — other agents' names and figures are never shown.
  </p>
</main>

<script>
(function () {
  var form=document.getElementById('filterForm'), input=document.getElementById('periodInput'),
      monthW=document.getElementById('monthWrap'), customW=document.getElementById('customWrap');
  function applyVisibility(p){ monthW.classList.toggle('hidden',p!=='monthly'); customW.classList.toggle('hidden',p!=='custom'); customW.classList.toggle('flex',p==='custom'); }
  document.querySelectorAll('.period-btn').forEach(function(btn){
    btn.addEventListener('click',function(){
      var p=btn.getAttribute('data-period'); input.value=p;
      if(p==='daily'||p==='alltime'){ form.submit(); return; }
      applyVisibility(p);
      document.querySelectorAll('.period-btn').forEach(function(b){ b.className=b.className.replace('bg-primary text-white shadow','text-on-surface-variant hover:text-primary'); });
      btn.className=btn.className.replace('text-on-surface-variant hover:text-primary','bg-primary text-white shadow');
    });
  });
})();
</script>
</body>
</html>
