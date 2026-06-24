<?php
/**
 * Centre Analytics — admin dashboard.
 *
 * @var array  $overview     ['centres'=>[...], 'totals'=>[...], 'prev_label'=>..]
 * @var array  $trend        weekly trend rows
 * @var string $period $periodLabel $selFrom $selTo
 * @var bool   $configured   centres set up?
 * @var bool   $aiConfigured Vertex key present?
 */
use App\Services\CentreService;

$activePage = 'analytics';
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
$h   = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$m0  = fn($n) => number_format((float)$n, 0);
$m2  = fn($n) => number_format((float)$n, 2);
$JS  = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
$cur = 'USD';

$centres = $overview['centres'];
$totals  = $overview['totals'];

// ── Chart payload ──
$labels = $colors = $netArr = $revArr = $bookArr = $spendArr = $roiArr = [];
foreach (CentreService::ALL as $code) {
    $c = $centres[$code];
    $labels[]   = $c['label'];
    $colors[]   = $c['color'];
    $netArr[]   = round($c['net'], 2);
    $revArr[]   = round($c['revenue'], 2);
    $bookArr[]  = $c['bookings'];
    $spendArr[] = round($c['spend'], 2);
    $roiArr[]   = $c['roi'] !== null ? $c['roi'] : 0;
}
$trendLabels = array_map(fn($r) => $r['label'], $trend);
$trendSeries = [];
foreach (CentreService::ALL as $code) {
    $trendSeries[$code] = array_map(fn($r) => round($r[$code]['net'], 2), $trend);
}
$chartData = [
    'labels' => $labels, 'colors' => $colors,
    'net' => $netArr, 'revenue' => $revArr, 'bookings' => $bookArr, 'spend' => $spendArr, 'roi' => $roiArr,
    'trend' => ['labels' => $trendLabels, 'series' => $trendSeries],
];

// delta chip
$delta = function ($pct) {
    if ($pct === null) return '';
    $up = $pct >= 0;
    $cls = $up ? 'text-emerald-600' : 'text-rose-600';
    $ico = $up ? 'arrow_upward' : 'arrow_downward';
    return '<span class="inline-flex items-center text-[11px] font-bold ' . $cls . '"><span class="material-symbols-outlined text-[13px]">' . $ico . '</span>' . abs($pct) . '%</span>';
};
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
<meta charset="utf-8"/>
<meta content="width=device-width,initial-scale=1.0" name="viewport"/>
<title>Centre Analytics — Base Fare CRM</title>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
<script src="https://cdn.tailwindcss.com?plugins=forms"></script>
<script src="/assets/js/chart.umd.min.js"></script>
<script>
tailwind.config={darkMode:"class",theme:{extend:{colors:{primary:"#163274","primary-container":"#314a8d",background:"#f8f9fa","surface-container-low":"#f3f4f5","on-surface":"#191c1d","on-surface-variant":"#434653"},fontFamily:{headline:["Manrope"],body:["Inter"]}}}};
</script>
<style>.material-symbols-outlined{font-variation-settings:'FILL' 0,'wght' 400,'GRAD' 0,'opsz' 24}</style>
</head>
<body class="bg-background font-body text-on-surface antialiased min-h-screen">

<?php require __DIR__.'/../layout/sidebar.php'; ?>

