<?php
/**
 * CRM Dashboard — Phase 5
 * Agent: Today's stats, weekly summary, quick tools, attendance widget
 * Admin: Same + live board summary counts + admin navigation links
 *
 * @var array  $stateInfo    From AttendanceService::getCurrentState()
 * @var array  $todayStats   Today's attendance stats
 * @var array  $weekData     7-day weekly summary
 * @var object $todayShift   Today's shift record (or null)
 * @var ?array $adminCounts  Admin board counts (null for agents)
 */
$userName = $_SESSION['user_name'] ?? 'Agent';
$role = $_SESSION['role'] ?? 'agent';
$isAdmin = in_array($role, ['admin', 'manager']);
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<title>Dashboard - Base Fare CRM</title>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
<script src="https://cdn.tailwindcss.com?plugins=forms"></script>
<script>
tailwind.config={darkMode:"class",theme:{extend:{colors:{primary:"#163274","primary-container":"#314a8d",background:"#f8f9fa",surface:"#f8f9fa","surface-container":"#edeeef","surface-container-low":"#f3f4f5","on-surface":"#191c1d","on-surface-variant":"#434653"},fontFamily:{headline:["Manrope"],body:["Inter"],label:["Inter"]}}}}
</script>
<style>.material-symbols-outlined{font-variation-settings:'FILL' 0,'wght' 400,'GRAD' 0,'opsz' 24}</style>
</head>
<body class="bg-background font-body text-on-surface antialiased min-h-screen">

<?php if ($isAdmin): ?>
<!-- Admin: Shared Sidebar -->
<?php $activePage = 'dashboard'; require __DIR__ . '/partials/admin_sidebar.php'; ?>
<main class="ml-60 pt-8 pb-20 px-10">
<?php else: ?>
<!-- Agent: Top Bar -->
<nav class="fixed top-0 w-full z-50 bg-white/70 backdrop-blur-md flex items-center justify-between px-8 py-4 shadow-sm shadow-blue-900/5">
  <span class="text-xl font-extrabold text-primary tracking-tighter font-headline">Base Fare CRM</span>
  <div class="flex items-center gap-6">
    <div class="text-right">
      <p class="text-sm font-bold text-on-surface"><?= htmlspecialchars($userName) ?></p>
      <p class="text-[10px] font-bold text-primary uppercase tracking-widest opacity-70"><?= strtoupper($role) ?></p>
    </div>
    <a href="/logout" class="text-sm font-bold text-on-surface-variant hover:text-red-600 transition-all">Sign Out</a>
  </div>
