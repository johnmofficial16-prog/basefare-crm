<?php
/**
 * Booking Reminders — cross-booking management page (admin/manager).
 *
 * @var \Illuminate\Support\Collection $reminders
 * @var string $filter   'upcoming' | 'past' | 'all'
 * @var string $userRole
 * @var string $csrf
 */
use App\Models\User;
$activePage = 'reminders';
$filter   = $filter ?? 'upcoming';
$reminders = $reminders ?? [];
$csrf     = $csrf ?? ($_SESSION['csrf_token'] ?? '');

$tabs = [
    'upcoming' => 'Upcoming',
    'past'     => 'Past',
    'all'      => 'All',
];
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<title>Reminders - Base Fare CRM</title>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
<script src="<?= \App\Services\Asset::url('assets/js/error-beacon.js') ?>"></script>
<script src="/assets/js/tailwind.js"></script>
<script src="<?= \App\Services\Asset::url('assets/js/buddy-widget.js') ?>" defer></script>
<script>
tailwind.config={darkMode:"class",theme:{extend:{colors:{primary:"#163274","primary-container":"#314a8d",background:"#f8f9fa",surface:"#f8f9fa","surface-container":"#edeeef","on-surface":"#191c1d","on-surface-variant":"#434653"},fontFamily:{headline:["Manrope"],body:["Inter"]}}}}
</script>
</head>
<body class="bg-background font-body text-on-surface antialiased min-h-screen">

<?php require __DIR__ . '/../layout/sidebar.php'; ?>

<main class="ml-60 pt-6 pb-20 px-8">

  <!-- Header -->
  <div class="flex items-center justify-between mb-6">
    <div>
      <h1 class="text-2xl font-headline font-extrabold text-primary tracking-tight">
        <span class="material-symbols-outlined align-text-bottom">notifications_active</span>
        Booking Reminders
      </h1>
      <p class="text-sm text-on-surface-variant mt-0.5">
        Alerts fired to the agent, their manager, and admins before departure.
      </p>
    </div>
  </div>

  <!-- Filter tabs -->
  <div class="flex items-center gap-1 mb-5 border-b border-slate-200">
    <?php foreach ($tabs as $key => $label): ?>
    <a href="/reminders?filter=<?= $key ?>"
       class="px-4 py-2 text-sm font-semibold -mb-px border-b-2 transition-colors
       <?= $filter === $key
           ? 'border-primary text-primary'
           : 'border-transparent text-on-surface-variant hover:text-primary' ?>">
      <?= $label ?>
    </a>
    <?php endforeach; ?>
  </div>

  <!-- Table -->
  <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="bg-slate-50 border-b border-slate-100 text-left">
            <th class="px-5 py-3 text-[11px] font-bold text-slate-500 uppercase">Reminder</th>
            <th class="px-5 py-3 text-[11px] font-bold text-slate-500 uppercase">Booking</th>
            <th class="px-5 py-3 text-[11px] font-bold text-slate-500 uppercase">Agent</th>
            <th class="px-5 py-3 text-[11px] font-bold text-slate-500 uppercase">Fires</th>
            <th class="px-5 py-3 text-[11px] font-bold text-slate-500 uppercase">Departure</th>
            <th class="px-5 py-3 text-[11px] font-bold text-slate-500 uppercase">Status</th>
            <th class="px-5 py-3 text-[11px] font-bold text-slate-500 uppercase text-right">Action</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-50">
          <?php if (!count($reminders)): ?>
          <tr><td colspan="7" class="px-5 py-10 text-center text-sm text-slate-400">
            No <?= htmlspecialchars($filter === 'all' ? '' : $filter) ?> reminders.
          </td></tr>
          <?php else: ?>
          <?php foreach ($reminders as $r): [$rl, $rc] = $r->statusBadge(); $t = $r->transaction; ?>
          <tr class="hover:bg-slate-50/60" data-reminder-id="<?= $r->id ?>">
            <td class="px-5 py-3 align-top">
              <p class="font-semibold text-slate-800"><?= htmlspecialchars($r->title) ?></p>
              <?php if ($r->message): ?>
              <p class="text-xs text-slate-500 mt-0.5 max-w-xs truncate"><?= htmlspecialchars($r->message) ?></p>
              <?php endif; ?>
              <p class="text-[11px] text-slate-400 mt-0.5"><?= htmlspecialchars($r->timingLabel()) ?> · by <?= htmlspecialchars($r->creator->name ?? '—') ?></p>
            </td>
            <td class="px-5 py-3 align-top">
              <?php if ($t): ?>
              <a href="/transactions/<?= $t->id ?>" class="font-mono font-bold text-primary hover:underline"><?= htmlspecialchars($t->pnr ?: ('#' . $t->id)) ?></a>
              <p class="text-xs text-slate-500"><?= htmlspecialchars($t->customer_name ?? '') ?></p>
              <?php else: ?>
              <span class="text-slate-400">—</span>
              <?php endif; ?>
            </td>
            <td class="px-5 py-3 align-top text-slate-700"><?= htmlspecialchars($t->agent->name ?? '—') ?></td>
            <td class="px-5 py-3 align-top text-slate-700 whitespace-nowrap"><?= $r->remind_at ? date('M j, Y g:i A', strtotime($r->remind_at)) : '—' ?></td>
            <td class="px-5 py-3 align-top text-slate-700 whitespace-nowrap"><?= $r->departure_at ? date('M j, Y g:i A', strtotime($r->departure_at)) : '—' ?></td>
            <td class="px-5 py-3 align-top"><span class="inline-block px-2 py-0.5 text-[10px] font-bold rounded-full <?= $rc ?>"><?= $rl ?></span></td>
            <td class="px-5 py-3 align-top text-right">
              <?php if ($r->isScheduled()): ?>
              <button type="button" onclick="cancelReminder(<?= $r->id ?>)"
                class="text-xs font-semibold text-slate-400 hover:text-red-600 transition-colors">Cancel</button>
              <?php else: ?>
              <span class="text-slate-300">—</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</main>

<script>
var CSRF = <?= json_encode($csrf) ?>;
function cancelReminder(id) {
  if (!confirm('Cancel this reminder? It will no longer fire.')) return;
  fetch('/reminders/' + id + '/cancel', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'csrf_token=' + encodeURIComponent(CSRF)
  })
  .then(function (r) { return r.json(); })
  .then(function (d) {
    if (!d.success) { alert(d.error || 'Could not cancel.'); return; }
    var row = document.querySelector('tr[data-reminder-id="' + id + '"]');
    if (row) row.parentNode.removeChild(row);
  })
  .catch(function () { alert('Network error.'); });
}
</script>
</body>
</html>
