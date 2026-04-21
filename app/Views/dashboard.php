<?php
/**
 * CRM Dashboard — All roles
 *
 * Variables injected by DashboardController:
 * @var array   $stateInfo       Attendance state
 * @var array   $todayStats      Today's clock-in/work/break/late
 * @var array   $weekData        7-day attendance array
 * @var object  $todayShift      Shift record or null
 * @var ?array  $adminCounts     Attendance board totals (admin/manager/supervisor)
 * @var ?array  $dashboardData   Full business data (admin/manager only)
 * @var ?array  $supervisorData  Team-scoped data (supervisor only)
 * @var ?array  $agentData       Personal KPIs (agent only)
 */
$userName = $_SESSION['user_name'] ?? 'User';
$role     = $_SESSION['role']     ?? 'agent';

$isAdmin      = in_array($role, ['admin', 'manager']);
$isSupervisor = $role === 'supervisor';
$isAgent      = $role === 'agent';

$dd  = $dashboardData  ?? [];
$sd  = $supervisorData ?? [];
$ad  = $agentData      ?? [];

// ── Helpers ──────────────────────────────────────────────────────────────────
function dbTypeLabel(string $t): string {
    return match($t) {
        'new_booking'     => 'New Booking',    'exchange'       => 'Exchange',
        'cancel_refund'   => 'Cancellation',   'cancel_credit'  => 'Cancel Credit',
        'seat_purchase'   => 'Seat Purchase',  'cabin_upgrade'  => 'Cabin Upgrade',
        'name_correction' => 'Name Correction','other'          => 'Other',
        default           => ucfirst(str_replace('_', ' ', $t)),
    };
}
function dbStatusBadge(string $s): string {
    return match(strtolower($s)) {
        'approved'       => '<span class="inline-flex text-[10px] font-bold bg-emerald-100 text-emerald-700 px-2 py-0.5 rounded-full">APPROVED</span>',
        'pending_review' => '<span class="inline-flex text-[10px] font-bold bg-amber-100 text-amber-700 px-2 py-0.5 rounded-full">PENDING</span>',
        'voided'         => '<span class="inline-flex text-[10px] font-bold bg-red-100 text-red-600 px-2 py-0.5 rounded-full">VOIDED</span>',
        'pending'        => '<span class="inline-flex text-[10px] font-bold bg-amber-100 text-amber-700 px-2 py-0.5 rounded-full">PENDING</span>',
        'expired'        => '<span class="inline-flex text-[10px] font-bold bg-slate-100 text-slate-500 px-2 py-0.5 rounded-full">EXPIRED</span>',
        'cancelled'      => '<span class="inline-flex text-[10px] font-bold bg-slate-100 text-slate-400 px-2 py-0.5 rounded-full">CANCELLED</span>',
        default          => '<span class="inline-flex text-[10px] font-bold bg-slate-100 text-slate-500 px-2 py-0.5 rounded-full">'.strtoupper($s).'</span>',
    };
}
function dbAttBadge(string $state, int $lateMins = 0): string {
    if ($state === 'clocked_in')  return '<span class="text-[10px] font-bold text-emerald-700 bg-emerald-100 px-2 py-0.5 rounded-full">● Active'.($lateMins > 0 ? ' ('.$lateMins.'m late)' : '').'</span>';
    if ($state === 'on_break')    return '<span class="text-[10px] font-bold text-amber-700 bg-amber-100 px-2 py-0.5 rounded-full">☕ On Break</span>';
    if ($state === 'clocked_out') return '<span class="text-[10px] font-bold text-blue-700 bg-blue-100 px-2 py-0.5 rounded-full">✓ Done</span>';
    return '<span class="text-[10px] font-bold text-red-700 bg-red-100 px-2 py-0.5 rounded-full">Absent</span>';
}
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<title>Dashboard — Base Fare CRM</title>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
<script src="https://cdn.tailwindcss.com?plugins=forms"></script>
<script>
tailwind.config={darkMode:"class",theme:{extend:{colors:{primary:"#163274","primary-container":"#314a8d",background:"#f8f9fa","surface-container-low":"#f3f4f5","on-surface":"#191c1d","on-surface-variant":"#434653"},fontFamily:{headline:["Manrope"],body:["Inter"]}}}}
</script>
<style>
.material-symbols-outlined{font-variation-settings:'FILL' 0,'wght' 400,'GRAD' 0,'opsz' 24}
.kpi-card{transition:transform .15s,box-shadow .15s}.kpi-card:hover{transform:translateY(-2px);box-shadow:0 8px 24px -6px rgba(0,0,0,.1)}
</style>
</head>
<body class="bg-[#f8f9fa] font-body text-[#191c1d] antialiased min-h-screen">

<?php $activePage = 'dashboard'; ?>
<?php if ($isAdmin): ?>
  <?php require __DIR__ . '/partials/admin_sidebar.php'; ?>
<?php elseif ($isSupervisor): ?>
  <?php require __DIR__ . '/partials/supervisor_sidebar.php'; ?>
<?php else: ?>
  <?php require __DIR__ . '/partials/agent_sidebar.php'; ?>
<?php endif; ?>

