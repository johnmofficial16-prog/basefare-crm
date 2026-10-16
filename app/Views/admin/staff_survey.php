<?php
/**
 * Staff survey — admin view: what the survey is telling you (insights), who to
 * talk to first, themes in what people wrote, centre/role breakdowns, every
 * response (with a Feedback column) and who hasn't answered yet.
 * Each person opens as a full page: /admin/survey/person/{id}.
 *
 * @var array $responses  [id, user_id, name, email, role, centre, answers, feedback, skips, at]
 * @var array $pending    [id, name, role, centre, skips]
 * @var array $summary    ['count', 'scales', 'choices', 'enps']
 * @var array $reads      user_id => StaffSurveyInsights::person()
 * @var array $team       StaffSurveyInsights::team()
 * @var array $questions  key => question (follow-ups included)
 * @var array $pages
 * @var array $centreLabels
 */
$activePage = 'staff_survey';
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$total = count($responses) + count($pending);
$rate = $total ? round(count($responses) / $total * 100) : 0;
$happy = $summary['scales']['happy']['avg'] ?? null;
$stayCounts = $summary['choices']['stay']['counts'] ?? [];
$stayYes = ($stayCounts['Yes, definitely'] ?? 0) + ($stayCounts['Probably'] ?? 0);
$stayPct = $summary['count'] ? round($stayYes / $summary['count'] * 100) : null;
$enps = $summary['enps'];
$written = $team['written'] ?? null;
$writtenPct = $written && $written['of'] ? round($written['wrote'] / $written['of'] * 100) : null;
$when = fn($t) => $t ? date('D j M, g:i a', strtotime($t)) : '';
$tone = fn($v) => $v === null ? 'text-slate-400' : ($v >= 4 ? 'text-emerald-700' : ($v >= 3 ? 'text-amber-700' : 'text-red-700'));
$roleLabel = fn($r) => ['agent' => 'Agent', 'csa' => 'CSA', 'supervisor' => 'Supervisor', 'manager' => 'Manager'][$r] ?? ucfirst((string) $r);
$chip = [
    'red' => 'bg-red-50 text-red-800 ring-red-200', 'amber' => 'bg-amber-50 text-amber-900 ring-amber-200',
    'green' => 'bg-emerald-50 text-emerald-800 ring-emerald-200', 'blue' => 'bg-blue-50 text-blue-800 ring-blue-200',
    'grey' => 'bg-slate-100 text-slate-700 ring-slate-200',
];
$ink = ['red' => 'text-red-700', 'amber' => 'text-amber-700', 'green' => 'text-emerald-700', 'blue' => 'text-blue-700', 'grey' => 'text-slate-500'];
$risk = [
    'high' => ['High', 'bg-red-600 text-white'], 'medium' => ['Medium', 'bg-amber-400 text-amber-950'], 'low' => ['Low', 'bg-emerald-100 text-emerald-800'],
];
$personUrl = fn($id) => '/admin/survey/person/' . (int) $id;
// "In their words": the feedback box, or else the most telling thing they wrote.
$inWords = function (array $r) use ($questions) {
    if (($r['feedback'] ?? '') !== '') {
        return [null, $r['feedback']];
    }
    foreach (['stay_why', 'frustration', 'boss', 'happy_why', 'workload_why', 'shift_why', 'support_why', 'valued_why', 'recommend_why', 'tools_why', 'best', 'skill'] as $k) {
        $v = trim((string) ($r['answers'][$k] ?? ''));
        if ($v !== '' && isset($questions[$k])) {
            return [$questions[$k]['label'], $v];
        }
    }
    return [null, null];
};
$fmt1 = fn($v) => $v === null ? '—' : number_format($v, 1);
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
<meta charset="utf-8"/>
<meta content="width=device-width,initial-scale=1.0" name="viewport"/>
<title>Staff Survey — Base Fare CRM</title>
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
.bar{height:10px;border-radius:99px;background:#eef1f6;overflow:hidden}
.bar>i{display:block;height:100%;border-radius:inherit;background:#163274}
.resp-row{cursor:pointer}
.resp-row:hover td{background:#f8fafc}
.clamp2{display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.clamp3{display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}
.tnum{font-variant-numeric:tabular-nums}
</style>
</head>
<body class="bg-background font-body text-on-surface antialiased min-h-screen">

<?php require __DIR__ . '/../layout/sidebar.php'; ?>

<main class="ml-60 pt-8 pb-24 pl-10 pr-20 max-w-[1600px]">

  <div class="flex items-start justify-between mb-6 gap-4 flex-wrap">
    <div>
      <h1 class="text-3xl font-headline font-extrabold text-primary tracking-tight flex items-center gap-2">
        <span class="material-symbols-outlined text-3xl">psychology</span> Staff Survey
      </h1>
      <p class="text-base text-on-surface-variant mt-1">How agents, CSAs, supervisors and managers feel, what they want, and who needs a conversation.</p>
    </div>
    <?php if ($responses): ?>
    <a href="/admin/survey/export" class="flex items-center gap-1.5 px-4 py-2.5 bg-white border border-slate-200 text-primary rounded-xl text-base font-bold hover:border-primary/50 transition-all shadow-sm">
      <span class="material-symbols-outlined text-xl">download</span> Download (CSV)
    </a>
    <?php endif; ?>
  </div>

  <!-- KPIs -->
  <div class="grid grid-cols-2 lg:grid-cols-3 xl:grid-cols-5 gap-4 mb-6">
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5">
      <div class="text-xs font-bold uppercase tracking-wider text-slate-500">Answered</div>
      <div class="text-3xl font-headline font-extrabold text-primary mt-1 tnum"><?= count($responses) ?> <span class="text-base font-semibold text-slate-400">of <?= $total ?></span></div>
      <div class="bar mt-3"><i style="width:<?= (int) $rate ?>%"></i></div>
      <div class="text-sm text-slate-500 mt-2"><?= (int) $rate ?>% · <?= count($pending) ?> still to answer</div>
    </div>
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5">
      <div class="text-xs font-bold uppercase tracking-wider text-slate-500">Happiness</div>
      <div class="text-3xl font-headline font-extrabold mt-1 tnum <?= $tone($happy) ?>"><?= $happy === null ? '—' : $h($happy) ?> <span class="text-base font-semibold text-slate-400">/ 5</span></div>
      <div class="text-sm text-slate-500 mt-2">"Overall, how happy are you working here?"</div>
    </div>
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5">
      <div class="text-xs font-bold uppercase tracking-wider text-slate-500">Would recommend (eNPS)</div>
      <div class="text-3xl font-headline font-extrabold mt-1 tnum <?= $enps === null ? 'text-slate-400' : ($enps >= 20 ? 'text-emerald-700' : ($enps >= 0 ? 'text-amber-700' : 'text-red-700')) ?>"><?= $enps === null ? '—' : ($enps > 0 ? '+' : '') . (int) $enps ?></div>
      <div class="text-sm text-slate-500 mt-2">From −100 to +100. Above +20 is good.</div>
    </div>
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5">
      <div class="text-xs font-bold uppercase tracking-wider text-slate-500">See themselves here in a year</div>
      <div class="text-3xl font-headline font-extrabold mt-1 tnum <?= $stayPct === null ? 'text-slate-400' : ($stayPct >= 70 ? 'text-emerald-700' : ($stayPct >= 50 ? 'text-amber-700' : 'text-red-700')) ?>"><?= $stayPct === null ? '—' : (int) $stayPct . '%' ?></div>
      <div class="text-sm text-slate-500 mt-2">Answered "Yes, definitely" or "Probably".</div>
    </div>
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5">
      <div class="text-xs font-bold uppercase tracking-wider text-slate-500">Wrote in their own words</div>
      <div class="text-3xl font-headline font-extrabold mt-1 tnum <?= $writtenPct === null ? 'text-slate-400' : ($writtenPct >= 60 ? 'text-emerald-700' : ($writtenPct >= 30 ? 'text-amber-700' : 'text-red-700')) ?>"><?= $writtenPct === null ? '—' : (int) $writtenPct . '%' ?></div>
      <div class="text-sm text-slate-500 mt-2"><?= $written ? (int) $written['wrote'] . ' of ' . (int) $written['of'] . ' wrote at least one answer' : 'Written answers, not just ticks' ?></div>
    </div>
  </div>

  <?php if (!$responses): ?>
  <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-10 text-center mb-6">
    <span class="material-symbols-outlined text-5xl text-slate-300">hourglass_top</span>
    <h2 class="text-lg font-headline font-bold text-on-surface mt-2">No answers yet</h2>
    <p class="text-base text-slate-500 mt-1 max-w-xl mx-auto">The survey pops up for staff after they sign in and when they are idle. Answers and insights appear here as soon as they are sent.</p>
  </div>
  <?php else: ?>

  <!-- Insights + who to talk to first -->
  <div class="grid grid-cols-1 xl:grid-cols-5 gap-6 mb-6">
    <section class="xl:col-span-3 bg-white rounded-2xl border border-slate-200 shadow-sm p-6">
      <h2 class="text-xl font-headline font-extrabold text-on-surface flex items-center gap-2"><span class="material-symbols-outlined text-primary">insights</span> What the survey is telling you</h2>
      <p class="text-sm text-slate-500 mt-1 mb-4">Read from the answers, how people filled the survey in, and their last <?= \App\Services\StaffSurveyInsights::WINDOW_DAYS ?> days of work. Patterns, not verdicts.</p>
      <ul class="space-y-3">
        <?php foreach ($team['findings'] as $f): ?>
        <li class="flex gap-3 items-start text-base leading-relaxed">
          <span class="material-symbols-outlined text-[22px] mt-0.5 <?= $ink[$f['tone']] ?? 'text-slate-500' ?>"><?= $h($f['icon']) ?></span>
          <span class="text-on-surface"><?= $h($f['text']) ?></span>
        </li>
        <?php endforeach; ?>
      </ul>
    </section>

    <section class="xl:col-span-2 bg-white rounded-2xl border border-slate-200 shadow-sm p-6">
      <h2 class="text-xl font-headline font-extrabold text-on-surface flex items-center gap-2"><span class="material-symbols-outlined text-primary">record_voice_over</span> Talk to these people first</h2>
      <p class="text-sm text-slate-500 mt-1 mb-4">Highest risk of leaving and the most warning signs, top first.</p>
      <?php if (!$team['priority']): ?>
      <p class="text-base text-emerald-700 font-semibold">Nobody stands out as at risk right now.</p>
      <?php else: ?>
      <div class="space-y-3">
        <?php foreach ($team['priority'] as $pr): $r = $pr['r']; $p = $pr['p']; [$rl, $rc] = $risk[$p['risk']['level']]; ?>
        <a href="<?= $personUrl($r['user_id']) ?>" class="block rounded-xl border border-slate-200 hover:border-primary/50 hover:bg-slate-50 p-4 transition-colors">
          <div class="flex items-center justify-between gap-3">
            <div class="min-w-0"><b class="text-base text-on-surface"><?= $h($r['name']) ?></b> <span class="text-sm text-slate-500">· <?= $h($roleLabel($r['role'])) ?><?= $r['centre'] ? ' · ' . $h($centreLabels[$r['centre']] ?? $r['centre']) : '' ?></span></div>
            <span class="shrink-0 text-xs font-bold uppercase tracking-wide px-2.5 py-1 rounded-full <?= $rc ?>"><?= $rl ?> risk</span>
          </div>
          <div class="flex flex-wrap gap-1.5 mt-2">
            <?php foreach (array_slice(array_values(array_filter($p['tags'], fn($t) => in_array($t['tone'], ['red', 'amber'], true))), 0, 3) as $t): ?>
            <span class="text-sm font-semibold px-2 py-0.5 rounded-full ring-1 <?= $chip[$t['tone']] ?>"><?= $h($t['label']) ?></span>
            <?php endforeach; ?>
          </div>
          <?php if ($p['talk']): ?><p class="text-sm text-slate-600 mt-2 clamp2"><?= $h($p['talk'][0]) ?></p><?php endif; ?>
        </a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </section>
  </div>

  <!-- Themes from what people wrote -->
  <section class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 mb-6">
    <h2 class="text-xl font-headline font-extrabold text-on-surface flex items-center gap-2"><span class="material-symbols-outlined text-primary">forum</span> In their own words</h2>
    <p class="text-sm text-slate-500 mt-1 mb-4">Topics picked out of every written answer (English, Hindi and Hinglish), with what people actually said.</p>
    <?php if (!$team['themes']): ?>
    <p class="text-base text-slate-500">Nobody has written anything yet. The survey now asks follow-up questions under low scores, offers tap-to-start phrases and voice typing, and asks people who already answered for a few lines once more.</p>
    <?php else: ?>
    <div class="grid grid-cols-1 lg:grid-cols-2 2xl:grid-cols-3 gap-4">
      <?php foreach ($team['themes'] as $t): ?>
      <div class="rounded-xl border border-slate-200 p-4">
        <div class="flex items-center justify-between gap-3">
          <b class="text-base text-on-surface"><?= $h($t['label']) ?></b>
          <span class="text-sm font-semibold text-slate-500 tnum"><?= (int) $t['people'] ?> <?= $t['people'] === 1 ? 'person' : 'people' ?></span>
        </div>
        <div class="bar mt-2"><i style="width:<?= (int) round($t['people'] / max(1, count($responses)) * 100) ?>%"></i></div>
        <div class="space-y-2.5 mt-3">
          <?php foreach ($t['quotes'] as $qt): ?>
          <blockquote class="text-base text-on-surface border-l-4 border-slate-200 pl-3">
            “<?= $h($qt['text']) ?>”
            <a href="<?= $personUrl($qt['user_id']) ?>" class="block text-sm text-slate-500 hover:text-primary mt-0.5">— <?= $h($qt['name']) ?></a>
          </blockquote>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </section>

  <!-- Centre and role breakdown -->
  <?php
  $groupTables = [
      'centre' => ['By centre', fn($g) => $g === 'unknown' ? 'No centre' : ($centreLabels[$g] ?? $g)],
      'role' => ['By role', fn($g) => $roleLabel($g) . 's'],
  ];
  ?>
  <div class="grid grid-cols-1 min-[1700px]:grid-cols-2 gap-6 mb-6">
    <?php foreach ($groupTables as $dim => [$title, $label]): $rows = $team['groups'][$dim] ?? []; ?>
    <section class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
      <div class="px-6 py-4 border-b border-slate-100"><h2 class="text-lg font-headline font-bold text-on-surface"><?= $h($title) ?></h2></div>
      <div class="overflow-x-auto">
        <table class="w-full text-base tnum">
          <thead class="bg-slate-50 text-left text-xs font-bold uppercase tracking-wider text-slate-500">
            <tr><th class="px-6 py-3"></th><th class="px-3 py-3 text-right">Answers</th><th class="px-3 py-3 text-right">Happy</th><th class="px-3 py-3 text-right">Supported</th><th class="px-3 py-3 text-right">Workload</th><th class="px-3 py-3 text-right">eNPS</th><th class="px-3 py-3 text-right">Staying</th><th class="px-6 py-3 text-right">High risk</th></tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <?php foreach ($rows as $g => $s): ?>
            <tr>
              <td class="px-6 py-3 font-semibold"><?= $h($label($g)) ?></td>
              <td class="px-3 py-3 text-right"><?= (int) $s['n'] ?></td>
              <td class="px-3 py-3 text-right font-bold <?= $tone($s['happy']) ?>"><?= $fmt1($s['happy']) ?></td>
              <td class="px-3 py-3 text-right font-bold <?= $tone($s['support']) ?>"><?= $fmt1($s['support']) ?></td>
              <td class="px-3 py-3 text-right font-bold <?= $tone($s['workload']) ?>"><?= $fmt1($s['workload']) ?></td>
              <td class="px-3 py-3 text-right font-bold <?= $s['enps'] === null ? 'text-slate-400' : ($s['enps'] >= 20 ? 'text-emerald-700' : ($s['enps'] >= 0 ? 'text-amber-700' : 'text-red-700')) ?>"><?= $s['enps'] === null ? '—' : ($s['enps'] > 0 ? '+' : '') . (int) $s['enps'] ?></td>
              <td class="px-3 py-3 text-right"><?= (int) $s['stay'] ?>%</td>
              <td class="px-6 py-3 text-right <?= $s['risk'] ? 'text-red-700 font-bold' : 'text-slate-400' ?>"><?= (int) $s['risk'] ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <p class="px-6 py-3 text-sm text-slate-500 border-t border-slate-100">Scores out of 5. Groups with one answer show one person's view, not a trend.</p>
    </section>
    <?php endforeach; ?>
  </div>

  <!-- Scores and choices -->
  <div class="grid grid-cols-1 xl:grid-cols-2 gap-6 mb-6">
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6">
      <h2 class="text-lg font-headline font-bold text-on-surface mb-4">Average scores <span class="text-sm font-medium text-slate-400">(out of 5)</span></h2>
      <div class="space-y-4">
        <?php foreach ($summary['scales'] as $key => $s): ?>
        <div>
          <div class="flex justify-between gap-4 text-base"><span class="text-on-surface"><?= $h($s['label']) ?></span><b class="tnum <?= $tone($s['avg']) ?>"><?= $s['avg'] === null ? '—' : $h($s['avg']) ?></b></div>
          <div class="bar mt-1.5"><i style="width:<?= $s['avg'] === null ? 0 : (int) round($s['avg'] / 5 * 100) ?>%"></i></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php foreach (['stay', 'shift_end'] as $ck): $c = $summary['choices'][$ck] ?? null; if (!$c) continue; $max = max(1, max($c['counts'])); ?>
    <?php if ($ck === 'stay'): ?><div class="space-y-6"><?php endif; ?>
      <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6">
        <h2 class="text-base font-headline font-bold text-on-surface mb-3"><?= $h($c['label']) ?></h2>
        <div class="space-y-2.5">
          <?php foreach ($c['counts'] as $opt => $n): ?>
          <div class="grid grid-cols-[minmax(0,1fr)_auto] gap-x-3 items-center text-base">
            <span><?= $h($opt) ?></span><b class="tnum"><?= (int) $n ?></b>
            <div class="bar col-span-2"><i style="width:<?= (int) round($n / $max * 100) ?>%"></i></div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php if ($ck === 'shift_end'): ?></div><?php endif; ?>
    <?php endforeach; ?>
  </div>

  <div class="grid grid-cols-1 xl:grid-cols-3 gap-6 mb-6">
    <?php foreach (['path', 'goals', 'help'] as $ck): $c = $summary['choices'][$ck] ?? null; if (!$c) continue; $counts = $c['counts']; arsort($counts); $max = max(1, max($counts)); ?>
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6">
      <h2 class="text-base font-headline font-bold text-on-surface mb-3"><?= $h(preg_replace('/ Pick up to \d+\.$/', '', $c['label'])) ?></h2>
      <div class="space-y-2.5">
        <?php foreach ($counts as $opt => $n): ?>
        <div class="grid grid-cols-[minmax(0,1fr)_auto] gap-x-3 items-center text-base">
          <span><?= $h($opt) ?></span><b class="tnum"><?= (int) $n ?></b>
          <div class="bar col-span-2"><i style="width:<?= (int) round($n / $max * 100) ?>%"></i></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Every response -->
  <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden mb-6">
    <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100 gap-4 flex-wrap">
      <h2 class="text-lg font-headline font-bold text-on-surface">Responses</h2>
      <span class="text-sm text-slate-500">Open a person for the full read, all their answers and their work next to it</span>
    </div>
    <div class="overflow-x-auto">
      <table class="w-full text-base">
        <thead class="bg-slate-50 text-left text-xs font-bold uppercase tracking-wider text-slate-500">
          <tr>
            <th class="px-6 py-3 min-w-[200px]">Name</th>
            <th class="px-2 py-3 text-center">Happy</th><th class="px-2 py-3 text-center" title="Would recommend us, out of 10">Recom&shy;mend</th><th class="px-3 py-3">In a year</th>
            <th class="px-3 py-3">Risk</th><th class="px-3 py-3 min-w-[200px]">What it shows</th>
            <th class="px-6 py-3 min-w-[240px]">Feedback / in their words</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <?php foreach ($responses as $r): $a = $r['answers']; $p = $reads[$r['user_id']]; [$rl, $rc] = $risk[$p['risk']['level']]; [$wLabel, $wText] = $inWords($r); ?>
          <tr class="resp-row align-top" data-href="<?= $personUrl($r['user_id']) ?>">
            <td class="px-6 py-3"><a href="<?= $personUrl($r['user_id']) ?>" class="font-bold text-on-surface hover:text-primary hover:underline whitespace-nowrap"><?= $h($r['name']) ?></a>
              <div class="text-sm text-slate-500 whitespace-nowrap"><?= $h($roleLabel($r['role'])) ?><?= $r['centre'] ? ' · ' . $h($centreLabels[$r['centre']] ?? $r['centre']) : '' ?></div>
              <div class="text-sm text-slate-400 whitespace-nowrap"><?= $h($when($r['at'])) ?><?= $r['skips'] ? ' · put off ' . (int) $r['skips'] . '×' : '' ?></div></td>
            <td class="px-2 py-3 text-center font-bold tnum <?= $tone($a['happy'] ?? null) ?>"><?= $h($a['happy'] ?? '—') ?></td>
            <td class="px-2 py-3 text-center font-bold tnum <?= isset($a['recommend']) ? ($a['recommend'] >= 9 ? 'text-emerald-700' : ($a['recommend'] >= 7 ? 'text-amber-700' : 'text-red-700')) : 'text-slate-400' ?>"><?= $h($a['recommend'] ?? '—') ?></td>
            <td class="px-3 py-3"><?= $h($a['stay'] ?? '—') ?></td>
            <td class="px-3 py-3"><span class="text-xs font-bold uppercase tracking-wide px-2.5 py-1 rounded-full whitespace-nowrap <?= $rc ?>"><?= $rl ?></span></td>
            <td class="px-3 py-3"><div class="flex flex-wrap gap-1.5">
              <?php foreach (array_slice($p['tags'], 0, 3) as $t): ?><span class="text-sm font-semibold px-2 py-0.5 rounded-full ring-1 whitespace-nowrap <?= $chip[$t['tone']] ?>" title="<?= $h($t['hint']) ?>"><?= $h($t['label']) ?></span><?php endforeach; ?>
              <?php if (count($p['tags']) > 3): ?><span class="text-sm text-slate-500">+<?= count($p['tags']) - 3 ?> more</span><?php endif; ?>
            </div></td>
            <td class="px-6 py-3"><?php if ($wText): ?>
              <?php if ($wLabel): ?><div class="text-xs font-semibold text-slate-400"><?= $h($wLabel) ?></div><?php endif; ?>
              <div class="clamp3"><?= $h($wText) ?></div>
            <?php else: ?><span class="text-slate-400">Wrote nothing</span><?php endif; ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <!-- Still to answer -->
  <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
    <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100 gap-4 flex-wrap">
      <h2 class="text-lg font-headline font-bold text-on-surface">Still to answer <span class="text-slate-400 font-semibold">(<?= count($pending) ?>)</span></h2>
      <span class="text-sm text-slate-500">"Not now" works 3 times; after that it has to be filled</span>
    </div>
    <?php if ($pending): ?>
    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-x-6 px-6 py-3">
      <?php foreach ($pending as $p): ?>
      <div class="flex items-center justify-between gap-3 py-2.5 border-b border-slate-50 text-base">
        <span><b class="text-on-surface"><?= $h($p['name']) ?></b> <span class="text-slate-400 text-sm">· <?= $h($roleLabel($p['role'])) ?><?= $p['centre'] ? ' · ' . $h($centreLabels[$p['centre']] ?? $p['centre']) : '' ?></span></span>
        <?php if ($p['skips']): ?><span class="text-xs font-semibold px-2 py-0.5 rounded-full whitespace-nowrap <?= $p['skips'] >= 3 ? 'bg-red-50 text-red-700' : 'bg-amber-50 text-amber-700' ?>">put off <?= (int) $p['skips'] ?>×</span><?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <p class="px-6 py-6 text-base text-emerald-700 font-semibold">Everyone has answered.</p>
    <?php endif; ?>
  </div>
</main>

<script>
(function () {
  // The whole row opens the person (the name is the real link, for keyboard and new-tab use).
  document.querySelectorAll('.resp-row').forEach(function (row) {
    row.addEventListener('click', function (e) {
      if (e.target.closest('a')) return;
      if (window.getSelection && String(window.getSelection())) return; // selecting text, not opening
      window.location.href = row.getAttribute('data-href');
    });
  });
})();
</script>
</body>
</html>
