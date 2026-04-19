<?php
/**
 * Transaction Recorder — Edit Form (Admin/Manager)
 *
 * @var \App\Models\Transaction $txn
 * @var string|null $flashError
 */
use App\Models\Transaction;

$isAdmin    = in_array($_SESSION['role'] ?? 'agent', ['admin', 'manager']);
$flashError = $flashError ?? null;

// ── Pre-fill data ────────────────────────────────────────────────────────────
$prefill = [
    'type'           => $txn->type,
    'customer_name'  => $txn->customer_name,
    'customer_email' => $txn->customer_email,
    'customer_phone' => $txn->customer_phone,
    'pnr'            => strtoupper($txn->pnr ?? ''),
    'airline'        => $txn->airline,
    'order_id'       => $txn->order_id,
    'total_amount'   => $txn->total_amount,
    'cost_amount'    => $txn->cost_amount,
    'currency'       => $txn->currency ?? 'USD',
    'travel_date'    => $txn->travel_date,
    'departure_time' => $txn->departure_time,
    'return_date'    => $txn->return_date,
    'payment_method' => $txn->payment_method,
    'payment_status' => $txn->payment_status,
    'agent_notes'    => $txn->agent_notes,
    'passengers'     => [],
];

foreach ($txn->passengers as $pax) {
    $prefill['passengers'][] = [
        'first_name'     => $pax->first_name,
        'last_name'      => $pax->last_name,
        'dob'            => $pax->dob,
        'pax_type'       => $pax->pax_type,
        'ticket_number'  => $pax->ticket_number,
        'frequent_flyer' => $pax->frequent_flyer,
    ];
}

$statusMap = [
    'pending_review' => ['label' => 'Pending Review', 'color' => 'amber',   'icon' => 'schedule'],
    'approved'       => ['label' => 'Approved',        'color' => 'emerald', 'icon' => 'check_circle'],
    'voided'         => ['label' => 'Voided',           'color' => 'red',    'icon' => 'block'],
];
$currentStatus = $txn->status ?? 'pending_review';
$statusInfo    = $statusMap[$currentStatus] ?? $statusMap['pending_review'];

