<?php
$activePage = 'vouchers';
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
<meta charset="utf-8"/>
<meta content="width=device-width,initial-scale=1.0" name="viewport"/>
<title>Travel Voucher Maker — Base Fare CRM</title>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
<script src="https://cdn.tailwindcss.com?plugins=forms"></script>
<script>
tailwind.config={darkMode:"class",theme:{extend:{colors:{primary:"#163274","primary-container":"#314a8d",background:"#f8f9fa","on-surface":"#191c1d","on-surface-variant":"#434653"},fontFamily:{headline:["Manrope"],body:["Inter"]}}}};
</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>
<style>
.material-symbols-outlined{font-variation-settings:'FILL' 0,'wght' 400,'GRAD' 0,'opsz' 24}

/* ─── VOUCHER SHELL ─────────────────────────────────────────────────── */
/* Fixed A4-landscape slice: 297mm × 105mm ≈ 842×298px @96dpi
   We build at 2× (1684×596) then scale down for preview */
#voucher-printable {
    font-family: 'Inter', Arial, sans-serif;
    width: 1122px;          /* 297mm @96dpi × ~1.2 */
    height: 398px;          /* 105mm @96dpi × ~1.2 */
    background: #d9ecf7;
    display: flex;
    overflow: hidden;
    position: relative;
    box-sizing: border-box;
    color: #1e293b;
    flex-shrink: 0;
}

