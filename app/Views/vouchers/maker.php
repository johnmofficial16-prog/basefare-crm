<?php
$activePage = 'vouchers';
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
<meta charset="utf-8"/>
<meta content="width=device-width,initial-scale=1.0" name="viewport"/>
<title>Travel Voucher Maker — Base Fare CRM</title>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&family=Inter:wght@300;400;500;600&family=Noto+Sans:wght@400;600;700;800&family=Dancing+Script:wght@600;700&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
<script src="https://cdn.tailwindcss.com?plugins=forms"></script>
<script>
tailwind.config={darkMode:"class",theme:{extend:{colors:{primary:"#163274","primary-container":"#314a8d",background:"#f8f9fa","surface-container-low":"#f3f4f5","on-surface":"#191c1d","on-surface-variant":"#434653"},fontFamily:{headline:["Manrope"],body:["Inter"],label:["Inter"]}}}};
</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>
<style>
.material-symbols-outlined{font-variation-settings:'FILL' 0,'wght' 400,'GRAD' 0,'opsz' 24}

/* Voucher PDF Styling */
#voucher-inner {
    width: 800px;
    height: 380px;
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.08);
    overflow: hidden;
    display: flex;
    font-family: 'Noto Sans', sans-serif;
    color: #1e293b;
    position: relative;
    box-sizing: border-box;
}