$typeCards = [
    ['value' => 'new_booking',     'label' => 'New Booking',            'icon' => 'flight_takeoff',            'color' => '#3b82f6'],
    ['value' => 'exchange',        'label' => 'Exchange / Date Change', 'icon' => 'swap_horiz',                'color' => '#8b5cf6'],
    ['value' => 'cancel_refund',   'label' => 'Cancellation & Refund',  'icon' => 'money_off',                 'color' => '#ef4444'],
    ['value' => 'cancel_credit',   'label' => 'Cancellation & Credit',  'icon' => 'savings',                   'color' => '#f97316'],
    ['value' => 'seat_purchase',   'label' => 'Seat Purchase',           'icon' => 'airline_seat_recline_extra','color' => '#06b6d4'],
    ['value' => 'cabin_upgrade',   'label' => 'Cabin Upgrade',           'icon' => 'upgrade',                   'color' => '#10b981'],
    ['value' => 'name_correction', 'label' => 'Name Correction',        'icon' => 'edit',                      'color' => '#f59e0b'],
    ['value' => 'other',           'label' => 'Other',                   'icon' => 'more_horiz',                'color' => '#6b7280'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Edit Transaction #<?= $txn->id ?> — Base Fare CRM</title>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
<script src="https://cdn.tailwindcss.com?plugins=forms"></script>
<script>
tailwind.config = {
  darkMode: 'class',
  theme: { extend: { colors: {
    primary: '#163274', 'primary-dark': '#0f2358',
    background: '#f4f6fb', surface: '#ffffff',
  }, fontFamily: { headline: ['Manrope'], body: ['Inter'] } } }
}
</script>
<style>
  .msym { font-family: 'Material Symbols Outlined'; font-weight: normal; font-style: normal; font-size: 20px; line-height: 1; display: inline-block; vertical-align: middle; }
  .fi { width: 100%; border: 1px solid #e2e8f0; border-radius: 8px; padding: 8px 12px; font-size: 13px; background: #f8fafc; outline: none; transition: border-color .15s, box-shadow .15s; }
  .fi:focus { border-color: rgba(22,50,116,.5); box-shadow: 0 0 0 3px rgba(22,50,116,.08); }
  .fl { display: block; font-size: 10px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: .06em; margin-bottom: 5px; }
  .type-card { cursor: pointer; border: 2px solid #e2e8f0; border-radius: 12px; padding: 14px 10px; text-align: center; transition: all .18s; background: #fff; }
  .type-card:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,.08); }
  .type-card.active { border-color: var(--tc); background: color-mix(in srgb, var(--tc) 8%, white); box-shadow: 0 0 0 3px color-mix(in srgb, var(--tc) 20%, transparent); transform: translateY(-2px); }
  .admin-badge { display: inline-flex; align-items: center; gap: 4px; padding: 3px 8px; background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 999px; font-size: 11px; font-weight: 700; color: #1d4ed8; }
  .section { background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; box-shadow: 0 1px 3px rgba(0,0,0,.05); overflow: hidden; }
  .section-head { padding: 14px 22px; border-bottom: 1px solid #f1f5f9; background: #f8fafc; display: flex; align-items: center; justify-content: space-between; }
  .section-head h2 { font-family: Manrope; font-weight: 700; font-size: 14px; color: #1e293b; display: flex; align-items: center; gap: 6px; }
  .section-body { padding: 20px 22px; }
  .grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
  .grid3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 14px; }
  .grid4 { display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 14px; }
</style>
</head>
<body style="background:#f4f6fb;font-family:Inter,sans-serif;min-height:100vh;">

<?php if ($isAdmin): ?>
<?php $activePage = 'transactions'; require __DIR__ . '/../partials/admin_sidebar.php'; ?>
<?php else: ?>
<?php $activePage = 'transactions'; require __DIR__ . '/../partials/agent_sidebar.php'; ?>
<?php endif; ?>

<main class="ml-60" style="padding: 24px 32px 80px; max-width: 1100px;">

  <!-- ── Page Header ──────────────────────────────────────────────────────── -->
  <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:24px;">
    <div>
      <h1 style="font-family:Manrope;font-size:22px;font-weight:800;color:#163274;display:flex;align-items:center;gap:8px;margin:0;">
        <span class="msym">edit_note</span> Edit Transaction #<?= $txn->id ?>
      </h1>
      <p style="font-size:13px;color:#64748b;margin-top:4px;">
        Status: <span style="font-weight:700;color:<?= $statusInfo['color'] === 'amber' ? '#d97706' : ($statusInfo['color'] === 'emerald' ? '#059669' : '#dc2626') ?>">
          <?= $statusInfo['label'] ?>
        </span>
        <?php if ($isAdmin): ?>
          &nbsp;<span class="admin-badge"><span class="msym" style="font-size:12px;">admin_panel_settings</span> Admin Mode</span>
        <?php endif; ?>
      </p>
    </div>
    <a href="/transactions/<?= $txn->id ?>" style="display:inline-flex;align-items:center;gap:6px;font-size:13px;font-weight:600;color:#64748b;text-decoration:none;padding:8px 14px;border:1px solid #e2e8f0;border-radius:8px;background:#fff;transition:all .15s;" onmouseover="this.style.color='#163274'" onmouseout="this.style.color='#64748b'">
      <span class="msym" style="font-size:16px;">arrow_back</span> Back to Transaction
    </a>
  </div>

  <?php if ($flashError): ?>
  <div style="margin-bottom:16px;padding:12px 16px;background:#fef2f2;border:1px solid #fecaca;border-radius:10px;font-size:13px;font-weight:600;color:#dc2626;display:flex;align-items:center;gap:8px;">
    <span class="msym" style="font-size:18px;">error</span><?= htmlspecialchars($flashError) ?>
  </div>
  <?php endif; ?>

  <form id="txnForm" method="POST" action="/transactions/<?= $txn->id ?>/edit" style="display:flex;flex-direction:column;gap:16px;">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">

    <!-- ── Admin: Status Control ─────────────────────────────────────────── -->
    <?php if ($isAdmin): ?>
    <div class="section" style="border-color:#bfdbfe;background:linear-gradient(135deg,#eff6ff,#fff);">
      <div class="section-head" style="background:rgba(219,234,254,.4);border-color:#bfdbfe;">
        <h2 style="color:#1d4ed8;"><span class="msym" style="font-size:18px;color:#3b82f6;">admin_panel_settings</span> Transaction Status <span style="font-size:10px;font-weight:600;background:#dbeafe;color:#1d4ed8;padding:2px 8px;border-radius:999px;margin-left:4px;">ADMIN ONLY</span></h2>
      </div>
      <div class="section-body">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;align-items:start;">
          <div>
            <label class="fl" style="color:#1d4ed8;">Change Status</label>
            <select name="status" class="fi" style="border-color:#93c5fd;color:#1e3a8a;font-weight:700;background:#eff6ff;font-size:13px;">
              <option value="pending_review" <?= $currentStatus === 'pending_review' ? 'selected' : '' ?>>⏳ Pending Review — Agent can still edit</option>
              <option value="approved"       <?= $currentStatus === 'approved'       ? 'selected' : '' ?>>✅ Approved — Locked from agent</option>
              <option value="voided"         <?= $currentStatus === 'voided'         ? 'selected' : '' ?>>🚫 Voided — Cancelled / reversed</option>
            </select>
          </div>
          <div style="padding-top:22px;">
            <p style="font-size:12px;color:#64748b;line-height:1.6;background:#f1f5f9;padding:10px 12px;border-radius:8px;border:1px solid #e2e8f0;">
              <strong>Pending Review</strong> — Visible & editable by the agent.<br>
              <strong>Approved</strong> — Agent can no longer edit; manager oversight done.<br>
              <strong>Voided</strong> — Marks this transaction as cancelled.
            </p>
          </div>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- ── Transaction Type ──────────────────────────────────────────────── -->
    <div class="section">
      <div class="section-head"><h2><span class="msym">category</span> Transaction Type</h2></div>
      <div class="section-body">
        <input type="hidden" name="type" id="field_type" value="<?= htmlspecialchars($prefill['type']) ?>">
        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;">
          <?php foreach ($typeCards as $tc): ?>
          <div class="type-card <?= $prefill['type'] === $tc['value'] ? 'active' : '' ?>"
               data-type="<?= $tc['value'] ?>"
               style="--tc:<?= $tc['color'] ?>;"
               onclick="selectType('<?= $tc['value'] ?>')">
            <span class="msym" style="font-size:24px;color:<?= $tc['color'] ?>;display:block;margin-bottom:6px;"><?= $tc['icon'] ?></span>
            <p style="font-size:11px;font-weight:700;color:#1e293b;margin:0;"><?= $tc['label'] ?></p>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- ── Customer & Booking ────────────────────────────────────────────── -->
    <div class="section">
      <div class="section-head"><h2><span class="msym">person</span> Customer & Booking</h2></div>
      <div class="section-body" style="display:flex;flex-direction:column;gap:14px;">
        <div class="grid3">
          <div><label class="fl">Full Name *</label><input type="text" name="customer_name" value="<?= htmlspecialchars($prefill['customer_name']) ?>" required class="fi"></div>
          <div><label class="fl">Email *</label><input type="email" name="customer_email" value="<?= htmlspecialchars($prefill['customer_email']) ?>" required class="fi"></div>
          <div><label class="fl">Phone</label><input type="text" name="customer_phone" value="<?= htmlspecialchars($prefill['customer_phone'] ?? '') ?>" class="fi"></div>
        </div>
        <div class="grid4">
          <div><label class="fl">PNR *</label><input type="text" name="pnr" value="<?= htmlspecialchars($prefill['pnr']) ?>" required class="fi" style="font-family:monospace;text-transform:uppercase;font-weight:700;"></div>
          <div><label class="fl">Airline</label><input type="text" name="airline" value="<?= htmlspecialchars($prefill['airline'] ?? '') ?>" class="fi"></div>
          <div><label class="fl">Order / Ticket ID</label><input type="text" name="order_id" value="<?= htmlspecialchars($prefill['order_id'] ?? '') ?>" class="fi"></div>
          <div><label class="fl">Currency</label>
            <select name="currency" class="fi">
              <?php foreach (['USD', 'CAD', 'GBP', 'EUR', 'INR', 'AED'] as $c): ?>
              <option value="<?= $c ?>" <?= $prefill['currency'] === $c ? 'selected' : '' ?>><?= $c ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="grid3">
          <div><label class="fl">Travel Date</label><input type="date" name="travel_date" value="<?= htmlspecialchars($prefill['travel_date'] ?? '') ?>" class="fi"></div>
          <div><label class="fl">Departure Time</label><input type="time" name="departure_time" value="<?= htmlspecialchars($prefill['departure_time'] ?? '') ?>" class="fi"></div>
          <div><label class="fl">Return Date</label><input type="date" name="return_date" value="<?= htmlspecialchars($prefill['return_date'] ?? '') ?>" class="fi"></div>
        </div>
      </div>
    </div>

    <!-- ── Passengers ───────────────────────────────────────────────────── -->
    <div class="section">
      <div class="section-head">
        <h2><span class="msym">groups</span> Passengers</h2>
        <button type="button" onclick="paxMgr.add()" style="display:inline-flex;align-items:center;gap:4px;font-size:12px;font-weight:700;color:#163274;background:none;border:none;cursor:pointer;padding:4px 8px;border-radius:6px;" onmouseover="this.style.background='#eff6ff'" onmouseout="this.style.background='none'">
          <span class="msym" style="font-size:16px;">add_circle</span> Add Passenger
        </button>
      </div>
      <div class="section-body">
        <div id="pax-list" style="display:flex;flex-direction:column;gap:10px;"></div>
        <input type="hidden" name="passengers_json" id="passengers_json">
      </div>
    </div>

    <!-- ── Other type description (shown only for 'other' type) ─────────── -->
    <div id="section-other-desc" class="section" style="border-color:#f59e0b;background:linear-gradient(135deg,#fffbeb,#fff);display:none;">
      <div class="section-head" style="background:rgba(254,243,199,.5);border-color:#fde68a;">
        <h2 style="color:#92400e;"><span class="msym" style="color:#f59e0b;">description</span> Charge Description <span style="font-size:11px;font-weight:600;color:#b45309;">— required for Other / Miscellaneous</span></h2>
      </div>
      <div class="section-body">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
          <div>
            <label class="fl" style="color:#92400e;">Charge Title / Short Description *</label>
            <input type="text" name="other_title" id="other_title_field"
                   value="<?= htmlspecialchars($txn->data['other_title'] ?? '') ?>"
                   placeholder="e.g. Airport transfer fee, Visa processing, Seat upgrade misc..."
                   class="fi" style="border-color:#fcd34d;">
            <p style="font-size:10px;color:#92400e;margin-top:4px;">A short title that describes what this charge is for.</p>
          </div>
          <div>
            <label class="fl" style="color:#92400e;">Additional Notes</label>
            <textarea name="other_notes" id="other_notes_field" rows="2" class="fi" style="border-color:#fcd34d;resize:none;"><?= htmlspecialchars($txn->data['other_notes'] ?? '') ?></textarea>
          </div>
        </div>
      </div>
    </div>

    <!-- ── Financials ────────────────────────────────────────────────────── -->
    <div class="section">
      <div class="section-head"><h2><span class="msym">receipt_long</span> Amounts & Payment</h2></div>
      <div class="section-body" style="display:flex;flex-direction:column;gap:14px;">
        <div class="grid3">
          <div>
            <label class="fl">Total Charged to Card *</label>
            <input type="number" step="0.01" name="total_amount" id="field_total_amount"
                   value="<?= $prefill['total_amount'] ?>" required class="fi"
                   style="font-family:monospace;font-weight:700;">
            <p style="font-size:10px;color:#64748b;margin-top:3px;">Amount billed to the customer's card</p>
          </div>
          <div>
            <label class="fl">Cost / Net (Supplier Price)</label>
            <input type="number" step="0.01" name="cost_amount" id="field_cost_amount"
                   value="<?= $prefill['cost_amount'] ?>" class="fi"
                   style="font-family:monospace;">
            <p style="font-size:10px;color:#64748b;margin-top:3px;">What we paid to the airline / supplier</p>
          </div>
          <div>
            <label class="fl">MCO — Profit Margin</label>
            <input type="number" step="0.01" name="profit_mco" id="field_profit_mco"
                   value="<?= htmlspecialchars($txn->profit_mco) ?>" required class="fi"
                   <?= !$isAdmin ? 'readonly' : '' ?>
                   style="font-family:monospace;font-weight:700;color:#059669;background-color:<?= !$isAdmin ? '#d1fae5' : '#ecfdf5' ?>;border-color:#34d399;<?= !$isAdmin ? 'cursor:not-allowed;' : '' ?>">
            <p style="font-size:10px;color:#64748b;margin-top:3px;">
              <?= $isAdmin ? 'Manual entry (Admin/Manager)' : 'Locked (Admin/Manager only)' ?>
            </p>
          </div>
        </div>
        <div class="grid2">
          <div>
            <label class="fl">Payment Method</label>
            <select name="payment_method" class="fi">
              <?php foreach (Transaction::paymentMethodOptions() as $v => $l): ?>
              <option value="<?= $v ?>" <?= $prefill['payment_method'] === $v ? 'selected' : '' ?>><?= $l ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="fl">Payment Status</label>
            <select name="payment_status" class="fi">
              <?php foreach (Transaction::paymentStatusOptions() as $v => $l): ?>
              <option value="<?= $v ?>" <?= $prefill['payment_status'] === $v ? 'selected' : '' ?>><?= $l ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>
    </div>

    <!-- ── Notes ─────────────────────────────────────────────────────────── -->
    <div class="section">
      <div class="section-head"><h2><span class="msym">sticky_note_2</span> Agent Notes *</h2></div>
      <div class="section-body">
        <textarea name="agent_notes" rows="3" class="fi" required style="resize:vertical;"><?= htmlspecialchars($prefill['agent_notes'] ?? '') ?></textarea>
      </div>
    </div>

    <input type="hidden" name="type_specific_data_json" id="type_specific_data_json">

    <!-- ── Action Bar ────────────────────────────────────────────────────── -->
    <div style="display:flex;align-items:center;justify-content:space-between;padding:16px 0;">
      <a href="/transactions/<?= $txn->id ?>" style="font-size:13px;font-weight:600;color:#94a3b8;text-decoration:none;" onmouseover="this.style.color='#ef4444'" onmouseout="this.style.color='#94a3b8'">
        Cancel & go back
      </a>
      <button type="submit" style="display:inline-flex;align-items:center;gap:8px;padding:12px 28px;background:#163274;color:#fff;font-family:Manrope;font-weight:800;font-size:14px;border:none;border-radius:12px;cursor:pointer;box-shadow:0 4px 14px rgba(22,50,116,.25);transition:all .2s;" onmouseover="this.style.background='#0f2358'" onmouseout="this.style.background='#163274'">
        <span class="msym" style="font-size:20px;">save</span> Save Changes
      </button>
    </div>

  </form>
</main>



<script>
// ── Type selector ────────────────────────────────────────────────────────────
function selectType(val) {
  document.getElementById('field_type').value = val;
  document.querySelectorAll('.type-card').forEach(c => c.classList.remove('active'));
  const sel = document.querySelector(`.type-card[data-type="${val}"]`);
  if (sel) sel.classList.add('active');
  // Show/hide the Other description panel
  const otherPanel = document.getElementById('section-other-desc');
  if (otherPanel) otherPanel.style.display = (val === 'other') ? 'block' : 'none';
}

// ── MCO manual entry now (auto-calc removed) ───────────────────────────────

// ── Passenger manager ────────────────────────────────────────────────────────
const paxMgr = {
  list: <?= json_encode($prefill['passengers'] ?: []) ?>,
  add(p = null) {
    this.list.push(p || { first_name:'', last_name:'', dob:'', pax_type:'adult', ticket_number:'', frequent_flyer:'' });
    this._render();
  },
  remove(i) { this.list.splice(i, 1); this._render(); },
  _e(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; },
  _render() {
    const c = document.getElementById('pax-list');
    c.innerHTML = this.list.map((p, i) => `
      <div style="display:grid;grid-template-columns:1fr 1fr 140px 100px 130px 120px 36px;gap:8px;align-items:center;padding:12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;">
        <div><input type="text" value="${this._e(p.first_name)}" placeholder="First name" onchange="paxMgr.list[${i}].first_name=this.value" style="width:100%;border:1px solid #e2e8f0;border-radius:6px;padding:6px 10px;font-size:12px;background:#fff;"></div>
        <div><input type="text" value="${this._e(p.last_name)}" placeholder="Last name" onchange="paxMgr.list[${i}].last_name=this.value" style="width:100%;border:1px solid #e2e8f0;border-radius:6px;padding:6px 10px;font-size:12px;background:#fff;"></div>
        <div><input type="date" value="${p.dob||''}" onchange="paxMgr.list[${i}].dob=this.value" style="width:100%;border:1px solid #e2e8f0;border-radius:6px;padding:6px 10px;font-size:12px;background:#fff;" title="Date of Birth"></div>
        <div><select onchange="paxMgr.list[${i}].pax_type=this.value" style="width:100%;border:1px solid #e2e8f0;border-radius:6px;padding:6px 8px;font-size:12px;background:#fff;">
          <option value="adult"  ${p.pax_type==='adult'?'selected':''}>Adult</option>
          <option value="child"  ${p.pax_type==='child'?'selected':''}>Child</option>
          <option value="infant" ${p.pax_type==='infant'?'selected':''}>Infant</option>
        </select></div>
        <div><input type="text" value="${this._e(p.ticket_number||'')}" placeholder="Ticket #" onchange="paxMgr.list[${i}].ticket_number=this.value" style="width:100%;border:1px solid #e2e8f0;border-radius:6px;padding:6px 10px;font-size:12px;font-family:monospace;background:#fff;" title="Ticket Number"></div>
        <div><input type="text" value="${this._e(p.frequent_flyer||'')}" placeholder="FF # (optional)" onchange="paxMgr.list[${i}].frequent_flyer=this.value" style="width:100%;border:1px solid #e2e8f0;border-radius:6px;padding:6px 10px;font-size:12px;font-family:monospace;background:#fff;" title="Frequent Flyer Number"></div>
        <div><button type="button" onclick="paxMgr.remove(${i})" style="width:32px;height:32px;border:1px solid #fecaca;border-radius:6px;background:#fef2f2;color:#dc2626;font-size:16px;cursor:pointer;display:flex;align-items:center;justify-content:center;" title="Remove">✕</button></div>
      </div>
    `).join('');
    this._sync();
  },
  _sync() { document.getElementById('passengers_json').value = JSON.stringify(this.list); }
};

// ── Init ─────────────────────────────────────────────────────────────────────
(function () {
  const t = document.getElementById('field_type').value;
  if (t) selectType(t);
  calcMco();
  if (paxMgr.list.length === 0) paxMgr.add(); else paxMgr._render();
})();

document.getElementById('txnForm').addEventListener('submit', function () {
  paxMgr._sync();
  const typeVal = document.getElementById('field_type').value;
  const typeData = {};
  if (typeVal === 'other') {
    typeData.other_title = (document.getElementById('other_title_field') || {}).value || '';
    typeData.other_notes = (document.getElementById('other_notes_field') || {}).value || '';
  }
  document.getElementById('type_specific_data_json').value = JSON.stringify(typeData);
});
</script>
</body>
</html>
