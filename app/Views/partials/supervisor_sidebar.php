<?php
/**
 * Supervisor Sidebar Partial
 * Shown to users with role = 'supervisor'.
 * Has attendance board + team-facing CRM links.
 * Does NOT expose: Users, Settings, Error Console, Activity Log, Payroll.
 *
 * @var string $activePage  e.g. 'dashboard', 'attendance', 'transactions', 'acceptance'
 */
$activePage = $activePage ?? '';

$navItems = [
    ['href' => '/dashboard',               'icon' => 'dashboard',      'label' => 'Dashboard',      'key' => 'dashboard'],
    ['href' => '/attendance/admin',         'icon' => 'groups',         'label' => 'Live Board',     'key' => 'attendance'],
    ['href' => '/attendance/admin/history', 'icon' => 'history',        'label' => 'History',        'key' => 'history'],
    ['href' => '/shifts/week',              'icon' => 'calendar_month', 'label' => 'Shift Schedule', 'key' => 'shifts'],
    ['href' => '/acceptance',              'icon' => 'verified',       'label' => 'Acceptance',     'key' => 'acceptance'],
    ['href' => '/transactions',             'icon' => 'payments',       'label' => 'Transactions',   'key' => 'transactions'],
];
?>
<aside class="fixed left-0 top-0 h-full w-60 bg-white border-r border-gray-100 flex flex-col z-30 shadow-sm">
  <!-- Logo -->
  <div class="px-6 py-5 border-b border-gray-100">
    <a href="/dashboard" class="flex items-center gap-2 no-underline">
      <div class="w-8 h-8 rounded-lg bg-primary flex items-center justify-center">
        <span class="material-symbols-outlined text-white text-sm">flight_takeoff</span>
      </div>
      <span class="font-headline font-extrabold text-primary text-sm leading-tight">Base Fare<br><span class="text-on-surface-variant font-medium text-xs">Supervisor</span></span>
    </a>
  </div>

  <!-- Navigation -->
  <nav class="flex-1 py-4 overflow-y-auto">
    <?php foreach ($navItems as $item): ?>
    <a href="<?= $item['href'] ?>"
       class="flex items-center gap-3 px-5 py-3 text-sm font-semibold transition-all
       <?= $activePage === $item['key']
           ? 'bg-primary/10 text-primary border-r-4 border-primary'
           : 'text-on-surface-variant hover:bg-gray-50 hover:text-primary' ?>">
      <span class="material-symbols-outlined text-[20px]"><?= $item['icon'] ?></span>
      <?= $item['label'] ?>
    </a>
    <?php endforeach; ?>
  </nav>

  <!-- Bottom user info -->
  <div class="px-5 py-4 border-t border-gray-100">
    <div class="flex items-center gap-3 mb-3">
      <div class="w-8 h-8 rounded-full bg-primary/20 flex items-center justify-center text-primary font-bold text-xs">
        <?= strtoupper(substr($_SESSION['user_name'] ?? 'S', 0, 1)) ?>
      </div>
      <div class="flex-1 min-w-0">
        <p class="text-xs font-bold text-on-surface truncate"><?= htmlspecialchars($_SESSION['user_name'] ?? 'Supervisor') ?></p>
        <p class="text-[10px] text-on-surface-variant capitalize">Supervisor</p>
      </div>
    </div>
    <a href="/logout" class="flex items-center gap-2 text-xs text-on-surface-variant hover:text-red-600 font-semibold transition-colors">
      <span class="material-symbols-outlined text-sm">logout</span> Sign Out
    </a>
  </div>
</aside>
