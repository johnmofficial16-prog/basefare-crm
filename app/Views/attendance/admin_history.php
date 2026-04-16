<?php
/**
 * Admin Attendance History (P2 #12 + G5 Sidebar + G6 Expandable Breaks)
 * 
 * @var array  $sessions  From AttendanceService::getHistoricalData()
 * @var object $agents    Collection of User models (for dropdown filter)
 * @var string $date      Selected date
 * @var ?int   $agentId   Selected agent filter
 */
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<title>Attendance History - Base Fare CRM</title>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
<script src="https://cdn.tailwindcss.com?plugins=forms"></script>
<script>
tailwind.config={darkMode:"class",theme:{extend:{colors:{primary:"#163274","primary-container":"#314a8d",background:"#f8f9fa","surface-container-low":"#f3f4f5","on-surface":"#191c1d","on-surface-variant":"#434653"},fontFamily:{headline:["Manrope"],body:["Inter"],label:["Inter"]}}}}
</script>
<style>.material-symbols-outlined{font-variation-settings:'FILL' 0,'wght' 400,'GRAD' 0,'opsz' 24}</style>
</head>
<body class="bg-background font-body text-on-surface antialiased min-h-screen">

<!-- G5: Shared Admin Sidebar -->
<?php $activePage = 'history'; require __DIR__ . '/../partials/admin_sidebar.php'; ?>

