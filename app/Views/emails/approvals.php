<?php
/**
 * Customer Emails — Approval queue (manager/admin).
 *
 * @var \Illuminate\Support\Collection $messages  pending CustomerEmailMessage[] (with thread, author)
 * @var string $role
 */
$activePage = 'emails';
$csrf = $_SESSION['csrf_token'] ?? '';
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<title>Email Approvals — Base Fare CRM</title>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
<script src="<?= \App\Services\Asset::url('assets/js/error-beacon.js') ?>"></script>
<script src="/assets/js/tailwind.js"></script>
<script src="<?= \App\Services\Asset::url('assets/js/buddy-widget.js') ?>" defer></script>
<script>
tailwind.config = { darkMode: "class", theme: { extend: {
  fontFamily: { sans: ['Inter', 'Manrope', 'sans-serif'] },
  colors: { primary: { DEFAULT: '#0f1e3c', 50: '#f0f4ff', 100: '#dde8ff', 500: '#1a3a6b', 600: '#0f1e3c' }, gold: { DEFAULT: '#c9a84c' } }
}}}
</script>
</head>
<body class="bg-slate-50 font-sans min-h-screen">

<?php require __DIR__ . '/../layout/sidebar.php'; ?>

<main class="ml-60 pt-6 pb-20 px-8 max-w-4xl">

  <div class="flex items-center gap-3 mb-6">
    <a href="/emails" class="text-slate-400 hover:text-primary"><span class="material-symbols-outlined">arrow_back</span></a>
    <h1 class="text-2xl font-headline font-extrabold text-primary tracking-tight flex items-center gap-2">
      <span class="material-symbols-outlined text-2xl">rule</span> Email Approvals
    </h1>
  </div>

  <?php if (count($messages) === 0): ?>
    <div class="bg-white border border-slate-200 rounded-xl p-12 text-center text-slate-400">
      <span class="material-symbols-outlined text-4xl mb-2">inbox</span>
      <p>Nothing waiting for approval.</p>
    </div>
  <?php else: ?>
    <div class="space-y-4">
      <?php foreach ($messages as $m): ?>
        <div class="bg-white border border-slate-200 rounded-xl p-5">
          <div class="flex items-center justify-between mb-2">
            <div class="text-xs font-bold text-slate-500">
              From <span class="text-primary"><?= htmlspecialchars($m->author->name ?? 'Agent') ?></span>
              → <?= htmlspecialchars($m->thread->customer_name ?: 'Customer') ?> (<?= htmlspecialchars($m->thread->customer_email) ?>)
            </div>
            <span class="text-[11px] text-slate-400"><?= $m->created_at->format('M j, g:i A') ?></span>
          </div>
          <div class="text-sm font-bold text-primary mb-1"><?= htmlspecialchars($m->final_subject) ?></div>
          <div class="text-sm text-slate-600 whitespace-pre-wrap leading-relaxed border-l-2 border-slate-100 pl-3 max-h-40 overflow-y-auto"><?= htmlspecialchars($m->final_body) ?></div>

          <?php if ($m->hasPlaceholders()): ?>
            <div class="mt-3 px-3 py-2 rounded-lg bg-amber-50 border border-amber-200 text-amber-800 text-xs font-semibold">
              ⚠ Contains unfilled <code>[[PLACEHOLDER]]</code> markers — reject and ask the agent to complete it.
            </div>
          <?php endif; ?>

          <div class="mt-4 flex items-center gap-2 border-t border-slate-100 pt-3">
            <a href="/emails/<?= $m->thread_id ?>" class="text-xs text-slate-400 hover:text-primary font-semibold mr-auto">View full conversation →</a>
            <form method="POST" action="/emails/message/<?= $m->id ?>/approve" class="inline">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>"/>
              <button class="inline-flex items-center gap-1.5 px-4 py-2 bg-emerald-600 text-white text-xs font-bold rounded-lg hover:bg-emerald-700">
                <span class="material-symbols-outlined text-sm">check</span> Approve &amp; Send
              </button>
            </form>
            <button type="button" onclick="document.getElementById('rej-<?= $m->id ?>').classList.toggle('hidden')"
                    class="inline-flex items-center gap-1.5 px-4 py-2 bg-rose-50 text-rose-600 border border-rose-200 text-xs font-bold rounded-lg hover:bg-rose-100">
              <span class="material-symbols-outlined text-sm">close</span> Reject
            </button>
          </div>
          <form method="POST" action="/emails/message/<?= $m->id ?>/reject" id="rej-<?= $m->id ?>" class="hidden mt-2 flex gap-2">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>"/>
            <input type="text" name="reason" placeholder="Reason for rejection" required
                   class="flex-1 rounded-lg border-slate-300 text-xs focus:ring-primary focus:border-primary"/>
            <button class="px-3 py-2 bg-rose-600 text-white text-xs font-bold rounded-lg hover:bg-rose-700">Confirm</button>
          </form>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

</main>
</body>
</html>
