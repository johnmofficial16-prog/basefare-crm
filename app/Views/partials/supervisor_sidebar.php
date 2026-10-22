<?php
/**
 * Team Leader Sidebar Partial (users.role = 'supervisor').
 * A team leader is an agent who also looks after a team (John, 22 Oct): their
 * own work like an agent (log a call, clock in/out, breaks), plus their team's
 * live board, history, performance and call logs, and vouchers.
 * Every team page shows only the agents who report to them.
 * Does NOT expose: Users, Shifts, monthly attendance/payroll, Settings, logs, analytics.
 *
 * @var string $activePage  e.g. 'dashboard', 'attendance', 'transactions', 'acceptance'
 */
$activePage = $activePage ?? '';
// Pages shared with agents use one key for "mine" and "team"; tell them apart by the address.
$__path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
if (str_starts_with($__path, '/call-logs')) {
    $activePage = str_starts_with($__path, '/call-logs/team') ? 'call_logs_team' : 'call_logs';
} elseif (str_starts_with($__path, '/attendance/my')) {
    $activePage = 'my_attendance';
}

$navItems = [
    ['href' => '/dashboard',                'icon' => 'dashboard',       'label' => 'Dashboard',        'key' => 'dashboard'],
    ['section' => 'My team'],
    ['href' => '/attendance/admin',         'icon' => 'groups',          'label' => 'Live Board',       'key' => 'attendance'],
    ['href' => '/attendance/admin/history', 'icon' => 'history',         'label' => 'Team History',     'key' => 'history'],
    ['href' => '/performance',              'icon' => 'leaderboard',     'label' => 'Team Performance', 'key' => 'performance'],
    ['href' => '/call-logs/team',           'icon' => 'support_agent',   'label' => 'Team Call Logs',   'key' => 'call_logs_team'],
    ['section' => 'My work'],
    ['href' => '/transactions',             'icon' => 'payments',        'label' => 'Transactions',     'key' => 'transactions'],
    ['href' => '/call-logs',                'icon' => 'phone_in_talk',   'label' => 'My Call Logs',     'key' => 'call_logs'],
    ['href' => '/acceptance',               'icon' => 'verified',        'label' => 'Acceptance',       'key' => 'acceptance'],
    ['href' => '/etickets',                 'icon' => 'airplane_ticket', 'label' => 'E-Tickets',        'key' => 'etickets'],
    ['href' => '/vouchers',                 'icon' => 'local_activity',  'label' => 'Vouchers',         'key' => 'vouchers'],
    ['href' => '/emails',                   'icon' => 'forward_to_inbox','label' => 'Customer Emails',  'key' => 'emails'],
    ['href' => '/invoices',                 'icon' => 'request_quote',   'label' => 'Invoices',         'key' => 'invoices'],
    ['href' => '/attendance/my',            'icon' => 'calendar_month',  'label' => 'My Attendance',    'key' => 'my_attendance'],
];
if ($__hr = \App\Services\HrLink::navItem()) { $navItems[] = $__hr; } // Trio HR (only when the link is switched on)
array_splice($navItems, 1, 0, [\App\Services\ChatService::navItem()]); // Messages, right after Dashboard
array_splice($navItems, 2, 0, [['href' => '#notepad', 'icon' => 'edit_note', 'label' => 'Notepad', 'key' => 'notepad']]); // Notepad (opens the slide-in panel)
?>
<aside class="fixed left-0 top-0 h-full w-60 bg-white border-r border-gray-100 flex flex-col z-30 shadow-sm">
  <!-- Logo -->
  <div class="px-6 py-5 border-b border-gray-100">
    <a href="/dashboard" class="flex items-center gap-2 no-underline">
      <div class="w-8 h-8 rounded-lg bg-primary flex items-center justify-center">
        <span class="material-symbols-outlined text-white text-sm">flight_takeoff</span>
      </div>
      <span class="font-headline font-extrabold text-primary text-sm leading-tight">Base Fare<br><span class="text-on-surface-variant font-medium text-xs">Team Leader</span></span>
    </a>
  </div>

  <?php require __DIR__ . '/call_log_quick.php'; ?>

  <!-- Navigation -->
  <nav class="flex-1 py-4 overflow-y-auto">
    <?php foreach ($navItems as $item): ?>
    <?php if (isset($item['section'])): ?>
    <p class="px-5 pt-4 pb-1 text-[11px] font-bold uppercase tracking-wider text-slate-400"><?= htmlspecialchars($item['section']) ?></p>
    <?php continue; endif; ?>
    <a href="<?= $item['href'] ?>"<?= !empty($item['new_tab']) ? ' target="_blank" rel="noopener"' : '' ?>
       class="flex items-center gap-3 px-5 py-3 text-sm font-semibold transition-all
       <?= $activePage === $item['key']
           ? 'bg-primary/10 text-primary border-r-4 border-primary'
           : 'text-on-surface-variant hover:bg-gray-50 hover:text-primary' ?>">
      <span class="material-symbols-outlined text-[20px]"><?= $item['icon'] ?></span>
      <?= $item['label'] ?>
      <?php if (!empty($item['badge'])): ?><span class="ml-auto min-w-[22px] h-[22px] px-1.5 rounded-full bg-rose-600 text-white text-[11px] font-bold flex items-center justify-center" aria-label="<?= (int) $item['badge'] ?> unread"><?= (int) $item['badge'] ?></span><?php endif; ?>
    </a>
    <?php endforeach; ?>
  </nav>

  <!-- Bottom: Attendance + User Info -->
  <div class="px-4 py-4 border-t border-gray-100 space-y-3">

    <?php require __DIR__ . '/attendance_strip.php'; ?>

    <!-- User info -->
    <div class="flex items-center gap-3">
      <div class="w-8 h-8 rounded-full bg-primary/20 flex items-center justify-center text-primary font-bold text-xs shrink-0">
        <?= strtoupper(substr($_SESSION['user_name'] ?? 'T', 0, 1)) ?>
      </div>
      <div class="flex-1 min-w-0">
        <p class="text-xs font-bold text-on-surface truncate"><?= htmlspecialchars($_SESSION['user_name'] ?? 'Team leader') ?></p>
        <p class="text-[10px] text-on-surface-variant">Team leader</p>
      </div>
    </div>
    <a href="/logout" class="flex items-center gap-2 text-xs text-on-surface-variant hover:text-red-600 font-semibold transition-colors">
      <span class="material-symbols-outlined text-sm">logout</span> Sign Out
    </a>
  </div>
</aside>
<?php require __DIR__ . '/notepad_quick.php'; ?>
<?php require __DIR__ . "/notification_bell.php"; ?>
