<?php
/**
 * Simple view — Reports: plain-English directory of the CRM's reports and
 * record lists (all existing full-CRM pages).
 * @var string $month  YYYY-MM
 */
$pageTitle = 'Reports';
require __DIR__ . '/_head.php';

$reports = [
    ['Attendance this month', 'Who came in, when, late days and breaks — one row per person.', 'event_available',
        '/attendance/admin/monthly?month=' . $month, '/attendance/admin/monthly/export?month=' . $month],
    ['Performance by person', 'Bookings, sales and profit for each person, any period you choose.', 'leaderboard',
        '/performance?period=monthly&month=' . $month, null],
    ['Centres compared', 'DMR, MOH and JSR side by side, including marketing spend and return.', 'insights',
        '/analytics', null],
    ['Chargebacks & refunds', 'Money customers took back or that we returned, by centre.', 'credit_card_off',
        '/chargebacks', null],
    ['Attendance history', 'Clock-in and clock-out times for any past day.', 'history',
        '/attendance/admin/history', null],
    ['Call logs', 'Calls the agents have logged, and who hasn\'t logged any.', 'phone_in_talk',
        '/call-logs/team', null],
];
$records = [
    ['Bookings', 'Every booking recorded by the team.', 'confirmation_number', '/transactions'],
    ['Customer authorizations', 'Payment authorizations sent to customers to sign.', 'verified', '/acceptance'],
    ['E-tickets', 'Tickets emailed to customers.', 'airplane_ticket', '/etickets'],
    ['Customer emails', 'Email conversations with customers.', 'mail', '/emails'],
    ['Invoices', 'Invoices sent to customers.', 'request_quote', '/invoices'],
    ['Travel vouchers', 'Future travel vouchers issued to customers.', 'local_activity', '/vouchers'],
    ['Activity log', 'A record of everything anyone did in the CRM, including you.', 'history_edu', '/admin/activity-log'],
];
?>

<header class="mb-8">
  <h1 class="font-headline text-[30px] font-extrabold tracking-tight">Reports</h1>
  <p class="text-[15px] text-slate-500 mt-1">Everything you might want to look up. These open the detailed CRM pages.</p>
</header>

<h2 class="font-headline text-[19px] font-bold mb-4">Reports</h2>
<div class="grid grid-cols-3 gap-4 mb-10">
  <?php foreach ($reports as [$title, $desc, $icon, $href, $csv]): ?>
  <div class="card p-5 flex flex-col">
    <a href="<?= sv_e($href) ?>" class="flex items-start gap-3 no-underline group flex-1">
      <span class="w-11 h-11 rounded-xl bg-[#e8edf9] text-primary flex items-center justify-center flex-none"><span class="material-symbols-outlined"><?= $icon ?></span></span>
      <span>
        <span class="block text-[16px] font-bold text-ink group-hover:text-primary"><?= sv_e($title) ?></span>
        <span class="block text-[14px] text-slate-500 mt-0.5 leading-snug"><?= sv_e($desc) ?></span>
      </span>
    </a>
    <?php if ($csv): ?>
    <a href="<?= sv_e($csv) ?>" class="mt-4 inline-flex items-center gap-1.5 text-[14px] font-semibold text-primary hover:underline">
      <span class="material-symbols-outlined text-[18px]">download</span> Download as spreadsheet
    </a>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>

<h2 class="font-headline text-[19px] font-bold mb-4">Records</h2>
<div class="card divide-y divide-slate-100">
  <?php foreach ($records as [$title, $desc, $icon, $href]): ?>
  <a href="<?= sv_e($href) ?>" class="flex items-center gap-4 px-5 py-4 no-underline group">
    <span class="material-symbols-outlined text-slate-400 group-hover:text-primary"><?= $icon ?></span>
    <span class="flex-1">
      <span class="block text-[15px] font-semibold text-ink group-hover:text-primary"><?= sv_e($title) ?></span>
      <span class="block text-[13px] text-slate-500"><?= sv_e($desc) ?></span>
    </span>
    <span class="material-symbols-outlined text-slate-300 group-hover:text-primary">chevron_right</span>
  </a>
  <?php endforeach; ?>
</div>

<?php require __DIR__ . '/_foot.php'; ?>
