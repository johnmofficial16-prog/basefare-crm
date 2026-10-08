<?php
/**
 * E-Ticket — Preview & Send (reissuance e-tickets with a Future Travel Voucher)
 *
 * Left: the customer email exactly as it will be sent. Right: the voucher in the
 * standard design (vouchers/voucher_template.php). On load the agent's browser
 * renders the voucher to PDF (html2pdf — the same pipeline as the Vouchers
 * maker) and uploads it; Send attaches that stored PDF.
 *
 * @var \App\Models\ETicket        $eticket
 * @var \App\Models\TravelVoucher  $voucher
 * @var array                      $ftv
 * @var string                     $emailHtml
 * @var bool                       $hasPdf
 * @var string                     $role
 * @var string|null                $flashError
 * @var bool                       $justCreated
 */
use App\Services\ReissueVoucherService;

$activePage = 'etickets';
$et   = $eticket;
$etId = 'ET-' . str_pad($et->id, 6, '0', STR_PAD_LEFT);
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];
$alreadySent = $et->status !== \App\Models\ETicket::STATUS_DRAFT;

$voucherData = [
    'id'     => $voucher->id,
    'no'     => $voucher->voucher_no,
    'issue'  => $voucher->issue_date?->format('d M Y'),
    'expiry' => $voucher->expiry_date?->format('d M Y'),
    'name'   => $voucher->customer_name,
    'pnr'    => $voucher->pnr ?: '—',
    'ticket' => $voucher->ticket_number ?: '—',
    'amount' => $voucher->currency . ' ' . number_format((float) $voucher->amount, 2),
    'reason' => $voucher->reason ?: ReissueVoucherService::REASON,
    'terms'  => $voucher->terms ?: ReissueVoucherService::DEFAULT_TERMS,
];
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<title>Preview <?= $etId ?> — Base Fare CRM</title>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
<script src="<?= \App\Services\Asset::url('assets/js/error-beacon.js') ?>"></script>
<script src="/assets/js/tailwind.js"></script>
<script src="<?= \App\Services\Asset::url('assets/js/buddy-widget.js') ?>" defer></script>
<script src="/assets/js/html2pdf.bundle.min.js"></script>
<script src="/assets/js/JsBarcode.all.min.js"></script>
<script>
tailwind.config = {
  theme: { extend: {
    fontFamily: { sans: ['Inter', 'Manrope', 'sans-serif'] },
    colors: { primary: { DEFAULT: '#0f1e3c', 50: '#f0f4ff', 100: '#dde8ff', 500: '#1a3a6b', 600: '#0f1e3c' } }
  } }
}
</script>
<style>
  /* Voucher is laid out at its true 1122×398 size (html2canvas captures that);
     the wrapper only scales the on-screen copy. */
  .voucher-frame { width: 100%; overflow: hidden; }
  .voucher-scale { transform-origin: top left; }
</style>
</head>
<body class="bg-slate-50 font-sans min-h-screen">

<?php require __DIR__ . '/../layout/sidebar.php'; ?>