<main class="ml-60 pt-8 pb-20 px-10">

  <!-- Header -->
  <div class="flex items-start justify-between mb-6 gap-4 flex-wrap">
    <div>
      <h1 class="text-3xl font-headline font-extrabold text-primary tracking-tight flex items-center gap-2">
        <span class="material-symbols-outlined text-3xl">insights</span> Centre Analytics
      </h1>
      <p class="text-sm text-on-surface-variant mt-1">
        DMR · MOH · JSR · <span class="font-semibold text-on-surface"><?= $h($periodLabel) ?></span>
        <span class="text-slate-400">· vs <?= $h($overview['prev_label']) ?></span>
      </p>
    </div>
    <div class="flex items-center gap-3">
      <a href="/analytics/settings" class="flex items-center gap-1.5 px-4 py-2.5 bg-white border border-slate-200 text-slate-600 rounded-xl text-sm font-bold hover:border-primary hover:text-primary transition-all shadow-sm">
        <span class="material-symbols-outlined text-base">settings</span> Centres &amp; Spend
      </a>
    </div>
  </div>

  <?php if (!$configured): ?>
  <div class="mb-6 px-5 py-4 bg-amber-50 border border-amber-200 rounded-2xl text-sm text-amber-800 flex items-center gap-3">
    <span class="material-symbols-outlined">warning</span>
    <div>Centres aren't configured yet. <a href="/analytics/settings" class="font-bold underline">Set the MOH/JSR managers and DMR members</a> so agents map into centres.</div>
  </div>
  <?php endif; ?>

  <!-- Period filter -->
  <form method="GET" action="/analytics" id="filterForm" class="bg-white rounded-2xl shadow-sm border border-slate-200 p-4 mb-6 flex flex-wrap items-end gap-4">
    <div>
      <label class="block text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1.5">Period</label>
      <div class="flex gap-1 bg-slate-100 rounded-xl p-1">
        <?php foreach (['weekly'=>'This Week','monthly'=>'This Month','last30'=>'Last 30d','custom'=>'Custom'] as $val=>$lbl): ?>
        <button type="button" data-period="<?= $val ?>" class="period-btn px-4 py-1.5 rounded-lg text-sm font-bold transition-all <?= $period===$val ? 'bg-primary text-white shadow' : 'text-on-surface-variant hover:text-primary' ?>"><?= $lbl ?></button>
        <?php endforeach; ?>
      </div>
      <input type="hidden" name="period" id="periodInput" value="<?= $h($period) ?>"/>
    </div>
    <div id="customWrap" class="<?= $period==='custom' ? 'flex' : 'hidden' ?> items-end gap-3">
      <div><label class="block text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1.5">From</label>
        <input type="date" name="date_from" value="<?= $h($selFrom) ?>" class="px-3 py-2 rounded-xl bg-white border border-slate-200 font-semibold text-primary text-sm focus:ring-2 focus:ring-primary/20"/></div>
      <div><label class="block text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1.5">To</label>
        <input type="date" name="date_to" value="<?= $h($selTo) ?>" class="px-3 py-2 rounded-xl bg-white border border-slate-200 font-semibold text-primary text-sm focus:ring-2 focus:ring-primary/20"/></div>
    </div>
    <button type="submit" class="px-5 py-2 bg-primary text-white rounded-xl text-sm font-bold hover:bg-primary-container transition-all">Apply</button>
  </form>

  <!-- Totals strip -->
  <div class="grid grid-cols-2 lg:grid-cols-5 gap-4 mb-6">
    <div class="bg-white rounded-2xl p-4 shadow-sm border border-slate-200"><p class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1">Bookings</p><p class="text-2xl font-headline font-extrabold text-on-surface"><?= $m0($totals['bookings']) ?></p></div>
    <div class="bg-white rounded-2xl p-4 shadow-sm border border-slate-200"><p class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1">Revenue</p><p class="text-2xl font-headline font-extrabold text-on-surface"><?= $cur ?> <?= $m0($totals['revenue']) ?></p></div>
    <div class="bg-white rounded-2xl p-4 shadow-sm border border-slate-200"><p class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1">Net MCO</p><p class="text-2xl font-headline font-extrabold text-emerald-700"><?= $cur ?> <?= $m0($totals['net']) ?></p></div>
    <div class="bg-white rounded-2xl p-4 shadow-sm border border-slate-200"><p class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1">Marketing Spend</p><p class="text-2xl font-headline font-extrabold text-on-surface"><?= $cur ?> <?= $m0($totals['spend']) ?></p></div>
    <div class="bg-gradient-to-br from-primary to-primary-container rounded-2xl p-4 shadow-sm text-white"><p class="text-[10px] font-bold uppercase tracking-wider text-white/70 mb-1">ROI (Net / Spend)</p><p class="text-2xl font-headline font-extrabold"><?= $totals['roi'] !== null ? $totals['roi'].'×' : '—' ?></p></div>
  </div>

  <!-- Per-centre cards -->
  <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-8">
    <?php foreach (CentreService::ALL as $code): $c = $centres[$code]; ?>
    <div class="bg-white rounded-2xl p-5 shadow-sm border border-slate-200">
      <div class="flex items-center gap-2 mb-3">
        <span class="w-3 h-3 rounded-full" style="background:<?= $c['color'] ?>"></span>
        <h3 class="font-headline font-extrabold text-slate-900"><?= $h($c['label']) ?></h3>
        <span class="ml-auto text-[11px] font-bold text-slate-400"><?= (int)$c['agents'] ?> agents</span>
      </div>
      <div class="grid grid-cols-2 gap-3">
        <div><p class="text-[10px] font-bold uppercase text-slate-400">Net MCO</p><p class="text-lg font-extrabold text-emerald-700"><?= $cur ?> <?= $m0($c['net']) ?></p><?= $delta($c['delta']['net']) ?></div>
        <div><p class="text-[10px] font-bold uppercase text-slate-400">Revenue</p><p class="text-lg font-extrabold text-on-surface"><?= $cur ?> <?= $m0($c['revenue']) ?></p><?= $delta($c['delta']['revenue']) ?></div>
        <div><p class="text-[10px] font-bold uppercase text-slate-400">Bookings</p><p class="text-lg font-extrabold text-on-surface"><?= (int)$c['bookings'] ?></p><?= $delta($c['delta']['bookings']) ?></div>
        <div><p class="text-[10px] font-bold uppercase text-slate-400">Spend</p><p class="text-lg font-extrabold text-on-surface"><?= $cur ?> <?= $m0($c['spend']) ?></p></div>
      </div>
      <div class="grid grid-cols-2 gap-3 mt-3 pt-3 border-t border-slate-100">
        <div><p class="text-[10px] font-bold uppercase text-slate-400">ROI</p><p class="text-sm font-bold <?= $c['roi'] !== null && $c['roi'] >= 1 ? 'text-emerald-600' : 'text-slate-600' ?>"><?= $c['roi'] !== null ? $c['roi'].'×' : '—' ?></p></div>
        <div><p class="text-[10px] font-bold uppercase text-slate-400">Cost / Booking</p><p class="text-sm font-bold text-slate-600"><?= $c['cost_per_booking'] !== null ? $cur.' '.$m2($c['cost_per_booking']) : '—' ?></p></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Charts -->
  <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
    <div class="bg-white rounded-2xl p-5 shadow-sm border border-slate-200">
      <h3 class="font-headline font-extrabold text-slate-900 mb-4">Net MCO &amp; Revenue by Centre</h3>
      <canvas id="barCentre" height="160"></canvas>
    </div>
    <div class="bg-white rounded-2xl p-5 shadow-sm border border-slate-200">
      <h3 class="font-headline font-extrabold text-slate-900 mb-4">Net MCO — Weekly Trend (8 weeks)</h3>
      <canvas id="lineTrend" height="160"></canvas>
    </div>
    <div class="bg-white rounded-2xl p-5 shadow-sm border border-slate-200">
      <h3 class="font-headline font-extrabold text-slate-900 mb-4">Net MCO Share</h3>
      <canvas id="donutShare" height="160"></canvas>
    </div>
    <div class="bg-white rounded-2xl p-5 shadow-sm border border-slate-200">
      <h3 class="font-headline font-extrabold text-slate-900 mb-4">ROI by Centre (Net MCO per $ spend)</h3>
      <canvas id="barRoi" height="160"></canvas>
    </div>
  </div>

  <!-- AI insights -->
  <div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
    <div class="px-6 py-4 border-b border-slate-100 bg-slate-50/60 flex items-center gap-2">
      <span class="material-symbols-outlined text-primary">auto_awesome</span>
      <h2 class="font-headline font-extrabold text-slate-900">AI Consolidated Analysis</h2>
      <button id="aiBtn" onclick="runAi()" class="ml-auto inline-flex items-center gap-1.5 px-4 py-2 bg-primary text-white rounded-xl text-sm font-bold hover:bg-primary-container transition-all <?= $aiConfigured ? '' : 'opacity-50 pointer-events-none' ?>">
        <span class="material-symbols-outlined text-base">auto_awesome</span> Generate Analysis
      </button>
    </div>
    <div class="p-6">
      <?php if (!$aiConfigured): ?>
      <p class="text-sm text-slate-400">AI is not configured (missing VERTEX_API_KEY).</p>
      <?php endif; ?>
      <div id="aiResult" class="hidden">
        <p id="aiSummary" class="text-sm text-slate-700 leading-relaxed mb-4"></p>
        <div id="aiNotes" class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-4"></div>
        <div id="aiSuggestions"></div>
      </div>
      <p id="aiHint" class="text-sm text-slate-400 <?= $aiConfigured ? '' : 'hidden' ?>">Click <strong>Generate Analysis</strong> for a written read of the centres + suggestions. Only aggregate stats are sent — never customer data.</p>
    </div>
  </div>
