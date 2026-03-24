<?php
/**
 * My Attendance — Agent's own attendance history (P1 #4)
 * 
 * @var array $history  From AttendanceService::getAgentHistory()
 *   - 'sessions': Collection of AttendanceSession models
 *   - 'summary': Array with totals
 */

$userName = $_SESSION['user_name'] ?? 'Agent';
$sessions = $history['sessions'];
$summary  = $history['summary'];

$totalHours = floor($summary['total_work_mins'] / 60);
$totalMins  = $summary['total_work_mins'] % 60;
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
tailwind.config={darkMode:"class",theme:{extend:{colors:{primary:"#163274","primary-container":"#314a8d",background:"#f8f9fa",surface:"#f8f9fa","surface-container-lowest":"#ffffff","surface-container-low":"#f3f4f5","on-surface":"#191c1d","on-surface-variant":"#434653"},fontFamily:{headline:["Manrope"],body:["Inter"],label:["Inter"]}}}}
</script>
<style>.material-symbols-outlined{font-variation-settings:'FILL' 0,'wght' 400,'GRAD' 0,'opsz' 24}</style>
</head>
<?php $role = $_SESSION['role'] ?? 'agent'; ?>
<body class="bg-background font-body text-on-surface antialiased min-h-screen">

<?php if (in_array($role, ['admin', 'manager'])): ?>
  <!-- Shared Admin Sidebar -->
  <?php $activePage = 'dashboard'; require __DIR__ . '/../partials/admin_sidebar.php'; ?>
<?php else: ?>
  <!-- Top Bar for Agents -->
  <nav class="fixed top-0 w-full z-50 bg-white/70 backdrop-blur-md flex items-center justify-between px-8 py-4 shadow-sm shadow-blue-900/5">
    <span class="text-xl font-extrabold text-primary tracking-tighter font-headline">Base Fare CRM</span>
    <span class="text-lg font-headline font-bold text-on-surface">My Attendance</span>
    <div class="flex items-center gap-4">
      <span class="text-sm font-semibold text-on-surface-variant"><?= htmlspecialchars($userName) ?></span>
      <a href="/logout" class="text-sm font-bold text-on-surface-variant hover:text-red-600 transition-all">Sign Out</a>
    </div>
  </nav>
<?php endif; ?>

<main class="<?= in_array($role, ['admin', 'manager']) ? 'ml-60 pt-8' : 'pt-24' ?> pb-20 px-8 max-w-6xl mx-auto">

  <!-- Back Link -->
  <a href="/dashboard" class="text-sm text-primary font-semibold hover:underline mb-6 inline-block">← Back to Dashboard</a>

  <!-- Summary Cards -->
  <div class="grid grid-cols-4 gap-6 mb-10">
    <div class="bg-white rounded-2xl p-6 shadow-sm shadow-blue-900/5">
      <div class="flex items-center justify-between mb-2">
        <span class="material-symbols-outlined text-primary text-2xl">event_available</span>
        <span class="text-3xl font-headline font-extrabold text-primary"><?= $summary['total_sessions'] ?></span>
      </div>
      <p class="text-sm font-semibold text-on-surface-variant">Total Sessions</p>
    </div>
    <div class="bg-white rounded-2xl p-6 shadow-sm shadow-blue-900/5">
      <div class="flex items-center justify-between mb-2">
        <span class="material-symbols-outlined text-green-600 text-2xl">schedule</span>
        <span class="text-3xl font-headline font-extrabold text-green-700"><?= $totalHours ?>h <?= $totalMins ?>m</span>
      </div>
      <p class="text-sm font-semibold text-on-surface-variant">Total Work</p>
    </div>
    <div class="bg-white rounded-2xl p-6 shadow-sm shadow-blue-900/5">
      <div class="flex items-center justify-between mb-2">
        <span class="material-symbols-outlined text-red-500 text-2xl">alarm</span>
        <span class="text-3xl font-headline font-extrabold text-red-600"><?= $summary['late_count'] ?></span>
      </div>
      <p class="text-sm font-semibold text-on-surface-variant">Late Arrivals</p>
    </div>
    <div class="bg-white rounded-2xl p-6 shadow-sm shadow-blue-900/5">
      <div class="flex items-center justify-between mb-2">
        <span class="material-symbols-outlined text-amber-500 text-2xl">flag</span>
        <span class="text-3xl font-headline font-extrabold text-amber-600"><?= $summary['flagged_breaks'] ?></span>
      </div>
      <p class="text-sm font-semibold text-on-surface-variant">Flagged Breaks</p>
    </div>
  </div>

  <!-- History Table -->
  <div class="bg-white rounded-2xl shadow-sm shadow-blue-900/5 overflow-hidden">
    <div class="px-6 py-4">
      <h2 class="text-lg font-headline font-extrabold text-primary">Last 30 Days</h2>
    </div>
    <table class="w-full text-sm">
      <thead>
        <tr class="bg-surface-container-low text-on-surface-variant font-label font-semibold text-xs uppercase tracking-wider">
          <th class="py-3 px-6 text-left">Date</th>
          <th class="py-3 px-6 text-left">Clock In</th>
          <th class="py-3 px-6 text-left">Clock Out</th>
          <th class="py-3 px-6 text-right">Net Work</th>
          <th class="py-3 px-6 text-right">Breaks</th>
          <th class="py-3 px-6 text-right">Late</th>
          <th class="py-3 px-6 text-center">Status</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($sessions->isEmpty()): ?>
        <tr><td colspan="7" class="py-8 text-center text-on-surface-variant">No attendance records found.</td></tr>
        <?php else: ?>
        <?php foreach ($sessions as $i => $s): ?>
        <tr class="<?= $i % 2 === 0 ? 'bg-white' : 'bg-surface-container-low/50' ?> hover:bg-blue-50/30 transition-colors">
          <td class="py-3 px-6 font-semibold"><?= date('D, M j', strtotime($s->date)) ?></td>
          <td class="py-3 px-6"><?= date('g:i A', strtotime($s->clock_in)) ?></td>
          <td class="py-3 px-6"><?= $s->clock_out ? date('g:i A', strtotime($s->clock_out)) : '—' ?></td>
          <td class="py-3 px-6 text-right font-semibold"><?= floor(($s->total_work_mins ?? 0) / 60) ?>h <?= ($s->total_work_mins ?? 0) % 60 ?>m</td>
          <td class="py-3 px-6 text-right"><?= $s->total_break_mins ?? 0 ?>m</td>
          <td class="py-3 px-6 text-right <?= $s->late_minutes > 0 ? 'text-red-600 font-bold' : '' ?>"><?= $s->late_minutes > 0 ? $s->late_minutes . 'm' : '—' ?></td>
          <td class="py-3 px-6 text-center">
            <?php
              $statusColor = match($s->status) {
                'completed' => 'bg-green-100 text-green-800',
                'auto_closed' => 'bg-amber-100 text-amber-800',
                'active' => 'bg-blue-100 text-blue-800',
                default => 'bg-gray-100 text-gray-600',
              };
            ?>
            <span class="inline-block px-3 py-1 rounded-full text-xs font-bold <?= $statusColor ?>"><?= ucfirst(str_replace('_', ' ', $s->status)) ?></span>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

</main>

<!-- ATTENDANCE WIDGET PARTIAL -->
<?php include __DIR__ . '/../partials/attendance_widget.php'; ?>

</body>
</html>
