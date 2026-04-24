<?php
/**
 * E-Ticket — List View
 */

use App\Models\ETicket;

$userRole = $_SESSION['role'] ?? 'agent';
$isAdmin  = in_array($userRole, ['admin', 'manager', 'supervisor']);
$activePage = 'etickets';

function etStatusBadge(string $status): string {
    return match($status) {
        'acknowledged' => '<span class="inline-flex items-center gap-1 px-2.5 py-1 text-[10px] font-bold rounded-full bg-emerald-100 text-emerald-700">✓ Acknowledged</span>',
        'sent'         => '<span class="inline-flex items-center gap-1 px-2.5 py-1 text-[10px] font-bold rounded-full bg-blue-100 text-blue-700">✉ Sent</span>',
        'draft'        => '<span class="inline-flex items-center gap-1 px-2.5 py-1 text-[10px] font-bold rounded-full bg-slate-100 text-slate-500">● Draft</span>',
        default        => htmlspecialchars(ucfirst($status)),
    };
}
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<title>E-Tickets — Base Fare CRM</title>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
<script src="https://cdn.tailwindcss.com?plugins=forms"></script>
<script>
tailwind.config={darkMode:"class",theme:{extend:{colors:{primary:"#163274","primary-container":"#314a8d",background:"#f8f9fa",surface:"#f8f9fa","surface-container":"#edeeef","surface-container-low":"#f3f4f5","on-surface":"#191c1d","on-surface-variant":"#434653"},fontFamily:{headline:["Manrope"],body:["Inter"],label:["Inter"]}}}}
</script>
</head>
<body class="bg-background font-body text-on-surface antialiased min-h-screen">

<?php if ($isAdmin): ?>
<?php require __DIR__ . '/../partials/admin_sidebar.php'; ?>
<?php else: ?>
<?php require __DIR__ . '/../partials/agent_sidebar.php'; ?>
<?php endif; ?>

