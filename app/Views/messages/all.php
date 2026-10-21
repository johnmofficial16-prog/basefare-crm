<?php
/**
 * Messages — admin: every conversation between staff, searchable, read-only.
 *
 * @var array  $list  ChatService::adminList
 * @var string $q
 */
$activePage = 'messages';
$h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<title>All conversations - Base Fare CRM</title>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
<link href="<?= \App\Services\Asset::url('assets/css/messages.css') ?>" rel="stylesheet"/>
<script src="<?= \App\Services\Asset::url('assets/js/error-beacon.js') ?>"></script>
<script src="/assets/js/tailwind.js"></script>
<script>
tailwind.config={darkMode:"class",theme:{extend:{colors:{primary:"#163274","primary-container":"#314a8d",background:"#f8f9fa",surface:"#f8f9fa","surface-container":"#edeeef","on-surface":"#191c1d","on-surface-variant":"#434653"},fontFamily:{headline:["Manrope"],body:["Inter"]}}}}
</script>
</head>
<body class="bg-background font-body text-on-surface antialiased min-h-screen">
<?php require __DIR__ . '/../layout/sidebar.php'; ?>
<main class="ml-60 pt-6 pb-20 px-8 max-w-[1400px]">
  <a href="/messages" class="text-sm font-semibold text-slate-500 hover:text-primary"><span class="material-symbols-outlined align-text-bottom text-[18px]">arrow_back</span> Messages</a>
  <div class="flex flex-wrap items-end justify-between gap-4 mt-2 mb-5 pr-16">
    <div>
      <h1 class="text-2xl font-headline font-extrabold text-primary tracking-tight">
        <span class="material-symbols-outlined align-text-bottom">manage_search</span> All conversations</h1>
      <p class="text-sm text-on-surface-variant mt-0.5">Every message between staff, read-only · <?= (int) $list['total'] ?> <?= (int) $list['total'] === 1 ? 'conversation' : 'conversations' ?> in all · each one you open is recorded in the Activity Log</p>
    </div>
    <form method="get" class="flex items-center gap-2">
      <input name="q" value="<?= $h($q) ?>" type="search" placeholder="Search a name, subject or words"
        class="w-80 px-3 py-2 rounded-lg border border-slate-200 bg-white text-[15px]" aria-label="Search conversations">
      <button class="px-4 py-2 rounded-lg bg-primary text-white text-sm font-bold">Search</button>
      <?php if ($q !== ''): ?><a href="/messages/all" class="text-sm font-semibold text-slate-500">Clear</a><?php endif; ?>
    </form>
  </div>
  <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden">
    <?php if (!$list['items']): ?>
      <div class="msg-blank" style="padding:56px"><span class="material-symbols-outlined">forum</span>
        <b><?= $q !== '' ? 'Nothing matches' : 'No conversations yet' ?></b>
        <p><?= $q !== '' ? 'Try another name or word.' : 'When staff write to each other in Messages, the conversations appear here.' ?></p></div>
    <?php else: ?>
    <table class="msg-table">
      <thead><tr><th>Between</th><th>Subject</th><th style="text-align:right">Messages</th><th style="text-align:right">Last message</th></tr></thead>
      <tbody>
      <?php foreach ($list['items'] as $t): ?>
        <tr>
          <td><a href="/messages/all/<?= $t['id'] ?>"><?= $h(implode(', ', $t['members'])) ?></a></td>
          <td><?= $h($t['subject']) ?><small><?= $h($t['preview']) ?></small></td>
          <td style="text-align:right"><?= (int) $t['messages'] ?></td>
          <td style="text-align:right;white-space:nowrap"><?= $h(date('j M, g:i a', strtotime($t['last_at']))) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</main>
</body>
</html>