/* Background Clouds/Texture */
.voucher-bg {
    position: absolute;
    top: 0; left: 0; right: 0; bottom: 0;
    background: linear-gradient(to bottom, #f0f7fd 0%, #e0eff9 100%);
    opacity: 0.6;
    z-index: 0;
}

.voucher-left {
    flex: 1;
    display: flex;
    flex-direction: column;
    z-index: 1;
    border-right: 2px dashed #cbd5e1;
}

.voucher-right {
    width: 250px;
    display: flex;
    flex-direction: column;
    z-index: 1;
    background: rgba(255,255,255,0.4);
}

/* Header */
.v-header {
    background: linear-gradient(135deg, #163274 0%, #314a8d 100%);
    color: #fff;
    padding: 16px 24px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.v-header-right {
    background: transparent;
    color: #163274;
    padding: 16px 20px;
    border-bottom: 2px solid #e2e8f0;
}

.v-title { font-size: 20px; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; display: flex; items-center; gap: 8px;}
.v-title-small { font-size: 14px; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; }

/* Content Areas */
.v-content { padding: 20px 24px; flex: 1; display: flex; flex-direction: column; gap: 16px; }
.v-content-right { padding: 20px; flex: 1; display: flex; flex-direction: column; gap: 16px; }

.v-row { display: flex; justify-content: space-between; gap: 20px; }
.v-col { display: flex; flex-direction: column; gap: 4px; }

.v-label { font-size: 9px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; }
.v-val { font-size: 13px; font-weight: 600; color: #1e293b; }
.v-val-large { font-size: 22px; font-weight: 800; color: #163274; text-transform: uppercase; }
.v-val-xl { font-size: 26px; font-weight: 800; color: #163274; }

.v-box {
    background: rgba(255,255,255,0.7);
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 12px 16px;
}

.v-box-blue {
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    border-radius: 8px;
    padding: 12px 16px;
}

.v-terms {
    font-size: 9px;
    color: #475569;
    line-height: 1.5;
}

.v-terms-title { font-size: 10px; font-weight: 800; color: #163274; margin-bottom: 4px; text-transform: uppercase; }

.v-footer {
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    margin-top: auto;
}

.v-sig-block { text-align: center; width: 140px; }
.v-sig-line { border-top: 1px solid #94a3b8; margin-top: 30px; padding-top: 4px; font-size: 9px; font-weight: 600; color: #64748b; text-transform: uppercase; }

/* Right Panel specifics */
.v-barcode-container { text-align: center; margin-top: auto; }
.v-barcode-container svg { max-width: 100%; height: 50px; }
.v-barcode-text { font-size: 10px; font-family: monospace; letter-spacing: 0.2em; margin-top: 4px; }

.v-brand-bottom {
    margin-top: 16px;
    text-align: center;
    border-top: 1px dashed #cbd5e1;
    padding-top: 12px;
}
.v-brand-name { font-size: 16px; font-weight: 800; color: #163274; }

@media print {
    body * { visibility: hidden; }
    #voucher-inner, #voucher-inner * { visibility: visible; }
    #voucher-inner { position: fixed; left: 0; top: 0; transform: scale(1); box-shadow: none; border-radius: 0; }
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

  <div class="grid grid-cols-1 xl:grid-cols-[400px_1fr] gap-6 items-start">

    <!-- ═══ EDITOR ═══ -->
    <div class="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden">
      
      <!-- General Details -->
      <div class="px-5 py-4 border-b border-slate-100">
        <p class="text-[10px] font-bold uppercase tracking-widest text-slate-400 mb-3 flex items-center gap-1.5">
          <span class="material-symbols-outlined text-sm text-primary">info</span> General Details
        </p>
        <div class="grid grid-cols-2 gap-2.5">
          <div><label class="text-[10px] font-semibold text-slate-500 uppercase tracking-wide">Voucher No.</label>
            <input id="v_no" type="text" value="VCH-<?= strtoupper(substr(uniqid(), -6)) ?>" oninput="render()" class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-slate-50 font-mono focus:ring-2 focus:ring-primary/30 focus:border-primary"/></div>
          <div><label class="text-[10px] font-semibold text-slate-500 uppercase tracking-wide">Reason</label>
            <select id="v_reason" onchange="render()" class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-slate-50 focus:ring-2 focus:ring-primary/30 focus:border-primary">
              <option value="CANCELLATION">Cancellation</option>
              <option value="FLIGHT DELAY">Flight Delay</option>
              <option value="CUSTOMER SERVICE">Customer Service</option>
              <option value="PROMOTIONAL">Promotional</option>
            </select>
          </div>
          <div><label class="text-[10px] font-semibold text-slate-500 uppercase tracking-wide">Date of Issue</label>
            <input id="v_issue" type="date" value="<?= date('Y-m-d') ?>" oninput="render()" class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-slate-50 focus:ring-2 focus:ring-primary/30 focus:border-primary"/></div>
          <div><label class="text-[10px] font-semibold text-slate-500 uppercase tracking-wide">Valid Until</label>
            <input id="v_expiry" type="date" value="<?= date('Y-m-d', strtotime('+1 year')) ?>" oninput="render()" class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-slate-50 focus:ring-2 focus:ring-primary/30 focus:border-primary"/></div>
        </div>
      </div>

      <!-- Customer Details -->
      <div class="px-5 py-4 border-b border-slate-100">
        <p class="text-[10px] font-bold uppercase tracking-widest text-slate-400 mb-3 flex items-center gap-1.5">
          <span class="material-symbols-outlined text-sm text-primary">person</span> Passenger Details
        </p>
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

      <!-- Value -->
      <div class="px-5 py-4 border-b border-slate-100 bg-blue-50/30">
        <p class="text-[10px] font-bold uppercase tracking-widest text-slate-400 mb-3 flex items-center gap-1.5">
          <span class="material-symbols-outlined text-sm text-primary">payments</span> Value
        </p>
        <div class="grid grid-cols-[100px_1fr] gap-2.5">
          <div><label class="text-[10px] font-semibold text-slate-500 uppercase tracking-wide">Currency</label>
            <select id="v_currency" onchange="render()" class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-white focus:ring-2 focus:ring-primary/30 focus:border-primary">
              <option value="USD">USD</option>
              <option value="CAD">CAD</option>
              <option value="GBP">GBP</option>
              <option value="EUR">EUR</option>
            </select>
          </div>
          <div><label class="text-[10px] font-semibold text-slate-500 uppercase tracking-wide">Amount</label>
            <input id="v_amount" type="number" step="0.01" placeholder="500.00" oninput="render()" class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-lg font-bold text-primary bg-white focus:ring-2 focus:ring-primary/30 focus:border-primary"/></div>
        </div>
      </div>

      <!-- Terms -->
      <div class="px-5 py-4 border-b border-slate-100">
        <p class="text-[10px] font-bold uppercase tracking-widest text-slate-400 mb-3 flex items-center gap-1.5">
          <span class="material-symbols-outlined text-sm text-primary">gavel</span> Terms & Conditions
        </p>
        <textarea id="v_terms" rows="6" oninput="render()" class="w-full border border-slate-200 rounded-lg px-3 py-2 text-[11px] bg-slate-50 focus:ring-2 focus:ring-primary/30 focus:border-primary resize-none">1. The voucher is non-refundable and non-transferable.
2. Valid for new flight bookings made through Base Fare only.
3. Must be redeemed before expiry; no extension allowed.
4. If new booking exceeds the voucher value, difference must be paid by the passenger.
5. If the new booking is lower, the remaining balance will not be refunded.
6. No cash value; cannot be exchanged for cash.</textarea>
      </div>

    </div>

    <!-- ═══ PREVIEW ═══ -->
    <div class="sticky top-6">
      <div class="bg-white border border-slate-200 rounded-2xl shadow-sm p-5 overflow-x-auto">
        <div class="flex items-center justify-between mb-4 min-w-[800px]">
          <p class="text-[10px] font-bold uppercase tracking-widest text-slate-400 flex items-center gap-1.5">
            <span class="material-symbols-outlined text-sm text-primary">visibility</span> Live Preview
          </p>
        </div>
        
        <!-- SCALE WRAPPER -->
        <div style="transform-origin: top left; transform: scale(0.9); width: 800px; height: 380px; margin-bottom: -38px;">
          <div id="voucher-inner">
            <div class="voucher-bg"></div>
            
            <!-- LEFT PANEL -->
            <div class="voucher-left">
              <div class="v-header">
                <div class="v-title">
                  <span class="material-symbols-outlined">flight_takeoff</span>
                  FLIGHT TRAVEL CREDIT VOUCHER
                </div>
              </div>
              
              <div class="v-content">
                <div class="v-row">
                  <div class="v-col" style="flex:1;">
                    <div class="v-val" style="color:#163274;">Issued By: <strong style="font-size:14px;">BASE FARE</strong></div>
                    <div style="font-size:10px;color:#64748b;margin-top:2px;">Contact: reservation@base-fare.com</div>
                  </div>
                  <div class="v-col" style="text-align:right;">
                    <div class="v-row" style="gap:10px;justify-content:flex-end;">
                      <span class="v-label" style="align-self:center;">Voucher No:</span>
                      <span class="v-val-large" id="prev_vno">XXXXXX</span>
                    </div>
                    <div class="v-row" style="gap:10px;justify-content:flex-end;margin-top:4px;">
                      <span class="v-label" style="align-self:center;">Date of Issue:</span>
                      <span class="v-val" id="prev_issue" style="font-weight:700;">01 JAN 2023</span>
                    </div>
                  </div>
                </div>

                <div class="v-row" style="margin-top:4px;">
                  <div class="v-box" style="flex: 1.2;">
                    <div class="v-val-xl" id="prev_name" style="margin-bottom: 8px;">JOHN DOE</div>
                    <div class="v-row" style="border-top:1px solid #e2e8f0; padding-top:8px;">
                      <div class="v-col"><span class="v-label">Booking Ref (PNR):</span><span class="v-val" id="prev_pnr">ABC123</span></div>
                      <div class="v-col"><span class="v-label">Original Ticket No:</span><span class="v-val" id="prev_ticket">1234567890</span></div>
                    </div>
                  </div>
                  
                  <div class="v-col" style="flex: 1; gap:8px;">
                    <div class="v-box-blue v-row" style="align-items:center;">
                      <span class="v-label" style="color:#1e3a8a;">Amount</span>
                      <span class="v-val-xl" id="prev_amt">USD 500.00</span>
                    </div>
                    <div class="v-row" style="padding: 0 8px;">
                      <div class="v-col"><span class="v-label">Valid Until:</span><span class="v-val" id="prev_expiry">31 DEC 2023</span></div>
                      <div class="v-col"><span class="v-label">Reason:</span><span class="v-val" id="prev_reason">CANCELLATION</span></div>
                    </div>
                  </div>
                </div>

                <div class="v-row" style="margin-top:8px;">
                  <div class="v-col" style="flex:1;">
                    <div class="v-terms-title">Terms & Conditions</div>
                    <div class="v-terms" id="prev_terms" style="white-space:pre-wrap;"></div>
                  </div>
                </div>

                <div class="v-footer">
                  <div class="v-sig-block">
                    <div class="v-sig-line">Passenger Signature</div>
                  </div>
                  <div class="v-sig-block">
                    <div class="v-sig-line">Authorized By (Base Fare)</div>
                  </div>
                </div>
              </div>
            </div>

            <!-- RIGHT PANEL -->
            <div class="voucher-right">
              <div class="v-header-right">
                <div class="v-title-small">FLIGHT CREDIT VOUCHER</div>
              </div>
              
              <div class="v-content-right">
                <div class="v-col">
                  <span class="v-label">Voucher No</span>
                  <span class="v-val-large" id="prev_vno_r">XXXXXX</span>
                </div>
                <div class="v-col">
                  <span class="v-label">Valid Until</span>
                  <span class="v-val" id="prev_expiry_r">31 DEC 2023</span>
                </div>

                <div class="v-col" style="margin-top:8px;">
                  <span class="v-label">Passenger</span>
                  <span class="v-val" style="font-size:16px;" id="prev_name_r">JOHN DOE</span>
                </div>

                <div class="v-col" style="margin-top:8px;">
                  <span class="v-label">Amount</span>
                  <span class="v-val" style="font-size:20px; color:#163274;" id="prev_amt_r">USD 500.00</span>
                </div>

                <div class="v-col">
                  <span class="v-label">Reason</span>
                  <span class="v-val" id="prev_reason_r">CANCELLATION</span>
                </div>

                <div class="v-barcode-container">
                  <svg id="barcode"></svg>
                  <div class="v-barcode-text" id="prev_vno_bc">XXXXXX</div>
                </div>

                <div class="v-brand-bottom">
                  <div class="v-brand-name">BASE FARE</div>
                </div>
              </div>
            </div>
            
          </div>
        </div>
      </div>
    </div>

  </div>
</main>

<script>
const v=id=>document.getElementById(id)?.value?.trim()??'';
const esc=s=>(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
const fmtDate=dStr=>{
    if(!dStr)return '';
    const d = new Date(dStr);
    return d.toLocaleDateString('en-GB', { day:'2-digit', month:'short', year:'numeric' }).toUpperCase();
};

function render(){
  const vno = v('v_no') || 'XXXXXX';
  const reason = v('v_reason') || 'CANCELLATION';
  const issue = fmtDate(v('v_issue'));
  const expiry = fmtDate(v('v_expiry'));
  
  const name = v('v_name') || 'JOHN DOE';
  const pnr = v('v_pnr') || '—';
  const ticket = v('v_ticket') || '—';
  
  const curr = v('v_currency');
  const amt = parseFloat(v('v_amount')||0).toFixed(2);
  const amtStr = `${curr} ${amt}`;
  
  const terms = v('v_terms');

  document.getElementById('prev_vno').textContent = vno;
  document.getElementById('prev_vno_r').textContent = vno;
  document.getElementById('prev_vno_bc').textContent = vno;
  
  document.getElementById('prev_issue').textContent = issue;
  document.getElementById('prev_expiry').textContent = expiry;
  document.getElementById('prev_expiry_r').textContent = expiry;
  
  document.getElementById('prev_reason').textContent = reason;
  document.getElementById('prev_reason_r').textContent = reason;
  
  document.getElementById('prev_name').textContent = name;
  document.getElementById('prev_name_r').textContent = name;
  
  document.getElementById('prev_pnr').textContent = pnr;
  document.getElementById('prev_ticket').textContent = ticket;
  
  document.getElementById('prev_amt').textContent = amtStr;
  document.getElementById('prev_amt_r').textContent = amtStr;
  
  document.getElementById('prev_terms').textContent = terms;

  try {
      JsBarcode("#barcode", vno, {
          format: "CODE128",
          lineColor: "#1e293b",
          width: 1.5,
          height: 40,
          displayValue: false,
          margin: 0
      });
  } catch(e) {}
}

async function saveAndDownload() {
    const btn = document.getElementById('btn-save');
    const name = v('v_name');
    const amt = v('v_amount');
    
    if(!name || !amt || parseFloat(amt) <= 0) {
        alert("Please enter a valid Passenger Name and Amount before saving.");
        return;
    }

    btn.disabled = true;
    btn.innerHTML = '<span class="material-symbols-outlined text-base animate-spin">refresh</span> Saving...';

    const payload = {
        voucher_no: v('v_no'),
        customer_name: name,
        pnr: v('v_pnr'),
        ticket_number: v('v_ticket'),
        amount: amt,
        currency: v('v_currency'),
        issue_date: v('v_issue'),
        expiry_date: v('v_expiry'),
        reason: v('v_reason'),
        terms: v('v_terms')
    };

    try {
        const res = await fetch('/vouchers', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': '<?= $_SESSION['csrf_token'] ?? '' ?>'
            },
            body: JSON.stringify(payload)
        });
        
        const data = await res.json();
        
        if(!data.success) {
            throw new Error(data.error || 'Failed to save');
        }

        // Generate PDF
        btn.innerHTML = '<span class="material-symbols-outlined text-base">download</span> Downloading PDF...';
        
        const el = document.getElementById('voucher-inner');
        const filename = `TravelVoucher_${payload.voucher_no}_${payload.customer_name.replace(/\s+/g,'_')}.pdf`;
        
        await html2pdf().set({
            margin: 0,
            filename: filename,
            image: { type: 'jpeg', quality: 1.0 },
            html2canvas: { scale: 3, useCORS: true, logging: false },
            jsPDF: { unit: 'mm', format: [211.6, 100.5], orientation: 'landscape' }
        }).from(el).save();
        
        // Redirect back to list
        window.location.href = '/vouchers';

    } catch (err) {
        alert(err.message);
        btn.disabled = false;
        btn.innerHTML = '<span class="material-symbols-outlined text-base">save</span> Save & Download PDF';
    }
}

function resetForm() {
    if(!confirm("Reset form?")) return;
    document.getElementById('v_no').value = 'VCH-' + Math.random().toString(36).substr(2, 6).toUpperCase();
    document.getElementById('v_name').value = '';
    document.getElementById('v_pnr').value = '';
    document.getElementById('v_ticket').value = '';
    document.getElementById('v_amount').value = '';
    render();
}

// Initial render
document.addEventListener('DOMContentLoaded', render);

</script>
</body>
</html>