/* ─── CLOUD BG ─── */
.vc-bg {
    position: absolute;
    inset: 0;
    background: linear-gradient(170deg,#cce4f6 0%,#ddeefa 40%,#eaf4fb 100%);
    z-index: 0;
}

/* ─── MAIN BODY (left 3/4) ─── */
.vc-main {
    flex: 1;
    display: flex;
    flex-direction: column;
    position: relative;
    z-index: 1;
    border-right: 3px dashed #a0c4dc;
}

/* ─── STUB (right 1/4) ─── */
.vc-stub {
    width: 248px;
    flex-shrink: 0;
    display: flex;
    flex-direction: column;
    position: relative;
    z-index: 1;
    background: rgba(255,255,255,0.25);
}

/* ─── HEADER ─── */
.vc-header {
    background: linear-gradient(90deg,#163274 0%,#2754a8 100%);
    color: #fff;
    padding: 10px 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    font-size: 18px;
    font-weight: 800;
    letter-spacing: 0.06em;
    text-transform: uppercase;
}
.vc-header-stub {
    background: linear-gradient(90deg,#163274 0%,#2754a8 100%);
    color: #fff;
    padding: 10px 16px;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.vc-header-stub .big { font-size: 18px; }

/* ─── BODY ROW ─── */
.vc-body {
    display: flex;
    flex: 1;
    padding: 10px 16px;
    gap: 14px;
    min-height: 0;
}

/* ─── LEFT COL ─── */
.vc-left {
    width: 210px;
    flex-shrink: 0;
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.vc-issuer { font-size: 11px; line-height: 1.5; }
.vc-issuer strong { font-size: 12px; font-weight: 800; color: #163274; }

.vc-pax-name {
    font-size: 22px;
    font-weight: 800;
    color: #1e293b;
    text-transform: uppercase;
    line-height: 1.1;
}
.vc-field { display: flex; flex-direction: column; gap: 1px; }
.vc-label { font-size: 8px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: #64748b; }
.vc-val { font-size: 11px; font-weight: 600; color: #1e293b; }

.vc-sig-area { margin-top: auto; border-top: 1px solid #94a3b8; padding-top: 5px; }
.vc-sig-line { font-size: 9px; font-weight: 700; text-transform: uppercase; color: #475569; }

/* ─── MIDDLE COL ─── */
.vc-mid {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 8px;
    min-width: 0;
}
.vc-top-meta {
    display: flex;
    justify-content: flex-end;
    gap: 24px;
}
.vc-meta-item { display: flex; flex-direction: column; align-items: flex-end; gap: 1px; }

.vc-amount-box {
    background: #fff;
    border: 1px solid #bcd6ee;
    border-radius: 6px;
    padding: 8px 12px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
}
.vc-amount-label { font-size: 9px; font-weight: 700; text-transform: uppercase; color: #163274; }
.vc-amount-val { font-size: 26px; font-weight: 800; color: #163274; }

.vc-validity-row { display: flex; gap: 24px; padding: 0 4px; }

/* ─── TERMS BOX ─── */
.vc-terms-box {
    background: rgba(255,255,255,0.7);
    border: 1px solid #bcd6ee;
    border-radius: 6px;
    padding: 8px 10px;
    flex: 1;
    min-height: 0;
    overflow: hidden;
}
.vc-terms-title { font-size: 9px; font-weight: 800; color: #163274; text-transform: uppercase; margin-bottom: 4px; }
.vc-terms-text { font-size: 8px; color: #334155; line-height: 1.55; white-space: pre-wrap; }

/* ─── STUB BODY ─── */
.vc-stub-body {
    padding: 10px 16px;
    display: flex;
    flex-direction: column;
    gap: 10px;
    flex: 1;
}
.vc-stub-field { display: flex; flex-direction: column; gap: 2px; }
.vc-stub-label { font-size: 8px; font-weight: 700; text-transform: uppercase; color: #64748b; letter-spacing: 0.05em; }
.vc-stub-val { font-size: 13px; font-weight: 800; color: #163274; text-transform: uppercase; }
.vc-stub-val-sm { font-size: 11px; font-weight: 700; color: #1e293b; }

.vc-barcode-wrap { margin-top: auto; text-align: center; }
.vc-barcode-wrap svg { width: 100%; height: 40px; }
.vc-barcode-num { font-size: 9px; font-family: monospace; letter-spacing: 0.15em; margin-top: 3px; color: #334155; }

.vc-brand-tag {
    text-align: center;
    border-top: 2px dashed #a0c4dc;
    padding-top: 8px;
    margin-top: 8px;
}
.vc-brand-name { font-size: 10px; font-weight: 800; color: #163274; text-transform: uppercase; letter-spacing: 0.1em; }
.vc-brand-sub { font-size: 8px; color: #64748b; text-transform: uppercase; letter-spacing: 0.08em; }

/* ─── PREVIEW WRAPPER ─── */
#preview-wrapper {
    transform-origin: top left;
    transform: scale(0.72);
    width: 1122px;
    height: 398px;
    margin-bottom: calc((398px * 0.72) - 398px);
}
</style>
</head>
<body class="bg-background font-body text-on-surface antialiased min-h-screen">

<?php require __DIR__.'/../partials/admin_sidebar.php'; ?>

<main class="ml-60 pt-6 pb-20 px-8">

  <div class="flex items-center justify-between mb-6">
    <div>
      <h1 class="text-2xl font-headline font-extrabold text-primary tracking-tight flex items-center gap-2">
        <span class="material-symbols-outlined text-2xl">card_travel</span>
        Travel Voucher Creator
      </h1>
      <p class="text-sm text-on-surface-variant mt-0.5">Generate, save, and download flight credit vouchers.</p>
    </div>
    <div class="flex items-center gap-3">
      <button onclick="resetForm()" class="inline-flex items-center gap-1.5 px-4 py-2.5 bg-white border border-slate-200 text-slate-600 font-bold rounded-xl hover:border-primary hover:text-primary transition-all text-sm shadow-sm">
        <span class="material-symbols-outlined text-base">restart_alt</span> Reset
      </button>
      <button id="btn-save" onclick="saveAndDownload()" class="inline-flex items-center gap-1.5 px-5 py-2.5 bg-emerald-600 text-white font-bold rounded-xl hover:bg-emerald-700 shadow-lg shadow-emerald-600/20 transition-all text-sm">
        <span class="material-symbols-outlined text-base">save</span> Save & Download PDF
      </button>
    </div>
  </div>

  <div class="grid grid-cols-1 xl:grid-cols-[380px_1fr] gap-6 items-start">

    <!-- ═══ EDITOR ═══ -->
    <div class="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden">

      <div class="px-5 py-4 border-b border-slate-100">
        <p class="text-[10px] font-bold uppercase tracking-widest text-slate-400 mb-3 flex items-center gap-1.5"><span class="material-symbols-outlined text-sm text-primary">info</span> General Details</p>
        <div class="grid grid-cols-2 gap-2.5">
          <div><label class="text-[10px] font-semibold text-slate-500 uppercase tracking-wide">Voucher No.</label>
            <input id="v_no" type="text" value="VCH-<?= strtoupper(substr(uniqid(), -6)) ?>" oninput="render()" class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-slate-50 font-mono focus:ring-2 focus:ring-primary/30 focus:border-primary"/></div>
          <div><label class="text-[10px] font-semibold text-slate-500 uppercase tracking-wide">Reason</label>
            <select id="v_reason" onchange="render()" class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-slate-50 focus:ring-2 focus:ring-primary/30 focus:border-primary">
              <option value="CANCELLATION">Cancellation</option>
              <option value="FLIGHT DELAY">Flight Delay</option>
              <option value="CUSTOMER SERVICE">Customer Service</option>
              <option value="PROMOTIONAL">Promotional</option>
            </select></div>
          <div><label class="text-[10px] font-semibold text-slate-500 uppercase tracking-wide">Date of Issue</label>
            <input id="v_issue" type="date" value="<?= date('Y-m-d') ?>" oninput="render()" class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-slate-50 focus:ring-2 focus:ring-primary/30 focus:border-primary"/></div>
          <div><label class="text-[10px] font-semibold text-slate-500 uppercase tracking-wide">Valid Until</label>
            <input id="v_expiry" type="date" value="<?= date('Y-m-d', strtotime('+1 year')) ?>" oninput="render()" class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-slate-50 focus:ring-2 focus:ring-primary/30 focus:border-primary"/></div>
        </div>
      </div>

      <div class="px-5 py-4 border-b border-slate-100">
        <p class="text-[10px] font-bold uppercase tracking-widest text-slate-400 mb-3 flex items-center gap-1.5"><span class="material-symbols-outlined text-sm text-primary">person</span> Passenger Details</p>
        <div class="grid grid-cols-1 gap-2.5">
          <div><label class="text-[10px] font-semibold text-slate-500 uppercase tracking-wide">Passenger Name</label>
            <input id="v_name" type="text" placeholder="JOHN DOE" oninput="render()" class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-slate-50 focus:ring-2 focus:ring-primary/30 focus:border-primary uppercase"/></div>
          <div class="grid grid-cols-2 gap-2.5">
            <div><label class="text-[10px] font-semibold text-slate-500 uppercase tracking-wide">Booking Ref (PNR)</label>
              <input id="v_pnr" type="text" placeholder="ABC123" oninput="render()" class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-slate-50 focus:ring-2 focus:ring-primary/30 focus:border-primary uppercase"/></div>
            <div><label class="text-[10px] font-semibold text-slate-500 uppercase tracking-wide">Original Ticket No.</label>
              <input id="v_ticket" type="text" placeholder="1234567890" oninput="render()" class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-slate-50 focus:ring-2 focus:ring-primary/30 focus:border-primary"/></div>
          </div>
        </div>
      </div>

      <div class="px-5 py-4 border-b border-slate-100 bg-blue-50/30">
        <p class="text-[10px] font-bold uppercase tracking-widest text-slate-400 mb-3 flex items-center gap-1.5"><span class="material-symbols-outlined text-sm text-primary">payments</span> Value</p>
        <div class="grid grid-cols-[100px_1fr] gap-2.5">
          <div><label class="text-[10px] font-semibold text-slate-500 uppercase tracking-wide">Currency</label>
            <select id="v_currency" onchange="render()" class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-white focus:ring-2 focus:ring-primary/30 focus:border-primary">
              <option value="USD">USD</option><option value="CAD">CAD</option><option value="GBP">GBP</option><option value="EUR">EUR</option>
            </select></div>
          <div><label class="text-[10px] font-semibold text-slate-500 uppercase tracking-wide">Amount</label>
            <input id="v_amount" type="number" step="0.01" placeholder="500.00" oninput="render()" class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-lg font-bold text-primary bg-white focus:ring-2 focus:ring-primary/30 focus:border-primary"/></div>
        </div>
      </div>

      <div class="px-5 py-4">
        <p class="text-[10px] font-bold uppercase tracking-widest text-slate-400 mb-3 flex items-center gap-1.5"><span class="material-symbols-outlined text-sm text-primary">gavel</span> Terms & Conditions</p>
        <textarea id="v_terms" rows="7" oninput="render()" class="w-full border border-slate-200 rounded-lg px-3 py-2 text-[11px] bg-slate-50 focus:ring-2 focus:ring-primary/30 focus:border-primary resize-none">1. The voucher is non-refundable and non-transferable.
2. Valid for new flight bookings made through Base Fare only.
3. Must be redeemed before expiry; no extension allowed.
4. If new booking exceeds the voucher value, difference must be paid by the passenger.
5. If the new booking is lower, the remaining balance will not be refunded.
6. No cash value; cannot be exchanged for cash.</textarea>
      </div>

    </div>

    <!-- ═══ PREVIEW (scaled for display) ═══ -->
    <div class="sticky top-6">
      <div class="bg-white border border-slate-200 rounded-2xl shadow-sm p-5 overflow-x-auto">
        <p class="text-[10px] font-bold uppercase tracking-widest text-slate-400 flex items-center gap-1.5 mb-4">
          <span class="material-symbols-outlined text-sm text-primary">visibility</span> Live Preview
        </p>
        <div id="preview-wrapper">
          <?php include __DIR__ . '/voucher_template.php'; ?>
        </div>
      </div>
    </div>

  </div>
</main>


<script>
const fld = id => document.getElementById(id)?.value?.trim() ?? '';
const setText = (id, val) => { const el = document.getElementById(id); if(el) el.textContent = val; };

const fmtDate = dStr => {
    if (!dStr) return '';
    const d = new Date(dStr + 'T00:00:00');
    return d.toLocaleDateString('en-GB', { day:'2-digit', month:'short', year:'numeric' }).toUpperCase();
};

function render() {
    const vno    = fld('v_no')       || 'VCH-XXXXXX';
    const reason = fld('v_reason')   || 'CANCELLATION';
    const issue  = fmtDate(fld('v_issue'));
    const expiry = fmtDate(fld('v_expiry'));
    const name   = (fld('v_name')   || 'JOHN DOE').toUpperCase();
    const pnr    = fld('v_pnr')     || '—';
    const ticket = fld('v_ticket')  || '—';
    const curr   = fld('v_currency') || 'USD';
    const amt    = parseFloat(fld('v_amount') || 0).toFixed(2);
    const amtStr = `${curr} ${amt}`;
    const terms  = fld('v_terms');

    setText('p_vno',    vno);
    setText('p_issue',  issue);
    setText('p_name',   name);
    setText('p_pnr',    pnr);
    setText('p_ticket', ticket);
    setText('p_amt',    amtStr);
    setText('p_expiry', expiry);
    setText('p_reason', reason);
    setText('p_terms',  terms);
    // stub
    setText('s_vno',    vno);
    setText('s_expiry', expiry);
    setText('s_name',   name);
    setText('s_amt',    amtStr);
    setText('s_reason', reason);
    setText('s_vno_bc', vno);

    try {
        JsBarcode('#barcode', vno, { format:'CODE128', lineColor:'#1e293b', width:1.2, height:36, displayValue:false, margin:0 });
    } catch(e) {}
}

async function saveAndDownload() {
    const btn  = document.getElementById('btn-save');
    const name = fld('v_name');
    const amt  = fld('v_amount');

    if (!name || !amt || parseFloat(amt) <= 0) {
        alert('Please enter a valid Passenger Name and Amount before saving.');
        return;
    }

    btn.disabled = true;
    btn.innerHTML = '<span class="material-symbols-outlined text-base animate-spin">refresh</span> Saving...';

    const payload = {
        voucher_no:    fld('v_no'),
        customer_name: name,
        pnr:           fld('v_pnr'),
        ticket_number: fld('v_ticket'),
        amount:        amt,
        currency:      fld('v_currency'),
        issue_date:    fld('v_issue'),
        expiry_date:   fld('v_expiry'),
        reason:        fld('v_reason'),
        terms:         fld('v_terms')
    };

    try {
        const res  = await fetch('/vouchers', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': '<?= $_SESSION['csrf_token'] ?? '' ?>'
            },
            body: JSON.stringify(payload)
        });
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Failed to save');

        btn.innerHTML = '<span class="material-symbols-outlined text-base">download</span> Downloading PDF...';

        // Target the real voucher element directly — html2canvas captures at true layout size
        // (the CSS transform on the wrapper is ignored by html2canvas)
        const printEl  = document.getElementById('voucher-printable');
        const filename = `TravelVoucher_${payload.voucher_no}_${payload.customer_name.replace(/\s+/g,'_')}.pdf`;

        await html2pdf().set({
            margin:      0,
            filename:    filename,
            image:       { type: 'jpeg', quality: 1.0 },
            html2canvas: { scale: 2, useCORS: true, logging: false, width: 1122, height: 398, scrollX: 0, scrollY: -window.scrollY },
            jsPDF:       { unit: 'mm', format: [297, 105.5], orientation: 'landscape' }
        }).from(printEl).save();

        window.location.href = '/vouchers';

    } catch (err) {
        alert(err.message);
        btn.disabled = false;
        btn.innerHTML = '<span class="material-symbols-outlined text-base">save</span> Save & Download PDF';
    }
}

function resetForm() {
    if (!confirm('Reset form?')) return;
    document.getElementById('v_no').value     = 'VCH-' + Math.random().toString(36).substr(2,6).toUpperCase();
    document.getElementById('v_name').value   = '';
    document.getElementById('v_pnr').value    = '';
    document.getElementById('v_ticket').value = '';
    document.getElementById('v_amount').value = '';
    render();
}

document.addEventListener('DOMContentLoaded', render);
</script>
</body>
</html>
