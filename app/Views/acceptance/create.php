<?php
/**
 * Acceptance Request — Create Wizard
 * Part 1 of 4: PHP header, Step 1 (Type), Step 2 (Customer/Passenger)
 *
 * @var array $prefill   Pre-fill values from query params (e.g. from Transaction Recorder)
 * @var string|null $flashError  Flash error from previous failed submission
 */

use App\Models\AcceptanceRequest;

$prefill = $prefill ?? [];
$flashError = $flashError ?? null;
$preauthRecord = $preauthRecord ?? null;

// Determine mode: preauth vs full
// Priority: from_preauth param (promote flow) forces full mode; ?mode=preauth forces preauth mode
$isPromoting   = ($preauthRecord !== null);  // coming from an approved pre-auth
$initMode      = $isPromoting ? 'full' : (($_GET['mode'] ?? '') === 'preauth' ? 'preauth' : 'full');
$initIsPreauth = ($initMode === 'preauth');
$initPreauthId = $isPromoting ? $preauthRecord->id : 0;

// Pre-fill helpers (safe defaults)
$pre = [
    'type'           => htmlspecialchars($prefill['type'] ?? ''),
    'pnr'            => htmlspecialchars(strtoupper($prefill['pnr'] ?? '')),
    'customer_name'  => htmlspecialchars($prefill['customer_name'] ?? ''),
    'customer_email' => htmlspecialchars($prefill['customer_email'] ?? ''),
    'customer_phone' => htmlspecialchars($prefill['customer_phone'] ?? ''),
    'transaction_id' => (int)($prefill['transaction_id'] ?? 0),
    'total_amount'   => htmlspecialchars($prefill['total_amount'] ?? ''),
    'currency'       => htmlspecialchars($prefill['currency'] ?? 'USD'),
    'agent_notes'    => htmlspecialchars($prefill['agent_notes'] ?? ''),
];

// Pre-fill JSON — always use json_encode() because Eloquent casts these to PHP arrays
$preJson = [
    'passengers'     => json_encode($prefill['passengers']  ?? []),
    'flight_data'    => json_encode($prefill['flight_data'] ?? null),
    'fare_breakdown' => json_encode($prefill['fare_breakdown'] ?? []),
];

// Type definitions for Step 1 cards
$typeCards = [
    ['value' => 'new_booking',     'label' => 'New Booking',              'sub' => 'Authorize a new flight ticket purchase',       'icon' => 'flight_takeoff',           'color' => 'blue'],
    ['value' => 'exchange',        'label' => 'Exchange / Date Change',   'sub' => 'Authorize a flight change or date swap',        'icon' => 'swap_horiz',              'color' => 'violet'],
    ['value' => 'cancel_refund',   'label' => 'Cancellation & Refund',    'sub' => 'Authorize cancellation with refund to card',    'icon' => 'money_off',               'color' => 'rose'],
    ['value' => 'cancel_credit',   'label' => 'Cancellation & Credit',    'sub' => 'Authorize cancellation with future credit',     'icon' => 'savings',                 'color' => 'orange'],
    ['value' => 'seat_purchase',   'label' => 'Seat Purchase',            'sub' => 'Authorize seat selection or upgrade fee',       'icon' => 'airline_seat_recline_extra', 'color' => 'cyan'],
    ['value' => 'cabin_upgrade',   'label' => 'Cabin Upgrade',            'sub' => 'Authorize an upgrade to a higher cabin class',  'icon' => 'workspace_premium',       'color' => 'teal'],
    ['value' => 'name_correction', 'label' => 'Name Correction',         'sub' => 'Authorize a passenger name change fee',         'icon' => 'badge',                   'color' => 'yellow'],
    ['value' => 'other',           'label' => 'Other Authorization',      'sub' => 'Custom authorization for other services',      'icon' => 'edit_document',           'color' => 'slate'],
];

// Color map for type cards
$colorMap = [
    'blue'   => ['ring' => 'ring-blue-500',   'bg' => 'bg-blue-50',   'icon' => 'text-blue-600',   'badge' => 'bg-blue-100 text-blue-800'],
    'violet' => ['ring' => 'ring-violet-500', 'bg' => 'bg-violet-50', 'icon' => 'text-violet-600', 'badge' => 'bg-violet-100 text-violet-800'],
    'rose'   => ['ring' => 'ring-rose-500',   'bg' => 'bg-rose-50',   'icon' => 'text-rose-600',   'badge' => 'bg-rose-100 text-rose-800'],
    'orange' => ['ring' => 'ring-orange-500', 'bg' => 'bg-orange-50', 'icon' => 'text-orange-600', 'badge' => 'bg-orange-100 text-orange-800'],
    'cyan'   => ['ring' => 'ring-cyan-500',   'bg' => 'bg-cyan-50',   'icon' => 'text-cyan-600',   'badge' => 'bg-cyan-100 text-cyan-800'],
    'teal'   => ['ring' => 'ring-teal-500',   'bg' => 'bg-teal-50',   'icon' => 'text-teal-600',   'badge' => 'bg-teal-100 text-teal-800'],
    'yellow' => ['ring' => 'ring-yellow-500', 'bg' => 'bg-yellow-50', 'icon' => 'text-yellow-600', 'badge' => 'bg-yellow-100 text-yellow-800'],
    'slate'  => ['ring' => 'ring-slate-400',  'bg' => 'bg-slate-50',  'icon' => 'text-slate-500',  'badge' => 'bg-slate-100 text-slate-700'],
];

