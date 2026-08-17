<?php
/**
 * Customer Emails — Thread inbox list.
 *
 * @var array  $records      CustomerEmailThread[]
 * @var int    $total
 * @var int    $page
 * @var int    $total_pages
 * @var array  $filters
 * @var string $role
 * @var bool   $canApprove
 * @var int    $pendingCount
 */
use App\Models\CustomerEmailThread;

$activePage = 'emails';

function ceThreadBadge(CustomerEmailThread $t): string {
    $b = $t->statusBadge();
    $map = [
        'amber'   => 'bg-amber-100 text-amber-700',
        'blue'    => 'bg-blue-100 text-blue-700',
        'slate'   => 'bg-slate-100 text-slate-500',
        'emerald' => 'bg-emerald-100 text-emerald-700',
    ];
    $cls = $map[$b['color']] ?? $map['slate'];
    return '<span class="inline-flex items-center gap-1 px-2.5 py-1 text-[10px] font-bold rounded-full ' . $cls . '">' . htmlspecialchars($b['label']) . '</span>';
}
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<title>Customer Emails — Base Fare CRM</title>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
<script src="<?= \App\Services\Asset::url('assets/js/error-beacon.js') ?>"></script>
<script src="/assets/js/tailwind.js"></script>
<script src="<?= \App\Services\Asset::url('assets/js/buddy-widget.js') ?>" defer></script>
<script>
tailwind.config = { darkMode: "class", theme: { extend: {
  fontFamily: { sans: ['Inter', 'Manrope', 'sans-serif'] },
  colors: { primary: { DEFAULT: '#0f1e3c', 50: '#f0f4ff', 100: '#dde8ff', 500: '#1a3a6b', 600: '#0f1e3c' }, gold: { DEFAULT: '#c9a84c' } }
}}}
</script>
</head>
<body class="bg-slate-50 font-sans min-h-screen">

<?php require __DIR__ . '/../layout/sidebar.php'; ?>

<main class="ml-60 pt-6 pb-20 px-8">

  <?php if (!empty($_SESSION['flash_error'])): ?>
    <div class="mb-4 px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm font-semibold">
      <?= htmlspecialchars($_SESSION['flash_error']) ?>
    </div>
    <?php unset($_SESSION['flash_error']); ?>
  <?php endif; ?>

  <div class="flex items-center justify-between mb-6">
    <div>
      <h1 class="text-2xl font-headline font-extrabold text-primary tracking-tight flex items-center gap-2">
        <span class="material-symbols-outlined text-2xl">forward_to_inbox</span>
        Customer Emails
      </h1>
      <p class="text-sm text-slate-500 mt-0.5"><?= number_format($total) ?> conversation<?= $total === 1 ? '' : 's' ?></p>
    </div>
    <div class="flex items-center gap-2">
      <?php if ($canApprove): ?>
        <a href="/emails/approvals" class="relative inline-flex items-center gap-2 px-4 py-2.5 bg-amber-50 text-amber-700 border border-amber-200 font-bold rounded-xl hover:bg-amber-100 transition-all text-sm">
          <span class="material-symbols-outlined text-base">rule</span> Approvals
          <?php if ($pendingCount > 0): ?>
            <span class="absolute -top-2 -right-2 min-w-[20px] h-5 px-1 flex items-center justify-center text-[11px] font-extrabold text-white bg-rose-500 rounded-full"><?= $pendingCount ?></span>
          <?php endif; ?>
        </a>
      <?php endif; ?>
      <?php if (($role ?? '') !== 'csa'): ?>
        <a href="/emails/compose" class="inline-flex items-center gap-2 px-5 py-2.5 bg-primary text-white font-bold rounded-xl hover:bg-primary-500 shadow-lg shadow-primary/20 transition-all text-sm">
          <span class="material-symbols-outlined text-base">edit_note</span> Compose
        </a>
      <?php endif; ?>
    </div>
  </div>

  <form method="GET" action="/emails" class="mb-6 bg-white border border-slate-200 rounded-xl p-4">
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
      <input type="text" name="search" value="<?= htmlspecialchars($filters['search'] ?? '') ?>"
             placeholder="Name, email, subject…"
             class="col-span-2 rounded-lg border-slate-300 text-sm focus:ring-primary focus:border-primary"/>
      <select name="status" class="rounded-lg border-slate-300 text-sm focus:ring-primary focus:border-primary">
        <option value="">All statuses</option>
        <?php foreach (['open'=>'Open','awaiting_agent'=>'Needs reply','awaiting_customer'=>'Sent','closed'=>'Closed'] as $k=>$v): ?>
          <option value="<?= $k ?>" <?= ($filters['status'] ?? '')===$k?'selected':'' ?>><?= $v ?></option>
        <?php endforeach; ?>
      </select>
      <button class="px-4 py-2 bg-primary text-white text-sm font-bold rounded-lg hover:bg-primary-500">Filter</button>
    </div>
  </form>

  <div class="bg-white border border-slate-200 rounded-xl overflow-hidden">
    <table class="w-full text-sm">
      <thead class="bg-slate-50 text-slate-500 text-[11px] uppercase tracking-wide">
        <tr>
          <th class="text-left px-5 py-3 font-bold">Customer</th>
          <th class="text-left px-5 py-3 font-bold">Subject</th>
          <th class="text-left px-5 py-3 font-bold">Status</th>
          <th class="text-left px-5 py-3 font-bold">Last activity</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-slate-100">
        <?php if (empty($records) || count($records) === 0): ?>
          <tr><td colspan="4" class="px-5 py-12 text-center text-slate-400">
            No conversations yet. <a href="/emails/compose" class="text-primary font-semibold underline">Compose one</a>.
          </td></tr>
        <?php else: foreach ($records as $t): ?>
          <tr class="hover:bg-slate-50 cursor-pointer" onclick="location.href='/emails/<?= $t->id ?>'">
            <td class="px-5 py-3">
              <div class="font-bold text-primary"><?= htmlspecialchars($t->customer_name ?: 'Customer') ?></div>
              <div class="text-xs text-slate-400"><?= htmlspecialchars($t->customer_email) ?></div>
            </td>
            <td class="px-5 py-3 text-slate-700"><?= htmlspecialchars($t->subject ?: '—') ?></td>
            <td class="px-5 py-3"><?= ceThreadBadge($t) ?></td>
            <td class="px-5 py-3 text-xs text-slate-500"><?= $t->last_message_at ? $t->last_message_at->format('M j, Y g:i A') : $t->created_at->format('M j, Y') ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <?php if (($total_pages ?? 1) > 1): ?>
    <div class="flex items-center justify-center gap-1 mt-6">
      <?php for ($i = 1; $i <= $total_pages; $i++): ?>
        <a href="?page=<?= $i ?>&status=<?= urlencode($filters['status'] ?? '') ?>&search=<?= urlencode($filters['search'] ?? '') ?>"
           class="px-3 py-1.5 rounded-lg text-sm font-semibold <?= $i===$page?'bg-primary text-white':'bg-white border border-slate-200 text-slate-600 hover:bg-slate-50' ?>"><?= $i ?></a>
      <?php endfor; ?>
    </div>
  <?php endif; ?>

</main>
</body>
</html>