<main class="ml-60 pt-6 pb-20 px-8">

  <!-- Header -->
  <div class="flex items-center justify-between mb-6">
    <div>
      <h1 class="text-2xl font-headline font-extrabold text-primary tracking-tight flex items-center gap-2">
        <span class="material-symbols-outlined text-2xl">airplane_ticket</span>
        E-Tickets
      </h1>
      <p class="text-sm text-on-surface-variant mt-0.5"><?= number_format($total) ?> total e-tickets</p>
    </div>
    <a href="/etickets/create"
       class="inline-flex items-center gap-2 px-5 py-2.5 bg-primary text-white font-bold rounded-xl hover:bg-primary-container shadow-lg shadow-primary/20 transition-all text-sm">
      <span class="material-symbols-outlined text-base">add</span> New E-Ticket
    </a>
  </div>

  <!-- Filters -->
  <form method="GET" action="/etickets" class="mb-6 bg-white border border-slate-200 rounded-xl p-4">
    <div class="grid grid-cols-2 sm:grid-cols-5 gap-3">
      <input type="text" name="search" value="<?= htmlspecialchars($filters['search'] ?? '') ?>"
             placeholder="Name, email, PNR…"
             class="w-full border border-slate-200 rounded-lg px-3 py-2 text-xs bg-slate-50 focus:ring-2 focus:ring-primary/40 col-span-2 sm:col-span-1">
      <select name="status" class="w-full border border-slate-200 rounded-lg px-3 py-2 text-xs bg-slate-50 focus:ring-2 focus:ring-primary/40">
        <option value="">All Statuses</option>
        <?php foreach (['draft','sent','acknowledged'] as $s): ?>
        <option value="<?= $s ?>" <?= ($filters['status'] ?? '') === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
        <?php endforeach; ?>
      </select>
      <input type="date" name="date_from" value="<?= htmlspecialchars($filters['date_from'] ?? '') ?>"
             class="w-full border border-slate-200 rounded-lg px-3 py-2 text-xs bg-slate-50 focus:ring-2 focus:ring-primary/40">
      <input type="date" name="date_to" value="<?= htmlspecialchars($filters['date_to'] ?? '') ?>"
             class="w-full border border-slate-200 rounded-lg px-3 py-2 text-xs bg-slate-50 focus:ring-2 focus:ring-primary/40">
      <button type="submit" class="inline-flex items-center justify-center gap-1 px-4 py-2 bg-primary text-white text-xs font-bold rounded-lg hover:bg-primary-container transition-colors">
        <span class="material-symbols-outlined text-sm">search</span> Filter
      </button>
    </div>
    <?php if (!empty($filters['search']) || !empty($filters['status']) || !empty($filters['date_from']) || !empty($filters['date_to'])): ?>
    <div class="mt-2">
      <a href="/etickets" class="text-xs text-slate-400 hover:text-red-500 transition-colors inline-flex items-center gap-1">
        <span class="material-symbols-outlined text-[14px]">close</span> Clear filters
      </a>
    </div>
    <?php endif; ?>
  </form>

  <!-- Table -->
  <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
    <?php if ($total === 0): ?>
    <div class="px-4 py-16 text-center">
      <span class="material-symbols-outlined text-4xl text-slate-300 block mb-3">airplane_ticket</span>
      <p class="text-sm font-semibold text-slate-400">No e-tickets found</p>
      <p class="text-xs text-slate-300 mt-1">Create your first e-ticket from an approved &amp; charged transaction.</p>
    </div>
    <?php else: ?>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="bg-slate-50/80 border-b border-slate-200">
            <th class="px-4 py-3 text-left text-[10px] font-bold text-slate-500 uppercase tracking-wider">ID</th>
            <th class="px-4 py-3 text-left text-[10px] font-bold text-slate-500 uppercase tracking-wider">Customer</th>
            <th class="px-4 py-3 text-left text-[10px] font-bold text-slate-500 uppercase tracking-wider">PNR</th>
            <th class="px-4 py-3 text-left text-[10px] font-bold text-slate-500 uppercase tracking-wider">Airline</th>
            <th class="px-4 py-3 text-left text-[10px] font-bold text-slate-500 uppercase tracking-wider">Amount</th>
            <th class="px-4 py-3 text-left text-[10px] font-bold text-slate-500 uppercase tracking-wider">Status</th>
            <?php if ($isAdmin): ?>
            <th class="px-4 py-3 text-left text-[10px] font-bold text-slate-500 uppercase tracking-wider">Agent</th>
            <?php endif; ?>
            <th class="px-4 py-3 text-left text-[10px] font-bold text-slate-500 uppercase tracking-wider">Created</th>
            <th class="px-4 py-3"></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <?php foreach ($records as $et): ?>
          <tr class="hover:bg-slate-50/50 cursor-pointer transition-colors" onclick="window.location='/etickets/<?= $et->id ?>'">
            <td class="px-4 py-3 font-mono text-xs font-bold text-primary">ET-<?= str_pad($et->id, 6, '0', STR_PAD_LEFT) ?></td>
            <td class="px-4 py-3">
              <div class="text-xs font-semibold text-slate-900"><?= htmlspecialchars($et->customer_name) ?></div>
              <div class="text-[10px] text-slate-400"><?= htmlspecialchars($et->customer_email) ?></div>
            </td>
            <td class="px-4 py-3 font-mono text-xs font-bold tracking-wider"><?= htmlspecialchars($et->pnr) ?></td>
            <td class="px-4 py-3 text-xs text-slate-500"><?= htmlspecialchars($et->airline ?? '—') ?></td>
            <td class="px-4 py-3 font-mono text-xs font-bold"><?= htmlspecialchars($et->currency) ?> <?= number_format($et->total_amount, 2) ?></td>
            <td class="px-4 py-3">
              <?= etStatusBadge($et->status) ?>
              <?php if ($et->acknowledged_at): ?>
              <div class="text-[10px] text-slate-400 mt-0.5"><?= $et->acknowledged_at->format('M j, g:i A') ?></div>
              <?php endif; ?>
            </td>
            <?php if ($isAdmin): ?>
            <td class="px-4 py-3 text-xs text-slate-500"><?= htmlspecialchars($et->agent?->name ?? '—') ?></td>
            <?php endif; ?>
            <td class="px-4 py-3 text-xs text-slate-400"><?= $et->created_at->format('M j, Y') ?></td>
            <td class="px-4 py-3 text-right">
              <a href="/etickets/<?= $et->id ?>"
                 class="inline-flex items-center gap-1 px-3 py-1.5 border border-slate-200 rounded-lg text-xs font-bold text-slate-600 hover:bg-slate-50 transition-colors">
                View
              </a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
    <div class="px-4 py-3 border-t border-slate-100 flex items-center justify-between">
      <p class="text-xs text-slate-500">Page <?= $page ?> of <?= $total_pages ?></p>
      <div class="flex items-center gap-1">
        <?php for ($p = max(1, $page-3); $p <= min($total_pages, $page+3); $p++): ?>
        <a href="?page=<?= $p ?>&<?= http_build_query(array_filter($filters)) ?>"
           class="px-3 py-1 text-xs rounded-lg transition-colors <?= $p === $page ? 'bg-primary text-white font-bold' : 'text-slate-500 hover:bg-slate-100' ?>">
          <?= $p ?>
        </a>
        <?php endfor; ?>
      </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
  </div>

</main>
</body>
</html>