<main class="ml-60 pt-6 pb-20 px-8">

  <div class="flex items-center justify-between mb-5 gap-4 flex-wrap">
    <div>
      <a href="/etickets/<?= (int) $et->id ?>" class="text-xs font-semibold text-slate-500 hover:text-primary inline-flex items-center gap-1">
        <span class="material-symbols-outlined text-sm">arrow_back</span> <?= $etId ?>
      </a>
      <h1 class="text-2xl font-extrabold text-primary tracking-tight mt-1" style="font-family:Manrope">Preview &amp; Send</h1>
      <p class="text-sm text-slate-500">Reissuance e-ticket for <strong><?= htmlspecialchars($et->customer_name) ?></strong> · PNR <span class="font-mono"><?= htmlspecialchars($et->pnr) ?></span> · to <?= htmlspecialchars($et->customer_email) ?></p>
    </div>
  </div>

  <?php if ($justCreated): ?>
  <div class="mb-4 px-4 py-3 bg-sky-50 border border-sky-200 rounded-xl text-sm font-semibold text-sky-800">
    E-ticket created. This reissuance carries a Future Travel Voucher, so it isn't sent yet — review below, then send.
  </div>
  <?php endif; ?>
  <?php if ($flashError): ?>
  <div class="mb-4 px-4 py-3 bg-red-50 border border-red-200 rounded-xl text-sm font-semibold text-red-700"><?= htmlspecialchars($flashError) ?></div>
  <?php endif; ?>

  <div class="grid grid-cols-1 2xl:grid-cols-2 gap-6">

    <!-- Email preview -->
    <section class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
      <div class="px-5 py-3 border-b border-slate-100 bg-slate-50/60 flex items-center gap-2">
        <span class="material-symbols-outlined text-slate-500 text-base">mail</span>
        <h2 class="font-bold text-sm text-slate-900">Email the customer receives</h2>
        <span class="ml-auto text-[11px] text-slate-400">+ voucher PDF attached</span>
      </div>
      <iframe title="E-ticket email preview" sandbox="" class="w-full bg-white" style="height:900px;border:0;"
              srcdoc="<?= htmlspecialchars($emailHtml, ENT_QUOTES) ?>"></iframe>
    </section>

    <!-- Voucher + send -->
    <section class="space-y-5">
      <div class="bg-white border-2 border-sky-200 rounded-xl shadow-sm overflow-hidden">
        <div class="px-5 py-3 border-b border-sky-100 bg-sky-50/60 flex items-center gap-2">
          <span class="material-symbols-outlined text-sky-600 text-base">card_giftcard</span>
          <h2 class="font-bold text-sm text-sky-900">Future Travel Voucher <span class="font-mono"><?= htmlspecialchars($voucher->voucher_no) ?></span></h2>
          <span class="ml-auto text-xs font-bold text-sky-800"><?= htmlspecialchars($voucherData['amount']) ?> · valid until <?= htmlspecialchars($voucherData['expiry']) ?></span>
        </div>
        <div class="p-4">
          <div class="voucher-frame" id="voucher-frame">
            <div class="voucher-scale" id="voucher-scale">
              <?php require __DIR__ . '/../vouchers/voucher_template.php'; ?>
            </div>
          </div>
        </div>
      </div>

      <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-5 space-y-4">
        <div id="pdf-status" class="flex items-center gap-2 text-sm font-semibold text-slate-600">
          <span class="material-symbols-outlined text-base animate-spin" id="pdf-icon">progress_activity</span>
          <span id="pdf-text">Preparing the voucher PDF…</span>
        </div>
        <div class="flex items-center gap-3 flex-wrap">
          <a id="pdf-link" href="/etickets/<?= (int) $et->id ?>/voucher.pdf" target="_blank" rel="noopener"
             class="<?= $hasPdf ? '' : 'hidden ' ?>inline-flex items-center gap-1.5 text-sm font-semibold text-sky-700 hover:text-sky-900 border border-sky-200 rounded-lg px-3 py-2">
            <span class="material-symbols-outlined text-base">picture_as_pdf</span> Open the PDF
          </a>
          <button type="button" id="btn-regen" class="hidden inline-flex items-center gap-1.5 text-sm font-semibold text-slate-600 hover:text-primary border border-slate-200 rounded-lg px-3 py-2">
            <span class="material-symbols-outlined text-base">refresh</span> Re-make PDF
          </button>
        </div>

        <?php if ($role !== 'csa'): ?>
        <form method="POST" action="/etickets/<?= (int) $et->id ?>/send" id="send-form" class="border-t border-slate-100 pt-4">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
          <button type="submit" id="btn-send" disabled
                  class="w-full inline-flex items-center justify-center gap-2 px-5 py-3 bg-primary text-white font-extrabold rounded-xl disabled:opacity-40 disabled:cursor-not-allowed hover:bg-primary-500">
            <span class="material-symbols-outlined text-base">send</span>
            <?= $alreadySent ? 'Resend e-ticket + voucher' : 'All good — send e-ticket + voucher' ?>
          </button>
          <p class="text-[11px] text-slate-400 text-center mt-2">Sends to <?= htmlspecialchars($et->customer_email) ?> with <?= htmlspecialchars(ReissueVoucherService::pdfFilename($voucher)) ?> attached.</p>
        </form>
        <?php endif; ?>
      </div>
    </section>
  </div>
</main>

