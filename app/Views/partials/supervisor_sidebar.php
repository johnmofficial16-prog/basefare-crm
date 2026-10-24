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
\App\Services\Theme::markStaffPage();
// Pages shared with agents use one key for "mine" and "team"; tell them apart by the address.
$__path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
if (str_starts_with($__path, '/call-logs')) {
    $activePage = str_starts_with($__path, '/call-logs/team') ? 'call_logs_team' : 'call_logs';
} elseif (str_starts_with($__path, '/attendance/my')) {
    $activePage = 'my_attendance';
}

$navItems = [
    ['href' => '/dashboard',                'icon' => 'dashboard',       'label' => 'Dashboard',        'key' => 'dashboard'],
    ['href' => '/kra',                      'icon' => 'trending_up',     'label' => 'My KRA',           'key' => 'kra'],
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
<aside class="bf-side fixed left-0 top-0 h-full w-60 bg-white border-r border-slate-200 flex flex-col z-30">
  <!-- Logo -->
  <div class="pl-5 pr-3 py-5 border-b border-slate-200 flex items-center justify-between gap-2">
    <a href="/dashboard" class="flex items-center gap-3 no-underline">
      <img src="/assets/img/basefare-logo-email.png" alt="" class="bf-logo">
      <span class="bf-brand">Base Fare<small>Team Leader</small></span>
    </a>
    <?= \App\Services\Theme::sidebarHideButton() ?>
  </div>

  <?php require __DIR__ . '/call_log_quick.php'; ?>

  <!-- Navigation -->
  <nav class="flex-1 py-3 overflow-y-auto space-y-0.5" aria-label="Main">
    <?php foreach ($navItems as $item): ?>
    <?php if (isset($item['section'])): ?>
    <p class="px-6 pt-4 pb-1 text-[12px] font-bold uppercase tracking-wider text-slate-500"><?= htmlspecialchars($item['section']) ?></p>
    <?php continue; endif; ?>
    <a href="<?= $item['href'] ?>"<?= !empty($item['new_tab']) ? ' target="_blank" rel="noopener"' : '' ?>
       class="flex items-center gap-3 mx-3 px-3 py-2.5 rounded-lg text-[15px] font-semibold transition-colors no-underline
       <?= $activePage === $item['key']
           ? 'bg-primary/15 text-slate-900'
           : 'text-slate-700 hover:bg-slate-100 hover:text-slate-900' ?>">
      <span class="material-symbols-outlined text-[20px]<?= $activePage === $item['key'] ? ' text-primary' : '' ?>"><?= $item['icon'] ?></span>
      <?= $item['label'] ?>
      <?php if (!empty($item['badge'])): ?><span class="ml-auto min-w-[22px] h-[22px] px-1.5 rounded-full bg-rose-600 text-white text-[12px] font-bold flex items-center justify-center" aria-label="<?= (int) $item['badge'] ?> unread"><?= (int) $item['badge'] ?></span><?php endif; ?>
    </a>
    <?php endforeach; ?>
  </nav>

  <!-- Bottom: Attendance + User Info -->
  <div class="px-4 py-4 border-t border-slate-200 space-y-3">

    <?php require __DIR__ . '/attendance_strip.php'; ?>

    <!-- User info -->
    <div class="flex items-center gap-3">
      <div class="w-9 h-9 rounded-full bg-primary/20 flex items-center justify-center text-primary font-bold text-[14px] shrink-0">
        <?= strtoupper(substr($_SESSION['user_name'] ?? 'T', 0, 1)) ?>
      </div>
      <div class="flex-1 min-w-0">
        <p class="text-[15px] font-semibold text-slate-900 truncate"><?= htmlspecialchars($_SESSION['user_name'] ?? 'Team leader') ?></p>
        <p class="text-[13px] text-slate-600">Team leader</p>
      </div>
    </div>
    <div class="flex items-center justify-between gap-2">
      <a href="/logout" class="flex items-center gap-2 text-[14px] text-slate-600 hover:text-rose-600 font-semibold transition-colors no-underline whitespace-nowrap">
        <span class="material-symbols-outlined text-[18px]">logout</span> Sign out
      </a>
      <?= \App\Services\Theme::toggleButton() ?>
    </div>
  </div>
</aside>
<?= \App\Services\Theme::sidebarRail('/dashboard') ?>
<?php require __DIR__ . '/notepad_quick.php'; ?>
<?php require __DIR__ . "/notification_bell.php"; ?>
