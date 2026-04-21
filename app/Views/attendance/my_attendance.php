<?php
/**
 * My Attendance — Agent's own attendance history
 *
 * @var array  $history       From AttendanceService::getAgentHistory()
 * @var string $preset        Active preset: '7d', '30d', '90d', 'custom'
 * @var string $dateFrom
 * @var string $dateTo
 */
$sessions     = $history['sessions'];
$summary      = $history['summary'];
$abuseLogMap  = $history['abuse_log_map'];
$dateFrom     = $history['date_from'];
$dateTo       = $history['date_to'];
$preset       = $preset ?? '7d';
$userName     = $_SESSION['user_name'] ?? 'Agent';

$totalHours = floor($summary['total_work_mins'] / 60);
$totalMins  = $summary['total_work_mins'] % 60;

// Today's session (if any)
$todayDate   = date('Y-m-d');
$todaySession = null;
foreach ($sessions as $s) {
    if ($s->date === $todayDate) { $todaySession = $s; break; }
}
// If today is outside the filtered range, fetch it separately
if (!$todaySession) {
    $todaySession = \App\Models\AttendanceSession::forUser($_SESSION['user_id'])
        ->where('date', $todayDate)
        ->with('breaks')
        ->first();
}

$breakTypeIcon  = ['lunch' => '🍽', 'short' => '☕', 'washroom' => '🚻'];
$breakTypeColor = ['lunch' => 'blue', 'short' => 'purple', 'washroom' => 'slate'];
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<title>My Attendance - Base Fare CRM</title>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
<script src="https://cdn.tailwindcss.com?plugins=forms"></script>
<script>
tailwind.config={darkMode:"class",theme:{extend:{colors:{primary:"#163274","primary-container":"#314a8d",background:"#f8f9fa","surface-container-low":"#f3f4f5","surface-container":"#edeeef","on-surface":"#191c1d","on-surface-variant":"#434653"},fontFamily:{headline:["Manrope"],body:["Inter"],label:["Inter"]}}}}
</script>
<style>
.material-symbols-outlined{font-variation-settings:'FILL' 0,'wght' 400,'GRAD' 0,'opsz' 24}
.break-row{display:none}.break-row.open{display:table-row}
</style>
</head>
<?php $role = $_SESSION['role'] ?? 'agent'; ?>
<body class="bg-background font-body text-on-surface antialiased min-h-screen">

<?php if (in_array($role, ['admin', 'manager'])): ?>
  <?php $activePage = 'attendance'; require __DIR__ . '/../partials/admin_sidebar.php'; ?>
<?php else: ?>
  <?php $activePage = 'attendance'; require __DIR__ . '/../partials/agent_sidebar.php'; ?>
<?php endif; ?>

