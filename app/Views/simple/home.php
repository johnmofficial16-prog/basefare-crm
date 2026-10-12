<?php
/**
 * Simple view — Home: today at a glance.
 * @var array $today, $yesterday, $top, $teamNow, $pending, $attention
 * @var string $shiftLabel, $currency, $meName
 */
$pageTitle = 'Home';
require __DIR__ . '/_head.php';

$hour = (int) date('G');
$greet = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
$first = explode(' ', trim($meName))[0] ?: '';

$stats = [
    ['Sales', sv_money($today['sales'], $currency), sv_delta($today['sales'], $yesterday['sales']), 'payments',
     'The total amount customers were charged for bookings made today.'],
    ['Profit', sv_money($today['profit'], $currency), sv_delta($today['profit'], $yesterday['profit']), 'savings',
     'What Base Fare keeps: our service fees on today\'s bookings, minus any refunds. (In the full CRM this is called "Net MCO".)'],
    ['Bookings', number_format($today['bookings']), sv_delta($today['bookings'], $yesterday['bookings']), 'confirmation_number',
     'How many bookings the team recorded today. Cancelled (voided) bookings are not counted.'],
];
$toneCls = ['up' => 'bg-emerald-50 text-emerald-700', 'down' => 'bg-rose-50 text-rose-700', 'flat' => 'bg-slate-100 text-slate-600'];

$waitingItems = [
    ['bookings', 'confirmation_number', 'booking', 'bookings', 'to approve'],
    ['emails',   'mail',                'customer email', 'customer emails', 'to check before sending'],
    ['invoices', 'request_quote',       'invoice', 'invoices', 'to check before sending'],
    ['clockins', 'schedule',            'late clock-in request', 'late clock-in requests', 'to allow or refuse'],
    ['shifts',   'calendar_month',      'shift schedule', 'shift schedules', 'to approve'],
];
$tn = $teamNow;
$toneDot = ['rose' => 'bg-rose-100 text-rose-700', 'amber' => 'bg-amber-100 text-amber-700', 'slate' => 'bg-slate-100 text-slate-600'];
?>

<header class="mb-8">
  <h1 class="font-headline text-[30px] font-extrabold tracking-tight"><?= sv_e($greet . ($first ? ', ' . $first : '')) ?></h1>
  <p class="text-[15px] text-slate-500 mt-1 flex items-center gap-1.5">
    Here's how the business is doing today.
    <span class="tip" tabindex="0">
      <span class="material-symbols-outlined text-[18px] text-slate-400">info</span>
      <span class="tip-box">"Today" means the current work shift: <?= sv_e($shiftLabel) ?>. The team works overnight, so the day starts at 6 PM.</span>
    </span>
  </p>
</header>

<!-- Today's numbers -->
<section class="grid grid-cols-3 gap-5 mb-6" aria-label="Today's numbers">
  <?php foreach ($stats as [$label, $value, $delta, $icon, $help]): ?>
  <div class="card p-6">
    <div class="flex items-center justify-between">
      <span class="flex items-center gap-2 text-[15px] font-semibold text-slate-500">
        <span class="material-symbols-outlined text-[20px] text-primary"><?= $icon ?></span><?= $label ?> today
        <span class="tip" tabindex="0"><span class="material-symbols-outlined text-[16px] text-slate-300">help</span><span class="tip-box"><?= sv_e($help) ?></span></span>
      </span>
    </div>
    <div class="font-headline text-[34px] font-extrabold mt-3 tracking-tight"><?= sv_e($value) ?></div>
    <div class="mt-2 flex items-center gap-2 text-[13px]">
      <span class="px-2 py-0.5 rounded-full font-semibold <?= $toneCls[$delta['tone']] ?>"><?= sv_e($delta['text']) ?></span>
      <span class="text-slate-400">vs. this time yesterday</span>
    </div>
  </div>
  <?php endforeach; ?>
</section>
<?php if ($today['waiting'] > 0): ?>
<p class="text-[14px] text-slate-500 -mt-2 mb-6">
  Includes <?= (int) $today['waiting'] ?> booking<?= $today['waiting'] > 1 ? 's' : '' ?> still waiting for approval.
  <a href="/simple/approvals" class="text-primary font-semibold hover:underline">Review them</a>
