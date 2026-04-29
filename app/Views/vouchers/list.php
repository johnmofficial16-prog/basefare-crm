<?php
$activePage = 'vouchers';
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
<meta charset="utf-8"/>
<meta content="width=device-width,initial-scale=1.0" name="viewport"/>
<title>Travel Vouchers — Base Fare CRM</title>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
<script src="https://cdn.tailwindcss.com?plugins=forms"></script>
<script>
tailwind.config={darkMode:"class",theme:{extend:{colors:{primary:"#163274","primary-container":"#314a8d",background:"#f8f9fa","surface-container-low":"#f3f4f5","on-surface":"#191c1d","on-surface-variant":"#434653"},fontFamily:{headline:["Manrope"],body:["Inter"]}}}};
</script>
<style>
.material-symbols-outlined{font-variation-settings:'FILL' 0,'wght' 400,'GRAD' 0,'opsz' 24}
</style>
</head>
<body class="bg-background font-body text-on-surface antialiased min-h-screen flex flex-col">

<?php require __DIR__.'/../partials/admin_sidebar.php'; ?>

<main class="ml-60 flex-1 flex flex-col">
  <div class="px-8 py-6">
    <div class="flex items-center justify-between mb-6">
      <div>
        <h1 class="text-2xl font-headline font-extrabold text-primary tracking-tight flex items-center gap-2">
          <span class="material-symbols-outlined text-2xl">local_activity</span>
          Travel Vouchers
        </h1>
        <p class="text-sm text-on-surface-variant mt-0.5">Manage and view issued travel vouchers.</p>
      </div>
      <a href="/vouchers/maker" class="inline-flex items-center gap-1.5 px-5 py-2.5 bg-primary text-white font-bold rounded-xl hover:bg-primary-container shadow-lg shadow-primary/20 transition-all text-sm">
        <span class="material-symbols-outlined text-base">add</span> Issue New Voucher
      </a>
    </div>

    <div class="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-left text-sm whitespace-nowrap">
          <thead class="bg-slate-50 border-b border-slate-100 text-slate-500">
            <tr>
              <th class="px-6 py-3 font-semibold">Voucher No.</th>
              <th class="px-6 py-3 font-semibold">Customer</th>
              <th class="px-6 py-3 font-semibold">Amount</th>
              <th class="px-6 py-3 font-semibold">Issue Date</th>
              <th class="px-6 py-3 font-semibold">Status</th>
              <th class="px-6 py-3 font-semibold">Issued By</th>
              <th class="px-6 py-3 font-semibold text-right">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <?php if (empty($vouchers)): ?>
            <tr>
              <td colspan="7" class="px-6 py-8 text-center text-slate-400">
                <span class="material-symbols-outlined text-4xl opacity-50 mb-2 block">receipt_long</span>
                No vouchers have been issued yet.
              </td>
            </tr>
            <?php else: ?>
              <?php foreach ($vouchers as $v): ?>
              <tr class="hover:bg-slate-50 transition-colors">
                <td class="px-6 py-4 font-bold text-primary"><?= htmlspecialchars($v->voucher_no) ?></td>
                <td class="px-6 py-4">
                  <div class="font-semibold text-slate-900"><?= htmlspecialchars($v->customer_name) ?></div>
                  <div class="text-xs text-slate-500">PNR: <?= htmlspecialchars($v->pnr ?: 'N/A') ?></div>
                </td>
                <td class="px-6 py-4 font-bold"><?= htmlspecialchars($v->currency) ?> <?= number_format($v->amount, 2) ?></td>
                <td class="px-6 py-4">
                  <div><?= $v->issue_date->format('M d, Y') ?></div>
                  <div class="text-xs text-slate-500">Exp: <?= $v->expiry_date->format('M d, Y') ?></div>
                </td>
                <td class="px-6 py-4">
                  <?php if ($v->status === 'active'): ?>
                    <span class="inline-flex items-center gap-1 px-2 py-1 bg-emerald-50 text-emerald-700 text-xs font-bold rounded-md border border-emerald-200">Active</span>
                  <?php elseif ($v->status === 'redeemed'): ?>
                    <span class="inline-flex items-center gap-1 px-2 py-1 bg-blue-50 text-blue-700 text-xs font-bold rounded-md border border-blue-200">Redeemed</span>
                  <?php else: ?>
                    <span class="inline-flex items-center gap-1 px-2 py-1 bg-rose-50 text-rose-700 text-xs font-bold rounded-md border border-rose-200">Void</span>
                  <?php endif; ?>
                </td>
                <td class="px-6 py-4 text-xs text-slate-500">
                  <?= htmlspecialchars($v->creator->full_name ?? 'System') ?>
                </td>
                <td class="px-6 py-4 text-right space-x-2">
                  <a href="/vouchers/<?= $v->id ?>" class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-slate-100 text-slate-600 hover:bg-primary hover:text-white transition-all shadow-sm" title="View Details">
                    <span class="material-symbols-outlined text-base">visibility</span>
                  </a>
                  <?php if ($_SESSION['role'] === 'admin'): ?>
                    <button onclick="deleteVoucher(<?= $v->id ?>)" class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-slate-100 text-rose-600 hover:bg-rose-600 hover:text-white transition-all shadow-sm" title="Delete Voucher">
                      <span class="material-symbols-outlined text-base">delete</span>
                    </button>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</main>

<script>
async function deleteVoucher(id) {
    if (!confirm('Are you absolutely sure you want to delete this voucher? This cannot be undone.')) return;

    try {
        const res = await fetch(`/vouchers/${id}`, {
            method: 'DELETE',
            headers: {
                'X-CSRF-Token': '<?= $_SESSION['csrf_token'] ?? '' ?>'
            }
        });
        const data = await res.json();
        if (data.success) {
            window.location.reload();
        } else {
            alert(data.error);
        }
    } catch (err) {
        alert('Error deleting voucher: ' + err.message);
    }
}
</script>
</body>
</html>