<main class="ml-60 pt-8 pb-20 px-8 max-w-6xl">

  <div class="flex items-center justify-between mb-8">
    <div>
      <h1 class="text-3xl font-headline font-extrabold text-primary tracking-tight">My Attendance</h1>
      <p class="text-sm text-on-surface-variant mt-1">Your shift history, breaks, and attendance record.</p>
    </div>
    <a href="/dashboard" class="text-sm text-primary font-semibold hover:underline flex items-center gap-1">
      <span class="material-symbols-outlined text-sm">arrow_back</span> Dashboard
    </a>
  </div>

  <?php if ($todaySession): ?>
  <!-- ── Today's Shift Card ─────────────────────────────────── -->
  <?php
    $clockInTs    = strtotime($todaySession->clock_in);
    $schedStart   = $todaySession->scheduled_start ?? '09:00:00';
    $schedEnd     = $todaySession->scheduled_end   ?? '18:00:00';
    $schedStartTs = strtotime(date('Y-m-d') . ' ' . $schedStart);
    $schedEndTs   = strtotime(date('Y-m-d') . ' ' . $schedEnd);
    if ($schedEndTs <= $schedStartTs) $schedEndTs += 86400; // overnight
    $shiftDuration = max(1, $schedEndTs - $schedStartTs);
    $elapsedSecs   = time() - $schedStartTs;
    $progressPct   = min(100, max(0, round($elapsedSecs / $shiftDuration * 100)));
    $isActive      = $todaySession->status === 'active';
    $clockOutTime  = $todaySession->clock_out;
  ?>
  <div class="bg-gradient-to-br from-primary to-primary-container rounded-2xl p-6 mb-8 text-white shadow-xl shadow-primary/30">
    <div class="flex items-start justify-between mb-4">
      <div>
        <p class="text-white/70 text-xs font-bold uppercase tracking-wider mb-1">Today — <?= date('l, F j') ?></p>
        <div class="flex items-center gap-3">
          <span class="text-2xl font-headline font-extrabold"><?= date('g:i A', $clockInTs) ?></span>
          <span class="text-white/50">→</span>
          <?php if ($clockOutTime): ?>
            <span class="text-2xl font-headline font-extrabold"><?= date('g:i A', strtotime($clockOutTime)) ?></span>
          <?php else: ?>
            <span id="live-clock" class="text-2xl font-headline font-extrabold font-mono tracking-widest">--:--:--</span>
          <?php endif; ?>
        </div>
        <?php if ($todaySession->late_minutes > 0): ?>
        <span class="inline-block mt-1 px-2 py-0.5 bg-red-400/30 text-red-100 rounded text-xs font-bold">
          <?= $todaySession->late_minutes ?>m late
        </span>
        <?php else: ?>
        <span class="inline-block mt-1 px-2 py-0.5 bg-green-400/30 text-green-100 rounded text-xs font-bold">On Time</span>
        <?php endif; ?>
      </div>
      <div class="text-right">
        <p class="text-white/70 text-xs font-bold uppercase tracking-wider">Net Work Today</p>
        <?php
          $workMins = $todaySession->total_work_mins ?? 0;
          if ($isActive && !$clockOutTime) {
              $breakMins = (int) \App\Models\AttendanceBreak::where('session_id', $todaySession->id)->whereNotNull('break_end')->sum('duration_mins');
              $workMins  = max(0, (int)round((time() - $clockInTs) / 60) - $breakMins);
          }
        ?>
        <p class="text-3xl font-headline font-extrabold"><?= floor($workMins/60) ?>h <?= $workMins%60 ?>m</p>
      </div>
    </div>

    <!-- Shift progress bar -->
    <div class="mb-4">
      <div class="flex justify-between text-xs text-white/60 mb-1">
        <span><?= date('g:i A', $schedStartTs) ?></span>
        <span><?= date('g:i A', $schedEndTs) ?></span>
      </div>
      <div class="w-full bg-white/20 rounded-full h-2">
        <div class="bg-white rounded-full h-2 transition-all" style="width:<?= $progressPct ?>%"></div>
      </div>
    </div>

    <!-- Today's breaks -->
    <?php if ($todaySession->breaks->isNotEmpty()): ?>
    <div class="flex flex-wrap gap-2">
      <?php foreach ($todaySession->breaks as $b): ?>
      <?php
        $icon  = $breakTypeIcon[$b->break_type] ?? '⏸';
        $isFl  = $b->flagged;
      ?>
      <div class="flex items-center gap-1.5 px-3 py-1.5 <?= $isFl ? 'bg-red-400/30 border border-red-300/40' : 'bg-white/15' ?> rounded-lg text-xs">
        <span><?= $icon ?></span>
        <span class="font-semibold"><?= ucfirst($b->break_type) ?></span>
        <span class="text-white/60"><?= date('g:i', strtotime($b->break_start)) ?><?= $b->break_end ? '→'.date('g:i A', strtotime($b->break_end)) : ' (active)' ?></span>
        <?php if ($b->duration_mins): ?><span class="font-bold"><?= $b->duration_mins ?>m</span><?php endif; ?>
        <?php if ($isFl): ?><span class="text-red-200 font-bold">🚩</span><?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- ── Summary Cards ─────────────────────────────────────── -->
  <div class="grid grid-cols-4 gap-5 mb-8">
    <div class="bg-white rounded-2xl p-5 shadow-sm">
      <div class="flex items-center justify-between mb-2">
        <span class="material-symbols-outlined text-primary text-2xl">event_available</span>
        <span class="text-3xl font-headline font-extrabold text-primary"><?= $summary['total_sessions'] ?></span>
      </div>
      <p class="text-sm font-semibold text-on-surface-variant">Sessions</p>
    </div>
    <div class="bg-white rounded-2xl p-5 shadow-sm">
      <div class="flex items-center justify-between mb-2">
        <span class="material-symbols-outlined text-green-600 text-2xl">schedule</span>
        <span class="text-3xl font-headline font-extrabold text-green-700"><?= $totalHours ?>h<?= $totalMins ?>m</span>
      </div>
      <p class="text-sm font-semibold text-on-surface-variant">Total Work</p>
    </div>
    <div class="bg-white rounded-2xl p-5 shadow-sm">
      <div class="flex items-center justify-between mb-2">
        <span class="material-symbols-outlined text-red-500 text-2xl">alarm</span>
        <span class="text-3xl font-headline font-extrabold text-red-600"><?= $summary['late_count'] ?></span>
      </div>
      <p class="text-sm font-semibold text-on-surface-variant">Late Arrivals</p>
    </div>
    <div class="bg-white rounded-2xl p-5 shadow-sm">
      <div class="flex items-center justify-between mb-2">
        <span class="material-symbols-outlined text-amber-500 text-2xl">flag</span>
        <span class="text-3xl font-headline font-extrabold text-amber-600"><?= $summary['flagged_breaks'] ?></span>
      </div>
      <p class="text-sm font-semibold text-on-surface-variant">Flagged Breaks</p>
    </div>
  </div>

  <!-- ── Date Range Filter ──────────────────────────────────── -->
  <form method="GET" action="/attendance/my" class="flex items-end gap-3 mb-6 bg-white rounded-2xl p-4 shadow-sm">
    <div>
      <label class="text-[10px] font-bold text-on-surface-variant uppercase tracking-wider block mb-1">Quick Range</label>
      <div class="flex gap-1.5">
        <?php foreach (['7d' => 'Last 7 Days', '30d' => 'Last 30 Days', '90d' => 'Last 90 Days'] as $key => $label): ?>
        <button type="submit" name="range" value="<?= $key ?>"
                class="px-3 py-1.5 rounded-lg text-xs font-bold transition-all <?= $preset === $key ? 'bg-primary text-white' : 'bg-surface-container text-on-surface-variant hover:bg-primary/10 hover:text-primary' ?>">
          <?= $label ?>
        </button>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="flex-1 border-l border-gray-100 pl-4">
      <label class="text-[10px] font-bold text-on-surface-variant uppercase tracking-wider block mb-1">Custom Range</label>
      <div class="flex items-center gap-2">
        <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>"
               class="px-3 py-1.5 rounded-lg bg-surface-container-low text-sm font-medium border-0 focus:ring-2 focus:ring-primary/20"/>
        <span class="text-on-surface-variant text-sm">→</span>
        <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>"
               class="px-3 py-1.5 rounded-lg bg-surface-container-low text-sm font-medium border-0 focus:ring-2 focus:ring-primary/20"/>
        <button type="submit" class="px-4 py-1.5 bg-primary text-white rounded-lg text-xs font-bold hover:opacity-90 transition-all">
          Apply
        </button>
      </div>
    </div>
    <div class="text-right ml-auto">
      <p class="text-[10px] text-on-surface-variant uppercase tracking-wider">Showing</p>
      <p class="text-sm font-bold text-on-surface"><?= date('M j', strtotime($dateFrom)) ?> – <?= date('M j, Y', strtotime($dateTo)) ?></p>
    </div>
  </form>

  <!-- ── Session Table ─────────────────────────────────────── -->
  <div class="bg-white rounded-2xl shadow-sm overflow-hidden">
    <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
      <h2 class="text-base font-headline font-extrabold text-primary">Attendance Log</h2>
      <p class="text-xs text-on-surface-variant"><?= $sessions->count() ?> session<?= $sessions->count() != 1 ? 's' : '' ?></p>
    </div>
    <table class="w-full text-sm">
      <thead>
        <tr class="bg-surface-container-low text-on-surface-variant font-label font-semibold text-xs uppercase tracking-wider">
          <th class="py-3 px-3 w-8"></th>
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
        <?php if ($sessions->isEmpty()): ?>
        <tr><td colspan="9" class="py-12 text-center text-on-surface-variant">No records found for this date range.</td></tr>
        <?php else: ?>
        <?php foreach ($sessions as $i => $s): ?>
        <?php
          $hasBreaks    = $s->breaks->isNotEmpty();
          $flaggedCount = $s->breaks->where('flagged', 1)->count();
          $hasFlagged   = $flaggedCount > 0;
          $rowBorder    = $hasFlagged ? 'border-l-4 border-red-400' : '';
          $statusColor  = match($s->status) {
            'completed'  => 'bg-green-100 text-green-800',
            'auto_closed'=> 'bg-amber-100 text-amber-800',
            'active'     => 'bg-blue-100 text-blue-800',
            default      => 'bg-gray-100 text-gray-600',
          };
        ?>
        <tr class="<?= $rowBorder ?> <?= $i % 2 === 0 ? 'bg-white' : 'bg-surface-container-low/40' ?> hover:bg-blue-50/30 transition-colors <?= $hasBreaks ? 'cursor-pointer' : '' ?>"
            <?= $hasBreaks ? "onclick=\"toggleRow('break-{$i}', 'ch-{$i}')\"" : '' ?>>
          <td class="py-3 px-3 text-center">
            <?php if ($hasBreaks): ?>
            <span class="material-symbols-outlined text-sm text-on-surface-variant" id="ch-<?= $i ?>">expand_more</span>
            <?php endif; ?>
          </td>
          <td class="py-3 px-5 font-semibold"><?= date('D, M j', strtotime($s->date)) ?></td>
          <td class="py-3 px-5"><?= date('g:i A', strtotime($s->clock_in)) ?></td>
          <td class="py-3 px-5"><?= $s->clock_out ? date('g:i A', strtotime($s->clock_out)) : '<span class="text-blue-600 font-semibold text-xs">Active</span>' ?></td>
          <td class="py-3 px-5 text-right font-semibold"><?= floor(($s->total_work_mins ?? 0)/60) ?>h <?= ($s->total_work_mins ?? 0) % 60 ?>m</td>
          <td class="py-3 px-5 text-right"><?= $s->total_break_mins ?? 0 ?>m</td>
          <td class="py-3 px-5 text-right <?= ($s->late_minutes ?? 0) > 0 ? 'text-red-600 font-bold' : 'text-gray-400' ?>">
            <?= ($s->late_minutes ?? 0) > 0 ? $s->late_minutes . 'm' : '—' ?>
          </td>
          <td class="py-3 px-5 text-center">
            <span class="inline-block px-3 py-1 rounded-full text-xs font-bold <?= $statusColor ?>">
              <?= ucfirst(str_replace('_', ' ', $s->status)) ?>
            </span>
          </td>
          <td class="py-3 px-5 text-center">
            <?php if ($hasFlagged): ?>
            <span class="inline-flex items-center gap-0.5 px-2 py-1 rounded-full text-xs font-bold bg-red-100 text-red-700">
              🚩 <?= $flaggedCount ?>
            </span>
            <?php else: ?><span class="text-gray-300">—</span><?php endif; ?>
          </td>
        </tr>
        <!-- Expandable break detail row -->
        <?php if ($hasBreaks): ?>
        <tr id="break-<?= $i ?>" class="break-row">
          <td colspan="9" class="p-0">
            <div class="bg-gradient-to-r from-amber-50 to-white px-10 py-4 border-t border-amber-100">
              <p class="text-[10px] font-bold text-amber-700 uppercase tracking-widest mb-3">Break Detail</p>
              <div class="flex flex-wrap gap-3">
                <?php foreach ($s->breaks as $b): ?>
                <?php
                  $bIcon    = $breakTypeIcon[$b->break_type]  ?? '⏸';
                  $bColor   = $breakTypeColor[$b->break_type] ?? 'slate';
                  $isFlagged= (bool) $b->flagged;
                  $reasons  = $abuseLogMap[$b->id] ?? [];
                ?>
                <div class="min-w-[200px] bg-white rounded-xl p-3 shadow-sm border <?= $isFlagged ? 'border-red-200' : 'border-gray-100' ?>">
                  <div class="flex items-center gap-2 mb-2">
                    <span class="text-base"><?= $bIcon ?></span>
                    <span class="text-xs font-bold <?= $isFlagged ? 'text-red-600' : "text-{$bColor}-700" ?>">
                      <?= ucfirst($b->break_type) ?> Break
                    </span>
                    <?php if ($isFlagged): ?>
                    <span class="ml-auto text-[9px] px-1.5 py-0.5 bg-red-100 text-red-700 rounded-full font-extrabold uppercase">🚩 Flagged</span>
                    <?php endif; ?>
                  </div>
                  <p class="text-[11px] text-on-surface-variant">
                    <?= date('g:i A', strtotime($b->break_start)) ?> →
                    <?= $b->break_end ? date('g:i A', strtotime($b->break_end)) : '<span class="text-blue-500 font-bold">Still Active</span>' ?>
                  </p>
                  <?php if ($b->duration_mins): ?>
                  <p class="text-[11px] font-bold mt-1"><?= $b->duration_mins ?> min</p>
                  <?php endif; ?>
                  <?php if ($isFlagged && !empty($reasons)): ?>
                  <div class="mt-2 pt-2 border-t border-red-100">
                    <?php foreach ($reasons as $reason): ?>
                    <p class="text-[10px] text-red-600 font-semibold">⚠ <?= htmlspecialchars($reason) ?></p>
                    <?php endforeach; ?>
                  </div>
                  <?php endif; ?>
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
function toggleRow(rowId, chevronId) {
  const row = document.getElementById(rowId);
  const ch  = document.getElementById(chevronId);
  if (!row) return;
  row.classList.toggle('open');
  if (ch) ch.textContent = row.classList.contains('open') ? 'expand_less' : 'expand_more';
}

// Live clock for active session
(function() {
  const el = document.getElementById('live-clock');
  if (!el) return;
  function tick() {
    const now = new Date();
    const h = String(now.getHours()).padStart(2,'0');
    const m = String(now.getMinutes()).padStart(2,'0');
    const s = String(now.getSeconds()).padStart(2,'0');
    el.textContent = h + ':' + m + ':' + s;
  }
  tick(); setInterval(tick, 1000);
})();
</script>
</body>
</html>
