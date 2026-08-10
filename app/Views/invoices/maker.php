<?php
/**
 * Invoice Maker — editor (left) + live receipt-style preview (right).
 *
 * @var \App\Models\Invoice|null $invoice   When set, the maker is in EDIT mode.
 * @var string $role
 * @var bool   $canApprove
 */
use App\Models\Invoice;

$activePage = 'invoices';
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$isEdit  = isset($invoice) && $invoice !== null;
$postUrl = $isEdit ? '/invoices/' . $invoice->id . '/edit' : '/invoices/create';

// Field defaults (edit mode pulls from the invoice).
$d = [
    'invoice_no'       => $isEdit ? $invoice->invoice_no : '',
    'issue_date'       => $isEdit ? (string) $invoice->issue_date->format('Y-m-d') : date('Y-m-d'),
    'purpose'          => $isEdit ? $invoice->purpose : Invoice::PURPOSE_NEW_BOOKING,
    'purpose_other'    => $isEdit ? (string) $invoice->purpose_other : '',
    'customer_name'    => $isEdit ? (string) $invoice->customer_name : '',
    'customer_phone'   => $isEdit ? (string) $invoice->customer_phone : '',
    'customer_email'   => $isEdit ? (string) $invoice->customer_email : '',
    'pnr'              => $isEdit ? (string) $invoice->pnr : '',
    'airline'          => $isEdit ? (string) $invoice->airline : '',
    'cardholder_name'  => $isEdit ? (string) $invoice->cardholder_name : '',
    'card_billing'     => $isEdit ? (string) $invoice->card_billing : '',
    'currency'         => $isEdit ? $invoice->currency : 'USD',
    'airline_charge'   => $isEdit && $invoice->airline_charge !== null ? number_format($invoice->airline_charge, 2, '.', '') : '',
    'base_fare_charge' => $isEdit ? number_format($invoice->base_fare_charge, 2, '.', '') : '',
    'transaction_id'   => $isEdit ? (string) ($invoice->transaction_id ?? '') : '',
    'itinerary'        => $isEdit && is_array($invoice->itinerary) ? $invoice->itinerary : [],
];
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
<meta charset="utf-8"/>
<meta content="width=device-width,initial-scale=1.0" name="viewport"/>
<title><?= $isEdit ? 'Edit' : 'New' ?> Invoice — Base Fare CRM</title>
<!-- Inter 700/800 are required by the invoice document; see the note in view.php.
     Keep these weights in step with invoice_template.php. -->
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
<script src="https://cdn.tailwindcss.com?plugins=forms"></script>
<script>
tailwind.config={darkMode:"class",theme:{extend:{colors:{primary:"#163274","primary-container":"#314a8d",background:"#f8f9fa","on-surface":"#191c1d","on-surface-variant":"#434653"},fontFamily:{headline:["Manrope"],body:["Inter"]}}}};
</script>
<script src="/assets/js/html2pdf.bundle.min.js"></script>
<style>
.material-symbols-outlined{font-variation-settings:'FILL' 0,'wght' 400,'GRAD' 0,'opsz' 24}
#preview-wrapper { width: 760px; max-width: 100%; }
#invoice-printable { max-width: 100%; }
.fld { margin-top:.25rem; width:100%; border:1px solid #e2e8f0; border-radius:.5rem; padding:.5rem .75rem; font-size:.875rem; background:#f8fafc; }
.fld:focus { outline:none; box-shadow:0 0 0 2px rgba(22,50,116,.25); border-color:#163274; }
.flbl { font-size:10px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:.03em; }
</style>
</head>
<body class="bg-background font-body text-on-surface antialiased min-h-screen">

<?php require __DIR__.'/../layout/sidebar.php'; ?>

<main class="ml-60 pt-6 pb-20 px-8">

  <div class="flex items-center justify-between mb-6">
    <div>
      <h1 class="text-2xl font-headline font-extrabold text-primary tracking-tight flex items-center gap-2">
        <span class="material-symbols-outlined text-2xl">request_quote</span>
        <?= $isEdit ? 'Edit Invoice' : 'Invoice Generator' ?>
      </h1>
      <p class="text-sm text-on-surface-variant mt-0.5">
        <?= $isEdit
          ? 'Editing ' . $h($invoice->invoice_no) . ' — changes are saved without re-sending.'
          : 'Build a customer invoice, optionally prefilled from a booking.' ?>
      </p>
    </div>
    <div class="flex items-center gap-3">
      <a href="/invoices" class="inline-flex items-center gap-1.5 px-4 py-2.5 bg-white border border-slate-200 text-slate-600 font-bold rounded-xl hover:border-primary hover:text-primary transition-all text-sm shadow-sm">
        <span class="material-symbols-outlined text-base">arrow_back</span> Back
      </a>
      <button type="button" onclick="window.printInvoice()" title="Opens the print dialog — choose &quot;Save as PDF&quot; for a sharp, selectable document"
              class="inline-flex items-center gap-1.5 px-4 py-2.5 bg-white border border-slate-200 text-slate-600 font-bold rounded-xl hover:border-primary hover:text-primary transition-all text-sm shadow-sm">
        <span class="material-symbols-outlined text-base">print</span> Print / Save as PDF
      </button>
      <button type="button" onclick="downloadPdf()" title="Quick image-based download — lower quality, text not selectable"
              class="inline-flex items-center gap-1.5 px-4 py-2.5 bg-white border border-slate-200 text-slate-600 font-bold rounded-xl hover:border-primary hover:text-primary transition-all text-sm shadow-sm">
        <span class="material-symbols-outlined text-base">download</span> Quick PDF
      </button>
    </div>
  </div>

  <?php if (!empty($_SESSION['flash_error'])): ?>
  <div class="mb-4 px-4 py-3 bg-rose-50 border border-rose-200 text-rose-700 rounded-xl text-sm font-semibold">
    <?= $h($_SESSION['flash_error']) ?>
  </div>
  <?php unset($_SESSION['flash_error']); endif; ?>

  <form id="invForm" method="post" action="<?= $postUrl ?>">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>"/>
    <input type="hidden" name="action" id="f_action" value=""/>
    <input type="hidden" name="itinerary" id="f_itinerary" value=""/>
    <input type="hidden" name="transaction_id" id="f_txn" value="<?= $h($d['transaction_id']) ?>"/>

  <div class="grid grid-cols-1 xl:grid-cols-[420px_1fr] gap-6 items-start">

    <!-- ═══ EDITOR ═══ -->
    <div class="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden">

      <?php if (!$isEdit): ?>
      <!-- Prefill from booking -->
      <div class="px-5 py-4 border-b border-slate-100 bg-blue-50/40">
        <p class="text-[10px] font-bold uppercase tracking-widest text-slate-400 mb-2 flex items-center gap-1.5"><span class="material-symbols-outlined text-sm text-primary">auto_awesome</span> Prefill from Booking</p>
        <div class="flex items-end gap-2">
          <div class="flex-1">
            <label class="flbl">Linked booking (optional)</label>
            <select id="booking_select" class="fld"><option value="">— None / manual entry —</option></select>
          </div>
          <button type="button" onclick="loadBooking()" class="px-4 py-2 bg-primary text-white font-bold rounded-lg text-sm hover:bg-primary-container transition-colors shrink-0">Load</button>
        </div>
        <p class="text-[11px] text-slate-400 mt-1.5">Pulls customer, card holder, itinerary &amp; amounts. Everything stays editable.</p>
      </div>
      <?php endif; ?>

      <!-- General -->
      <div class="px-5 py-4 border-b border-slate-100">
        <p class="text-[10px] font-bold uppercase tracking-widest text-slate-400 mb-3 flex items-center gap-1.5"><span class="material-symbols-outlined text-sm text-primary">description</span> General</p>
        <div class="grid grid-cols-2 gap-2.5">
          <div><label class="flbl">Issue Date</label>
            <input id="f_issue" name="issue_date" type="date" value="<?= $h($d['issue_date']) ?>" oninput="render()" class="fld"/></div>
          <div><label class="flbl">Purpose of Charge</label>
            <select id="f_purpose" name="purpose" onchange="onPurpose()" class="fld">
              <?php foreach (Invoice::purposeOptions() as $val => $label): ?>
              <option value="<?= $val ?>" <?= $d['purpose'] === $val ? 'selected' : '' ?>><?= $h($label) ?></option>
              <?php endforeach; ?>
            </select></div>
          <div class="col-span-2 <?= $d['purpose'] === Invoice::PURPOSE_OTHER ? '' : 'hidden' ?>" id="wrap_purpose_other">
            <label class="flbl">Describe purpose</label>
            <input id="f_purpose_other" name="purpose_other" type="text" value="<?= $h($d['purpose_other']) ?>" oninput="render()" class="fld" placeholder="e.g. Baggage fee"/></div>
        </div>
      </div>

      <!-- Customer -->
      <div class="px-5 py-4 border-b border-slate-100">
        <p class="text-[10px] font-bold uppercase tracking-widest text-slate-400 mb-3 flex items-center gap-1.5"><span class="material-symbols-outlined text-sm text-primary">person</span> Customer</p>
        <div class="grid grid-cols-2 gap-2.5">
          <div class="col-span-2"><label class="flbl">Passenger Name(s)</label>
            <input id="f_pax" name="customer_name" type="text" value="<?= $h($d['customer_name']) ?>" oninput="render()" class="fld" placeholder="JOHN DOE"/></div>
          <div><label class="flbl">Phone</label>
            <input id="f_phone" name="customer_phone" type="text" value="<?= $h($d['customer_phone']) ?>" oninput="render()" class="fld"/></div>
          <div><label class="flbl">Email</label>
            <input id="f_email" name="customer_email" type="email" value="<?= $h($d['customer_email']) ?>" oninput="render()" class="fld" placeholder="name@email.com"/></div>
          <div><label class="flbl">PNR</label>
            <input id="f_pnr" name="pnr" type="text" value="<?= $h($d['pnr']) ?>" oninput="render()" class="fld uppercase"/></div>
          <div><label class="flbl">Airline</label>
            <input id="f_airline" name="airline" type="text" value="<?= $h($d['airline']) ?>" oninput="render()" class="fld"/></div>
        </div>
      </div>

      <!-- Payment card (holder + billing only) -->
      <div class="px-5 py-4 border-b border-slate-100">
        <p class="text-[10px] font-bold uppercase tracking-widest text-slate-400 mb-3 flex items-center gap-1.5"><span class="material-symbols-outlined text-sm text-primary">credit_card</span> Payment Card</p>
        <div class="grid grid-cols-1 gap-2.5">
          <div><label class="flbl">Cardholder Name (CCH)</label>
            <input id="f_cch" name="cardholder_name" type="text" value="<?= $h($d['cardholder_name']) ?>" oninput="render()" class="fld"/></div>
          <div><label class="flbl">Card Billing Address</label>
            <textarea id="f_billing" name="card_billing" rows="2" oninput="render()" class="fld resize-none"><?= $h($d['card_billing']) ?></textarea></div>
        </div>
        <p class="text-[11px] text-slate-400 mt-1.5">Card number &amp; CVV are never used on invoices.</p>
      </div>

      <!-- Itinerary -->
      <div class="px-5 py-4 border-b border-slate-100">
        <div class="flex items-center justify-between mb-3">
          <p class="text-[10px] font-bold uppercase tracking-widest text-slate-400 flex items-center gap-1.5"><span class="material-symbols-outlined text-sm text-primary">flight</span> Itinerary</p>
          <button type="button" onclick="addSeg()" class="text-[11px] font-bold text-primary hover:underline flex items-center gap-1"><span class="material-symbols-outlined text-sm">add</span> Add segment</button>
        </div>
        <div id="seg_rows" class="space-y-2"></div>
        <p class="text-[11px] text-slate-400 mt-2" id="seg_empty">No flight segments. Add one or load a booking.</p>
      </div>

      <!-- Charges -->
      <div class="px-5 py-4 bg-blue-50/30">
        <p class="text-[10px] font-bold uppercase tracking-widest text-slate-400 mb-3 flex items-center gap-1.5"><span class="material-symbols-outlined text-sm text-primary">payments</span> Charges</p>
        <div class="grid grid-cols-2 gap-2.5">
          <div><label class="flbl">Currency</label>
            <select id="f_currency" name="currency" onchange="render()" class="fld bg-white">
              <?php foreach (['USD','CAD','GBP','EUR'] as $c): ?>
              <option value="<?= $c ?>" <?= $d['currency'] === $c ? 'selected' : '' ?>><?= $c ?></option>
              <?php endforeach; ?>
            </select></div>
          <div class="flex items-end pb-1">
            <label class="inline-flex items-center gap-2 text-sm font-semibold text-slate-600 cursor-pointer">
              <input type="checkbox" id="f_has_airline" onchange="toggleAirline()" class="rounded border-slate-300 text-primary focus:ring-primary" <?= $d['airline_charge'] !== '' ? 'checked' : '' ?>/>
              Include airline charge
            </label>
          </div>
          <div id="wrap_airline" class="<?= $d['airline_charge'] !== '' ? '' : 'hidden' ?>">
            <label class="flbl">Airline Charge</label>
            <input id="f_airline_charge" name="airline_charge" type="number" step="0.01" min="0" value="<?= $h($d['airline_charge']) ?>" oninput="render()" class="fld bg-white"/></div>
          <div><label class="flbl">Base Fare Charge</label>
            <input id="f_base_charge" name="base_fare_charge" type="number" step="0.01" min="0" value="<?= $h($d['base_fare_charge']) ?>" oninput="render()" class="fld bg-white"/></div>
          <div class="col-span-2 mt-1 flex items-center justify-between bg-primary/5 border border-primary/20 rounded-lg px-4 py-2.5">
            <span class="text-xs font-bold uppercase tracking-wide text-primary">Total Amount</span>
            <span class="text-xl font-extrabold text-primary" id="f_total_disp">USD 0.00</span>
          </div>
        </div>
      </div>

      <!-- Actions -->
      <div class="px-5 py-4 border-t border-slate-100 flex flex-col gap-2">
        <?php if ($isEdit): ?>
          <button type="button" onclick="submitForm('')" class="w-full inline-flex items-center justify-center gap-1.5 px-5 py-3 bg-primary text-white font-bold rounded-xl hover:bg-primary-container shadow-lg shadow-primary/20 transition-all text-sm">
            <span class="material-symbols-outlined text-base">save</span> Save Changes
          </button>
        <?php elseif ($canApprove): ?>
          <button type="button" onclick="submitForm('send')" class="w-full inline-flex items-center justify-center gap-1.5 px-5 py-3 bg-emerald-600 text-white font-bold rounded-xl hover:bg-emerald-700 shadow-lg shadow-emerald-600/20 transition-all text-sm">
            <span class="material-symbols-outlined text-base">send</span> Save &amp; Send to Customer
          </button>
          <button type="button" onclick="submitForm('save')" class="w-full inline-flex items-center justify-center gap-1.5 px-5 py-2.5 bg-white border border-slate-200 text-slate-600 font-bold rounded-xl hover:border-primary hover:text-primary transition-all text-sm">
            <span class="material-symbols-outlined text-base">save</span> Save without Sending
          </button>
        <?php else: ?>
          <button type="button" onclick="submitForm('submit')" class="w-full inline-flex items-center justify-center gap-1.5 px-5 py-3 bg-primary text-white font-bold rounded-xl hover:bg-primary-container shadow-lg shadow-primary/20 transition-all text-sm">
            <span class="material-symbols-outlined text-base">forward_to_inbox</span> Submit for Approval
          </button>
          <p class="text-[11px] text-slate-400 text-center">A manager or admin will review and send this invoice.</p>
        <?php endif; ?>
      </div>

    </div>

    <!-- ═══ PREVIEW ═══ -->
    <div class="sticky top-6">
      <div class="bg-white border border-slate-200 rounded-2xl shadow-sm p-5 overflow-x-auto">
        <p class="text-[10px] font-bold uppercase tracking-widest text-slate-400 flex items-center gap-1.5 mb-4">
          <span class="material-symbols-outlined text-sm text-primary">visibility</span> Live Preview
        </p>
        <div id="preview-wrapper">
          <?php include __DIR__ . '/invoice_template.php'; ?>
        </div>
      </div>
    </div>

  </div>
  </form>
</main>

<script>
<?php $JS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT; ?>
const PURPOSE_LABELS = <?= json_encode(Invoice::purposeOptions(), $JS) ?>;
const INVOICE_NO = <?= json_encode($d['invoice_no'], $JS) ?>;
let SEGMENTS = <?= json_encode(array_values($d['itinerary']), $JS) ?>;

function segRowHtml(i, s) {
    s = s || {};
    const v = k => (s[k] == null ? '' : String(s[k]).replace(/"/g, '&quot;'));
    return `<div class="grid grid-cols-12 gap-1.5 items-center" data-seg="${i}">
      <input class="fld col-span-2 !mt-0 uppercase" placeholder="FROM" value="${v('from')}" data-k="from" oninput="syncSegs()"/>
      <input class="fld col-span-2 !mt-0 uppercase" placeholder="TO" value="${v('to')}" data-k="to" oninput="syncSegs()"/>
      <input class="fld col-span-3 !mt-0" placeholder="Flight (AC 123)" value="${v('flight')}" data-k="flight" oninput="syncSegs()"/>
      <input class="fld col-span-2 !mt-0" placeholder="Date" value="${v('date')}" data-k="date" oninput="syncSegs()"/>
      <input class="fld col-span-1 !mt-0" placeholder="Dep" value="${v('dep')}" data-k="dep" oninput="syncSegs()"/>
      <div class="col-span-2 flex gap-1">
        <input class="fld flex-1 !mt-0" placeholder="Arr" value="${v('arr')}" data-k="arr" oninput="syncSegs()"/>
        <button type="button" onclick="removeSeg(${i})" class="text-slate-300 hover:text-rose-500 shrink-0"><span class="material-symbols-outlined text-lg">close</span></button>
      </div>
      <input type="hidden" data-k="cabin" value="${v('cabin')}"/>
    </div>`;
}

function paintSegs() {
    const wrap = document.getElementById('seg_rows');
    wrap.innerHTML = SEGMENTS.map((s, i) => segRowHtml(i, s)).join('');
    document.getElementById('seg_empty').style.display = SEGMENTS.length ? 'none' : '';
}
function addSeg() { SEGMENTS.push({from:'',to:'',flight:'',date:'',dep:'',arr:'',cabin:''}); paintSegs(); render(); }
function removeSeg(i) { SEGMENTS.splice(i, 1); paintSegs(); render(); }
function syncSegs() {
    document.querySelectorAll('#seg_rows [data-seg]').forEach(row => {
        const i = +row.dataset.seg;
        SEGMENTS[i] = SEGMENTS[i] || {};
        row.querySelectorAll('[data-k]').forEach(inp => { SEGMENTS[i][inp.dataset.k] = inp.value; });
    });
    render();
}

function val(id) { const el = document.getElementById(id); return el ? el.value.trim() : ''; }

function collect() {
    const purpose = val('f_purpose');
    return {
        invoice_no:       INVOICE_NO || 'INV-XXXXXX',
        issue_date:       val('f_issue'),
        purpose_label:    purpose === 'other' ? (val('f_purpose_other') || 'Other') : (PURPOSE_LABELS[purpose] || ''),
        customer_name:    val('f_pax'),
        customer_phone:   val('f_phone'),
        customer_email:   val('f_email'),
        pnr:              val('f_pnr'),
        airline:          val('f_airline'),
        cardholder_name:  val('f_cch'),
        card_billing:     val('f_billing'),
        currency:         val('f_currency'),
        airline_charge:   document.getElementById('f_has_airline').checked ? val('f_airline_charge') : '',
        base_fare_charge: val('f_base_charge'),
        itinerary:        SEGMENTS,
    };
}

function render() {
    const d = collect();
    window.renderInvoice(d);
    const total = (d.airline_charge !== '' ? parseFloat(d.airline_charge) || 0 : 0) + (parseFloat(d.base_fare_charge) || 0);
    document.getElementById('f_total_disp').textContent = (d.currency || 'USD') + ' ' + total.toFixed(2);
}

function onPurpose() {
    document.getElementById('wrap_purpose_other').classList.toggle('hidden', val('f_purpose') !== 'other');
    render();
}
function toggleAirline() {
    const on = document.getElementById('f_has_airline').checked;
    document.getElementById('wrap_airline').classList.toggle('hidden', !on);
    if (!on) document.getElementById('f_airline_charge').value = '';
    render();
}

async function loadBooking() {
    const id = document.getElementById('booking_select').value;
    if (!id) return;
    try {
        const res = await fetch('/invoices/transaction-data/' + id);
        const json = await res.json();
        if (!json.success) { alert(json.error || 'Could not load booking.'); return; }
        const x = json.data;
        document.getElementById('f_txn').value = x.transaction_id || '';
        const setv = (id, v) => { const el = document.getElementById(id); if (el) el.value = v || ''; };
        setv('f_pax', x.customer_name); setv('f_phone', x.customer_phone); setv('f_email', x.customer_email);
        setv('f_pnr', x.pnr); setv('f_airline', x.airline);
        setv('f_cch', x.cardholder_name); setv('f_billing', x.card_billing);
        setv('f_base_charge', x.base_fare_charge);
        if (x.currency) document.getElementById('f_currency').value = x.currency;
        // purpose
        if (x.purpose) document.getElementById('f_purpose').value = x.purpose;
        onPurpose();
        // airline charge
        const hasAir = x.airline_charge !== '' && x.airline_charge != null;
        document.getElementById('f_has_airline').checked = hasAir;
        setv('f_airline_charge', x.airline_charge);
        toggleAirline();
        // itinerary
        SEGMENTS = Array.isArray(x.itinerary) ? x.itinerary : [];
        paintSegs();
        render();
    } catch (e) { alert('Failed to load booking.'); }
}

async function loadBookingOptions() {
    const sel = document.getElementById('booking_select');
    if (!sel) return;
    try {
        const res = await fetch('/invoices/booking-options');
        const json = await res.json();
        if (json.success) {
            json.options.forEach(o => {
                const opt = document.createElement('option');
                opt.value = o.id; opt.textContent = o.label;
                sel.appendChild(opt);
            });
        }
    } catch (e) {}
}

function submitForm(action) {
    const pax = val('f_pax');
    if (!pax) { alert('Passenger name is required.'); return; }
    if (action === 'send' && !val('f_email')) { alert('A customer email is required to send the invoice.'); return; }
    syncSegs();
    document.getElementById('f_action').value = action;
    document.getElementById('f_itinerary').value = JSON.stringify(SEGMENTS);
    document.getElementById('invForm').submit();
}

async function downloadPdf() {
    render();
    const fname = 'Invoice_' + (INVOICE_NO || 'draft') + '_' + (val('f_pax') || 'customer').replace(/\s+/g, '_') + '.pdf';
    await window.captureInvoicePdf(fname);
}

document.addEventListener('DOMContentLoaded', () => {
    <?php if (!$isEdit): ?>loadBookingOptions();<?php endif; ?>
    paintSegs();
    onPurpose();
    render();
});
</script>
</body>
</html>
