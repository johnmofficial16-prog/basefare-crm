<?php
/**
 * Staff survey — admin view: summary, every response (with a Feedback column)
 * and who hasn't answered yet.
 *
 * @var array $responses  [id, user_id, name, email, role, centre, answers, feedback, skips, at]
 * @var array $pending    [id, name, role, centre, skips]
 * @var array $summary    ['count', 'scales', 'choices', 'enps']
 * @var array $questions  key => question
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
$when = fn($t) => $t ? date('D j M, g:i a', strtotime($t)) : '';
$tone = fn($v) => $v === null ? 'text-slate-400' : ($v >= 4 ? 'text-emerald-700' : ($v >= 3 ? 'text-amber-700' : 'text-red-700'));
$roleLabel = fn($r) => ['agent' => 'Agent', 'csa' => 'CSA', 'supervisor' => 'Supervisor', 'manager' => 'Manager'][$r] ?? ucfirst((string) $r);
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
.resp-detail td{background:#fbfcfe}
.clamp2{display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
</style>
</head>
<body class="bg-background font-body text-on-surface antialiased min-h-screen">

<?php require __DIR__ . '/../layout/sidebar.php'; ?>

<main class="ml-60 pt-8 pb-24 px-10">

  <div class="flex items-start justify-between mb-6 gap-4 flex-wrap">
    <div>
      <h1 class="text-3xl font-headline font-extrabold text-primary tracking-tight flex items-center gap-2">
        <span class="material-symbols-outlined text-3xl">psychology</span> Staff Survey
      </h1>
      <p class="text-base text-on-surface-variant mt-1">Experience, mindset, goals and feedback from agents, CSAs, supervisors and managers.</p>
    </div>
    <?php if ($responses): ?>
    <a href="/admin/survey/export" class="flex items-center gap-1.5 px-4 py-2.5 bg-white border border-slate-200 text-primary rounded-xl text-sm font-bold hover:border-primary/50 transition-all shadow-sm">
      <span class="material-symbols-outlined text-base">download</span> Download (CSV)
    </a>
    <?php endif; ?>
  </div>

  <!-- KPIs -->
  <div class="grid grid-cols-2 xl:grid-cols-4 gap-4 mb-6">
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5">
      <div class="text-xs font-bold uppercase tracking-wider text-slate-500">Answered</div>
      <div class="text-3xl font-headline font-extrabold text-primary mt-1"><?= count($responses) ?> <span class="text-base font-semibold text-slate-400">of <?= $total ?></span></div>
      <div class="bar mt-3"><i style="width:<?= (int) $rate ?>%"></i></div>
      <div class="text-sm text-slate-500 mt-2"><?= (int) $rate ?>% · <?= count($pending) ?> still to answer</div>
    </div>
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5">
      <div class="text-xs font-bold uppercase tracking-wider text-slate-500">Happiness</div>
      <div class="text-3xl font-headline font-extrabold mt-1 <?= $tone($happy) ?>"><?= $happy === null ? '—' : $h($happy) ?> <span class="text-base font-semibold text-slate-400">/ 5</span></div>
      <div class="text-sm text-slate-500 mt-2">"Overall, how happy are you working here?"</div>
    </div>
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5">
      <div class="text-xs font-bold uppercase tracking-wider text-slate-500">Would recommend (eNPS)</div>
      <div class="text-3xl font-headline font-extrabold mt-1 <?= $enps === null ? 'text-slate-400' : ($enps >= 20 ? 'text-emerald-700' : ($enps >= 0 ? 'text-amber-700' : 'text-red-700')) ?>"><?= $enps === null ? '—' : ($enps > 0 ? '+' : '') . (int) $enps ?></div>
      <div class="text-sm text-slate-500 mt-2">From −100 to +100. Above +20 is good.</div>
    </div>
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5">
      <div class="text-xs font-bold uppercase tracking-wider text-slate-500">See themselves here in a year</div>
      <div class="text-3xl font-headline font-extrabold mt-1 <?= $stayPct === null ? 'text-slate-400' : ($stayPct >= 70 ? 'text-emerald-700' : ($stayPct >= 50 ? 'text-amber-700' : 'text-red-700')) ?>"><?= $stayPct === null ? '—' : (int) $stayPct . '%' ?></div>
      <div class="text-sm text-slate-500 mt-2">Answered "Yes, definitely" or "Probably".</div>
    </div>
  </div>

  <?php if (!$responses): ?>
  <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-10 text-center mb-6">
    <span class="material-symbols-outlined text-5xl text-slate-300">hourglass_top</span>
    <h2 class="text-lg font-headline font-bold text-on-surface mt-2">No answers yet</h2>
    <p class="text-base text-slate-500 mt-1 max-w-xl mx-auto">The survey pops up for staff after they sign in and when they are idle. Answers appear here as soon as they are sent.</p>
  </div>
  <?php else: ?>

  <!-- Scores and choices -->
  <div class="grid grid-cols-1 xl:grid-cols-2 gap-6 mb-6">
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6">
      <h2 class="text-lg font-headline font-bold text-on-surface mb-4">Average scores <span class="text-sm font-medium text-slate-400">(out of 5)</span></h2>
      <div class="space-y-4">
        <?php foreach ($summary['scales'] as $key => $s): ?>
        <div>
          <div class="flex justify-between gap-4 text-sm"><span class="text-on-surface"><?= $h($s['label']) ?></span><b class="<?= $tone($s['avg']) ?>"><?= $s['avg'] === null ? '—' : $h($s['avg']) ?></b></div>
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
          <div class="grid grid-cols-[minmax(0,1fr)_auto] gap-x-3 items-center text-sm">
            <span><?= $h($opt) ?></span><b class="tabular-nums"><?= (int) $n ?></b>
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
        <div class="grid grid-cols-[minmax(0,1fr)_auto] gap-x-3 items-center text-sm">
          <span><?= $h($opt) ?></span><b class="tabular-nums"><?= (int) $n ?></b>
          <div class="bar col-span-2"><i style="width:<?= (int) round($n / $max * 100) ?>%"></i></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Every response -->
  <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden mb-6">
    <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
      <h2 class="text-lg font-headline font-bold text-on-surface">Responses</h2>
      <span class="text-sm text-slate-500">Click a row to read all of their answers</span>
    </div>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-slate-50 text-left text-xs font-bold uppercase tracking-wider text-slate-500">
          <tr>
            <th class="px-6 py-3">Name</th><th class="px-3 py-3">Role</th><th class="px-3 py-3">Centre</th><th class="px-3 py-3">Answered</th>
            <th class="px-3 py-3 text-center">Happy</th><th class="px-3 py-3 text-center">Recommend</th><th class="px-3 py-3">In a year</th>
            <th class="px-6 py-3 w-[34%]">Feedback</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <?php foreach ($responses as $r): $a = $r['answers']; ?>
          <tr class="resp-row align-top" data-toggle="d<?= (int) $r['id'] ?>" tabindex="0" aria-expanded="false">
            <td class="px-6 py-3"><b class="text-on-surface"><?= $h($r['name']) ?></b><div class="text-xs text-slate-400"><?= $h($r['email']) ?></div></td>
            <td class="px-3 py-3"><?= $h($roleLabel($r['role'])) ?></td>
            <td class="px-3 py-3"><?= $h($centreLabels[$r['centre']] ?? '—') ?></td>
            <td class="px-3 py-3 whitespace-nowrap"><?= $h($when($r['at'])) ?><?php if ($r['skips']): ?><div class="text-xs text-slate-400">put off <?= (int) $r['skips'] ?>×</div><?php endif; ?></td>
            <td class="px-3 py-3 text-center font-bold <?= $tone($a['happy'] ?? null) ?>"><?= $h($a['happy'] ?? '—') ?></td>
            <td class="px-3 py-3 text-center font-bold <?= isset($a['recommend']) ? ($a['recommend'] >= 9 ? 'text-emerald-700' : ($a['recommend'] >= 7 ? 'text-amber-700' : 'text-red-700')) : 'text-slate-400' ?>"><?= $h($a['recommend'] ?? '—') ?></td>
            <td class="px-3 py-3"><?= $h($a['stay'] ?? '—') ?></td>
            <td class="px-6 py-3"><?= $r['feedback'] ? '<div class="clamp2">' . $h($r['feedback']) . '</div>' : '<span class="text-slate-300">—</span>' ?></td>
          </tr>
          <tr class="resp-detail hidden" id="d<?= (int) $r['id'] ?>">
            <td colspan="8" class="px-6 py-5">
              <div class="grid grid-cols-1 lg:grid-cols-2 gap-x-10 gap-y-4">
                <?php foreach ($pages as $pg): ?>
                <div>
                  <div class="text-xs font-bold uppercase tracking-wider text-primary mb-2"><?= $h($pg['title']) ?></div>
                  <dl class="space-y-2">
                    <?php foreach ($pg['questions'] as $q): $v = $a[$q['key']] ?? null; ?>
                    <div><dt class="text-slate-500"><?= $h($q['label']) ?></dt>
                      <dd class="font-medium text-on-surface whitespace-pre-line"><?= ($v === null || $v === '' || $v === []) ? '<span class="text-slate-300">No answer</span>' : $h(is_array($v) ? implode(', ', $v) : ($q['type'] === 'scale' ? $v . ' / 5' : ($q['type'] === 'nps' ? $v . ' / 10' : $v))) ?></dd></div>
                    <?php endforeach; ?>
                  </dl>
                </div>
                <?php endforeach; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <!-- Still to answer -->
  <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
    <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
      <h2 class="text-lg font-headline font-bold text-on-surface">Still to answer <span class="text-slate-400 font-semibold">(<?= count($pending) ?>)</span></h2>
      <span class="text-sm text-slate-500">"Not now" works 3 times; after that it has to be filled</span>
    </div>
    <?php if ($pending): ?>
    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-x-6 px-6 py-3">
      <?php foreach ($pending as $p): ?>
      <div class="flex items-center justify-between gap-3 py-2.5 border-b border-slate-50 text-sm">
        <span><b class="text-on-surface"><?= $h($p['name']) ?></b> <span class="text-slate-400">· <?= $h($roleLabel($p['role'])) ?><?= $p['centre'] ? ' · ' . $h($centreLabels[$p['centre']] ?? $p['centre']) : '' ?></span></span>
        <?php if ($p['skips']): ?><span class="text-xs font-semibold px-2 py-0.5 rounded-full <?= $p['skips'] >= 3 ? 'bg-red-50 text-red-700' : 'bg-amber-50 text-amber-700' ?>">put off <?= (int) $p['skips'] ?>×</span><?php endif; ?>
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
  function toggle(row) {
    var d = document.getElementById(row.getAttribute('data-toggle'));
    if (!d) return;
    var show = d.classList.contains('hidden');
    d.classList.toggle('hidden', !show);
    row.setAttribute('aria-expanded', show ? 'true' : 'false');
  }
  document.querySelectorAll('.resp-row').forEach(function (row) {
    row.addEventListener('click', function () { toggle(row); });
    row.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggle(row); } });
  });
})();
</script>
</body>
</html>
