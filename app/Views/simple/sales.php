<?php
/**
 * Simple view — Sales: totals, by centre, by person for a chosen period.
 * @var string $period, $label, $currency
 * @var array  $totals, $agents, $centres
 */
$pageTitle = 'Sales';
require __DIR__ . '/_head.php';

$maxCentre = max(1, ...array_map(fn($c) => max(0, $c['profit']), array_values($centres) ?: [['profit' => 0]]));
$maxAgent  = max(1, ...array_map(fn($r) => max(0, $r['profit']), $agents ?: [['profit' => 0]]));
?>

<header class="mb-6 flex items-end justify-between gap-6">
  <div>
    <h1 class="font-headline text-[30px] font-extrabold tracking-tight">Sales</h1>
    <p class="text-[15px] text-slate-500 mt-1"><?= sv_e($label) ?></p>
  </div>
  <nav class="flex bg-white border border-slate-200 rounded-xl p-1" aria-label="Choose a period">
    <?php foreach (\App\Services\SimpleViewService::PERIODS as $k => $lbl): ?>
    <a href="/simple/sales?period=<?= $k ?>" class="px-4 py-2 rounded-lg text-[14px] font-semibold no-underline <?= $period === $k ? 'bg-primary text-white' : 'text-slate-600 hover:bg-slate-100' ?>"><?= $lbl ?></a>
    <?php endforeach; ?>
  </nav>
</header>

<section class="grid grid-cols-4 gap-5 mb-8">
  <?php foreach ([
      ['Sales', sv_money($totals['sales'], $currency), 'payments', 'Total charged to customers.'],
      ['Profit', sv_money($totals['profit'], $currency), 'savings', 'What Base Fare keeps after refunds (Net MCO).'],
      ['Bookings', number_format($totals['bookings']), 'confirmation_number', $totals['waiting'] ? $totals['waiting'] . ' still waiting for approval' : 'All approved'],
      ['Refunds', $totals['refunds'] ? sv_money($totals['refunded'], $currency) : 'None', 'undo', $totals['refunds'] ? $totals['refunds'] . ' refund' . ($totals['refunds'] > 1 ? 's' : '') . ' given' : 'No refunds in this period'],
  ] as [$lbl, $val, $icon, $sub]): ?>
  <div class="card p-6">
    <div class="flex items-center gap-2 text-[15px] font-semibold text-slate-500"><span class="material-symbols-outlined text-[20px] text-primary"><?= $icon ?></span><?= $lbl ?></div>
    <div class="font-headline text-[30px] font-extrabold mt-2 tracking-tight"><?= sv_e($val) ?></div>
    <div class="text-[13px] text-slate-500 mt-1"><?= sv_e($sub) ?></div>
  </div>
  <?php endforeach; ?>
</section>

<?php if (!$agents): ?>
<div class="card p-12 text-center">
  <span class="material-symbols-outlined text-[52px] text-slate-300">bar_chart</span>
  <p class="font-headline text-[20px] font-bold mt-2">No bookings in this period yet</p>
  <p class="text-[15px] text-slate-500 mt-1">Try a longer period, like "This month".</p>
</div>
<?php else: ?>
<div class="grid grid-cols-3 gap-5">
  <section class="card p-6">
    <h2 class="font-headline text-[19px] font-bold">By centre</h2>
    <p class="text-[13px] text-slate-500 mt-0.5">Profit</p>
    <ul class="mt-5 space-y-5">
      <?php foreach ($centres as $c): ?>
      <li>
        <div class="flex items-baseline justify-between text-[15px]">
          <span class="font-semibold"><?= sv_e($c['label']) ?></span>
          <span class="font-bold"><?= sv_e(sv_money($c['profit'], $currency)) ?></span>
        </div>
        <div class="mt-2 h-3 rounded-full bg-slate-100 overflow-hidden">
          <div class="h-full rounded-full" style="width:<?= round(max(0, $c['profit']) / $maxCentre * 100, 1) ?>%;background:<?= sv_e($c['color']) ?>"></div>
        </div>
        <div class="text-[13px] text-slate-500 mt-1"><?= $c['bookings'] ?> booking<?= $c['bookings'] === 1 ? '' : 's' ?> · sales <?= sv_e(sv_money($c['sales'], $currency)) ?></div>
      </li>
      <?php endforeach; ?>
    </ul>
  </section>

  <section class="card p-6 col-span-2">
    <h2 class="font-headline text-[19px] font-bold">By person</h2>
    <p class="text-[13px] text-slate-500 mt-0.5">Best first. Click a name to see their bookings.</p>
    <table class="w-full mt-4 text-[15px]">
      <thead>
        <tr class="text-left text-[13px] text-slate-500 border-b border-slate-200">
          <th class="py-2 font-semibold w-8">#</th>
          <th class="py-2 font-semibold">Name</th>
          <th class="py-2 font-semibold text-right">Bookings</th>
          <th class="py-2 font-semibold text-right">Sales</th>
          <th class="py-2 font-semibold text-right pr-1">Profit</th>
          <th class="py-2 w-32"></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($agents as $i => $r): ?>
        <tr class="border-b border-slate-100 last:border-0 hover:bg-slate-50">
          <td class="py-3 text-slate-400 font-semibold"><?= $i + 1 ?></td>
          <td class="py-3">
            <a href="/performance/agent/<?= $r['id'] ?>" class="font-semibold text-ink hover:text-primary no-underline"><?= sv_e($r['name']) ?></a>
            <?php if ($r['centre']): ?><span class="ml-1 text-[12px] text-slate-400"><?= sv_e($r['centre']) ?></span><?php endif; ?>
          </td>
          <td class="py-3 text-right"><?= $r['bookings'] ?></td>
          <td class="py-3 text-right text-slate-600"><?= sv_e(sv_money($r['sales'], $currency)) ?></td>
          <td class="py-3 text-right font-bold pr-1 <?= $r['profit'] < 0 ? 'text-rose-600' : '' ?>"><?= sv_e(sv_money($r['profit'], $currency)) ?></td>
          <td class="py-3 pl-3"><div class="h-2 rounded-full bg-slate-100"><div class="h-2 rounded-full bg-primary/70" style="width:<?= round(max(0, $r['profit']) / $maxAgent * 100, 1) ?>%"></div></div></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </section>
</div>
<?php endif; ?>

<p class="text-[13px] text-slate-400 mt-6 max-w-3xl">
  Sales and profit include bookings still waiting for approval; cancelled (voided) bookings are left out.
  Profit is Base Fare's fees minus refunds. "Today" and "Yesterday" follow the work shift (6 PM to 6 PM).
</p>

<?php require __DIR__ . '/_foot.php'; ?>