<main class="ml-60 pt-8 pb-20 px-10">

  <!-- ── Page header ────────────────────────────────────────────────────── -->
  <div class="flex items-start justify-between mb-8">
    <div>
      <h1 class="text-3xl font-headline font-extrabold text-primary tracking-tight mb-1">
        Welcome back, <?= htmlspecialchars(explode(' ', $userName)[0]) ?>!
      </h1>
      <p class="text-[#434653] font-medium opacity-70"><?= date('l, F j, Y') ?></p>
    </div>
    <?php if ($isAdmin || $isSupervisor): ?>
    <div class="flex gap-2">
      <a href="/acceptance/create" class="inline-flex items-center gap-1.5 bg-primary text-white text-sm font-bold px-4 py-2 rounded-xl hover:bg-[#314a8d] transition-colors shadow-sm">
        <span class="material-symbols-outlined text-base">add</span> New Acceptance
      </a>
      <a href="/transactions/create" class="inline-flex items-center gap-1.5 bg-white border border-slate-200 text-primary text-sm font-bold px-4 py-2 rounded-xl hover:bg-slate-50 transition-colors shadow-sm">
        <span class="material-symbols-outlined text-base">receipt_long</span> Record Transaction
      </a>
    </div>
    <?php endif; ?>
  </div>

  <!-- ── Attendance board bar (shown to admin/manager/supervisor who have team data) -->
  <?php if ($adminCounts): ?>
  <div class="grid grid-cols-5 gap-4 mb-8">
    <a href="<?= $isAdmin ? '/attendance/admin' : '/attendance/admin' ?>" class="kpi-card bg-emerald-50 border border-emerald-100 rounded-2xl p-4">
      <div class="flex items-center justify-between mb-1"><span class="material-symbols-outlined text-emerald-600 text-xl">check_circle</span><span class="text-2xl font-headline font-extrabold text-emerald-700"><?= $adminCounts['in'] ?></span></div>
      <p class="text-xs font-semibold text-emerald-800">Working Now</p>
    </a>
    <a href="/attendance/admin" class="kpi-card bg-amber-50 border border-amber-100 rounded-2xl p-4">
      <div class="flex items-center justify-between mb-1"><span class="material-symbols-outlined text-amber-600 text-xl">coffee</span><span class="text-2xl font-headline font-extrabold text-amber-700"><?= $adminCounts['on_break'] ?></span></div>
      <p class="text-xs font-semibold text-amber-800">On Break</p>
    </a>
    <a href="/attendance/admin" class="kpi-card bg-blue-50 border border-blue-100 rounded-2xl p-4">
      <div class="flex items-center justify-between mb-1"><span class="material-symbols-outlined text-blue-500 text-xl">task_alt</span><span class="text-2xl font-headline font-extrabold text-blue-700"><?= $adminCounts['completed'] ?></span></div>
      <p class="text-xs font-semibold text-blue-800">Completed</p>
    </a>
    <a href="/attendance/admin" class="kpi-card bg-gray-100 border border-gray-200 rounded-2xl p-4">
      <div class="flex items-center justify-between mb-1"><span class="material-symbols-outlined text-gray-500 text-xl">person_off</span><span class="text-2xl font-headline font-extrabold text-gray-600"><?= $adminCounts['absent'] ?></span></div>
      <p class="text-xs font-semibold text-gray-700">Absent</p>
    </a>
    <a href="/attendance/admin" class="kpi-card bg-red-50 border border-red-100 rounded-2xl p-4 <?= $adminCounts['pending'] > 0 ? 'ring-2 ring-red-300 animate-pulse' : '' ?>">
      <div class="flex items-center justify-between mb-1"><span class="material-symbols-outlined text-red-600 text-xl">warning</span><span class="text-2xl font-headline font-extrabold text-red-700"><?= $adminCounts['pending'] ?></span></div>
      <p class="text-xs font-semibold text-red-800">Pending Override</p>
    </a>
  </div>
  <?php endif; ?>

  <!-- ═══════════════════════════════════════════════════════════════════════ -->
  <!-- ADMIN / MANAGER SECTION -->
  <!-- ═══════════════════════════════════════════════════════════════════════ -->
  <?php if ($isAdmin && !empty($dd)): ?>

  <!-- Row 1: Business KPIs (6 cards) -->
  <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-4 mb-8">
    <a href="/acceptance?status=PENDING" class="kpi-card col-span-1 bg-white border <?= $dd['pending_acceptances'] > 0 ? 'border-rose-300 ring-2 ring-rose-200' : 'border-slate-200' ?> rounded-2xl p-4 shadow-sm">
      <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-2">Pending Auth</p>
      <p class="text-3xl font-headline font-extrabold <?= $dd['pending_acceptances'] > 0 ? 'text-rose-600' : 'text-slate-400' ?>"><?= $dd['pending_acceptances'] ?></p>
      <p class="text-[10px] text-slate-500 mt-1"><?= $dd['expiring_soon'] > 0 ? '<span class="text-amber-600 font-bold">'.$dd['expiring_soon'].' expiring</span>' : 'All on time' ?></p>
    </a>
    <a href="/acceptance" class="kpi-card bg-white border border-slate-200 rounded-2xl p-4 shadow-sm">
      <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-2">New Today</p>
      <p class="text-3xl font-headline font-extrabold text-violet-700"><?= $dd['today_new_acc'] ?></p>
      <p class="text-[10px] text-slate-500 mt-1">Acceptances sent</p>
    </a>
    <a href="/acceptance?status=APPROVED" class="kpi-card bg-white border border-slate-200 rounded-2xl p-4 shadow-sm">
      <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-2">Signed Today</p>
      <p class="text-3xl font-headline font-extrabold text-emerald-600"><?= $dd['today_approved_acc'] ?></p>
      <p class="text-[10px] text-slate-500 mt-1">Customers approved</p>
    </a>
    <a href="/transactions" class="kpi-card bg-white border border-slate-200 rounded-2xl p-4 shadow-sm">
      <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-2">Txns Today</p>
      <p class="text-3xl font-headline font-extrabold text-blue-700"><?= $dd['today_txn_count'] ?></p>
      <p class="text-[10px] mt-1"><?= $dd['pending_txn_count'] > 0 ? '<span class="text-amber-600 font-bold">'.$dd['pending_txn_count'].' pending review</span>' : '<span class="text-slate-500">All recorded</span>' ?></p>
    </a>
    <a href="/transactions" class="kpi-card bg-white border border-slate-200 rounded-2xl p-4 shadow-sm">
      <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-2">Revenue Today</p>
      <p class="text-xl font-headline font-extrabold text-primary"><?= number_format($dd['today_revenue'], 0) ?></p>
      <p class="text-[10px] text-slate-500 mt-1"><?= htmlspecialchars($currency) ?> charged</p>
    </a>
    <div class="kpi-card bg-white border border-slate-200 rounded-2xl p-4 shadow-sm">
      <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-2">Profit Today</p>
      <p class="text-xl font-headline font-extrabold <?= $dd['today_profit'] >= 0 ? 'text-emerald-600' : 'text-rose-600' ?>">
        <?= ($dd['today_profit'] >= 0 ? '+' : '-') . number_format(abs($dd['today_profit']), 0) ?>
      </p>
      <p class="text-[10px] text-slate-500 mt-1">MCO / margin</p>
    </div>
  </div>

  <!-- Row 2: Action Items + Recent Activity -->
  <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">

    <!-- Action Items -->
    <div class="bg-white rounded-3xl shadow-sm border border-slate-200 overflow-hidden">
      <div class="px-6 py-4 border-b border-slate-100 bg-slate-50/60 flex items-center gap-2">
        <span class="material-symbols-outlined text-rose-500 text-xl">notifications_active</span>
        <h2 class="font-headline font-extrabold text-slate-900">Action Required</h2>
        <?php $totalActions = $dd['pending_acceptances'] + $dd['pending_txn_count']; ?>
        <?php if ($totalActions > 0): ?><span class="ml-auto text-xs font-bold bg-rose-100 text-rose-700 px-2 py-0.5 rounded-full"><?= $totalActions ?></span><?php endif; ?>
      </div>
      <div class="p-4 space-y-3">
        <?php if ($dd['expiring_soon'] > 0): ?>
        <a href="/acceptance?status=PENDING" class="flex items-start gap-3 p-3 bg-amber-50 border border-amber-200 rounded-xl hover:bg-amber-100 transition-colors">
          <span class="material-symbols-outlined text-amber-600 text-base mt-0.5">timer</span>
          <div><p class="text-sm font-bold text-amber-900"><?= $dd['expiring_soon'] ?> acceptance<?= $dd['expiring_soon'] > 1 ? 's' : '' ?> expiring soon</p>
          <p class="text-[11px] text-amber-700">Token expires &lt;4h — follow up now</p></div>
        </a>
        <?php endif; ?>
        <?php foreach ($dd['pending_acc_list'] as $acc): ?>
        <a href="/acceptance/<?= $acc->id ?>" class="flex items-start gap-3 p-3 bg-rose-50 border border-rose-100 rounded-xl hover:bg-rose-100 transition-colors">
          <span class="material-symbols-outlined text-rose-500 text-base mt-0.5">verified</span>
          <div class="min-w-0">
            <p class="text-sm font-bold text-rose-900 truncate"><?= htmlspecialchars($acc->customer_name) ?></p>
            <p class="text-[11px] text-rose-700">Acc #<?= $acc->id ?> · <?= $acc->currency ?> <?= number_format($acc->total_amount, 2) ?> · <?= $acc->agent?->name ?? '—' ?></p>
          </div>
        </a>
        <?php endforeach; ?>
        <?php foreach ($dd['pending_txn_list'] as $txn): ?>
        <a href="/transactions/<?= $txn->id ?>" class="flex items-start gap-3 p-3 bg-blue-50 border border-blue-100 rounded-xl hover:bg-blue-100 transition-colors">
          <span class="material-symbols-outlined text-blue-500 text-base mt-0.5">payments</span>
          <div class="min-w-0">
            <p class="text-sm font-bold text-blue-900 truncate"><?= htmlspecialchars($txn->customer_name) ?></p>
            <p class="text-[11px] text-blue-700">Txn #<?= $txn->id ?> · <?= dbTypeLabel($txn->type) ?> · <?= $txn->currency ?> <?= number_format($txn->total_amount, 2) ?></p>
          </div>
        </a>
        <?php endforeach; ?>
        <?php if ($totalActions === 0 && $dd['expiring_soon'] === 0): ?>
        <div class="text-center py-6 text-slate-400">
          <span class="material-symbols-outlined text-3xl mb-2 block">task_alt</span>
          <p class="text-sm font-semibold">All clear — nothing pending</p>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Recent Activity (merged feed) -->
    <div class="lg:col-span-2 bg-white rounded-3xl shadow-sm border border-slate-200 overflow-hidden">
      <div class="px-6 py-4 border-b border-slate-100 bg-slate-50/60 flex items-center justify-between">
        <div class="flex items-center gap-2"><span class="material-symbols-outlined text-primary text-xl">history</span><h2 class="font-headline font-extrabold text-slate-900">Recent Activity</h2></div>
        <div class="flex gap-2 text-xs font-semibold"><a href="/transactions" class="text-primary hover:underline">All Txns</a><span class="text-slate-300">·</span><a href="/acceptance" class="text-primary hover:underline">All Auth</a></div>
      </div>
      <div class="divide-y divide-slate-50">
        <?php
        $activity = [];
        foreach ($dd['recent_txns'] as $t) $activity[] = ['kind'=>'txn','item'=>$t,'ts'=>strtotime($t->created_at)];
        foreach ($dd['recent_acceptances'] as $a) $activity[] = ['kind'=>'acc','item'=>$a,'ts'=>strtotime($a->created_at)];
        usort($activity, fn($a,$b) => $b['ts'] - $a['ts']);
        $activity = array_slice($activity, 0, 10);
        ?>
        <?php if (empty($activity)): ?>
        <div class="text-center py-10 text-slate-400"><span class="material-symbols-outlined text-3xl mb-2 block">inbox</span><p class="text-sm font-semibold">No activity yet</p></div>
        <?php endif; ?>
        <?php foreach ($activity as $row): $item = $row['item']; $isTxn = $row['kind']==='txn'; ?>
        <a href="<?= $isTxn ? '/transactions/'.$item->id : '/acceptance/'.$item->id ?>" class="flex items-center gap-4 px-6 py-3 hover:bg-slate-50/80 transition-colors">
          <div class="w-8 h-8 <?= $isTxn ? 'bg-blue-100' : 'bg-violet-100' ?> rounded-full flex items-center justify-center flex-none">
            <span class="material-symbols-outlined <?= $isTxn ? 'text-blue-600' : 'text-violet-600' ?> text-sm"><?= $isTxn ? 'payments' : 'verified' ?></span>
          </div>
          <div class="min-w-0 flex-1">
            <p class="text-sm font-semibold text-slate-900 truncate"><?= htmlspecialchars($item->customer_name) ?></p>
            <p class="text-[11px] text-slate-500"><?= $isTxn ? 'Txn #'.$item->id.' · '.dbTypeLabel($item->type) : 'Acc #'.$item->id.' · '.dbTypeLabel($item->type) ?> · <?= $item->agent?->name ?? '—' ?></p>
          </div>
          <div class="text-right flex-none">
            <p class="text-sm font-bold text-slate-800"><?= $item->currency ?> <?= number_format($item->total_amount, 2) ?></p>
            <p class="text-[10px] text-slate-400"><?= date('g:i A', $row['ts']) ?></p>
          </div>
          <div><?= dbStatusBadge($item->status) ?></div>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- Row 3: Weekly Financials + All-Time + Agent Leaderboard -->
  <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">

    <!-- Weekly Financials -->
    <div class="lg:col-span-2 bg-white rounded-3xl shadow-sm border border-slate-200 overflow-hidden">
      <div class="px-6 py-4 border-b border-slate-100 bg-slate-50/60 flex items-center justify-between">
        <div class="flex items-center gap-2"><span class="material-symbols-outlined text-emerald-600 text-xl">account_balance_wallet</span><h2 class="font-headline font-extrabold text-slate-900">Financial Summary</h2></div>
        <span class="text-[10px] text-slate-400 font-medium"><?= htmlspecialchars($dd['week_label'] ?? '') ?></span>
      </div>
      <div class="p-6">
        <p class="text-[10px] font-bold uppercase text-slate-400 tracking-wider mb-3">This Week</p>
        <div class="grid grid-cols-3 gap-4 text-center mb-4">
          <div class="bg-blue-50 rounded-2xl p-4">
            <p class="text-[10px] font-bold uppercase text-blue-700 tracking-wider mb-1">Charged</p>
            <p class="text-2xl font-headline font-extrabold text-blue-900"><?= number_format($dd['week_revenue'], 0) ?></p>
            <p class="text-[10px] text-blue-600 mt-0.5"><?= htmlspecialchars($currency) ?></p>
          </div>
          <div class="bg-rose-50 rounded-2xl p-4">
            <p class="text-[10px] font-bold uppercase text-rose-700 tracking-wider mb-1">Cost</p>
            <p class="text-2xl font-headline font-extrabold text-rose-900"><?= number_format($dd['week_cost'], 0) ?></p>
            <p class="text-[10px] text-rose-600 mt-0.5"><?= htmlspecialchars($currency) ?></p>
          </div>
          <div class="<?= $dd['week_profit'] >= 0 ? 'bg-emerald-50' : 'bg-rose-50' ?> rounded-2xl p-4">
            <p class="text-[10px] font-bold uppercase <?= $dd['week_profit'] >= 0 ? 'text-emerald-700' : 'text-rose-700' ?> tracking-wider mb-1">Profit</p>
            <p class="text-2xl font-headline font-extrabold <?= $dd['week_profit'] >= 0 ? 'text-emerald-700' : 'text-rose-700' ?>">
              <?= ($dd['week_profit'] >= 0 ? '+' : '-') . number_format(abs($dd['week_profit']), 0) ?>
            </p>
            <p class="text-[10px] <?= $dd['week_profit'] >= 0 ? 'text-emerald-600' : 'text-rose-600' ?> mt-0.5"><?= htmlspecialchars($currency) ?></p>
          </div>
        </div>
        <div class="border-t border-slate-100 pt-4">
          <p class="text-[10px] font-bold uppercase text-slate-400 tracking-wider mb-2">All-Time Totals</p>
          <div class="flex items-center gap-6 text-sm">
            <div><p class="text-[10px] text-slate-400">Transactions</p><p class="font-headline font-extrabold text-primary"><?= number_format($dd['all_txn_count']) ?></p></div>
            <div><p class="text-[10px] text-slate-400">Total Revenue</p><p class="font-headline font-extrabold text-blue-700"><?= htmlspecialchars($currency) ?> <?= number_format($dd['all_revenue'], 0) ?></p></div>
            <div><p class="text-[10px] text-slate-400">Total Profit</p><p class="font-headline font-extrabold <?= $dd['all_profit'] >= 0 ? 'text-emerald-600' : 'text-rose-600' ?>"><?= htmlspecialchars($currency) ?> <?= number_format(abs($dd['all_profit']), 0) ?></p></div>
          </div>
        </div>
        <p class="mt-3 text-xs text-slate-400">
          <?= $dd['week_txn_count'] ?> transactions this week
          <?= $dd['all_txn_count'] > 0 ? '· avg '.htmlspecialchars($currency).' '.number_format($dd['week_txn_count'] > 0 ? $dd['week_revenue']/$dd['week_txn_count'] : 0, 0).' / txn this week' : '' ?>
        </p>
      </div>
    </div>

    <!-- Agent Leaderboard -->
    <div class="bg-white rounded-3xl shadow-sm border border-slate-200 overflow-hidden">
      <div class="px-6 py-4 border-b border-slate-100 bg-slate-50/60 flex items-center gap-2">
        <span class="material-symbols-outlined text-amber-500 text-xl">emoji_events</span>
        <h2 class="font-headline font-extrabold text-slate-900">Top Agents Today</h2>
      </div>
      <div class="p-4 space-y-2">
        <?php if ($dd['leaderboard']->count() === 0): ?>
        <div class="text-center py-8 text-slate-400"><span class="material-symbols-outlined text-3xl mb-2 block">leaderboard</span><p class="text-sm font-semibold">No transactions today yet</p></div>
        <?php else: ?>
        <?php $medals = ['🥇','🥈','🥉','4.','5.']; ?>
        <?php foreach ($dd['leaderboard'] as $rank => $row): ?>
        <div class="flex items-center gap-3 px-3 py-2.5 rounded-xl <?= $rank===0 ? 'bg-amber-50 border border-amber-100' : 'hover:bg-slate-50' ?> transition-colors">
          <span class="text-lg w-7 text-center flex-none"><?= $medals[$rank] ?? ($rank+1).'.' ?></span>
          <div class="flex-1 min-w-0">
            <p class="text-sm font-bold text-slate-900 truncate"><?= htmlspecialchars($row->agent?->name ?? 'Unknown') ?></p>
            <p class="text-[11px] text-slate-500"><?= $row->txn_count ?> txn<?= $row->txn_count != 1 ? 's' : '' ?></p>
          </div>
          <div class="text-right flex-none">
            <p class="text-sm font-bold text-primary"><?= htmlspecialchars($currency) ?> <?= number_format($row->revenue, 0) ?></p>
            <?php if ($row->profit > 0): ?><p class="text-[10px] text-emerald-600 font-semibold">+<?= number_format($row->profit, 0) ?> profit</p><?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <?php endif; /* end admin section */ ?>

  <!-- ═══════════════════════════════════════════════════════════════════════ -->
  <!-- SUPERVISOR SECTION -->
  <!-- ═══════════════════════════════════════════════════════════════════════ -->
  <?php if ($isSupervisor && !empty($sd)): ?>

  <!-- Team KPI Cards -->
  <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-8">
    <a href="/acceptance?status=PENDING" class="kpi-card bg-white border <?= $sd['team_pending_acc'] > 0 ? 'border-rose-300 ring-2 ring-rose-200' : 'border-slate-200' ?> rounded-2xl p-4 shadow-sm">
      <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-2">Team Pending Auth</p>
      <p class="text-3xl font-headline font-extrabold <?= $sd['team_pending_acc'] > 0 ? 'text-rose-600' : 'text-slate-400' ?>"><?= $sd['team_pending_acc'] ?></p>
    </a>
    <a href="/acceptance" class="kpi-card bg-white border border-slate-200 rounded-2xl p-4 shadow-sm">
      <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-2">Team Auth Today</p>
      <p class="text-3xl font-headline font-extrabold text-violet-700"><?= $sd['team_today_new_acc'] ?></p>
    </a>
    <a href="/transactions" class="kpi-card bg-white border border-slate-200 rounded-2xl p-4 shadow-sm">
      <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-2">Team Txns Today</p>
      <p class="text-3xl font-headline font-extrabold text-blue-700"><?= $sd['team_today_count'] ?></p>
      <?php if ($sd['team_pending_rev'] > 0): ?><p class="text-[10px] text-amber-600 font-bold mt-1"><?= $sd['team_pending_rev'] ?> need approval</p><?php endif; ?>
    </a>
    <div class="kpi-card bg-white border border-slate-200 rounded-2xl p-4 shadow-sm">
      <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-2">Team Revenue Today</p>
      <p class="text-xl font-headline font-extrabold text-primary"><?= htmlspecialchars($currency) ?> <?= number_format($sd['team_today_rev'], 0) ?></p>
    </div>
  </div>

  <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">

    <!-- Team Members Status -->
    <div class="bg-white rounded-3xl shadow-sm border border-slate-200 overflow-hidden">
      <div class="px-6 py-4 border-b border-slate-100 bg-slate-50/60 flex items-center gap-2">
        <span class="material-symbols-outlined text-primary text-xl">group</span>
        <h2 class="font-headline font-extrabold text-slate-900">My Team</h2>
      </div>
      <div class="divide-y divide-slate-50">
        <?php if (empty($sd['agent_statuses'])): ?>
        <div class="text-center py-8 text-slate-400 text-sm">No agents assigned to you yet</div>
        <?php endif; ?>
        <?php foreach ($sd['agent_statuses'] as $agent): ?>
        <div class="flex items-center gap-3 px-5 py-3">
          <div class="w-8 h-8 rounded-full bg-primary/15 flex items-center justify-center text-primary font-bold text-xs flex-none">
            <?= strtoupper(substr($agent['name'], 0, 1)) ?>
          </div>
          <div class="flex-1 min-w-0"><p class="text-sm font-semibold text-slate-900 truncate"><?= htmlspecialchars($agent['name']) ?></p></div>
          <div><?= dbAttBadge($agent['state'] ?? 'not_clocked_in', $agent['late_min'] ?? 0) ?></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Pending Approvals (supervisor can approve) -->
    <div class="lg:col-span-2 bg-white rounded-3xl shadow-sm border border-slate-200 overflow-hidden">
      <div class="px-6 py-4 border-b border-slate-100 bg-slate-50/60 flex items-center justify-between">
        <div class="flex items-center gap-2">
          <span class="material-symbols-outlined text-amber-500 text-xl">pending_actions</span>
          <h2 class="font-headline font-extrabold text-slate-900">Pending Approvals</h2>
        </div>
        <a href="/transactions?status=pending_review" class="text-xs font-semibold text-primary hover:underline">View all</a>
      </div>
      <div class="divide-y divide-slate-50">
        <?php if ($sd['pending_approvals']->isEmpty()): ?>
        <div class="text-center py-8 text-slate-400"><span class="material-symbols-outlined text-3xl mb-2 block">task_alt</span><p class="text-sm font-semibold">No pending approvals</p></div>
        <?php endif; ?>
        <?php foreach ($sd['pending_approvals'] as $txn): ?>
        <a href="/transactions/<?= $txn->id ?>" class="flex items-center gap-4 px-6 py-3 hover:bg-amber-50 transition-colors">
          <div class="w-8 h-8 bg-amber-100 rounded-full flex items-center justify-center flex-none"><span class="material-symbols-outlined text-amber-600 text-sm">payments</span></div>
          <div class="min-w-0 flex-1">
            <p class="text-sm font-semibold text-slate-900 truncate"><?= htmlspecialchars($txn->customer_name) ?></p>
            <p class="text-[11px] text-slate-500">Txn #<?= $txn->id ?> · <?= dbTypeLabel($txn->type) ?> · by <?= $txn->agent?->name ?? '—' ?></p>
          </div>
          <div class="text-right flex-none">
            <p class="text-sm font-bold text-slate-800"><?= $txn->currency ?> <?= number_format($txn->total_amount, 2) ?></p>
            <p class="text-[10px] text-slate-400"><?= date('g:i A', strtotime($txn->created_at)) ?></p>
          </div>
          <span class="text-xs font-bold text-amber-700 bg-amber-100 px-2 py-0.5 rounded-full">Review →</span>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- Team Recent Transactions -->
  <div class="bg-white rounded-3xl shadow-sm border border-slate-200 overflow-hidden mb-8">
    <div class="px-6 py-4 border-b border-slate-100 bg-slate-50/60 flex items-center justify-between">
      <div class="flex items-center gap-2"><span class="material-symbols-outlined text-primary text-xl">history</span><h2 class="font-headline font-extrabold text-slate-900">Team Recent Transactions</h2></div>
      <a href="/transactions" class="text-xs font-semibold text-primary hover:underline">All transactions →</a>
    </div>
    <div class="divide-y divide-slate-50">
      <?php if ($sd['team_recent_txns']->isEmpty()): ?>
      <div class="text-center py-8 text-slate-400 text-sm">No transactions recorded yet</div>
      <?php endif; ?>
      <?php foreach ($sd['team_recent_txns'] as $txn): ?>
      <a href="/transactions/<?= $txn->id ?>" class="flex items-center gap-4 px-6 py-3 hover:bg-slate-50/80 transition-colors">
        <div class="w-8 h-8 bg-blue-100 rounded-full flex items-center justify-center flex-none"><span class="material-symbols-outlined text-blue-600 text-sm">payments</span></div>
        <div class="min-w-0 flex-1">
          <p class="text-sm font-semibold text-slate-900 truncate"><?= htmlspecialchars($txn->customer_name) ?></p>
          <p class="text-[11px] text-slate-500">Txn #<?= $txn->id ?> · <?= dbTypeLabel($txn->type) ?> · <?= $txn->agent?->name ?? '—' ?></p>
        </div>
        <div class="text-right flex-none">
          <p class="text-sm font-bold text-slate-800"><?= $txn->currency ?> <?= number_format($txn->total_amount, 2) ?></p>
          <p class="text-[10px] text-slate-400"><?= date('M j, g:i A', strtotime($txn->created_at)) ?></p>
        </div>
        <div><?= dbStatusBadge($txn->status) ?></div>
      </a>
      <?php endforeach; ?>
    </div>
  </div>

  <?php elseif ($isSupervisor): ?>
  <div class="bg-amber-50 border border-amber-200 rounded-2xl p-6 mb-8 flex items-center gap-3">
    <span class="material-symbols-outlined text-amber-600 text-2xl">info</span>
    <div><p class="font-bold text-amber-900">No team members assigned yet</p><p class="text-sm text-amber-700">Ask an admin to assign agents to you via User Management.</p></div>
  </div>
  <?php endif; ?>

  <!-- ═══════════════════════════════════════════════════════════════════════ -->
  <!-- AGENT — Personal performance KPIs -->
  <!-- ═══════════════════════════════════════════════════════════════════════ -->
  <?php if ($isAgent && !empty($ad)): ?>
  <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-8">
    <div class="kpi-card bg-white border border-slate-200 rounded-2xl p-4 shadow-sm">
      <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-2">My Txns Today</p>
      <p class="text-3xl font-headline font-extrabold text-blue-700"><?= $ad['today_txn_count'] ?></p>
    </div>
    <div class="kpi-card bg-white border border-slate-200 rounded-2xl p-4 shadow-sm">
      <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-2">Revenue Today</p>
      <p class="text-xl font-headline font-extrabold text-primary"><?= htmlspecialchars($currency) ?> <?= number_format($ad['today_revenue'], 0) ?></p>
    </div>
    <div class="kpi-card bg-white border border-slate-200 rounded-2xl p-4 shadow-sm">
      <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-2">My Auth Today</p>
      <p class="text-3xl font-headline font-extrabold text-violet-700"><?= $ad['today_acc_count'] ?></p>
    </div>
    <a href="/transactions?status=pending_review&mine=1" class="kpi-card bg-white border <?= $ad['pending_txn_count'] > 0 ? 'border-amber-300 ring-2 ring-amber-200' : 'border-slate-200' ?> rounded-2xl p-4 shadow-sm">
      <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-2">Pending Review</p>
      <p class="text-3xl font-headline font-extrabold <?= $ad['pending_txn_count'] > 0 ? 'text-amber-600' : 'text-slate-400' ?>"><?= $ad['pending_txn_count'] ?></p>
      <p class="text-[10px] <?= $ad['pending_txn_count'] > 0 ? 'text-amber-600 font-bold' : 'text-slate-400' ?> mt-1"><?= $ad['pending_txn_count'] > 0 ? 'Awaiting approval' : 'All approved' ?></p>
    </a>
  </div>

  <!-- My Recent Records -->
  <?php if (!$ad['recent_txns']->isEmpty()): ?>
  <div class="bg-white rounded-3xl shadow-sm border border-slate-200 overflow-hidden mb-8">
    <div class="px-6 py-4 border-b border-slate-100 bg-slate-50/60 flex items-center justify-between">
      <div class="flex items-center gap-2"><span class="material-symbols-outlined text-primary text-xl">history</span><h2 class="font-headline font-extrabold text-slate-900">My Recent Transactions</h2></div>
      <a href="/transactions" class="text-xs font-semibold text-primary hover:underline">View all →</a>
    </div>
    <div class="divide-y divide-slate-50">
      <?php foreach ($ad['recent_txns'] as $txn): ?>
      <a href="/transactions/<?= $txn->id ?>" class="flex items-center gap-4 px-6 py-3 hover:bg-slate-50/80 transition-colors">
        <div class="w-8 h-8 bg-blue-100 rounded-full flex items-center justify-center flex-none"><span class="material-symbols-outlined text-blue-600 text-sm">payments</span></div>
        <div class="min-w-0 flex-1">
          <p class="text-sm font-semibold text-slate-900 truncate"><?= htmlspecialchars($txn->customer_name) ?></p>
          <p class="text-[11px] text-slate-500">Txn #<?= $txn->id ?> · <?= dbTypeLabel($txn->type) ?></p>
        </div>
        <div class="text-right flex-none">
          <p class="text-sm font-bold text-slate-800"><?= $txn->currency ?> <?= number_format($txn->total_amount, 2) ?></p>
          <p class="text-[10px] text-slate-400"><?= date('M j, g:i A', strtotime($txn->created_at)) ?></p>
        </div>
        <div><?= dbStatusBadge($txn->status) ?></div>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>
  <?php endif; /* end agent section */ ?>

  <!-- ═══════════════════════════════════════════════════════════════════════ -->
  <!-- ATTENDANCE & QUICK TOOLS — shown to ALL roles -->
  <!-- ═══════════════════════════════════════════════════════════════════════ -->
  <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">

    <!-- Left: Today + Weekly -->
    <div class="lg:col-span-2 space-y-6">
      <!-- Today's Attendance -->
      <div class="bg-white rounded-3xl p-6 shadow-sm">
        <h2 class="text-lg font-headline font-extrabold text-primary mb-4 flex items-center gap-2">
          <span class="material-symbols-outlined">today</span> My Attendance Today
        </h2>
        <div class="grid grid-cols-4 gap-4">
          <div class="text-center p-3 bg-[#f3f4f5] rounded-xl">
            <p class="text-[10px] font-bold uppercase text-[#434653] tracking-wider">Clock In</p>
            <p class="text-lg font-headline font-extrabold text-primary mt-1"><?= $todayStats['clock_in'] ? date('g:i A', strtotime($todayStats['clock_in'])) : '—' ?></p>
          </div>
          <div class="text-center p-3 bg-[#f3f4f5] rounded-xl">
            <p class="text-[10px] font-bold uppercase text-[#434653] tracking-wider">Work Time</p>
            <p class="text-lg font-headline font-extrabold text-green-700 mt-1"><?= floor($todayStats['work_mins']/60) ?>h <?= $todayStats['work_mins']%60 ?>m</p>
          </div>
          <div class="text-center p-3 bg-[#f3f4f5] rounded-xl">
            <p class="text-[10px] font-bold uppercase text-[#434653] tracking-wider">Break</p>
            <p class="text-lg font-headline font-extrabold text-amber-700 mt-1"><?= $todayStats['break_mins'] ?>m</p>
          </div>
          <div class="text-center p-3 bg-[#f3f4f5] rounded-xl">
            <p class="text-[10px] font-bold uppercase text-[#434653] tracking-wider">Late</p>
            <p class="text-lg font-headline font-extrabold <?= $todayStats['late_mins'] > 0 ? 'text-red-600' : 'text-green-600' ?> mt-1">
              <?= $todayStats['late_mins'] > 0 ? $todayStats['late_mins'].'m' : 'On Time' ?>
            </p>
          </div>
        </div>
        <?php if ($todayShift): ?>
        <div class="mt-4 px-4 py-2 bg-blue-50 text-blue-700 rounded-lg text-xs font-semibold inline-flex items-center gap-2">
          <span class="material-symbols-outlined text-sm">schedule</span>
          Shift: <?= date('g:i A', strtotime($todayShift->shift_start)) ?> – <?= date('g:i A', strtotime($todayShift->shift_end)) ?>
          <?php if ($todayShift->template): ?>(<?= htmlspecialchars($todayShift->template->name) ?>)<?php endif; ?>
        </div>
        <?php endif; ?>
      </div>

      <!-- Weekly Summary -->
      <div class="bg-white rounded-3xl p-6 shadow-sm">
        <h2 class="text-lg font-headline font-extrabold text-primary mb-4 flex items-center gap-2">
          <span class="material-symbols-outlined">date_range</span> This Week
        </h2>
        <div class="grid grid-cols-7 gap-2">
          <?php foreach ($weekData as $day): ?>
          <div class="text-center p-3 rounded-xl <?= $day['is_today'] ? 'bg-primary text-white' : ($day['has_data'] ? 'bg-green-50' : 'bg-gray-50') ?>">
            <p class="text-[10px] font-bold uppercase mb-1 <?= $day['is_today'] ? 'text-white/70' : 'text-[#434653]' ?>"><?= $day['day'] ?></p>
            <?php if ($day['has_data']): ?>
            <p class="text-sm font-headline font-extrabold <?= $day['is_today'] ? 'text-white' : 'text-green-700' ?>"><?= floor($day['work_mins']/60) ?>h<?= $day['work_mins']%60>0 ? $day['work_mins']%60 .'m':'' ?></p>
            <?php if ($day['late_mins'] > 0): ?><p class="text-[9px] font-bold <?= $day['is_today'] ? 'text-red-200' : 'text-red-500' ?>"><?= $day['late_mins'] ?>m late</p><?php endif; ?>
            <?php else: ?>
            <p class="text-sm font-semibold <?= $day['is_today'] ? 'text-white/50' : 'text-gray-400' ?>">—</p>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
        <?php
          $twWork  = array_sum(array_column($weekData,'work_mins'));
          $twBreak = array_sum(array_column($weekData,'break_mins'));
          $twLate  = array_sum(array_column($weekData,'late_mins'));
          $twDays  = count(array_filter($weekData, fn($d)=>$d['has_data']));
        ?>
        <div class="mt-4 flex items-center gap-6 text-xs font-semibold text-[#434653]">
          <span>Total: <strong class="text-primary"><?= floor($twWork/60) ?>h <?= $twWork%60 ?>m</strong></span>
          <span>Breaks: <strong class="text-amber-700"><?= $twBreak ?>m</strong></span>
          <span>Days: <strong class="text-green-700"><?= $twDays ?>/<?= count($weekData) ?></strong></span>
          <?php if ($twLate > 0): ?><span>Late: <strong class="text-red-600"><?= $twLate ?>m</strong></span><?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Right: Status + Quick Tools -->
    <div class="space-y-6">
      <div class="bg-gradient-to-br from-primary to-[#314a8d] rounded-3xl p-6 text-white shadow-xl shadow-primary/20">
        <p class="text-xs font-bold uppercase tracking-widest opacity-70 mb-1">Current Status</p>
        <?php
          $sLabel = match($stateInfo['state'] ?? 'not_clocked_in') {
            'clocked_in'  => 'Active on Shift', 'on_break' => 'On Break',
            'clocked_out' => 'Shift Completed', default => 'Not Clocked In',
          };
          $sIcon = match($stateInfo['state'] ?? 'not_clocked_in') {
            'clocked_in'  => 'play_circle', 'on_break' => 'pause_circle',
            'clocked_out' => 'check_circle', default => 'circle',
          };
        ?>
        <p class="text-2xl font-headline font-extrabold mb-2 flex items-center gap-2">
          <span class="material-symbols-outlined text-2xl"><?= $sIcon ?></span><?= $sLabel ?>
        </p>
        <p class="text-sm opacity-80 font-medium">Manage breaks via the sidebar widget →</p>
      </div>

      <div class="bg-white rounded-3xl p-6 shadow-sm">
        <h2 class="text-lg font-headline font-extrabold text-primary mb-4">Quick Tools</h2>
        <div class="space-y-2">
          <a href="/attendance/my" class="flex items-center gap-4 p-3 rounded-xl bg-[#f3f4f5] hover:bg-primary hover:text-white transition-all group">
            <span class="material-symbols-outlined text-primary group-hover:text-white">history</span><span class="font-bold text-sm">My Attendance</span>
          </a>
          <a href="/transactions" class="flex items-center gap-4 p-3 rounded-xl bg-[#f3f4f5] hover:bg-primary hover:text-white transition-all group">
            <span class="material-symbols-outlined text-primary group-hover:text-white">payments</span><span class="font-bold text-sm">My Transactions</span>
          </a>
          <a href="/acceptance" class="flex items-center gap-4 p-3 rounded-xl bg-[#f3f4f5] hover:bg-primary hover:text-white transition-all group">
            <span class="material-symbols-outlined text-primary group-hover:text-white">verified</span><span class="font-bold text-sm">Acceptance</span>
          </a>
          <?php if ($isAdmin || $isSupervisor): ?>
          <a href="/acceptance/create" class="flex items-center gap-4 p-3 rounded-xl bg-violet-50 hover:bg-primary hover:text-white transition-all group">
            <span class="material-symbols-outlined text-violet-600 group-hover:text-white">add_circle</span><span class="font-bold text-sm">New Acceptance</span>
          </a>
          <a href="/transactions/create" class="flex items-center gap-4 p-3 rounded-xl bg-violet-50 hover:bg-primary hover:text-white transition-all group">
            <span class="material-symbols-outlined text-violet-600 group-hover:text-white">add_task</span><span class="font-bold text-sm">Record Transaction</span>
          </a>
          <a href="/attendance/admin" class="flex items-center gap-4 p-3 rounded-xl bg-blue-50 hover:bg-primary hover:text-white transition-all group">
            <span class="material-symbols-outlined text-primary group-hover:text-white">how_to_reg</span><span class="font-bold text-sm">Attendance Board</span>
          </a>
          <?php endif; ?>
          <?php if ($isAdmin): ?>
          <a href="/shifts/week" class="flex items-center gap-4 p-3 rounded-xl bg-blue-50 hover:bg-primary hover:text-white transition-all group">
            <span class="material-symbols-outlined text-primary group-hover:text-white">calendar_month</span><span class="font-bold text-sm">Shift Schedule</span>
          </a>
          <a href="/attendance/admin/history" class="flex items-center gap-4 p-3 rounded-xl bg-blue-50 hover:bg-primary hover:text-white transition-all group">
            <span class="material-symbols-outlined text-primary group-hover:text-white">analytics</span><span class="font-bold text-sm">Attendance History</span>
          </a>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

</main>
</body>
</html>
