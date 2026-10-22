<?php
/**
 * Staff survey — one person, as a full page: the read on them, what to bring up
 * in a one-to-one, their work next to their role's average, how they took the
 * survey, and every answer (follow-ups under the question that opened them).
 *
 * @var array      $r         the response (StaffSurveyService::results())
 * @var array      $p         StaffSurveyInsights::person()
 * @var array|null $work      their numbers for the window
 * @var array|null $avg       their role's average for the window
 * @var array      $window    ['from', 'to']
 * @var string     $currency
 * @var array      $pages
 * @var array      $questions
 * @var array      $centreLabels
 */
$activePage = 'staff_survey';
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$a = $r['answers'];
$roleLabel = fn($x) => ['agent' => 'Agent', 'csa' => 'CSA', 'supervisor' => 'Team leader', 'manager' => 'Manager'][$x] ?? ucfirst((string) $x);
$chip = [
    'red' => 'bg-red-50 text-red-800 ring-red-200', 'amber' => 'bg-amber-50 text-amber-900 ring-amber-200',
    'green' => 'bg-emerald-50 text-emerald-800 ring-emerald-200', 'blue' => 'bg-blue-50 text-blue-800 ring-blue-200',
    'grey' => 'bg-slate-100 text-slate-700 ring-slate-200',
];
$dot = ['red' => 'bg-red-600', 'amber' => 'bg-amber-500', 'green' => 'bg-emerald-600', 'blue' => 'bg-blue-600', 'grey' => 'bg-slate-400'];
$riskStyle = [
    'high' => ['High risk of leaving', 'bg-red-600 text-white'],
    'medium' => ['Medium risk of leaving', 'bg-amber-400 text-amber-950'],
    'low' => ['Low risk of leaving', 'bg-emerald-100 text-emerald-800'],
];
[$riskLabel, $riskClass] = $riskStyle[$p['risk']['level']];
$t = $p['taking'];
$mins = fn($s) => $s === null ? null : ($s < 60 ? $s . ' sec' : floor($s / 60) . ' min' . ($s % 60 ? ' ' . ($s % 60) . ' sec' : ''));
$seller = in_array($r['role'], ['agent', 'csa', 'supervisor'], true);
$money = fn($v) => $v === null ? '—' : ($v < 0 ? '−' : '') . number_format(abs($v), 0);
$num = fn($v, $d = 0) => $v === null ? '—' : number_format($v, $d);
$scaleTone = fn($v) => $v >= 4 ? '#047857' : ($v >= 3 ? '#d97706' : '#dc2626');

