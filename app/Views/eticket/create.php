<?php
/**
 * E-Ticket — Create Form
 */

use App\Services\ETicketService;

$userRole = $_SESSION['role'] ?? 'agent';
$isAdmin  = in_array($userRole, ['admin', 'manager', 'supervisor']);
$activePage = 'etickets';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf  = $_SESSION['csrf_token'];
$error = $_GET['error'] ?? '';
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<title>New E-Ticket — Base Fare CRM</title>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
<script src="https://cdn.tailwindcss.com?plugins=forms"></script>
<script>
tailwind.config={darkMode:"class",theme:{extend:{colors:{primary:"#163274","primary-container":"#314a8d",background:"#f8f9fa",surface:"#f8f9fa","surface-container":"#edeeef","surface-container-low":"#f3f4f5","on-surface":"#191c1d","on-surface-variant":"#434653"},fontFamily:{headline:["Manrope"],body:["Inter"],label:["Inter"]}}}}}
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
        New E-Ticket
      </h1>
      <p class="text-sm text-on-surface-variant mt-0.5">Issue an e-ticket from an approved &amp; charged transaction</p>
    </div>
    <a href="/etickets" class="inline-flex items-center gap-2 px-4 py-2 border border-slate-200 rounded-lg text-sm text-slate-500 hover:bg-slate-50 transition-colors">
      ← Back to List
    </a>
  </div>

  <?php if ($error): ?>
  <div class="mb-4 px-4 py-3 bg-red-50 border border-red-200 rounded-xl text-sm font-semibold text-red-700 flex items-center gap-2">
    <span class="material-symbols-outlined text-base">error</span>
    <?= htmlspecialchars($error) ?>
  </div>
  <?php endif; ?>

  <!-- Autofill Banner -->
  <div id="autofill-banner" class="hidden mb-4 px-4 py-3 bg-emerald-50 border border-emerald-200 rounded-xl text-sm font-semibold text-emerald-700 flex items-center gap-2">
    <span class="material-symbols-outlined text-base">check_circle</span>
    Transaction data loaded — all fields pre-populated and editable.
  </div>

  <!-- Transaction Selector Card -->
  <div class="bg-white border border-slate-200 rounded-xl p-5 mb-5">
    <p class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-3">🔗 Select Transaction to Auto-fill</p>
    <div class="flex gap-3 items-end">
      <div class="flex-1">
        <select id="txn-selector" class="w-full border-2 border-primary rounded-lg px-3 py-2.5 text-sm bg-white font-body focus:ring-2 focus:ring-primary/40">
          <option value="">— Select an approved &amp; charged transaction —</option>
          <?php foreach ($autofill_options as $opt): ?>
          <option value="<?= (int)$opt['id'] ?>"><?= htmlspecialchars($opt['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="button" id="btn-autofill" onclick="loadAutofill()"
              class="inline-flex items-center gap-2 px-5 py-2.5 bg-primary text-white font-bold rounded-lg hover:bg-primary-container transition-colors text-sm whitespace-nowrap">
        <span class="material-symbols-outlined text-base">download</span> Load Data
      </button>
    </div>
    <p id="autofill-msg" class="text-xs text-slate-400 mt-2"></p>
  </div>

  <form method="POST" action="/etickets/create" id="et-form">
    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
    <input type="hidden" name="transaction_id" id="f-transaction_id" value="">
    <input type="hidden" name="flight_data_json" id="f-flight_data_json" value="">
    <input type="hidden" name="fare_breakdown_json" id="f-fare_breakdown_json" value="">
    <input type="hidden" name="extra_data_json" id="f-extra_data_json" value="">

    <!-- Customer Info -->
    <div class="bg-white border border-slate-200 rounded-xl p-5 mb-5">
      <p class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-4">👤 Customer Information</p>
      <div class="grid grid-cols-3 gap-4">
        <div>
          <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1.5">Full Name *</label>
          <input class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-slate-50 focus:ring-2 focus:ring-primary/40 focus:border-primary"
                 id="f-customer_name" name="customer_name" type="text" required placeholder="John Smith">
        </div>
        <div>
          <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1.5">Email Address *</label>
          <input class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-slate-50 focus:ring-2 focus:ring-primary/40 focus:border-primary"
                 id="f-customer_email" name="customer_email" type="email" required placeholder="john@email.com">
        </div>
        <div>
          <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1.5">Phone</label>
          <input class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-slate-50 focus:ring-2 focus:ring-primary/40 focus:border-primary"
                 id="f-customer_phone" name="customer_phone" type="text" placeholder="+1 555 000 0000">
        </div>
      </div>
    </div>

    <!-- Booking Details -->
    <div class="bg-white border border-slate-200 rounded-xl p-5 mb-5">
      <p class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-4">✈ Booking Details</p>
      <div class="grid grid-cols-4 gap-4">
        <div>
          <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1.5">PNR / Booking Ref *</label>
          <input class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-slate-50 focus:ring-2 focus:ring-primary/40 font-mono font-bold tracking-widest uppercase"
                 id="f-pnr" name="pnr" type="text" required placeholder="ABC123">
        </div>
        <div>
          <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1.5">Airline *</label>
          <input class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-slate-50 focus:ring-2 focus:ring-primary/40"
                 id="f-airline" name="airline" type="text" required placeholder="Air Canada">
        </div>
        <div>
          <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1.5">Order / Confirmation #</label>
          <input class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-slate-50 focus:ring-2 focus:ring-primary/40"
                 id="f-order_id" name="order_id" type="text" placeholder="CONF-12345">
        </div>
        <div>
          <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1.5">Total Amount *</label>
          <div class="flex gap-2">
            <select id="f-currency" name="currency" class="border border-slate-200 rounded-lg px-2 py-2 text-sm bg-slate-50 focus:ring-2 focus:ring-primary/40 w-20">
              <option>USD</option><option>CAD</option><option>GBP</option><option>EUR</option><option>AUD</option>
            </select>
            <input class="flex-1 border border-slate-200 rounded-lg px-3 py-2 text-sm bg-slate-50 focus:ring-2 focus:ring-primary/40 font-mono"
                   id="f-total_amount" name="total_amount" type="number" step="0.01" required placeholder="0.00">
          </div>
        </div>
      </div>
    </div>

    <!-- Passengers -->
    <div class="bg-white border border-slate-200 rounded-xl p-5 mb-5">
      <div class="flex items-center justify-between mb-4">
        <p class="text-xs font-bold text-slate-500 uppercase tracking-wider">🎫 Passengers &amp; Ticket Numbers</p>
        <button type="button" onclick="addPax()"
                class="inline-flex items-center gap-1 px-3 py-1.5 bg-emerald-50 text-emerald-700 border border-emerald-200 rounded-lg text-xs font-bold hover:bg-emerald-100 transition-colors">
          <span class="material-symbols-outlined text-sm">add</span> Add Passenger
        </button>
      </div>
      <p class="text-[10px] text-slate-400 mb-3">Enter the airline-issued e-ticket number for each passenger. If blank, a reference will be auto-generated.</p>
      <div id="pax-rows"></div>
    </div>

    <!-- Flight Itinerary -->
    <div class="bg-white border border-slate-200 rounded-xl p-5 mb-5">
      <p class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-3">🛫 Flight Itinerary</p>
      <div id="flight-display" class="bg-slate-50 border border-dashed border-slate-200 rounded-lg p-8 text-center text-slate-400 text-sm">
        Load a transaction above to populate flight itinerary
      </div>
    </div>

    <!-- Ticket Conditions -->
    <div class="bg-white border border-slate-200 rounded-xl p-5 mb-5">
      <p class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-4">📋 Ticket Conditions</p>
      <div class="grid grid-cols-2 gap-4 mb-4">
        <div>
          <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1.5">Endorsements / Restrictions</label>
          <textarea class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-slate-50 focus:ring-2 focus:ring-primary/40 font-mono"
                    id="f-endorsements" name="endorsements" rows="2" placeholder="e.g. NON END/NON REF/NON REROUTABLE"></textarea>
        </div>
        <div>
          <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1.5">Baggage Allowance</label>
          <textarea class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-slate-50 focus:ring-2 focus:ring-primary/40"
                    id="f-baggage_info" name="baggage_info" rows="2" placeholder="e.g. 1PC 23KG checked, 1 carry-on 7KG"></textarea>
        </div>
      </div>
      <div>
        <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1.5">Fare Rules (Exchange / Cancellation)</label>
        <textarea class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-slate-50 focus:ring-2 focus:ring-primary/40"
                  id="f-fare_rules" name="fare_rules" rows="2" placeholder="e.g. Changes: USD 250 penalty + fare difference. Cancellations: NON-REFUNDABLE."></textarea>
      </div>
    </div>

    <!-- Policy Text -->
    <div class="bg-white border border-slate-200 rounded-xl p-5 mb-5">
      <p class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-3">⚖️ Acknowledgment Policy (shown to customer)</p>
      <textarea class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-slate-50 focus:ring-2 focus:ring-primary/40 font-body leading-relaxed"
                id="f-policy_text" name="policy_text" rows="10"><?= htmlspecialchars($defaultPolicy) ?></textarea>
    </div>

    <!-- Agent Notes -->
    <div class="bg-white border border-slate-200 rounded-xl p-5 mb-5">
      <p class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-3">📝 Agent Notes (Internal)</p>
      <textarea class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-slate-50 focus:ring-2 focus:ring-primary/40"
                id="f-agent_notes" name="agent_notes" rows="2" placeholder="Internal notes (not visible to customer)…"></textarea>
    </div>

    <!-- Actions -->
    <div class="flex items-center justify-end gap-4 mt-6">
      <label class="flex items-center gap-2 cursor-pointer text-sm text-slate-500 mr-4">
        <input type="checkbox" name="send_now" value="1" class="w-4 h-4 accent-primary">
        Send e-ticket email immediately after saving
      </label>
      <a href="/etickets" class="px-5 py-2.5 border border-slate-200 rounded-xl text-sm text-slate-500 font-semibold hover:bg-slate-50 transition-colors">Cancel</a>
      <button type="submit"
              class="inline-flex items-center gap-2 px-6 py-2.5 bg-primary text-white font-bold rounded-xl hover:bg-primary-container shadow-lg shadow-primary/20 transition-all text-sm">
        <span class="material-symbols-outlined text-base">save</span> Save E-Ticket
      </button>
    </div>
  </form>

</main>

<script>
// ── PAX ROWS ─────────────────────────────────────────────────────────────────
let paxIndex = 0;

function addPax(data = {}) {
    const i = paxIndex++;
    const paxName    = data.pax_name      || '';
    const paxType    = data.pax_type      || 'adult';
    const ticketNo   = data.ticket_number || '';
    const seat       = data.seat          || '';
    const af         = data._autofilled   ? 'bg-blue-50 border-blue-200' : 'bg-slate-50 border-slate-200';

    const row = document.createElement('div');
    row.id = 'pax-row-' + i;
    row.className = 'relative border rounded-lg p-3 mb-2 ' + af;
    row.innerHTML = `
      <button type="button" onclick="removePax(${i})"
              class="absolute top-2 right-2 text-slate-400 hover:text-red-500 transition-colors">
        <span class="material-symbols-outlined text-base">close</span>
      </button>
      <div class="grid grid-cols-4 gap-3">
        <div>
          <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1">Passenger Name *</label>
          <input class="w-full border border-slate-200 rounded-lg px-2.5 py-1.5 text-xs bg-white focus:ring-2 focus:ring-primary/40 font-semibold"
                 name="pax_name[]" type="text" required value="${escHtml(paxName)}" placeholder="SMITH/JOHN">
        </div>
        <div>
          <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1">Type</label>
          <select class="w-full border border-slate-200 rounded-lg px-2.5 py-1.5 text-xs bg-white focus:ring-2 focus:ring-primary/40" name="pax_type[]">
            <option value="adult"  ${paxType==='adult'  ?'selected':''}>Adult</option>
            <option value="child"  ${paxType==='child'  ?'selected':''}>Child</option>
            <option value="infant" ${paxType==='infant' ?'selected':''}>Infant</option>
          </select>
        </div>
        <div>
          <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1">E-Ticket Number</label>
          <input class="w-full border border-slate-200 rounded-lg px-2.5 py-1.5 text-xs bg-white focus:ring-2 focus:ring-primary/40 font-mono"
                 name="ticket_number[]" type="text" value="${escHtml(ticketNo)}" placeholder="0141234567890">
        </div>
        <div>
          <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1">Seat</label>
          <input class="w-full border border-slate-200 rounded-lg px-2.5 py-1.5 text-xs bg-white focus:ring-2 focus:ring-primary/40 font-mono"
                 name="seat[]" type="text" value="${escHtml(seat)}" placeholder="12A">
        </div>
      </div>`;
    document.getElementById('pax-rows').appendChild(row);
}

function removePax(i) {
    const el = document.getElementById('pax-row-' + i);
    if (el) el.remove();
}

function escHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── AUTOFILL ──────────────────────────────────────────────────────────────────
async function loadAutofill() {
    const txnId = document.getElementById('txn-selector').value;
    if (!txnId) { setMsg('Please select a transaction.', true); return; }

    setMsg('Loading…');
    document.getElementById('btn-autofill').disabled = true;

    try {
        const res  = await fetch('/etickets/transaction-data/' + txnId);
        const json = await res.json();

        if (!json.success) {
            setMsg('⚠ ' + (json.error || 'Error loading data.'), true);
            document.getElementById('btn-autofill').disabled = false;
            return;
        }

        const d = json.data;

        document.getElementById('f-transaction_id').value     = d.transaction_id || '';
        document.getElementById('f-flight_data_json').value   = JSON.stringify(d.flight_data || null);
        document.getElementById('f-fare_breakdown_json').value = JSON.stringify(d.fare_breakdown || []);
        document.getElementById('f-extra_data_json').value    = JSON.stringify(d.extra_data || null);

        fillField('f-customer_name',  d.customer_name);
        fillField('f-customer_email', d.customer_email);
        fillField('f-customer_phone', d.customer_phone);
        fillField('f-pnr',           d.pnr);
        fillField('f-airline',        d.airline);
        fillField('f-order_id',       d.order_id);
        fillField('f-total_amount',   d.total_amount);

        if (d.currency) {
            for (let opt of document.getElementById('f-currency').options) {
                opt.selected = (opt.value === d.currency);
            }
        }

        fillField('f-endorsements', d.endorsements);
        fillField('f-baggage_info', d.baggage_info);
        fillField('f-fare_rules',   d.fare_rules);

        document.getElementById('pax-rows').innerHTML = '';
        paxIndex = 0;
        if (d.ticket_data && d.ticket_data.length > 0) {
            d.ticket_data.forEach(p => addPax({...p, _autofilled: true}));
        } else {
            addPax();
        }

        renderFlightDisplay(d.flight_data);
        document.getElementById('autofill-banner').classList.remove('hidden');
        setMsg('');

    } catch (err) {
        setMsg('⚠ Network error. Please try again.', true);
    }

    document.getElementById('btn-autofill').disabled = false;
}

function fillField(id, value) {
    const el = document.getElementById(id);
    if (!el || !value) return;
    el.value = value;
    el.classList.add('bg-blue-50', 'border-blue-200');
}

function setMsg(msg, isError = false) {
    const el = document.getElementById('autofill-msg');
    el.textContent = msg;
    el.className = 'text-xs mt-2 ' + (isError ? 'text-red-500' : 'text-slate-400');
}

// ── FLIGHT DISPLAY ────────────────────────────────────────────────────────────
function renderFlightDisplay(flights) {
    const el = document.getElementById('flight-display');
    if (!flights || !flights.length) {
        el.innerHTML = '<span class="text-slate-400 text-sm">No flight itinerary data available from this record.</span>';
        return;
    }

    let html = '<div class="divide-y divide-slate-100">';
    flights.forEach(f => {
        const dep = f.departure_airport || f.from || '???';
        const arr = f.arrival_airport   || f.to   || '???';
        const dt  = f.departure_date    || f.date || '';
        const tm  = f.departure_time    || f.time || '';
        const fn  = f.flight_number     || f.flight || '';
        const cab = f.cabin_class       || f.class  || '';
        html += `
        <div class="flex items-center gap-6 py-4">
          <div class="text-center min-w-[56px]">
            <div class="text-xl font-black text-primary font-mono">${escHtml(dep)}</div>
            <div class="text-[10px] text-slate-400">${escHtml(dt)}</div>
            <div class="text-xs font-bold text-slate-500">${escHtml(tm)}</div>
          </div>
          <div class="flex-1 text-center">
            <div class="text-[10px] text-slate-400 mb-1">${escHtml(fn)}${cab ? ' · ' + escHtml(cab) : ''}</div>
            <div class="flex items-center gap-1">
              <div class="flex-1 h-px bg-slate-200"></div>
              <span class="material-symbols-outlined text-base text-slate-400">flight</span>
              <div class="flex-1 h-px bg-slate-200"></div>
            </div>
          </div>
          <div class="text-center min-w-[56px]">
            <div class="text-xl font-black text-primary font-mono">${escHtml(arr)}</div>
            <div class="text-[10px] text-slate-400">${escHtml(f.arrival_date||'')}</div>
            <div class="text-xs font-bold text-slate-500">${escHtml(f.arrival_time||'')}</div>
          </div>
        </div>`;
    });
    html += '</div>';
    el.innerHTML = html;
    el.className = 'rounded-lg border border-slate-100 px-4';
}

window.addEventListener('DOMContentLoaded', () => { addPax(); });
</script>

</body>
</html>
