<?php
/**
 * Admin Monthly Attendance Report
 * @var array  $report      From AttendanceService::getMonthlyReport()
 * @var object $agents      Eloquent collection of User models
 * @var string $month       'YYYY-MM'
 * @var string $activeTab   'grid' | 'detail' | 'payroll'
 * @var ?int   $agentId     Selected agent for detail tab
 */
$agents      = $report['agents'];
$dates       = $report['dates'];
$sessionMap  = $report['session_map'];
$summary     = $report['summary'];
$daysInMonth = $report['days_in_month'];
$dateFrom    = $report['date_from'];
$dateTo      = $report['date_to'];
$activeTab   = $activeTab ?? 'grid';
$agentId     = $agentId   ?? null;

// Month nav helpers
[$ym, $mm]   = explode('-', $month);
$prevMonth   = date('Y-m', mktime(0,0,0,(int)$mm-1,1,(int)$ym));
$nextMonth   = date('Y-m', mktime(0,0,0,(int)$mm+1,1,(int)$ym));
$monthLabel  = date('F Y', mktime(0,0,0,(int)$mm,1,(int)$ym));

// Break type icons
$btIcon = ['lunch'=>'🍽','short'=>'☕','washroom'=>'🚻'];
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
<meta charset="utf-8"/>
<meta content="width=device-width,initial-scale=1.0" name="viewport"/>
<title>Monthly Report — <?= htmlspecialchars($monthLabel) ?> — Base Fare CRM</title>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
<script src="https://cdn.tailwindcss.com?plugins=forms"></script>
<script>
tailwind.config={darkMode:"class",theme:{extend:{colors:{primary:"#163274","primary-container":"#314a8d",background:"#f8f9fa","surface-container-low":"#f3f4f5","on-surface":"#191c1d","on-surface-variant":"#434653"},fontFamily:{headline:["Manrope"],body:["Inter"],label:["Inter"]}}}};
</script>
<style>
.material-symbols-outlined{font-variation-settings:'FILL' 0,'wght' 400,'GRAD' 0,'opsz' 24}
.tab-panel{display:none}.tab-panel.active{display:block}
.break-detail-row{display:none}.break-detail-row.open{display:table-row}
</style>
</head>
<body class="bg-background font-body text-on-surface antialiased min-h-screen">

<?php $activePage='monthly'; require __DIR__.'/../partials/admin_sidebar.php'; ?>

