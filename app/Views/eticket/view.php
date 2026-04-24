<?php
/**
 * E-Ticket — Agent Detail View
 */

use App\Models\ETicket;

$userRole = $_SESSION['role'] ?? 'agent';
$isAdmin  = in_array($userRole, ['admin', 'manager', 'supervisor']);
$activePage = 'etickets';
$et  = $eticket;
$etId = 'ET-' . str_pad($et->id, 6, '0', STR_PAD_LEFT);
$pax = $et->ticketDataWithAutoNumbers();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

[$statusLabel, $statusClass] = match($et->status) {
    ETicket::STATUS_ACKNOWLEDGED => ['✓ Acknowledged', 'bg-emerald-100 text-emerald-700'],
    ETicket::STATUS_SENT         => ['✉ Sent',         'bg-blue-100 text-blue-700'],
    default                      => ['● Draft',         'bg-slate-100 text-slate-500'],
};
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<title><?= $etId ?> — Base Fare CRM</title>
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
    <div class="flex items-center gap-4">
      <a href="/etickets" class="inline-flex items-center gap-1 px-3 py-1.5 border border-slate-200 rounded-lg text-sm text-slate-500 hover:bg-slate-50 transition-colors">← Back</a>
      <div>
        <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">E-Ticket</p>
        <h1 class="text-2xl font-headline font-extrabold text-primary font-mono"><?= $etId ?></h1>
      </div>
    </div>
    <span class="inline-flex items-center gap-2 px-4 py-2 rounded-full text-sm font-extrabold <?= $statusClass ?>">
      <?= $statusLabel ?>
    </span>
  </div>

  <!-- Alert Banners -->
  <?php if ($created): ?>
  <div class="mb-4 px-4 py-3 bg-emerald-50 border border-emerald-200 rounded-xl text-sm font-semibold text-emerald-700 flex items-center gap-2">
    <span class="material-symbols-outlined text-base">check_circle</span> E-Ticket created successfully.
  </div>
  <?php endif; ?>
  <?php if ($sent): ?>
  <div class="mb-4 px-4 py-3 bg-blue-50 border border-blue-200 rounded-xl text-sm font-semibold text-blue-700 flex items-center gap-2">
    <span class="material-symbols-outlined text-base">mail</span> E-Ticket emailed successfully.
  </div>
  <?php endif; ?>
  <?php if (isset($_GET['send_error'])): ?>
  <div class="mb-4 px-4 py-3 bg-red-50 border border-red-200 rounded-xl text-sm font-semibold text-red-700 flex items-center gap-2">
    <span class="material-symbols-outlined text-base">error</span> Email failed to send. Check SMTP settings.
  </div>
  <?php endif; ?>

  <div class="grid grid-cols-3 gap-6">

    <!-- LEFT (2/3) -->
    <div class="col-span-2 space-y-5">

      <!-- Customer & Booking -->
      <div class="bg-white border border-slate-200 rounded-xl p-5">
        <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-4">Customer &amp; Booking</p>
        <div class="grid grid-cols-3 gap-4">
          <div>
            <p class="text-[10px] text-slate-400 uppercase font-bold tracking-wider">Customer</p>
            <p class="text-sm font-bold text-slate-800 mt-0.5"><?= htmlspecialchars($et->customer_name) ?></p>
          </div>
          <div>
            <p class="text-[10px] text-slate-400 uppercase font-bold tracking-wider">Email</p>
            <p class="text-xs font-semibold text-slate-700 mt-0.5"><?= htmlspecialchars($et->customer_email) ?></p>
          </div>
          <div>
            <p class="text-[10px] text-slate-400 uppercase font-bold tracking-wider">Phone</p>
            <p class="text-sm font-semibold text-slate-700 mt-0.5"><?= htmlspecialchars($et->customer_phone ?: '—') ?></p>
          </div>
          <div>
            <p class="text-[10px] text-slate-400 uppercase font-bold tracking-wider">PNR</p>
            <p class="text-2xl font-black text-primary font-mono tracking-widest mt-0.5"><?= htmlspecialchars($et->pnr) ?></p>
          </div>
          <div>
            <p class="text-[10px] text-slate-400 uppercase font-bold tracking-wider">Airline</p>
            <p class="text-sm font-semibold text-slate-700 mt-0.5"><?= htmlspecialchars($et->airline ?: '—') ?></p>
          </div>
          <div>
            <p class="text-[10px] text-slate-400 uppercase font-bold tracking-wider">Amount</p>
            <p class="text-xl font-extrabold text-emerald-600 mt-0.5"><?= htmlspecialchars($et->currency) ?> <?= number_format($et->total_amount, 2) ?></p>
          </div>
          <div>
            <p class="text-[10px] text-slate-400 uppercase font-bold tracking-wider">Transaction</p>
            <a href="/transactions/<?= $et->transaction_id ?>" class="text-sm font-bold text-primary hover:underline">TXN-<?= $et->transaction_id ?> ↗</a>
          </div>
          <?php if ($et->acceptance_id): ?>
          <div>
            <p class="text-[10px] text-slate-400 uppercase font-bold tracking-wider">Acceptance</p>
            <a href="/acceptance/<?= $et->acceptance_id ?>" class="text-sm font-bold text-primary hover:underline">ACC-<?= $et->acceptance_id ?> ↗</a>
          </div>
          <?php endif; ?>
          <?php if ($et->order_id): ?>
          <div>
            <p class="text-[10px] text-slate-400 uppercase font-bold tracking-wider">Order #</p>
            <p class="text-sm font-semibold text-slate-700 mt-0.5"><?= htmlspecialchars($et->order_id) ?></p>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Passengers -->
      <div class="bg-white border border-slate-200 rounded-xl overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-100">
          <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Passengers &amp; E-Ticket Numbers</p>
        </div>
        <table class="w-full text-sm">
          <thead>
            <tr class="bg-slate-50/80 border-b border-slate-100">
              <th class="px-4 py-2.5 text-left text-[10px] font-bold text-slate-400 uppercase tracking-wider">#</th>
              <th class="px-4 py-2.5 text-left text-[10px] font-bold text-slate-400 uppercase tracking-wider">Passenger</th>
              <th class="px-4 py-2.5 text-left text-[10px] font-bold text-slate-400 uppercase tracking-wider">Type</th>
              <th class="px-4 py-2.5 text-left text-[10px] font-bold text-slate-400 uppercase tracking-wider">E-Ticket #</th>
              <th class="px-4 py-2.5 text-left text-[10px] font-bold text-slate-400 uppercase tracking-wider">Seat</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <?php foreach ($pax as $i => $p): ?>
            <tr>
              <td class="px-4 py-3 text-xs text-slate-400"><?= $i+1 ?></td>
              <td class="px-4 py-3 text-sm font-bold text-slate-800"><?= htmlspecialchars($p['pax_name'] ?? '') ?></td>
              <td class="px-4 py-3 text-xs text-slate-500 capitalize"><?= htmlspecialchars($p['pax_type'] ?? 'adult') ?></td>
              <td class="px-4 py-3 font-mono font-bold text-sm text-blue-700"><?= htmlspecialchars($p['ticket_number'] ?? '—') ?></td>
              <td class="px-4 py-3">
                <?php if (!empty($p['seat'])): ?>
                <span class="inline-block px-2 py-0.5 bg-violet-100 text-violet-700 text-xs font-bold rounded"><?= htmlspecialchars($p['seat']) ?></span>
                <?php else: ?>—<?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <!-- Flight Itinerary -->
      <?php if ($et->flight_data && count((array)$et->flight_data) > 0): ?>
      <div class="bg-white border border-slate-200 rounded-xl p-5">
        <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-3">Flight Itinerary</p>
        <div class="divide-y divide-slate-100">
          <?php foreach ((array)$et->flight_data as $f): ?>
          <div class="flex items-center gap-6 py-4">
            <div class="text-center min-w-[64px]">
              <div class="text-2xl font-black text-primary font-mono"><?= htmlspecialchars($f['departure_airport'] ?? $f['from'] ?? '???') ?></div>
              <div class="text-[10px] text-slate-400"><?= htmlspecialchars($f['departure_date'] ?? $f['date'] ?? '') ?></div>
              <div class="text-xs font-bold text-slate-500"><?= htmlspecialchars($f['departure_time'] ?? $f['time'] ?? '') ?></div>
            </div>
            <div class="flex-1 text-center">
              <div class="text-[10px] text-slate-400 mb-1">
                <?= htmlspecialchars($f['flight_number'] ?? $f['flight'] ?? '') ?>
                <?php if (!empty($f['cabin_class'] ?? $f['class'] ?? '')): ?>&nbsp;·&nbsp;<?= htmlspecialchars($f['cabin_class'] ?? $f['class']) ?><?php endif; ?>
              </div>
              <div class="flex items-center gap-1">
                <div class="flex-1 h-px bg-slate-200"></div>
                <span class="material-symbols-outlined text-base text-slate-300">flight</span>
                <div class="flex-1 h-px bg-slate-200"></div>
              </div>
              <?php if (!empty($f['duration'])): ?><div class="text-[10px] text-slate-400 mt-1"><?= htmlspecialchars($f['duration']) ?></div><?php endif; ?>
            </div>
            <div class="text-center min-w-[64px]">
              <div class="text-2xl font-black text-primary font-mono"><?= htmlspecialchars($f['arrival_airport'] ?? $f['to'] ?? '???') ?></div>
              <div class="text-[10px] text-slate-400"><?= htmlspecialchars($f['arrival_date'] ?? '') ?></div>
              <div class="text-xs font-bold text-slate-500"><?= htmlspecialchars($f['arrival_time'] ?? '') ?></div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- Conditions -->
      <?php if ($et->endorsements || $et->baggage_info || $et->fare_rules): ?>
      <div class="bg-white border border-slate-200 rounded-xl p-5">
        <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-3">Ticket Conditions</p>
        <?php if ($et->endorsements): ?>
        <div class="mb-3"><p class="text-[10px] font-bold text-slate-400 uppercase mb-1">Endorsements</p><p class="text-xs font-mono text-slate-700"><?= nl2br(htmlspecialchars($et->endorsements)) ?></p></div>
        <?php endif; ?>
        <?php if ($et->baggage_info): ?>
        <div class="mb-3"><p class="text-[10px] font-bold text-slate-400 uppercase mb-1">Baggage</p><p class="text-xs text-slate-700"><?= nl2br(htmlspecialchars($et->baggage_info)) ?></p></div>
        <?php endif; ?>
        <?php if ($et->fare_rules): ?>
        <div><p class="text-[10px] font-bold text-slate-400 uppercase mb-1">Fare Rules</p><p class="text-xs text-slate-700"><?= nl2br(htmlspecialchars($et->fare_rules)) ?></p></div>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <!-- Notes -->
      <div class="bg-white border border-slate-200 rounded-xl p-5">
        <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-3">Activity Log</p>
        <?php if ($notes->isEmpty()): ?>
        <p class="text-sm text-slate-400">No notes yet.</p>
        <?php else: ?>
        <div class="space-y-2 mb-4">
          <?php foreach ($notes as $note): ?>
          <div class="bg-slate-50 border border-slate-100 rounded-lg p-3">
            <div class="flex justify-between mb-1">
              <span class="text-xs font-bold text-slate-600"><?= htmlspecialchars($note->user?->name ?? ($note->user_id == 0 ? 'Customer' : 'System')) ?></span>
              <span class="text-[10px] text-slate-400"><?= $note->created_at->format('M j, Y g:i A') ?></span>
            </div>
            <p class="text-xs text-slate-700"><?= nl2br(htmlspecialchars($note->note ?? '')) ?></p>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <form method="POST" action="/etickets/<?= $et->id ?>/note" class="flex gap-2">
          <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
          <input type="text" name="note" placeholder="Add internal note…" required
                 class="flex-1 border border-slate-200 rounded-lg px-3 py-2 text-sm bg-slate-50 focus:ring-2 focus:ring-primary/40">
          <button type="submit" class="px-4 py-2 bg-primary text-white text-sm font-bold rounded-lg hover:bg-primary-container transition-colors">Add</button>
        </form>
      </div>

    </div><!-- /left col -->

    <!-- RIGHT (1/3) -->
    <div class="space-y-4">

      <!-- Send E-Ticket -->
      <div class="bg-white border border-slate-200 rounded-xl p-5">
        <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-3">Send E-Ticket</p>

        <!-- Send to customer email -->
        <form method="POST" action="/etickets/<?= $et->id ?>/send" class="mb-4">
          <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
          <button type="submit"
                  class="w-full inline-flex items-center justify-center gap-2 px-4 py-3 bg-primary text-white font-bold rounded-xl hover:bg-primary-container transition-all text-sm shadow-lg shadow-primary/20">
            <span class="material-symbols-outlined text-base">send</span>
            <?= $et->isSent() ? 'Resend E-Ticket' : 'Send E-Ticket' ?>
          </button>
          <?php if ($et->last_emailed_at): ?>
          <p class="text-[10px] text-slate-400 text-center mt-1.5">
            Last sent <?= $et->last_emailed_at->format('M j, g:i A') ?>
            <?php if ($et->sent_to_email): ?>to <?= htmlspecialchars($et->sent_to_email) ?><?php endif; ?>
          </p>
          <?php endif; ?>
        </form>

        <!-- Resend to alternate email -->
        <div class="border-t border-slate-100 pt-4">
          <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-2">Resend to Different Email</p>
          <form method="POST" action="/etickets/<?= $et->id ?>/send" class="space-y-2">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="email" name="resend_email" placeholder="Alternate email address" required
                   class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-slate-50 focus:ring-2 focus:ring-primary/40">
            <button type="submit"
                    class="w-full inline-flex items-center justify-center gap-2 px-4 py-2.5 border border-slate-200 bg-slate-50 text-slate-600 font-bold rounded-xl hover:bg-slate-100 transition-colors text-sm">
              <span class="material-symbols-outlined text-base">forward_to_inbox</span> Send to This Address
            </button>
          </form>
        </div>
      </div>

      <!-- Acknowledgment -->
      <div class="bg-white border border-slate-200 rounded-xl p-5">
        <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-3">Acknowledgment</p>
        <?php if ($et->isAcknowledged()): ?>
        <div class="text-center bg-emerald-50 border border-emerald-100 rounded-xl p-4 mb-3">
          <span class="material-symbols-outlined text-3xl text-emerald-500 block mb-1">verified</span>
          <p class="text-sm font-extrabold text-emerald-700">Acknowledged</p>
          <p class="text-[10px] text-emerald-600 mt-0.5"><?= $et->acknowledged_at->format('F j, Y \a\t g:i A') ?></p>
        </div>
        <p class="text-[10px] text-slate-400">IP: <?= htmlspecialchars($et->acknowledged_ip ?? 'N/A') ?></p>
        <?php else: ?>
        <div class="text-center bg-slate-50 border border-slate-100 rounded-xl p-4">
          <p class="text-sm text-slate-400">Awaiting customer acknowledgment</p>
        </div>
        <?php endif; ?>
        <div class="mt-3 pt-3 border-t border-slate-100">
          <p class="text-[10px] font-bold text-slate-400 uppercase mb-1">Public Link</p>
          <a href="<?= htmlspecialchars($et->publicUrl()) ?>" target="_blank"
             class="text-[10px] text-primary break-all hover:underline"><?= htmlspecialchars($et->publicUrl()) ?></a>
        </div>
      </div>

      <!-- Email Status -->
      <div class="bg-white border border-slate-200 rounded-xl p-5">
        <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-3">Email Status</p>
        <?php
        $emailIcon  = match($et->email_status) { 'SENT','RESENT' => 'check_circle', 'FAILED' => 'error', default => 'schedule' };
        $emailColor = match($et->email_status) { 'SENT','RESENT' => 'text-emerald-600', 'FAILED' => 'text-red-600', default => 'text-slate-400' };
        ?>
        <div class="flex items-center gap-2 mb-2">
          <span class="material-symbols-outlined text-xl <?= $emailColor ?>"><?= $emailIcon ?></span>
          <span class="text-sm font-bold <?= $emailColor ?>"><?= htmlspecialchars($et->email_status) ?></span>
        </div>
        <p class="text-[10px] text-slate-400">Attempts: <strong class="text-slate-600"><?= $et->email_attempts ?></strong></p>
        <?php if ($et->sent_to_email): ?>
        <p class="text-[10px] text-slate-400 mt-0.5">Last to: <strong class="text-slate-600"><?= htmlspecialchars($et->sent_to_email) ?></strong></p>
        <?php endif; ?>
      </div>

      <!-- Meta -->
      <div class="bg-white border border-slate-200 rounded-xl p-5">
        <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-3">Record Info</p>
        <div class="space-y-1.5 text-xs text-slate-500">
          <div>Agent: <strong class="text-slate-700"><?= htmlspecialchars($et->agent?->name ?? '—') ?></strong></div>
          <div>Created: <strong class="text-slate-700"><?= $et->created_at->format('M j, Y g:i A') ?></strong></div>
          <div>Updated: <strong class="text-slate-700"><?= $et->updated_at->format('M j, Y g:i A') ?></strong></div>
        </div>
      </div>

    </div><!-- /right col -->
  </div><!-- /grid -->

</main>
</body>
</html>
