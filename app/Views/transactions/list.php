<?php
/**
 * Transaction Recorder — List View
 * Filterable table with status badges, quick stats, pagination.
 *
 * @var array  $data     ['items','total','pages','page'] from TransactionService::list()
 * @var array  $filters  Active filter values
 * @var string $userRole Current user role
 */

use App\Models\Transaction;

$items   = $data['items'];
$total   = $data['total'];
$pages   = $data['pages'];
$page    = $data['page'];
$filters = $filters ?? [];
$userId  = $_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? 'agent';
$isAdmin = in_array($userRole, ['admin','manager']);
// Universal search: agents can search all records (read-only). Only used for display column logic.
$isSearchingAll = !$isAdmin && !empty($filters['search']);

// Quick stats
$todayCount   = 0;
$todayRevenue = 0;
$todayMco     = 0;
foreach ($items as $t) {
    if (date('Y-m-d', strtotime($t->created_at)) === date('Y-m-d')) {
        $todayCount++;
        $todayRevenue += $t->total_amount;
        $todayMco     += $t->profit_mco;
    }
}

$flashSuccess = $_SESSION['flash_success'] ?? null;
$flashError   = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);
$activePage = 'transactions';
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<title>Transactions - Base Fare CRM</title>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
<script src="https://cdn.tailwindcss.com?plugins=forms"></script>
<script>
tailwind.config={darkMode:"class",theme:{extend:{colors:{primary:"#163274","primary-container":"#314a8d",background:"#f8f9fa",surface:"#f8f9fa","surface-container":"#edeeef","surface-container-low":"#f3f4f5","on-surface":"#191c1d","on-surface-variant":"#434653"},fontFamily:{headline:["Manrope"],body:["Inter"],label:["Inter"]}}}}
</script>
</head>
<body class="bg-background font-body text-on-surface antialiased min-h-screen">

<?php if ($isAdmin): ?>
<?php $activePage = 'transactions'; require __DIR__ . '/../partials/admin_sidebar.php'; ?>
<?php else: ?>
<?php $activePage = 'transactions'; require __DIR__ . '/../partials/agent_sidebar.php'; ?>
<?php endif; ?>

