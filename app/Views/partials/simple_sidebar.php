<?php
/**
 * Simple view sidebar — the short menu for an admin in Simple view
 * (see SimpleViewController). Chosen by layout/sidebar.php when
 * $_SESSION['simple_mode'] is on, so it also appears on full-CRM pages she
 * opens from here (booking details, invoices…), keeping her in one interface.
 *
 * Dark in both themes (a data-theme="dark" island, see App\Services\Theme).
 * Loads simple-ui.js (confirmation box + guard on approval forms).
 *
 * @var string $activePage
 */
$activePage = $activePage ?? '';
\App\Services\Theme::markStaffPage();
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
<aside class="bf-side fixed left-0 top-0 h-full w-60 bg-white border-r border-slate-200 flex flex-col z-30" data-theme="dark">
  <a href="/simple" class="flex items-center gap-3 px-5 py-6 no-underline">
    <img src="/assets/img/basefare-logo-email.png" alt="" class="bf-logo">
    <span class="bf-brand">Base Fare<small>Owner's view</small></span>
  </a>

  <nav class="flex-1 px-3 space-y-1 overflow-y-auto" aria-label="Main">
    <?php foreach ($__simpleNav as [$__sHref, $__sIcon, $__sLabel, $__sKey, $__sHint]): $__sOn = $activePage === $__sKey; ?>
    <a href="<?= $__sHref ?>" title="<?= htmlspecialchars($__sHint) ?>"<?= $__sKey === 'trio_hr' ? ' target="_blank" rel="noopener"' : '' ?>
       class="flex items-center gap-3 px-3 py-3 rounded-xl text-[15px] font-semibold transition-colors no-underline
              <?= $__sOn ? 'bg-primary/15 text-slate-900' : 'text-slate-700 hover:bg-slate-100 hover:text-slate-900' ?>"
       <?= $__sOn ? 'aria-current="page"' : '' ?>>
      <span class="material-symbols-outlined text-[22px]<?= $__sOn ? ' text-primary' : '' ?>"><?= $__sIcon ?></span>
      <span class="flex-1"><?= $__sLabel ?></span>
      <?php if ($__sKey === 'messages' && $__simpleUnread > 0): ?>
      <span class="min-w-[24px] h-6 px-2 rounded-full text-[12px] font-bold flex items-center justify-center bg-rose-600 text-white"
            aria-label="<?= (int) $__simpleUnread ?> unread"><?= (int) $__simpleUnread ?></span>
      <?php endif; ?>
      <?php if ($__sKey === 'simple_approvals' && $__simplePending > 0): ?>
      <span class="min-w-[24px] h-6 px-2 rounded-full text-[12px] font-bold flex items-center justify-center bg-rose-600 text-white"
            aria-label="<?= (int) $__simplePending ?> waiting"><?= (int) $__simplePending ?></span>
      <?php endif; ?>
    </a>
    <?php endforeach; ?>
  </nav>

  <div class="px-3 pb-3">
    <form method="POST" action="/simple/mode">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
      <input type="hidden" name="mode" value="full">
      <button type="submit" class="w-full flex items-center gap-3 px-3 py-3 rounded-xl border border-slate-200 text-left hover:border-primary/40 hover:bg-slate-100 transition-colors">
        <span class="material-symbols-outlined text-[22px] text-slate-500">apps</span>
        <span class="leading-tight">
          <span class="block text-[14px] font-semibold text-slate-800">Full CRM</span>
          <span class="block text-[12px] text-slate-500">Every screen and setting</span>
        </span>
      </button>
    </form>
  </div>

  <div class="px-5 py-4 border-t border-slate-200 flex items-center gap-3">
    <span class="w-9 h-9 rounded-full bg-primary/20 text-primary font-bold text-[14px] flex items-center justify-center shrink-0">
      <?= htmlspecialchars(strtoupper(mb_substr($__simpleName ?: 'A', 0, 1))) ?>
    </span>
    <span class="flex-1 min-w-0">
      <span class="block text-[14px] font-semibold text-slate-800 truncate"><?= htmlspecialchars($__simpleName) ?></span>
      <a href="/logout" class="text-[13px] text-slate-600 hover:text-rose-600 no-underline">Sign out</a>
    </span>
  </div>
  <div class="px-5 pb-4">
    <?= \App\Services\Theme::toggleButton(true) ?>
  </div>
</aside>
<?php require __DIR__ . '/notepad_quick.php'; ?>
<script>window.SIMPLE_CSRF = <?= json_encode($_SESSION['csrf_token'] ?? '') ?>;</script>
<script src="<?= \App\Services\Asset::url('assets/js/simple-ui.js') ?>"></script>
<?php require __DIR__ . '/notification_bell.php'; ?>
