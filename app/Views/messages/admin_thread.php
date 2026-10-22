<?php
/**
 * Messages — admin: one conversation between staff, read-only.
 *
 * @var array $t      ChatService::adminThread
 * @var int   $opens  how many times admins have opened it (this one included)
 */
$activePage = 'messages';
$h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$roles = ['admin' => 'Admin', 'manager' => 'Manager', 'supervisor' => 'Team leader', 'agent' => 'Travel agent', 'csa' => 'Customer support'];
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<title><?= $h($t['subject']) ?> - Base Fare CRM</title>
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
  <a href="/messages/all" class="text-sm font-semibold text-slate-500 hover:text-primary"><span class="material-symbols-outlined align-text-bottom text-[18px]">arrow_back</span> All conversations</a>
  <div class="mt-2 mb-5 pr-16">
    <h1 class="text-2xl font-headline font-extrabold text-primary tracking-tight"><?= $h($t['subject']) ?></h1>
    <p class="text-sm text-on-surface-variant mt-0.5"><?php if ($t['broadcast']): ?><b class="text-amber-800">Broadcast</b> from <?= $h($t['sender'] ?? 'an admin') ?> to <?= $h($t['audience']) ?> · <?php endif; ?>Started <?= $h(date('j M Y', strtotime($t['started']))) ?> · read-only · opened <?= (int) $opens ?> <?= $opens === 1 ? 'time' : 'times' ?> by admins</p>
  </div>
  <div class="grid grid-cols-[minmax(0,1fr)_320px] gap-5 items-start">
    <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden">
      <ol class="msg-thread" style="max-height:none">
        <?php foreach ($t['messages'] as $m): ?>
        <li class="msg">
          <span class="msg-av msg-av--sm"><?= $h($m['initials']) ?></span>
          <div class="msg-bubble">
            <div class="msg-meta"><b><?= $h($m['author']) ?></b><time><?= $h(date('j M, g:i a', strtotime($m['at']))) ?></time></div>
            <?php if ($m['body'] !== ''): ?><div class="msg-body"><?= $h($m['body']) ?></div><?php endif; ?>
            <?php $msgFiles = $m['files']; require __DIR__ . '/files.php'; ?>
          </div>
        </li>
        <?php endforeach; ?>
      </ol>
    </div>
    <div class="bg-white border border-slate-200 rounded-2xl p-5">
      <h2 class="font-headline font-extrabold text-primary text-[17px] mb-3"><?= $t['broadcast'] ? 'Sent to ' . count($t['members']) . ' people (sender included)' : 'In this conversation' ?></h2>
      <?php foreach ($t['members'] as $p): ?>
      <div class="flex items-center gap-3 py-2 border-t border-slate-100 first:border-0">
        <span class="msg-av msg-av--sm"><?= $h($p['initials']) ?></span>
        <div><div class="font-semibold text-[15px]"><?= $h($p['name']) ?></div><div class="text-[13.5px] text-slate-500"><?= $h($roles[$p['role']] ?? ucfirst((string) $p['role'])) ?></div></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</main>
</body>
</html>
