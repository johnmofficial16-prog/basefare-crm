<?php
/**
 * Shared Admin Sidebar Partial
 * Include in ALL admin-facing pages for consistent navigation.
 *
 * @var string $activePage  e.g. 'dashboard', 'attendance', 'history', 'shifts'
 */
$activePage = $activePage ?? '';
\App\Services\Theme::markStaffPage();

$actorRole = $_SESSION['role'] ?? 'agent';

$navItems = [
    ['href' => '/dashboard',               'icon' => 'dashboard',      'label' => 'Dashboard',        'key' => 'dashboard',     'roles' => ['admin', 'manager', 'supervisor']],
    ['href' => '/attendance/admin',         'icon' => 'groups',         'label' => 'Live Board',       'key' => 'attendance',    'roles' => ['admin', 'manager', 'supervisor']],
    ['href' => '/attendance/admin/history', 'icon' => 'history',        'label' => 'History',          'key' => 'history',       'roles' => ['admin', 'manager', 'supervisor']],
    ['href' => '/performance',             'icon' => 'leaderboard',    'label' => 'Performance',      'key' => 'performance',   'roles' => ['admin', 'manager', 'supervisor']],
    ['href' => '/call-logs/team',          'icon' => 'phone_in_talk',  'label' => 'Call Logs',        'key' => 'call_logs',     'roles' => ['admin', 'manager', 'supervisor']],
    ['href' => '/analytics',               'icon' => 'insights',       'label' => 'Centre Analytics', 'key' => 'analytics',     'roles' => ['admin']],
    ['href' => '/chargebacks',             'icon' => 'credit_card_off','label' => 'Chargebacks & Refunds', 'key' => 'chargebacks', 'roles' => ['admin']],
    ['href' => '/shifts/week',             'icon' => 'calendar_month', 'label' => 'Shift Schedule',   'key' => 'shifts',        'roles' => ['admin', 'manager', 'supervisor']],
    ['href' => '/acceptance',              'icon' => 'verified',       'label' => 'Acceptance',       'key' => 'acceptance',    'roles' => ['admin', 'manager', 'supervisor']],
    ['href' => '/transactions',             'icon' => 'payments',       'label' => 'Transactions',     'key' => 'transactions',  'roles' => ['admin', 'manager', 'supervisor']],
    ['href' => '/reminders',               'icon' => 'notifications_active', 'label' => 'Reminders',  'key' => 'reminders',     'roles' => ['admin', 'manager']],
    ['href' => '/etickets',                'icon' => 'airplane_ticket','label' => 'E-Tickets',        'key' => 'etickets',      'roles' => ['admin', 'manager', 'supervisor']],
    ['href' => '/emails',                  'icon' => 'forward_to_inbox','label' => 'Customer Emails',  'key' => 'emails',        'roles' => ['admin', 'manager', 'supervisor']],
    ['href' => '/invoices',                'icon' => 'request_quote',  'label' => 'Invoices',         'key' => 'invoices',      'roles' => ['admin', 'manager', 'supervisor']],
    ['href' => '/payroll',                 'icon' => 'receipt_long',   'label' => 'Salary Slips',     'key' => 'payroll',       'roles' => ['admin', 'manager']],
    ['href' => '/letters',                 'icon' => 'assignment_ind', 'label' => 'Letter of Intent', 'key' => 'letters',       'roles' => ['admin']],
    ['href' => '/admin/survey',            'icon' => 'psychology',     'label' => 'Staff Survey',     'key' => 'staff_survey',  'roles' => ['admin']],
    ['href' => '/vouchers',                'icon' => 'local_activity', 'label' => 'Vouchers',         'key' => 'vouchers',      'roles' => ['admin', 'manager']],
    ['href' => '/users',                   'icon' => 'manage_accounts','label' => 'Users',            'key' => 'users',         'roles' => ['admin', 'manager']],
    ['href' => '/attendance/admin/monthly',   'icon' => 'date_range',    'label' => 'Monthly Report',    'key' => 'monthly',       'roles' => ['admin', 'manager', 'supervisor']],
    ['href' => '/admin/settings',          'icon' => 'settings',       'label' => 'Settings',         'key' => 'settings',      'roles' => ['admin']],
    ['href' => '/admin/fare-terms',        'icon' => 'gavel',          'label' => 'Fare Terms',       'key' => 'fare_terms',    'roles' => ['admin', 'manager']],
    ['href' => '/admin/ip-whitelist',      'icon' => 'shield',         'label' => 'IP Whitelist',     'key' => 'ip_whitelist',  'roles' => ['admin']],
    ['href' => '/admin/activity-log',      'icon' => 'history_edu',    'label' => 'Activity Log',     'key' => 'activity_log',  'roles' => ['admin', 'manager']],
    ['href' => '/admin/error-console',     'icon' => 'bug_report',     'label' => 'Error Console',    'key' => 'error_console', 'roles' => ['admin', 'manager']],
];
if ($__hr = \App\Services\HrLink::navItem()) { $navItems[] = $__hr + ['roles' => ['admin', 'manager', 'supervisor']]; } // Trio HR (only when the link is switched on)
array_splice($navItems, 1, 0, [\App\Services\ChatService::navItem() + ['roles' => ['admin', 'manager', 'supervisor']]]); // Messages, right after Dashboard
array_splice($navItems, 2, 0, [['href' => '#notepad', 'icon' => 'edit_note', 'label' => 'Notepad', 'key' => 'notepad', 'roles' => ['admin', 'manager', 'supervisor']]]); // Notepad (opens the slide-in panel)
?>
<aside class="bf-side fixed left-0 top-0 h-full w-60 bg-white border-r border-slate-200 flex flex-col z-30" data-theme="dark">
  <!-- Logo -->
  <div class="pl-5 pr-3 py-5 border-b border-slate-200 flex items-center justify-between gap-2">
    <a href="/dashboard" class="flex items-center gap-3 no-underline">
      <img src="/assets/img/basefare-logo-email.png" alt="" class="bf-logo">
      <span class="bf-brand">Base Fare<small>CRM <?= ucfirst(htmlspecialchars($actorRole)) ?></small></span>
    </a>
    <?= \App\Services\Theme::sidebarHideButton() ?>
  </div>

  <!-- Navigation -->
  <nav class="flex-1 py-3 overflow-y-auto space-y-0.5" aria-label="Main">
    <?php foreach ($navItems as $item): ?>
    <?php if (!in_array($actorRole, $item['roles'])) continue; ?>
    <?php $isDisabled = !empty($item['disabled']); ?>
    <a href="<?= $item['href'] ?>"<?= !empty($item['new_tab']) ? ' target="_blank" rel="noopener"' : '' ?>
       class="flex items-center gap-3 mx-3 px-3 py-2.5 rounded-lg text-[15px] font-semibold transition-colors no-underline
       <?= $activePage === $item['key']
           ? 'bg-primary/15 text-slate-900'
           : ($isDisabled 
               ? 'text-slate-400 cursor-not-allowed' 
               : 'text-slate-700 hover:bg-slate-100 hover:text-slate-900') ?>"
       <?= $isDisabled ? 'onclick="return false;"' : '' ?>>
      <span class="material-symbols-outlined text-[20px]<?= $activePage === $item['key'] ? ' text-primary' : '' ?>"><?= $item['icon'] ?></span>
      <?= $item['label'] ?>
      <?php if (!empty($item['badge'])): ?><span class="ml-auto min-w-[22px] h-[22px] px-1.5 rounded-full bg-rose-600 text-white text-[12px] font-bold flex items-center justify-center" aria-label="<?= (int) $item['badge'] ?> unread"><?= (int) $item['badge'] ?></span><?php endif; ?>
      <?php if ($isDisabled): ?>
      <span class="text-[12px] text-slate-400 ml-auto">Soon</span>
      <?php endif; ?>
    </a>
    <?php endforeach; ?>
  </nav>

  <?php if (!empty($_SESSION['simple_available'])): ?>
  <!-- Simple view account: way back to the easy interface -->
  <form method="POST" action="/simple/mode" class="px-4 pb-3">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
    <input type="hidden" name="mode" value="simple">
    <button type="submit" class="w-full flex items-center justify-center gap-2 py-2.5 rounded-lg bg-[#163274] text-white text-sm font-bold hover:opacity-90">
      <span class="material-symbols-outlined text-[18px]">home</span> Back to Simple view
    </button>
  </form>
  <?php endif; ?>

  <!-- Bottom user info -->
  <div class="px-5 py-4 border-t border-slate-200">
    <div class="flex items-center gap-3">
      <div class="w-9 h-9 rounded-full bg-primary/20 flex items-center justify-center text-primary font-bold text-[14px] shrink-0">
        <?= strtoupper(substr($_SESSION['user_name'] ?? 'A', 0, 1)) ?>
      </div>
      <div class="flex-1 min-w-0">
        <p class="text-[15px] font-semibold text-slate-900 truncate"><?= htmlspecialchars($_SESSION['user_name'] ?? 'Admin') ?></p>
        <?php 
          $displayRole = $_SESSION['role'] ?? 'admin';
          if (stripos($_SESSION['user_name'] ?? '', 'thomas') !== false) {
              $displayRole = 'manager';
          }
        ?>
        <p class="text-[13px] text-slate-600 capitalize"><?= htmlspecialchars($displayRole) ?></p>
      </div>
    </div>
    <div class="flex items-center justify-between gap-2 mt-3">
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
