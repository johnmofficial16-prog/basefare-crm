<?php
/**
 * Transaction Recorder — Detail / View Page
 * Role-aware display: agents see masked cards, admins get click-to-reveal.
 *
 * @var Transaction $txn  Fully loaded transaction with relationships
 * @var bool $isAdmin     Whether current user is admin/manager
 */

use App\Models\Transaction;

$txn         = $txn ?? null;
$isAdmin     = $isAdmin ?? false;
$flashSuccess = $flashSuccess ?? null;
$flashError   = $flashError ?? null;
[$statusLabel, $statusClass] = $txn->statusBadge();
[$payLabel, $payClass]       = $txn->paymentBadge();
$activePage = 'transactions';
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<title>Transaction #<?= $txn->id ?> - Base Fare CRM</title>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
<script src="https://cdn.tailwindcss.com?plugins=forms"></script>
<script>
tailwind.config={darkMode:"class",theme:{extend:{colors:{primary:"#163274","primary-container":"#314a8d",background:"#f8f9fa",surface:"#f8f9fa","surface-container":"#edeeef",
"on-surface":"#191c1d","on-surface-variant":"#434653"},fontFamily:{headline:["Manrope"],body:["Inter"]}}}}
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
      <div class="flex items-center gap-3">
        <h1 class="text-2xl font-headline font-extrabold text-primary tracking-tight">
          Transaction #<?= $txn->id ?>
        </h1>
        <span class="inline-block px-3 py-1 text-xs font-bold rounded-full <?= $statusClass ?>"><?= $statusLabel ?></span>
        <span class="inline-block px-2 py-0.5 text-xs font-bold rounded-full bg-slate-100 text-slate-700"><?= $txn->typeLabel() ?></span>
      </div>
      <p class="text-sm text-on-surface-variant mt-0.5">
        Recorded by <?= htmlspecialchars($txn->agent->name ?? '—') ?> on <?= date('M d, Y g:i A', strtotime($txn->created_at)) ?>
      </p>
    </div>
    <div class="flex items-center gap-2">
      <?php if ($txn->isEditable($isAdmin)): ?>
      <a href="/transactions/<?= $txn->id ?>/edit" class="inline-flex items-center gap-1 px-4 py-2 text-sm font-semibold text-primary border border-primary rounded-lg hover:bg-primary/5 transition-colors">
        <span class="material-symbols-outlined text-sm">edit</span> Edit
      </a>
      <?php endif; ?>
      <a href="/transactions" class="inline-flex items-center gap-1 text-sm font-semibold text-on-surface-variant hover:text-primary transition-colors">
        <span class="material-symbols-outlined text-sm">arrow_back</span> Back
      </a>
    </div>
  </div>

  <!-- Flash Messages -->
  <?php if ($flashSuccess): ?>
  <div class="mb-4 px-4 py-3 bg-emerald-50 border border-emerald-200 rounded-xl text-sm font-semibold text-emerald-700 flex items-center gap-2">
    <span class="material-symbols-outlined text-base">check_circle</span><?= htmlspecialchars($flashSuccess) ?>
  </div>
  <?php endif; ?>
  <?php if ($flashError): ?>
  <div class="mb-4 px-4 py-3 bg-red-50 border border-red-200 rounded-xl text-sm font-semibold text-red-700 flex items-center gap-2">
    <span class="material-symbols-outlined text-base">error</span><?= htmlspecialchars($flashError) ?>
  </div>
  <?php endif; ?>

  <!-- Void Warning Banner -->
  <?php if ($txn->isVoided()): ?>
  <div class="mb-4 px-4 py-3 bg-red-50 border border-red-300 rounded-xl text-sm text-red-800">
    <p class="font-bold flex items-center gap-1"><span class="material-symbols-outlined text-base">block</span> VOIDED</p>
    <p class="mt-1"><?= htmlspecialchars($txn->void_reason) ?></p>
    <p class="text-xs mt-1 text-red-600">
      Voided by <?= htmlspecialchars($txn->voidedByUser->name ?? '—') ?> on <?= date('M d, Y g:i A', strtotime($txn->voided_at)) ?>
    </p>
  </div>
  <?php endif; ?>

  <!-- Linked Acceptance -->
  <?php if ($txn->acceptance): ?>
  <div class="mb-4 px-4 py-3 bg-blue-50 border border-blue-200 rounded-xl text-sm text-blue-800 flex items-center gap-2">
    <span class="material-symbols-outlined text-base text-blue-600">link</span>
    Linked to <a href="/acceptance/<?= $txn->acceptance_id ?>" class="font-bold underline hover:text-blue-600">Acceptance #<?= $txn->acceptance_id ?></a>
    — <?= htmlspecialchars($txn->acceptance->customer_name ?? '') ?> (<?= $txn->acceptance->status ?? '' ?>)
  </div>
  <?php endif; ?>

  <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

    <!-- ── LEFT COLUMN (2/3) ─────────────────────────────────────── -->
    <div class="lg:col-span-2 space-y-6">

      <!-- Customer & Booking -->
      <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100 bg-slate-50/50">
          <h2 class="font-bold text-slate-900" style="font-family:Manrope">
            <span class="material-symbols-outlined text-base align-text-bottom mr-1">person</span>
            Customer & Booking
          </h2>
        </div>
        <div class="p-6 grid grid-cols-2 sm:grid-cols-3 gap-4 text-sm">
          <div><p class="text-[10px] font-bold text-slate-400 uppercase">Name</p><p class="font-semibold"><?= htmlspecialchars($txn->customer_name) ?></p></div>
          <div><p class="text-[10px] font-bold text-slate-400 uppercase">Email</p><p class="text-slate-700"><?= htmlspecialchars($txn->customer_email) ?></p></div>
          <div><p class="text-[10px] font-bold text-slate-400 uppercase">Phone</p><p class="text-slate-700"><?= htmlspecialchars($txn->customer_phone ?: '—') ?></p></div>
          <div><p class="text-[10px] font-bold text-slate-400 uppercase">PNR</p><p class="font-mono font-bold tracking-wider text-primary"><?= htmlspecialchars($txn->pnr) ?></p></div>
          <div><p class="text-[10px] font-bold text-slate-400 uppercase">Airline</p><p class="text-slate-700"><?= htmlspecialchars($txn->airline ?: '—') ?></p></div>
          <div><p class="text-[10px] font-bold text-slate-400 uppercase">Order ID</p><p class="text-slate-700 font-mono text-xs"><?= htmlspecialchars($txn->order_id ?: '—') ?></p></div>
          <?php if ($txn->travel_date): ?>
          <div><p class="text-[10px] font-bold text-slate-400 uppercase">Travel Date</p><p class="text-slate-700"><?= date('M d, Y', strtotime($txn->travel_date)) ?></p></div>
          <?php endif; ?>
          <?php if ($txn->return_date): ?>
          <div><p class="text-[10px] font-bold text-slate-400 uppercase">Return</p><p class="text-slate-700"><?= date('M d, Y', strtotime($txn->return_date)) ?></p></div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Passengers -->
      <?php if ($txn->passengers->count()): ?>
      <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100 bg-slate-50/50">
          <h2 class="font-bold text-slate-900" style="font-family:Manrope">
            <span class="material-symbols-outlined text-base align-text-bottom mr-1">groups</span>
            Passengers (<?= $txn->passengers->count() ?>)
          </h2>
        </div>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead><tr class="border-b border-slate-100">
              <th class="px-6 py-2 text-left text-[10px] font-bold text-slate-500 uppercase">#</th>
              <th class="px-4 py-2 text-left text-[10px] font-bold text-slate-500 uppercase">Name</th>
              <th class="px-4 py-2 text-left text-[10px] font-bold text-slate-500 uppercase">Type</th>
              <th class="px-4 py-2 text-left text-[10px] font-bold text-slate-500 uppercase">DOB</th>
              <th class="px-4 py-2 text-left text-[10px] font-bold text-slate-500 uppercase">Ticket #</th>
            </tr></thead>
            <tbody class="divide-y divide-slate-50">
              <?php foreach ($txn->passengers as $i => $pax): ?>
              <tr>
                <td class="px-6 py-2 text-xs text-slate-400"><?= $i + 1 ?></td>
                <td class="px-4 py-2 font-semibold"><?= htmlspecialchars($pax->fullName()) ?></td>
                <td class="px-4 py-2"><span class="inline-block px-2 py-0.5 text-[10px] font-bold rounded bg-slate-100 text-slate-600"><?= $pax->paxLabel() ?></span></td>
                <td class="px-4 py-2 text-xs text-slate-500"><?= $pax->dob ? date('M d, Y', strtotime($pax->dob)) : '—' ?></td>
                <td class="px-4 py-2 font-mono text-xs"><?= htmlspecialchars($pax->ticket_number ?: '—') ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php endif; ?>

      <!-- Payment Cards -->
      <?php if ($txn->cards->count()): ?>
      <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100 bg-slate-50/50">
          <h2 class="font-bold text-slate-900" style="font-family:Manrope">
            <span class="material-symbols-outlined text-base align-text-bottom mr-1">credit_card</span>
            Payment Cards (<?= $txn->cards->count() ?>)
          </h2>
        </div>
        <div class="p-6 space-y-3">
          <?php foreach ($txn->cards as $card): ?>
          <div class="flex items-center justify-between p-3 bg-slate-50 border border-slate-200 rounded-lg">
            <div class="flex items-center gap-3">
              <div>
                <span class="text-xs font-bold text-slate-500"><?= $card->card_type ?></span>
                <?php if ($card->is_primary): ?><span class="text-[9px] font-bold text-blue-600 ml-1">PRIMARY</span><?php endif; ?>
                <p class="font-mono font-bold tracking-wider text-sm mt-0.5"><?= $card->maskedNumber() ?></p>
                <p class="text-[10px] text-slate-500"><?= htmlspecialchars($card->holder_name) ?> · Exp <?= $card->expiryFormatted() ?></p>
              </div>
            </div>
            <div class="text-right">
              <p class="font-mono font-bold text-sm"><?= $txn->currency ?> <?= number_format($card->amount, 2) ?></p>
              <?php if ($isAdmin): ?>
              <button type="button" onclick="revealCard(<?= $card->id ?>)"
                class="mt-1 text-[10px] font-bold text-amber-600 hover:text-amber-500 flex items-center gap-0.5 ml-auto">
                <span class="material-symbols-outlined text-xs">visibility</span> Reveal Full Details
              </button>
              <?php endif; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- Type-Specific Data -->
      <?php if ($txn->data && is_array($txn->data) && count($txn->data) > 0): ?>
      <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100 bg-slate-50/50">
          <h2 class="font-bold text-slate-900" style="font-family:Manrope">
            <span class="material-symbols-outlined text-base align-text-bottom mr-1">tune</span>
            <?= $txn->typeLabel() ?> Details
          </h2>
        </div>
        <div class="p-6 grid grid-cols-2 sm:grid-cols-3 gap-4 text-sm">
          <?php foreach ($txn->data as $key => $val): ?>
          <?php if (!empty($val)): ?>
          <div>
            <p class="text-[10px] font-bold text-slate-400 uppercase"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $key))) ?></p>
            <p class="text-slate-700"><?= htmlspecialchars(is_array($val) ? json_encode($val) : $val) ?></p>
          </div>
          <?php endif; ?>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- Agent Notes -->
      <?php if ($txn->agent_notes): ?>
      <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100 bg-slate-50/50">
          <h2 class="font-bold text-slate-900" style="font-family:Manrope">
            <span class="material-symbols-outlined text-base align-text-bottom mr-1">sticky_note_2</span> Notes
          </h2>
        </div>
        <div class="p-6 text-sm text-slate-700 whitespace-pre-line"><?= htmlspecialchars($txn->agent_notes) ?></div>
      </div>
      <?php endif; ?>
    </div>

    <!-- ── RIGHT COLUMN (1/3) ────────────────────────────────────── -->
    <div class="space-y-6">

      <!-- Financial Summary -->
      <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100 bg-slate-50/50">
          <h2 class="font-bold text-slate-900" style="font-family:Manrope">
            <span class="material-symbols-outlined text-base align-text-bottom mr-1">payments</span> Financials
          </h2>
        </div>
        <div class="p-6 space-y-3">
          <div class="flex justify-between items-baseline">
            <span class="text-xs text-slate-500">Total Charged</span>
            <span class="font-mono font-bold text-lg"><?= $txn->currency ?> <?= number_format($txn->total_amount, 2) ?></span>
          </div>
          <div class="flex justify-between items-baseline">
            <span class="text-xs text-slate-500">Cost Amount</span>
            <span class="font-mono text-sm text-slate-600"><?= $txn->currency ?> <?= number_format($txn->cost_amount, 2) ?></span>
          </div>
          <div class="border-t border-slate-200 pt-3 flex justify-between items-baseline">
            <span class="text-xs font-bold text-slate-700">Profit / MCO</span>
            <span class="font-mono font-bold text-lg <?= $txn->profit_mco >= 0 ? 'text-emerald-600' : 'text-red-600' ?>">
              <?= $txn->formattedMco() ?>
            </span>
          </div>
          <div class="flex justify-between items-baseline pt-2">
            <span class="text-xs text-slate-500">Payment Method</span>
            <span class="text-xs font-semibold"><?= $txn->paymentMethodLabel() ?></span>
          </div>
          <div class="flex justify-between items-baseline">
            <span class="text-xs text-slate-500">Payment Status</span>
            <span class="inline-block px-2 py-0.5 text-[10px] font-bold rounded-full <?= $payClass ?>"><?= $payLabel ?></span>
          </div>
        </div>
      </div>


      <!-- Reversal Info -->
      <?php if ($txn->voidOf): ?>
      <div class="bg-orange-50 border border-orange-200 rounded-xl p-4 text-sm">
        <p class="font-bold text-orange-800 flex items-center gap-1"><span class="material-symbols-outlined text-base">undo</span> Reversal of</p>
        <a href="/transactions/<?= $txn->void_of_transaction_id ?>" class="font-bold text-orange-600 underline">#<?= $txn->void_of_transaction_id ?></a>
      </div>
      <?php endif; ?>
      <?php if ($txn->reversal): ?>
      <div class="bg-red-50 border border-red-200 rounded-xl p-4 text-sm">
        <p class="font-bold text-red-800 flex items-center gap-1"><span class="material-symbols-outlined text-base">block</span> Voided by</p>
        <a href="/transactions/<?= $txn->reversal->id ?>" class="font-bold text-red-600 underline">Reversal #<?= $txn->reversal->id ?></a>
      </div>
      <?php endif; ?>
    </div>
  </div>
