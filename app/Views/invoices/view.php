<?php
/**
 * Invoice — saved view with actions + audit timeline.
 *
 * @var \App\Models\Invoice $invoice
 * @var \Illuminate\Support\Collection $notes
 * @var string $role
 * @var bool   $canApprove
 * @var array  $flash
 */
use App\Models\Invoice;
use App\Models\RecordNote;

$activePage = 'invoices';
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
[$stLabel, $stClass] = $invoice->statusBadge();
$isEditable = $invoice->isEditable($canApprove);

$rdata = [
    'invoice_no'      => $invoice->invoice_no,
    'issue_date'      => (string) $invoice->issue_date->format('Y-m-d'),
    'purpose_label'   => $invoice->purposeLabel(),
    'customer_name'   => $invoice->customer_name,
    'customer_phone'  => $invoice->customer_phone,
    'customer_email'  => $invoice->customer_email,
    'pnr'             => $invoice->pnr,
    'airline'         => $invoice->airline,
    'cardholder_name' => $invoice->cardholder_name,
    'card_billing'    => $invoice->card_billing,
    'currency'        => $invoice->currency,
    'airline_charge'  => $invoice->airline_charge,
    'base_fare_charge'=> $invoice->base_fare_charge,
    'itinerary'       => is_array($invoice->itinerary) ? $invoice->itinerary : [],
];
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
<meta charset="utf-8"/>
<meta content="width=device-width,initial-scale=1.0" name="viewport"/>
<title>Invoice <?= $h($invoice->invoice_no) ?> — Base Fare CRM</title>
<!-- Inter 700/800 are required by the invoice document (labels, values, section
     titles, the total row). Without them the browser substitutes a system face —
     Arial Black was landing in printed PDFs — so the weights requested here must
     stay in step with invoice_template.php. -->
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
<script src="https://cdn.tailwindcss.com?plugins=forms"></script>
<script>
tailwind.config={darkMode:"class",theme:{extend:{colors:{primary:"#163274","primary-container":"#314a8d",background:"#f8f9fa","on-surface":"#191c1d","on-surface-variant":"#434653"},fontFamily:{headline:["Manrope"],body:["Inter"]}}}};
</script>
<script src="/assets/js/html2pdf.bundle.min.js"></script>
<style>.material-symbols-outlined{font-variation-settings:'FILL' 0,'wght' 400,'GRAD' 0,'opsz' 24}</style>
</head>
<body class="bg-background font-body text-on-surface antialiased min-h-screen">

<?php require __DIR__.'/../layout/sidebar.php'; ?>