$defaultPolicy = "1. PASSENGER NAMES: Names must match your government-issued ID exactly. Lets Fly Travel DBA Base Fare is not responsible for denied boarding due to name mismatches or Visa/Travel Document issues.\n2. REFUNDS & CHANGES: All tickets are NON-REFUNDABLE and NON-TRANSFERABLE once issued. Date changes are subject to airline penalties plus fare differences.\n3. CHARGEBACK WAIVER: By signing, I acknowledge the service has been performed. I agree NOT to dispute or chargeback this transaction with my card issuer for any reason.\n4. AUTHORIZATION: I authorize Lets Fly Travel DBA Base Fare to charge the Total Amount listed to my credit card.";
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>New Acceptance Request — Base Fare CRM</title>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@400,0&display=swap" rel="stylesheet"/>
<script src="https://cdn.tailwindcss.com?plugins=forms"></script>
<script>
tailwind.config = {
  darkMode: 'class',
  theme: {
    extend: {
      fontFamily: { sans: ['Inter', 'Manrope', 'sans-serif'] },
      colors: {
        primary: { DEFAULT: '#0f1e3c', 50: '#f0f4ff', 100: '#dde8ff', 500: '#1a3a6b', 600: '#0f1e3c' },
        gold: { DEFAULT: '#c9a84c', light: '#f5e6c0' }
      }
    }
  }
}
</script>
<style>
  .step-panel { display: none; }
  .step-panel.active { display: block; }
  .type-card { cursor: pointer; transition: all 0.15s ease; }
  .type-card:hover { transform: translateY(-1px); }
  .type-card.selected { outline: 2px solid; outline-offset: 2px; }
  .gds-terminal { background: #0f172a; color: #4ade80; font-family: 'Courier New', monospace; }
  .gds-terminal::placeholder { color: #374151; }
  .seg-card { animation: slideIn 0.2s ease; }
  @keyframes slideIn { from { opacity: 0; transform: translateY(-4px); } to { opacity: 1; transform: translateY(0); } }
  .fare-row { animation: fadeIn 0.15s ease; }
  @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
</style>
</head>
<body class="bg-slate-50 font-sans">

<?php require __DIR__ . '/../layout/sidebar.php'; ?>

<div class="ml-60 min-h-screen">
<div class="max-w-7xl mx-auto px-6 py-8 space-y-6">

  <!-- Flash Error -->
  <?php if ($flashError): ?>
  <div class="flex items-start gap-3 bg-rose-50 border border-rose-200 rounded-xl p-4">
    <span class="material-symbols-outlined text-rose-500 mt-0.5 flex-none">error</span>
    <div>
      <p class="font-semibold text-rose-800 text-sm">Submission Error</p>
      <p class="text-rose-700 text-sm mt-0.5"><?= htmlspecialchars($flashError) ?></p>
    </div>
  </div>
  <?php endif; ?>

  <!-- Page Header -->
  <div class="flex items-center justify-between">
    <div>
      <div class="flex items-center gap-2 text-[11px] text-slate-400 font-medium mb-1">
        <a href="/acceptance" class="hover:text-primary-600 transition-colors">Acceptance Requests</a>
        <span class="material-symbols-outlined text-xs">chevron_right</span>
        <span class="text-slate-600 font-semibold">New Request</span>
      </div>
      <h1 class="text-2xl font-bold text-slate-900" style="font-family:Manrope,sans-serif;">New Acceptance Request</h1>
      <p class="text-slate-500 text-sm mt-1">Create a secure, tokenized authorization link for your customer.</p>
    </div>
    <a href="/acceptance" class="inline-flex items-center gap-2 bg-white border border-slate-200 text-slate-700 hover:bg-slate-50 font-semibold py-2 px-4 rounded-lg text-sm transition-colors shadow-sm">
      <span class="material-symbols-outlined text-base">list</span> All Requests
    </a>
  </div>

  <!-- ═══ MODE TOGGLE PILL ═══ -->
  <?php if ($isPromoting): ?>
  <div class="bg-indigo-50 border border-indigo-200 rounded-xl px-5 py-3.5 flex items-center gap-3">
    <span class="material-symbols-outlined text-indigo-600 text-lg">upgrade</span>
    <div class="flex-1">
      <p class="text-indigo-900 font-bold text-sm">Creating Full Acceptance from Pre-Auth #<?= $preauthRecord->id ?> &mdash; <?= htmlspecialchars($preauthRecord->customer_name) ?></p>
      <p class="text-indigo-600 text-xs mt-0.5">Customer info, PNR &amp; flights are pre-filled. Add fare breakdown, endorsements and split charges to complete.</p>
    </div>
    <a href="/acceptance/<?= $preauthRecord->id ?>" class="text-xs text-indigo-500 hover:underline flex-none">View pre-auth →</a>
  </div>
  <?php else: ?>
  <div class="bg-white border border-slate-200 rounded-xl px-5 py-4 flex flex-col sm:flex-row sm:items-center gap-4">
    <div class="flex-1">
      <p class="text-xs font-semibold" id="modeBannerText" style="color:<?= $initIsPreauth ? '#b45309' : '#1a3a6b' ?>">
        <?= $initIsPreauth
          ? '⚡ Quick Pre-Auth — sends total amount only to the customer. Send a Full Acceptance after ticketing with full breakdown.'
          : '📋 Full Acceptance — complete fare breakdown, split charges, endorsements and signed receipt.' ?>
      </p>
    </div>
    <div class="flex rounded-xl overflow-hidden border border-slate-200 flex-none text-sm font-bold shadow-sm">
      <button type="button" id="modeBtn-preauth" onclick="setMode('preauth')"
        class="px-4 py-2.5 flex items-center gap-1.5 transition-colors <?= $initIsPreauth ? 'bg-amber-500 text-white' : 'bg-white text-slate-600 hover:bg-amber-50' ?>">
        <span class="material-symbols-outlined text-base">bolt</span>
        Quick Pre-Auth
      </button>
      <button type="button" id="modeBtn-full" onclick="setMode('full')"
        class="px-4 py-2.5 flex items-center gap-1.5 border-l border-slate-200 transition-colors <?= !$initIsPreauth ? 'bg-primary-600 text-white' : 'bg-white text-slate-600 hover:bg-slate-50' ?>">
        <span class="material-symbols-outlined text-base">receipt_long</span>
        Full Acceptance
      </button>
    </div>
  </div>
  <?php endif; ?>

  <!-- Step Indicator -->
  <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-5">
    <div class="flex items-center">
      <?php
      $steps = ['Request Type','Customer & Passengers','Flight Details','Fare & Payment','Review & Send'];
      foreach ($steps as $i => $sLabel):
        $n = $i + 1;
        $isLast = ($n === count($steps));
      ?>
      <div class="flex items-center gap-2.5 <?= $isLast ? '' : 'flex-1' ?>">
        <div id="step-num-<?= $n ?>" class="w-8 h-8 rounded-full flex items-center justify-center text-sm font-bold flex-none
          <?= $n === 1 ? 'bg-primary-600 text-white' : 'bg-slate-100 text-slate-400' ?>">
          <?= $n ?>
        </div>
        <span id="step-label-<?= $n ?>" class="text-sm font-semibold whitespace-nowrap
          <?= $n === 1 ? 'text-slate-900' : 'text-slate-400' ?>">
          <?= $sLabel ?>
        </span>
      </div>
      <?php if (!$isLast): ?>
      <div id="step-line-<?= $n ?>" class="flex-1 mx-3 h-px bg-slate-200 min-w-[20px]"></div>
      <?php endif; ?>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- MAIN FORM -->
  <form id="acceptanceForm" method="POST" action="/acceptance/create" novalidate>
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
  <?php if ($pre['transaction_id']): ?>
  <input type="hidden" name="transaction_id" value="<?= $pre['transaction_id'] ?>">
  <?php endif; ?>

  <!-- Hidden: pre-auth flags -->
  <input type="hidden" name="is_preauth" id="hidIsPreauth" value="<?= $initIsPreauth ? '1' : '0' ?>">
  <input type="hidden" name="preauth_id"  id="hidPreauthId"  value="<?= $initPreauthId ?>">

  <!-- JSON Hidden Inputs (populated by JS before submit) -->
  <input type="hidden" name="passengers_json"       id="hidPassengers"      value="[]">
  <input type="hidden" name="flight_data_json"       id="hidFlightData"      value="null">
  <input type="hidden" name="fare_breakdown_json"    id="hidFareBreakdown"   value="[]">
  <input type="hidden" name="extra_data_json"        id="hidExtraData"       value="null">
  <input type="hidden" name="additional_cards_json"  id="hidAdditionalCards" value="null">

  <div style="display:grid; grid-template-columns:1fr 300px; gap:1.5rem; align-items:start;">

    <!-- LEFT: Steps Column -->
    <div style="min-width:0;">

      <!-- ═══════════════════════════════════════════════════════════════ -->
      <!-- STEP 1: Request Type                                            -->
      <!-- ═══════════════════════════════════════════════════════════════ -->
      <div id="step-1" class="step-panel active space-y-5">
        <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
          <div class="px-6 py-4 border-b border-slate-100 bg-slate-50/50">
            <h2 class="font-bold text-slate-900" style="font-family:Manrope,sans-serif;">What type of authorization is this?</h2>
            <p class="text-slate-500 text-sm mt-0.5">Select the transaction type — this determines the information the customer will see and authorize.</p>
          </div>
          <div class="p-6 grid grid-cols-1 sm:grid-cols-2 gap-3">
            <?php foreach ($typeCards as $tc):
              $c = $colorMap[$tc['color']];
              $isPreSelected = ($pre['type'] === $tc['value']) ? 'selected' : '';
            ?>
            <label class="type-card block <?= $c['bg'] ?> border-2 <?= $isPreSelected ? $c['ring'] . ' border-current' : 'border-transparent' ?> rounded-xl p-4 <?= $isPreSelected ? 'selected' : '' ?>"
                   id="tc-<?= $tc['value'] ?>"
                   onclick="selectType('<?= $tc['value'] ?>')">
              <input type="radio" name="type" value="<?= $tc['value'] ?>" class="sr-only"
                     id="type_<?= $tc['value'] ?>" <?= $isPreSelected ? 'checked' : '' ?>>
              <div class="flex items-start gap-3">
                <span class="material-symbols-outlined text-2xl <?= $c['icon'] ?> flex-none mt-0.5"><?= $tc['icon'] ?></span>
                <div class="min-w-0">
                  <div class="font-bold text-slate-900 text-sm leading-tight"><?= $tc['label'] ?></div>
                  <div class="text-xs text-slate-500 mt-0.5 leading-snug"><?= $tc['sub'] ?></div>
                </div>
              </div>
            </label>
            <?php endforeach; ?>
          </div>

          <!-- ── Other type description ─────────── -->
          <div id="section-other-desc" class="hidden mt-6 mx-6 mb-6 bg-amber-50 border border-amber-200 rounded-xl p-4">
            <h3 class="text-amber-900 font-bold text-sm flex items-center gap-1.5 mb-3">
              <span class="material-symbols-outlined text-lg">description</span>
              Charge Description <span class="text-[10px] bg-amber-100 px-2 py-0.5 rounded-full ml-1">Required for Other</span>
            </h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div>
                <label class="block text-[10px] font-bold text-amber-900 uppercase mb-1">Charge Title <span class="text-rose-500">*</span></label>
                <input type="text" id="field_other_title" placeholder="e.g. Airport transfer fee, Hotel booking..."
                       class="w-full border border-amber-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-amber-400 bg-white"
                       oninput="syncExtraData && syncExtraData()">
                <p class="text-[10px] text-amber-700 mt-1">A short title describing what is being charged.</p>
              </div>
              <div>
                <label class="block text-[10px] font-bold text-amber-900 uppercase mb-1">Reference / Booking Number</label>
                <input type="text" id="field_other_reference" placeholder="e.g. HTLXYZ1234, INV-001"
                       class="w-full border border-amber-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-amber-400 bg-white">
                <p class="text-[10px] text-amber-700 mt-1">Hotel confirmation, invoice #, or supplier ref.</p>
              </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-3">
              <div>
                <label class="block text-[10px] font-bold text-amber-900 uppercase mb-1">Service Provider</label>
                <input type="text" id="field_other_provider" placeholder="e.g. Marriott Hotels, Enterprise, Visa Office"
                       class="w-full border border-amber-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-amber-400 bg-white">
              </div>
              <div>
                <label class="block text-[10px] font-bold text-amber-900 uppercase mb-1">Payment Summary</label>
                <input type="text" id="field_other_payment_summary" placeholder="e.g. 2 nights × $200 = $400"
                       class="w-full border border-amber-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-amber-400 bg-white">
                <p class="text-[10px] text-amber-700 mt-1">Brief breakdown of how the charge is composed.</p>
              </div>
            </div>
            <div class="mt-3">
              <label class="block text-[10px] font-bold text-amber-900 uppercase mb-1">Additional Notes</label>
              <textarea id="field_other_notes" rows="2" placeholder="Any other context, instructions, or authorizations..."
                        class="w-full border border-amber-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-amber-400 bg-white resize-none"></textarea>
            </div>
          </div>
        </div>

        <div id="step1-error" class="hidden text-rose-600 text-sm font-medium flex items-center gap-1.5">
          <span class="material-symbols-outlined text-base">error</span>
          Please select a request type to continue.
        </div>

        <!-- Step 1 Nav -->
        <div class="flex justify-end">
          <button type="button" onclick="wizard.next()"
            class="inline-flex items-center gap-2 bg-primary-600 hover:bg-primary-500 text-white font-bold py-2.5 px-6 rounded-lg text-sm transition-colors shadow-sm">
            Next: Customer & Passengers <span class="material-symbols-outlined text-base">arrow_forward</span>
          </button>
        </div>
      </div><!-- /step-1 -->


      <!-- ═══════════════════════════════════════════════════════════════ -->
      <!-- STEP 2: Customer Info + Passengers                              -->
      <!-- ═══════════════════════════════════════════════════════════════ -->
      <div id="step-2" class="step-panel space-y-5">

        <!-- Customer Info -->
        <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
          <div class="px-6 py-4 border-b border-slate-100 bg-slate-50/50">
            <h2 class="font-bold text-slate-900" style="font-family:Manrope,sans-serif;">Customer Information</h2>
          </div>
          <div class="p-6 grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1.5" for="field_pnr">
                Booking Reference (PNR) <span class="text-rose-500">*</span>
              </label>
              <input type="text" name="pnr" id="field_pnr" required maxlength="10"
                     value="<?= $pre['pnr'] ?>" placeholder="e.g. ABCD12"
                     oninput="this.value=this.value.toUpperCase(); syncSummary();"
                     class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm font-mono font-bold text-slate-900 bg-slate-50 focus:outline-none focus:ring-2 focus:ring-primary-600 focus:border-transparent uppercase">
            </div>
            <div>
              <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1.5" for="field_order_id">
                Order / Record ID
              </label>
              <input type="text" name="order_id" id="field_order_id" maxlength="50"
                     placeholder="e.g. BF-10023"
                     class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm text-slate-700 bg-slate-50 focus:outline-none focus:ring-2 focus:ring-primary-600 focus:border-transparent">
            </div>
            <div>
              <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1.5" for="field_customer_name">
                Customer Full Name <span class="text-rose-500">*</span>
              </label>
              <input type="text" name="customer_name" id="field_customer_name" required
                     value="<?= $pre['customer_name'] ?>" placeholder="John Doe"
                     oninput="syncSummary();"
                     class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm text-slate-700 bg-slate-50 focus:outline-none focus:ring-2 focus:ring-primary-600 focus:border-transparent">
            </div>
            <div>
              <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1.5" for="field_customer_email">
                Customer Email <span class="text-rose-500">*</span>
              </label>
              <input type="email" name="customer_email" id="field_customer_email" required
                     value="<?= $pre['customer_email'] ?>" placeholder="john@example.com"
                     class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm text-slate-700 bg-slate-50 focus:outline-none focus:ring-2 focus:ring-primary-600 focus:border-transparent">
            </div>
            <div>
              <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1.5" for="field_customer_phone">
                Customer Phone
              </label>
              <input type="tel" name="customer_phone" id="field_customer_phone" maxlength="20"
                     value="<?= $pre['customer_phone'] ?>" placeholder="+1 416-555-0100"
                     class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm text-slate-700 bg-slate-50 focus:outline-none focus:ring-2 focus:ring-primary-600 focus:border-transparent">
            </div>
            <div>
              <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1.5" for="field_airline">
                Primary Airline <span class="text-rose-500">*</span>
              </label>
              <input type="text" name="airline" id="field_airline" maxlength="60" required
                     placeholder="e.g. Lufthansa"
                     class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm text-slate-700 bg-slate-50 focus:outline-none focus:ring-2 focus:ring-primary-600 focus:border-transparent">
            </div>
          </div>
        </div>

        <!-- Passengers -->
        <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
          <div class="px-6 py-4 border-b border-slate-100 bg-slate-50/50 flex items-center justify-between">
            <div>
              <h2 class="font-bold text-slate-900" style="font-family:Manrope,sans-serif;">Passengers</h2>
              <p class="text-slate-500 text-xs mt-0.5">Paste from GDS or add manually.</p>
            </div>
            <div class="flex gap-2">
              <button type="button" onclick="paxManager.toggleMode('gds')"
                id="btn-gds-mode" class="px-3 py-1.5 rounded-lg text-xs font-bold transition-colors bg-primary-600 text-white">
                GDS Paste
              </button>
              <button type="button" onclick="paxManager.toggleMode('manual')"
                id="btn-manual-mode" class="px-3 py-1.5 rounded-lg text-xs font-bold transition-colors bg-white border border-slate-200 text-slate-600 hover:bg-slate-50">
                Manual
              </button>
            </div>
          </div>
          <div class="p-6 space-y-4">

            <!-- GDS Mode -->
            <div id="pax-gds-mode">
              <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1.5">
                Paste GDS Passenger List (Amadeus format):
              </label>
              <textarea id="gds-pax-input" rows="4"
                placeholder="1.SMITH/JOHN MR&#10;2.SMITH/JANE MRS&#10;3.SMITH/TOMMY MSTR"
                class="gds-terminal w-full rounded-lg px-3 py-2.5 text-xs resize-none focus:outline-none focus:ring-2 focus:ring-emerald-500"
                oninput="paxManager.parseGDS(this.value)"></textarea>
              <div id="gds-pax-status" class="text-xs mt-1.5 min-h-[20px]"></div>
            </div>

            <!-- Manual Mode -->
            <div id="pax-manual-mode" class="hidden">
              <div id="manual-pax-list" class="space-y-3"></div>
              <button type="button" onclick="paxManager.addManual()"
                class="mt-3 inline-flex items-center gap-1.5 text-sm text-primary-600 font-semibold hover:text-primary-500 transition-colors">
                <span class="material-symbols-outlined text-base">add_circle</span> Add Passenger
              </button>
            </div>

            <!-- Parsed Preview (shown in both modes) -->
            <div id="pax-preview" class="hidden">
              <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-2">Identified Passengers:</p>
              <div id="pax-preview-list" class="flex flex-wrap gap-2"></div>
            </div>

            <div id="step2-pax-error" class="hidden text-rose-600 text-xs font-medium flex items-center gap-1">
              <span class="material-symbols-outlined text-sm">error</span>
              At least one passenger is required.
            </div>
          </div>
        </div>

        <!-- Step 2 Nav -->
        <div class="flex justify-between items-center">
          <button type="button" onclick="wizard.prev()"
            class="inline-flex items-center gap-2 bg-white border border-slate-200 text-slate-700 hover:bg-slate-50 font-bold py-2.5 px-4 rounded-lg text-sm transition-colors">
            <span class="material-symbols-outlined text-base">arrow_back</span> Back
          </button>
          <div id="step2-errors" class="flex-1 mx-4 text-rose-600 text-xs font-medium"></div>
          <button type="button" onclick="wizard.next()"
            class="inline-flex items-center gap-2 bg-primary-600 hover:bg-primary-500 text-white font-bold py-2.5 px-6 rounded-lg text-sm transition-colors shadow-sm">
            Next: Flight Details <span class="material-symbols-outlined text-base">arrow_forward</span>
          </button>
        </div>
      </div><!-- /step-2 -->

      <!-- ═══════════════════════════════════════════════════════════════ -->
      <!-- STEP 3: Flight Details (Type-Aware)                             -->
      <!-- ═══════════════════════════════════════════════════════════════ -->
      <div id="step-3" class="step-panel space-y-5">

        <!-- Section: Standard Itinerary (new_booking, seat_purchase, cabin_upgrade, name_correction) -->
        <div id="sec-itinerary" class="hidden bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
          <div class="px-6 py-4 border-b border-slate-100 bg-slate-50/50 flex items-center justify-between">
            <div>
              <h2 class="font-bold text-slate-900" style="font-family:Manrope,sans-serif;">Flight Itinerary</h2>
              <p class="text-xs text-slate-500 mt-0.5">Paste Amadeus GDS output or add segments manually.</p>
            </div>
            <span class="px-2 py-1 bg-blue-100 text-blue-700 text-[10px] font-bold rounded-full uppercase tracking-wider">GDS Format</span>
          </div>
          <div class="p-6 space-y-4">
            <textarea id="gds-main" rows="4" placeholder="1 LH 419 Y 12MAR 1 JFKFRA HK2  1040  0610+1&#10;2 LH 740 C 13MAR 2 FRAMUC HK1  0730  0845"
              class="gds-terminal w-full rounded-lg px-3 py-2.5 text-xs resize-none focus:outline-none focus:ring-2 focus:ring-emerald-500"
              oninput="flightMgr.parse('main', this.value)"></textarea>
            <div id="parse-status-main" class="text-xs min-h-[18px]"></div>
            <div id="segs-main" class="space-y-2"></div>
            <button type="button" onclick="flightMgr.addManual('main')"
              class="inline-flex items-center gap-1.5 text-sm text-primary-600 font-semibold hover:text-primary-500 transition-colors">
              <span class="material-symbols-outlined text-base">add_circle</span> Add Segment Manually
            </button>
          </div>
        </div>

        <!-- Section: Old Flights (exchange, cancel_refund, cancel_credit) -->
        <div id="sec-old-flights" class="hidden bg-white border border-rose-200 rounded-xl shadow-sm overflow-hidden">
          <div class="px-6 py-4 border-b border-rose-100 bg-rose-50/50 flex items-center justify-between">
            <div>
              <h2 class="font-bold text-rose-900" style="font-family:Manrope,sans-serif;">
                <span class="material-symbols-outlined text-base align-middle text-rose-600 mr-1">flight_land</span>
                Original Flights (Before Change)
              </h2>
              <p class="text-xs text-rose-600 mt-0.5">The flights the customer originally booked.</p>
            </div>
          </div>
          <div class="p-6 space-y-4">
            <textarea id="gds-old" rows="4" placeholder="1 LH 419 Y 10MAR 1 JFKFRA HK2  1040  0610+1&#10;Original GDS itinerary..."
              class="gds-terminal w-full rounded-lg px-3 py-2.5 text-xs resize-none focus:outline-none focus:ring-2 focus:ring-rose-500"
              oninput="flightMgr.parse('old', this.value)"></textarea>
            <div id="parse-status-old" class="text-xs min-h-[18px]"></div>
            <div id="segs-old" class="space-y-2"></div>
            <button type="button" onclick="flightMgr.addManual('old')"
              class="inline-flex items-center gap-1.5 text-sm text-rose-600 font-semibold hover:text-rose-500 transition-colors">
              <span class="material-symbols-outlined text-base">add_circle</span> Add Segment Manually
            </button>
          </div>
        </div>

        <!-- Section: New Flights (exchange only) -->
        <div id="sec-new-flights" class="hidden bg-white border border-emerald-200 rounded-xl shadow-sm overflow-hidden">
          <div class="px-6 py-4 border-b border-emerald-100 bg-emerald-50/50 flex items-center justify-between">
            <div>
              <h2 class="font-bold text-emerald-900" style="font-family:Manrope,sans-serif;">
                <span class="material-symbols-outlined text-base align-middle text-emerald-600 mr-1">flight_takeoff</span>
                New Flights (After Change)
              </h2>
              <p class="text-xs text-emerald-600 mt-0.5">The replacement flights after the date/route change.</p>
            </div>
          </div>
          <div class="p-6 space-y-4">
            <textarea id="gds-new" rows="4" placeholder="1 LH 419 Y 15MAR 1 JFKFRA HK2  1040  0610+1&#10;New GDS itinerary..."
              class="gds-terminal w-full rounded-lg px-3 py-2.5 text-xs resize-none focus:outline-none focus:ring-2 focus:ring-emerald-500"
              oninput="flightMgr.parse('new', this.value)"></textarea>
            <div id="parse-status-new" class="text-xs min-h-[18px]"></div>
            <div id="segs-new" class="space-y-2"></div>
            <button type="button" onclick="flightMgr.addManual('new')"
              class="inline-flex items-center gap-1.5 text-sm text-emerald-600 font-semibold hover:text-emerald-500 transition-colors">
              <span class="material-symbols-outlined text-base">add_circle</span> Add Segment Manually
            </button>
          </div>
        </div>

        <!-- Section: Name Correction extras -->
        <div id="sec-name-correction" class="hidden bg-white border border-yellow-200 rounded-xl shadow-sm overflow-hidden">
          <div class="px-6 py-4 border-b border-yellow-100 bg-yellow-50/50">
            <h2 class="font-bold text-yellow-900" style="font-family:Manrope,sans-serif;">Name Correction Details</h2>
          </div>
          <div class="p-6 grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1.5">Current (Wrong) Name</label>
              <input type="text" id="nc-old-name" placeholder="e.g. SMYTH/JOHN" oninput="syncExtraData()"
                class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-slate-50 focus:outline-none focus:ring-2 focus:ring-yellow-500 font-mono uppercase">
            </div>
            <div>
              <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1.5">Corrected Name</label>
              <input type="text" id="nc-new-name" placeholder="e.g. SMITH/JOHN" oninput="syncExtraData()"
                class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-slate-50 focus:outline-none focus:ring-2 focus:ring-yellow-500 font-mono uppercase">
            </div>
            <div class="sm:col-span-2">
              <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1.5">Correction Reason (Optional)</label>
              <input type="text" id="nc-reason" placeholder="e.g. Typo at time of booking" oninput="syncExtraData()"
                class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-slate-50 focus:outline-none focus:ring-2 focus:ring-yellow-500">
            </div>
          </div>
        </div>

        <!-- Section: Cabin Upgrade extras -->
        <div id="sec-cabin-upgrade" class="hidden bg-white border border-teal-200 rounded-xl shadow-sm overflow-hidden">
          <div class="px-6 py-4 border-b border-teal-100 bg-teal-50/50">
            <h2 class="font-bold text-teal-900" style="font-family:Manrope,sans-serif;">Cabin Upgrade Details</h2>
          </div>
          <div class="p-6 grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1.5">Current Cabin</label>
              <select id="cu-old-cabin" onchange="syncExtraData()"
                class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-slate-50 focus:outline-none focus:ring-2 focus:ring-teal-500">
                <option value="">-- Select --</option>
                <option>Economy</option><option>Premium Economy</option><option>Business</option><option>First</option>
              </select>
            </div>
            <div>
              <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1.5">Upgraded Cabin</label>
              <select id="cu-new-cabin" onchange="syncExtraData()"
                class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-slate-50 focus:outline-none focus:ring-2 focus:ring-teal-500">
                <option value="">-- Select --</option>
                <option>Economy</option><option>Premium Economy</option><option>Business</option><option>First</option>
              </select>
            </div>
          </div>
        </div>

        <!-- Section: Other / free-form -->
        <div id="sec-other-info" class="hidden bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
          <div class="px-6 py-4 border-b border-slate-100 bg-slate-50/50">
            <h2 class="font-bold text-slate-900" style="font-family:Manrope,sans-serif;">Authorization Details</h2>
          </div>
          <div class="p-6 space-y-4">
            <div>
              <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1.5">Description / Details</label>
              <textarea id="other-desc" rows="4" placeholder="Describe the service/charge being authorized..."
                class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-slate-50 resize-none focus:outline-none focus:ring-2 focus:ring-primary-600"
                oninput="syncExtraData()"></textarea>
            </div>

            <!-- Optional Flight Panel for Other -->
            <div class="border border-slate-200 rounded-lg overflow-hidden">
              <button type="button" onclick="toggleOtherFlights()"
                class="w-full flex items-center justify-between px-4 py-3 bg-slate-50 text-sm font-semibold text-slate-700 hover:bg-slate-100 transition-colors">
                <span class="flex items-center gap-2">
                  <span class="material-symbols-outlined text-base text-violet-600">connecting_airports</span>
                  Optional: Add Flight Details (GDS / Manual)
                </span>
                <span id="other-flights-chevron" class="material-symbols-outlined text-slate-400 transition-transform">expand_more</span>
              </button>
              <div id="other-flights-panel" class="hidden p-4 space-y-3 bg-white border-t border-slate-100">
                <p class="text-xs text-slate-500">If this charge is related to a flight, paste the GDS itinerary or add segments manually.</p>
                <label class="block text-[10px] font-bold text-violet-700 uppercase tracking-wider mb-1">Paste GDS Itinerary (Amadeus / Sabre)</label>
                <textarea id="gds-other" rows="3" placeholder="Paste GDS output here..."
                  class="gds-terminal w-full rounded-lg px-3 py-2.5 text-xs resize-none focus:outline-none focus:ring-2 focus:ring-violet-500"
                  oninput="flightMgr.parse('other', this.value)"></textarea>
                <div id="parse-status-other" class="text-xs min-h-[18px]"></div>
                <div id="segs-other" class="space-y-2"></div>
                <button type="button" onclick="flightMgr.addManual('other')"
                  class="inline-flex items-center gap-1.5 text-sm text-primary-600 font-semibold hover:text-primary-500 transition-colors">
                  <span class="material-symbols-outlined text-base">add_circle</span> Add Segment Manually
                </button>
              </div>
            </div>
          </div>
        </div>

        <div id="step3-errors" class="text-rose-600 text-xs font-medium min-h-[18px]"></div>

        <!-- Step 3 Nav -->
        <div class="flex justify-between items-center">
          <button type="button" onclick="wizard.prev()"
            class="inline-flex items-center gap-2 bg-white border border-slate-200 text-slate-700 hover:bg-slate-50 font-bold py-2.5 px-4 rounded-lg text-sm transition-colors">
            <span class="material-symbols-outlined text-base">arrow_back</span> Back
          </button>
          <button type="button" onclick="wizard.next()"
            class="inline-flex items-center gap-2 bg-primary-600 hover:bg-primary-500 text-white font-bold py-2.5 px-6 rounded-lg text-sm transition-colors shadow-sm">
            Next: Fare &amp; Payment <span class="material-symbols-outlined text-base">arrow_forward</span>
          </button>
        </div>
      </div><!-- /step-3 -->


      <!-- ═══════════════════════════════════════════════════════════════ -->
      <!-- STEP 4: Fare & Payment                                          -->
      <!-- ═══════════════════════════════════════════════════════════════ -->
      <div id="step-4" class="step-panel space-y-5">

        <!-- Fare Breakdown (full acceptance only) -->
        <div id="sec-fare-breakdown" class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
          <div class="px-6 py-4 border-b border-slate-100 bg-slate-50/50">
            <h2 class="font-bold text-slate-900" style="font-family:Manrope,sans-serif;">Fare Breakdown</h2>
            <p class="text-xs text-slate-500 mt-0.5">Add line items — total is calculated automatically.</p>
          </div>
          <div class="p-6 space-y-3">
            <div id="fare-items" class="space-y-2"></div>
            <button type="button" onclick="fareMgr.addItem()"
              class="inline-flex items-center gap-1.5 text-sm text-primary-600 font-semibold hover:text-primary-500 transition-colors">
              <span class="material-symbols-outlined text-base">add_circle</span> Add Line Item
            </button>
            <div class="border-t border-slate-100 pt-3 flex justify-between items-center">
              <span class="text-sm font-bold text-slate-700">Total Amount</span>
              <div class="flex items-center gap-2">
                <select name="currency" id="field_currency"
                  class="border border-slate-200 rounded-lg px-2 py-1.5 text-xs font-bold bg-slate-50 focus:outline-none focus:ring-2 focus:ring-primary-600">
                  <option value="USD" <?= $pre['currency'] === 'USD' ? 'selected' : '' ?>>USD</option>
                  <option value="CAD" <?= $pre['currency'] === 'CAD' ? 'selected' : '' ?>>CAD</option>
                  <option value="GBP" <?= $pre['currency'] === 'GBP' ? 'selected' : '' ?>>GBP</option>
                  <option value="EUR" <?= $pre['currency'] === 'EUR' ? 'selected' : '' ?>>EUR</option>
                  <option value="INR" <?= $pre['currency'] === 'INR' ? 'selected' : '' ?>>INR</option>
                  <option value="AED" <?= $pre['currency'] === 'AED' ? 'selected' : '' ?>>AED</option>
                  <option value="SGD" <?= $pre['currency'] === 'SGD' ? 'selected' : '' ?>>SGD</option>
                </select>
                <input type="number" name="total_amount" id="field_total_amount" step="0.01" min="0" required
                  value="<?= $pre['total_amount'] ?>" placeholder="0.00" oninput="syncSummary()"
                  class="w-32 border border-slate-200 rounded-lg px-3 py-1.5 text-lg font-bold text-emerald-700 bg-slate-50 focus:outline-none focus:ring-2 focus:ring-emerald-500">
              </div>
            </div>
            <div id="step4-amount-error" class="hidden text-rose-600 text-xs font-medium flex items-center gap-1">
              <span class="material-symbols-outlined text-sm">error</span> Total amount is required.
            </div>
          </div>
        </div>

        <!-- Pre-Auth: simple total only (shown only in preauth mode) -->
        <div id="sec-preauth-total" style="display:none;" class="bg-amber-50 border-2 border-amber-300 rounded-xl shadow-sm p-5">
          <p class="text-xs font-bold text-amber-800 uppercase tracking-wider mb-3">⚡ Pre-Auth — Total Amount Only</p>
          <p class="text-xs text-amber-700 mb-4">No fare breakdown needed. The customer will see only the total. You'll send a Full Acceptance with the complete breakdown after ticketing.</p>
          <div class="flex items-center gap-3">
            <select id="preauth_currency" onchange="document.getElementById('field_currency').value=this.value"
              class="border border-amber-300 rounded-lg px-2 py-2 text-xs font-bold bg-white focus:outline-none focus:ring-2 focus:ring-amber-500">
              <option value="USD" selected>USD</option><option value="CAD">CAD</option><option value="GBP">GBP</option><option value="EUR">EUR</option><option value="INR">INR</option><option value="AED">AED</option>
            </select>
            <input type="number" step="0.01" min="0" id="preauth_total"
              placeholder="Total amount e.g. 1250.00"
              oninput="document.getElementById('field_total_amount').value=this.value; syncSummary();"
              class="flex-1 border border-amber-300 rounded-lg px-3 py-2 text-lg font-black text-amber-900 bg-white focus:outline-none focus:ring-2 focus:ring-amber-500">
          </div>
        </div>


        <!-- sec-cancel-refund: shown when type = cancel_refund -->
        <div id="sec-cancel-refund" class="hidden bg-white border-2 border-rose-200 rounded-xl shadow-sm overflow-hidden">
          <div class="px-6 py-4 border-b border-rose-100 bg-rose-50/50 flex items-center gap-2">
            <span class="material-symbols-outlined text-rose-500">money_off</span>
            <div>
              <h2 class="font-bold text-rose-900" style="font-family:Manrope,sans-serif;">Cancellation &amp; Refund Details</h2>
              <p class="text-xs text-rose-600 mt-0.5">Required for cancel refund type. Shown to customer in authorization form.</p>
            </div>
          </div>
          <div class="p-6 space-y-4">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div>
                <label class="block text-[10px] font-bold text-rose-700 uppercase tracking-wider mb-1.5">Refund Amount <span class="text-rose-500">*</span></label>
                <input type="number" name="cr_refund_amount" id="field_cr_refund_amount" step="0.01" min="0" placeholder="0.00"
                  class="w-full border border-rose-200 rounded-lg px-3 py-2 text-sm font-mono font-bold text-rose-700 bg-rose-50 focus:outline-none focus:ring-2 focus:ring-rose-400">
                <p class="text-[10px] text-rose-500 mt-1">Amount to be refunded to customer (same currency as total).</p>
              </div>
              <div>
                <label class="block text-[10px] font-bold text-rose-700 uppercase tracking-wider mb-1.5">Cancellation Fee</label>
                <input type="number" name="cr_cancel_fee" id="field_cr_cancel_fee" step="0.01" min="0" placeholder="0.00"
                  class="w-full border border-rose-200 rounded-lg px-3 py-2 text-sm font-mono bg-rose-50 focus:outline-none focus:ring-2 focus:ring-rose-400">
                <p class="text-[10px] text-rose-500 mt-1">Airline/supplier cancellation penalty (if any).</p>
              </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div>
                <label class="block text-[10px] font-bold text-rose-700 uppercase tracking-wider mb-1.5">Refund Method</label>
                <select name="cr_refund_method" id="field_cr_refund_method"
                  class="w-full border border-rose-200 rounded-lg px-3 py-2 text-sm bg-rose-50 focus:outline-none focus:ring-2 focus:ring-rose-400">
                  <option value="">-- Select --</option>
                  <option value="original_card">Original Card</option>
                  <option value="bank_transfer">Bank Transfer</option>
                  <option value="cheque">Cheque</option>
                  <option value="other">Other</option>
                </select>
              </div>
              <div>
                <label class="block text-[10px] font-bold text-rose-700 uppercase tracking-wider mb-1.5">Refund Timeline</label>
                <input type="text" name="cr_refund_timeline" id="field_cr_refund_timeline" placeholder="e.g. 7-10 business days"
                  class="w-full border border-rose-200 rounded-lg px-3 py-2 text-sm bg-rose-50 focus:outline-none focus:ring-2 focus:ring-rose-400">
              </div>
            </div>
          </div>
        </div>

        <!-- sec-cancel-credit: shown when type = cancel_credit -->
        <div id="sec-cancel-credit" class="hidden bg-white border-2 border-violet-200 rounded-xl shadow-sm overflow-hidden">
          <div class="px-6 py-4 border-b border-violet-100 bg-violet-50/50 flex items-center gap-2">
            <span class="material-symbols-outlined text-violet-500">savings</span>
            <div>
              <h2 class="font-bold text-violet-900" style="font-family:Manrope,sans-serif;">Future Credit Details</h2>
              <p class="text-xs text-violet-600 mt-0.5">Required for cancel credit type. Shown to customer in authorization form.</p>
            </div>
          </div>
          <div class="p-6 space-y-4">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div>
                <label class="block text-[10px] font-bold text-violet-700 uppercase tracking-wider mb-1.5">Future Credit Amount <span class="text-rose-500">*</span></label>
                <input type="number" name="cc_credit_amount" id="field_cc_credit_amount" step="0.01" min="0" placeholder="0.00"
                  class="w-full border border-violet-200 rounded-lg px-3 py-2 text-sm font-mono font-bold text-violet-700 bg-violet-50 focus:outline-none focus:ring-2 focus:ring-violet-400">
                <p class="text-[10px] text-violet-500 mt-1">Credit held with the airline for future re-booking.</p>
              </div>
              <div>
                <label class="block text-[10px] font-bold text-violet-700 uppercase tracking-wider mb-1.5">Credit Valid Until <span class="text-rose-500">*</span></label>
                <input type="date" name="cc_valid_until" id="field_cc_valid_until"
                  class="w-full border border-violet-200 rounded-lg px-3 py-2 text-sm bg-violet-50 focus:outline-none focus:ring-2 focus:ring-violet-400">
                <p class="text-[10px] text-violet-500 mt-1">Expiry date of the future travel credit.</p>
              </div>
            </div>
            <div>
              <label class="block text-[10px] font-bold text-violet-700 uppercase tracking-wider mb-1.5">E-Ticket Numbers (one per passenger)</label>
              <p class="text-[10px] text-violet-500 mb-2">Enter the original e-ticket number for each passenger being cancelled and converted to credit.</p>
              <div id="credit-etkt-rows" class="space-y-2"></div>
              <button type="button" onclick="creditEtktMgr.addRow()"
                class="mt-2 inline-flex items-center gap-1 text-xs text-violet-600 font-semibold hover:text-violet-800 transition-colors">
                <span class="material-symbols-outlined text-sm">add_circle</span> Add Passenger Row
              </button>
            </div>
            <div>
              <label class="block text-[10px] font-bold text-violet-700 uppercase tracking-wider mb-1.5">Credit Instructions</label>
              <textarea name="cc_instructions" id="field_cc_instructions" rows="2"
                placeholder="e.g. Credit valid for re-booking on same airline. Subject to fare difference at time of re-booking."
                class="w-full border border-violet-200 rounded-lg px-3 py-2 text-sm bg-violet-50 resize-none focus:outline-none focus:ring-2 focus:ring-violet-400"></textarea>
            </div>
          </div>
        </div>

        <!-- Payment Details -->
        <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
          <div class="px-6 py-4 border-b border-slate-100 bg-slate-50/50">
            <h2 class="font-bold text-slate-900" style="font-family:Manrope,sans-serif;">Payment Details</h2>
          </div>
          <div class="p-6 space-y-4">
            <!-- Row 1: Card type + Cardholder -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div>
                <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1.5">Card Type <span class="text-rose-500">*</span></label>
                <select name="card_type" id="field_card_type" required
                  class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-slate-50 focus:outline-none focus:ring-2 focus:ring-primary-600">
                  <option value="">-- Select --</option>
                  <option>Visa</option><option>Mastercard</option><option>Amex</option><option>Discover</option><option>UnionPay</option>
                </select>
              </div>
              <div>
                <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1.5">Cardholder Name <span class="text-rose-500">*</span></label>
                <input type="text" name="cardholder_name" id="field_cardholder_name" required placeholder="As it appears on card"
                  class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-slate-50 focus:outline-none focus:ring-2 focus:ring-primary-600">
              </div>
            </div>
            <!-- Row 2: Full Card Number + Expiry + CVV -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
              <div class="sm:col-span-1">
                <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1.5">Card Number <span class="text-rose-500">*</span></label>
                <input type="text" name="card_number" id="field_card_number" required
                  maxlength="19" placeholder="•••• •••• •••• ••••"
                  oninput="this.value=this.value.replace(/[^\d\s]/g,'').replace(/(\d{4})(?=\d)/g,'$1 ').trim().slice(0,19); syncSummary();"
                  class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm font-mono font-bold tracking-widest bg-slate-50 focus:outline-none focus:ring-2 focus:ring-primary-600">
              </div>
              <div>
                <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1.5">Expiry <span class="text-rose-500">*</span></label>
                <input type="text" name="card_expiry" id="field_card_expiry" required
                  maxlength="5" placeholder="MM/YY"
                  oninput="let v=this.value.replace(/\D/g,''); if(v.length>=3) v=v.slice(0,2)+'/'+v.slice(2,4); this.value=v;"
                  class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm font-mono font-bold tracking-widest bg-slate-50 focus:outline-none focus:ring-2 focus:ring-primary-600">
              </div>
              <div>
                <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1.5">CVV <span class="text-rose-500">*</span></label>
                <input type="password" name="card_cvv" id="field_card_cvv" required
                  maxlength="4" placeholder="•••"
                  autocomplete="off"
                  class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm font-mono font-bold tracking-widest bg-slate-50 focus:outline-none focus:ring-2 focus:ring-primary-600">
              </div>
            </div>
            <p class="text-[10px] text-slate-400 flex items-center gap-1"><span class="material-symbols-outlined text-sm text-emerald-600">lock</span> Full CC details are AES-256 encrypted at rest. Only last 4 digits are shown to the customer.</p>
            <div>
              <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1.5">Billing Address <span class="text-rose-500">*</span></label>
              <textarea name="billing_address" id="field_billing_address" required rows="2"
                placeholder="123 Main St, City, Province/State, Postal Code, Country"
                class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-slate-50 resize-none focus:outline-none focus:ring-2 focus:ring-primary-600"></textarea>
            </div>
            <div>
              <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1.5">Statement Descriptor</label>
              <input type="text" name="statement_descriptor" placeholder="e.g. Lufthansa Airlines / Date Change Fee"
                class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-slate-50 focus:outline-none focus:ring-2 focus:ring-primary-600">
            </div>
            <div id="sec-split-charge">
              <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1.5">Split Charge Note</label>
              <input type="text" name="split_charge_note" placeholder="e.g. Card 1: $500, Card 2: $320"
                class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-slate-50 focus:outline-none focus:ring-2 focus:ring-primary-600">
            </div>

            <!-- Additional Cards -->
            <div>
              <div class="flex items-center justify-between mb-2">
                <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider">Additional Cards (Split Charge)</label>
                <button type="button" onclick="cardMgr.add()"
                  class="inline-flex items-center gap-1 text-xs text-primary-600 font-semibold hover:text-primary-500 transition-colors">
                  <span class="material-symbols-outlined text-sm">add_circle</span> Add Card
                </button>
              </div>
              <div id="additional-cards" class="space-y-2"></div>
            </div>
          </div>
        </div>

        <!-- Endorsements, Baggage & Fare Rules (full acceptance only) -->
        <div id="sec-ticket-conditions" class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
          <div class="px-6 py-4 border-b border-slate-100 bg-slate-50/50">
            <h2 class="font-bold text-slate-900" style="font-family:Manrope,sans-serif;">Ticket Conditions</h2>
          </div>
          <div class="p-6 space-y-4">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div>
                <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1.5">Endorsements</label>
                <input type="text" name="endorsements" value="NON END/NON REF/NON RRT" placeholder="e.g. NON END/NON REF/NON RRT"
                  class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm font-mono bg-slate-50 focus:outline-none focus:ring-2 focus:ring-primary-600">
              </div>
              <div>
                <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1.5">Baggage Info</label>
                <input type="text" name="baggage_info" placeholder="e.g. 1PC 23KG checked, 7KG carry-on"
                  class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-slate-50 focus:outline-none focus:ring-2 focus:ring-primary-600">
              </div>
            </div>
            <div>
              <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1.5">Fare Rules</label>
              <textarea name="fare_rules" rows="3"
                placeholder="Exchange: permitted with fee. Cancellation: non-refundable. Name changes: not permitted..."
                class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-slate-50 resize-none focus:outline-none focus:ring-2 focus:ring-primary-600"></textarea>
            </div>
          </div>
        </div>

        <!-- Policy & Options -->
        <div class="bg-white border border-amber-200 rounded-xl shadow-sm overflow-hidden">
          <div class="px-6 py-4 border-b border-amber-100 bg-amber-50/40">
            <h2 class="font-bold text-slate-900 flex items-center gap-2" style="font-family:Manrope,sans-serif;">
              <span class="material-symbols-outlined text-amber-600 text-base">shield</span>
              Authorization Policy &amp; Options
            </h2>
          </div>
          <div class="p-6 space-y-4">
            <div>
              <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1.5">Policy / Terms Text (Customer Will Read &amp; Agree To)</label>
              <textarea name="policy_text" rows="5"
                class="w-full border border-slate-200 rounded-lg px-3 py-2 text-xs bg-slate-50 resize-none focus:outline-none focus:ring-2 focus:ring-primary-600"
><?= htmlspecialchars($defaultPolicy) ?></textarea>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-2">
              <label class="flex items-center gap-3 p-3 bg-slate-50 rounded-lg border border-slate-200 cursor-pointer hover:bg-slate-100 transition-colors">
                <input type="checkbox" name="req_passport" value="1" class="w-4 h-4 rounded accent-amber-500 flex-none">
                <div>
                  <div class="text-sm font-semibold text-slate-800">Require Passport / ID Scan</div>
                  <div class="text-xs text-slate-500">Customer must upload photo ID before signing</div>
                </div>
              </label>
              <label class="flex items-center gap-3 p-3 bg-slate-50 rounded-lg border border-slate-200 cursor-pointer hover:bg-slate-100 transition-colors">
                <input type="checkbox" name="req_cc_front" value="1" class="w-4 h-4 rounded accent-amber-500 flex-none">
                <div>
                  <div class="text-sm font-semibold text-slate-800">Require CC Front Scan</div>
                  <div class="text-xs text-slate-500">For high-risk bookings — extra chargeback defense</div>
                </div>
              </label>
            </div>
            <div>
              <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1.5" for="field_agent_notes">Agent Notes (Internal Only) <span class="text-rose-500">*</span></label>
              <textarea name="agent_notes" id="field_agent_notes" rows="2" placeholder="Internal notes not visible to customer..."
                class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-slate-50 resize-none focus:outline-none focus:ring-2 focus:ring-primary-600"><?= $pre['agent_notes'] ?></textarea>
            </div>
          </div>
        </div>

        <div id="step4-errors" class="text-rose-600 text-xs font-medium min-h-[18px]"></div>

        <!-- Step 4 Nav -->
        <div class="flex justify-between items-center">
          <button type="button" onclick="wizard.prev()"
            class="inline-flex items-center gap-2 bg-white border border-slate-200 text-slate-700 hover:bg-slate-50 font-bold py-2.5 px-4 rounded-lg text-sm transition-colors">
            <span class="material-symbols-outlined text-base">arrow_back</span> Back
          </button>
          <button type="button" onclick="wizard.next()"
            class="inline-flex items-center gap-2 bg-primary-600 hover:bg-primary-500 text-white font-bold py-2.5 px-6 rounded-lg text-sm transition-colors shadow-sm">
            Review &amp; Send <span class="material-symbols-outlined text-base">arrow_forward</span>
          </button>
        </div>
      </div><!-- /step-4 -->


      <!-- ═══════════════════════════════════════════════════════════════ -->
      <!-- STEP 5: Review & Send                                           -->
      <!-- ═══════════════════════════════════════════════════════════════ -->
      <div id="step-5" class="step-panel space-y-5">

        <!-- Preview Panel -->
        <div class="bg-white border border-primary-100 rounded-xl shadow-sm overflow-hidden">
          <div class="px-6 py-4 border-b border-primary-100" style="background:linear-gradient(135deg,#0f1e3c,#1a3a6b);">
            <div class="flex items-center justify-between">
              <div>
                <p class="text-[10px] font-bold text-blue-300 uppercase tracking-widest">Lets Fly Travel DBA Base Fare</p>
                <h2 class="text-white font-bold text-lg mt-0.5" style="font-family:Manrope,sans-serif;">Authorization Preview</h2>
                <p class="text-blue-200 text-xs mt-0.5">Review before sending. This cannot be edited after generation.</p>
              </div>
              <span id="preview-type-badge" class="px-3 py-1.5 bg-white/10 text-white text-xs font-bold rounded-full border border-white/20"></span>
            </div>
          </div>

          <!-- Preview Grid -->
          <div class="p-6 space-y-5" id="preview-body">

            <!-- Basic Info -->
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
              <div class="bg-slate-50 rounded-lg p-3 border border-slate-100">
                <p class="text-[9px] font-bold text-slate-400 uppercase tracking-wider">PNR</p>
                <p id="prev-pnr" class="text-sm font-black font-mono text-primary-600 mt-1">—</p>
              </div>
              <div class="bg-slate-50 rounded-lg p-3 border border-slate-100">
                <p class="text-[9px] font-bold text-slate-400 uppercase tracking-wider">Customer</p>
                <p id="prev-name" class="text-sm font-semibold text-slate-900 mt-1 truncate">—</p>
              </div>
              <div class="bg-slate-50 rounded-lg p-3 border border-slate-100">
                <p class="text-[9px] font-bold text-slate-400 uppercase tracking-wider">Email</p>
                <p id="prev-email" class="text-xs font-medium text-slate-700 mt-1 truncate">—</p>
              </div>
              <div class="bg-slate-50 rounded-lg p-3 border border-slate-100">
                <p class="text-[9px] font-bold text-slate-400 uppercase tracking-wider">Passengers</p>
                <p id="prev-pax-count" class="text-sm font-bold text-slate-900 mt-1">0</p>
              </div>
            </div>

            <!-- Passengers list -->
            <div id="prev-pax-section" class="hidden">
              <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-2">Passenger Names</p>
              <div id="prev-pax-list" class="flex flex-wrap gap-2"></div>
            </div>

            <!-- Flight segments -->
            <div id="prev-flights-section" class="hidden">
              <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-2">Flight Segments</p>
              <div id="prev-flights" class="space-y-2"></div>
            </div>

            <!-- Fare -->
            <div id="prev-fare-section" class="hidden">
              <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-2">Fare Breakdown</p>
              <div id="prev-fare" class="bg-slate-50 rounded-lg border border-slate-100 overflow-hidden">
                <table class="w-full text-sm"><tbody id="prev-fare-rows"></tbody></table>
                <div class="flex justify-between items-center px-3 py-2 bg-primary-600 text-white">
                  <span class="font-bold text-xs uppercase tracking-wider">Total</span>
                  <span id="prev-total" class="font-black font-mono text-base">—</span>
                </div>
              </div>
            </div>

            <!-- Card -->
            <div id="prev-card-section" class="hidden">
              <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-2">Payment Card</p>
              <div class="flex items-center gap-3 p-3 bg-slate-50 rounded-lg border border-slate-100">
                <span id="prev-card-icon" class="material-symbols-outlined text-slate-500">credit_card</span>
                <div>
                  <p id="prev-card-holder" class="text-sm font-semibold text-slate-900">—</p>
                  <p id="prev-card-num" class="text-xs font-mono text-slate-500">—</p>
                </div>
              </div>
            </div>

            <!-- Future Credit Details (cancel_credit only) -->
            <div id="prev-credit-section" class="hidden">
              <p class="text-[10px] font-bold text-violet-500 uppercase tracking-wider mb-2">Future Travel Credit Summary</p>
              <div class="bg-violet-50 rounded-lg border border-violet-200 p-4 space-y-3">
                <div class="grid grid-cols-2 gap-3">
                  <div>
                    <p class="text-[9px] font-bold text-violet-400 uppercase tracking-wider">Credit Value</p>
                    <p id="prev-credit-amount" class="text-base font-black text-violet-700 mt-1 font-mono">—</p>
                  </div>
                  <div>
                    <p class="text-[9px] font-bold text-violet-400 uppercase tracking-wider">Valid Until</p>
                    <p id="prev-credit-valid" class="text-sm font-semibold text-violet-700 mt-1">—</p>
                  </div>
                </div>
                <div id="prev-credit-etkt-wrap" class="hidden">
                  <p class="text-[9px] font-bold text-violet-400 uppercase tracking-wider mb-1">E-Ticket Numbers</p>
                  <div id="prev-credit-etkts" class="flex flex-wrap gap-1.5"></div>
                </div>
                <div id="prev-credit-instr-wrap" class="hidden">
                  <p class="text-[9px] font-bold text-violet-400 uppercase tracking-wider mb-1">Credit Instructions</p>
                  <p id="prev-credit-instructions" class="text-xs text-violet-700"></p>
                </div>
              </div>
            </div>

            <!-- Cancel/Refund Details (cancel_refund only) -->
            <div id="prev-refund-section" class="hidden">
              <p class="text-[10px] font-bold text-rose-500 uppercase tracking-wider mb-2">Cancellation &amp; Refund Details</p>
              <div class="bg-rose-50 rounded-lg border border-rose-200 p-4 space-y-3">
                <div class="grid grid-cols-2 gap-3">
                  <div>
                    <p class="text-[9px] font-bold text-rose-400 uppercase tracking-wider">Refund Amount</p>
                    <p id="prev-refund-amount" class="text-base font-black text-rose-700 mt-1 font-mono">—</p>
                  </div>
                  <div>
                    <p class="text-[9px] font-bold text-rose-400 uppercase tracking-wider">Cancellation Fee</p>
                    <p id="prev-cancel-fee" class="text-sm font-semibold text-rose-700 mt-1">—</p>
                  </div>
                </div>
                <div id="prev-refund-method-wrap" class="hidden">
                  <p class="text-[9px] font-bold text-rose-400 uppercase tracking-wider mb-1">Refund Method / Timeline</p>
                  <p id="prev-refund-method" class="text-xs text-rose-700"></p>
                </div>
              </div>
            </div>

            <!-- Link expiry notice -->
            <div class="flex items-start gap-3 p-3 bg-amber-50 border border-amber-200 rounded-lg">
              <span class="material-symbols-outlined text-amber-600 text-base flex-none mt-0.5">schedule</span>
              <p class="text-xs text-amber-800 font-medium">
                The authorization link will expire <strong>12 hours</strong> after generation.
                The customer will receive a secure link via email (and you can copy it as backup).
              </p>
            </div>
          </div>
        </div>

        <!-- Step 5 Nav + Submit -->
        <div class="flex justify-between items-center">
          <button type="button" onclick="wizard.prev()"
            class="inline-flex items-center gap-2 bg-white border border-slate-200 text-slate-700 hover:bg-slate-50 font-bold py-2.5 px-4 rounded-lg text-sm transition-colors">
            <span class="material-symbols-outlined text-base">arrow_back</span> Back &amp; Edit
          </button>
          <button type="button" id="btn-generate" onclick="formAssembly.submit()"
            class="inline-flex items-center gap-2 bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-3 px-8 rounded-lg text-base transition-colors shadow-md">
            <span class="material-symbols-outlined">send</span> Generate &amp; Send Link
          </button>
        </div>
      </div><!-- /step-5 -->

    </div><!-- /LEFT steps column -->


    <!-- ═══════════════════════════════════════════════════════════════ -->
    <!-- RIGHT: Sticky Sidebar                                           -->
    <!-- ═══════════════════════════════════════════════════════════════ -->
    <div style="position:sticky; top:24px; width:300px; display:flex; flex-direction:column; gap:1rem;">

      <!-- Draft Summary -->
      <div class="bg-white border border-primary-100 rounded-xl shadow-sm overflow-hidden">
        <div class="px-4 py-3 border-b border-slate-100 flex items-center gap-2" style="background:#0f1e3c;">
          <span class="material-symbols-outlined text-gold text-base">edit_note</span>
          <span class="text-sm font-bold text-white">Draft Summary</span>
        </div>
        <div class="p-4 space-y-2.5">
          <div class="flex justify-between items-start">
            <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Type</span>
            <span id="sum-type" class="text-xs font-semibold text-slate-700 text-right max-w-[130px]">—</span>
          </div>
          <div class="flex justify-between items-center">
            <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">PNR</span>
            <span id="sum-pnr" class="text-xs font-black font-mono text-primary-600">—</span>
          </div>
          <div class="flex justify-between items-center">
            <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Customer</span>
            <span id="sum-name" class="text-xs font-semibold text-slate-800 text-right max-w-[130px] truncate">—</span>
          </div>
          <div class="flex justify-between items-center">
            <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Passengers</span>
            <span id="sum-pax" class="text-xs font-bold text-slate-900">0</span>
          </div>
          <div class="flex justify-between items-center">
            <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Total</span>
            <span id="sum-total" class="text-sm font-black text-emerald-700">—</span>
          </div>
          <div class="flex justify-between items-center">
            <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Step</span>
            <span id="sum-step" class="text-xs font-bold text-primary-600">1 / 5</span>
          </div>
        </div>
      </div>

      <!-- Recent Requests -->
      <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
        <div class="px-4 py-3 border-b border-slate-100 flex items-center justify-between">
          <span class="text-sm font-semibold text-slate-700">Recent</span>
          <span class="material-symbols-outlined text-slate-400 text-base">history</span>
        </div>
        <?php
        // Load 5 most recent acceptance requests for context
        try {
            $recent = AcceptanceRequest::orderByDesc('id')->limit(5)->get(['id','customer_name','total_amount','currency','status','type']);
        } catch (\Throwable $e) {
            $recent = collect();
        }
        ?>
        <div class="divide-y divide-slate-50">
          <?php if ($recent->isEmpty()): ?>
          <div class="p-4 text-[11px] text-slate-400 text-center italic">No recent requests.</div>
          <?php else: ?>
          <?php foreach ($recent as $rec):
            $sc = match($rec->status) {
              'APPROVED' => 'bg-emerald-100 text-emerald-700',
              'PENDING'  => 'bg-amber-100 text-amber-700',
              'EXPIRED'  => 'bg-rose-100 text-rose-700',
              default    => 'bg-slate-100 text-slate-600',
            };
          ?>
          <a href="/acceptance/<?= $rec->id ?>" class="flex items-center justify-between px-4 py-2.5 hover:bg-slate-50 transition-colors">
            <div class="min-w-0">
              <div class="text-xs font-semibold text-slate-800 truncate"><?= htmlspecialchars($rec->customer_name) ?></div>
              <div class="text-[10px] text-slate-400"><?= htmlspecialchars($rec->currency) ?> <?= number_format($rec->total_amount, 2) ?></div>
            </div>
            <span class="flex-none px-2 py-0.5 <?= $sc ?> text-[10px] font-bold rounded-full"><?= $rec->status ?></span>
          </a>
          <?php endforeach; ?>
          <?php endif; ?>
        </div>
        <div class="px-4 py-2.5 border-t border-slate-50">
          <a href="/acceptance" class="text-xs text-primary-600 font-semibold hover:underline">View all →</a>
        </div>
      </div>

      <!-- Security Note -->
      <div class="p-4 bg-primary-50 border border-primary-100 rounded-xl">
        <div class="flex items-start gap-2">
          <span class="material-symbols-outlined text-primary-600 text-base flex-none">lock</span>
          <div>
            <p class="text-[11px] font-bold text-primary-800">12-Hour Token Expiry</p>
            <p class="text-[10px] text-primary-600 mt-0.5 leading-relaxed">Links automatically expire 12 hours after creation. Resend from the request detail view if needed.</p>
          </div>
        </div>
      </div>

    </div><!-- /sidebar -->

  </div><!-- /grid -->
  </form><!-- /acceptanceForm -->

</div><!-- /max-w -->
</div><!-- /ml-60 -->

<script>
// ─────────────────────────────────────────────────────────────────────────────
// LOOKUP MAPS
// ─────────────────────────────────────────────────────────────────────────────
const AIRLINES = {
  'AC':'Air Canada','WS':'WestJet','AA':'American Airlines','DL':'Delta','UA':'United',
  'BA':'British Airways','LH':'Lufthansa','AF':'Air France','KL':'KLM','EK':'Emirates',
  'QR':'Qatar Airways','SQ':'Singapore Airlines','CX':'Cathay Pacific','JL':'Japan Airlines',
  'NH':'ANA','TK':'Turkish Airlines','EY':'Etihad','LX':'Swiss','OS':'Austrian',
  'AI':'Air India','TP':'TAP Portugal','VS':'Virgin Atlantic','AM':'Aeromexico',
  'CM':'Copa Airlines','AV':'Avianca','LA':'LATAM','QF':'Qantas','NZ':'Air New Zealand',
  'KE':'Korean Air','BR':'EVA Air','CI':'China Airlines','CZ':'China Southern',
  'MU':'China Eastern','CA':'Air China','HU':'Hainan Airlines','MH':'Malaysia Airlines',
  'TG':'Thai Airways','VN':'Vietnam Airlines','PR':'Philippine Airlines','GA':'Garuda',
  'UL':'SriLankan','KU':'Kuwait Airways','WY':'Oman Air','GF':'Gulf Air','SV':'Saudia',
  'MS':'EgyptAir','ET':'Ethiopian Airlines','AT':'Royal Air Maroc','KQ':'Kenya Airways',
  'F9':'Frontier','NK':'Spirit','B6':'JetBlue','WN':'Southwest','AS':'Alaska Airlines',
  'HA':'Hawaiian Airlines','G4':'Allegiant','VX':'Virgin America','AD':'Azul',
  'O6':'Avianca Brasil','JJ':'LATAM Brasil','AR':'Aerolíneas Argentinas'
};

const CITIES = {
  'YYZ':'Toronto','YVR':'Vancouver','YUL':'Montreal','YYC':'Calgary','YEG':'Edmonton',
  'YOW':'Ottawa','YHZ':'Halifax','YWG':'Winnipeg',
  'LHR':'London Heathrow','LGW':'London Gatwick','CDG':'Paris de Gaulle','ORY':'Paris Orly',
  'FRA':'Frankfurt','AMS':'Amsterdam','MAD':'Madrid','BCN':'Barcelona','FCO':'Rome Fiumicino',
  'MXP':'Milan Malpensa','ZRH':'Zurich','GVA':'Geneva','MUC':'Munich','LIS':'Lisbon',
  'IST':'Istanbul','SAW':'Istanbul Sabiha','ATH':'Athens','VIE':'Vienna','BRU':'Brussels',
  'CPH':'Copenhagen','ARN':'Stockholm','OSL':'Oslo','HEL':'Helsinki',
  'DXB':'Dubai','DOH':'Doha','AUH':'Abu Dhabi','BAH':'Bahrain','KWI':'Kuwait',
  'MCT':'Muscat','SHJ':'Sharjah',
  'BOM':'Mumbai','DEL':'New Delhi','BLR':'Bangalore','MAA':'Chennai','HYD':'Hyderabad',
  'CCU':'Kolkata','COK':'Kochi','TRV':'Trivandrum','AMD':'Ahmedabad','PNQ':'Pune',
  'GOI':'Goa','JAI':'Jaipur','LKO':'Lucknow','ATQ':'Amritsar',
  'JFK':'New York JFK','EWR':'Newark','LGA':'LaGuardia','LAX':'Los Angeles',
  'SFO':'San Francisco','ORD':'Chicago O\'Hare','MDW':'Chicago Midway','MIA':'Miami',
  'DFW':'Dallas Ft Worth','SEA':'Seattle','BOS':'Boston','ATL':'Atlanta','IAH':'Houston Intercontinental',
  'HOU':'Houston Hobby','DEN':'Denver','PHX':'Phoenix','LAS':'Las Vegas','MCO':'Orlando',
  'FLL':'Fort Lauderdale','IAD':'Washington Dulles','DCA':'Washington Reagan','PHL':'Philadelphia',
  'CLT':'Charlotte','DTW':'Detroit','MSP':'Minneapolis','SLC':'Salt Lake City',
  'SYD':'Sydney','MEL':'Melbourne','BNE':'Brisbane','PER':'Perth','ADL':'Adelaide',
  'SIN':'Singapore','HKG':'Hong Kong','BKK':'Bangkok Suvarnabhumi','DMK':'Bangkok Don Mueang',
  'KUL':'Kuala Lumpur','CGK':'Jakarta','MNL':'Manila',
  'NRT':'Tokyo Narita','HND':'Tokyo Haneda','KIX':'Osaka','NGO':'Nagoya',
  'ICN':'Seoul Incheon','GMP':'Seoul Gimpo',
  'PEK':'Beijing Capital','PVG':'Shanghai Pudong','SHA':'Shanghai Hongqiao','CAN':'Guangzhou',
  'JNB':'Johannesburg','NBO':'Nairobi','CAI':'Cairo','ADD':'Addis Ababa',
  'CMN':'Casablanca','LOS':'Lagos',
  'MEX':'Mexico City','GRU':'São Paulo','EZE':'Buenos Aires','SCL':'Santiago','LIM':'Lima',
  'BOG':'Bogotá','GIG':'Rio de Janeiro'
};

const TYPE_LABELS = {
  'new_booking':'New Booking','exchange':'Exchange / Date Change',
  'cancel_refund':'Cancellation & Refund','cancel_credit':'Cancellation & Credit',
  'seat_purchase':'Seat Purchase','cabin_upgrade':'Cabin Upgrade',
  'name_correction':'Name Correction','other':'Other Authorization'
};

// ─────────────────────────────────────────────────────────────────────────────
// STATE
// ─────────────────────────────────────────────────────────────────────────────
const state = {
  step: 1,
  type: '',
  passengers: [],        // [{name:'', dob:'', paxType:'adult'}]
  segments: {            // group → [{airline_iata, flight_no, date, from, to, dep_time, arr_time, arr_next_day}]
    main: [], old: [], new: [], other: []
  },
  fareItems: [],         // [{label:'', amount:0}]
  extraCards: []         // [{cardholder_name:'', card_last_four:'', card_type:''}]
};

// ─────────────────────────────────────────────────────────────────────────────
// WIZARD — Step navigation
// ─────────────────────────────────────────────────────────────────────────────
const wizard = {
  totalSteps: 5,

  goTo: function(n) {
    if (n < 1 || n > this.totalSteps) return;
    // Hide all panels
    for (let i = 1; i <= this.totalSteps; i++) {
      const el = document.getElementById('step-' + i);
      if (el) el.classList.remove('active');
    }
    // Show target panel
    const target = document.getElementById('step-' + n);
    if (target) target.classList.add('active');
    // Update indicators
    for (let i = 1; i <= this.totalSteps; i++) {
      const numEl  = document.getElementById('step-num-' + i);
      const lblEl  = document.getElementById('step-label-' + i);
      const lineEl = document.getElementById('step-line-' + i);
      if (!numEl) continue;
      if (i < n) {
        numEl.className = 'w-8 h-8 rounded-full flex items-center justify-center text-sm font-bold flex-none bg-emerald-500 text-white';
        numEl.textContent = '✓';
        if (lblEl) lblEl.className = 'text-sm font-semibold whitespace-nowrap text-emerald-600';
      } else if (i === n) {
        numEl.className = 'w-8 h-8 rounded-full flex items-center justify-center text-sm font-bold flex-none bg-primary-600 text-white';
        numEl.textContent = String(i);
        if (lblEl) lblEl.className = 'text-sm font-semibold whitespace-nowrap text-slate-900';
      } else {
        numEl.className = 'w-8 h-8 rounded-full flex items-center justify-center text-sm font-bold flex-none bg-slate-100 text-slate-400';
        numEl.textContent = String(i);
        if (lblEl) lblEl.className = 'text-sm font-semibold whitespace-nowrap text-slate-400';
      }
    }
    state.step = n;
    const sumStep = document.getElementById('sum-step');
    if (sumStep) sumStep.textContent = n + ' / ' + this.totalSteps;
    window.scrollTo({ top: 0, behavior: 'smooth' });
    // On Step 5, build preview
    if (n === 5) preview.build();
  },

  next: function() {
    const { valid, msg } = this.validate(state.step);
    if (!valid) {
      this._showError(state.step, msg);
      return;
    }
    this._clearError(state.step);
    this.goTo(state.step + 1);
  },

  prev: function() {
    this._clearError(state.step);
    this.goTo(state.step - 1);
  },

  validate(step) {
    if (step === 1) {
      if (!state.type) {
        document.getElementById('step1-error').classList.remove('hidden');
        return { valid: false, msg: 'Select a request type.' };
      }
      // "Other Authorization" requires a Charge Title before proceeding
      if (state.type === 'other') {
        const otherTitle = (document.getElementById('field_other_title') ? document.getElementById('field_other_title').value : '').trim();
        if (!otherTitle) {
          document.getElementById('step1-error').classList.remove('hidden');
          return { valid: false, msg: 'A Charge Title is required for "Other Authorization". Please fill in the Charge Description box.' };
        }
      }
      document.getElementById('step1-error').classList.add('hidden');
      return { valid: true };
    }
    if (step === 2) {
      const pnr     = document.getElementById('field_pnr').value.trim();
      const name    = document.getElementById('field_customer_name').value.trim();
      const email   = document.getElementById('field_customer_email').value.trim();
      const airline = document.getElementById('field_airline').value.trim();
      const errors = [];
      if (!airline) errors.push('Primary airline is required.');
      if (!pnr)     errors.push('PNR is required.');
      if (!name)  errors.push('Customer name is required.');
      if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) errors.push('Valid email is required.');
      if (state.passengers.length === 0) {
        document.getElementById('step2-pax-error').classList.remove('hidden');
        errors.push('At least one passenger is required.');
      } else {
        document.getElementById('step2-pax-error').classList.add('hidden');
      }
      if (errors.length) return { valid: false, msg: errors.join(' ') };
      return { valid: true };
    }
    if (step === 3) {
      // All types except 'other' require at least one confirmed flight segment
      const needsFlights = state.type !== 'other';
      if (needsFlights) {
        const confirmed = (segs) => (segs || []).filter(s => !s._editing && s.from && s.to && s.flight_no).length > 0;
        const hasMain   = confirmed(state.segments.main);
        const hasOld    = confirmed(state.segments.old);
        if (!hasMain && !hasOld) {
          return { valid: false, msg: 'At least one confirmed flight segment is required. Add a segment and click the green ✓ button to confirm it.' };
        }
      }
      return { valid: true };
    }
    if (step === 4) {
      const total      = parseFloat(document.getElementById('field_total_amount').value);
      const cardType   = document.getElementById('field_card_type').value;
      const cardName   = document.getElementById('field_cardholder_name').value.trim();
      const cardNumber = document.getElementById('field_card_number').value.replace(/\s/g,'').trim();
      const cardExpiry = document.getElementById('field_card_expiry').value.trim();
      const cardCvv    = document.getElementById('field_card_cvv').value.trim();
      const billing    = document.getElementById('field_billing_address').value.trim();
      const agentNotes = document.getElementById('field_agent_notes').value.trim();
      const errs = [];
      if (!total || total <= 0) { errs.push('Total amount is required.'); document.getElementById('step4-amount-error').classList.remove('hidden'); }
      else document.getElementById('step4-amount-error').classList.add('hidden');
      if (!cardType)   errs.push('Card type is required.');
      if (!cardName)   errs.push('Cardholder name is required.');
      if (!/^\d{13,19}$/.test(cardNumber)) errs.push('Valid card number is required (13–19 digits).');
      if (!/^\d{2}\/\d{2}$/.test(cardExpiry)) errs.push('Expiry must be MM/YY format.');
      if (!/^\d{3,4}$/.test(cardCvv)) errs.push('CVV must be 3 or 4 digits.');
      if (!billing)    errs.push('Billing address is required.');
      if (!agentNotes) errs.push('Agent notes are required.');
      if (errs.length) return { valid: false, msg: errs.join(' ') };
      return { valid: true };
    }
    return { valid: true };
  },

  _showError(step, msg) {
    const ids = { 2:'step2-errors', 3:'step3-errors', 4:'step4-errors' };
    const el = document.getElementById(ids[step]);
    if (el) el.textContent = msg;
  },
  _clearError(step) {
    const ids = { 2:'step2-errors', 3:'step3-errors', 4:'step4-errors' };
    const el = document.getElementById(ids[step]);
    if (el) el.textContent = '';
  }
};

// ─────────────────────────────────────────────────────────────────────────────
// TYPE MANAGER
// ─────────────────────────────────────────────────────────────────────────────
function selectType(type) {
  state.type = type;
  // Update card styles
  Object.keys(TYPE_LABELS).forEach(t => {
    const card = document.getElementById('tc-' + t);
    const radio = document.getElementById('type_' + t);
    if (!card || !radio) return;
    if (t === type) {
      card.classList.add('selected');
      radio.checked = true;
    } else {
      card.classList.remove('selected');
      radio.checked = false;
    }
  });
  // Update sidebar
  document.getElementById('sum-type').textContent = TYPE_LABELS[type] || type;
  // Configure Step 3 sections
  const itinerary   = ['new_booking','seat_purchase','cabin_upgrade','name_correction'];
  const oldFlights  = ['exchange','cancel_refund','cancel_credit'];
  const newFlights  = ['exchange'];
  const nameCorrect = ['name_correction'];
  const cabinUpg    = ['cabin_upgrade'];
  const otherSec    = ['other'];

  _toggleSec('sec-itinerary',      itinerary.includes(type));
  _toggleSec('sec-old-flights',    oldFlights.includes(type));
  _toggleSec('sec-new-flights',    newFlights.includes(type));
  _toggleSec('sec-name-correction',nameCorrect.includes(type));
  _toggleSec('sec-cabin-upgrade',  cabinUpg.includes(type));
  _toggleSec('sec-other-info',     otherSec.includes(type));
  _toggleSec('section-other-desc', type === 'other');
  // Show type-specific cancel sections (only in full mode)
  _toggleSec('sec-cancel-refund',  type === 'cancel_refund' && _currentMode === 'full');
  _toggleSec('sec-cancel-credit',  type === 'cancel_credit' && _currentMode === 'full');
  // Sync credit e-ticket rows with passenger count if switching to cancel_credit
  if (type === 'cancel_credit') creditEtktMgr.syncFromPassengers();
}

function _toggleSec(id, show) {
  const el = document.getElementById(id);
  if (el) el.classList.toggle('hidden', !show);
}

function toggleOtherFlights() {
  const panel   = document.getElementById('other-flights-panel');
  const chevron = document.getElementById('other-flights-chevron');
  if (!panel) return;
  const isHidden = panel.classList.toggle('hidden');
  if (chevron) chevron.style.transform = isHidden ? '' : 'rotate(180deg)';
}

// ─────────────────────────────────────────────────────────────────────────────
// PASSENGER MANAGER
// ─────────────────────────────────────────────────────────────────────────────
const paxManager = {
  mode: 'gds',

  toggleMode: function(m) {
    this.mode = m;
    const gdsEl = document.getElementById('pax-gds-mode');
    const manualEl = document.getElementById('pax-manual-mode');
    const btnGds = document.getElementById('btn-gds-mode');
    const btnManual = document.getElementById('btn-manual-mode');
    if (gdsEl) gdsEl.classList.toggle('hidden', m !== 'gds');
    if (manualEl) manualEl.classList.toggle('hidden', m !== 'manual');
    if (btnGds) btnGds.className   = 'px-3 py-1.5 rounded-lg text-xs font-bold transition-colors ' + (m==='gds'?'bg-primary-600 text-white':'bg-white border border-slate-200 text-slate-600 hover:bg-slate-50');
    if (btnManual) btnManual.className = 'px-3 py-1.5 rounded-lg text-xs font-bold transition-colors ' + (m==='manual'?'bg-primary-600 text-white':'bg-white border border-slate-200 text-slate-600 hover:bg-slate-50');
    if (m === 'manual' && state.passengers.length === 0) this.addManual();
  },

  parseGDS: function(raw) {
    const statusEl  = document.getElementById('gds-pax-status');
    const previewEl = document.getElementById('pax-preview');
    if (!raw.trim()) {
      state.passengers = [];
      if (statusEl) statusEl.innerHTML = '';
      if (previewEl) previewEl.classList.add('hidden');
      this._syncCount();
      return;
    }
    // Regex: {idx}.LAST/FIRST TITLE(optional) optionally with (CHD/...) or INF/...
    const re = /\d+\s*\.\s*([A-Z][A-Z\-]+)\/([A-Z][A-Z\s\-]*?)(?:\s+(MR|MRS|MS|MISS|MSTR|DR|REV))?\s*(?:\((?:CHD|INF)[^)]*\))?(?:\s+INF\/\S+)?\s*$/gim;
    let m, pax = [];
    const lines = raw.split('\n');
    lines.forEach(function(line) {
      const ln = line.trim().replace(/\r/g,'');
      if (!ln) return;
      const match = /^\s*\d+\.?\s*([A-Z][A-Z\-]+)\/([A-Z][A-Z\s\-]*)(\s+(?:MR|MRS|MS|MISS|MSTR|DR|REV|CHD|INF))?/i.exec(ln);
      if (!match) return;
      const last  = match[1].trim().toUpperCase();
      const first = match[2].trim().toUpperCase().replace(/\s+$/,'');
      const title = match[3] ? match[3].trim().toUpperCase() : '';
      const isChd = /CHD|MSTR/i.test(title) || /\(CHD/i.test(ln);
      const isInf = /INF/i.test(title) || /INF\//i.test(ln);
      const paxType = isInf ? 'infant' : (isChd ? 'child' : 'adult');
      const name = last + '/' + first + (title && !['CHD','INF'].includes(title) ? ' ' + title : '');
      pax.push({ name: name, dob: '', paxType: paxType });
    });

    if (pax.length) {
      state.passengers = pax;
      if (statusEl) statusEl.innerHTML = '<span class="text-emerald-600 font-semibold">✓ Parsed ' + pax.length + ' passenger' + (pax.length>1?'s':'') + '</span>';
      this._renderPreview();
    } else {
      state.passengers = [];
      if (statusEl) statusEl.innerHTML = '<span class="text-rose-600">⚠ Could not parse — try: 1.SMITH/JOHN MR</span>';
      if (previewEl) previewEl.classList.add('hidden');
    }
    this._syncCount();
  },

  addManual: function() {
    state.passengers.push({ name: '', dob: '', paxType: 'adult' });
    this._renderManual();
    this._renderPreview();
    this._syncCount();
  },

  removePassenger: function(idx) {
    state.passengers.splice(idx, 1);
    this._renderManual();
    this._renderPreview();
    this._syncCount();
  },

  _updatePassenger: function(idx, field, val) {
    if (!state.passengers[idx]) return;
    state.passengers[idx][field] = val;
    if (field === 'name') this._renderPreview();
  },

  _renderManual: function() {
    const el = document.getElementById('manual-pax-list');
    el.innerHTML = state.passengers.map(function(p, i) { return `
      <div class="flex items-center gap-2 p-3 bg-slate-50 border border-slate-200 rounded-lg fare-row">
        <input type="text" value="${_esc(p.name)}" placeholder="SMITH/JOHN MR"
          class="flex-1 border border-slate-200 rounded-lg px-3 py-1.5 text-sm font-mono bg-white focus:outline-none focus:ring-2 focus:ring-primary-600 uppercase"
          oninput="paxManager._updatePassenger(${i},'name',this.value.toUpperCase())">
        <input type="date" value="${_esc(p.dob)}" title="Date of Birth"
          class="border border-slate-200 rounded-lg px-2 py-1.5 text-xs bg-white focus:outline-none focus:ring-2 focus:ring-primary-600"
          onchange="paxManager._updatePassenger(${i},'dob',this.value)">
        <select class="border border-slate-200 rounded-lg px-2 py-1.5 text-xs bg-white focus:outline-none focus:ring-2 focus:ring-primary-600"
          onchange="paxManager._updatePassenger(${i},'paxType',this.value)">
          <option value="adult" ${p.paxType==='adult'?'selected':''}>Adult</option>
          <option value="child" ${p.paxType==='child'?'selected':''}>Child</option>
          <option value="infant" ${p.paxType==='infant'?'selected':''}>Infant</option>
        </select>
        <button type="button" onclick="paxManager.removePassenger(${i})"
          class="p-1.5 bg-rose-100 hover:bg-rose-200 text-rose-700 rounded-lg transition-colors flex-none">
          <span class="material-symbols-outlined text-sm">delete</span>
        </button>
      </div>`; }).join('');
  },

  _renderPreview: function() {
    const previewEl  = document.getElementById('pax-preview');
    const previewList = document.getElementById('pax-preview-list');
    const filled = state.passengers.filter(function(p) { return p.name.trim(); });
    if (!filled.length) { if (previewEl) previewEl.classList.add('hidden'); return; }
    if (previewEl) previewEl.classList.remove('hidden');
    if (previewList) previewList.innerHTML = filled.map(function(p) { return `
      <span class="inline-flex items-center gap-1 px-2.5 py-1 bg-slate-100 text-slate-700 rounded-full text-xs font-mono font-semibold">
        <span class="material-symbols-outlined text-xs text-primary-600">person</span>${_esc(p.name)}
      </span>`;
    }).join('');
  },

  _syncCount: function() {
    const n = state.passengers.length;
    const el = document.getElementById('sum-pax');
    if (el) el.textContent = n;
  }
};

// ─────────────────────────────────────────────────────────────────────────────
// AMADEUS TIME FORMATTER
// ─────────────────────────────────────────────────────────────────────────────
function fmtTime(t) {
  if (!t) return '';
  const s = String(t).replace(/\s/g,'');
  // 24h: 1040 → 10:40
  if (/^\d{4}$/.test(s)) return s.substring(0,2) + ':' + s.substring(2);
  // 12h: 1040P → 22:40, 0215A → 02:15
  if (/^\d{3,4}[APap]$/.test(s)) {
    const isPM = /P$/i.test(s);
    const raw  = s.replace(/[APap]/,'').padStart(4,'0');
    let h = parseInt(raw.substring(0,2));
    const min = raw.substring(2,4);
    if (isPM && h !== 12) h += 12;
    if (!isPM && h === 12) h = 0;
    return (h<10?'0':'') + h + ':' + min;
  }
  return s;
}

// ─────────────────────────────────────────────────────────────────────────────
// FLIGHT MANAGER  (groups: 'main' | 'old' | 'new')
// ─────────────────────────────────────────────────────────────────────────────
const flightMgr = {

  // ── Multi-format GDS segment parser ──────────────────────────────────────
  // Supports: Amadeus, Sabre, Galileo/Travelport, Worldspan, Apollo
  _parseOneLine(ln) {
    ln = ln.replace(/\r/g, '').trim();
    if (!ln) return null;

    // Strategy 1: 6-char airport pair (JFKFRA, JFKMIA) — Amadeus & Sabre compact
    // e.g. "1  AA1758 Y 21APR JFKMIA DK1  0530 0842  21APR"
    //      "1 LH 419 Y 12MAR 1 JFKFRA HK2 1040 0610+1"
    const re6 = /^\s*\d{0,2}\s*\.?\s*([A-Z0-9]{2})\s*(\d{1,4}[A-Z]?)\s+([A-Z])\s+(\d{2}[A-Z]{3})(?:\s+\d)?\s+([A-Z]{3})([A-Z]{3})\s+[A-Z]{2,3}\d{0,2}\s+(\d{3,4}[APap]?)\s+(\d{3,4}[APap]?)(?:\+(\d))?(?:\s+(\d{2}[A-Z]{3}))?/i;
    let m = re6.exec(ln);
    if (m) return {
      airline_iata: m[1].toUpperCase(),
      flight_no:    m[1].toUpperCase() + m[2].toUpperCase(),
      cabin_class:  m[3].toUpperCase(),
      date:         m[4].toUpperCase(),
      from:         m[5].toUpperCase(),
      to:           m[6].toUpperCase(),
      dep_time:     fmtTime(m[7]),
      arr_time:     fmtTime(m[8]),
      arr_next_day: !!(m[9] && parseInt(m[9]) > 0) || !!(m[10] && m[10].toUpperCase() !== m[4].toUpperCase()),
    };

    // Strategy 2: Space-separated airports — Galileo/Travelport & Apollo
    // e.g. "1. UA  826 B 15MAR MO JFK ORD HK1 0800 0951"
    const re3 = /^\s*\d{0,2}\s*\.?\s*([A-Z0-9]{2})\s+(\d{1,4}[A-Z]?)\s+([A-Z])\s+(\d{2}[A-Z]{3})(?:\s+[A-Z]{2})?\s+([A-Z]{3})\s+([A-Z]{3})\s+[A-Z]{2,3}\s*\d{0,2}\s+(\d{3,4}[APap]?)\s+(\d{3,4}[APap]?)(?:\+(\d))?/i;
    m = re3.exec(ln);
    if (m) return {
      airline_iata: m[1].toUpperCase(),
      flight_no:    m[1].toUpperCase() + m[2].toUpperCase(),
      cabin_class:  m[3].toUpperCase(),
      date:         m[4].toUpperCase(),
      from:         m[5].toUpperCase(),
      to:           m[6].toUpperCase(),
      dep_time:     fmtTime(m[7]),
      arr_time:     fmtTime(m[8]),
      arr_next_day: !!(m[9] && parseInt(m[9]) > 0),
    };

    // Strategy 3: Worldspan slash format
    // e.g. "AA1081/Y21APR MIAJFK HK1 0945 1158"
    const reWS = /^\s*([A-Z]{2})(\d{1,4}[A-Z]?)\/([A-Z])(\d{2}[A-Z]{3})\s+([A-Z]{3})([A-Z]{3})\s+[A-Z0-9]+\s+(\d{3,4}[APap]?)\s+(\d{3,4}[APap]?)(?:\+(\d))?/i;
    m = reWS.exec(ln);
    if (m) return {
      airline_iata: m[1].toUpperCase(),
      flight_no:    m[1].toUpperCase() + m[2].toUpperCase(),
      cabin_class:  m[3].toUpperCase(),
      date:         m[4].toUpperCase(),
      from:         m[5].toUpperCase(),
      to:           m[6].toUpperCase(),
      dep_time:     fmtTime(m[7]),
      arr_time:     fmtTime(m[8]),
      arr_next_day: !!(m[9] && parseInt(m[9]) > 0),
    };

    // Strategy 4: Bare minimal last-resort
    const reMin = /([A-Z0-9]{2})\s*(\d{1,4}[A-Z]?)\s+([A-Z])\s+(\d{2}[A-Z]{3})\s+([A-Z]{3})\s*([A-Z]{3})\s+(\d{3,4}[APap]?)\s+(\d{3,4}[APap]?)/i;
    m = reMin.exec(ln);
    if (m) return {
      airline_iata: m[1].toUpperCase(),
      flight_no:    m[1].toUpperCase() + m[2].toUpperCase(),
      cabin_class:  m[3].toUpperCase(),
      date:         m[4].toUpperCase(),
      from:         m[5].toUpperCase(),
      to:           m[6].toUpperCase(),
      dep_time:     fmtTime(m[7]),
      arr_time:     fmtTime(m[8]),
      arr_next_day: false,
    };

    return null;
  },

  parse: function(group, raw) {
    const statusEl = document.getElementById('parse-status-' + group);
    if (!raw.trim()) {
      state.segments[group] = [];
      if (statusEl) statusEl.innerHTML = '';
      this._render(group);
      return;
    }
    const segs = [];
    const lines = raw.split('\n');
    const self = this;
    lines.forEach(function(line) {
      const seg = self._parseOneLine(line);
      if (seg) segs.push(seg);
    });
    if (segs.length) {
      state.segments[group] = segs;
      if (statusEl) statusEl.innerHTML = '<span class="text-emerald-600 font-semibold">✓ Parsed ' + segs.length + ' segment' + (segs.length>1?'s':'') + '</span>';
    } else {
      state.segments[group] = [];
      if (statusEl) statusEl.innerHTML = '<span class="text-rose-600">⚠ Could not parse — check GDS format</span>';
    }
    this._render(group);
  },

  addManual: function(group) {
    state.segments[group].push({
      airline_iata:'', flight_no:'', cabin_class:'Y', date:'', from:'', to:'',
      dep_time:'', arr_time:'', arr_next_day:false
    });
    this._render(group);
  },

  removeSegment: function(group, idx) {
    state.segments[group].splice(idx, 1);
    this._render(group);
  },

  _updateSeg: function(group, idx, field, val) {
    if (!state.segments[group] || !state.segments[group][idx]) return;
    state.segments[group][idx][field] = val;
    if (field === 'airline_iata' && val.length === 2 && !state.segments[group][idx].flight_no) {
      state.segments[group][idx].flight_no = val.toUpperCase();
    }
  },

  _render: function(group) {
    const el = document.getElementById('segs-' + group);
    if (!el) return;
    const segs = state.segments[group];
    if (!segs || !segs.length) { el.innerHTML = ''; return; }

    const self = this;
    el.innerHTML = segs.map(function(seg, i) {
      const aName  = AIRLINES[seg.airline_iata] || seg.airline_iata || '';
      const fCity  = CITIES[seg.from] || seg.from;
      const tCity  = CITIES[seg.to]   || seg.to;
      const logoUrl= seg.airline_iata ? "https://www.gstatic.com/flights/airline_logos/35px/" + seg.airline_iata + ".png" : "";
      const parsed = !!(seg.from && seg.to && seg.dep_time);

      const card = parsed
        ? `<div class="seg-card flex items-stretch bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm">
            <div class="bg-slate-900 px-3 py-3 flex flex-col items-center justify-center gap-1 min-w-[72px]">
              ${logoUrl ? `<img src="${logoUrl}" alt="${seg.airline_iata}" class="w-8 h-8 object-contain" onerror="this.style.display='none'">` : ''}
              <span class="text-[11px] font-black text-white">${_esc(seg.airline_iata)}</span>
              <span class="text-[9px] text-slate-400 text-center leading-tight">${_esc(aName)}</span>
            </div>
            <div class="flex-1 p-3 grid grid-cols-[1fr_auto_1fr] gap-2 items-center">
              <div class="text-right"><div class="text-lg font-black text-slate-900">${_esc(seg.dep_time)}</div><div class="text-sm font-bold text-blue-700">${_esc(seg.from)}</div><div class="text-[10px] text-slate-400">${_esc(fCity)}</div></div>
              <div class="flex flex-col items-center px-1"><div class="text-[9px] font-bold text-slate-500">${_esc(seg.flight_no)}</div><div class="w-14 h-px bg-slate-300 relative my-1.5"><div class="absolute -right-1 -top-1.5 text-blue-600 text-xs">✈</div></div><div class="text-[9px] text-slate-400">${_esc(seg.date)}</div></div>
              <div><div class="flex items-baseline gap-1"><span class="text-lg font-black text-slate-900">${_esc(seg.arr_time)}</span>${seg.arr_next_day?'<span class="px-1 py-0.5 bg-rose-100 text-rose-700 text-[9px] font-bold rounded">+1d</span>':''}</div><div class="text-sm font-bold text-blue-700">${_esc(seg.to)}</div><div class="text-[10px] text-slate-400">${_esc(tCity)}</div></div>
            </div>
            <button type="button" onclick="flightMgr.removeSegment('${group}',${i})" class="px-2 bg-slate-50 border-l border-slate-200 hover:bg-rose-50 hover:text-rose-600 text-slate-400 flex items-center transition-colors"><span class="material-symbols-outlined text-sm">close</span></button>
          </div>`
        : `<div class="seg-card p-3 bg-slate-50 border border-slate-200 rounded-xl fare-row space-y-2">
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
              <div>
                <label class="block text-[9px] font-bold text-slate-400 uppercase mb-1">Airline IATA</label>
                <input type="text" maxlength="3" placeholder="LH" value="${_esc(seg.airline_iata)}"
                  class="w-full border border-slate-200 rounded-lg px-2 py-1.5 text-xs font-mono font-bold uppercase bg-white focus:outline-none focus:ring-2 focus:ring-primary-600"
                  oninput="flightMgr._updateSeg('${group}',${i},'airline_iata',this.value.toUpperCase())">
              </div>
              <div>
                <label class="block text-[9px] font-bold text-slate-400 uppercase mb-1">Flight No</label>
                <input type="text" maxlength="8" placeholder="LH419" value="${_esc(seg.flight_no)}"
                  class="w-full border border-slate-200 rounded-lg px-2 py-1.5 text-xs font-mono uppercase bg-white focus:outline-none focus:ring-2 focus:ring-primary-600"
                  oninput="flightMgr._updateSeg('${group}',${i},'flight_no',this.value.toUpperCase())">
              </div>
              <div>
                <label class="block text-[9px] font-bold text-slate-400 uppercase mb-1">Date</label>
                <input type="text" maxlength="5" placeholder="12MAR" value="${_esc(seg.date)}"
                  class="w-full border border-slate-200 rounded-lg px-2 py-1.5 text-xs font-mono uppercase bg-white focus:outline-none focus:ring-2 focus:ring-primary-600"
                  oninput="flightMgr._updateSeg('${group}',${i},'date',this.value.toUpperCase())">
              </div>
              <div>
                <label class="block text-[9px] font-bold text-slate-400 uppercase mb-1">Cabin</label>
                <input type="text" maxlength="2" placeholder="Y" value="${_esc(seg.cabin_class)}"
                  class="w-full border border-slate-200 rounded-lg px-2 py-1.5 text-xs font-mono uppercase bg-white focus:outline-none focus:ring-2 focus:ring-primary-600"
                  oninput="flightMgr._updateSeg('${group}',${i},'cabin_class',this.value.toUpperCase())">
              </div>
              <div>
                <label class="block text-[9px] font-bold text-slate-400 uppercase mb-1">From (IATA)</label>
                <input type="text" maxlength="3" placeholder="JFK" value="${_esc(seg.from)}"
                  class="w-full border border-slate-200 rounded-lg px-2 py-1.5 text-xs font-mono uppercase bg-white focus:outline-none focus:ring-2 focus:ring-primary-600"
                  oninput="flightMgr._updateSeg('${group}',${i},'from',this.value.toUpperCase())">
              </div>
              <div>
                <label class="block text-[9px] font-bold text-slate-400 uppercase mb-1">To (IATA)</label>
                <input type="text" maxlength="3" placeholder="FRA" value="${_esc(seg.to)}"
                  class="w-full border border-slate-200 rounded-lg px-2 py-1.5 text-xs font-mono uppercase bg-white focus:outline-none focus:ring-2 focus:ring-primary-600"
                  oninput="flightMgr._updateSeg('${group}',${i},'to',this.value.toUpperCase())">
              </div>
              <div>
                <label class="block text-[9px] font-bold text-slate-400 uppercase mb-1">Dep Time</label>
                <input type="text" maxlength="5" placeholder="10:40" value="${_esc(seg.dep_time)}"
                  class="w-full border border-slate-200 rounded-lg px-2 py-1.5 text-xs font-mono bg-white focus:outline-none focus:ring-2 focus:ring-primary-600"
                  oninput="flightMgr._updateSeg('${group}',${i},'dep_time',this.value)">
              </div>
              <div>
                <label class="block text-[9px] font-bold text-slate-400 uppercase mb-1">Arr Time</label>
                <input type="text" maxlength="5" placeholder="06:10" value="${_esc(seg.arr_time)}"
                  class="w-full border border-slate-200 rounded-lg px-2 py-1.5 text-xs font-mono bg-white focus:outline-none focus:ring-2 focus:ring-primary-600"
                  oninput="flightMgr._updateSeg('${group}',${i},'arr_time',this.value)">
              </div>
            </div>
            <div class="flex items-center justify-between">
              <label class="flex items-center gap-2 text-xs text-slate-600 cursor-pointer">
                <input type="checkbox" ${seg.arr_next_day?'checked':''} class="w-3.5 h-3.5 accent-rose-500"
                  onchange="flightMgr._updateSeg('${group}',${i},'arr_next_day',this.checked)">
                Arrives next day (+1)
              </label>
              <button type="button" onclick="flightMgr.removeSegment('${group}',${i})"
                class="inline-flex items-center gap-1 text-xs text-rose-600 font-semibold hover:text-rose-800">
                <span class="material-symbols-outlined text-sm">delete</span> Remove
              </button>
            </div>
          </div>`;

      // Layover indicator between consecutive parsed segments
      const layover = (i < segs.length-1 && parsed && segs[i+1].from)
        ? `<div class="flex items-center gap-2 px-3 py-2 bg-amber-50 border border-amber-200 rounded-lg text-xs font-semibold text-amber-700">
             <span class="material-symbols-outlined text-sm">connecting_airports</span>
             Connection in ${_esc(CITIES[seg.to] || seg.to)} · ${_esc(segs[i+1].airline_iata)}${_esc(segs[i+1].flight_no.replace(segs[i+1].airline_iata,''))}
           </div>` : '';

      return card + layover;
    }).join('');
  },

  _renderLayovers(segs) { return ''; }
};

// ─────────────────────────────────────────────────────────────────────────────
// FARE MANAGER
// ─────────────────────────────────────────────────────────────────────────────
const fareMgr = {
  addItem(label='', amount='') {
    state.fareItems.push({ label, amount: parseFloat(amount) || 0 });
    this._render();
  },
  removeItem(idx) {
    state.fareItems.splice(idx, 1);
    this._render();
  },
  _updateItem(idx, field, val) {
    if (!state.fareItems[idx]) return;
    state.fareItems[idx][field] = field === 'amount' ? (parseFloat(val) || 0) : val;
    this._recalc();
  },
  _recalc() {
    const total = state.fareItems.reduce((s, it) => s + (parseFloat(it.amount) || 0), 0);
    const el = document.getElementById('field_total_amount');
    if (el && state.fareItems.length > 0) el.value = total.toFixed(2);
    syncSummary();
  },
  _render() {
    const el = document.getElementById('fare-items');
    el.innerHTML = state.fareItems.map((item, i) => `
      <div class="fare-row flex items-center gap-2">
        <input type="text" value="${_esc(item.label)}" placeholder="e.g. Base Fare"
          class="flex-1 border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-slate-50 focus:outline-none focus:ring-2 focus:ring-primary-600"
          oninput="fareMgr._updateItem(${i},'label',this.value)">
        <input type="number" step="0.01" value="${item.amount || ''}" placeholder="0.00"
          class="w-28 border border-slate-200 rounded-lg px-3 py-1.5 text-sm font-mono bg-slate-50 focus:outline-none focus:ring-2 focus:ring-emerald-500"
          oninput="fareMgr._updateItem(${i},'amount',this.value)">
        <button type="button" onclick="fareMgr.removeItem(${i})"
          class="p-1.5 bg-rose-100 hover:bg-rose-200 text-rose-700 rounded-lg transition-colors flex-none">
          <span class="material-symbols-outlined text-sm">delete</span>
        </button>
      </div>`).join('');
    this._recalc();
  }
};

// ─────────────────────────────────────────────────────────────────────────────
// ADDITIONAL CARD MANAGER
// ─────────────────────────────────────────────────────────────────────────────
const cardMgr = {
  add() {
    state.extraCards.push({ cardholder_name:'', card_last_four:'', card_type:'' });
    this._render();
  },
  remove(idx) {
    state.extraCards.splice(idx, 1);
    this._render();
  },
  _update(idx, field, val) {
    if (!state.extraCards[idx]) return;
    state.extraCards[idx][field] = val;
  },
  _render: function() {
    const el = document.getElementById('additional-cards');
    if (!el) return;
    el.innerHTML = state.extraCards.map(function(c, i) { return `
      <div class="fare-row grid grid-cols-[1fr_1fr_auto_auto] gap-2 items-end p-3 bg-slate-50 border border-slate-200 rounded-lg">
        <div>
          <label class="block text-[9px] font-bold text-slate-400 uppercase mb-1">Card Type</label>
          <select class="w-full border border-slate-200 rounded-lg px-2 py-1.5 text-xs bg-white focus:outline-none focus:ring-2 focus:ring-primary-600"
            onchange="cardMgr._update(${i},'card_type',this.value)">
            <option value="">--</option><option>Visa</option><option>Mastercard</option><option>Amex</option><option>Discover</option>
          </select>
        </div>
        <div>
          <label class="block text-[9px] font-bold text-slate-400 uppercase mb-1">Cardholder Name</label>
          <input type="text" placeholder="Name on card" value="${_esc(c.cardholder_name)}"
            class="w-full border border-slate-200 rounded-lg px-2 py-1.5 text-xs bg-white focus:outline-none focus:ring-2 focus:ring-primary-600"
            oninput="cardMgr._update(${i},'cardholder_name',this.value)">
        </div>
        <div>
          <label class="block text-[9px] font-bold text-slate-400 uppercase mb-1">Last 4</label>
          <input type="text" maxlength="4" placeholder="••••" value="${_esc(c.card_last_four)}"
            class="w-16 border border-slate-200 rounded-lg px-2 py-1.5 text-xs font-mono font-bold tracking-widest bg-white focus:outline-none focus:ring-2 focus:ring-primary-600"
            oninput="cardMgr._update(${i},'card_last_four',this.value.replace(/\\D/g,'').substring(0,4))">
        </div>
        <button type="button" onclick="cardMgr.remove(${i})"
          class="p-1.5 bg-rose-100 hover:bg-rose-200 text-rose-700 rounded-lg transition-colors self-end">
          <span class="material-symbols-outlined text-sm">delete</span>
        </button>
      </div>`; }).join('');
  }
};

// ─────────────────────────────────────────────────────────────────────────────
// EXTRA DATA SYNC (for type-specific fields)
// ─────────────────────────────────────────────────────────────────────────────
function syncExtraData() {
  // Called by name correction / cabin upgrade / other fields
}

// ─────────────────────────────────────────────────────────────────────────────
// SUMMARY SIDEBAR SYNC
// ─────────────────────────────────────────────────────────────────────────────
function syncSummary() {
  const pnrEl = document.getElementById('field_pnr');
  const nameEl = document.getElementById('field_customer_name');
  const totalEl = document.getElementById('field_total_amount');
  const currEl = document.getElementById('field_currency');
  const pnr   = (pnrEl ? pnrEl.value : '').trim();
  const name  = (nameEl ? nameEl.value : '').trim();
  const total = (totalEl ? totalEl.value : '').trim();
  const curr  = (currEl ? currEl.value : 'USD');
  const sumPnr = document.getElementById('sum-pnr');
  const sumName = document.getElementById('sum-name');
  const sumTotal = document.getElementById('sum-total');
  if (sumPnr) sumPnr.textContent   = pnr  || '—';
  if (sumName) sumName.textContent  = name || '—';
  if (sumTotal) sumTotal.textContent = total ? curr + ' ' + parseFloat(total).toLocaleString('en-CA',{minimumFractionDigits:2}) : '—';
}

// ─────────────────────────────────────────────────────────────────────────────
// PREVIEW BUILDER (Step 5)
// ─────────────────────────────────────────────────────────────────────────────
const preview = {
  build: function() {
    const pnrEl    = document.getElementById('field_pnr');
    const nameEl   = document.getElementById('field_customer_name');
    const emailEl  = document.getElementById('field_customer_email');
    const totalEl  = document.getElementById('field_total_amount');
    const currEl   = document.getElementById('field_currency');
    const holderEl = document.getElementById('field_cardholder_name');
    const cardNumEl = document.getElementById('field_card_number');
    const typeEl    = document.getElementById('field_card_type');

    const pnr    = (pnrEl ? pnrEl.value : '') || '—';
    const name   = (nameEl ? nameEl.value : '') || '—';
    const email  = (emailEl ? emailEl.value : '') || '—';
    const total  = parseFloat(totalEl ? totalEl.value : '0') || 0;
    const curr   = (currEl ? currEl.value : '') || 'USD';
    const holder = (holderEl ? holderEl.value : '');
    const rawNum = (cardNumEl ? cardNumEl.value.replace(/\s/g,'') : '');
    const last4  = rawNum.length >= 4 ? rawNum.slice(-4) : rawNum;
    const ctype  = (typeEl ? typeEl.value : '');

    // Basic info
    const prevPnr = document.getElementById('prev-pnr');
    const prevName = document.getElementById('prev-name');
    const prevEmail = document.getElementById('prev-email');
    const prevPaxCount = document.getElementById('prev-pax-count');
    const prevBadge = document.getElementById('preview-type-badge');
    if (prevPnr) prevPnr.textContent   = pnr;
    if (prevName) prevName.textContent  = name;
    if (prevEmail) prevEmail.textContent = email;
    if (prevPaxCount) prevPaxCount.textContent = state.passengers.length;
    if (prevBadge) prevBadge.textContent = TYPE_LABELS[state.type] || '';

    // Passengers
    const paxFilled = state.passengers.filter(p => p.name.trim());
    const paxSec = document.getElementById('prev-pax-section');
    if (paxFilled.length) {
      paxSec.classList.remove('hidden');
      document.getElementById('prev-pax-list').innerHTML = paxFilled.map(p =>
        `<span class="inline-flex items-center gap-1 px-2.5 py-1 bg-slate-100 text-slate-700 rounded-full text-xs font-mono">${_esc(p.name)}</span>`
      ).join('');
    } else paxSec.classList.add('hidden');

    // Flights (combine all segment groups for preview)
    const allSegs = [...(state.segments.main||[]), ...(state.segments.old||[]), ...(state.segments.new||[]), ...(state.segments.other||[])];
    const flightsSec = document.getElementById('prev-flights-section');
    if (allSegs.length) {
      flightsSec.classList.remove('hidden');
      document.getElementById('prev-flights').innerHTML = allSegs.map(seg => {
        const logoUrl = seg.airline_iata ? `https://www.gstatic.com/flights/airline_logos/35px/${seg.airline_iata}.png` : '';
        return `<div class="flex items-center gap-3 p-2 bg-slate-50 border border-slate-100 rounded-lg">
          ${logoUrl ? `<img src="${logoUrl}" alt="${seg.airline_iata}" class="w-7 h-7 object-contain flex-none" onerror="this.style.display='none'">` : ''}
          <span class="text-xs font-bold font-mono text-slate-900">${_esc(seg.flight_no)}</span>
          <span class="text-xs text-slate-500">${_esc(seg.date)}</span>
          <span class="text-xs font-semibold text-slate-700">${_esc(seg.from)} → ${_esc(seg.to)}</span>
          <span class="text-xs text-slate-400">${_esc(seg.dep_time)} → ${_esc(seg.arr_time)}${seg.arr_next_day?' (+1)':''}</span>
        </div>`;
      }).join('');
    } else flightsSec.classList.add('hidden');

    // Fare
    const fareSec = document.getElementById('prev-fare-section');
    if (state.fareItems.length || total > 0) {
      fareSec.classList.remove('hidden');
      document.getElementById('prev-fare-rows').innerHTML = state.fareItems.map(it =>
        `<tr class="border-b border-slate-100">
          <td class="px-3 py-1.5 text-xs text-slate-600">${_esc(it.label)}</td>
          <td class="px-3 py-1.5 text-xs font-mono text-right font-semibold">${curr} ${(it.amount||0).toFixed(2)}</td>
        </tr>`
      ).join('');
      document.getElementById('prev-total').textContent = curr + ' ' + total.toLocaleString('en-CA',{minimumFractionDigits:2});
    } else fareSec.classList.add('hidden');

    // Card
    const cardSec = document.getElementById('prev-card-section');
    if (holder || last4) {
      cardSec.classList.remove('hidden');
      document.getElementById('prev-card-holder').textContent = holder;
      document.getElementById('prev-card-num').textContent = (ctype ? ctype + ' ' : '') + '**** **** **** ' + last4;
    } else cardSec.classList.add('hidden');

    // Future Credit Details (cancel_credit)
    const creditSec = document.getElementById('prev-credit-section');
    if (state.type === 'cancel_credit') {
      const ccAmt   = document.getElementById('field_cc_credit_amount')?.value || '';
      const ccValid = document.getElementById('field_cc_valid_until')?.value || '';
      const ccInstr = document.getElementById('field_cc_instructions')?.value || '';
      const ccEtkts = typeof creditEtktMgr !== 'undefined' ? creditEtktMgr.getData() : [];
      if (ccAmt || ccValid || ccInstr || ccEtkts.length) {
        creditSec.classList.remove('hidden');
        document.getElementById('prev-credit-amount').textContent = ccAmt ? curr + ' ' + parseFloat(ccAmt).toLocaleString('en-CA',{minimumFractionDigits:2}) : '—';
        document.getElementById('prev-credit-valid').textContent = ccValid || '—';
        // E-tickets
        const etktWrap = document.getElementById('prev-credit-etkt-wrap');
        if (ccEtkts.length) {
          etktWrap.classList.remove('hidden');
          document.getElementById('prev-credit-etkts').innerHTML = ccEtkts.map(e =>
            `<span class="inline-flex items-center gap-1 px-2 py-1 bg-violet-100 text-violet-800 rounded-full text-[10px] font-mono font-bold">${_esc(e.name)}: ${_esc(e.etkt)}</span>`
          ).join('');
        } else etktWrap.classList.add('hidden');
        // Instructions
        const instrWrap = document.getElementById('prev-credit-instr-wrap');
        if (ccInstr.trim()) {
          instrWrap.classList.remove('hidden');
          document.getElementById('prev-credit-instructions').textContent = ccInstr;
        } else instrWrap.classList.add('hidden');
      } else creditSec.classList.add('hidden');
    } else creditSec.classList.add('hidden');

    // Cancel/Refund Details (cancel_refund)
    const refundSec = document.getElementById('prev-refund-section');
    if (state.type === 'cancel_refund') {
      const crAmt    = document.getElementById('field_cr_refund_amount')?.value || '';
      const crFee    = document.getElementById('field_cr_cancel_fee')?.value || '';
      const crMethod = document.getElementById('field_cr_refund_method')?.value || '';
      const crTime   = document.getElementById('field_cr_refund_timeline')?.value || '';
      if (crAmt || crFee) {
        refundSec.classList.remove('hidden');
        document.getElementById('prev-refund-amount').textContent = crAmt ? curr + ' ' + parseFloat(crAmt).toLocaleString('en-CA',{minimumFractionDigits:2}) : '—';
        document.getElementById('prev-cancel-fee').textContent = crFee ? curr + ' ' + parseFloat(crFee).toLocaleString('en-CA',{minimumFractionDigits:2}) : '—';
        const methodWrap = document.getElementById('prev-refund-method-wrap');
        if (crMethod || crTime) {
          methodWrap.classList.remove('hidden');
          document.getElementById('prev-refund-method').textContent = [crMethod, crTime].filter(Boolean).join(' — ');
        } else methodWrap.classList.add('hidden');
      } else refundSec.classList.add('hidden');
    } else refundSec.classList.add('hidden');
  }
};

// ─────────────────────────────────────────────────────────────────────────────
// FORM ASSEMBLY & SUBMIT
// ─────────────────────────────────────────────────────────────────────────────
const formAssembly = {
  submit() {
    // 1. Final validation
    const { valid, msg } = wizard.validate(4);
    if (!valid) { wizard.goTo(4); setTimeout(()=>alert('Please fix: ' + msg), 100); return; }

    // 2. Assemble passengers JSON
    document.getElementById('hidPassengers').value = JSON.stringify(
      state.passengers.filter(p => p.name.trim()).map(p => ({
        name: p.name.trim(), dob: p.dob || '', type: p.paxType || 'adult'
      }))
    );

    // 3. Assemble flight_data JSON based on type
    let flightData = null;
    const t = state.type;
    if (['new_booking','seat_purchase','cabin_upgrade','name_correction'].includes(t)) {
      flightData = { flights: state.segments.main || [] };
    } else if (t === 'exchange') {
      flightData = { old_flights: state.segments.old || [], new_flights: state.segments.new || [] };
    } else if (['cancel_refund','cancel_credit'].includes(t)) {
      flightData = { flights: state.segments.old || [] };
    }
    // Append type-specific extras into flightData
    if (t === 'name_correction') {
      flightData.old_name  = (document.getElementById('nc-old-name')?.value || '').trim().toUpperCase();
      flightData.new_name  = (document.getElementById('nc-new-name')?.value || '').trim().toUpperCase();
      flightData.reason    = (document.getElementById('nc-reason')?.value || '').trim();
    }
    if (t === 'cabin_upgrade') {
      flightData.old_cabin = document.getElementById('cu-old-cabin')?.value || '';
      flightData.new_cabin = document.getElementById('cu-new-cabin')?.value || '';
    }
    if (t === 'other') {
      flightData = { flights: state.segments.other || [] };
    }
    document.getElementById('hidFlightData').value = JSON.stringify(flightData);

    // 4. Fare breakdown
    document.getElementById('hidFareBreakdown').value = JSON.stringify(
      state.fareItems.filter(it => it.label.trim() || it.amount > 0).map(it => ({
        label: it.label.trim(), amount: parseFloat(it.amount) || 0
      }))
    );

    // 5. Extra data (type-specific fields: other, cancel_refund, cancel_credit)
    const extraData = {};
    if (t === 'other') {
      extraData.other_title = (document.getElementById('field_other_title')?.value || '').trim();
      extraData.other_notes = (document.getElementById('field_other_notes')?.value || '').trim();
    } else if (t === 'cancel_refund') {
      extraData.refund_amount   = parseFloat(document.getElementById('field_cr_refund_amount')?.value || 0) || 0;
      extraData.cancel_fee      = parseFloat(document.getElementById('field_cr_cancel_fee')?.value || 0) || 0;
      extraData.refund_method   = document.getElementById('field_cr_refund_method')?.value || '';
      extraData.refund_timeline = document.getElementById('field_cr_refund_timeline')?.value || '';
    } else if (t === 'cancel_credit') {
      extraData.credit_amount = parseFloat(document.getElementById('field_cc_credit_amount')?.value || 0) || 0;
      extraData.valid_until   = document.getElementById('field_cc_valid_until')?.value || '';
      extraData.instructions  = document.getElementById('field_cc_instructions')?.value || '';
      extraData.etkt_list     = typeof creditEtktMgr !== 'undefined' ? creditEtktMgr.getData() : [];
    }
    document.getElementById('hidExtraData').value = Object.keys(extraData).length ? JSON.stringify(extraData) : 'null';

    // 6. Additional cards
    const validCards = state.extraCards.filter(c => c.cardholder_name.trim() && /^\d{4}$/.test(c.card_last_four));
    document.getElementById('hidAdditionalCards').value = validCards.length ? JSON.stringify(validCards) : 'null';

    // 7. Lock submit button
    const btn = document.getElementById('btn-generate');
    btn.disabled = true;
    btn.innerHTML = '<span class="material-symbols-outlined animate-spin">progress_activity</span> Generating...';
    btn.classList.add('opacity-75','cursor-not-allowed');

    // 8. Submit
    document.getElementById('acceptanceForm').submit();
  }
};

// ─────────────────────────────────────────────────────────────────────────────
// UTILITY
// ─────────────────────────────────────────────────────────────────────────────
function _esc(s) {
  return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ─────────────────────────────────────────────────────────────────────────────
// INIT
// ─────────────────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  // Restore pre-selected type from PHP
  const preType = '<?= addslashes($pre['type']) ?>';
  if (preType) selectType(preType);

  // Pre-fill passengers JSON if available (from pre-auth promote)
  const prePassengersList = <?= !empty($preJson['passengers']) && $preJson['passengers'] !== '[]' && $preJson['passengers'] !== 'null' ? $preJson['passengers'] : 'null' ?>;
  if (prePassengersList && Array.isArray(prePassengersList) && prePassengersList.length > 0) {
    state.passengers = prePassengersList.map(p => ({
        name: p.name || p.first_name + ' ' + p.last_name || '', // handling multiple schema formats
        dob: p.dob || '',
        paxType: p.type || p.pax_type || 'adult'
    }));
    // Try rendering preview/manual list
    if (typeof paxManager !== 'undefined') {
        paxManager._renderManual();
        paxManager._renderPreview();
        paxManager._syncCount();
    }
  }

  // Pre-fill flight segments if available
  const preFlightData = <?= !empty($preJson['flight_data']) && $preJson['flight_data'] !== 'null' ? $preJson['flight_data'] : 'null' ?>;
  if (preFlightData) {
     if (preFlightData.flights) state.segments.main = preFlightData.flights;
     if (preFlightData.old_flights) state.segments.old = preFlightData.old_flights;
     if (preFlightData.new_flights) state.segments.new = preFlightData.new_flights;
     if (typeof flightMgr !== 'undefined') {
         flightMgr._render('main');
         flightMgr._render('old');
         flightMgr._render('new');
     }
  }

  // Set up live summary listeners
  ['field_pnr','field_customer_name','field_total_amount','field_currency'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('input', syncSummary);
  });

  // Seed one fare item for convenience
  fareMgr.addItem('Base Fare', '');

  syncSummary();
});