</main>

<script>
const CHART = <?= json_encode($chartData, $JS) ?>;
const PERIOD = { period: <?= json_encode($period, $JS) ?>, date_from: <?= json_encode($selFrom, $JS) ?>, date_to: <?= json_encode($selTo, $JS) ?> };
const CSRF = <?= json_encode($_SESSION['csrf_token'] ?? '', $JS) ?>;

// ── Period filter UI ──
(function(){
  var form=document.getElementById('filterForm'), input=document.getElementById('periodInput'), customW=document.getElementById('customWrap');
  document.querySelectorAll('.period-btn').forEach(function(btn){
    btn.addEventListener('click', function(){
      var p=btn.getAttribute('data-period'); input.value=p;
      if(p!=='custom'){ form.submit(); return; }
      customW.classList.remove('hidden'); customW.classList.add('flex');
      document.querySelectorAll('.period-btn').forEach(function(b){ b.className=b.className.replace('bg-primary text-white shadow','text-on-surface-variant hover:text-primary'); });
      btn.className=btn.className.replace('text-on-surface-variant hover:text-primary','bg-primary text-white shadow');
    });
  });
})();

// ── Charts ──
const money = v => (CHART && new Intl.NumberFormat('en-US').format(Math.round(v)));
document.addEventListener('DOMContentLoaded', function(){
  if (typeof Chart === 'undefined') return;
  Chart.defaults.font.family = 'Inter, sans-serif';
  Chart.defaults.color = '#64748b';

  new Chart(document.getElementById('barCentre'), {
    type:'bar',
    data:{ labels:CHART.labels, datasets:[
      { label:'Net MCO', data:CHART.net, backgroundColor:CHART.colors },
      { label:'Revenue', data:CHART.revenue, backgroundColor:CHART.colors.map(c=>c+'66') },
    ]},
    options:{ responsive:true, plugins:{legend:{position:'bottom'}}, scales:{y:{beginAtZero:true}} }
  });

  new Chart(document.getElementById('lineTrend'), {
    type:'line',
    data:{ labels:CHART.trend.labels, datasets:Object.keys(CHART.trend.series).map((code,i)=>({
      label:CHART.labels[i], data:CHART.trend.series[code], borderColor:CHART.colors[i], backgroundColor:CHART.colors[i]+'22', tension:0.3, fill:false,
    }))},
    options:{ responsive:true, plugins:{legend:{position:'bottom'}}, scales:{y:{beginAtZero:true}} }
  });

  new Chart(document.getElementById('donutShare'), {
    type:'doughnut',
    data:{ labels:CHART.labels, datasets:[{ data:CHART.net.map(v=>Math.max(0,v)), backgroundColor:CHART.colors }]},
    options:{ responsive:true, plugins:{legend:{position:'bottom'}}, cutout:'62%' }
  });

  new Chart(document.getElementById('barRoi'), {
    type:'bar',
    data:{ labels:CHART.labels, datasets:[{ label:'ROI (×)', data:CHART.roi, backgroundColor:CHART.colors }]},
    options:{ responsive:true, plugins:{legend:{display:false}}, scales:{y:{beginAtZero:true}} }
  });
});