<main class="ml-60 pt-8 pb-20 px-10">

  <h1 class="text-3xl font-headline font-extrabold text-primary tracking-tight mb-6 flex items-center justify-between">
    Attendance History
    <a id="btn-csv-attendance"
       href="/attendance/admin/export?date=<?= htmlspecialchars($date) ?>&agent_id=<?= $agentId ?? '' ?>"
       class="inline-flex items-center gap-2 text-sm font-bold bg-emerald-600 text-white px-4 py-2 rounded-xl hover:bg-emerald-700 transition-colors shadow-lg shadow-emerald-600/20"
       title="Download this day's attendance as CSV">
      <span class="material-symbols-outlined text-base">download</span> Export CSV
    </a>
  </h1>

  <!-- Filters -->
  <form method="GET" action="/attendance/admin/history" class="flex items-center gap-4 mb-8">
    <div>
      <label class="text-xs font-label font-bold text-on-surface-variant uppercase tracking-wider block mb-1">Date</label>
      <input type="date" name="date" value="<?= htmlspecialchars($date) ?>" class="px-4 py-2 rounded-lg bg-white text-sm font-medium border-0 shadow-sm focus:ring-2 focus:ring-primary/20"/>
    </div>
    <div>
      <label class="text-xs font-label font-bold text-on-surface-variant uppercase tracking-wider block mb-1">Agent</label>
      <select name="agent_id" class="px-4 py-2 rounded-lg bg-white text-sm font-medium border-0 shadow-sm focus:ring-2 focus:ring-primary/20 min-w-[200px]">
        <option value="">All Agents</option>
        <?php foreach ($agents as $a): ?>
        <option value="<?= $a->id ?>" <?= $agentId == $a->id ? 'selected' : '' ?>><?= htmlspecialchars($a->name) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="pt-5">
      <button type="submit" class="px-6 py-2 bg-gradient-to-r from-primary to-primary-container text-white rounded-lg font-bold text-sm hover:opacity-90 transition-all">
        <span class="material-symbols-outlined text-sm align-[-3px]">filter_list</span> Filter
      </button>
    </div>
  </form>

  <!-- Results Table -->
  <div class="bg-white rounded-2xl shadow-sm shadow-blue-900/5 overflow-hidden">
    <table class="w-full text-sm">
      <thead>
        <tr class="bg-surface-container-low text-on-surface-variant font-label font-semibold text-xs uppercase tracking-wider">
          <th class="py-3 px-6 text-left w-6"></th>
          <th class="py-3 px-6 text-left">Agent</th>
          <th class="py-3 px-6 text-left">Clock In</th>
          <th class="py-3 px-6 text-left">Clock Out</th>
          <th class="py-3 px-6 text-right">Net Work</th>
          <th class="py-3 px-6 text-right">Break Time</th>
          <th class="py-3 px-6 text-right">Late</th>
          <th class="py-3 px-6 text-center">Status</th>
          <th class="py-3 px-6 text-center">Flags</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($sessions)): ?>
        <tr><td colspan="9" class="py-8 text-center text-on-surface-variant">No records found for <?= date('l, F j, Y', strtotime($date)) ?>.</td></tr>
        <?php else: ?>
        <?php foreach ($sessions as $i => $s): ?>
        <?php $hasBreaks = !empty($s['breaks']); ?>
        <tr class="<?= $i % 2 === 0 ? 'bg-white' : 'bg-surface-container-low/50' ?> hover:bg-blue-50/30 transition-colors <?= $hasBreaks ? 'cursor-pointer' : '' ?>"
            <?php if ($hasBreaks): ?>onclick="toggleBreakRow('break-<?= $i ?>')"<?php endif; ?>>
          <td class="py-3 px-3 text-center">
            <?php if ($hasBreaks): ?>
            <span class="material-symbols-outlined text-sm text-on-surface-variant break-chevron" id="chevron-<?= $i ?>">expand_more</span>
            <?php endif; ?>
          </td>
          <td class="py-3 px-6 font-semibold"><?= htmlspecialchars($s['user']['name'] ?? 'Unknown') ?></td>
          <td class="py-3 px-6"><?= date('g:i A', strtotime($s['clock_in'])) ?></td>
          <td class="py-3 px-6"><?= $s['clock_out'] ? date('g:i A', strtotime($s['clock_out'])) : '—' ?></td>
          <td class="py-3 px-6 text-right font-semibold"><?= floor(($s['total_work_mins'] ?? 0) / 60) ?>h <?= ($s['total_work_mins'] ?? 0) % 60 ?>m</td>
          <td class="py-3 px-6 text-right"><?= $s['total_break_mins'] ?? 0 ?>m</td>
          <td class="py-3 px-6 text-right <?= ($s['late_minutes'] ?? 0) > 0 ? 'text-red-600 font-bold' : '' ?>"><?= ($s['late_minutes'] ?? 0) > 0 ? $s['late_minutes'] . 'm' : '—' ?></td>
          <td class="py-3 px-6 text-center">
            <?php
              $sc = match($s['status'] ?? '') {
                'completed' => 'bg-green-100 text-green-800',
                'auto_closed' => 'bg-amber-100 text-amber-800',
                'active' => 'bg-blue-100 text-blue-800',
                default => 'bg-gray-100 text-gray-600',
              };
            ?>
            <span class="inline-block px-3 py-1 rounded-full text-xs font-bold <?= $sc ?>"><?= ucfirst(str_replace('_', ' ', $s['status'] ?? 'unknown')) ?></span>
          </td>
          <td class="py-3 px-6 text-center">
            <?php 
              $flagged = 0;
              foreach ($s['breaks'] ?? [] as $b) { if ($b['flagged'] ?? false) $flagged++; }
              if ($flagged > 0): 
            ?>
            <span class="inline-block px-2 py-1 rounded-full text-xs font-bold bg-red-100 text-red-700">🚩 <?= $flagged ?></span>
            <?php else: ?>
            <span class="text-gray-400">—</span>
            <?php endif; ?>
          </td>
        </tr>
        <!-- G6: Expandable Break Details Row -->
        <?php if ($hasBreaks): ?>
        <tr id="break-<?= $i ?>" class="hidden">
          <td colspan="9" class="p-0">
            <div class="bg-amber-50/50 px-12 py-4 border-t border-amber-100">
              <p class="text-xs font-label font-bold text-amber-800 uppercase tracking-wider mb-3">Break Details</p>
              <div class="grid grid-cols-4 gap-3">
                <?php foreach ($s['breaks'] as $b): ?>
                <div class="bg-white rounded-lg p-3 shadow-sm border <?= ($b['flagged'] ?? false) ? 'border-red-200' : 'border-amber-100' ?>">
                  <div class="flex items-center gap-2 mb-1">
                    <span class="text-xs font-bold <?= ($b['flagged'] ?? false) ? 'text-red-600' : 'text-amber-700' ?>">
                      <?= ucfirst($b['break_type'] ?? 'break') ?>
                      <?php if ($b['flagged'] ?? false): ?> 🚩<?php endif; ?>
                    </span>
                  </div>
                  <p class="text-[11px] text-on-surface-variant">
                    <?= date('g:i A', strtotime($b['break_start'])) ?>
                    → <?= isset($b['break_end']) ? date('g:i A', strtotime($b['break_end'])) : '<span class="text-red-500 font-bold">Still Open</span>' ?>
                  </p>
                  <p class="text-[11px] font-semibold mt-1">
                    <?= isset($b['duration_mins']) ? $b['duration_mins'] . ' min' : '—' ?>
                  </p>
                </div>
                <?php endforeach; ?>
              </div>
            </div>
          </td>
        </tr>
        <?php endif; ?>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

</main>

<script>
function toggleBreakRow(id) {
  const row = document.getElementById(id);
  if (!row) return;
  const idx = id.replace('break-', '');
  const chevron = document.getElementById('chevron-' + idx);
  row.classList.toggle('hidden');
  if (chevron) {
    chevron.textContent = row.classList.contains('hidden') ? 'expand_more' : 'expand_less';
  }
}
</script>
</body>
</html>