</nav>
<main class="pt-28 px-10 max-w-7xl mx-auto pb-20">
<?php endif; ?>

  <!-- Header -->
  <div class="mb-8">
    <h1 class="text-3xl font-headline font-extrabold text-primary tracking-tight mb-1">
      Welcome back, <?= explode(' ', $userName)[0] ?>!
    </h1>
    <p class="text-on-surface-variant font-medium opacity-70"><?= date('l, F j, Y') ?></p>
  </div>

  <?php if ($isAdmin && $adminCounts): ?>
  <!-- Admin: Quick Board Summary -->
  <div class="grid grid-cols-5 gap-4 mb-8">
    <a href="/attendance/admin" class="bg-green-50 rounded-2xl p-4 hover:shadow-md transition-all">
      <div class="flex items-center justify-between mb-1">
        <span class="material-symbols-outlined text-green-600 text-xl">check_circle</span>
        <span class="text-2xl font-headline font-extrabold text-green-700"><?= $adminCounts['in'] ?></span>
      </div>
      <p class="text-xs font-semibold text-green-800">Working</p>
    </a>
    <a href="/attendance/admin" class="bg-amber-50 rounded-2xl p-4 hover:shadow-md transition-all">
      <div class="flex items-center justify-between mb-1">
        <span class="material-symbols-outlined text-amber-600 text-xl">coffee</span>
        <span class="text-2xl font-headline font-extrabold text-amber-700"><?= $adminCounts['on_break'] ?></span>
      </div>
      <p class="text-xs font-semibold text-amber-800">On Break</p>
    </a>
    <a href="/attendance/admin" class="bg-blue-50 rounded-2xl p-4 hover:shadow-md transition-all">
      <div class="flex items-center justify-between mb-1">
        <span class="material-symbols-outlined text-blue-500 text-xl">task_alt</span>
        <span class="text-2xl font-headline font-extrabold text-blue-700"><?= $adminCounts['completed'] ?></span>
      </div>
      <p class="text-xs font-semibold text-blue-800">Completed</p>
    </a>
    <a href="/attendance/admin" class="bg-gray-100 rounded-2xl p-4 hover:shadow-md transition-all">
      <div class="flex items-center justify-between mb-1">
        <span class="material-symbols-outlined text-gray-500 text-xl">person_off</span>
        <span class="text-2xl font-headline font-extrabold text-gray-600"><?= $adminCounts['absent'] ?></span>
      </div>
      <p class="text-xs font-semibold text-gray-700">Absent</p>
    </a>
    <a href="/attendance/admin" class="bg-red-50 rounded-2xl p-4 hover:shadow-md transition-all <?= $adminCounts['pending'] > 0 ? 'ring-2 ring-red-300 animate-pulse' : '' ?>">
      <div class="flex items-center justify-between mb-1">
        <span class="material-symbols-outlined text-red-600 text-xl">warning</span>
        <span class="text-2xl font-headline font-extrabold text-red-700"><?= $adminCounts['pending'] ?></span>
      </div>
      <p class="text-xs font-semibold text-red-800">Pending</p>
    </a>
  </div>
  <?php endif; ?>

  <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
    <!-- Left Column: Today's Summary + Weekly -->
    <div class="lg:col-span-2 space-y-8">

      <!-- Today's Attendance Card -->
      <div class="bg-white rounded-3xl p-6 shadow-sm shadow-blue-900/5">
        <h2 class="text-lg font-headline font-extrabold text-primary mb-4 flex items-center gap-2">
          <span class="material-symbols-outlined">today</span> Today's Attendance
        </h2>
        <div class="grid grid-cols-4 gap-4">
          <div class="text-center p-3 bg-surface-container-low rounded-xl">
            <p class="text-[10px] font-bold uppercase text-on-surface-variant tracking-wider">Clock In</p>
            <p class="text-lg font-headline font-extrabold text-primary mt-1">
              <?= $todayStats['clock_in'] ? date('g:i A', strtotime($todayStats['clock_in'])) : '—' ?>
            </p>
          </div>
          <div class="text-center p-3 bg-surface-container-low rounded-xl">
            <p class="text-[10px] font-bold uppercase text-on-surface-variant tracking-wider">Work Time</p>
            <p class="text-lg font-headline font-extrabold text-green-700 mt-1">
              <?= floor($todayStats['work_mins'] / 60) ?>h <?= $todayStats['work_mins'] % 60 ?>m
            </p>
          </div>
          <div class="text-center p-3 bg-surface-container-low rounded-xl">
            <p class="text-[10px] font-bold uppercase text-on-surface-variant tracking-wider">Break Time</p>
            <p class="text-lg font-headline font-extrabold text-amber-700 mt-1">
              <?= $todayStats['break_mins'] ?>m
            </p>
          </div>
          <div class="text-center p-3 bg-surface-container-low rounded-xl">
            <p class="text-[10px] font-bold uppercase text-on-surface-variant tracking-wider">Late</p>
            <p class="text-lg font-headline font-extrabold <?= $todayStats['late_mins'] > 0 ? 'text-red-600' : 'text-green-600' ?> mt-1">
              <?= $todayStats['late_mins'] > 0 ? $todayStats['late_mins'] . 'm' : 'On Time' ?>
            </p>
          </div>
        </div>
        <?php if ($todayShift): ?>
        <div class="mt-4 px-4 py-2 bg-blue-50 text-blue-700 rounded-lg text-xs font-semibold inline-flex items-center gap-2">
          <span class="material-symbols-outlined text-sm">schedule</span>
          Shift: <?= date('g:i A', strtotime($todayShift->shift_start)) ?> – <?= date('g:i A', strtotime($todayShift->shift_end)) ?>
          <?php if ($todayShift->template): ?>(<?= htmlspecialchars($todayShift->template->name) ?>)<?php endif; ?>
        </div>
        <?php endif; ?>
      </div>

      <!-- Weekly Summary -->
      <div class="bg-white rounded-3xl p-6 shadow-sm shadow-blue-900/5">
        <h2 class="text-lg font-headline font-extrabold text-primary mb-4 flex items-center gap-2">
          <span class="material-symbols-outlined">date_range</span> This Week
        </h2>
        <div class="grid grid-cols-7 gap-2">
          <?php foreach ($weekData as $day): ?>
          <div class="text-center p-3 rounded-xl <?= $day['is_today'] ? 'bg-primary text-white' : ($day['has_data'] ? 'bg-green-50' : 'bg-gray-50') ?>">
            <p class="text-[10px] font-bold uppercase mb-1 <?= $day['is_today'] ? 'text-white/70' : 'text-on-surface-variant' ?>"><?= $day['day'] ?></p>
            <?php if ($day['has_data']): ?>
            <p class="text-sm font-headline font-extrabold <?= $day['is_today'] ? 'text-white' : 'text-green-700' ?>">
              <?= floor($day['work_mins'] / 60) ?>h<?= $day['work_mins'] % 60 > 0 ? $day['work_mins'] % 60 . 'm' : '' ?>
            </p>
            <?php if ($day['late_mins'] > 0): ?>
            <p class="text-[9px] font-bold <?= $day['is_today'] ? 'text-red-200' : 'text-red-500' ?>"><?= $day['late_mins'] ?>m late</p>
            <?php endif; ?>
            <?php else: ?>
            <p class="text-sm font-semibold <?= $day['is_today'] ? 'text-white/50' : 'text-gray-400' ?>">—</p>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
        <?php
          $totalWeekWork = array_sum(array_column($weekData, 'work_mins'));
          $totalWeekBreak = array_sum(array_column($weekData, 'break_mins'));
          $totalWeekLate = array_sum(array_column($weekData, 'late_mins'));
          $daysWorked = count(array_filter($weekData, fn($d) => $d['has_data']));
        ?>
        <div class="mt-4 flex items-center gap-6 text-xs font-semibold text-on-surface-variant">
          <span>Total: <strong class="text-primary"><?= floor($totalWeekWork / 60) ?>h <?= $totalWeekWork % 60 ?>m</strong></span>
          <span>Breaks: <strong class="text-amber-700"><?= $totalWeekBreak ?>m</strong></span>
          <span>Days: <strong class="text-green-700"><?= $daysWorked ?>/<?= count($weekData) ?></strong></span>
          <?php if ($totalWeekLate > 0): ?>
          <span>Late: <strong class="text-red-600"><?= $totalWeekLate ?>m</strong></span>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Right Column: Quick Actions + Status Card -->
    <div class="space-y-8">
      <!-- Status Card -->
      <div class="bg-gradient-to-br from-primary to-primary-container rounded-3xl p-6 text-white shadow-xl shadow-primary/20">
        <p class="text-xs font-bold uppercase tracking-widest opacity-70 mb-1">Current Status</p>
        <?php
          $statusLabel = match($stateInfo['state'] ?? 'not_clocked_in') {
            'clocked_in' => 'Active on Shift',
            'on_break'   => 'On Break',
            'clocked_out'=> 'Shift Completed',
            default      => 'Not Clocked In',
          };
          $statusIcon = match($stateInfo['state'] ?? 'not_clocked_in') {
            'clocked_in' => 'play_circle',
            'on_break'   => 'pause_circle',
            'clocked_out'=> 'check_circle',
            default      => 'circle',
          };
        ?>
        <p class="text-2xl font-headline font-extrabold mb-2 flex items-center gap-2">
          <span class="material-symbols-outlined text-2xl"><?= $statusIcon ?></span>
          <?= $statusLabel ?>
        </p>
        <p class="text-sm opacity-80 font-medium">
          Manage breaks via the floating widget →
        </p>
      </div>

      <!-- Quick Actions -->
      <div class="bg-white rounded-3xl p-6 shadow-sm shadow-blue-900/5">
        <h2 class="text-lg font-headline font-extrabold text-primary mb-4">Quick Tools</h2>
        <div class="space-y-3">
          <a href="/attendance/my" class="flex items-center gap-4 p-3 rounded-xl bg-surface-container-low hover:bg-primary hover:text-white transition-all group">
            <span class="material-symbols-outlined text-primary group-hover:text-white">history</span>
            <span class="font-bold text-sm">My Attendance</span>
          </a>
          <a href="#" class="flex items-center gap-4 p-3 rounded-xl bg-surface-container-low opacity-50 cursor-not-allowed">
            <span class="material-symbols-outlined text-primary">payments</span>
            <span class="font-bold text-sm">Transactions <span class="text-[10px] text-on-surface-variant">(Coming Soon)</span></span>
          </a>
          <?php if ($isAdmin): ?>
          <a href="/attendance/admin" class="flex items-center gap-4 p-3 rounded-xl bg-blue-50 hover:bg-primary hover:text-white transition-all group">
            <span class="material-symbols-outlined text-primary group-hover:text-white">how_to_reg</span>
            <span class="font-bold text-sm">Attendance Board</span>
          </a>
          <a href="/shifts/week" class="flex items-center gap-4 p-3 rounded-xl bg-blue-50 hover:bg-primary hover:text-white transition-all group">
            <span class="material-symbols-outlined text-primary group-hover:text-white">calendar_month</span>
            <span class="font-bold text-sm">Shift Schedule</span>
          </a>
          <a href="/attendance/admin/history" class="flex items-center gap-4 p-3 rounded-xl bg-blue-50 hover:bg-primary hover:text-white transition-all group">
            <span class="material-symbols-outlined text-primary group-hover:text-white">analytics</span>
            <span class="font-bold text-sm">Attendance History</span>
          </a>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

</main>

<!-- ATTENDANCE WIDGET PARTIAL -->
<?php include __DIR__ . '/partials/attendance_widget.php'; ?>

</body>
</html>