// ── AI ──
async function runAi(){
  const btn=document.getElementById('aiBtn');
  const hint=document.getElementById('aiHint'), result=document.getElementById('aiResult');
  btn.disabled=true; btn.innerHTML='<span class="material-symbols-outlined text-base animate-spin">progress_activity</span> Analysing…';
  try{
    const fd=new URLSearchParams({csrf_token:CSRF, period:PERIOD.period, date_from:PERIOD.date_from, date_to:PERIOD.date_to});
    const res=await fetch('/analytics/ai',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:fd});
    const j=await res.json();
    if(!j.success) throw new Error(j.error||'AI failed');
    document.getElementById('aiSummary').textContent=j.summary||'';
    const notes=document.getElementById('aiNotes'); notes.innerHTML='';
    const colors={DMR:'#2563eb',MOH:'#7c3aed',JSR:'#0d9488'};
    Object.keys(j.centre_notes||{}).forEach(code=>{
      const d=document.createElement('div'); d.className='rounded-xl border border-slate-200 p-3';
      d.innerHTML='<p class="text-[11px] font-bold uppercase mb-1" style="color:'+(colors[code]||'#64748b')+'">'+code+'</p><p class="text-[13px] text-slate-600">'+escapeHtml(j.centre_notes[code])+'</p>';
      notes.appendChild(d);
    });
    const sug=document.getElementById('aiSuggestions');
    if((j.suggestions||[]).length){
      sug.innerHTML='<p class="text-xs font-bold uppercase tracking-wider text-slate-400 mb-2">Suggestions</p>'+
        '<ul class="space-y-2">'+j.suggestions.map(s=>'<li class="flex gap-2 text-sm text-slate-700"><span class="material-symbols-outlined text-base text-primary">arrow_right</span><span>'+escapeHtml(s)+'</span></li>').join('')+'</ul>';
    } else { sug.innerHTML=''; }
    hint.classList.add('hidden'); result.classList.remove('hidden');
  }catch(e){ alert(e.message||'Could not generate analysis.'); }
  finally{ btn.disabled=false; btn.innerHTML='<span class="material-symbols-outlined text-base">auto_awesome</span> Regenerate'; }
}
function escapeHtml(s){ const d=document.createElement('div'); d.textContent=s==null?'':String(s); return d.innerHTML; }
</script>
</body>
</html>
