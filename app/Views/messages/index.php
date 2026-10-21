<?php
/**
 * Messages — staff write to each other inside the CRM.
 *
 * @var array      $threads     inbox rows (ChatService::inbox)
 * @var array|null $open        the open conversation (ChatService::thread)
 * @var bool       $composing   show the new-message form
 * @var bool       $broadcasting show the broadcast form (admins)
 * @var array|null $groups      broadcast groups: labels + headcounts
 * @var string     $preSubject
 * @var int|null   $preTo       colleague to pre-select in a new message
 * @var array      $colleagues  the "To" list (everyone else with an active CRM login)
 * @var int        $maxTo
 * @var bool       $isAdmin
 * @var string|null $error
 * @var string     $csrf
 */
$activePage = 'messages';
$h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$when = function (string $at): string {
    $ts = strtotime($at);
    return date('Y-m-d', $ts) === date('Y-m-d') ? date('g:i a', $ts) : date('j M', $ts);
};
$roleOrder = ['Admin', 'Manager', 'Team leader', 'Travel agent', 'Customer support'];
$byRole = [];
foreach ($colleagues as $p) {
    $byRole[$p['role']][] = $p;
}
$rank = fn($r) => ($i = array_search($r, $roleOrder, true)) === false ? 99 : $i;
uksort($byRole, fn($a, $b) => $rank($a) <=> $rank($b));
$boot = [
    'csrf' => $csrf, 'open' => $open ? ['id' => $open['id'], 'last' => $open['messages'] ? end($open['messages'])['id'] : 0] : null,
    'colleagues' => $colleagues, 'maxTo' => $maxTo, 'preTo' => $preTo, 'me' => (int) ($_SESSION['user_id'] ?? 0),
];
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<title>Messages - Base Fare CRM</title>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
<link href="<?= \App\Services\Asset::url('assets/css/messages.css') ?>" rel="stylesheet"/>
<script src="<?= \App\Services\Asset::url('assets/js/error-beacon.js') ?>"></script>
<script src="/assets/js/tailwind.js"></script>
<script src="<?= \App\Services\Asset::url('assets/js/buddy-widget.js') ?>" defer></script>
<script>
tailwind.config={darkMode:"class",theme:{extend:{colors:{primary:"#163274","primary-container":"#314a8d",background:"#f8f9fa",surface:"#f8f9fa","surface-container":"#edeeef","on-surface":"#191c1d","on-surface-variant":"#434653"},fontFamily:{headline:["Manrope"],body:["Inter"]}}}}
</script>
</head>
<body class="bg-background font-body text-on-surface antialiased min-h-screen">

<?php require __DIR__ . '/../layout/sidebar.php'; ?>

