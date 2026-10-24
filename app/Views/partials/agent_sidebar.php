<?php
/**
 * Agent Sidebar Partial
 * Shown to users with role = 'agent'.
 * Mirrors admin_sidebar.php styling for UI consistency.
 *
 * @var string $activePage  e.g. 'dashboard', 'transactions', 'acceptance', 'attendance'
 */
$activePage = $activePage ?? '';
\App\Services\Theme::markStaffPage();

$navItems = [
    ['href' => '/dashboard',     'icon' => 'dashboard',      'label' => 'Dashboard',    'key' => 'dashboard'],
    ['href' => '/kra',           'icon' => 'trending_up',    'label' => 'My KRA',       'key' => 'kra'],
    ['href' => '/transactions',  'icon' => 'payments',       'label' => 'Transactions', 'key' => 'transactions'],
    ['href' => '/call-logs',     'icon' => 'phone_in_talk',  'label' => 'Call Logs',    'key' => 'call_logs'],
    ['href' => '/acceptance',    'icon' => 'verified',       'label' => 'Acceptance',   'key' => 'acceptance'],
    ['href' => '/etickets',      'icon' => 'airplane_ticket','label' => 'E-Tickets',    'key' => 'etickets'],
    ['href' => '/emails',        'icon' => 'forward_to_inbox','label' => 'Customer Emails','key' => 'emails'],
    ['href' => '/invoices',      'icon' => 'request_quote',  'label' => 'Invoices',     'key' => 'invoices'],
    ['href' => '/performance',   'icon' => 'leaderboard',    'label' => 'My Performance','key' => 'performance'],
    ['href' => '/attendance/my', 'icon' => 'calendar_month', 'label' => 'My Attendance','key' => 'attendance'],
];
if ($__hr = \App\Services\HrLink::navItem()) { $navItems[] = $__hr; } // Trio HR (only when the link is switched on)
array_splice($navItems, 1, 0, [\App\Services\ChatService::navItem()]); // Messages, right after Dashboard
array_splice($navItems, 2, 0, [['href' => '#notepad', 'icon' => 'edit_note', 'label' => 'Notepad', 'key' => 'notepad']]); // Notepad (opens the slide-in panel)
?>
<aside class="bf-side fixed left-0 top-0 h-full w-60 bg-white border-r border-slate-200 flex flex-col z-30" data-theme="dark">
  <!-- Logo -->
  <div class="px-5 py-5 border-b border-slate-200">
    <a href="/dashboard" class="flex items-center gap-3 no-underline">
      <img src="/assets/img/basefare-logo-email.png" alt="" class="bf-logo">
      <span class="bf-brand">Base Fare<small>CRM Agent</small></span>
    </a>
  </div>

  <?php require __DIR__ . '/call_log_quick.php'; ?>

  <!-- Navigation -->
  <nav class="flex-1 py-3 overflow-y-auto space-y-0.5" aria-label="Main">
    <?php foreach ($navItems as $item): ?>
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
        <?= strtoupper(substr($_SESSION['user_name'] ?? 'A', 0, 1)) ?>
      </div>
      <div class="flex-1 min-w-0">
        <p class="text-[15px] font-semibold text-slate-900 truncate"><?= htmlspecialchars($_SESSION['user_name'] ?? 'Agent') ?></p>
        <?php 
          $displayRole = $_SESSION['role'] ?? 'agent';
          if (stripos($_SESSION['user_name'] ?? '', 'thomas') !== false) {
              $displayRole = 'manager';
          }
        ?>
        <p class="text-[13px] text-slate-600 capitalize"><?= htmlspecialchars($displayRole) ?></p>
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
<?php require __DIR__ . '/notepad_quick.php'; ?>
<?php require __DIR__ . "/notification_bell.php"; ?>
