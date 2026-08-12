<?php
/**
 * Notifications — full list for the current user.
 *
 * @var \Illuminate\Support\Collection $notifications
 * @var string $csrf
 */
use Carbon\Carbon;
$activePage = '';
$notifications = $notifications ?? [];
$csrf = $csrf ?? ($_SESSION['csrf_token'] ?? '');
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<title>Notifications - Base Fare CRM</title>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
<script src="/assets/js/tailwind.js"></script>
<script>
tailwind.config={darkMode:"class",theme:{extend:{colors:{primary:"#163274","primary-container":"#314a8d",background:"#f8f9fa",surface:"#f8f9fa","surface-container":"#edeeef","on-surface":"#191c1d","on-surface-variant":"#434653"},fontFamily:{headline:["Manrope"],body:["Inter"]}}}}
</script>
</head>
<body class="bg-background font-body text-on-surface antialiased min-h-screen">

<?php require __DIR__ . '/../layout/sidebar.php'; ?>

<main class="ml-60 pt-6 pb-20 px-8">

  <div class="flex items-center justify-between mb-6 max-w-3xl">
    <div>
      <h1 class="text-2xl font-headline font-extrabold text-primary tracking-tight">
        <span class="material-symbols-outlined align-text-bottom">notifications</span>
        Notifications
      </h1>
      <p class="text-sm text-on-surface-variant mt-0.5">Your alerts, newest first.</p>
    </div>
    <button type="button" onclick="markAllRead()"
      class="px-3 py-1.5 text-xs font-semibold text-primary border border-primary rounded-lg hover:bg-primary/5 transition-colors">
      Mark all read
    </button>
  </div>

  <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden max-w-3xl">
    <?php if (!count($notifications)): ?>
    <div class="px-6 py-12 text-center text-sm text-slate-400">You have no notifications.</div>
    <?php else: ?>
    <div class="divide-y divide-slate-100">
      <?php foreach ($notifications as $n): ?>
      <a href="<?= htmlspecialchars($n->link ?: '#') ?>"
         class="flex items-start gap-3 px-6 py-4 hover:bg-slate-50 transition-colors <?= $n->isRead() ? 'opacity-70' : '' ?>">
        <?php if (!$n->isRead()): ?>
        <span class="mt-1.5 shrink-0 w-2 h-2 rounded-full bg-red-500"></span>
        <?php else: ?>
        <span class="mt-1.5 shrink-0 w-2 h-2 rounded-full bg-transparent"></span>
        <?php endif; ?>
        <div class="flex-1 min-w-0">
          <p class="text-sm font-semibold text-slate-800"><?= htmlspecialchars($n->title) ?></p>
          <?php if ($n->body): ?>
          <p class="text-xs text-slate-500 mt-0.5 whitespace-pre-line"><?= htmlspecialchars($n->body) ?></p>
          <?php endif; ?>
          <p class="text-[11px] text-slate-400 mt-1"><?= $n->created_at ? Carbon::parse($n->created_at)->format('M j, Y g:i A') : '' ?></p>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</main>

<script>
var CSRF = <?= json_encode($csrf) ?>;
function markAllRead() {
  fetch('/notifications/read-all', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'csrf_token=' + encodeURIComponent(CSRF)
  }).then(function () { location.reload(); }).catch(function () {});
}
</script>
</body>
</html>