// Work tiles: [label, mine, average, compare key, higher is better?, formatter]
$tiles = [];
if ($work) {
    if ($seller) {
        $tiles[] = ['Bookings (approved)', $work['bookings'], $avg['bookings'] ?? null, 'bookings', true, $num];
        $tiles[] = ['Net MCO (' . $currency . ')', $work['net'], $avg['net'] ?? null, 'net', true, $money];
        $tiles[] = ['Calls logged', $work['calls'], $avg['calls'] ?? null, 'calls', true, $num];
    }
    $tiles[] = ['Days at work', $work['days'], $avg['days'] ?? null, 'days', true, $num];
    $tiles[] = ['Days late', $work['late'], $avg['late'] ?? null, 'late', false, $num];
    $tiles[] = ['Flagged breaks', $work['flagged'], $avg['flagged'] ?? null, 'flagged', false, $num];
}
$fmtAnswer = function (array $q, $v) {
    if ($v === null || $v === '' || $v === []) {
        return null;
    }
    if (is_array($v)) {
        return implode(', ', $v);
    }
    return (string) $v;
};
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
<meta charset="utf-8"/>
<meta content="width=device-width,initial-scale=1.0" name="viewport"/>
<title><?= $h($r['name']) ?> — Staff Survey — Base Fare CRM</title>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
<script src="<?= \App\Services\Asset::url('assets/js/error-beacon.js') ?>"></script>
<script src="/assets/js/tailwind.js"></script>
<script src="<?= \App\Services\Asset::url('assets/js/buddy-widget.js') ?>" defer></script>
<script>
tailwind.config={darkMode:"class",theme:{extend:{colors:{primary:"#163274","primary-container":"#314a8d",background:"#f8f9fa","on-surface":"#191c1d","on-surface-variant":"#434653"},fontFamily:{headline:["Manrope"],body:["Inter"]}}}};
</script>
<style>
.material-symbols-outlined{font-variation-settings:'FILL' 0,'wght' 400,'GRAD' 0,'opsz' 24}
.tnum{font-variant-numeric:tabular-nums}
.pips{display:flex;gap:4px}
.pips i{width:22px;height:8px;border-radius:99px;background:#e5e9f0}
</style>
</head>
<body class="bg-background font-body text-on-surface antialiased min-h-screen">

<?php require __DIR__ . '/../layout/sidebar.php'; ?>

<main class="ml-60 pt-8 pb-24 pl-10 pr-20 max-w-[1400px]">

  <a href="/admin/survey" class="inline-flex items-center gap-1 text-base font-semibold text-primary hover:underline mb-4">
    <span class="material-symbols-outlined text-xl">arrow_back</span> Staff Survey
  </a>

  <!-- Who -->
  <div class="flex items-start justify-between gap-4 flex-wrap mb-6">
    <div>
      <h1 class="text-3xl font-headline font-extrabold text-primary tracking-tight"><?= $h($r['name']) ?></h1>
      <p class="text-base text-on-surface-variant mt-1">
        <?= $h($roleLabel($r['role'])) ?><?= $r['centre'] ? ' · ' . $h($centreLabels[$r['centre']] ?? $r['centre']) : '' ?>
        · answered <?= $h(date('D j M Y, g:i a', strtotime($r['at']))) ?>
        <?php if ($r['email']): ?>· <span class="text-slate-500"><?= $h($r['email']) ?></span><?php endif; ?>
      </p>
    </div>
    <span class="text-sm font-bold uppercase tracking-wide px-4 py-2 rounded-full <?= $riskClass ?>"><?= $h($riskLabel) ?></span>
  </div>

  <div class="grid grid-cols-1 xl:grid-cols-3 gap-6 mb-6">
    <!-- The read -->
    <section class="xl:col-span-2 bg-white rounded-2xl border border-slate-200 shadow-sm p-6">
      <h2 class="text-xl font-headline font-extrabold text-on-surface flex items-center gap-2"><span class="material-symbols-outlined text-primary">psychology</span> The read</h2>
      <p class="text-lg leading-relaxed text-on-surface mt-3"><?= $h($p['read']) ?></p>
      <?php if ($p['risk']['why']): ?>
      <p class="text-base text-slate-600 mt-3"><b class="text-on-surface">Why <?= $h(strtolower($riskLabel)) ?>:</b> <?= $h(implode(' · ', $p['risk']['why'])) ?>.</p>
      <?php endif; ?>
      <?php if ($p['tags']): ?>
      <div class="mt-5 space-y-3">
        <?php foreach ($p['tags'] as $tg): ?>
        <div class="flex gap-3 items-start">
          <span class="mt-2 w-2.5 h-2.5 rounded-full shrink-0 <?= $dot[$tg['tone']] ?>"></span>
          <div class="text-base"><b class="text-on-surface"><?= $h($tg['label']) ?>.</b> <span class="text-slate-600"><?= $h($tg['hint']) ?></span></div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </section>

    <!-- One-to-one -->
    <section class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6">
      <h2 class="text-xl font-headline font-extrabold text-on-surface flex items-center gap-2"><span class="material-symbols-outlined text-primary">record_voice_over</span> Bring up in a one-to-one</h2>
      <ol class="mt-3 space-y-3 list-decimal pl-5 marker:font-bold marker:text-primary">
        <?php foreach ($p['talk'] as $line): ?>
        <li class="text-base leading-relaxed text-on-surface pl-1"><?= $h($line) ?></li>
        <?php endforeach; ?>
      </ol>
      <?php if ($p['drivers']): ?>
      <div class="mt-5 pt-4 border-t border-slate-100">
        <div class="text-xs font-bold uppercase tracking-wider text-slate-500 mb-2">What drives them</div>
        <div class="flex flex-wrap gap-1.5">
          <?php foreach ($p['drivers'] as $d): ?><span class="text-sm font-semibold px-2.5 py-1 rounded-full ring-1 <?= $chip['blue'] ?>"><?= $h($d) ?></span><?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>
    </section>
  </div>

  <div class="grid grid-cols-1 xl:grid-cols-3 gap-6 mb-6">
    <!-- At work -->
    <section class="xl:col-span-2 bg-white rounded-2xl border border-slate-200 shadow-sm p-6">
      <h2 class="text-xl font-headline font-extrabold text-on-surface flex items-center gap-2"><span class="material-symbols-outlined text-primary">work_history</span> At work, last <?= \App\Services\StaffSurveyInsights::WINDOW_DAYS ?> days</h2>
      <p class="text-sm text-slate-500 mt-1 mb-4"><?= $h(date('j M', strtotime($window['from']))) ?> to <?= $h(date('j M', strtotime($window['to']))) ?>, next to the average for active <?= $h(strtolower($roleLabel($r['role']))) ?>s<?= isset($avg['n']) ? ' (' . (int) $avg['n'] . ')' : '' ?>. Bookings and profit count approved bookings only, as on Performance.</p>
      <?php if (!$tiles): ?>
      <p class="text-base text-slate-500">No work data for this person (they may no longer be active).</p>
      <?php else: ?>
      <div class="grid grid-cols-2 lg:grid-cols-3 gap-4">
        <?php foreach ($tiles as [$label, $mine, $team, $key, $upGood, $f]):
            $c = $p['compare'][$key] ?? null;
            $good = $c === 'level' || $c === null ? null : (($c === 'above') === $upGood);
        ?>
        <div class="rounded-xl border border-slate-200 p-4">
          <div class="text-xs font-bold uppercase tracking-wider text-slate-500"><?= $h($label) ?></div>
          <div class="text-2xl font-headline font-extrabold mt-1 tnum <?= $good === null ? 'text-on-surface' : ($good ? 'text-emerald-700' : 'text-red-700') ?>"><?= $h($f($mine)) ?></div>
          <div class="text-sm text-slate-500 mt-1 tnum flex items-center gap-1">
            <?php if ($c === 'above' || $c === 'below'): ?><span class="material-symbols-outlined text-base <?= $good ? 'text-emerald-700' : 'text-red-700' ?>"><?= $c === 'above' ? 'arrow_upward' : 'arrow_downward' ?></span><?php endif; ?>
            average <?= $h($team === null ? '—' : ($key === 'net' ? $money($team) : $num($team, 1))) ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </section>

    <!-- How they took the survey -->
    <section class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6">
      <h2 class="text-xl font-headline font-extrabold text-on-surface flex items-center gap-2"><span class="material-symbols-outlined text-primary">timer</span> How they took the survey</h2>
      <dl class="mt-3 divide-y divide-slate-100 text-base">
        <div class="flex justify-between gap-4 py-2.5"><dt class="text-slate-600">Time taken</dt><dd class="font-semibold tnum <?= $t['rushed'] ? 'text-red-700' : '' ?>"><?= $h($mins($t['secs']) ?? 'Not recorded') ?></dd></div>
        <div class="flex justify-between gap-4 py-2.5"><dt class="text-slate-600">Put it off first</dt><dd class="font-semibold tnum"><?= $t['skips'] ? (int) $t['skips'] . ' time' . ($t['skips'] === 1 ? '' : 's') : 'No' ?></dd></div>
        <div class="flex justify-between gap-4 py-2.5"><dt class="text-slate-600">Written answers</dt><dd class="font-semibold tnum <?= $t['written'] ? '' : 'text-amber-700' ?>"><?= (int) $t['written'] ?> of <?= (int) $t['asked'] ?><?= $t['words'] ? ' · ' . (int) $t['words'] . ' words' : '' ?></dd></div>
        <?php if ($t['voice'] || $t['starters'] || $t['nudged']): ?>
        <div class="flex justify-between gap-4 py-2.5"><dt class="text-slate-600">Used</dt><dd class="font-semibold text-right"><?= $h(implode(', ', array_filter([
            $t['voice'] ? 'voice typing' : null, $t['starters'] ? 'starter phrases' : null, $t['nudged'] ? 'needed a nudge to write' : null,
        ]))) ?></dd></div>
        <?php endif; ?>
        <div class="flex justify-between gap-4 py-2.5"><dt class="text-slate-600">Gave the same score to everything</dt><dd class="font-semibold <?= $t['straight'] ? 'text-amber-700' : '' ?>"><?= $t['straight'] ? 'Yes' : 'No' ?></dd></div>
        <?php if ($t['more_at']): ?>
        <div class="flex justify-between gap-4 py-2.5"><dt class="text-slate-600">Added more words</dt><dd class="font-semibold"><?= $h(date('j M, g:i a', strtotime($t['more_at']))) ?></dd></div>
        <?php endif; ?>
      </dl>
    </section>
  </div>

  <!-- Every answer -->
  <section class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6">
    <h2 class="text-xl font-headline font-extrabold text-on-surface mb-4">All their answers</h2>
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-x-12 gap-y-8">
      <?php foreach ($pages as $pg): ?>
      <div>
        <div class="text-xs font-bold uppercase tracking-wider text-primary mb-3"><?= $h($pg['title']) ?></div>
        <dl class="space-y-4">
          <?php foreach ($pg['questions'] as $q): $v = $fmtAnswer($q, $a[$q['key']] ?? null); ?>
          <div>
            <dt class="text-base text-slate-600"><?= $h($q['label']) ?></dt>
            <dd class="mt-1">
              <?php if ($v === null): ?>
                <span class="text-base text-slate-400"><?= $q['type'] === 'text' ? 'Wrote nothing' : 'No answer' ?></span>
              <?php elseif ($q['type'] === 'scale'): ?>
                <div class="flex items-center gap-3"><div class="pips"><?php for ($i = 1; $i <= 5; $i++): ?><i<?= $i <= (int) $v ? ' style="background:' . $scaleTone((int) $v) . '"' : '' ?>></i><?php endfor; ?></div><b class="text-base tnum"><?= $h($v) ?> / 5</b></div>
              <?php elseif ($q['type'] === 'nps'): ?>
                <b class="text-base tnum <?= $v >= 9 ? 'text-emerald-700' : ($v >= 7 ? 'text-amber-700' : 'text-red-700') ?>"><?= $h($v) ?> / 10</b>
              <?php elseif ($q['type'] === 'text'): ?>
                <p class="text-base text-on-surface whitespace-pre-line bg-slate-50 rounded-xl px-4 py-3">“<?= $h($v) ?>”</p>
              <?php else: ?>
                <span class="text-base font-semibold text-on-surface"><?= $h($v) ?></span>
              <?php endif; ?>
              <?php if (!empty($q['followup']) && \App\Services\StaffSurveyService::followupOpen($q['followup'], $a[$q['key']] ?? null)):
                  $fv = trim((string) ($a[$q['followup']['key']] ?? '')); ?>
              <div class="mt-2 ml-4 pl-4 border-l-4 border-slate-200">
                <div class="text-sm text-slate-500">Follow-up: <?= $h($q['followup']['label']) ?></div>
                <?php if ($fv !== ''): ?><p class="text-base text-on-surface whitespace-pre-line mt-0.5">“<?= $h($fv) ?>”</p>
                <?php else: ?><span class="text-base text-slate-400">Wrote nothing</span><?php endif; ?>
              </div>
              <?php endif; ?>
            </dd>
          </div>
          <?php endforeach; ?>
        </dl>
      </div>
      <?php endforeach; ?>
    </div>
  </section>
</main>
</body>
</html>
