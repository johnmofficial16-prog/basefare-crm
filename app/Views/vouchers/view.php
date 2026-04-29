<?php
$activePage = 'vouchers';
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
<meta charset="utf-8"/>
<meta content="width=device-width,initial-scale=1.0" name="viewport"/>
<title>View Voucher <?= htmlspecialchars($voucher->voucher_no) ?> — Base Fare CRM</title>
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

#preview-wrapper {
    transform-origin: top left;
    transform: scale(0.85);
    width: 1122px;
    height: 398px;
    margin-bottom: calc((398px * 0.85) - 398px);
}
</style>
</head>
<body class="bg-background font-body text-on-surface antialiased min-h-screen">

<?php require __DIR__.'/../partials/admin_sidebar.php'; ?>

<main class="ml-60 pt-6 pb-20 px-8">

  <div class="flex items-center justify-between mb-8">
    <div class="flex items-center gap-4">
      <a href="/vouchers" class="w-10 h-10 flex items-center justify-center rounded-full bg-white border border-slate-200 text-slate-500 hover:text-primary hover:border-primary transition-all">
        <span class="material-symbols-outlined">arrow_back</span>
      </a>
      <div>
        <h1 class="text-2xl font-headline font-extrabold text-primary tracking-tight">Voucher Details</h1>
        <p class="text-sm text-on-surface-variant">Voucher No: <?= htmlspecialchars($voucher->voucher_no) ?></p>
      </div>
    </div>
    <div class="flex items-center gap-3">
      <button id="btn-download" onclick="downloadPDF()" class="inline-flex items-center gap-1.5 px-5 py-2.5 bg-emerald-600 text-white font-bold rounded-xl hover:bg-emerald-700 shadow-lg shadow-emerald-600/20 transition-all text-sm">
        <span class="material-symbols-outlined text-base">download</span> Download PDF
      </button>
    </div>
  </div>

  <div class="grid grid-cols-1 lg:grid-cols-[1fr_350px] gap-8">
    
    <!-- Voucher Rendering -->
    <div class="bg-white border border-slate-200 rounded-3xl p-8 shadow-sm overflow-x-auto flex justify-center items-center">
        <div id="preview-wrapper">
          <?php include __DIR__ . '/voucher_template.php'; ?>
        </div>
    </div>

    <!-- Details Panel -->
    <div class="flex flex-col gap-6">
      <div class="bg-white border border-slate-200 rounded-3xl p-6 shadow-sm">
        <h3 class="text-sm font-bold uppercase tracking-widest text-slate-400 mb-4 flex items-center gap-2">
          <span class="material-symbols-outlined text-base text-primary">info</span> Audit Information
        </h3>
        <div class="space-y-4">
          <div>
            <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Issued By</label>
            <p class="text-sm font-semibold text-slate-900"><?= htmlspecialchars($voucher->creator->full_name ?? 'System') ?></p>
          </div>
          <div>
            <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Date of Issue</label>
            <p class="text-sm font-semibold text-slate-900"><?= $voucher->issue_date->format('F d, Y') ?></p>
          </div>
          <div>
            <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Valid Until</label>
            <p class="text-sm font-semibold text-slate-900"><?= $voucher->expiry_date->format('F d, Y') ?></p>
          </div>
          <div>
            <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Status</label>
            <div class="mt-1">
              <?php if ($voucher->status === 'active'): ?>
                <span class="inline-flex items-center gap-1 px-2.5 py-1 bg-emerald-50 text-emerald-700 text-xs font-bold rounded-full border border-emerald-200">Active</span>
              <?php elseif ($voucher->status === 'redeemed'): ?>
                <span class="inline-flex items-center gap-1 px-2.5 py-1 bg-blue-50 text-blue-700 text-xs font-bold rounded-full border border-blue-200">Redeemed</span>
              <?php else: ?>
                <span class="inline-flex items-center gap-1 px-2.5 py-1 bg-rose-50 text-rose-700 text-xs font-bold rounded-full border border-rose-200">Void</span>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>

      <?php if ($_SESSION['role'] === 'admin'): ?>
      <div class="bg-rose-50 border border-rose-100 rounded-3xl p-6 shadow-sm">
        <h3 class="text-sm font-bold uppercase tracking-widest text-rose-400 mb-4 flex items-center gap-2">
          <span class="material-symbols-outlined text-base">warning</span> Danger Zone
        </h3>
        <p class="text-xs text-rose-600 mb-4 leading-relaxed">Deleting this voucher will permanently remove it from the database and tracking. This action cannot be undone.</p>
        <button onclick="deleteVoucher()" class="w-full inline-flex items-center justify-center gap-1.5 px-4 py-2.5 bg-rose-600 text-white font-bold rounded-xl hover:bg-rose-700 transition-all text-sm">
          <span class="material-symbols-outlined text-base">delete</span> Delete Voucher
        </button>
      </div>
      <?php endif; ?>
    </div>

  </div>