</main>


<!-- ── CARD REVEAL MODAL ───────────────────────────────────────────────── -->
<div id="reveal_modal" class="hidden fixed inset-0 bg-black/40 flex items-center justify-center z-50 backdrop-blur-sm">
  <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm p-6">
    <h3 class="text-lg font-bold text-amber-700 flex items-center gap-2 mb-4">
      <span class="material-symbols-outlined">visibility</span> Reveal Card
    </h3>
    <input type="hidden" id="reveal_card_id">
    <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Re-enter your password</label>
    <input type="password" id="reveal_password" class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm mb-4 focus:ring-2 focus:ring-amber-400" placeholder="Your admin password">
    <div id="reveal_result" class="hidden mb-4 p-3 bg-amber-50 border border-amber-200 rounded-lg"></div>
    <div id="reveal_error" class="hidden mb-4 p-3 bg-red-50 border border-red-200 rounded-lg text-sm text-red-700"></div>
    <div class="flex items-center gap-3">
      <button type="button" onclick="closeReveal()" class="flex-1 py-2 text-sm font-semibold text-slate-500 border border-slate-200 rounded-lg hover:bg-slate-50">Close</button>
      <button type="button" onclick="submitReveal()" id="btn_reveal" class="flex-1 py-2 bg-amber-600 text-white text-sm font-bold rounded-lg hover:bg-amber-500">Reveal</button>
    </div>
  </div>
