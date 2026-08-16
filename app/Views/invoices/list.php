<?php
/**
 * Invoices — list.
 *
 * @var array  $records  (Collection of Invoice)
 * @var int    $total
 * @var int    $page
 * @var int    $total_pages
 * @var array  $filters
 * @var string $role
 * @var bool   $canApprove
 * @var int    $pendingCount
 */
use App\Models\Invoice;

$activePage = 'invoices';
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$qs = fn(array $extra) => '?' . http_build_query(array_merge([
    'status' => $filters['status'] ?? '', 'search' => $filters['search'] ?? '',
], $extra));
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
<meta charset="utf-8"/>
<meta content="width=device-width,initial-scale=1.0" name="viewport"/>
<title>Invoices — Base Fare CRM</title>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
<script src="/assets/js/error-beacon.js"></script>
<script src="/assets/js/tailwind.js"></script>
<script src="/assets/js/buddy-widget.js" defer></script>
<script>
tailwind.config={darkMode:"class",theme:{extend:{colors:{primary:"#163274","primary-container":"#314a8d",background:"#f8f9fa","on-surface":"#191c1d","on-surface-variant":"#434653"},fontFamily:{headline:["Manrope"],body:["Inter"]}}}};
</script>
<style>.material-symbols-outlined{font-variation-settings:'FILL' 0,'wght' 400,'GRAD' 0,'opsz' 24}</style>
</head>
<body class="bg-background font-body text-on-surface antialiased min-h-screen">

<?php require __DIR__.'/../layout/sidebar.php'; ?>

<main class="ml-60 pt-6 pb-20 px-8">

  <div class="flex items-center justify-between mb-6">
    <div>
      <h1 class="text-2xl font-headline font-extrabold text-primary tracking-tight flex items-center gap-2">
        <span class="material-symbols-outlined text-2xl">request_quote</span> Invoices
      </h1>
      <p class="text-sm text-on-surface-variant mt-0.5"><?= (int) $total ?> invoice<?= $total == 1 ? '' : 's' ?></p>
    </div>
    <div class="flex items-center gap-3">
      <?php if ($canApprove): ?>
      <a href="/invoices/approvals" class="relative inline-flex items-center gap-1.5 px-4 py-2.5 bg-white border border-slate-200 text-slate-600 font-bold rounded-xl hover:border-primary hover:text-primary transition-all text-sm shadow-sm">
        <span class="material-symbols-outlined text-base">approval</span> Approvals
        <?php if ($pendingCount > 0): ?><span class="ml-1 px-1.5 py-0.5 rounded-full bg-amber-500 text-white text-[10px] font-bold"><?= (int) $pendingCount ?></span><?php endif; ?>
      </a>
      <?php endif; ?>
      <a href="/invoices/create" class="inline-flex items-center gap-1.5 px-5 py-2.5 bg-primary text-white font-bold rounded-xl hover:bg-primary-container shadow-lg shadow-primary/20 transition-all text-sm">
        <span class="material-symbols-outlined text-base">add</span> New Invoice
      </a>
    </div>
  </div>

  <!-- Filters -->
  <form method="get" class="flex items-center gap-3 mb-5">
    <div class="relative flex-1 max-w-md">
      <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-lg">search</span>
      <input name="search" value="<?= $h($filters['search'] ?? '') ?>" placeholder="Search name, invoice no, PNR, email…" class="w-full pl-10 pr-3 py-2.5 border border-slate-200 rounded-xl text-sm bg-white focus:ring-2 focus:ring-primary/30 focus:border-primary"/>
    </div>
    <select name="status" onchange="this.form.submit()" class="px-3 py-2.5 border border-slate-200 rounded-xl text-sm bg-white focus:ring-2 focus:ring-primary/30 focus:border-primary">
      <option value="">All statuses</option>
      <?php foreach (['pending_approval'=>'Pending Approval','approved'=>'Approved','sent'=>'Sent','rejected'=>'Rejected'] as $v=>$l): ?>
      <option value="<?= $v ?>" <?= ($filters['status'] ?? '') === $v ? 'selected' : '' ?>><?= $l ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="px-4 py-2.5 bg-white border border-slate-200 text-slate-600 font-bold rounded-xl hover:border-primary hover:text-primary transition-all text-sm">Filter</button>
  </form>

  <!-- Table -->
  <div class="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden">
    <table class="w-full text-sm">
      <thead>
        <tr class="border-b border-slate-100 text-left text-[11px] uppercase tracking-wider text-slate-400">
          <th class="px-5 py-3 font-bold">Invoice</th>
          <th class="px-5 py-3 font-bold">Customer</th>
          <th class="px-5 py-3 font-bold">Purpose</th>
          <th class="px-5 py-3 font-bold text-right">Total</th>
          <th class="px-5 py-3 font-bold">Status</th>
          <th class="px-5 py-3 font-bold">Created</th>
          <th class="px-5 py-3 font-bold"></th>
        </tr>
      </thead>
      <tbody>
        <?php if ($records->isEmpty()): ?>
        <tr><td colspan="7" class="px-5 py-12 text-center text-slate-400">
          <span class="material-symbols-outlined text-4xl block mb-2">receipt_long</span>
          No invoices yet. <a href="/invoices/create" class="text-primary font-semibold hover:underline">Create one</a>.
        </td></tr>
        <?php else: foreach ($records as $inv): [$stLabel, $stClass] = $inv->statusBadge(); ?>
        <tr class="border-b border-slate-50 hover:bg-slate-50/60 transition-colors">
          <td class="px-5 py-3 font-mono font-bold text-primary"><?= $h($inv->invoice_no) ?></td>
          <td class="px-5 py-3">
            <div class="font-semibold text-slate-700"><?= $h($inv->customer_name) ?></div>
            <?php if ($inv->pnr): ?><div class="text-[11px] text-slate-400 font-mono"><?= $h($inv->pnr) ?></div><?php endif; ?>
          </td>
          <td class="px-5 py-3 text-slate-500"><?= $h($inv->purposeLabel()) ?></td>
          <td class="px-5 py-3 text-right font-bold text-slate-700"><?= $h($inv->formattedTotal()) ?></td>
          <td class="px-5 py-3"><span class="px-2.5 py-1 rounded-full text-xs font-bold <?= $stClass ?>"><?= $h($stLabel) ?></span></td>
          <td class="px-5 py-3 text-slate-400 text-[13px]"><?= $h($inv->created_at->format('M j, Y')) ?></td>
          <td class="px-5 py-3 text-right">
            <a href="/invoices/<?= $inv->id ?>" class="inline-flex items-center gap-1 text-primary font-semibold hover:underline text-[13px]">View <span class="material-symbols-outlined text-sm">chevron_right</span></a>
          </td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <?php if (($total_pages ?? 1) > 1): ?>
  <div class="flex items-center justify-center gap-2 mt-5">
    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
    <a href="<?= $qs(['page' => $i]) ?>" class="px-3.5 py-2 rounded-lg text-sm font-bold <?= $i === $page ? 'bg-primary text-white' : 'bg-white border border-slate-200 text-slate-500 hover:border-primary hover:text-primary' ?>"><?= $i ?></a>
    <?php endfor; ?>
  </div>
  <?php endif; ?>

</main>
</body>
</html>
