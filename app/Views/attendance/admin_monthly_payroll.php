<?php
/**
 * Admin Monthly — Tab 3: Payroll Summary
 * Variables inherited from admin_monthly.php scope:
 * $agents, $summary, $month, $daysInMonth, $sessionMap
 */
?>
<div class="bg-white rounded-2xl shadow-sm overflow-hidden">
  <div class="px-6 py-5 border-b border-gray-100 flex items-center justify-between">
    <div>
      <h2 class="text-base font-headline font-extrabold text-primary flex items-center gap-2">
        <span class="material-symbols-outlined">summarize</span>
        Payroll Summary — <?= date('F Y', mktime(0,0,0,(int)explode('-',$month)[1],1,(int)explode('-',$month)[0])) ?>
      </h2>
      <p class="text-xs text-on-surface-variant mt-0.5">Pay period: 1st – <?= $daysInMonth ?>th · No automatic deductions — admin review only.</p>
    </div>
    <a href="/attendance/admin/monthly/export?month=<?= htmlspecialchars($month) ?>"
       class="flex items-center gap-2 px-4 py-2 bg-emerald-600 text-white rounded-xl text-sm font-bold hover:bg-emerald-700 transition-all shadow-lg shadow-emerald-600/20">
      <span class="material-symbols-outlined text-base">download</span> Export CSV
    </a>
  </div>

  <table class="w-full text-sm">
    <thead>
      <tr class="bg-surface-container-low text-on-surface-variant text-xs font-bold uppercase tracking-wider">
        <th class="py-3 px-6 text-left">Agent</th>
        <th class="py-3 px-5 text-center">Days Present</th>
        <th class="py-3 px-5 text-center">Days Absent</th>
        <th class="py-3 px-5 text-right">Total Work Hrs</th>
        <th class="py-3 px-5 text-right">Total Break</th>
        <th class="py-3 px-5 text-right">Late Arrivals</th>
        <th class="py-3 px-5 text-right">Late (mins total)</th>
        <th class="py-3 px-5 text-center">Flagged Breaks</th>
        <th class="py-3 px-5 text-center">Admin Notes</th>
      </tr>
    </thead>
    <tbody>
    <?php $totWork=0; $totBreak=0; $totLate=0; $totFlags=0; $totPresent=0; $totAbsent=0; ?>
    <?php foreach ($agents as $ai => $agent): ?>
    <?php
      $sum = $summary[$agent->id] ?? [];
      $wh  = floor(($sum['work_mins']??0)/60);
      $wm  = ($sum['work_mins']??0)%60;
      $bh  = floor(($sum['break_mins']??0)/60);
      $bm  = ($sum['break_mins']??0)%60;
      $totWork    += $sum['work_mins']??0;
      $totBreak   += $sum['break_mins']??0;
      $totLate    += $sum['late_mins']??0;
      $totFlags   += $sum['flagged_count']??0;
      $totPresent += $sum['days_present']??0;
      $totAbsent  += $sum['days_absent']??0;
    ?>
    <tr class="<?= $ai%2===0?'bg-white':'bg-surface-container-low/40' ?> hover:bg-blue-50/20 transition-colors">
      <td class="py-3.5 px-6">
        <div class="flex items-center gap-3">
          <div class="w-8 h-8 rounded-full bg-primary/10 flex items-center justify-center text-primary font-bold text-xs shrink-0">
            <?= strtoupper(substr($agent->name,0,1)) ?>
          </div>
          <div>
            <p class="font-semibold text-on-surface"><?= htmlspecialchars($agent->name) ?></p>
            <?php 
              $displayRole = $agent->role;
              if (stripos($agent->name, 'thomas') !== false) {
                  $displayRole = 'manager';
              }
            ?>
            <p class="text-[10px] text-on-surface-variant capitalize"><?= htmlspecialchars($displayRole) ?></p>
          </div>
        </div>
      </td>
      <td class="py-3.5 px-5 text-center">
        <span class="inline-block px-3 py-1 rounded-full text-xs font-bold bg-green-100 text-green-800">
          <?= $sum['days_present']??0 ?>
        </span>
      </td>
      <td class="py-3.5 px-5 text-center">
        <?php $ab = $sum['days_absent']??0; ?>
        <span class="inline-block px-3 py-1 rounded-full text-xs font-bold <?= $ab>0?'bg-red-100 text-red-700':'bg-gray-100 text-gray-400' ?>">
          <?= $ab ?>
        </span>
      </td>
      <td class="py-3.5 px-5 text-right font-bold text-primary"><?= $wh ?>h <?= $wm ?>m</td>
      <td class="py-3.5 px-5 text-right text-on-surface-variant"><?= $bh ?>h <?= $bm ?>m</td>
      <td class="py-3.5 px-5 text-right <?= ($sum['late_count']??0)>0?'text-amber-700 font-bold':'text-gray-400' ?>">
        <?= ($sum['late_count']??0)>0 ? $sum['late_count'].'x' : '—' ?>
      </td>
      <td class="py-3.5 px-5 text-right <?= ($sum['late_mins']??0)>0?'text-red-600 font-bold':'text-gray-400' ?>">
        <?= ($sum['late_mins']??0)>0 ? $sum['late_mins'].'m' : '—' ?>
      </td>
      <td class="py-3.5 px-5 text-center">
        <?php $fl = $sum['flagged_count']??0; ?>
        <?php if ($fl>0): ?>
        <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs font-bold bg-red-100 text-red-700">
          🚩 <?= $fl ?>
        </span>
        <?php else: ?><span class="text-gray-300">—</span><?php endif; ?>
      </td>
      <td class="py-3.5 px-5 text-center">
        <a href="/attendance/admin/history?agent_id=<?= $agent->id ?>&date=<?= date('Y-m-d') ?>"
           class="text-xs text-primary font-semibold hover:underline flex items-center justify-center gap-1">
          <span class="material-symbols-outlined text-sm">open_in_new</span>View
        </a>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
    <!-- Totals footer -->
    <tfoot>
      <tr class="bg-primary text-white text-sm font-bold">
        <td class="py-4 px-6">Totals (<?= count($agents) ?> agents)</td>
        <td class="py-4 px-5 text-center"><?= $totPresent ?></td>
        <td class="py-4 px-5 text-center"><?= $totAbsent ?></td>
        <td class="py-4 px-5 text-right"><?= floor($totWork/60) ?>h <?= $totWork%60 ?>m</td>
        <td class="py-4 px-5 text-right"><?= floor($totBreak/60) ?>h <?= $totBreak%60 ?>m</td>
        <td class="py-4 px-5 text-right" colspan="2"><?= $totLate ?>m late total</td>
        <td class="py-4 px-5 text-center"><?= $totFlags ?> flags</td>
        <td></td>
      </tr>
    </tfoot>
  </table>

  <div class="px-6 py-4 border-t border-gray-100 bg-amber-50/40 flex items-start gap-3">
    <span class="material-symbols-outlined text-amber-600 text-lg shrink-0 mt-0.5">info</span>
    <p class="text-xs text-amber-800">
      This summary is for <strong>review purposes only</strong>. 
      Deductions and final payroll calculations are handled manually by the admin.
      Export CSV to share with payroll.
    </p>
  </div>
</div>