</p>
<?php endif; ?>

<div class="grid grid-cols-5 gap-5">
  <!-- Waiting for you -->
  <section class="card p-6 col-span-2">
    <h2 class="font-headline text-[19px] font-bold flex items-center gap-2">
      <span class="material-symbols-outlined text-primary">task_alt</span> Waiting for you
    </h2>
    <?php if (($pending['total'] ?? 0) === 0): ?>
      <div class="mt-6 mb-2 text-center">
        <span class="material-symbols-outlined text-[44px] text-emerald-500">verified</span>
        <p class="font-semibold text-[16px] mt-1">All caught up</p>
        <p class="text-[14px] text-slate-500">Nothing is waiting for your approval right now.</p>
      </div>
    <?php else: ?>
      <ul class="mt-4 space-y-2">
        <?php foreach ($waitingItems as [$k, $icon, $one, $many, $what]): $n = (int) ($pending[$k] ?? 0); if (!$n) continue; ?>
        <li class="flex items-center gap-3 p-3 rounded-xl bg-slate-50">
          <span class="w-9 h-9 rounded-lg bg-white border border-slate-200 flex items-center justify-center">
            <span class="material-symbols-outlined text-[20px] text-primary"><?= $icon ?></span>
          </span>
          <span class="text-[15px]"><strong><?= $n ?></strong> <?= $n === 1 ? $one : $many ?> <span class="text-slate-500"><?= $what ?></span></span>
        </li>
        <?php endforeach; ?>
      </ul>
      <a href="/simple/approvals" class="mt-5 inline-flex items-center justify-center gap-2 w-full py-3 rounded-xl bg-primary text-white font-bold text-[15px] hover:bg-[#0f2557] no-underline">
        Review approvals <span class="material-symbols-outlined text-[20px]">arrow_forward</span>
      </a>
    <?php endif; ?>
  </section>

  <!-- Team right now -->
  <section class="card p-6 col-span-3">
    <div class="flex items-center justify-between">
      <h2 class="font-headline text-[19px] font-bold flex items-center gap-2">
        <span class="material-symbols-outlined text-primary">groups</span> Team right now
      </h2>
      <a href="/simple/team" class="text-[14px] font-semibold text-primary hover:underline">See everyone</a>
    </div>
    <div class="grid grid-cols-4 gap-3 mt-4">
      <?php foreach ([
          ['Working', count($tn['in']), 'bg-emerald-500', 'Clocked in and at work'],
          ['On a break', count($tn['on_break']), 'bg-amber-400', 'Clocked in, taking a break'],
          ['Finished', count($tn['finished']), 'bg-slate-400', 'Already clocked out for today'],
          ['Not in', count($tn['not_in']), 'bg-slate-200', 'Not clocked in today (may simply be off today)'],
      ] as [$lbl, $n, $dot, $hint]): ?>
      <div class="rounded-xl bg-slate-50 p-3" title="<?= sv_e($hint) ?>">
        <div class="flex items-center gap-2 text-[13px] font-semibold text-slate-500"><span class="w-2.5 h-2.5 rounded-full <?= $dot ?>"></span><?= $lbl ?></div>
        <div class="font-headline text-[26px] font-extrabold mt-1"><?= $n ?></div>
      </div>
      <?php endforeach; ?>
    </div>

    <?php if ($tn['asking']): ?>
    <div class="mt-4 p-3 rounded-xl bg-amber-50 border border-amber-200 text-[14px] text-amber-900 flex items-center gap-2">
      <span class="material-symbols-outlined text-[20px]">schedule</span>
      <span><strong><?= sv_e(implode(', ', array_column($tn['asking'], 'name'))) ?></strong> tried to clock in outside their shift and <?= count($tn['asking']) > 1 ? 'are' : 'is' ?> waiting.</span>
      <a href="/simple/approvals#clockins" class="ml-auto font-semibold underline">Decide</a>
    </div>
    <?php endif; ?>

    <?php if ($tn['in'] || $tn['on_break']): ?>
    <div class="mt-4">
      <p class="text-[13px] font-semibold text-slate-500 mb-2">At work now</p>
      <div class="flex flex-wrap gap-2">
        <?php foreach ($tn['in'] as $p): ?>
        <span class="inline-flex items-center gap-2 pl-1 pr-3 py-1 rounded-full bg-emerald-50 text-[14px] text-emerald-900" title="Clocked in at <?= sv_e($p['since']) ?><?= $p['late'] > 0 ? ' · ' . $p['late'] . ' min late' : '' ?>">
          <span class="w-7 h-7 rounded-full bg-emerald-500 text-white text-[11px] font-bold flex items-center justify-center"><?= sv_e(sv_initials($p['name'])) ?></span>
          <?= sv_e($p['name']) ?><?php if ($p['late'] > 0): ?><span class="text-[12px] text-amber-700 font-semibold">late</span><?php endif; ?>
        </span>
        <?php endforeach; ?>
        <?php foreach ($tn['on_break'] as $p): ?>
        <span class="inline-flex items-center gap-2 pl-1 pr-3 py-1 rounded-full bg-amber-50 text-[14px] text-amber-900" title="On a break since <?= sv_e($p['since']) ?>">
          <span class="w-7 h-7 rounded-full bg-amber-400 text-white text-[11px] font-bold flex items-center justify-center"><?= sv_e(sv_initials($p['name'])) ?></span>
          <?= sv_e($p['name']) ?> <span class="text-[12px]">on break</span>
        </span>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </section>

  <!-- Top performers -->
  <section class="card p-6 col-span-2">
    <h2 class="font-headline text-[19px] font-bold flex items-center gap-2">
      <span class="material-symbols-outlined text-primary">emoji_events</span> Top performers today
    </h2>
    <?php if (!$top): ?>
      <p class="text-[15px] text-slate-500 mt-4">No bookings yet today.</p>
    <?php else: ?>
    <ol class="mt-4 space-y-3">
      <?php foreach ($top as $i => $r): ?>
      <li class="flex items-center gap-3">
        <span class="w-7 text-center font-headline font-extrabold text-[16px] <?= $i === 0 ? 'text-amber-500' : 'text-slate-400' ?>"><?= $i + 1 ?></span>
        <span class="flex-1 min-w-0">
          <span class="block text-[15px] font-semibold truncate"><?= sv_e($r['name']) ?></span>
          <span class="block text-[13px] text-slate-500"><?= $r['bookings'] ?> booking<?= $r['bookings'] > 1 ? 's' : '' ?></span>
        </span>
        <span class="text-[15px] font-bold"><?= sv_e(sv_money($r['profit'], $currency)) ?></span>
      </li>
      <?php endforeach; ?>
    </ol>
    <a href="/simple/sales" class="mt-4 inline-block text-[14px] font-semibold text-primary hover:underline">See all sales</a>
    <?php endif; ?>
  </section>

  <!-- Worth a look -->
  <section class="card p-6 col-span-3">
    <h2 class="font-headline text-[19px] font-bold flex items-center gap-2">
      <span class="material-symbols-outlined text-primary">visibility</span> Worth a look
    </h2>
    <?php if (!$attention): ?>
      <p class="text-[15px] text-slate-500 mt-4">Nothing unusual — no open disputes, recent refunds or open chargebacks.</p>
    <?php else: ?>
    <ul class="mt-3 divide-y divide-slate-100">
      <?php foreach (array_slice($attention, 0, 8) as $a): ?>
      <li>
        <a href="<?= sv_e($a['href']) ?>" class="flex items-center gap-3 py-3 no-underline group">
          <span class="w-9 h-9 rounded-lg flex items-center justify-center <?= $toneDot[$a['tone']] ?? $toneDot['slate'] ?>">
            <span class="material-symbols-outlined text-[20px]"><?= sv_e($a['icon']) ?></span>
          </span>
          <span class="flex-1 min-w-0">
            <span class="block text-[15px] font-semibold text-ink group-hover:text-primary truncate"><?= sv_e($a['title']) ?></span>
            <span class="block text-[13px] text-slate-500 truncate"><?= sv_e($a['detail']) ?></span>
          </span>
          <span class="material-symbols-outlined text-slate-300 group-hover:text-primary">chevron_right</span>
        </a>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </section>
</div>

<?php require __DIR__ . '/_foot.php'; ?>