// ─── PRE-AUTH MODE TOGGLE ────────────────────────────────────────────────────
let _currentMode = '<?= $initIsPreauth ? 'preauth' : 'full' ?>';

function setMode(mode) {
  _currentMode = mode;

  // Hidden flag
  document.getElementById('hidIsPreauth').value = mode === 'preauth' ? '1' : '0';

  // Toggle button styles
  const btnPre  = document.getElementById('modeBtn-preauth');
  const btnFull = document.getElementById('modeBtn-full');
  if (btnPre && btnFull) {
    if (mode === 'preauth') {
      btnPre.className  = btnPre.className.replace(/bg-white text-slate-600 hover:bg-amber-50|bg-primary-600 text-white/, 'bg-amber-500 text-white');
      btnFull.className = btnFull.className.replace('bg-primary-600 text-white', 'bg-white text-slate-600 hover:bg-slate-50');
    } else {
      btnFull.className = btnFull.className.replace(/bg-white text-slate-600 hover:bg-slate-50|bg-amber-500 text-white/, 'bg-primary-600 text-white');
      btnPre.className  = btnPre.className.replace('bg-amber-500 text-white', 'bg-white text-slate-600 hover:bg-amber-50');
    }
  }

  // Banner text
  const banner = document.getElementById('modeBannerText');
  if (banner) {
    if (mode === 'preauth') {
      banner.textContent = '⚡ Quick Pre-Auth — sends total amount only to the customer. Send a Full Acceptance after ticketing with full breakdown.';
      banner.style.color = '#b45309';
    } else {
      banner.textContent = '📋 Full Acceptance — complete fare breakdown, split charges, endorsements and signed receipt.';
      banner.style.color = '#1a3a6b';
    }
  }

  // Show/hide full-only sections
  const fullOnly = ['sec-fare-breakdown', 'sec-ticket-conditions', 'sec-split-charge'];
  fullOnly.forEach(id => {
    const el = document.getElementById(id);
    if (el) el.style.display = (mode === 'full') ? '' : 'none';
  });

  // Show/hide preauth total box
  const preBox = document.getElementById('sec-preauth-total');
  if (preBox) preBox.style.display = (mode === 'preauth') ? '' : 'none';

  // Cancel type sections only visible in full mode
  ['sec-cancel-refund','sec-cancel-credit'].forEach(function(id) {
    var el = document.getElementById(id);
    if (el) el.style.display = 'none'; // reset; selectType() handles show logic
  });

  // Sync currency from preauth total selector to main
  if (mode === 'preauth') {
    const preCur = document.getElementById('preauth_currency');
    const mainCur = document.getElementById('field_currency');
    if (preCur && mainCur) {
      preCur.addEventListener('change', () => mainCur.value = preCur.value);
      mainCur.value = preCur.value;
    }
  }
}

