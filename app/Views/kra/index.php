<?php
/**
 * My KRA — agents, team leaders and managers (KraController::page).
 *
 * @var array|null $card    KraEngine::card() — null when HR hasn't saved any month yet
 * @var bool       $failed  the engine could not run (shown as a calm notice, never an error page)
 */
$role      = (string) ($_SESSION['role'] ?? 'agent');
$firstName = explode(' ', trim((string) ($_SESSION['name'] ?? $_SESSION['user_name'] ?? 'there')))[0];
$e   = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$usd = fn($v) => ($v < 0 ? '−$' : '$') . number_format(abs((float) $v));
/** ₹ with Indian grouping: 1,23,456 */
$inr = function ($v): string {
    $n = (string) (int) round(abs((float) $v));
    if (strlen($n) > 3) {
        $last = substr($n, -3);
        $rest = substr($n, 0, -3);
        $n = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest) . ',' . $last;
    }
    return ($v < 0 ? '−₹' : '₹') . $n;
};
$me      = $card['me'] ?? null;
$monthLb = isset($card['month']) ? date('F Y', strtotime($card['month'] . '-01')) : date('F Y');
$hour    = (int) date('G');
$hello   = $hour < 5 ? 'Hi' : ($hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening'));
$title   = $role === 'manager' ? 'Floor KRA' : 'My KRA';
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<title><?= $title ?> - Base Fare CRM</title>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
<script src="<?= \App\Services\Asset::url('assets/js/error-beacon.js') ?>"></script>
<script src="/assets/js/tailwind.js"></script>
<script src="<?= \App\Services\Asset::url('assets/js/buddy-widget.js') ?>" defer></script>
<script>
tailwind.config={darkMode:"class",theme:{extend:{colors:{primary:"#163274","primary-container":"#314a8d",background:"#f4f6fb","on-surface":"#191c1d","on-surface-variant":"#5b6070"},fontFamily:{headline:["Manrope"],body:["Inter"]}}}};
</script>
<style>
  body { font-family: Inter, sans-serif; }
  .k-card { background: #fff; border: 1px solid #e2e5ee; border-radius: 20px; }
  .k-num  { font-family: Manrope, Inter, sans-serif; font-weight: 800; }
  .k-chip { display: inline-block; font-size: 14px; font-weight: 600; border-radius: 8px; padding: 4px 10px; }
  .k-ok   { color: #0f5c3c; background: #e8f6ef; }
  .k-warn { color: #8a5300; background: #fff4e0; }
  .k-bar  { position: relative; height: 14px; border-radius: 7px; background: #e6e9f2; }
  .k-fill { position: absolute; left: 0; top: 0; bottom: 0; border-radius: 7px; background: #2f5bd3; }
  .k-mark { position: absolute; top: -6px; width: 2px; height: 26px; background: #5b6070; }
  .k-mlab { position: absolute; top: 24px; transform: translateX(-50%); font-size: 13px; color: #5b6070; white-space: nowrap; }
  .k-row  { display: flex; justify-content: space-between; align-items: baseline; gap: 12px; }
  .k-row strong { white-space: nowrap; }
</style>
</head>
<body class="bg-background font-body text-on-surface antialiased min-h-screen">

<?php require __DIR__ . '/../layout/sidebar.php'; ?>

<main class="ml-60 pt-6 pb-20 px-8 max-w-[1240px]">
<div class="flex flex-col gap-5 pr-16">

  <div class="flex flex-wrap items-end justify-between gap-3">
    <div>
      <p class="text-[14px] font-semibold uppercase tracking-wider text-on-surface-variant mb-1"><?= $e($title) ?> · <?= $e($monthLb) ?><?= $role === 'supervisor' ? ' · Team leader' : '' ?></p>
      <h1 class="text-[30px] font-headline font-extrabold text-primary leading-tight"><?= $e($hello) ?>, <?= $e($firstName) ?></h1>
    </div>
    <?php if (!empty($card['live']) && isset($card['days_left'])): ?>
    <p class="text-[15px] text-on-surface-variant"><?= (int) $card['days_left'] ?> <?= (int) $card['days_left'] === 1 ? 'day' : 'days' ?> left this month</p>
    <?php endif; ?>
  </div>

<?php if ($failed): ?>
  <div class="k-card p-7 text-[16px] leading-relaxed">Your KRA couldn't be loaded just now. Please try again in a few minutes.</div>

<?php elseif ($card === null): ?>
  <div class="k-card p-7">
    <h2 class="text-[20px] font-headline font-extrabold mb-2">Your KRA starts soon</h2>
    <p class="text-[16px] leading-relaxed text-on-surface-variant">Targets and incentives for this month haven't been set up yet. As soon as they are, this page will show where you stand, what you've earned and what to work on.</p>
  </div>

<?php elseif (empty($card['in_kra'])): ?>
  <div class="k-card p-7 text-[16px] leading-relaxed">You're not part of a KRA this month.</div>

<?php else: ?>

  <?php if (!empty($card['booster'])): $bo = $card['booster']; ?>
  <div class="flex flex-wrap items-center gap-4 px-5 py-4 rounded-2xl text-white" style="background:#163274">
    <div style="flex: 999 1 420px; min-width: 0">
      <p class="text-[13px] font-bold uppercase tracking-wider mb-1" style="color:#c9d5ff"><?= strtotime($bo['ends_at']) - time() > 20 * 3600 ? 'Booster on now' : 'Tonight\'s booster' ?></p>
      <p class="text-[18px] font-semibold leading-snug"><?= $e($bo['rule']) ?> — until <?= $e(\App\Services\Kra\KraEngine::endsLabel($bo['ends_at'])) ?></p>
    </div>
    <p class="text-[15px]" style="flex: 1 1 200px; color:#dfe6ff"><?= $bo['earned'] > 0 ? 'You\'ve earned ' . $inr($bo['earned']) . ' from it so far' : 'Every qualifying sale counts' ?></p>
  </div>
  <?php endif; ?>

  <?php if ($role !== 'manager'): ?>
    <?php if (!$me['set_up']): ?>
    <div class="k-card p-7">
      <h2 class="text-[20px] font-headline font-extrabold mb-2">Your target hasn't been set yet</h2>
      <p class="text-[16px] leading-relaxed text-on-surface-variant">HR still needs to set your target for this month. Meanwhile, here's how your month is going.</p>
      <p class="mt-4 text-[16px]">Net MCO so far: <strong><?= $usd($me['mco']) ?></strong> from <?= (int) $me['sales'] ?> <?= $me['sales'] === 1 ? 'sale' : 'sales' ?>.</p>
    </div>
    <?php else: $lv = $me['levels']; $end = max(1, $lv['l3'] * 1.06); ?>
    <div class="flex flex-wrap gap-5">
      <section class="k-card p-7" style="flex: 999 1 560px; min-width: 0">
        <p class="text-[15px] font-semibold text-on-surface-variant mb-1">Net MCO this month</p>
        <div class="flex flex-wrap items-baseline gap-3">
          <p class="k-num text-[46px] leading-none"><?= $usd($me['mco']) ?></p>
          <p class="text-[18px] text-on-surface-variant">of your <?= $usd($lv['l2']) ?> target · <?= (int) $me['progress'] ?>%</p>
        </div>
        <div class="k-bar mt-7 mb-11" role="img" aria-label="<?= (int) $me['progress'] ?>% of target">
          <div class="k-fill" style="width: <?= min(100, max(0, $me['mco'] / $end * 100)) ?>%"></div>
          <?php foreach ([1 => 'l1', 2 => 'l2', 3 => 'l3'] as $n => $k): $pos = $lv[$k] / $end * 100; ?>
          <div class="k-mark" style="left: <?= $pos ?>%"></div>
          <p class="k-mlab" style="left: <?= $pos ?>%">Level <?= $n ?> · <?= $usd($lv[$k]) ?></p>
          <?php endforeach; ?>
        </div>
        <p class="text-[17px] leading-relaxed">
        <?php if ($me['next'] === null): ?>
          <strong>You're past Level 3</strong> — every dollar now earns the top rate. Brilliant month.
        <?php elseif ($me['next']['level'] === 1): ?>
          <strong><?= $usd($me['next']['away']) ?> more to reach Level 1</strong>, where your incentive starts.<?= $card['nightly'] ? ' That\'s about <strong>' . $usd($card['nightly']) . ' a night</strong> for the nights left.' : '' ?>
        <?php else: ?>
          <strong><?= $usd($me['next']['away']) ?> more to reach Level <?= (int) $me['next']['level'] ?></strong>, where every dollar earns more.<?= $card['nightly'] ? ' That\'s about <strong>' . $usd($card['nightly']) . ' a night</strong> for the nights left.' : '' ?>
        <?php endif; ?>
        </p>
      </section>

      <section class="p-7 flex flex-col gap-2.5" style="flex: 1 1 300px; min-width: 0; background: #e8f6ef; border: 1px solid #bfe5d0; border-radius: 20px">
        <p class="text-[15px] font-semibold" style="color:#0f5c3c">My incentive so far</p>
        <p class="k-num text-[42px] leading-none" style="color:#0f5c3c"><?= $inr($me['incentive_total']) ?></p>
        <div class="flex flex-col gap-1.5 text-[15px]" style="color:#1d4d38">
          <p>Monthly incentive · <?= $inr($me['incentive']) ?></p>
          <?php if (isset($me['team']) && $role === 'supervisor'): ?>
          <p>Team share · <?= $inr($me['team']['share_inr']) ?></p>
          <p>Team bonus · <?= $me['team']['bonus_unlocked'] ? $inr($me['team']['bonus_inr']) : $inr($me['team']['bonus_amount']) . ' waiting (needs ' . (int) $me['team']['bonus_need'] . ' of ' . (int) $me['team']['set_up'] . ' past Level 1)' ?></p>
          <?php endif; ?>
          <?php if ($me['spot_inr'] > 0): ?><p>Spot rewards · <?= $inr($me['spot_inr']) ?></p><?php endif; ?>
          <?php if ($me['booster_inr'] > 0): ?><p>Boosters · <?= $inr($me['booster_inr']) ?></p><?php endif; ?>
          <?php if ($me['quality_hit']): ?>
          <p class="font-semibold" style="color:#8a5300">Cut by the refund limit this month</p>
          <?php endif; ?>
        </div>
        <p class="mt-1.5 text-[14px] leading-snug" style="color:#1d4d38">Earned so far from approved sales. Paid with next month's salary, after any refunds are taken off and within the company's monthly incentive budget.</p>
      </section>
    </div>

    <h2 class="mt-2 text-[22px] font-headline font-extrabold">How you're getting there</h2>
    <div class="grid gap-4" style="grid-template-columns: repeat(auto-fit, minmax(230px, 1fr))">
      <?php foreach ($me['levers'] as $l): $weak = ($me['gap']['lever'] ?? '') === $l['key']; ?>
      <div class="k-card p-5" style="border-radius:16px;<?= $weak ? 'border:2px solid #f0b35b' : '' ?>">
        <p class="text-[15px] font-semibold text-on-surface-variant mb-2"><?= $e($l['label']) ?></p>
        <p class="k-num text-[28px] leading-none"><?= $e($l['text']) ?></p>
        <p class="mt-2.5 k-chip <?= $l['ok'] ? 'k-ok' : 'k-warn' ?>"><?= $l['ok'] ? 'On target' : 'Target' ?> (<?= $e($l['target_text']) ?>)</p>
      </div>
      <?php endforeach; ?>
      <div class="k-card p-5" style="border-radius:16px">
        <p class="text-[15px] font-semibold text-on-surface-variant mb-2">Refunds &amp; disputes</p>
        <p class="k-num text-[28px] leading-none"><?= (int) $me['bad'] ?></p>
        <p class="mt-2.5 k-chip <?= $me['quality_hit'] ? 'k-warn' : 'k-ok' ?>"><?= $me['quality_hit'] ? 'Over the limit' : 'Quality gate clear' ?></p>
      </div>
    </div>

    <div class="flex flex-wrap gap-5">
      <?php if ($me['gap']): $g = $me['gap']; ?>
      <section class="k-card p-7" style="flex: 999 1 560px; min-width: 0">
        <p class="text-[14px] font-bold uppercase tracking-wider mb-1.5" style="color:#8a5300"><?= $e($g['title']) ?></p>
        <p class="text-[18px] leading-relaxed mb-4"><?= $e($g['text']) ?>
          <?php if (!empty($g['extra_mco'])): ?> That's roughly <strong><?= $usd($g['extra_mco']) ?> more Net MCO</strong><?= !empty($g['extra_inr']) ? ' and <strong>' . $inr($g['extra_inr']) . ' more incentive</strong>' : '' ?> this month.<?php endif; ?>
        </p>
        <p class="text-[16px] font-bold mb-2">Tonight, try this</p>
        <ol class="list-decimal pl-6 flex flex-col gap-2 text-[16px] leading-relaxed">
          <?php foreach ($g['steps'] as $s): ?><li><?= $e($s) ?></li><?php endforeach; ?>
        </ol>
        <button type="button" onclick="document.querySelector('.aw-orb') ? document.querySelector('.aw-orb').click() : null"
                class="mt-5 inline-flex items-center min-h-[44px] px-5 rounded-xl bg-primary text-white text-[15px] font-semibold hover:bg-primary-container">Ask Aisha for tips</button>
      </section>
      <?php else: ?>
      <section class="k-card p-7" style="flex: 999 1 560px; min-width: 0">
        <p class="text-[14px] font-bold uppercase tracking-wider mb-1.5" style="color:#0f5c3c">No gaps right now</p>
        <p class="text-[18px] leading-relaxed">All three levers are on target. Keep the rhythm going.</p>
      </section>
      <?php endif; ?>

      <section class="flex flex-col gap-4" style="flex: 1 1 300px; min-width: 0">
        <div class="k-card p-6">
          <p class="text-[16px] font-bold mb-3">Personal bests</p>
          <div class="flex flex-col gap-2 text-[15px] leading-snug">
            <p>Best month · <strong><?= $card['bests']['best_month'] ? $usd($card['bests']['best_month']['mco']) . '</strong> (' . $e($card['bests']['best_month']['month']) . ')' : '—</strong>' ?></p>
            <p>Biggest sale · <strong><?= $card['bests']['biggest'] !== null ? $usd($card['bests']['biggest']) . ' MCO' : '—' ?></strong></p>
            <p>Streak · <strong><?= (int) $card['bests']['streak'] ?> <?= (int) $card['bests']['streak'] === 1 ? 'shift' : 'shifts' ?></strong> in a row with a sale</p>
          </div>
        </div>
        <?php $paidSpots = array_values(array_filter($me['spots'], fn($s) => $s['paid'])); if ($paidSpots): ?>
        <div class="k-card p-6">
          <p class="text-[16px] font-bold mb-3">Spot rewards this month</p>
          <div class="flex flex-col gap-2 text-[15px] leading-snug">
            <?php foreach (array_slice(array_reverse($paidSpots), 0, 5) as $s): ?>
            <p class="k-row"><span><?= $e($s['label']) ?></span><strong><?= $inr($s['amount']) ?></strong></p>
            <?php endforeach; ?>
            <?php if (count($paidSpots) > 5): ?>
            <p class="text-on-surface-variant">+ <?= count($paidSpots) - 5 ?> earlier, <?= $inr($me['spot_inr']) ?> in all</p>
            <?php endif; ?>
          </div>
        </div>
        <?php endif; ?>
        <?php if ($card['feedback']): ?>
        <div class="k-card p-6">
          <p class="text-[16px] font-bold mb-2">Latest feedback</p>
          <p class="text-[15px] leading-relaxed whitespace-pre-line">"<?= $e($card['feedback']['body']) ?>"</p>
          <p class="mt-2 text-[13px] text-on-surface-variant">From HR · <?= $e(date('j M', strtotime($card['feedback']['at']))) ?></p>
        </div>
        <?php endif; ?>
      </section>
    </div>
    <?php endif; ?>
  <?php endif; ?>

  <?php if (isset($me['team']) && $me['team']['count'] === 0): ?>
  <div class="k-card p-7">
    <h2 class="text-[20px] font-headline font-extrabold mb-2"><?= $role === 'manager' ? 'No floor linked yet' : 'No team linked yet' ?></h2>
    <p class="text-[16px] leading-relaxed text-on-surface-variant">Nobody reports to you in the CRM yet, so there's no <?= $role === 'manager' ? 'floor' : 'team' ?> to show. An admin can set who reports to whom on each person's CRM profile.</p>
  </div>
  <?php elseif (isset($me['team'])): $t = $me['team']; ?>
    <?php if ($role === 'manager'): ?>
    <div class="flex flex-wrap gap-5">
      <section class="k-card p-7" style="flex: 999 1 560px; min-width: 0">
        <p class="text-[15px] font-semibold text-on-surface-variant mb-1">Floor Net MCO this month</p>
        <div class="flex flex-wrap items-baseline gap-3">
          <p class="k-num text-[46px] leading-none"><?= $usd($t['mco']) ?></p>
          <?php if ($t['target'] > 0): ?><p class="text-[18px] text-on-surface-variant">of <?= $usd($t['target']) ?> floor target · <?= (int) $t['progress'] ?>%</p><?php endif; ?>
        </div>
        <div class="k-bar mt-6 mb-5"><div class="k-fill" style="width: <?= min(100, (int) ($t['progress'] ?? 0)) ?>%"></div></div>
        <p class="text-[16px] leading-relaxed">At this pace the month ends near <strong><?= $usd($t['projected']) ?></strong><?= $t['target'] > 0 ? ($t['projected'] >= $t['target'] ? ' — <strong>above target</strong>.' : ' — <strong>' . $usd($t['target'] - $t['projected']) . ' short</strong>.') : '.' ?></p>
      </section>
      <section class="p-7 flex flex-col gap-2.5" style="flex: 1 1 300px; min-width: 0; background: #e8f6ef; border: 1px solid #bfe5d0; border-radius: 20px">
        <p class="text-[15px] font-semibold" style="color:#0f5c3c">My incentive so far</p>
        <p class="k-num text-[42px] leading-none" style="color:#0f5c3c"><?= $inr($me['incentive_total']) ?></p>
        <p class="text-[15px] leading-snug" style="color:#1d4d38">Your share of floor MCO above the floor target.</p>
        <p class="mt-1.5 text-[14px]" style="color:#1d4d38">Projected · paid with next month's salary.</p>
      </section>
    </div>
    <?php if (!empty($card['teams'])): ?>
    <h2 class="mt-2 text-[22px] font-headline font-extrabold">Teams</h2>
    <div class="grid gap-4" style="grid-template-columns: repeat(auto-fit, minmax(300px, 1fr))">
      <?php foreach ($card['teams'] as $tm): ?>
      <div class="k-card p-6" style="border-radius:16px">
        <div class="flex justify-between items-baseline gap-2"><p class="text-[18px] font-bold"><?= $e($tm['name']) ?>'s team</p><p class="text-[16px] font-bold"><?= $tm['progress'] === null ? '—' : (int) $tm['progress'] . '%' ?></p></div>
        <div class="k-bar my-3" style="height:10px"><div class="k-fill" style="width: <?= min(100, (int) ($tm['progress'] ?? 0)) ?>%"></div></div>
        <p class="text-[16px]"><?= (int) $tm['past_l1'] ?> of <?= (int) $tm['count'] ?> past Level 1</p>
        <?php if ($tm['weakest']): ?><p class="text-[15px] mt-1" style="color:#8a5300">Weakest area: <?= $e($tm['weakest']) ?></p><?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php if (!empty($card['watch'])): ?>
    <h2 class="mt-2 text-[22px] font-headline font-extrabold">Watch list</h2>
    <div class="flex flex-col gap-2.5">
      <?php foreach ($card['watch'] as $w): ?>
      <div class="k-card px-5 py-4 text-[16px]" style="border-radius:14px"><strong><?= $e($w['name']) ?></strong> <?= $e($w['text']) ?></div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php else: ?>
    <section class="k-card p-7">
      <p class="text-[15px] font-semibold text-on-surface-variant mb-1">Team Net MCO this month</p>
      <div class="flex flex-wrap items-baseline gap-3">
        <p class="k-num text-[40px] leading-none"><?= $usd($t['mco']) ?></p>
        <?php if ($t['target'] > 0): ?><p class="text-[18px] text-on-surface-variant">of <?= $usd($t['target']) ?> team target · <?= (int) $t['progress'] ?>%</p><?php endif; ?>
      </div>
      <div class="k-bar mt-6 mb-5"><div class="k-fill" style="width: <?= min(100, (int) ($t['progress'] ?? 0)) ?>%"></div></div>
      <p class="text-[16px] leading-relaxed"><strong><?= (int) $t['past_l1'] ?> of <?= (int) $t['set_up'] ?></strong> agents past Level 1 — <strong><?= (int) $t['bonus_need'] ?></strong> unlocks your team bonus.</p>
    </section>
    <?php endif; ?>

    <?php if (!empty($card['help'])): ?>
    <h2 class="mt-2 text-[22px] font-headline font-extrabold">Who needs a hand tonight</h2>
    <div class="grid gap-4" style="grid-template-columns: repeat(auto-fit, minmax(300px, 1fr))">
      <?php foreach ($card['help'] as $h): ?>
      <div class="k-card p-5" style="border-radius:16px; border:2px solid #f0b35b">
        <p class="text-[18px] font-bold mb-1.5"><?= $e($h['name']) ?></p>
        <p class="text-[16px] leading-relaxed"><?php
          echo match ($h['weak_key']) {
              'conversion' => 'Taking calls, but few become sales (' . $e($h['conv']) . '). Sit in on a call or two and help them close.',
              'calls'      => 'Only ' . $e($h['calls_ps']) . ' calls a shift. Check what\'s slowing them down.',
              'profit'     => 'Selling, but at low profit per sale. Help them pitch add-ons and better-value fares.',
              'logging'    => 'Not logging calls. Ask them to log every call (Alt+L).',
              default      => 'Behind on their target. A quick check-in will help.',
          };
        ?></p>
        <?php if ($h['away_l1'] !== null): ?><p class="mt-2 text-[14px] text-on-surface-variant"><?= $usd($h['away_l1']) ?> from Level 1</p><?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($role === 'supervisor'): ?>
    <h2 class="mt-2 text-[22px] font-headline font-extrabold">My team</h2>
    <div class="k-card overflow-x-auto py-2">
      <table class="w-full text-[16px]" style="min-width: 640px; border-collapse: collapse">
        <thead><tr class="text-left text-[14px] text-on-surface-variant">
          <th class="px-5 py-3 font-semibold">Agent</th><th class="px-5 py-3 font-semibold">Progress to target</th>
          <th class="px-5 py-3 font-semibold">Weakest area</th><th class="px-5 py-3 font-semibold">Sales this month</th>
        </tr></thead>
        <tbody>
        <?php foreach ($card['team'] as $m): $p = (int) ($m['progress'] ?? 0); ?>
          <tr style="border-top:1px solid #eef0f5">
            <td class="px-5 py-3.5 font-semibold"><?= $e($m['name']) ?></td>
            <td class="px-5 py-3.5"><?php if ($m['set_up']): ?><div class="flex items-center gap-2.5"><div style="width:150px;height:10px;border-radius:5px;background:#e6e9f2"><div style="width:<?= min(100, $p) ?>%;height:10px;border-radius:5px;background:<?= $p >= 100 ? '#0f7b4f' : ($p >= 60 ? '#2f5bd3' : '#d08a1e') ?>"></div></div><?= $p ?>%</div><?php else: ?><span class="text-on-surface-variant">Target not set</span><?php endif; ?></td>
            <td class="px-5 py-3.5"><?= $m['weakest'] ? $e(preg_replace('/^Your biggest gap · /', '', $m['weakest'])) : '<span class="text-on-surface-variant">—</span>' ?></td>
            <td class="px-5 py-3.5"><?= (int) $m['sales'] ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
    <p class="text-[14px] text-on-surface-variant">You see your team's progress, not their incentive amounts — those are private to each person.</p>
  <?php endif; ?>

<?php endif; ?>
</div>
</main>
</body>
</html>