</main>

<script>
const setText = (id, val) => { const el = document.getElementById(id); if(el) el.textContent = val; };

function render() {
    const vno    = "<?= htmlspecialchars($voucher->voucher_no) ?>";
    const name   = "<?= htmlspecialchars($voucher->customer_name) ?>".toUpperCase();
    const pnr    = "<?= htmlspecialchars($voucher->pnr ?: '—') ?>";
    const ticket = "<?= htmlspecialchars($voucher->ticket_number ?: '—') ?>";
    const amtStr = "<?= htmlspecialchars($voucher->currency) ?> <?= number_format($voucher->amount, 2) ?>";
    const issue  = "<?= strtoupper($voucher->issue_date->format('d M Y')) ?>";
    const expiry = "<?= strtoupper($voucher->expiry_date->format('d M Y')) ?>";
    const reason = "<?= htmlspecialchars($voucher->reason) ?>";
    const terms  = `<?= addslashes($voucher->terms) ?>`;

    // main panel
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
    setText('s_vno_tag', vno);

    try {
        JsBarcode('#barcode', vno, { format:'CODE128', lineColor:'#1e293b', width:1.2, height:36, displayValue:false, margin:0 });
    } catch(e) {}
}

async function downloadPDF() {
    const btn = document.getElementById('btn-download');
    btn.disabled = true;
    btn.innerHTML = '<span class="material-symbols-outlined text-base animate-spin">refresh</span> Processing...';

    const printEl = document.getElementById('voucher-printable');
    const filename = `TravelVoucher_<?= htmlspecialchars($voucher->voucher_no) ?>_<?= htmlspecialchars(str_replace(' ', '_', $voucher->customer_name)) ?>.pdf`;

    try {
        await html2pdf().set({
            margin:      0,
            filename:    filename,
            image:       { type: 'jpeg', quality: 1.0 },
            html2canvas: { scale: 2, useCORS: true, logging: false, width: 1122, height: 398, scrollX: 0, scrollY: -window.scrollY },
            jsPDF:       { unit: 'mm', format: [297, 105.5], orientation: 'landscape' }
        }).from(printEl).save();
    } catch (err) {
        alert('Failed to generate PDF: ' + err.message);
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<span class="material-symbols-outlined text-base">download</span> Download PDF';
    }
}

async function deleteVoucher() {
    if (!confirm('Are you absolutely sure you want to delete this voucher? This cannot be undone.')) return;

    try {
        const res = await fetch('/vouchers/<?= $voucher->id ?>', {
            method: 'DELETE',
            headers: {
                'X-CSRF-Token': '<?= $_SESSION['csrf_token'] ?? '' ?>'
            }
        });
        const data = await res.json();
        if (data.success) {
            window.location.href = '/vouchers';
        } else {
            alert(data.error);
        }
    } catch (err) {
        alert('Error deleting voucher: ' + err.message);
    }
}

document.addEventListener('DOMContentLoaded', render);
</script>
</body>
</html>
