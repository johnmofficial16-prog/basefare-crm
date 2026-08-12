<?php
/**
 * Invoices — manager/admin approval queue.
 *
 * @var \Illuminate\Support\Collection $invoices  Pending invoices.
 * @var string $role
 */
$activePage = 'invoices';
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
<meta charset="utf-8"/>
<meta content="width=device-width,initial-scale=1.0" name="viewport"/>
<title>Invoice Approvals — Base Fare CRM</title>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
<script src="/assets/js/tailwind.js"></script>
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
        <span class="material-symbols-outlined text-2xl">approval</span> Invoice Approvals
      </h1>
      <p class="text-sm text-on-surface-variant mt-0.5"><?= $invoices->count() ?> invoice<?= $invoices->count() == 1 ? '' : 's' ?> awaiting approval</p>
    </div>
    <a href="/invoices" class="inline-flex items-center gap-1.5 px-4 py-2.5 bg-white border border-slate-200 text-slate-600 font-bold rounded-xl hover:border-primary hover:text-primary transition-all text-sm shadow-sm">
      <span class="material-symbols-outlined text-base">arrow_back</span> All Invoices
    </a>
  </div>

  <?php if ($invoices->isEmpty()): ?>
  <div class="bg-white border border-slate-200 rounded-2xl shadow-sm px-5 py-16 text-center text-slate-400">
    <span class="material-symbols-outlined text-5xl block mb-3">task_alt</span>
    <p class="font-semibold text-slate-500">All caught up</p>
    <p class="text-sm">No invoices are waiting for approval.</p>
  </div>
  <?php else: ?>
  <div class="space-y-4">
    <?php foreach ($invoices as $inv): ?>
    <div class="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden">
      <div class="flex items-start justify-between gap-4 p-5">
        <div class="min-w-0">
          <div class="flex items-center gap-2 mb-1">
            <a href="/invoices/<?= $inv->id ?>" class="font-mono font-bold text-primary hover:underline"><?= $h($inv->invoice_no) ?></a>
            <span class="px-2 py-0.5 rounded-full text-[11px] font-bold bg-amber-100 text-amber-800">Pending</span>
          </div>
          <p class="font-semibold text-slate-700"><?= $h($inv->customer_name) ?> · <span class="font-bold"><?= $h($inv->formattedTotal()) ?></span></p>
          <p class="text-[13px] text-slate-400 mt-0.5">
            <?= $h($inv->purposeLabel()) ?><?= $inv->pnr ? ' · PNR ' . $h($inv->pnr) : '' ?>
            · by <?= $h($inv->creator->name ?? 'Unknown') ?> · <?= $h($inv->created_at->format('M j, g:i A')) ?>
          </p>
          <?php if (!$inv->customer_email): ?>
          <p class="text-[12px] text-rose-500 font-semibold mt-1 flex items-center gap-1"><span class="material-symbols-outlined text-sm">warning</span> No customer email — add one before approving.</p>
          <?php endif; ?>
        </div>
        <div class="flex items-center gap-2 shrink-0">
          <a href="/invoices/<?= $inv->id ?>" class="px-3.5 py-2 bg-white border border-slate-200 text-slate-600 font-bold rounded-lg hover:border-primary hover:text-primary transition-all text-[13px]">Review</a>
          <form method="post" action="/invoices/<?= $inv->id ?>/approve" onsubmit="return confirm('Approve and email this invoice?')">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>"/>
            <button type="submit" class="inline-flex items-center gap-1 px-3.5 py-2 bg-emerald-600 text-white font-bold rounded-lg hover:bg-emerald-700 transition-all text-[13px]">
              <span class="material-symbols-outlined text-sm">verified</span> Approve &amp; Send
            </button>
          </form>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

</main>
</body>
</html>
