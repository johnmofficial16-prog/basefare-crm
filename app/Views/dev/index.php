<?php
/**
 * Developer home (DevController::index): switch view, maintenance tools, recent dev activity.
 *
 * @var array   $people      view => list of {id, name, email, reports_to}
 * @var array   $recent      recent dev activity_log rows
 * @var array   $migrations  {ok, pending[], applied}
 * @var int     $errors24h   error_log rows in the last 24 h (-1 = table missing)
 * @var ?string $flash
 */
use App\Services\DevMode;

$e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$csrf = $e($_SESSION['csrf_token'] ?? '');
$view = DevMode::view();
$asName = $_SESSION['dev']['as_name'] ?? null;
$labels = ['dev_view' => 'Switched view', 'dev_view_as' => 'Viewing as', 'dev_action_as' => 'Action as', 'dev_open_hr' => 'Opened Trio HR', 'dev_logout' => 'Signed out'];
$cards = [
    'manager'    => ['Manager', 'supervisor_account', 'Sees their own team and floor only.'],
    'supervisor' => ['Team leader', 'groups', 'Sees their own agents only.'],
    'agent'      => ['Agent', 'person', 'Sees their own bookings, calls and KRA.'],
];
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<meta name="robots" content="noindex, nofollow">
<title>Dev - Base Fare CRM</title>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
<script src="<?= \App\Services\Asset::url('assets/js/error-beacon.js') ?>"></script>
<script src="/assets/js/tailwind.js"></script>
<script src="<?= \App\Services\Asset::url('assets/js/buddy-widget.js') ?>" defer></script>
<script>
tailwind.config={darkMode:"class",theme:{extend:{colors:{primary:"#163274","primary-container":"#314a8d",background:"#f4f6fb","on-surface":"#191c1d","on-surface-variant":"#5b6070"}}}};
</script>
<style>
  .d-card { background: rgb(var(--bf-white)); border: 1px solid rgb(var(--bf-slate-200)); border-radius: 18px; }
  .d-sel  { width: 100%; font-size: 16px; padding: 10px 12px; border-radius: 10px; border: 1px solid rgb(var(--bf-slate-300)); background: rgb(var(--bf-white)); }
  .d-btn  { display: inline-flex; align-items: center; justify-content: center; gap: 8px; min-height: 44px; padding: 0 18px; border-radius: 12px; font-size: 15px; font-weight: 600; }
  .d-on   { outline: 2px solid rgb(var(--bf-primary)); outline-offset: -2px; }
</style>
</head>
<body class="bg-background text-on-surface antialiased min-h-screen">

<?php require __DIR__ . '/../layout/sidebar.php'; ?>