<main class="ml-60 pt-8 pb-20 px-10">

  <!-- Header -->
  <div class="flex items-center justify-between mb-8">
    <div>
      <h1 class="text-3xl font-headline font-extrabold text-primary tracking-tight">Monthly Report</h1>
      <p class="text-sm text-on-surface-variant mt-1">Full attendance record · Pay period 1–<?= $daysInMonth ?></p>
    </div>
    <!-- Month Navigator -->
    <div class="flex items-center gap-3">
      <a href="?month=<?= $prevMonth ?>&tab=<?= $activeTab ?>"
         class="w-9 h-9 rounded-xl bg-white shadow-sm flex items-center justify-center hover:bg-primary hover:text-white transition-all">
        <span class="material-symbols-outlined text-lg">chevron_left</span>
      </a>
      <form method="GET" action="/attendance/admin/monthly" class="flex items-center gap-2">
        <input type="month" name="month" value="<?= htmlspecialchars($month) ?>"
               onchange="this.form.submit()"
               class="px-4 py-2 rounded-xl bg-white shadow-sm font-headline font-bold text-primary text-sm border-0 focus:ring-2 focus:ring-primary/20"/>
        <input type="hidden" name="tab" value="<?= htmlspecialchars($activeTab) ?>"/>
      </form>
      <a href="?month=<?= $nextMonth ?>&tab=<?= $activeTab ?>"
         class="w-9 h-9 rounded-xl bg-white shadow-sm flex items-center justify-center hover:bg-primary hover:text-white transition-all">
        <span class="material-symbols-outlined text-lg">chevron_right</span>
      </a>
      <a href="/attendance/admin/monthly/export?month=<?= htmlspecialchars($month) ?>"
         class="flex items-center gap-2 px-4 py-2 bg-emerald-600 text-white rounded-xl text-sm font-bold hover:bg-emerald-700 transition-all shadow-lg shadow-emerald-600/20">
        <span class="material-symbols-outlined text-base">download</span> Export CSV
      </a>
    </div>
  </div>

  <!-- Tabs -->
  <div class="flex gap-1 mb-6 bg-white rounded-2xl p-1.5 shadow-sm w-fit">
    <?php foreach (['grid'=>['grid_view','Grid View'],'detail'=>['person','Agent Detail'],'payroll'=>['summarize','Payroll Summary']] as $tab=>[$icon,$label]): ?>
    <button onclick="switchTab('<?= $tab ?>')" id="tab-btn-<?= $tab ?>"
            class="flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-bold transition-all <?= $activeTab===$tab ? 'bg-primary text-white shadow-md' : 'text-on-surface-variant hover:text-primary' ?>">
      <span class="material-symbols-outlined text-base"><?= $icon ?></span><?= $label ?>
    </button>
    <?php endforeach; ?>
  </div>

  <!-- ═══════════════════════════════════════════════════════ -->
  <!-- TAB 1: GRID VIEW                                        -->
  <!-- ═══════════════════════════════════════════════════════ -->
  <div id="panel-grid" class="tab-panel <?= $activeTab==='grid'?'active':'' ?>">
    <div class="bg-white rounded-2xl shadow-sm overflow-hidden">
      <!-- Scrollable grid wrapper -->
      <div class="overflow-x-auto">
        <table class="text-xs min-w-max w-full border-collapse">
          <thead>
            <tr class="bg-primary text-white">
              <th class="sticky left-0 z-10 bg-primary py-3 px-4 text-left font-bold min-w-[160px]">Agent</th>
              <?php foreach ($dates as $d): ?>
              <?php $dow = date('D', strtotime($d)); $dom = (int)date('j', strtotime($d)); ?>
              <th class="py-2 px-1.5 text-center font-semibold min-w-[56px] <?= in_array($dow,['Sat','Sun'])?'opacity-60':'' ?>">
                <div class="text-[9px] opacity-70"><?= $dow ?></div>
                <div><?= $dom ?></div>
              </th>
              <?php endforeach; ?>
              <th class="py-3 px-4 text-right font-bold min-w-[80px]">Total</th>
              <th class="py-3 px-4 text-right font-bold min-w-[60px]">Late</th>
              <th class="py-3 px-4 text-center font-bold min-w-[60px]">Flags</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($agents as $ai => $agent): ?>
          <?php $agSum = $summary[$agent->id] ?? []; ?>
          <tr class="<?= $ai%2===0?'bg-white':'bg-surface-container-low/40' ?> hover:bg-blue-50/20">
            <td class="sticky left-0 z-10 <?= $ai%2===0?'bg-white':'bg-gray-50' ?> py-3 px-4 font-semibold border-r border-gray-100">
              <div class="flex items-center gap-2">
                <div class="w-7 h-7 rounded-full bg-primary/15 flex items-center justify-center text-primary font-bold text-[11px] shrink-0">
                  <?= strtoupper(substr($agent->name,0,1)) ?>
                </div>
                <span class="truncate max-w-[110px]" title="<?= htmlspecialchars($agent->name) ?>"><?= htmlspecialchars($agent->name) ?></span>
              </div>
            </td>
            <?php foreach ($dates as $d): ?>
            <?php
              $s       = $sessionMap[$agent->id][$d] ?? null;
              $dow2    = date('D', strtotime($d));
              $isWknd  = in_array($dow2, ['Sat','Sun']);
              $isLate  = $s && ($s->late_minutes ?? 0) > 0;
              $hasFl   = $s && $s->breaks->where('flagged',1)->count() > 0;
              if (!$s) {
                  $cellBg = $isWknd ? 'bg-gray-50 text-gray-300' : 'bg-white text-gray-300';
                  $cellVal = '—';
              } elseif ($isLate) {
                  $cellBg  = 'bg-red-50 text-red-700';
                  $cellVal = floor(($s->total_work_mins??0)/60).'h'.($s->total_work_mins??0)%60.'m';
              } else {
                  $cellBg  = 'bg-green-50 text-green-800';
                  $cellVal = floor(($s->total_work_mins??0)/60).'h'.($s->total_work_mins??0)%60.'m';
              }
            ?>
            <td class="py-1.5 px-1 text-center <?= $cellBg ?> <?= $hasFl?'ring-1 ring-inset ring-red-300':'' ?> border-r border-gray-50 font-medium">
              <?php if ($s): ?>
              <a href="/attendance/admin/history?date=<?= $d ?>&agent_id=<?= $agent->id ?>"
                 class="block hover:underline" title="<?= htmlspecialchars($agent->name) ?> — <?= $d ?>">
                <?= $cellVal ?>
                <?= $hasFl ? '<span class="text-[8px]">🚩</span>' : '' ?>
              </a>
              <?php else: ?>
              <?= $cellVal ?>
              <?php endif; ?>
            </td>
            <?php endforeach; ?>
            <!-- Totals -->
            <?php $wh = floor(($agSum['work_mins']??0)/60); $wm = ($agSum['work_mins']??0)%60; ?>
            <td class="py-3 px-4 text-right font-bold text-primary"><?= $wh ?>h<?= $wm ?>m</td>
            <td class="py-3 px-4 text-right <?= ($agSum['late_count']??0)>0?'text-red-600 font-bold':'text-gray-400' ?>">
              <?= ($agSum['late_count']??0)>0 ? $agSum['late_count'].'x' : '—' ?>
            </td>
            <td class="py-3 px-4 text-center">
              <?php if (($agSum['flagged_count']??0)>0): ?>
              <span class="inline-flex items-center gap-0.5 px-2 py-0.5 rounded-full text-[10px] font-bold bg-red-100 text-red-700">
                🚩<?= $agSum['flagged_count'] ?>
              </span>
              <?php else: ?>
              <span class="text-gray-300">—</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="px-5 py-3 border-t border-gray-100 flex items-center gap-4 text-[10px] text-on-surface-variant">
        <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded bg-green-100 inline-block"></span>On time</span>
        <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded bg-red-100 inline-block"></span>Late</span>
        <span class="flex items-center gap-1.5"><span class="text-[10px]">🚩</span>Flagged break</span>
        <span class="flex items-center gap-1.5"><span class="text-gray-300">—</span>Absent / no session</span>
        <span class="ml-auto">Click any cell to view that day's detail</span>
      </div>
    </div>
  </div>

  <!-- ═══════════════════════════════════════════════════════ -->
  <!-- TAB 2 & 3 are in Part 2 of this file                   -->
  <!-- ═══════════════════════════════════════════════════════ -->
  <div id="panel-detail"  class="tab-panel <?= $activeTab==='detail'?'active':'' ?>">
    <?php require __DIR__.'/admin_monthly_detail.php'; ?>
  </div>
  <div id="panel-payroll" class="tab-panel <?= $activeTab==='payroll'?'active':'' ?>">
    <?php require __DIR__.'/admin_monthly_payroll.php'; ?>
  </div>

</main>

<script>
const INIT_TAB = '<?= addslashes($activeTab) ?>';
function switchTab(tab) {
  document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('[id^="tab-btn-"]').forEach(b => {
    b.classList.remove('bg-primary','text-white','shadow-md');
    b.classList.add('text-on-surface-variant');
  });
  const panel = document.getElementById('panel-' + tab);
  const btn   = document.getElementById('tab-btn-' + tab);
  if (panel) panel.classList.add('active');
  if (btn)   { btn.classList.add('bg-primary','text-white','shadow-md'); btn.classList.remove('text-on-surface-variant'); }
  // Update URL without reload
  const url = new URL(window.location);
  url.searchParams.set('tab', tab);
  window.history.replaceState({}, '', url);
}
// Restore active tab on load
switchTab(INIT_TAB);
</script>
</body>
</html>