// Apply initial mode on load
if (_currentMode !== 'full') setMode(_currentMode);

// ─────────────────────────────────────────────────────────────────────────────
// CREDIT E-TICKET MANAGER
// Manages per-passenger e-ticket rows in sec-cancel-credit
// ─────────────────────────────────────────────────────────────────────────────
var creditEtktMgr = {
  rows: [],
  addRow: function(pax_name, etkt) {
    this.rows.push({ pax_name: pax_name || '', etkt: etkt || '' });
    this._render();
  },
  removeRow: function(idx) {
    this.rows.splice(idx, 1);
    this._render();
  },
  syncFromPassengers: function() {
    // Populate one row per passenger if rows is empty
    if (this.rows.length > 0) return;
    (state.passengers || []).forEach(function(p) {
      var name = ((p.first_name || '') + ' ' + (p.last_name || '')).trim();
      creditEtktMgr.addRow(name, '');
    });
    if (this.rows.length === 0) this.addRow('Passenger 1', '');
  },
  getData: function() { return this.rows; },
  _render: function() {
    var container = document.getElementById('credit-etkt-rows');
    if (!container) return;
    container.innerHTML = '';
    this.rows.forEach(function(row, idx) {
      var div = document.createElement('div');
      div.className = 'flex items-center gap-2';
      div.innerHTML =
        '<input type="text" placeholder="Passenger Name" value="' + (row.pax_name||'').replace(/"/g,'&quot;') + '"' +
        '  class="w-1/2 border border-violet-200 rounded-lg px-2 py-1.5 text-xs bg-violet-50 focus:outline-none focus:ring-2 focus:ring-violet-400"' +
        '  oninput="creditEtktMgr.rows[' + idx + '].pax_name=this.value">' +
        '<input type="text" placeholder="E-Ticket # e.g. 0161234567890" value="' + (row.etkt||'').replace(/"/g,'&quot;') + '"' +
        '  class="w-1/2 border border-violet-200 rounded-lg px-2 py-1.5 text-xs font-mono bg-violet-50 focus:outline-none focus:ring-2 focus:ring-violet-400"' +
        '  oninput="creditEtktMgr.rows[' + idx + '].etkt=this.value">' +
        '<button type="button" onclick="creditEtktMgr.removeRow(' + idx + ')"' +
        '  class="text-rose-400 hover:text-rose-600 text-xs flex-none"><span class="material-symbols-outlined text-base">close</span></button>';
      container.appendChild(div);
    });
  }
};

// ─────────────────────────────────────────────────────────────────────────────
// EXTRA_DATA SERIALIZER — runs before form submit
// Collects cancel_refund / cancel_credit fields into extra_data JSON hidden input
// ─────────────────────────────────────────────────────────────────────────────
function serializeExtraData() {
  var t = state.type;
  var extra = {};
  if (t === 'cancel_refund') {
    extra.refund_amount  = parseFloat(document.getElementById('field_cr_refund_amount')?.value||0)||0;
    extra.cancel_fee     = parseFloat(document.getElementById('field_cr_cancel_fee')?.value||0)||0;
    extra.refund_method  = document.getElementById('field_cr_refund_method')?.value||'';
    extra.refund_timeline= document.getElementById('field_cr_refund_timeline')?.value||'';
  } else if (t === 'cancel_credit') {
    extra.credit_amount  = parseFloat(document.getElementById('field_cc_credit_amount')?.value||0)||0;
    extra.valid_until    = document.getElementById('field_cc_valid_until')?.value||'';
    extra.instructions   = document.getElementById('field_cc_instructions')?.value||'';
    extra.etkt_list      = creditEtktMgr.getData();
  }
  var hidExtra = document.getElementById('hidden_extra_data');
  if (!hidExtra) {
    hidExtra = document.createElement('input');
    hidExtra.type = 'hidden'; hidExtra.name = 'extra_data_json';
    hidExtra.id   = 'hidden_extra_data';
    document.getElementById('acc-form').appendChild(hidExtra);
  }
  hidExtra.value = JSON.stringify(extra);
}

// Hook into form submission
(function() {
  var form = document.getElementById('acceptanceForm');
  if (form) {
    form.addEventListener('submit', function(e) {
      serializeExtraData();
    });
  }
})();

</script>
</body></html>