<main class="ml-60 pt-6 pb-24 px-8 max-w-[1240px]">
<div class="flex flex-col gap-6 pr-16">

  <div>
    <p class="text-[14px] font-semibold uppercase tracking-wider text-on-surface-variant">Developer</p>
    <h1 class="text-[30px] font-headline font-extrabold leading-tight">Switch view</h1>
    <p class="text-[17px] text-on-surface-variant mt-1">
      Now: <strong><?= $e(DevMode::VIEWS[$view][0] ?? 'Dev') ?> view</strong><?= $asName ? ' as <strong>' . $e($asName) . '</strong>' : '' ?>.
      Everything you do while viewing as someone is logged as you.
    </p>
  </div>

  <?php if ($flash): ?>
  <div class="d-card px-5 py-4 text-[16px]" role="alert" style="border-color:rgb(var(--bf-rose-300))"><?= $e($flash) ?></div>
  <?php endif; ?>

  <div class="grid gap-4" style="grid-template-columns: repeat(auto-fit, minmax(280px, 1fr))">
    <?php foreach (['dev' => ['Dev', 'terminal', 'Your own account: admin plus these tools.'], 'admin' => ['Admin', 'shield_person', 'The CRM exactly as an admin sees it.']] as $k => [$label, $icon, $hint]): ?>
    <form method="POST" action="/dev/view" class="d-card p-6 flex flex-col gap-3 <?= $view === $k ? 'd-on' : '' ?>">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="view" value="<?= $k ?>">
      <p class="text-[19px] font-bold flex items-center gap-2"><span class="material-symbols-outlined"><?= $icon ?></span><?= $e($label) ?></p>
      <p class="text-[16px] text-on-surface-variant"><?= $e($hint) ?></p>
      <button type="submit" class="d-btn bg-primary text-white mt-auto"><?= $view === $k ? 'You\'re here' : 'Switch to ' . $e($label) ?></button>
    </form>
    <?php endforeach; ?>

    <?php foreach ($cards as $k => [$label, $icon, $hint]): ?>
    <form method="POST" action="/dev/view" class="d-card p-6 flex flex-col gap-3 <?= $view === $k ? 'd-on' : '' ?>">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="view" value="<?= $k ?>">
      <p class="text-[19px] font-bold flex items-center gap-2"><span class="material-symbols-outlined"><?= $icon ?></span><?= $e($label) ?></p>
      <p class="text-[16px] text-on-surface-variant"><?= $e($hint) ?></p>
      <label class="sr-only" for="p-<?= $k ?>">Who to view as</label>
      <select class="d-sel" id="p-<?= $k ?>" name="person" required>
        <option value="">Choose <?= $k === 'agent' ? 'an' : 'a' ?> <?= $e(strtolower($label)) ?>…</option>
        <?php foreach ($people[$k] as $p): ?>
        <option value="<?= (int) $p->id ?>" <?= (int) DevMode::actingAs() === (int) $p->id ? 'selected' : '' ?>><?= $e($p->name) ?><?= $p->reports_to ? ' — ' . $e($p->reports_to) : '' ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="d-btn bg-primary text-white"><?= count($people[$k]) ? 'View as this ' . $e(strtolower($label)) : 'Nobody to view as' ?></button>
    </form>
    <?php endforeach; ?>
  </div>

  <h2 class="text-[22px] font-headline font-extrabold mt-2">Tools</h2>
  <div class="grid gap-4" style="grid-template-columns: repeat(auto-fit, minmax(240px, 1fr))">
    <a href="/admin/error-console" class="d-card p-6 block hover:shadow-md">
      <p class="text-[18px] font-bold">Error console</p>
      <p class="text-[16px] text-on-surface-variant mt-1"><?= $errors24h < 0 ? 'Error log table not set up.' : $errors24h . ' error' . ($errors24h === 1 ? '' : 's') . ' in the last 24 hours.' ?></p>
    </a>
    <a href="/admin/activity-log" class="d-card p-6 block hover:shadow-md">
      <p class="text-[18px] font-bold">Activity log</p>
      <p class="text-[16px] text-on-surface-variant mt-1">Every sign-in, change and dev action.</p>
    </a>
    <a href="/hr" target="_blank" rel="noopener" class="d-card p-6 block hover:shadow-md">
      <p class="text-[18px] font-bold">Trio HR</p>
      <p class="text-[16px] text-on-surface-variant mt-1">Opens as owner, no extra sign-in.</p>
    </a>
    <div class="d-card p-6">
      <p class="text-[18px] font-bold">Database migrations</p>
      <p class="text-[16px] text-on-surface-variant mt-1">
        <?php if (!$migrations['ok']): ?>Ledger not found.
        <?php elseif ($migrations['pending']): ?><strong style="color:rgb(var(--bf-orange-700))"><?= count($migrations['pending']) ?> waiting</strong>: <?= $e(implode(', ', $migrations['pending'])) ?>. Run <code>php hostinger_migrate.php</code>.
        <?php else: ?>All <?= (int) $migrations['applied'] ?> applied.<?php endif; ?>
      </p>
    </div>
  </div>
  <p class="text-[15px] text-on-surface-variant">Aisha's maintenance tools (health, errors, backups, migrations) are in the chat bubble at the bottom right.</p>

  <h2 class="text-[22px] font-headline font-extrabold mt-2">Your recent dev activity</h2>
  <div class="d-card overflow-x-auto py-2">
    <table class="w-full text-[16px]" style="min-width:620px;border-collapse:collapse">
      <thead><tr class="text-left text-[14px] text-on-surface-variant"><th class="px-5 py-3 font-semibold">When</th><th class="px-5 py-3 font-semibold">What</th><th class="px-5 py-3 font-semibold">Details</th></tr></thead>
      <tbody>
      <?php if (!$recent): ?>
        <tr><td colspan="3" class="px-5 py-4 text-on-surface-variant">Nothing yet.</td></tr>
      <?php endif; ?>
      <?php foreach ($recent as $r): $d = json_decode((string) $r->details, true) ?: []; ?>
        <tr style="border-top:1px solid rgb(var(--bf-slate-100))">
          <td class="px-5 py-3 whitespace-nowrap"><?= $e(date('j M, g:i a', strtotime($r->created_at))) ?></td>
          <td class="px-5 py-3"><?= $e($labels[$r->action] ?? $r->action) ?><?= $r->as_name ? ' <strong>' . $e($r->as_name) . '</strong>' : '' ?></td>
          <td class="px-5 py-3 text-on-surface-variant"><?= $e($d['request'] ?? ($d['view'] ?? '')) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

</div>
</main>
</body>
</html>