</div>

<script>
function revealCard(cardId) {
  document.getElementById('reveal_card_id').value = cardId;
  document.getElementById('reveal_password').value = '';
  document.getElementById('reveal_result').classList.add('hidden');
  document.getElementById('reveal_error').classList.add('hidden');
  document.getElementById('reveal_modal').classList.remove('hidden');
}
function closeReveal() {
  document.getElementById('reveal_modal').classList.add('hidden');
  document.getElementById('reveal_result').classList.add('hidden');
}
function submitReveal() {
  const cardId = document.getElementById('reveal_card_id').value;
  const pw     = document.getElementById('reveal_password').value;
  if(!pw) return;
  document.getElementById('btn_reveal').disabled = true;
  document.getElementById('btn_reveal').textContent = 'Verifying...';
  fetch('/transactions/reveal-card', {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: `card_id=${cardId}&password=${encodeURIComponent(pw)}`
  })
  .then(r => r.json())
  .then(res => {
    document.getElementById('btn_reveal').disabled = false;
    document.getElementById('btn_reveal').textContent = 'Reveal';
    if(res.success) {
      const d = res.data;
      document.getElementById('reveal_result').innerHTML = `
        <p class="text-xs font-bold text-amber-800 mb-2">Decrypted Card Data:</p>
        <p class="font-mono font-bold text-lg tracking-wider">${d.card_number}</p>
        <p class="text-sm mt-1"><strong>CVV:</strong> <span class="font-mono font-bold">${d.cvv || '—'}</span></p>
        <p class="text-sm"><strong>Expiry:</strong> ${d.expiry}</p>
        <p class="text-sm"><strong>Holder:</strong> ${d.holder_name}</p>
        <p class="text-[10px] text-amber-600 mt-2">⚠ This reveal has been logged.</p>
      `;
      document.getElementById('reveal_result').classList.remove('hidden');
      document.getElementById('reveal_error').classList.add('hidden');
    } else {
      document.getElementById('reveal_error').textContent = res.error || 'Reveal failed.';
      document.getElementById('reveal_error').classList.remove('hidden');
      document.getElementById('reveal_result').classList.add('hidden');
    }
  })
  .catch(() => {
    document.getElementById('btn_reveal').disabled = false;
    document.getElementById('btn_reveal').textContent = 'Reveal';
    document.getElementById('reveal_error').textContent = 'Network error.';
    document.getElementById('reveal_error').classList.remove('hidden');
  });
}
</script>


</body>
</html>