<script>
(function () {
  const V = <?= json_encode($voucherData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
  const UPLOAD_URL = '/etickets/<?= (int) $et->id ?>/voucher-pdf';
  const CSRF = <?= json_encode($csrf) ?>;
  const HAS_PDF = <?= $hasPdf ? 'true' : 'false' ?>;

  const set = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = v; };
  const up = s => String(s || '').toUpperCase();

  // Fill the standard voucher template (same element ids as vouchers/maker.php)
  set('p_vno', V.no);       set('s_vno', V.no);   set('s_vno_bc', V.no); set('s_vno_tag', V.no);
  set('p_issue', up(V.issue));
  set('p_name', up(V.name)); set('s_name', up(V.name));
  set('p_pnr', V.pnr);       set('p_ticket', V.ticket);
  set('p_amt', V.amount);    set('s_amt', V.amount);
  set('p_expiry', up(V.expiry)); set('s_expiry', up(V.expiry));
  set('p_reason', V.reason); set('s_reason', V.reason);
  set('p_terms', V.terms);
  try { JsBarcode('#barcode', V.no, { format:'CODE128', lineColor:'#1e293b', width:1.2, height:36, displayValue:false, margin:0 }); } catch (e) {}

  // Scale the on-screen copy to the column width
  const frame = document.getElementById('voucher-frame');
  const scaler = document.getElementById('voucher-scale');
  function fit() {
    const s = Math.min(1, frame.clientWidth / 1122);
    scaler.style.transform = 'scale(' + s + ')';
    frame.style.height = Math.ceil(398 * s) + 'px';
  }
  fit(); window.addEventListener('resize', fit);

  const icon = document.getElementById('pdf-icon'), text = document.getElementById('pdf-text');
  const send = document.getElementById('btn-send'), regen = document.getElementById('btn-regen');
  function state(kind, msg) {
    text.textContent = msg;
    icon.classList.toggle('animate-spin', kind === 'busy');
    icon.textContent = kind === 'busy' ? 'progress_activity' : (kind === 'ok' ? 'check_circle' : 'error');
    icon.className = icon.className.replace(/text-\w+-\d+/g, '') + (kind === 'ok' ? ' text-emerald-600' : kind === 'err' ? ' text-rose-600' : '');
    if (send) send.disabled = kind !== 'ok';
    regen.classList.toggle('hidden', kind === 'busy');
  }

  async function makePdf() {
    state('busy', 'Making the voucher PDF…');
    try {
      const el = document.getElementById('voucher-printable');
      const blob = await html2pdf().set({
        margin: 0,
        image: { type: 'jpeg', quality: 1.0 },
        html2canvas: { scale: 2, useCORS: true, logging: false, width: 1122, height: 398, scrollX: 0, scrollY: -window.scrollY },
        jsPDF: { unit: 'mm', format: [297, 105.5], orientation: 'landscape' }
      }).from(el).outputPdf('blob');

      const fd = new FormData();
      fd.append('csrf_token', CSRF);
      fd.append('voucher_id', V.id);
      fd.append('voucher_pdf', new File([blob], V.no + '.pdf', { type: 'application/pdf' }));
      const res = await fetch(UPLOAD_URL, { method: 'POST', body: fd, credentials: 'same-origin' });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || !data.success) throw new Error(data.error || 'Upload failed (' + res.status + ')');

      document.getElementById('pdf-link').classList.remove('hidden');
      state('ok', 'Voucher PDF ready — check the email and the voucher, then send.');
    } catch (e) {
      state('err', 'Could not make the voucher PDF: ' + e.message + ' — try Re-make PDF.');
    }
  }

  regen.addEventListener('click', makePdf);
  document.getElementById('send-form')?.addEventListener('submit', (e) => {
    if (send.disabled) { e.preventDefault(); return; }
    // Disable after the submit is dispatched, so a double-click can't send twice
    setTimeout(() => { send.disabled = true; }, 0);
  });

  // Fonts must be loaded before html2canvas captures, or the PDF falls back to Arial
  (document.fonts ? document.fonts.ready : Promise.resolve()).then(() => {
    if (HAS_PDF) { state('ok', 'Voucher PDF ready — check the email and the voucher, then send.'); }
    else { makePdf(); }
  });
})();
</script>
</body>
</html>
