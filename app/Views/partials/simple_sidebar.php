<?php
/**
 * Simple view sidebar — the short menu for an admin in Simple view
 * (see SimpleViewController). Chosen by layout/sidebar.php when
 * $_SESSION['simple_mode'] is on, so it also appears on full-CRM pages she
 * opens from here (booking details, invoices…), keeping her in one interface.
 *
 * Uses only stock Tailwind colours / arbitrary values, so it renders the same
 * whatever the host page's tailwind.config. Loads simple-ui.js (confirmation
 * box + guard on approval forms).
 *
 * @var string $activePage
 */
$activePage = $activePage ?? '';
try {
    $__simplePending = (new \App\Services\SimpleViewService())->pendingCounts()['total'] ?? 0;
} catch (\Throwable $e) {
    $__simplePending = 0;
}
$__simpleNav = [
    ['/simple',           'home',          'Home',      'simple_home',      'Today at a glance'],
    ['/simple/approvals', 'task_alt',      'Approvals', 'simple_approvals', 'Things waiting for you'],
    ['/simple/team',      'groups',        'Team',      'simple_team',      'People and logins'],
    ['/simple/sales',     'monitoring',    'Sales',     'simple_sales',     'Sales and profit'],
    ['/simple/reports',   'description',   'Reports',   'simple_reports',   'Reports and records'],
    ['/admin/survey',     'psychology',    'Staff survey', 'staff_survey',  'What the team told us'],
];
array_splice($__simpleNav, 1, 0, [['/messages', 'forum', 'Messages', 'messages', 'Write to anyone on the team'], ['#notepad', 'edit_note', 'Notepad', 'notepad', 'Your own notes (Alt+N)']]);
$__simpleUnread = \App\Services\ChatService::navItem()['badge'];
if (\App\Services\HrLink::enabled()) { $__simpleNav[] = ['/hr', 'badge', 'Trio HR', 'trio_hr', 'Staff, letters and HR messages (opens in a new tab)']; }
$__simpleName = $_SESSION['user_name'] ?? '';
?>
<meta name="simple-csrf" content="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
<aside class="fixed left-0 top-0 h-full w-60 bg-white border-r border-slate-200 flex flex-col z-30" style="font-family:Inter,system-ui,sans-serif">
  <a href="/simple" class="flex items-center gap-3 px-5 py-6 no-underline">
    <span class="w-10 h-10 rounded-xl bg-[#163274] flex items-center justify-center">
      <span class="material-symbols-outlined text-white text-[22px]">flight_takeoff</span>
    </span>
    <span class="leading-tight">
      <span class="block font-extrabold text-[#163274] text-[16px]" style="font-family:Manrope,Inter,sans-serif">Base Fare</span>
      <span class="block text-[12px] text-slate-500">Owner's view</span>
    </span>
  </a>

  <nav class="flex-1 px-3 space-y-1 overflow-y-auto" aria-label="Main">
    <?php foreach ($__simpleNav as [$__sHref, $__sIcon, $__sLabel, $__sKey, $__sHint]): $__sOn = $activePage === $__sKey; ?>
    <a href="<?= $__sHref ?>" title="<?= htmlspecialchars($__sHint) ?>"<?= $__sKey === 'trio_hr' ? ' target="_blank" rel="noopener"' : '' ?>
       class="flex items-center gap-3 px-3 py-3 rounded-xl text-[15px] font-semibold transition-colors no-underline
              <?= $__sOn ? 'bg-[#163274] text-white shadow-sm' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900' ?>"
       <?= $__sOn ? 'aria-current="page"' : '' ?>>
      <span class="material-symbols-outlined text-[22px]"><?= $__sIcon ?></span>
      <span class="flex-1"><?= $__sLabel ?></span>
      <?php if ($__sKey === 'messages' && $__simpleUnread > 0): ?>
      <span class="min-w-[24px] h-6 px-2 rounded-full text-[12px] font-bold flex items-center justify-center <?= $__sOn ? 'bg-white text-[#163274]' : 'bg-rose-600 text-white' ?>"
            aria-label="<?= (int) $__simpleUnread ?> unread"><?= (int) $__simpleUnread ?></span>
      <?php endif; ?>
      <?php if ($__sKey === 'simple_approvals' && $__simplePending > 0): ?>
      <span class="min-w-[24px] h-6 px-2 rounded-full text-[12px] font-bold flex items-center justify-center <?= $__sOn ? 'bg-white text-[#163274]' : 'bg-rose-600 text-white' ?>"
            aria-label="<?= (int) $__simplePending ?> waiting"><?= (int) $__simplePending ?></span>
      <?php endif; ?>
    </a>
    <?php endforeach; ?>
  </nav>

  <div class="px-3 pb-3">
    <form method="POST" action="/simple/mode">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
      <input type="hidden" name="mode" value="full">
      <button type="submit" class="w-full flex items-center gap-3 px-3 py-3 rounded-xl border border-slate-200 text-left hover:border-[#163274]/40 hover:bg-slate-50 transition-colors">
        <span class="material-symbols-outlined text-[22px] text-slate-500">apps</span>
        <span class="leading-tight">
          <span class="block text-[14px] font-semibold text-slate-800">Full CRM</span>
          <span class="block text-[12px] text-slate-500">Every screen and setting</span>
        </span>
      </button>
    </form>
  </div>

  <div class="px-5 py-4 border-t border-slate-200 flex items-center gap-3">
    <span class="w-9 h-9 rounded-full bg-[#e8edf9] text-[#163274] font-bold text-[14px] flex items-center justify-center">
      <?= htmlspecialchars(strtoupper(mb_substr($__simpleName ?: 'A', 0, 1))) ?>
    </span>
    <span class="flex-1 min-w-0">
      <span class="block text-[14px] font-semibold text-slate-800 truncate"><?= htmlspecialchars($__simpleName) ?></span>
      <a href="/logout" class="text-[13px] text-slate-500 hover:text-rose-600 no-underline">Sign out</a>
    </span>
  </div>
</aside>
<?php require __DIR__ . '/notepad_quick.php'; ?>
<script>window.SIMPLE_CSRF = <?= json_encode($_SESSION['csrf_token'] ?? '') ?>;</script>
<script src="<?= \App\Services\Asset::url('assets/js/simple-ui.js') ?>"></script>
<?php require __DIR__ . '/notification_bell.php'; ?>