<main class="ml-60 pt-6 pb-6 px-8 max-w-[1400px]">
  <div class="flex flex-wrap items-end justify-between gap-4 mb-4 pr-16">
    <div>
      <h1 class="text-2xl font-headline font-extrabold text-primary tracking-tight">
        <span class="material-symbols-outlined align-text-bottom">forum</span> Messages
      </h1>
      <p class="text-sm text-on-surface-variant mt-0.5">Write to anyone at Trio who uses the CRM.</p>
    </div>
    <div class="flex items-center gap-2">
      <?php if ($isAdmin): ?>
      <a href="/messages/all" class="px-3 py-2 rounded-lg border border-slate-200 bg-white text-sm font-semibold text-slate-600 hover:text-primary">
        <span class="material-symbols-outlined align-text-bottom text-[18px]">manage_search</span> All conversations</a>
      <a href="/messages?broadcast=1" class="px-3 py-2 rounded-lg border border-amber-300 bg-amber-50 text-sm font-bold text-amber-800 hover:bg-amber-100">
        <span class="material-symbols-outlined align-text-bottom text-[18px]">campaign</span> Broadcast</a>
      <?php endif; ?>
      <a href="/messages?new=1" class="px-4 py-2 rounded-lg bg-primary text-white text-sm font-bold hover:bg-primary-container">
        <span class="material-symbols-outlined align-text-bottom text-[18px]">edit</span> New message</a>
    </div>
  </div>

  <div class="msg-shell">
    <!-- Conversations -->
    <aside class="msg-list" aria-label="Conversations">
      <div class="msg-search"><span class="material-symbols-outlined">search</span>
        <input id="msg-filter" type="search" placeholder="Search conversations" aria-label="Search conversations"></div>
      <div id="msg-items">
      <?php if (!$threads): ?>
        <div class="msg-empty-list">
          <span class="material-symbols-outlined">forum</span>
          <b>No conversations yet</b>
          <p>Start one with <em>New message</em>.</p>
        </div>
      <?php endif; ?>
      <?php foreach ($threads as $t): ?>
        <a class="msg-item<?= $t['unread'] ? ' is-unread' : '' ?><?= $open && $open['id'] === $t['id'] ? ' is-open' : '' ?>"
           href="/messages?c=<?= $t['id'] ?>" data-search="<?= $h(mb_strtolower(implode(' ', $t['with']) . ' ' . $t['subject'] . ' ' . $t['preview'])) ?>">
          <span class="msg-av<?= $t['broadcast'] ? ' is-broadcast' : ($t['group'] ? ' is-group' : '') ?>"><?= $t['broadcast'] ? '<span class="material-symbols-outlined">campaign</span>' : ($t['group'] ? '<span class="material-symbols-outlined">group</span>' : $h($t['initials'])) ?></span>
          <span class="msg-item__t">
            <span class="msg-item__top"><b><?= $h(implode(', ', $t['with'])) ?></b><time><?= $h($when($t['last_at'])) ?></time></span>
            <span class="msg-item__s"><?= $h($t['subject']) ?></span>
            <span class="msg-item__p"><?= $t['from_me'] ? 'You: ' : ($t['last_by'] && $t['group'] ? $h($t['last_by']) . ': ' : '') ?><?= $h($t['preview']) ?></span>
          </span>
          <?php if ($t['unread']): ?><span class="msg-dot" aria-label="Unread"></span><?php endif; ?>
        </a>
      <?php endforeach; ?>
      </div>
    </aside>

    <!-- The open conversation, a new message, or nothing -->
    <section class="msg-pane" aria-live="polite">
      <?php if ($error): ?>
        <div class="msg-blank"><span class="material-symbols-outlined">error</span><b><?= $h($error) ?></b><a href="/messages">Back to messages</a></div>
      <?php elseif ($open): ?>
        <?php $others = array_values(array_filter($open['members'], fn($m) => !$m['me'])); ?>
        <header class="msg-head">
          <div>
            <h2><?php if ($open['broadcast']): ?><span class="msg-tag"><span class="material-symbols-outlined">campaign</span> Broadcast</span> <?php endif; ?><?= $h($open['subject']) ?></h2>
            <?php if ($open['broadcast']): ?>
            <p>From <?= $h($open['sender']['name'] ?? 'an admin') ?> to <?= $h($open['audience']) ?> · <?= (int) $open['recipients'] ?> <?= (int) $open['recipients'] === 1 ? 'person' : 'people' ?></p>
            <?php else: ?>
            <p>With <?= $h(implode(', ', array_map(fn($m) => $m['name'] . ($m['active'] ? '' : ' (no longer active)'), $others))) ?></p>
            <?php endif; ?>
          </div>
        </header>
        <ol class="msg-thread" id="msg-thread">
          <?php foreach ($open['messages'] as $m): ?>
          <li class="msg<?= $m['mine'] ? ' msg--me' : '' ?>" data-id="<?= $m['id'] ?>">
            <?php if (!$m['mine']): ?><span class="msg-av msg-av--sm"><?= $h($m['initials']) ?></span><?php endif; ?>
            <div class="msg-bubble">
              <div class="msg-meta"><b><?= $m['mine'] ? 'You' : $h($m['author']) ?></b><time><?= $h(date('j M, g:i a', strtotime($m['at']))) ?></time></div>
              <div class="msg-body"><?= $h($m['body']) ?></div>
            </div>
          </li>
          <?php endforeach; ?>
        </ol>
        <?php if ($open['can_reply']): ?>
        <form class="msg-reply" id="msg-reply" data-id="<?= $open['id'] ?>" novalidate>
          <textarea name="body" id="msg-reply-body" rows="2" placeholder="Write a reply… (Enter to send, Shift+Enter for a new line)" maxlength="4000" aria-label="Reply"></textarea>
          <button type="submit" class="msg-send" aria-label="Send"><span class="material-symbols-outlined">send</span></button>
          <p class="msg-err" id="msg-reply-err" role="alert"></p>
        </form>
        <?php elseif ($open['broadcast'] && $open['sender'] && $open['sender']['active']): ?>
        <div class="msg-closed"><a class="msg-btn" href="/messages?to=<?= (int) $open['sender']['id'] ?>&amp;subject=<?= rawurlencode('Re: ' . $open['subject']) ?>">
          <span class="material-symbols-outlined">reply</span> Reply to <?= $h($open['sender']['first']) ?> privately</a></div>
        <?php elseif ($open['broadcast']): ?>
        <div class="msg-closed"><span class="material-symbols-outlined">campaign</span> A broadcast from an admin.</div>
        <?php else: ?>
        <div class="msg-closed"><span class="material-symbols-outlined">info</span> Nobody else in this conversation still has an active CRM login, so it is closed.</div>
        <?php endif; ?>
      <?php elseif ($composing): ?>
        <header class="msg-head"><div><h2>New message</h2><p>Pick who to write to from the list.</p></div></header>
        <form class="msg-compose" id="msg-compose" novalidate>
          <label class="msg-label" for="msg-to">To</label>
          <div class="msg-chips" id="msg-chips"></div>
          <select id="msg-to" class="msg-input">
            <option value="">Pick a colleague…</option>
            <?php foreach ($byRole as $role => $list): ?>
            <optgroup label="<?= $h($role) ?>">
              <?php foreach ($list as $p): ?><option value="<?= $p['id'] ?>"><?= $h($p['name']) ?></option><?php endforeach; ?>
            </optgroup>
            <?php endforeach; ?>
          </select>
          <p class="msg-err" id="msg-to-err" role="alert"></p>
          <label class="msg-label" for="msg-subject">Subject <span class="msg-opt">(optional)</span></label>
          <input id="msg-subject" class="msg-input" maxlength="160" value="<?= $h($preSubject) ?>">
          <label class="msg-label" for="msg-body">Message</label>
          <textarea id="msg-body" class="msg-input" rows="7" maxlength="4000" placeholder="Write in English, Hindi or Hinglish"></textarea>
          <p class="msg-err" id="msg-body-err" role="alert"></p>
          <div class="msg-actions"><a href="/messages" class="msg-btn-quiet">Cancel</a>
            <button type="submit" class="msg-btn"><span class="material-symbols-outlined">send</span> Send</button></div>
        </form>
      <?php elseif ($broadcasting): ?>
        <header class="msg-head"><div><h2><span class="material-symbols-outlined align-text-bottom text-amber-700">campaign</span> Broadcast</h2><p>One message to everyone, or to the groups you pick. They can't reply into it; they can reply to you privately.</p></div></header>
        <form class="msg-compose" id="msg-broadcast" novalidate>
          <span class="msg-label">To</span>
          <div class="msg-groups" role="group" aria-label="Who it goes to">
            <?php $total = array_sum($groups['counts']); ?>
            <label class="msg-group is-all"><input type="checkbox" name="groups" value="everyone" checked> <b>Everyone</b> <small><?= (int) $total ?> people</small></label>
            <?php foreach ($groups['labels'] as $key => $label): ?>
            <label class="msg-group"><input type="checkbox" name="groups" value="<?= $h($key) ?>"<?= $groups['counts'][$key] ? '' : ' disabled' ?>> <?= $h($label) ?> <small><?= (int) $groups['counts'][$key] ?></small></label>
            <?php endforeach; ?>
          </div>
          <p class="msg-err" id="msg-groups-err" role="alert"></p>
          <label class="msg-label" for="msg-bsubject">Subject</label>
          <input id="msg-bsubject" class="msg-input" maxlength="160" placeholder="For example: Office closed on Diwali">
          <p class="msg-err" id="msg-bsubject-err" role="alert"></p>
          <label class="msg-label" for="msg-bbody">Message</label>
          <textarea id="msg-bbody" class="msg-input" rows="8" maxlength="4000"></textarea>
          <p class="msg-err" id="msg-bbody-err" role="alert"></p>
          <div class="msg-actions"><a href="/messages" class="msg-btn-quiet">Cancel</a>
            <button type="submit" class="msg-btn msg-btn--amber"><span class="material-symbols-outlined">campaign</span> <span id="msg-bsend">Send to everyone</span></button></div>
        </form>
      <?php else: ?>
        <div class="msg-blank"><span class="material-symbols-outlined">forum</span><b>Pick a conversation</b><p>Or start one with <a href="/messages?new=1">New message</a>.</p></div>
      <?php endif; ?>
    </section>
  </div>
</main>

<script type="application/json" id="msg-boot"><?= json_encode($boot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="<?= \App\Services\Asset::url('assets/js/messages.js') ?>"></script>
</body>
</html>
