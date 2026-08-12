<?php
/**
 * Chargebacks & Refunds — admin dashboard + manual entry.
 *
 * @var array  $analysis   ['centres'=>[...], 'totals'=>[...]]
 * @var array  $trend      monthlyTrend rows
 * @var string $period $periodLabel $selFrom $selTo $currency $csrf
 * @var \Illuminate\Support\Collection $entries
 */
use App\Services\CentreService;
use App\Models\ChargebackRefund;

$activePage = 'chargebacks';
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
$h   = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$m0  = fn($n) => number_format((float)$n, 0);
$m2  = fn($n) => number_format((float)$n, 2);
$cur = $currency ?? 'CAD';

$centres = $analysis['centres'];
$totals  = $analysis['totals'];

// Chart payloads
$barLabels = $cbBar = $rfBar = [];
foreach (CentreService::ALL as $code) {
    $c = $centres[$code];
    $barLabels[] = $c['label'];
    $cbBar[]     = $c['chargebacks']['amount'];
    $rfBar[]     = $c['refunds']['amount'];
}
$chartData = [
    'bar'   => ['labels' => $barLabels, 'chargeback' => $cbBar, 'refund' => $rfBar],
    'donut' => ['chargeback' => $totals['chargebacks']['amount'], 'refund' => $totals['refunds']['amount']],
    'trend' => [
        'labels'     => array_map(fn($r) => $r['label'], $trend),
        'chargeback' => array_map(fn($r) => $r['chargeback'], $trend),
        'refund'     => array_map(fn($r) => $r['refund'], $trend),
    ],
];

