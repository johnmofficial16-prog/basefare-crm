<?php
/**
 * Admin Monthly — Tab 2: Agent Detail
 * Variables inherited from admin_monthly.php scope:
 * $agents, $sessionMap, $dates, $summary, $month, $agentId, $btIcon
 */
$selAgent = null;
if ($agentId) {
    foreach ($agents as $a) { if ($a->id == $agentId) { $selAgent = $a; break; } }
}
?>
<div class="bg-white rounded-2xl shadow-sm overflow-hidden">
  <!-- Agent picker -->
  <div class="px-6 py-5 border-b border-gray-100 flex items-center gap-4">
    <span class="material-symbols-outlined text-primary text-2xl">person</span>
    <form method="GET" action="/attendance/admin/monthly" class="flex items-center gap-3 flex-1">
      <input type="hidden" name="month" value="<?= htmlspecialchars($month) ?>"/>
      <input type="hidden" name="tab" value="detail"/>
      <label class="text-sm font-bold text-on-surface-variant shrink-0">Select Agent:</label>
      <select name="agent_id" onchange="this.form.submit()"
              class="flex-1 max-w-xs px-4 py-2 rounded-xl bg-surface-container-low text-sm font-medium border-0 focus:ring-2 focus:ring-primary/20">
        <option value="">— Choose an agent —</option>
        <?php foreach ($agents as $a): ?>
        <option value="<?= $a->id ?>" <?= $agentId == $a->id ? 'selected' : '' ?>><?= htmlspecialchars($a->name) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if ($selAgent): ?>
      <a href="/attendance/admin/monthly/export?month=<?= htmlspecialchars($month) ?>"
         class="flex items-center gap-1.5 px-4 py-2 bg-emerald-600 text-white rounded-xl text-xs font-bold hover:bg-emerald-700 transition-all">
        <span class="material-symbols-outlined text-sm">download</span> CSV
      </a>
      <?php endif; ?>
    </form>
  </div>

  <?php if (!$selAgent): ?>
  <div class="py-16 text-center text-on-surface-variant">
    <span class="material-symbols-outlined text-5xl opacity-30 block mb-3">person_search</span>
    <p class="font-semibold">Select an agent to view their monthly detail</p>
  </div>
  <?php else: ?>
  <?php
    $agentSessions = $sessionMap[$selAgent->id] ?? [];
    $agSum         = $summary[$selAgent->id]    ?? [];
  ?>

  <!-- Agent summary strip -->
  <div class="px-6 py-4 bg-gradient-to-r from-primary/5 to-transparent border-b border-gray-100 grid grid-cols-5 gap-4">
    <?php
      $wh = floor(($agSum['work_mins']??0)/60); $wm = ($agSum['work_mins']??0)%60;
      $strips = [
        ['Days Present', $agSum['days_present']??0, 'text-green-700', 'bg-green-50'],
        ['Days Absent',  $agSum['days_absent']??0,  'text-red-600',   'bg-red-50'],
        ['Total Work',   $wh.'h '.$wm.'m',          'text-primary',   'bg-primary/5'],
        ['Late Count',   ($agSum['late_count']??0).'x', 'text-amber-700','bg-amber-50'],
        ['Flagged',      ($agSum['flagged_count']??0), 'text-red-700',  'bg-red-50'],
      ];
    ?>
    <?php foreach ($strips as [$lbl,$val,$tc,$bg]): ?>
    <div class="<?= $bg ?> rounded-xl p-3 text-center">
      <p class="text-xs text-on-surface-variant font-semibold mb-1"><?= $lbl ?></p>
      <p class="text-lg font-headline font-extrabold <?= $tc ?>"><?= $val ?></p>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Day-by-day table -->
  <table class="w-full text-sm">
    <thead>
      <tr class="bg-surface-container-low text-on-surface-variant text-xs font-bold uppercase tracking-wider">
        <th class="py-3 px-4 w-6"></th>
        <th class="py-3 px-5 text-left">Date</th>
        <th class="py-3 px-5 text-left">Clock In</th>
        <th class="py-3 px-5 text-left">Clock Out</th>
        <th class="py-3 px-5 text-right">Net Work</th>
        <th class="py-3 px-5 text-right">Breaks</th>
        <th class="py-3 px-5 text-right">Late</th>
        <th class="py-3 px-5 text-center">Status</th>
        <th class="py-3 px-5 text-center">Flags</th>
      </tr>
    </thead>
    <tbody>
    <?php $ri = 0; foreach ($dates as $d): ?>
    <?php
      $s       = $agentSessions[$d] ?? null;
      $dow     = date('D', strtotime($d));
      $isWknd  = in_array($dow, ['Sat','Sun']);
      if (!$s) {
          $rowBg = $isWknd ? 'bg-gray-50 opacity-50' : 'bg-white';
      } else {
          $flagCnt = $s->breaks->where('flagged',1)->count();
          $rowBg   = $flagCnt > 0 ? 'border-l-4 border-red-400 bg-white' : ($ri%2===0?'bg-white':'bg-surface-container-low/40');
      }
      $ri++;
    ?>
    <tr class="<?= $rowBg ?> hover:bg-blue-50/20 transition-colors <?= ($s && $s->breaks->isNotEmpty()) ? 'cursor-pointer' : '' ?>"
        <?= ($s && $s->breaks->isNotEmpty()) ? "onclick=\"toggleDR('dr-{$d}','dch-{$d}')\"" : '' ?>>
      <td class="py-2.5 px-3 text-center">
        <?php if ($s && $s->breaks->isNotEmpty()): ?>
        <span class="material-symbols-outlined text-sm text-on-surface-variant" id="dch-<?= $d ?>">expand_more</span>
        <?php endif; ?>
      </td>
      <td class="py-2.5 px-5 font-semibold <?= $isWknd ? 'text-gray-400' : '' ?>">
        <?= date('D, M j', strtotime($d)) ?>
      </td>
      <?php if (!$s): ?>
      <td colspan="7" class="py-2.5 px-5 text-gray-300 text-center">— Absent —</td>
      <?php else: ?>
      <td class="py-2.5 px-5"><?= date('g:i A', strtotime($s->clock_in)) ?></td>
      <td class="py-2.5 px-5"><?= $s->clock_out ? date('g:i A', strtotime($s->clock_out)) : '<span class="text-blue-500 font-semibold text-xs">Active</span>' ?></td>
      <td class="py-2.5 px-5 text-right font-semibold"><?= floor(($s->total_work_mins??0)/60) ?>h <?= ($s->total_work_mins??0)%60 ?>m</td>
      <td class="py-2.5 px-5 text-right"><?= $s->total_break_mins??0 ?>m</td>
      <td class="py-2.5 px-5 text-right <?= ($s->late_minutes??0)>0 ? 'text-red-600 font-bold' : 'text-gray-400' ?>">
        <?= ($s->late_minutes??0)>0 ? $s->late_minutes.'m' : '—' ?>
      </td>
      <td class="py-2.5 px-5 text-center">
        <?php $sc = match($s->status??'') { 'completed'=>'bg-green-100 text-green-800','auto_closed'=>'bg-amber-100 text-amber-800','active'=>'bg-blue-100 text-blue-800', default=>'bg-gray-100 text-gray-500' }; ?>
        <span class="inline-block px-2 py-0.5 rounded-full text-[10px] font-bold <?= $sc ?>"><?= ucfirst(str_replace('_',' ',$s->status??'')) ?></span>
      </td>
      <td class="py-2.5 px-5 text-center">
        <?php $fc=$s->breaks->where('flagged',1)->count(); ?>
        <?php if ($fc>0): ?>
        <span class="inline-flex items-center gap-0.5 px-2 py-0.5 rounded-full text-[10px] font-bold bg-red-100 text-red-700">🚩<?= $fc ?></span>
        <?php else: ?><span class="text-gray-300">—</span><?php endif; ?>
      </td>
      <?php endif; ?>
    </tr>
    <!-- Expandable breaks -->
    <?php if ($s && $s->breaks->isNotEmpty()): ?>
    <tr id="dr-<?= $d ?>" class="break-detail-row">
      <td colspan="9" class="p-0">
        <div class="bg-amber-50/60 px-12 py-4 border-t border-amber-100">
          <p class="text-[10px] font-bold text-amber-700 uppercase tracking-widest mb-3">Break Detail</p>
          <div class="flex flex-wrap gap-3">
            <?php foreach ($s->breaks as $b): ?>
            <?php $isFl = (bool)$b->flagged; ?>
            <div class="bg-white rounded-xl p-3 shadow-sm border <?= $isFl?'border-red-200':'border-gray-100' ?> min-w-[190px]">
              <div class="flex items-center gap-2 mb-1">
                <span><?= $btIcon[$b->break_type]??'⏸' ?></span>
                <span class="text-xs font-bold <?= $isFl?'text-red-600':'text-amber-700' ?>"><?= ucfirst($b->break_type) ?></span>
                <?php if ($isFl): ?>
                <span class="ml-auto text-[9px] px-1.5 py-0.5 bg-red-100 text-red-700 rounded font-bold">🚩 Flagged</span>
                <?php endif; ?>
              </div>
              <p class="text-[11px] text-on-surface-variant">
                <?= date('g:i A', strtotime($b->break_start)) ?> →
                <?= $b->break_end ? date('g:i A', strtotime($b->break_end)) : '<span class="text-blue-500">Still Open</span>' ?>
              </p>
              <?php if ($b->duration_mins): ?><p class="text-[11px] font-bold mt-1"><?= $b->duration_mins ?> min</p><?php endif; ?>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      </td>
    </tr>
    <?php endif; ?>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<script>
function toggleDR(rowId, chId) {
  const row = document.getElementById(rowId);
  const ch  = document.getElementById(chId);
  if (!row) return;
  row.classList.toggle('open');
  if (ch) ch.textContent = row.classList.contains('open') ? 'expand_less' : 'expand_more';
}
</script>
