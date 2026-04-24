<?php
/**
 * E-Ticket — Create Form
 *
 * Auto-populates from an approved+charged transaction.
 * All fields are editable (auto-filled but not locked).
 *
 * @var array  $autofill_options  [{id, label, customer_name, pnr, ...}]
 * @var array|null $prefill
 * @var string $role
 * @var string $defaultPolicy
 */

$layout    = __DIR__ . '/../layout/base.php';
$pageTitle = 'New E-Ticket';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];
$error = $_GET['error'] ?? '';

ob_start();
?>
<style>
.et-card { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:24px 28px; margin-bottom:20px; }
.et-card-title { font-size:13px; font-weight:800; color:#0f1e3c; text-transform:uppercase; letter-spacing:.8px; margin:0 0 18px; padding-bottom:12px; border-bottom:1px solid #f1f5f9; display:flex; align-items:center; gap:8px; }
.et-label { font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.5px; display:block; margin-bottom:5px; }
.et-input { width:100%; border:1px solid #e2e8f0; border-radius:8px; padding:10px 14px; font-size:13px; color:#1e293b; font-family:inherit; box-sizing:border-box; transition:border-color .15s; }
.et-input:focus { outline:none; border-color:#1a3a6b; box-shadow:0 0 0 3px rgba(26,58,107,.08); }
.et-input.autofilled { background:#f0f9ff; border-color:#bae6fd; }
.et-row { display:grid; gap:16px; margin-bottom:16px; }
.et-row-2 { grid-template-columns: 1fr 1fr; }
.et-row-3 { grid-template-columns: 1fr 1fr 1fr; }
.et-row-4 { grid-template-columns: 1fr 1fr 1fr 1fr; }
.pax-row { background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:14px 16px; margin-bottom:10px; position:relative; }
.pax-row:hover { background:#f0f9ff; border-color:#bae6fd; }
.remove-pax { position:absolute; top:10px; right:12px; background:none; border:none; color:#ef4444; font-size:16px; cursor:pointer; padding:2px 6px; border-radius:4px; }
.remove-pax:hover { background:#fee2e2; }
.et-section-info { font-size:11px; color:#94a3b8; margin-top:4px; }
.autofill-banner { background:linear-gradient(135deg,#ecfdf5,#d1fae5); border:1px solid #6ee7b7; border-radius:10px; padding:12px 18px; margin-bottom:20px; display:none; align-items:center; gap:10px; }
.autofill-banner.show { display:flex; }
</style>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;">
  <div>
    <h1 style="font-size:22px;font-weight:800;color:#0f1e3c;margin:0;">✈ New E-Ticket</h1>
    <p style="font-size:13px;color:#64748b;margin:4px 0 0;">Issue an e-ticket for an approved & charged transaction</p>
  </div>
  <a href="/etickets" style="color:#64748b;font-size:13px;text-decoration:none;padding:8px 16px;border:1px solid #e2e8f0;border-radius:8px;">← Back to List</a>
</div>

<?php if ($error): ?>
<div style="background:#fee2e2;border:1px solid #fca5a5;border-radius:10px;padding:14px 18px;margin-bottom:20px;color:#991b1b;font-size:13px;font-weight:600;">
  ⚠ <?= htmlspecialchars($error) ?>
</div>
<?php endif; ?>

<!-- Transaction Selector -->
<div class="et-card">
  <div class="et-card-title">🔗 Select Transaction</div>
  <p style="font-size:13px;color:#64748b;margin:0 0 14px;">Choose an approved &amp; charged transaction to auto-populate the form. All fields are editable after loading.</p>
  <div style="display:flex;gap:12px;align-items:flex-end;">
    <div style="flex:1;">
      <label class="et-label">Transaction</label>
      <select id="txn-selector" style="width:100%;border:2px solid #1a3a6b;border-radius:8px;padding:10px 14px;font-size:13px;color:#1e293b;font-family:inherit;background:#fff;">
        <option value="">— Select a transaction —</option>
        <?php foreach ($autofill_options as $opt): ?>
        <option value="<?= (int)$opt['id'] ?>"><?= htmlspecialchars($opt['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button type="button" id="btn-autofill" onclick="loadAutofill()"
            style="background:linear-gradient(135deg,#0f1e3c,#1a3a6b);color:#fff;border:none;padding:11px 22px;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;white-space:nowrap;">
      Load Data ↓
    </button>
  </div>
  <div id="autofill-msg" style="margin-top:10px;font-size:12px;color:#64748b;"></div>
</div>

<div id="autofill-banner" class="autofill-banner">
  <span style="font-size:20px;">✅</span>
  <div>
    <div style="font-size:13px;font-weight:700;color:#065f46;">Transaction data loaded</div>
    <div style="font-size:11px;color:#047857;margin-top:1px;">All fields have been pre-populated. Review and edit before saving.</div>
  </div>
</div>

<form method="POST" action="/etickets/create" id="et-form">
  <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
  <input type="hidden" name="transaction_id" id="f-transaction_id" value="">
  <input type="hidden" name="flight_data_json" id="f-flight_data_json" value="">
  <input type="hidden" name="fare_breakdown_json" id="f-fare_breakdown_json" value="">
  <input type="hidden" name="extra_data_json" id="f-extra_data_json" value="">

  <!-- Customer Info -->
  <div class="et-card">
    <div class="et-card-title">👤 Customer Information</div>
    <div class="et-row et-row-3">
      <div>
        <label class="et-label" for="f-customer_name">Full Name *</label>
        <input class="et-input" id="f-customer_name" name="customer_name" type="text" required placeholder="John Smith">
      </div>
      <div>
        <label class="et-label" for="f-customer_email">Email Address *</label>
        <input class="et-input" id="f-customer_email" name="customer_email" type="email" required placeholder="john@email.com">
      </div>
      <div>
        <label class="et-label" for="f-customer_phone">Phone</label>
        <input class="et-input" id="f-customer_phone" name="customer_phone" type="text" placeholder="+1 555 000 0000">
      </div>
    </div>
  </div>

  <!-- Booking Reference -->
  <div class="et-card">
    <div class="et-card-title">✈ Booking Details</div>
    <div class="et-row et-row-4">
      <div>
        <label class="et-label" for="f-pnr">PNR / Booking Ref *</label>
        <input class="et-input" id="f-pnr" name="pnr" type="text" required placeholder="ABC123" style="font-family:monospace;font-weight:800;font-size:15px;letter-spacing:2px;text-transform:uppercase;">
      </div>
      <div>
        <label class="et-label" for="f-airline">Airline *</label>
        <input class="et-input" id="f-airline" name="airline" type="text" required placeholder="Air Canada">
      </div>
      <div>
        <label class="et-label" for="f-order_id">Order / Confirmation #</label>
        <input class="et-input" id="f-order_id" name="order_id" type="text" placeholder="CONF-12345">
      </div>
      <div>
        <div style="display:flex;gap:8px;">
          <div style="flex:1;">
            <label class="et-label" for="f-total_amount">Total Amount *</label>
            <input class="et-input" id="f-total_amount" name="total_amount" type="number" step="0.01" required placeholder="0.00">
          </div>
          <div style="width:80px;">
            <label class="et-label" for="f-currency">Cur.</label>
            <select class="et-input" id="f-currency" name="currency" style="padding:9px 8px;">
              <option>USD</option><option>CAD</option><option>GBP</option><option>EUR</option><option>AUD</option>
            </select>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Passengers & Ticket Numbers -->
  <div class="et-card">
    <div class="et-card-title" style="justify-content:space-between;">
      <span>🎫 Passengers & Ticket Numbers</span>
      <button type="button" onclick="addPax()" style="background:#ecfdf5;color:#065f46;border:1px solid #86efac;padding:5px 14px;border-radius:6px;font-size:12px;font-weight:700;cursor:pointer;">+ Add Passenger</button>
    </div>
    <p style="font-size:11px;color:#94a3b8;margin:0 0 16px;">Enter the airline-issued e-ticket number for each passenger. If unknown, leave blank and an internal reference will be auto-generated.</p>
    <div id="pax-rows"></div>
  </div>

  <!-- Flight Itinerary (from acceptance) -->
  <div class="et-card">
    <div class="et-card-title">🛫 Flight Itinerary</div>
    <div id="flight-display" style="background:#f8fafc;border:1px dashed #cbd5e1;border-radius:8px;padding:16px;text-align:center;color:#94a3b8;font-size:13px;">
      Load a transaction above to populate flight itinerary
    </div>
  </div>

  <!-- Ticket Conditions -->
  <div class="et-card">
    <div class="et-card-title">📋 Ticket Conditions</div>
    <div class="et-row et-row-2">
      <div>
        <label class="et-label" for="f-endorsements">Endorsements / Restrictions</label>
        <textarea class="et-input" id="f-endorsements" name="endorsements" rows="2" placeholder="e.g. NON END/NON REF/NON REROUTABLE"></textarea>
      </div>
      <div>
        <label class="et-label" for="f-baggage_info">Baggage Allowance</label>
        <textarea class="et-input" id="f-baggage_info" name="baggage_info" rows="2" placeholder="e.g. 1PC 23KG checked, 1 carry-on 7KG"></textarea>
      </div>
    </div>
    <div>
      <label class="et-label" for="f-fare_rules">Fare Rules (Exchange / Cancellation)</label>
      <textarea class="et-input" id="f-fare_rules" name="fare_rules" rows="3" placeholder="e.g. Changes: USD 250 penalty + fare difference. Cancellations: NON-REFUNDABLE."></textarea>
    </div>
  </div>

  <!-- Policy Text -->
  <div class="et-card">
    <div class="et-card-title">⚖️ Acknowledgment Policy</div>
    <p style="font-size:11px;color:#94a3b8;margin:0 0 10px;">This text will be displayed to the customer on the public acknowledgment page. Edit if needed.</p>
    <textarea class="et-input" id="f-policy_text" name="policy_text" rows="10"
              style="font-size:12px;line-height:1.7;font-family:'Segoe UI',sans-serif;"><?= htmlspecialchars($defaultPolicy) ?></textarea>
  </div>

  <!-- Agent Notes -->
  <div class="et-card">
    <div class="et-card-title">📝 Agent Notes (Internal)</div>
    <textarea class="et-input" id="f-agent_notes" name="agent_notes" rows="3" placeholder="Internal notes (not visible to customer)..."></textarea>
  </div>

  <!-- Actions -->
  <div style="display:flex;align-items:center;justify-content:flex-end;gap:12px;margin-top:24px;">
    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;color:#475569;margin-right:12px;">
      <input type="checkbox" name="send_now" value="1" id="send_now"
             style="width:16px;height:16px;accent-color:#1a3a6b;">
      Send e-ticket email immediately after saving
    </label>
    <a href="/etickets" style="padding:11px 22px;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;color:#64748b;text-decoration:none;font-weight:600;">Cancel</a>
    <button type="submit" id="btn-save"
            style="background:linear-gradient(135deg,#0f1e3c,#1a3a6b);color:#fff;border:none;padding:12px 32px;border-radius:8px;font-size:14px;font-weight:800;cursor:pointer;transition:opacity .15s;">
      💾 Save E-Ticket
    </button>
  </div>
</form>

<script>
// =========================================================================
// PAX ROWS
// =========================================================================
let paxIndex = 0;

function addPax(data = {}) {
    const i = paxIndex++;
    const paxName     = data.pax_name      || '';
    const paxType     = data.pax_type      || 'adult';
    const dob         = data.dob           || '';
    const ticketNo    = data.ticket_number || '';
    const seat        = data.seat          || '';
    const autofilled  = data._autofilled ? 'autofilled' : '';

    const row = document.createElement('div');
    row.className = 'pax-row';
    row.id = 'pax-row-' + i;
    row.innerHTML = `
      <button type="button" class="remove-pax" onclick="removePax(${i})" title="Remove passenger">✕</button>
      <div class="et-row et-row-4" style="margin-bottom:0;">
        <div>
          <label class="et-label">Passenger Name *</label>
          <input class="et-input ${autofilled}" name="pax_name[]" type="text" required value="${escHtml(paxName)}" placeholder="SMITH/JOHN">
        </div>
        <div>
          <label class="et-label">Type</label>
          <select class="et-input ${autofilled}" name="pax_type[]">
            <option value="adult"  ${paxType==='adult'  ?'selected':''}>Adult</option>
            <option value="child"  ${paxType==='child'  ?'selected':''}>Child</option>
            <option value="infant" ${paxType==='infant' ?'selected':''}>Infant</option>
          </select>
        </div>
        <div>
          <label class="et-label">E-Ticket Number *</label>
          <input class="et-input ${autofilled}" name="ticket_number[]" type="text" value="${escHtml(ticketNo)}" placeholder="0141234567890" style="font-family:monospace;font-size:12px;" oninput="this.value=this.value.replace(/[^0-9A-Za-z\\-]/g,'')">
        </div>
        <div>
          <label class="et-label">Seat</label>
          <input class="et-input ${autofilled}" name="seat[]" type="text" value="${escHtml(seat)}" placeholder="12A">
        </div>
      </div>
    `;
    document.getElementById('pax-rows').appendChild(row);
}

function removePax(i) {
    const el = document.getElementById('pax-row-' + i);
    if (el) el.remove();
}

function escHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// =========================================================================
// AUTOFILL
// =========================================================================
async function loadAutofill() {
    const txnId = document.getElementById('txn-selector').value;
    if (!txnId) { setMsg('Please select a transaction.', 'error'); return; }

    setMsg('Loading...', 'info');
    document.getElementById('btn-autofill').disabled = true;

    try {
        const res  = await fetch('/etickets/transaction-data/' + txnId);
        const json = await res.json();

        if (!json.success) {
            setMsg('⚠ ' + (json.error || 'Error loading data.'), 'error');
            document.getElementById('btn-autofill').disabled = false;
            return;
        }

        const d = json.data;

        // --- Hidden fields ---
        document.getElementById('f-transaction_id').value    = d.transaction_id || '';
        document.getElementById('f-flight_data_json').value  = JSON.stringify(d.flight_data || null);
        document.getElementById('f-fare_breakdown_json').value = JSON.stringify(d.fare_breakdown || []);
        document.getElementById('f-extra_data_json').value   = JSON.stringify(d.extra_data || null);

        // --- Customer fields ---
        fillField('f-customer_name',  d.customer_name);
        fillField('f-customer_email', d.customer_email);
        fillField('f-customer_phone', d.customer_phone);

        // --- Booking fields ---
        fillField('f-pnr',          d.pnr);
        fillField('f-airline',       d.airline);
        fillField('f-order_id',      d.order_id);
        fillField('f-total_amount',  d.total_amount);

        if (d.currency) {
            const sel = document.getElementById('f-currency');
            for (let opt of sel.options) { opt.selected = (opt.value === d.currency); }
        }

        // --- Conditions ---
        fillField('f-endorsements', d.endorsements);
        fillField('f-baggage_info', d.baggage_info);
        fillField('f-fare_rules',   d.fare_rules);

        // --- Passengers ---
        document.getElementById('pax-rows').innerHTML = '';
        paxIndex = 0;
        if (d.ticket_data && d.ticket_data.length > 0) {
            d.ticket_data.forEach(p => addPax({...p, _autofilled: true}));
        } else {
            addPax(); // at least one blank row
        }

        // --- Flight display ---
        renderFlightDisplay(d.flight_data);

        // --- Banner ---
        document.getElementById('autofill-banner').classList.add('show');
        setMsg('');

    } catch (err) {
        setMsg('⚠ Network error. Please try again.', 'error');
    }

    document.getElementById('btn-autofill').disabled = false;
}

function fillField(id, value) {
    const el = document.getElementById(id);
    if (!el) return;
    el.value = value || '';
    if (value) el.classList.add('autofilled');
}

function setMsg(msg, type = 'info') {
    const el = document.getElementById('autofill-msg');
    el.style.color = type === 'error' ? '#ef4444' : '#94a3b8';
    el.textContent = msg;
}

// =========================================================================
// FLIGHT DISPLAY
// =========================================================================
function renderFlightDisplay(flights) {
    const el = document.getElementById('flight-display');
    if (!flights || !flights.length) {
        el.innerHTML = '<span style="color:#94a3b8;font-size:13px;">No flight itinerary data available from this record.</span>';
        return;
    }

    let html = '';
    flights.forEach((f, idx) => {
        const dep = f.departure_airport || f.from || '???';
        const arr = f.arrival_airport   || f.to   || '???';
        const dt  = f.departure_date    || f.date || '';
        const tm  = f.departure_time    || f.time || '';
        const fn  = f.flight_number     || f.flight || '';
        const cab = f.cabin_class       || f.class  || '';
        html += `
        <div style="display:flex;align-items:center;gap:12px;padding:14px 0;${idx>0?'border-top:1px solid #e2e8f0;':''}">
          <div style="text-align:center;min-width:56px;">
            <div style="font-size:18px;font-weight:800;color:#0f1e3c;font-family:monospace;">${escHtml(dep)}</div>
            <div style="font-size:10px;color:#94a3b8;">${escHtml(dt)} ${escHtml(tm)}</div>
          </div>
          <div style="flex:1;text-align:center;">
            <div style="font-size:11px;color:#94a3b8;margin-bottom:2px;">${escHtml(fn)} ${cab?'· '+escHtml(cab):''}</div>
            <div style="border-top:2px solid #cbd5e1;position:relative;margin:4px 0;">
              <span style="position:absolute;top:-8px;left:50%;transform:translateX(-50%);font-size:14px;">✈</span>
            </div>
          </div>
          <div style="text-align:center;min-width:56px;">
            <div style="font-size:18px;font-weight:800;color:#0f1e3c;font-family:monospace;">${escHtml(arr)}</div>
            <div style="font-size:10px;color:#94a3b8;">${escHtml(f.arrival_date||'')} ${escHtml(f.arrival_time||'')}</div>
          </div>
        </div>`;
    });

    el.innerHTML = html;
}

// Init with at least one blank passenger row on load
window.addEventListener('DOMContentLoaded', () => {
    addPax();
});
</script>

<?php
$content = ob_get_clean();
require $layout;