if (!function_exists('cbrKindBadge')) {
function cbrKindBadge(string $kind): string {
    return $kind === 'chargeback'
        ? '<span class="px-2 py-0.5 rounded-full text-[10px] font-extrabold bg-red-100 text-red-700">CHARGEBACK</span>'
        : '<span class="px-2 py-0.5 rounded-full text-[10px] font-extrabold bg-amber-100 text-amber-700">REFUND</span>';
}
function cbrOutcomeBadge(?string $o): string {
    if (!$o) return '<span class="text-slate-300">—</span>';
    $map = ['won'=>'bg-emerald-100 text-emerald-700','lost'=>'bg-red-100 text-red-700','pending'=>'bg-slate-100 text-slate-600','processed'=>'bg-blue-100 text-blue-700'];
    return '<span class="px-2 py-0.5 rounded-full text-[10px] font-bold '.($map[$o]??'bg-slate-100 text-slate-600').'">'.strtoupper($o).'</span>';
}
}
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
<meta charset="utf-8"/>
<meta content="width=device-width,initial-scale=1.0" name="viewport"/>
<title>Chargebacks &amp; Refunds — Base Fare CRM</title>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
<script src="/assets/js/tailwind.js"></script>
<script src="/assets/js/chart.umd.min.js"></script>
<script>
tailwind.config={darkMode:"class",theme:{extend:{colors:{primary:"#163274","primary-container":"#314a8d",background:"#f8f9fa","on-surface":"#191c1d","on-surface-variant":"#434653"},fontFamily:{headline:["Manrope"],body:["Inter"]}}}};
</script>
<style>
.material-symbols-outlined{font-variation-settings:'FILL' 0,'wght' 400,'GRAD' 0,'opsz' 24}
.card-hover{transition:transform .16s ease,box-shadow .16s ease}
.card-hover:hover{transform:translateY(-3px);box-shadow:0 16px 34px rgba(15,30,60,.12)}
.kpi-ico{width:38px;height:38px;border-radius:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.centre-card{position:relative;overflow:hidden}
.centre-card::before{content:'';position:absolute;top:0;left:0;right:0;height:4px;background:var(--accent)}
.seg{transition:all .12s ease}
.fld{width:100%;margin-top:.25rem;border:1px solid #e2e8f0;border-radius:.6rem;padding:.5rem .7rem;font-size:.85rem;background:#fff;outline:none}
.fld:focus{box-shadow:0 0 0 2px rgba(22,50,116,.18);border-color:#163274}
@keyframes floatIn{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
.float-in{animation:floatIn .35s ease both}
#toast{transition:opacity .25s ease, transform .25s ease}
</style>
</head>
<body class="bg-background font-body text-on-surface antialiased min-h-screen">

<?php require __DIR__.'/../layout/sidebar.php'; ?>

<main class="ml-60 pt-8 pb-24 px-10">

  <!-- Header -->
  <div class="flex items-start justify-between mb-6 gap-4 flex-wrap">
    <div>
      <h1 class="text-3xl font-headline font-extrabold text-primary tracking-tight flex items-center gap-2">
        <span class="material-symbols-outlined text-3xl">credit_card_off</span> Chargebacks &amp; Refunds
      </h1>
      <p class="text-sm text-on-surface-variant mt-1">
        Bifurcated per centre · <span class="font-semibold text-on-surface"><?= $h($periodLabel) ?></span>
      </p>
    </div>
    <button onclick="document.getElementById('addCard').scrollIntoView({behavior:'smooth'});document.getElementById('f_amount').focus();"
       class="flex items-center gap-1.5 px-4 py-2.5 bg-primary text-white rounded-xl text-sm font-bold hover:bg-primary-container transition-all shadow-sm">
      <span class="material-symbols-outlined text-base">add</span> Add entry
    </button>
  </div>

  <!-- Period filter -->
  <form method="GET" action="/chargebacks" id="filterForm" class="bg-white rounded-2xl shadow-sm border border-slate-200 p-4 mb-6 flex flex-wrap items-end gap-4">
    <div>
      <label class="block text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1.5">Period</label>
      <div class="flex gap-1 bg-slate-100 rounded-xl p-1">
        <?php foreach (['monthly'=>'This Month','last30'=>'Last 30d','year'=>'This Year','all'=>'All Time','custom'=>'Custom'] as $val=>$lbl): ?>
        <button type="button" data-period="<?= $val ?>" class="period-btn px-4 py-1.5 rounded-lg text-sm font-bold transition-all <?= $period===$val ? 'bg-primary text-white shadow' : 'text-on-surface-variant hover:text-primary' ?>"><?= $lbl ?></button>
        <?php endforeach; ?>
      </div>
      <input type="hidden" name="period" id="periodInput" value="<?= $h($period) ?>"/>
    </div>
    <div id="customWrap" class="<?= $period==='custom' ? 'flex' : 'hidden' ?> items-end gap-3">
      <div><label class="block text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1.5">From</label>
        <input type="date" name="date_from" value="<?= $h($selFrom) ?>" class="px-3 py-2 rounded-xl bg-white border border-slate-200 font-semibold text-primary text-sm"/></div>
      <div><label class="block text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1.5">To</label>
        <input type="date" name="date_to" value="<?= $h($selTo) ?>" class="px-3 py-2 rounded-xl bg-white border border-slate-200 font-semibold text-primary text-sm"/></div>
    </div>
    <button type="submit" class="px-5 py-2 bg-primary text-white rounded-xl text-sm font-bold hover:bg-primary-container transition-all">Apply</button>
  </form>

  <!-- Totals strip -->
  <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <div class="card-hover float-in bg-white rounded-2xl p-4 shadow-sm border border-slate-200 flex items-center gap-3">
      <div class="kpi-ico bg-red-50 text-red-600"><span class="material-symbols-outlined text-xl">gpp_bad</span></div>
      <div><p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Chargebacks</p>
        <p class="text-2xl font-headline font-extrabold text-red-600 leading-tight"><?= $cur ?> <?= $m0($totals['chargebacks']['amount']) ?></p>
        <p class="text-[11px] text-slate-400 font-semibold"><?= (int)$totals['chargebacks']['count'] ?> event<?= $totals['chargebacks']['count']==1?'':'s' ?></p></div>
    </div>
    <div class="card-hover float-in bg-white rounded-2xl p-4 shadow-sm border border-slate-200 flex items-center gap-3" style="animation-delay:.04s">
      <div class="kpi-ico bg-amber-50 text-amber-600"><span class="material-symbols-outlined text-xl">currency_exchange</span></div>
      <div><p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Refunds</p>
        <p class="text-2xl font-headline font-extrabold text-amber-600 leading-tight"><?= $cur ?> <?= $m0($totals['refunds']['amount']) ?></p>
        <p class="text-[11px] text-slate-400 font-semibold"><?= (int)$totals['refunds']['count'] ?> event<?= $totals['refunds']['count']==1?'':'s' ?></p></div>
    </div>
    <div class="card-hover float-in bg-white rounded-2xl p-4 shadow-sm border border-slate-200 flex items-center gap-3" style="animation-delay:.08s">
      <div class="kpi-ico bg-slate-100 text-slate-600"><span class="material-symbols-outlined text-xl">summarize</span></div>
      <div><p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Combined</p>
        <p class="text-2xl font-headline font-extrabold text-on-surface leading-tight"><?= $cur ?> <?= $m0($totals['total_amount']) ?></p>
        <p class="text-[11px] text-slate-400 font-semibold"><?= (int)$totals['total_count'] ?> total</p></div>
    </div>
    <div class="card-hover float-in bg-white rounded-2xl p-4 shadow-sm border border-slate-200 flex items-center gap-3" style="animation-delay:.12s">
      <div class="kpi-ico bg-blue-50 text-blue-600"><span class="material-symbols-outlined text-xl">pie_chart</span></div>
      <div><p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Chargeback share</p>
        <p class="text-2xl font-headline font-extrabold text-on-surface leading-tight"><?= $totals['total_amount']>0 ? round($totals['chargebacks']['amount']/$totals['total_amount']*100) : 0 ?>%</p>
        <p class="text-[11px] text-slate-400 font-semibold">of total $</p></div>
    </div>
  </div>

  <!-- Per-centre cards -->
  <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
    <?php foreach (CentreService::ALL as $code): $c = $centres[$code]; ?>
    <div class="centre-card card-hover float-in bg-white rounded-2xl p-5 shadow-sm border border-slate-200" style="--accent:<?= $c['color'] ?>">
      <div class="flex items-center justify-between mb-3">
        <span class="font-headline font-extrabold text-lg" style="color:<?= $c['color'] ?>"><?= $h($c['label']) ?></span>
        <span class="text-xs font-bold text-slate-400"><?= $c['total_count'] ?> events</span>
      </div>
      <div class="flex items-end justify-between gap-3">
        <div>
          <p class="text-[10px] font-bold uppercase tracking-wide text-red-500">Chargebacks</p>
          <p class="text-xl font-extrabold text-red-600"><?= $cur ?> <?= $m0($c['chargebacks']['amount']) ?></p>
          <p class="text-[11px] text-slate-400"><?= $c['chargebacks']['count'] ?> event<?= $c['chargebacks']['count']==1?'':'s' ?></p>
        </div>
        <div class="text-right">
          <p class="text-[10px] font-bold uppercase tracking-wide text-amber-500">Refunds</p>
          <p class="text-xl font-extrabold text-amber-600"><?= $cur ?> <?= $m0($c['refunds']['amount']) ?></p>
          <p class="text-[11px] text-slate-400"><?= $c['refunds']['count'] ?> event<?= $c['refunds']['count']==1?'':'s' ?></p>
        </div>
      </div>
      <div class="mt-3 pt-3 border-t border-slate-100 flex items-baseline justify-between">
        <span class="text-[11px] font-bold uppercase tracking-wide text-slate-400">Total</span>
        <span class="font-extrabold text-on-surface"><?= $cur ?> <?= $m0($c['total_amount']) ?></span>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Charts -->
  <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">
    <div class="lg:col-span-2 bg-white rounded-2xl shadow-sm border border-slate-200 p-5">
      <p class="text-sm font-bold text-on-surface mb-3">Chargebacks vs Refunds by centre (<?= $cur ?>)</p>
      <div style="height:260px"><canvas id="barChart"></canvas></div>
    </div>
    <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-5">
      <p class="text-sm font-bold text-on-surface mb-3">Split</p>
      <div style="height:260px"><canvas id="donutChart"></canvas></div>
    </div>
  </div>
  <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-5 mb-6">
    <p class="text-sm font-bold text-on-surface mb-3">Monthly trend (<?= $cur ?>)</p>
    <div style="height:220px"><canvas id="trendChart"></canvas></div>
  </div>

  <!-- ── Quick add ───────────────────────────────────────────────────────── -->
  <div id="addCard" class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6 mb-6">
    <div class="flex items-center justify-between mb-4">
      <h2 class="font-headline font-extrabold text-lg text-primary flex items-center gap-2">
        <span class="material-symbols-outlined">edit_note</span> <span id="formTitle">Add an entry</span>
      </h2>
      <a id="refreshAnalysis" href="/chargebacks?period=<?= $h($period) ?>&date_from=<?= $h($selFrom) ?>&date_to=<?= $h($selTo) ?>" class="hidden text-xs font-bold text-primary hover:underline items-center gap-1">
        <span class="material-symbols-outlined text-sm">refresh</span> Refresh analysis (<span id="addedCount">0</span> added)
      </a>
    </div>

    <form id="entryForm" class="space-y-4">
      <input type="hidden" id="f_id" value=""/>
      <div class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
        <!-- Type segmented -->
        <div class="md:col-span-3">
          <label class="text-[10px] font-bold uppercase text-slate-500">Type</label>
          <div class="flex gap-1 bg-slate-100 rounded-xl p-1 mt-1">
            <button type="button" data-kind="chargeback" class="kind-btn seg flex-1 px-3 py-2 rounded-lg text-sm font-bold bg-red-600 text-white">Chargeback</button>
            <button type="button" data-kind="refund" class="kind-btn seg flex-1 px-3 py-2 rounded-lg text-sm font-bold text-slate-500">Refund</button>
          </div>
          <input type="hidden" id="f_kind" value="chargeback"/>
        </div>
        <!-- Centre -->
        <div class="md:col-span-3">
          <label class="text-[10px] font-bold uppercase text-slate-500">Centre</label>
          <div class="flex gap-1 mt-1">
            <?php foreach (CentreService::ALL as $code): ?>
            <button type="button" data-centre="<?= $code ?>" class="centre-btn seg flex-1 px-2 py-2 rounded-lg text-sm font-bold border border-slate-200 text-slate-500"><?= $code ?></button>
            <?php endforeach; ?>
          </div>
          <input type="hidden" id="f_centre" value=""/>
        </div>
        <div class="md:col-span-2">
          <label class="text-[10px] font-bold uppercase text-slate-500">Date</label>
          <input type="date" id="f_date" value="<?= date('Y-m-d') ?>" class="fld"/>
        </div>
        <div class="md:col-span-2">
          <label class="text-[10px] font-bold uppercase text-slate-500">Amount</label>
          <input type="number" step="0.01" min="0.01" id="f_amount" placeholder="0.00" class="fld"/>
        </div>
        <div class="md:col-span-2">
          <label class="text-[10px] font-bold uppercase text-slate-500">Currency</label>
          <select id="f_currency" class="fld"><?php foreach (['USD','CAD','GBP','EUR'] as $cc): ?><option value="<?= $cc ?>" <?= $cc===$cur?'selected':'' ?>><?= $cc ?></option><?php endforeach; ?></select>
        </div>
      </div>
      <div class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
        <div class="md:col-span-2"><label class="text-[10px] font-bold uppercase text-slate-500">PNR <span class="text-slate-300 normal-case">(optional)</span></label><input type="text" id="f_pnr" class="fld" placeholder="ABC123"/></div>
        <div class="md:col-span-3"><label class="text-[10px] font-bold uppercase text-slate-500">Customer <span class="text-slate-300 normal-case">(optional)</span></label><input type="text" id="f_customer" class="fld"/></div>
        <div class="md:col-span-3"><label class="text-[10px] font-bold uppercase text-slate-500">Reason <span class="text-slate-300 normal-case">(optional)</span></label><input type="text" id="f_reason" class="fld" placeholder="Friendly fraud…"/></div>
        <div class="md:col-span-2"><label class="text-[10px] font-bold uppercase text-slate-500">Outcome</label>
          <select id="f_outcome" class="fld"><option value="">—</option><?php foreach (ChargebackRefund::OUTCOMES as $o): ?><option value="<?= $o ?>"><?= ucfirst($o) ?></option><?php endforeach; ?></select></div>
        <div class="md:col-span-2 flex gap-2">
          <button type="submit" class="flex-1 px-4 py-2.5 bg-primary text-white rounded-lg text-sm font-bold hover:bg-primary-container transition-all"><span id="submitLabel">Save entry</span></button>
          <button type="button" id="cancelEdit" class="hidden px-3 py-2.5 bg-slate-100 text-slate-500 rounded-lg text-sm font-bold hover:bg-slate-200">Cancel</button>
        </div>
      </div>
      <p id="formError" class="hidden text-xs font-semibold text-red-600"></p>
    </form>
  </div>

  <!-- Entries log -->
  <div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
    <div class="px-6 py-4 border-b border-slate-100"><h2 class="font-headline font-extrabold text-on-surface">Entry log <span class="text-sm font-normal text-slate-400">(most recent)</span></h2></div>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-slate-50 text-slate-500 text-[10px] uppercase tracking-wider">
          <tr>
            <th class="px-6 py-3 text-left font-bold">Date</th>
            <th class="px-6 py-3 text-left font-bold">Centre</th>
            <th class="px-6 py-3 text-left font-bold">Type</th>
            <th class="px-6 py-3 text-right font-bold">Amount</th>
            <th class="px-6 py-3 text-left font-bold">PNR</th>
            <th class="px-6 py-3 text-left font-bold">Customer</th>
            <th class="px-6 py-3 text-left font-bold">Reason</th>
            <th class="px-6 py-3 text-left font-bold">Outcome</th>
            <th class="px-6 py-3 text-right font-bold">Actions</th>
          </tr>
        </thead>
        <tbody id="entryRows" class="divide-y divide-slate-100">
          <?php if ($entries->isEmpty()): ?>
          <tr id="emptyRow"><td colspan="9" class="px-6 py-12 text-center text-slate-400">No entries yet. Add your first chargeback or refund above.</td></tr>
          <?php else: foreach ($entries as $e): ?>
          <tr id="row-<?= (int)$e->id ?>" class="hover:bg-slate-50"
              data-json='<?= $h(json_encode(["id"=>$e->id,"centre"=>$e->centre,"kind"=>$e->kind,"event_date"=>$e->event_date?$e->event_date->format("Y-m-d"):"","amount"=>(float)$e->amount,"currency"=>$e->currency,"pnr"=>$e->pnr,"customer_name"=>$e->customer_name,"reason"=>$e->reason,"outcome"=>$e->outcome])) ?>'>
            <td class="px-6 py-3 text-slate-500 text-[13px] whitespace-nowrap"><?= $e->event_date ? $h($e->event_date->format('M j, Y')) : '—' ?></td>
            <td class="px-6 py-3"><span class="px-2 py-0.5 rounded-full text-[10px] font-bold text-white" style="background:<?= CentreService::COLORS[$e->centre] ?? '#64748b' ?>"><?= $h($e->centre) ?></span></td>
            <td class="px-6 py-3"><?= cbrKindBadge($e->kind) ?></td>
            <td class="px-6 py-3 text-right font-bold <?= $e->kind==='chargeback'?'text-red-600':'text-amber-600' ?> whitespace-nowrap"><?= $h($e->currency) ?> <?= $m2($e->amount) ?></td>
            <td class="px-6 py-3 font-mono text-[13px] text-slate-600"><?= $h($e->pnr ?: '—') ?></td>
            <td class="px-6 py-3 text-slate-600 text-[13px]"><?= $h($e->customer_name ?: '—') ?></td>
            <td class="px-6 py-3 text-slate-500 text-[13px]"><?= $h($e->reason ?: '—') ?></td>
            <td class="px-6 py-3"><?= cbrOutcomeBadge($e->outcome) ?></td>
            <td class="px-6 py-3 text-right whitespace-nowrap">
              <button onclick="editEntry(<?= (int)$e->id ?>)" class="text-slate-400 hover:text-primary p-1" title="Edit"><span class="material-symbols-outlined text-[18px]">edit</span></button>
              <button onclick="deleteEntry(<?= (int)$e->id ?>)" class="text-slate-400 hover:text-red-600 p-1" title="Delete"><span class="material-symbols-outlined text-[18px]">delete</span></button>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</main>

<div id="toast" class="fixed bottom-6 right-6 opacity-0 pointer-events-none px-4 py-3 rounded-xl bg-slate-900 text-white text-sm font-bold shadow-2xl z-50"></div>

<script>
const CSRF = <?= json_encode($csrf) ?>;
const CD   = <?= json_encode($chartData) ?>;
const COLORS = <?= json_encode(CentreService::COLORS) ?>;

// ── Period filter ──
document.querySelectorAll('.period-btn').forEach(b => b.addEventListener('click', () => {
  document.getElementById('periodInput').value = b.dataset.period;
  document.getElementById('customWrap').classList.toggle('hidden', b.dataset.period !== 'custom');
  document.getElementById('customWrap').classList.toggle('flex', b.dataset.period === 'custom');
  if (b.dataset.period !== 'custom') document.getElementById('filterForm').submit();
}));

// ── Charts ──
const RED='#dc2626', AMBER='#d97706';
new Chart(document.getElementById('barChart'), {
  type:'bar',
  data:{labels:CD.bar.labels, datasets:[
    {label:'Chargebacks', data:CD.bar.chargeback, backgroundColor:RED, borderRadius:6},
    {label:'Refunds', data:CD.bar.refund, backgroundColor:AMBER, borderRadius:6}]},
  options:{maintainAspectRatio:false, plugins:{legend:{position:'bottom'}}, scales:{y:{beginAtZero:true}}}
});
new Chart(document.getElementById('donutChart'), {
  type:'doughnut',
  data:{labels:['Chargebacks','Refunds'], datasets:[{data:[CD.donut.chargeback, CD.donut.refund], backgroundColor:[RED,AMBER], borderWidth:0}]},
  options:{maintainAspectRatio:false, cutout:'62%', plugins:{legend:{position:'bottom'}}}
});
new Chart(document.getElementById('trendChart'), {
  type:'line',
  data:{labels:CD.trend.labels, datasets:[
    {label:'Chargebacks', data:CD.trend.chargeback, borderColor:RED, backgroundColor:'rgba(220,38,38,.08)', fill:true, tension:.35},
    {label:'Refunds', data:CD.trend.refund, borderColor:AMBER, backgroundColor:'rgba(217,119,6,.08)', fill:true, tension:.35}]},
  options:{maintainAspectRatio:false, plugins:{legend:{position:'bottom'}}, scales:{y:{beginAtZero:true}}}
});

// ── Toast ──
let toastT;
function toast(msg, ok=true){
  const t=document.getElementById('toast'); t.textContent=msg;
  t.style.background = ok ? '#0f172a' : '#b91c1c';
  t.style.opacity='1'; t.style.transform='translateY(0)';
  clearTimeout(toastT); toastT=setTimeout(()=>{t.style.opacity='0';t.style.transform='translateY(8px)';},2200);
}

// ── Segmented controls ──
function paintKind(){
  document.querySelectorAll('.kind-btn').forEach(b=>{
    const on=b.dataset.kind===document.getElementById('f_kind').value;
    b.className='kind-btn seg flex-1 px-3 py-2 rounded-lg text-sm font-bold '+(on?(b.dataset.kind==='chargeback'?'bg-red-600 text-white':'bg-amber-500 text-white'):'text-slate-500');
  });
}
document.querySelectorAll('.kind-btn').forEach(b=>b.addEventListener('click',()=>{document.getElementById('f_kind').value=b.dataset.kind;paintKind();}));
function paintCentre(){
  document.querySelectorAll('.centre-btn').forEach(b=>{
    const on=b.dataset.centre===document.getElementById('f_centre').value;
    b.style.background = on ? (COLORS[b.dataset.centre]||'#163274') : '#fff';
    b.style.color = on ? '#fff' : '#64748b';
    b.style.borderColor = on ? (COLORS[b.dataset.centre]||'#163274') : '#e2e8f0';
  });
}
document.querySelectorAll('.centre-btn').forEach(b=>b.addEventListener('click',()=>{document.getElementById('f_centre').value=b.dataset.centre;paintCentre();}));
paintKind(); paintCentre();

// ── Add / edit submit ──
let added = 0;
function gv(id){return document.getElementById(id).value;}
document.getElementById('entryForm').addEventListener('submit', async (e)=>{
  e.preventDefault();
  const err=document.getElementById('formError'); err.classList.add('hidden');
  if(!gv('f_centre')){ err.textContent='Pick a centre.'; err.classList.remove('hidden'); return; }
  if(!(parseFloat(gv('f_amount'))>0)){ err.textContent='Enter an amount greater than 0.'; err.classList.remove('hidden'); return; }

  const id = gv('f_id');
  const url = id ? ('/chargebacks/'+id+'/update') : '/chargebacks';
  const body = new URLSearchParams({
    csrf_token:CSRF, centre:gv('f_centre'), kind:gv('f_kind'), event_date:gv('f_date'),
    amount:gv('f_amount'), currency:gv('f_currency'), pnr:gv('f_pnr'),
    customer_name:gv('f_customer'), reason:gv('f_reason'), outcome:gv('f_outcome')
  });
  try{
    const res=await fetch(url,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body});
    const d=await res.json();
    if(!d.success){ err.textContent=d.error||'Could not save.'; err.classList.remove('hidden'); return; }
    if(id){ replaceRow(d.entry); toast('Entry updated'); exitEdit(); }
    else { prependRow(d.entry); toast('Entry saved'); added++; showRefresh(); }
    // Keep centre/type/date/currency for rapid repeat entry; clear the rest.
    ['f_amount','f_pnr','f_customer','f_reason'].forEach(x=>document.getElementById(x).value='');
    document.getElementById('f_outcome').value='';
    document.getElementById('f_amount').focus();
  }catch(_){ err.textContent='Network error. Please try again.'; err.classList.remove('hidden'); }
});

function showRefresh(){ const r=document.getElementById('refreshAnalysis'); r.classList.remove('hidden'); r.classList.add('flex'); document.getElementById('addedCount').textContent=added; }

function rowHtml(en){
  const kindBadge = en.kind==='chargeback'
    ? '<span class="px-2 py-0.5 rounded-full text-[10px] font-extrabold bg-red-100 text-red-700">CHARGEBACK</span>'
    : '<span class="px-2 py-0.5 rounded-full text-[10px] font-extrabold bg-amber-100 text-amber-700">REFUND</span>';
  const oMap={won:'bg-emerald-100 text-emerald-700',lost:'bg-red-100 text-red-700',pending:'bg-slate-100 text-slate-600',processed:'bg-blue-100 text-blue-700'};
  const outcome = en.outcome ? '<span class="px-2 py-0.5 rounded-full text-[10px] font-bold '+(oMap[en.outcome]||'bg-slate-100 text-slate-600')+'">'+en.outcome.toUpperCase()+'</span>' : '<span class="text-slate-300">—</span>';
  const esc=s=>(s==null?'':String(s)).replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
  const amtCls = en.kind==='chargeback'?'text-red-600':'text-amber-600';
  return '<td class="px-6 py-3 text-slate-500 text-[13px] whitespace-nowrap">'+esc(en.event_date)+'</td>'
    +'<td class="px-6 py-3"><span class="px-2 py-0.5 rounded-full text-[10px] font-bold text-white" style="background:'+(en.centre_color||'#64748b')+'">'+esc(en.centre)+'</span></td>'
    +'<td class="px-6 py-3">'+kindBadge+'</td>'
    +'<td class="px-6 py-3 text-right font-bold '+amtCls+' whitespace-nowrap">'+esc(en.currency)+' '+Number(en.amount).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2})+'</td>'
    +'<td class="px-6 py-3 font-mono text-[13px] text-slate-600">'+esc(en.pnr||'—')+'</td>'
    +'<td class="px-6 py-3 text-slate-600 text-[13px]">'+esc(en.customer_name||'—')+'</td>'
    +'<td class="px-6 py-3 text-slate-500 text-[13px]">'+esc(en.reason||'—')+'</td>'
    +'<td class="px-6 py-3">'+outcome+'</td>'
    +'<td class="px-6 py-3 text-right whitespace-nowrap">'
    +'<button onclick="editEntry('+en.id+')" class="text-slate-400 hover:text-primary p-1" title="Edit"><span class="material-symbols-outlined text-[18px]">edit</span></button>'
    +'<button onclick="deleteEntry('+en.id+')" class="text-slate-400 hover:text-red-600 p-1" title="Delete"><span class="material-symbols-outlined text-[18px]">delete</span></button></td>';
}
function prependRow(en){
  document.getElementById('emptyRow')?.remove();
  const tr=document.createElement('tr'); tr.id='row-'+en.id; tr.className='hover:bg-slate-50 float-in';
  tr.setAttribute('data-json', JSON.stringify({id:en.id,centre:en.centre,kind:en.kind,event_date:en.event_date_iso||document.getElementById('f_date').value,amount:en.amount,currency:en.currency,pnr:en.pnr,customer_name:en.customer_name,reason:en.reason,outcome:en.outcome}));
  tr.innerHTML=rowHtml(en);
  document.getElementById('entryRows').prepend(tr);
}
function replaceRow(en){
  const tr=document.getElementById('row-'+en.id); if(!tr) return;
  tr.setAttribute('data-json', JSON.stringify({id:en.id,centre:en.centre,kind:en.kind,event_date:document.getElementById('f_date').value,amount:en.amount,currency:en.currency,pnr:en.pnr,customer_name:en.customer_name,reason:en.reason,outcome:en.outcome}));
  tr.innerHTML=rowHtml(en);
}

// ── Edit ──
function editEntry(id){
  const tr=document.getElementById('row-'+id); if(!tr) return;
  const en=JSON.parse(tr.getAttribute('data-json'));
  document.getElementById('f_id').value=en.id;
  document.getElementById('f_kind').value=en.kind; paintKind();
  document.getElementById('f_centre').value=en.centre; paintCentre();
  document.getElementById('f_date').value=en.event_date||'';
  document.getElementById('f_amount').value=en.amount;
  document.getElementById('f_currency').value=en.currency;
  document.getElementById('f_pnr').value=en.pnr||'';
  document.getElementById('f_customer').value=en.customer_name||'';
  document.getElementById('f_reason').value=en.reason||'';
  document.getElementById('f_outcome').value=en.outcome||'';
  document.getElementById('formTitle').textContent='Edit entry';
  document.getElementById('submitLabel').textContent='Update entry';
  document.getElementById('cancelEdit').classList.remove('hidden');
  document.getElementById('addCard').scrollIntoView({behavior:'smooth'});
}
function exitEdit(){
  document.getElementById('f_id').value='';
  document.getElementById('formTitle').textContent='Add an entry';
  document.getElementById('submitLabel').textContent='Save entry';
  document.getElementById('cancelEdit').classList.add('hidden');
}
document.getElementById('cancelEdit').addEventListener('click', exitEdit);

// ── Delete ──
async function deleteEntry(id){
  if(!confirm('Delete this entry?')) return;
  const res=await fetch('/chargebacks/'+id+'/delete',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({csrf_token:CSRF})});
  const d=await res.json();
  if(d.success){ document.getElementById('row-'+id)?.remove(); toast('Entry deleted'); added++; showRefresh(); }
  else toast(d.error||'Could not delete', false);
}
</script>
</body>
</html>