<main class="ml-60 pt-6 pb-20 px-8">

  <!-- Header -->
  <div class="flex items-center justify-between mb-6">
    <div>
      <h1 class="text-2xl font-headline font-extrabold text-primary tracking-tight flex items-center gap-2">
        <span class="material-symbols-outlined text-2xl">receipt_long</span>
        Transaction Recorder
      </h1>
      <p class="text-sm text-on-surface-variant mt-0.5"><?= number_format($total) ?> total transactions</p>
    </div>
    <div class="flex items-center gap-3">
      <?php if ($isAdmin): ?>
      <a id="btn-csv-transactions"
         href="/transactions/export?<?= http_build_query(array_filter($filters)) ?>"
         class="inline-flex items-center gap-2 px-4 py-2.5 bg-emerald-600 text-white font-bold rounded-xl hover:bg-emerald-700 shadow-lg shadow-emerald-600/20 transition-all text-sm"
         title="Download current filter results as CSV">
        <span class="material-symbols-outlined text-base">download</span> Export CSV
      </a>
      <?php endif; ?>
      <a href="/transactions/create"
        class="inline-flex items-center gap-2 px-5 py-2.5 bg-primary text-white font-bold rounded-xl hover:bg-primary-container shadow-lg shadow-primary/20 transition-all text-sm">
        <span class="material-symbols-outlined text-base">add_card</span> Record Transaction
      </a>
    </div>
  </div>


  <!-- Flash Messages -->
  <?php if ($flashSuccess): ?>
  <div class="mb-4 px-4 py-3 bg-emerald-50 border border-emerald-200 rounded-xl text-sm font-semibold text-emerald-700 flex items-center gap-2">
    <span class="material-symbols-outlined text-base">check_circle</span>
    <?= htmlspecialchars($flashSuccess) ?>
  </div>
  <?php endif; ?>
  <?php if ($flashError): ?>
  <div class="mb-4 px-4 py-3 bg-red-50 border border-red-200 rounded-xl text-sm font-semibold text-red-700 flex items-center gap-2">
    <span class="material-symbols-outlined text-base">error</span>
    <?= htmlspecialchars($flashError) ?>
  </div>
  <?php endif; ?>

  <!-- Quick Stats -->
  <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
    <div class="bg-white border border-slate-200 rounded-xl p-4">
      <p class="text-[10px] font-bold text-slate-500 uppercase tracking-wider">Today</p>
      <p class="text-2xl font-headline font-extrabold text-primary mt-1"><?= $todayCount ?></p>
    </div>
    <div class="bg-white border border-slate-200 rounded-xl p-4">
      <p class="text-[10px] font-bold text-slate-500 uppercase tracking-wider">Today Revenue</p>
      <p class="text-2xl font-headline font-extrabold text-emerald-600 mt-1">$<?= number_format($todayRevenue, 2) ?></p>
    </div>
    <div class="bg-white border border-slate-200 rounded-xl p-4">
      <p class="text-[10px] font-bold text-slate-500 uppercase tracking-wider">Today MCO</p>
      <p class="text-2xl font-headline font-extrabold text-blue-600 mt-1">$<?= number_format($todayMco, 2) ?></p>
    </div>
    <div class="bg-white border border-slate-200 rounded-xl p-4">
      <p class="text-[10px] font-bold text-slate-500 uppercase tracking-wider">Total Records</p>
      <p class="text-2xl font-headline font-extrabold text-on-surface mt-1"><?= number_format($total) ?></p>
    </div>
  </div>

  <!-- Filters & Search -->
  <form method="GET" action="/transactions" class="mb-6 bg-white border border-slate-200 rounded-xl p-4">
    <!-- Universal search bar (searches across ALL transactions for all roles) -->
    <div class="mb-3">
      <div class="relative">
        <span class="absolute left-3 top-1/2 -translate-y-1/2 material-symbols-outlined text-slate-400 text-[18px]">search</span>
        <input type="text" name="search" value="<?= htmlspecialchars($filters['search'] ?? '') ?>"
          placeholder="Search by customer name, phone, email or PNR…"
          class="w-full border border-slate-200 rounded-lg pl-9 pr-4 py-2.5 text-sm bg-slate-50 focus:ring-2 focus:ring-primary/40 focus:border-primary">
      </div>
      <?php if (!$isAdmin && !empty($filters['search'])): ?>
      <p class="text-[10px] text-slate-400 mt-1 ml-1">Showing results across all agents — read-only view for customer assistance.</p>
      <?php endif; ?>
    </div>
    <div class="grid grid-cols-2 sm:grid-cols-6 gap-3">
      <input type="text" name="pnr" value="<?= htmlspecialchars($filters['pnr'] ?? '') ?>" placeholder="PNR"
        class="w-full border border-slate-200 rounded-lg px-3 py-2 text-xs bg-slate-50 focus:ring-2 focus:ring-primary/40 font-mono uppercase">
      <select name="type" class="w-full border border-slate-200 rounded-lg px-3 py-2 text-xs bg-slate-50 focus:ring-2 focus:ring-primary/40">
        <option value="">All Types</option>
        <?php foreach (Transaction::typeOptions() as $val => $lbl): ?>
        <option value="<?= $val ?>" <?= ($filters['type'] ?? '') === $val ? 'selected' : '' ?>><?= $lbl ?></option>
        <?php endforeach; ?>
      </select>
      <select name="status" class="w-full border border-slate-200 rounded-lg px-3 py-2 text-xs bg-slate-50 focus:ring-2 focus:ring-primary/40">
        <option value="">All Status</option>
        <option value="pending_review" <?= ($filters['status'] ?? '') === 'pending_review' ? 'selected' : '' ?>>Pending</option>
        <option value="approved" <?= ($filters['status'] ?? '') === 'approved' ? 'selected' : '' ?>>Approved</option>
        <option value="voided" <?= ($filters['status'] ?? '') === 'voided' ? 'selected' : '' ?>>Voided</option>
      </select>
      <input type="date" name="date_from" value="<?= htmlspecialchars($filters['date_from'] ?? '') ?>" class="w-full border border-slate-200 rounded-lg px-3 py-2 text-xs bg-slate-50 focus:ring-2 focus:ring-primary/40">
      <input type="date" name="date_to" value="<?= htmlspecialchars($filters['date_to'] ?? '') ?>" class="w-full border border-slate-200 rounded-lg px-3 py-2 text-xs bg-slate-50 focus:ring-2 focus:ring-primary/40">
      <button type="submit" class="w-full inline-flex items-center justify-center gap-1 px-4 py-2 bg-primary text-white text-xs font-bold rounded-lg hover:bg-primary-container transition-colors">
        <span class="material-symbols-outlined text-sm">search</span> Filter
      </button>
    </div>
    <?php if (!empty($filters['search']) || !empty($filters['pnr']) || !empty($filters['type']) || !empty($filters['status']) || !empty($filters['date_from']) || !empty($filters['date_to'])): ?>
    <div class="mt-2">
      <a href="/transactions" class="text-xs text-slate-400 hover:text-red-500 transition-colors inline-flex items-center gap-1">
        <span class="material-symbols-outlined text-[14px]">close</span> Clear filters
      </a>
    </div>
    <?php endif; ?>
  </form>

  <!-- Table -->
  <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="bg-slate-50/80 border-b border-slate-200">
            <th class="px-4 py-3 text-left text-[10px] font-bold text-slate-500 uppercase tracking-wider">ID</th>
            <th class="px-4 py-3 text-left text-[10px] font-bold text-slate-500 uppercase tracking-wider">Type</th>
            <th class="px-4 py-3 text-left text-[10px] font-bold text-slate-500 uppercase tracking-wider">Customer</th>
            <th class="px-4 py-3 text-left text-[10px] font-bold text-slate-500 uppercase tracking-wider">PNR</th>
            <th class="px-4 py-3 text-left text-[10px] font-bold text-slate-500 uppercase tracking-wider">Amount</th>
            <th class="px-4 py-3 text-left text-[10px] font-bold text-slate-500 uppercase tracking-wider">MCO</th>
            <th class="px-4 py-3 text-left text-[10px] font-bold text-slate-500 uppercase tracking-wider">Card</th>
            <th class="px-4 py-3 text-left text-[10px] font-bold text-slate-500 uppercase tracking-wider">Status</th>
            <?php if ($isAdmin || $isSearchingAll): ?>
            <th class="px-4 py-3 text-left text-[10px] font-bold text-slate-500 uppercase tracking-wider">Agent</th>
            <?php endif; ?>
            <th class="px-4 py-3 text-left text-[10px] font-bold text-slate-500 uppercase tracking-wider">Date</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <?php if ($items->isEmpty()): ?>
          <tr>
            <td colspan="<?= $isAdmin ? 10 : 9 ?>" class="px-4 py-12 text-center text-sm text-slate-400">
              <span class="material-symbols-outlined text-3xl block mb-2">inbox</span>
              No transactions found.
            </td>
          </tr>
          <?php else: ?>
          <?php foreach ($items as $t): ?>
          <?php [$statusLabel, $statusClass] = $t->statusBadge(); ?>
          <tr class="hover:bg-slate-50/50 cursor-pointer transition-colors" onclick="window.location='/transactions/<?= $t->id ?>'">
            <td class="px-4 py-3 font-mono text-xs font-bold text-primary">#<?= $t->id ?></td>
            <td class="px-4 py-3">
              <span class="inline-block px-2 py-0.5 text-[10px] font-bold rounded-full bg-slate-100 text-slate-700">
                <?= $t->typeBadge() ?>
              </span>
            </td>
            <td class="px-4 py-3 text-xs font-semibold text-slate-900 max-w-[160px]">
              <div class="truncate"><?= htmlspecialchars($t->customer_name) ?></div>
              <?php if (!empty($t->customer_phone)): ?>
              <div class="text-[10px] text-slate-400 font-mono"><?= htmlspecialchars($t->customer_phone) ?></div>
              <?php endif; ?>
            </td>
            <td class="px-4 py-3 font-mono text-xs font-bold tracking-wider"><?= htmlspecialchars($t->pnr) ?></td>
            <td class="px-4 py-3 font-mono text-xs font-bold"><?= $t->currency ?> <?= number_format($t->total_amount, 2) ?></td>
            <td class="px-4 py-3 font-mono text-xs font-bold <?= $t->profit_mco >= 0 ? 'text-emerald-600' : 'text-red-600' ?>">
              <?= $t->profit_mco >= 0 ? '+' : '' ?><?= number_format($t->profit_mco, 2) ?>
            </td>
            <td class="px-4 py-3 text-xs text-slate-500">
              <?php if ($t->primaryCard): ?>
                <?= htmlspecialchars($t->primaryCard->displayLabel()) ?>
              <?php else: ?>
                —
              <?php endif; ?>
            </td>
            <td class="px-4 py-3">
              <span class="inline-block px-2.5 py-1 text-[10px] font-bold rounded-full <?= $statusClass ?>">
                <?= $statusLabel ?>
              </span>
            </td>
            <?php if ($isAdmin || $isSearchingAll): ?>
            <td class="px-4 py-3 text-xs text-slate-500"><?= htmlspecialchars($t->agent->name ?? '—') ?></td>
            <?php endif; ?>
            <td class="px-4 py-3 text-xs text-slate-400"><?= date('M d, Y g:i A', strtotime($t->created_at)) ?></td>
          </tr>
          <?php endforeach; ?>

          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <!-- Pagination -->
    <?php if ($pages > 1): ?>
    <div class="px-4 py-3 border-t border-slate-100 flex items-center justify-between">
      <p class="text-xs text-slate-500">Page <?= $page ?> of <?= $pages ?></p>
      <div class="flex items-center gap-1">
        <?php
        $qBase = $_GET;
        for ($p = max(1, $page - 3); $p <= min($pages, $page + 3); $p++):
          $qBase['page'] = $p;
          $qs = http_build_query($qBase);
        ?>
        <a href="/transactions?<?= $qs ?>"
          class="px-3 py-1 text-xs rounded-lg transition-colors <?= $p === $page ? 'bg-primary text-white font-bold' : 'text-slate-500 hover:bg-slate-100' ?>">
          <?= $p ?>
        </a>
        <?php endfor; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</main>

</body>
</html>