<main class="ml-60 pt-6 pb-20 px-8">

  <div class="flex items-center justify-between mb-6">
    <div>
      <div class="flex items-center gap-3">
        <h1 class="text-2xl font-headline font-extrabold text-primary tracking-tight flex items-center gap-2">
          <span class="material-symbols-outlined text-2xl">request_quote</span>
          <?= $h($invoice->invoice_no) ?>
        </h1>
        <span class="px-2.5 py-1 rounded-full text-xs font-bold <?= $stClass ?>"><?= $h($stLabel) ?></span>
      </div>
      <p class="text-sm text-on-surface-variant mt-0.5">
        For <?= $h($invoice->customer_name) ?> · Created by <?= $h($invoice->creator->name ?? 'Unknown') ?> · <?= $h($invoice->created_at->format('M j, Y g:i A')) ?>
      </p>
    </div>
    <div class="flex items-center gap-2">
      <a href="/invoices" class="inline-flex items-center gap-1.5 px-4 py-2.5 bg-white border border-slate-200 text-slate-600 font-bold rounded-xl hover:border-primary hover:text-primary transition-all text-sm shadow-sm">
        <span class="material-symbols-outlined text-base">arrow_back</span> Back
      </a>
      <button type="button" onclick="window.printInvoice()" title="Opens the print dialog — choose &quot;Save as PDF&quot; for a sharp, selectable document"
              class="inline-flex items-center gap-1.5 px-4 py-2.5 bg-primary text-white font-bold rounded-xl hover:bg-primary-container transition-all text-sm shadow-lg shadow-primary/20">
        <span class="material-symbols-outlined text-base">print</span> Print / Save as PDF
      </button>
      <button type="button" onclick="downloadPdf()" title="Quick image-based download — lower quality, text not selectable"
              class="inline-flex items-center gap-1.5 px-4 py-2.5 bg-white border border-slate-200 text-slate-600 font-bold rounded-xl hover:border-primary hover:text-primary transition-all text-sm shadow-sm">
        <span class="material-symbols-outlined text-base">download</span> Quick PDF
      </button>
      <?php if ($isEditable): ?>
      <a href="/invoices/<?= $invoice->id ?>/edit" class="inline-flex items-center gap-1.5 px-4 py-2.5 bg-white border border-slate-200 text-slate-600 font-bold rounded-xl hover:border-primary hover:text-primary transition-all text-sm shadow-sm">
        <span class="material-symbols-outlined text-base">edit</span> Edit
      </a>
      <?php endif; ?>
      <?php if ($canApprove && $invoice->isApproved()): ?>
      <form method="post" action="/invoices/<?= $invoice->id ?>/send" onsubmit="return confirm('Email this invoice to <?= $h($invoice->customer_email ?: 'the customer') ?>?')">
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>"/>
        <button type="submit" class="inline-flex items-center gap-1.5 px-4 py-2.5 bg-emerald-600 text-white font-bold rounded-xl hover:bg-emerald-700 shadow-lg shadow-emerald-600/20 transition-all text-sm">
          <span class="material-symbols-outlined text-base">send</span> Send to Customer
        </button>
      </form>
      <?php endif; ?>
    </div>
  </div>

  <?php
  $flashMap = [
    'sent'       => ['emerald', 'Invoice emailed to the customer.'],
    'saved'      => ['blue',    'Invoice saved.'],
    'submitted'  => ['amber',   'Invoice submitted for manager approval.'],
    'updated'    => ['blue',    'Changes saved.'],
    'approved'   => ['emerald', 'Invoice approved and emailed to the customer.'],
    'rejected'   => ['rose',    'Invoice rejected.'],
    'send_error' => ['rose',    'The invoice could not be emailed. Check the SMTP settings / customer email and try Send again.'],
  ];
  foreach ($flashMap as $k => [$color, $msg]):
    if (!empty($flash[$k])): ?>
  <div class="mb-4 px-4 py-3 bg-<?= $color ?>-50 border border-<?= $color ?>-200 text-<?= $color ?>-700 rounded-xl text-sm font-semibold flex items-center gap-2">
    <span class="material-symbols-outlined text-base"><?= $k === 'send_error' || $k === 'rejected' ? 'error' : 'check_circle' ?></span>
    <?= $h($msg) ?>
  </div>
  <?php endif; endforeach; ?>

  <?php if (!empty($_SESSION['flash_error'])): ?>
  <div class="mb-4 px-4 py-3 bg-rose-50 border border-rose-200 text-rose-700 rounded-xl text-sm font-semibold"><?= $h($_SESSION['flash_error']) ?></div>
  <?php unset($_SESSION['flash_error']); endif; ?>

  <?php if ($invoice->isRejected() && $invoice->rejected_reason): ?>
  <div class="mb-4 px-4 py-3 bg-rose-50 border border-rose-200 text-rose-800 rounded-xl text-sm">
    <strong>Rejected:</strong> <?= $h($invoice->rejected_reason) ?>
  </div>
  <?php endif; ?>

  <div class="grid grid-cols-1 xl:grid-cols-[1fr_360px] gap-6 items-start">

    <!-- Document -->
    <div class="bg-white border border-slate-200 rounded-2xl shadow-sm p-5 overflow-x-auto">
      <?php include __DIR__ . '/invoice_template.php'; ?>
    </div>

    <!-- Side: approval + audit -->
    <div class="space-y-6">

      <?php if ($canApprove && $invoice->isPendingApproval()): ?>
      <div class="bg-white border border-amber-200 rounded-2xl shadow-sm overflow-hidden">
        <div class="px-5 py-3 bg-amber-50 border-b border-amber-100">
          <p class="text-xs font-bold uppercase tracking-widest text-amber-700 flex items-center gap-1.5"><span class="material-symbols-outlined text-base">gavel</span> Approval</p>
        </div>
        <div class="p-5 space-y-3">
          <form method="post" action="/invoices/<?= $invoice->id ?>/approve" onsubmit="return confirm('Approve and email this invoice to the customer?')">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>"/>
            <button type="submit" class="w-full inline-flex items-center justify-center gap-1.5 px-5 py-3 bg-emerald-600 text-white font-bold rounded-xl hover:bg-emerald-700 shadow-lg shadow-emerald-600/20 transition-all text-sm">
              <span class="material-symbols-outlined text-base">verified</span> Approve &amp; Send
            </button>
          </form>
          <form method="post" action="/invoices/<?= $invoice->id ?>/reject">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>"/>
            <textarea name="reason" rows="2" required placeholder="Reason for rejection…" class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-slate-50 focus:ring-2 focus:ring-rose-300 focus:border-rose-400 resize-none"></textarea>
            <button type="submit" class="mt-2 w-full inline-flex items-center justify-center gap-1.5 px-5 py-2.5 bg-white border border-rose-200 text-rose-600 font-bold rounded-xl hover:bg-rose-50 transition-all text-sm">
              <span class="material-symbols-outlined text-base">block</span> Reject
            </button>
          </form>
        </div>
      </div>
      <?php endif; ?>

      <!-- Audit timeline -->
      <div class="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-100">
          <p class="text-xs font-bold uppercase tracking-widest text-slate-400 flex items-center gap-1.5"><span class="material-symbols-outlined text-base text-primary">history</span> Activity &amp; Notes</p>
        </div>
        <div class="p-5">
          <form method="post" action="/invoices/<?= $invoice->id ?>/note" class="mb-4">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>"/>
            <textarea name="note" rows="2" required placeholder="Add a note…" class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-slate-50 focus:ring-2 focus:ring-primary/30 focus:border-primary resize-none"></textarea>
            <button type="submit" class="mt-2 inline-flex items-center gap-1.5 px-4 py-2 bg-primary text-white font-bold rounded-lg text-xs hover:bg-primary-container transition-colors">
              <span class="material-symbols-outlined text-sm">add_comment</span> Add Note
            </button>
          </form>
          <div class="space-y-3">
            <?php if ($notes->isEmpty()): ?>
              <p class="text-sm text-slate-400">No activity yet.</p>
            <?php else: foreach ($notes->reverse() as $n):
              $badge = RecordNote::actionBadge($n->action ?? 'note'); ?>
            <div class="flex gap-3">
              <div class="mt-0.5 w-7 h-7 rounded-full bg-<?= $badge['color'] ?>-100 text-<?= $badge['color'] ?>-600 flex items-center justify-center shrink-0">
                <span class="material-symbols-outlined text-sm"><?= $badge['icon'] ?></span>
              </div>
              <div class="flex-1 min-w-0">
                <p class="text-sm text-slate-700"><?= $h($n->note) ?></p>
                <p class="text-[11px] text-slate-400 mt-0.5">
                  <?= $h($n->user->name ?? 'System') ?> · <?= $h(\Carbon\Carbon::parse($n->created_at)->format('M j, Y g:i A')) ?>
                </p>
              </div>
            </div>
            <?php endforeach; endif; ?>
          </div>
        </div>
      </div>

    </div>
  </div>
</main>

<script>
const RDATA = <?= json_encode($rdata, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
document.addEventListener('DOMContentLoaded', () => window.renderInvoice(RDATA));
async function downloadPdf() {
    const fname = 'Invoice_<?= $h($invoice->invoice_no) ?>_' + (<?= json_encode($invoice->customer_name, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?> || 'customer').replace(/\s+/g,'_') + '.pdf';
    await window.captureInvoicePdf(fname);
}
</script>
</body>
</html>
