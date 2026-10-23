<?php
/**
 * Agent Sidebar Partial
 * Shown to users with role = 'agent'.
 * Mirrors admin_sidebar.php styling for UI consistency.
 *
 * @var string $activePage  e.g. 'dashboard', 'transactions', 'acceptance', 'attendance'
 */
$activePage = $activePage ?? '';

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
<aside class="fixed left-0 top-0 h-full w-60 bg-white border-r border-gray-100 flex flex-col z-30 shadow-sm">
  <!-- Logo -->
  <div class="px-6 py-5 border-b border-gray-100">
    <a href="/dashboard" class="flex items-center gap-2 no-underline">
      <div class="w-8 h-8 rounded-lg bg-primary flex items-center justify-center">
        <span class="material-symbols-outlined text-white text-sm">flight_takeoff</span>
      </div>
      <span class="font-headline font-extrabold text-primary text-sm leading-tight">Base Fare<br><span class="text-on-surface-variant font-medium text-xs">CRM Agent</span></span>
    </a>
  </div>

  <?php require __DIR__ . '/call_log_quick.php'; ?>

  <!-- Navigation -->
  <nav class="flex-1 py-4 overflow-y-auto">
    <?php foreach ($navItems as $item): ?>
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
        <?= strtoupper(substr($_SESSION['user_name'] ?? 'A', 0, 1)) ?>
      </div>
      <div class="flex-1 min-w-0">
        <p class="text-xs font-bold text-on-surface truncate"><?= htmlspecialchars($_SESSION['user_name'] ?? 'Agent') ?></p>
        <?php 
          $displayRole = $_SESSION['role'] ?? 'agent';
          if (stripos($_SESSION['user_name'] ?? '', 'thomas') !== false) {
              $displayRole = 'manager';
          }
        ?>
        <p class="text-[10px] text-on-surface-variant capitalize"><?= htmlspecialchars($displayRole) ?></p>
      </div>
    </div>
    <a href="/logout" class="flex items-center gap-2 text-xs text-on-surface-variant hover:text-red-600 font-semibold transition-colors">
      <span class="material-symbols-outlined text-sm">logout</span> Sign Out
    </a>
  </div>
</aside>
<?php require __DIR__ . '/notepad_quick.php'; ?>
<?php require __DIR__ . "/notification_bell.php"; ?>
