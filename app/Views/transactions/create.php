<?php
/**
 * Transaction Recorder - Create Form (5-Step Wizard)
 * Modeled after the production Acceptance wizard.
 */
use App\Models\Transaction;

$prefill         = $prefill ?? null;
$autofillOptions = $autofillOptions ?? [];
$flashError      = $flashError ?? null;
$flashSuccess    = $flashSuccess ?? null;
$isAdmin         = in_array($_SESSION['role'] ?? 'agent', ['admin','manager']);

$pre = [
    'acceptance_id'   => $prefill['acceptance_id'] ?? '',
    'type'            => $prefill['type'] ?? '',
    'customer_name'   => htmlspecialchars($prefill['customer_name'] ?? ''),
    'customer_email'  => htmlspecialchars($prefill['customer_email'] ?? ''),
    'customer_phone'  => htmlspecialchars($prefill['customer_phone'] ?? ''),
    'pnr'             => htmlspecialchars(strtoupper($prefill['pnr'] ?? '')),
    'airline'         => htmlspecialchars($prefill['airline'] ?? ''),
    'order_id'        => htmlspecialchars($prefill['order_id'] ?? ''),
    'total_amount'    => $prefill['total_amount'] ?? '',
    'currency'        => $prefill['currency'] ?? 'USD',
    'card_type'       => htmlspecialchars($prefill['card_type'] ?? ''),
    'cardholder_name' => htmlspecialchars($prefill['cardholder_name'] ?? ''),
    'card_last_4'     => htmlspecialchars($prefill['card_last_4'] ?? ''),
    'billing_address' => htmlspecialchars($prefill['billing_address'] ?? ''),
    'passengers'      => $prefill['passengers'] ?? [],
    'flight_data'     => $prefill['flight_data'] ?? null,
    'fare_breakdown'  => $prefill['fare_breakdown'] ?? [],
];

$typeCards = [
    ['value'=>'new_booking','label'=>'New Booking','sub'=>'New flight ticket purchase','icon'=>'flight_takeoff','color'=>'blue'],
    ['value'=>'exchange','label'=>'Exchange / Date Change','sub'=>'Flight change or date swap','icon'=>'swap_horiz','color'=>'violet'],
    ['value'=>'cancel_refund','label'=>'Cancellation & Refund','sub'=>'Refund to card','icon'=>'money_off','color'=>'rose'],
    ['value'=>'cancel_credit','label'=>'Cancellation & Credit','sub'=>'Future credit','icon'=>'savings','color'=>'orange'],
    ['value'=>'seat_purchase','label'=>'Seat Purchase','sub'=>'Seat selection fee','icon'=>'airline_seat_recline_extra','color'=>'cyan'],
    ['value'=>'cabin_upgrade','label'=>'Cabin Upgrade','sub'=>'Upgrade cabin class','icon'=>'upgrade','color'=>'emerald'],
    ['value'=>'name_correction','label'=>'Name Correction','sub'=>'Name change fee','icon'=>'edit','color'=>'amber'],
    ['value'=>'other','label'=>'Other','sub'=>'Miscellaneous','icon'=>'more_horiz','color'=>'gray'],
];
$colorMap = [
    'blue'=>['ring'=>'ring-blue-500','bg'=>'bg-blue-50','icon'=>'text-blue-600'],
    'violet'=>['ring'=>'ring-violet-500','bg'=>'bg-violet-50','icon'=>'text-violet-600'],
    'rose'=>['ring'=>'ring-rose-500','bg'=>'bg-rose-50','icon'=>'text-rose-600'],
    'orange'=>['ring'=>'ring-orange-500','bg'=>'bg-orange-50','icon'=>'text-orange-600'],
    'cyan'=>['ring'=>'ring-cyan-500','bg'=>'bg-cyan-50','icon'=>'text-cyan-600'],
    'emerald'=>['ring'=>'ring-emerald-500','bg'=>'bg-emerald-50','icon'=>'text-emerald-600'],
    'amber'=>['ring'=>'ring-amber-500','bg'=>'bg-amber-50','icon'=>'text-amber-600'],
    'gray'=>['ring'=>'ring-gray-400','bg'=>'bg-gray-50','icon'=>'text-gray-500'],
];
$activePage = 'transactions';

$stepLabels = ['Type','Customer & Passengers','Flight Itinerary','Fare & Payment','Review & Submit'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Record Transaction - Base Fare CRM</title>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@400,0&display=swap" rel="stylesheet"/>
<script src="https://cdn.tailwindcss.com?plugins=forms"></script>
<script>
tailwind.config={darkMode:'class',theme:{extend:{fontFamily:{sans:['Inter','Manrope','sans-serif']},colors:{primary:{DEFAULT:'#0f1e3c','50':'#f0f4ff','100':'#dde8ff','500':'#1a3a6b','600':'#0f1e3c'},gold:{DEFAULT:'#c9a84c',light:'#f5e6c0'}}}}}
</script>
<style>
.step-panel{display:none}.step-panel.active{display:block}
.type-card{cursor:pointer;transition:all .15s ease}.type-card:hover{transform:translateY(-1px)}.type-card.selected{outline:2px solid;outline-offset:2px}
.gds-terminal{background:#0f172a;color:#4ade80;font-family:'Courier New',monospace}.gds-terminal::placeholder{color:#374151}
.seg-card{animation:slideIn .2s ease}@keyframes slideIn{from{opacity:0;transform:translateY(-4px)}to{opacity:1;transform:none}}
.fare-row{animation:slideIn .15s ease}
.field-input{width:100%;border:1px solid #e2e8f0;border-radius:0.5rem;padding:0.5rem 0.75rem;font-size:0.875rem;background:#f8fafc;outline:none;transition:all .15s}
.field-input:focus{box-shadow:0 0 0 2px rgba(15,30,60,0.2);border-color:rgba(15,30,60,0.4)}
.field-label{display:block;font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:0.375rem}
</style>
</head>
<body class="bg-[#f8f9fa] font-sans text-slate-900 antialiased min-h-screen">

<?php if ($isAdmin): ?>
<?php require __DIR__ . '/../partials/admin_sidebar.php'; ?>
<?php else: ?>
<?php $activePage = 'transactions'; require __DIR__ . '/../partials/agent_sidebar.php'; ?>
<?php endif; ?>

<main class="ml-60 pt-6 pb-20 px-8">

  <!-- Page Header -->
  <div class="flex items-center justify-between mb-6">
    <div>
      <h1 class="text-2xl font-extrabold text-primary tracking-tight flex items-center gap-2" style="font-family:Manrope">
        <span class="material-symbols-outlined text-2xl">add_card</span> Record Transaction
      </h1>
      <p class="text-sm text-slate-500 mt-0.5">Fill in details step by step. Fields marked <span class="text-rose-500 font-bold">*</span> are required.</p>
    </div>
    <a href="/transactions" class="inline-flex items-center gap-1.5 text-sm font-semibold text-slate-500 hover:text-primary transition-colors">
      <span class="material-symbols-outlined text-sm">arrow_back</span> Back to List
    </a>
  </div>

  <!-- Flash Messages -->
  <?php if ($flashError): ?>
  <div class="mb-4 px-4 py-3 bg-red-50 border border-red-200 rounded-xl text-sm font-semibold text-red-700 flex items-center gap-2">
    <span class="material-symbols-outlined text-base">error</span> <?= htmlspecialchars($flashError) ?>
  </div>
  <?php endif; ?>
  <?php if ($flashSuccess): ?>
  <div class="mb-4 px-4 py-3 bg-emerald-50 border border-emerald-200 rounded-xl text-sm font-semibold text-emerald-700 flex items-center gap-2">
    <span class="material-symbols-outlined text-base">check_circle</span> <?= htmlspecialchars($flashSuccess) ?>
  </div>
  <?php endif; ?>

  <!-- ═══ STEP INDICATOR ═══ -->
  <div class="mb-6 flex items-center gap-1" id="step-bar">
    <?php foreach ($stepLabels as $i => $lbl): $n=$i+1; ?>
    <div class="flex items-center gap-1 <?= $i > 0 ? 'ml-1' : '' ?>">
      <?php if ($i > 0): ?><div class="w-8 h-px bg-slate-200" id="step-line-<?=$n?>"></div><?php endif; ?>
      <button type="button" onclick="wizard.goTo(<?=$n?>)" id="step-btn-<?=$n?>"
        class="flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-bold transition-all
        <?= $n===1 ? 'bg-primary text-white' : 'bg-slate-100 text-slate-400' ?>">
        <span class="w-5 h-5 rounded-full flex items-center justify-center text-[10px] font-black
          <?= $n===1 ? 'bg-white/20' : 'bg-slate-200 text-slate-400' ?>"><?=$n?></span>
        <span class="hidden sm:inline"><?=$lbl?></span>
      </button>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- ═══ TWO-COLUMN LAYOUT ═══ -->
  <div class="grid grid-cols-1 lg:grid-cols-[1fr_280px] gap-6">

    <!-- LEFT: Steps Column -->
    <div>
    <form id="txnForm" method="POST" action="/transactions/create" enctype="multipart/form-data">
      <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
    <?php if ($pre['acceptance_id']): ?>
    <input type="hidden" name="acceptance_id" value="<?= (int)$pre['acceptance_id'] ?>">
    <?php endif; ?>

    <!-- Hidden JSON Inputs -->
    <input type="hidden" name="passengers_json" id="hidPassengers" value="[]">
    <input type="hidden" name="flight_data_json" id="hidFlightData" value="null">
    <input type="hidden" name="fare_breakdown_json" id="hidFareBreakdown" value="[]">
    <input type="hidden" name="type_specific_data_json" id="hidTypeData" value="null">
    <input type="hidden" name="additional_cards_json" id="hidAdditionalCards" value="null">

    <!-- ═══════════════════════════════════════════════════════════════ -->
    <!-- STEP 1: Transaction Type                                       -->
    <!-- ═══════════════════════════════════════════════════════════════ -->
    <div class="step-panel active" id="step-1">
      <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100 bg-slate-50/50">
          <h2 class="font-bold text-slate-900" style="font-family:Manrope">
            <span class="material-symbols-outlined text-base align-text-bottom mr-1">category</span>
            What type of transaction? <span class="text-rose-500">*</span>
          </h2>
          <p class="text-xs text-slate-500 mt-0.5">Select the transaction type. This determines which fields appear.</p>
        </div>
        <div class="p-6">
          <input type="hidden" name="type" id="field_type" value="<?= $pre['type'] ?>">
          <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
            <?php foreach ($typeCards as $tc):
              $cm = $colorMap[$tc['color']];
              $sel = $pre['type'] === $tc['value'];
            ?>
            <div onclick="selectType('<?= $tc['value'] ?>')" id="tc-<?= $tc['value'] ?>"
                 class="type-card rounded-xl border-2 p-4 text-center
                 <?= $sel ? $cm['ring'].' '.$cm['bg'].' selected border-transparent' : 'border-slate-200 bg-white hover:border-slate-300' ?>"
                 data-type="<?= $tc['value'] ?>" data-color="<?= $tc['color'] ?>">
              <span class="material-symbols-outlined text-2xl <?= $cm['icon'] ?> mb-2"><?= $tc['icon'] ?></span>
              <p class="text-xs font-bold text-slate-900"><?= $tc['label'] ?></p>
              <p class="text-[10px] text-slate-500 mt-0.5"><?= $tc['sub'] ?></p>
            </div>
            <?php endforeach; ?>
          </div>

          <!-- ── Other type description ─────────── -->
          <div id="section-other-desc" class="hidden mt-6 bg-amber-50 border border-amber-200 rounded-xl p-4">
            <h3 class="text-amber-900 font-bold text-sm flex items-center gap-1.5 mb-3">
              <span class="material-symbols-outlined text-lg">description</span>
              Charge Description <span class="text-[10px] bg-amber-100 px-2 py-0.5 rounded-full ml-1">Required for Other</span>
            </h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div>
                <label class="block text-[10px] font-bold text-amber-900 uppercase mb-1">Charge Title *</label>
                <input type="text" id="field_other_title" placeholder="e.g. Airport transfer fee..."
                       class="w-full border border-amber-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-amber-400 bg-white">
                <p class="text-[10px] text-amber-700 mt-1">A short title describing the charge.</p>
              </div>
              <div>
                <label class="block text-[10px] font-bold text-amber-900 uppercase mb-1">Additional Notes</label>
                <textarea id="field_other_notes" rows="2" class="w-full border border-amber-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-amber-400 bg-white resize-none"></textarea>
              </div>
            </div>
          </div>
          <div id="step1-error" class="hidden mt-3 text-rose-600 text-xs font-medium flex items-center gap-1">
            <span class="material-symbols-outlined text-sm">error</span> Please select a transaction type.
          </div>
        </div>
      </div>

      <!-- Step 1 Nav -->
      <div class="flex justify-end mt-4">
        <button type="button" onclick="wizard.next()"
          class="inline-flex items-center gap-2 bg-primary hover:bg-primary-500 text-white font-bold py-2.5 px-6 rounded-lg text-sm transition-colors">
          Next: Customer Info <span class="material-symbols-outlined text-base">arrow_forward</span>
        </button>
      </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════ -->
    <!-- STEP 2: Customer & Passengers                                  -->
    <!-- ═══════════════════════════════════════════════════════════════ -->
    <div class="step-panel" id="step-2">
      <div class="space-y-4">
        <!-- Customer Info -->
        <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
          <div class="px-6 py-4 border-b border-slate-100 bg-slate-50/50">
            <h2 class="font-bold text-slate-900" style="font-family:Manrope">
              <span class="material-symbols-outlined text-base align-text-bottom mr-1">person</span>
              Customer & Booking
            </h2>
          </div>
          <div class="p-6 space-y-4">
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
              <div>
                <label class="field-label">Customer Name <span class="text-rose-500">*</span></label>
                <input type="text" name="customer_name" id="field_customer_name" value="<?= $pre['customer_name'] ?>" placeholder="Full Name" class="field-input" oninput="syncSummary()">
              </div>
              <div>
                <label class="field-label">Email <span class="text-rose-500">*</span></label>
                <input type="email" name="customer_email" id="field_customer_email" value="<?= $pre['customer_email'] ?>" placeholder="email@example.com" class="field-input">
              </div>
              <div>
                <label class="field-label">Phone</label>
                <input type="text" name="customer_phone" id="field_customer_phone" value="<?= $pre['customer_phone'] ?>" placeholder="+1 234 567 8900" class="field-input">
              </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
              <div>
                <label class="field-label">PNR <span class="text-rose-500">*</span></label>
                <input type="text" name="pnr" id="field_pnr" maxlength="20" value="<?= $pre['pnr'] ?>" placeholder="ABC123" class="field-input font-mono font-bold tracking-wider uppercase" oninput="syncSummary()">
              </div>
              <div>
                <label class="field-label">Airline</label>
                <input type="text" name="airline" id="field_airline" value="<?= $pre['airline'] ?>" placeholder="e.g. Air Canada" class="field-input">
              </div>
              <div>
                <label class="field-label">Order ID</label>
                <input type="text" name="order_id" id="field_order_id" value="<?= $pre['order_id'] ?>" placeholder="Internal ref" class="field-input">
              </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
              <div>
                <label class="field-label">Travel Date</label>
                <input type="date" name="travel_date" id="field_travel_date" class="field-input">
              </div>
              <div>
                <label class="field-label">Departure Time</label>
                <input type="time" name="departure_time" id="field_departure_time" class="field-input">
              </div>
              <div>
                <label class="field-label">Return Date</label>
                <input type="date" name="return_date" id="field_return_date" class="field-input">
              </div>
            </div>
          </div>
        </div>

        <!-- Passengers -->
        <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
          <div class="px-6 py-4 border-b border-slate-100 bg-slate-50/50 flex items-center justify-between">
            <h2 class="font-bold text-slate-900" style="font-family:Manrope">
              <span class="material-symbols-outlined text-base align-text-bottom mr-1">groups</span>
              Passengers <span class="text-rose-500">*</span>
            </h2>
            <button type="button" onclick="paxMgr.add()"
              class="inline-flex items-center gap-1 text-xs text-primary font-semibold hover:text-primary-500 transition-colors">
              <span class="material-symbols-outlined text-sm">add_circle</span> Add Passenger
            </button>
          </div>
          <div class="p-6">
            <div id="pax-list" class="space-y-2"></div>
            <div id="step2-pax-error" class="hidden mt-2 text-rose-600 text-xs font-medium flex items-center gap-1">
              <span class="material-symbols-outlined text-sm">error</span> At least one passenger is required.
            </div>
          </div>
        </div>
      </div>

      <!-- Step 2 Nav -->
      <div class="flex justify-between mt-4">
        <button type="button" onclick="wizard.prev()"
          class="inline-flex items-center gap-2 bg-white border border-slate-200 text-slate-700 hover:bg-slate-50 font-bold py-2.5 px-4 rounded-lg text-sm transition-colors">
          <span class="material-symbols-outlined text-base">arrow_back</span> Back
        </button>
        <button type="button" onclick="wizard.next()"
          class="inline-flex items-center gap-2 bg-primary hover:bg-primary-500 text-white font-bold py-2.5 px-6 rounded-lg text-sm transition-colors">
          Next: Flights <span class="material-symbols-outlined text-base">arrow_forward</span>
        </button>
      </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════ -->
    <!-- STEP 3: Flight Itinerary (type-aware)                          -->
    <!-- ═══════════════════════════════════════════════════════════════ -->
    <div class="step-panel" id="step-3">
      <div class="space-y-4">
        <!-- Main itinerary — new_booking, seat_purchase, cabin_upgrade, name_correction -->
        <div id="sec-itinerary" class="hidden">
          <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-100 bg-slate-50/50 flex items-center justify-between">
              <h2 class="font-bold text-slate-900" style="font-family:Manrope">
                <span class="material-symbols-outlined text-base align-text-bottom mr-1">connecting_airports</span> Flight Itinerary
              </h2>
              <button type="button" onclick="flightMgr.addManual('main')" class="inline-flex items-center gap-1 text-xs text-primary font-semibold hover:text-primary-500 transition-colors">
                <span class="material-symbols-outlined text-sm">add_circle</span> Add Segment
              </button>
            </div>
            <div class="p-6 space-y-3">
              <div>
                <label class="field-label text-violet-600">Paste GDS Itinerary (Amadeus / Sabre)</label>
                <textarea id="gds-main" rows="3" placeholder="Paste GDS output here..." class="gds-terminal w-full rounded-lg px-3 py-2 text-xs resize-none" oninput="flightMgr.parse('main',this.value)"></textarea>
                <div id="parse-status-main" class="text-xs mt-1"></div>
              </div>
              <div id="segs-main" class="space-y-2"></div>
            </div>
          </div>
        </div>

        <!-- Old flights — exchange, cancel_refund, cancel_credit -->
        <div id="sec-old-flights" class="hidden">
          <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-100 bg-slate-50/50 flex items-center justify-between">
              <h2 class="font-bold text-slate-900" style="font-family:Manrope">
                <span class="material-symbols-outlined text-base align-text-bottom mr-1">flight_land</span> Original Flights
              </h2>
              <button type="button" onclick="flightMgr.addManual('old')" class="inline-flex items-center gap-1 text-xs text-primary font-semibold">
                <span class="material-symbols-outlined text-sm">add_circle</span> Add Segment
              </button>
            </div>
            <div class="p-6 space-y-3">
              <textarea id="gds-old" rows="3" placeholder="Paste original itinerary..." class="gds-terminal w-full rounded-lg px-3 py-2 text-xs resize-none" oninput="flightMgr.parse('old',this.value)"></textarea>
              <div id="parse-status-old" class="text-xs mt-1"></div>
              <div id="segs-old" class="space-y-2"></div>
            </div>
          </div>
        </div>

        <!-- New flights — exchange only -->
        <div id="sec-new-flights" class="hidden">
          <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-100 bg-slate-50/50 flex items-center justify-between">
              <h2 class="font-bold text-slate-900" style="font-family:Manrope">
                <span class="material-symbols-outlined text-base align-text-bottom mr-1">flight_takeoff</span> New Flights (After Change)
              </h2>
              <button type="button" onclick="flightMgr.addManual('new')" class="inline-flex items-center gap-1 text-xs text-primary font-semibold">
                <span class="material-symbols-outlined text-sm">add_circle</span> Add Segment
              </button>
            </div>
            <div class="p-6 space-y-3">
              <textarea id="gds-new" rows="3" placeholder="Paste new itinerary..." class="gds-terminal w-full rounded-lg px-3 py-2 text-xs resize-none" oninput="flightMgr.parse('new',this.value)"></textarea>
              <div id="parse-status-new" class="text-xs mt-1"></div>
              <div id="segs-new" class="space-y-2"></div>
            </div>
          </div>
        </div>

        <!-- Name correction -->
        <div id="sec-name-correction" class="hidden">
          <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-100 bg-slate-50/50">
              <h2 class="font-bold text-slate-900" style="font-family:Manrope">
                <span class="material-symbols-outlined text-base align-text-bottom mr-1">badge</span> Name Correction Details
              </h2>
            </div>
            <div class="p-6">
              <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div><label class="field-label">Old Name <span class="text-rose-500">*</span></label>
                  <input type="text" id="nc-old-name" placeholder="As originally booked" class="field-input uppercase"></div>
                <div><label class="field-label">New Name <span class="text-rose-500">*</span></label>
                  <input type="text" id="nc-new-name" placeholder="Corrected name" class="field-input uppercase"></div>
                <div><label class="field-label">Reason</label>
                  <input type="text" id="nc-reason" placeholder="e.g. Typo, Legal name change" class="field-input"></div>
              </div>
            </div>
          </div>
        </div>

        <!-- Cabin upgrade -->
        <div id="sec-cabin-upgrade" class="hidden">
          <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-100 bg-slate-50/50">
              <h2 class="font-bold text-slate-900" style="font-family:Manrope">
                <span class="material-symbols-outlined text-base align-text-bottom mr-1">upgrade</span> Cabin Upgrade Details
              </h2>
            </div>
            <div class="p-6">
              <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div><label class="field-label">Old Cabin</label>
                  <select id="cu-old-cabin" class="field-input"><option value="">Select</option><option>Economy</option><option>Premium Economy</option><option>Business</option><option>First</option></select></div>
                <div><label class="field-label">New Cabin</label>
                  <select id="cu-new-cabin" class="field-input"><option value="">Select</option><option>Economy</option><option>Premium Economy</option><option>Business</option><option>First</option></select></div>
              </div>
            </div>
          </div>
        </div>

        <!-- Other -->
        <div id="sec-other-info" class="hidden">
          <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-100 bg-slate-50/50 flex items-center justify-between">
              <h2 class="font-bold text-slate-900" style="font-family:Manrope">
                <span class="material-symbols-outlined text-base align-text-bottom mr-1">description</span> Other Details
              </h2>
            </div>
            <div class="p-6 space-y-4">
              <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                  <label class="field-label">Description <span class="text-rose-500">*</span></label>
                  <textarea id="other-desc" rows="3" placeholder="Describe the service/charge being authorized..." class="field-input resize-none"></textarea>
                </div>
                <div>
                  <label class="field-label">Reference / Booking Number</label>
                  <input type="text" id="field_other_reference" placeholder="e.g. HTLXYZ1234, INV-001" class="field-input">
                  <p class="text-[10px] text-slate-500 mt-1">Hotel confirmation, invoice #, or supplier reference.</p>
                </div>
              </div>
              <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                  <label class="field-label">Service Provider</label>
                  <input type="text" id="field_other_provider" placeholder="e.g. Marriott Hotels, Enterprise, Visa Office" class="field-input">
                </div>
                <div>
                  <label class="field-label">Payment Summary</label>
                  <input type="text" id="field_other_payment_summary" placeholder="e.g. 2 nights × $200 = $400" class="field-input">
                  <p class="text-[10px] text-slate-500 mt-1">Brief itemized breakdown of the charge.</p>
                </div>
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
                  <label class="field-label text-violet-600">Paste GDS Itinerary (Amadeus / Sabre)</label>
                  <textarea id="gds-other" rows="3" placeholder="Paste GDS output here..." class="gds-terminal w-full rounded-lg px-3 py-2 text-xs resize-none" oninput="flightMgr.parse('other', this.value)"></textarea>
                  <div id="parse-status-other" class="text-xs mt-1"></div>
                  <div id="segs-other" class="space-y-2"></div>
                  <button type="button" onclick="flightMgr.addManual('other')" class="inline-flex items-center gap-1 text-xs text-primary font-semibold hover:text-primary-500 transition-colors">
                    <span class="material-symbols-outlined text-sm">add_circle</span> Add Segment Manually
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- No sections hint -->
        <div id="sec-no-type" class="text-center py-12 text-slate-400">
          <span class="material-symbols-outlined text-4xl mb-2">info</span>
          <p class="text-sm font-medium">Select a transaction type in Step 1 to see relevant flight fields.</p>
        </div>
      </div>

      <!-- Step 3 Nav -->
      <div class="flex justify-between mt-4">
        <button type="button" onclick="wizard.prev()" class="inline-flex items-center gap-2 bg-white border border-slate-200 text-slate-700 hover:bg-slate-50 font-bold py-2.5 px-4 rounded-lg text-sm transition-colors">
          <span class="material-symbols-outlined text-base">arrow_back</span> Back
        </button>
        <button type="button" onclick="wizard.next()" class="inline-flex items-center gap-2 bg-primary hover:bg-primary-500 text-white font-bold py-2.5 px-6 rounded-lg text-sm transition-colors">
          Next: Fare & Payment <span class="material-symbols-outlined text-base">arrow_forward</span>
        </button>
      </div>
    </div>

    <!-- Steps 4-5 loaded from partial -->
    <?php require __DIR__ . '/create_steps4_5.php'; ?>

    </form>
    </div><!-- /LEFT -->

    <!-- RIGHT: Sidebar -->
    <div class="space-y-4 sticky top-6 self-start">
      <!-- Import from Acceptance -->
      <div class="bg-gradient-to-b from-blue-50 to-indigo-50 border border-blue-200 rounded-xl shadow-sm overflow-hidden">
        <div class="px-4 py-3 border-b border-blue-100 flex items-center gap-2" style="background:#0f1e3c">
          <span class="material-symbols-outlined text-blue-300 text-base">magic_button</span>
          <span class="text-sm font-bold text-white">Import from Acceptance</span>
        </div>
        <div class="p-4 space-y-2">
          <p class="text-[10px] text-blue-700 font-medium">Auto-fill from an approved acceptance request.</p>
          <?php if (!empty($autofillOptions)): ?>
          <select id="acceptance_import_select" onchange="importAcceptance(this.value)" class="w-full text-xs border border-blue-200 rounded-lg px-2 py-1.5 bg-white focus:outline-none focus:ring-2 focus:ring-blue-400">
            <option value="">Select acceptance...</option>
            <?php foreach ($autofillOptions as $opt): ?>
            <option value="<?= $opt['id'] ?>"><?= htmlspecialchars($opt['label']) ?></option>
            <?php endforeach; ?>
          </select>
          <?php else: ?>
          <p class="text-[10px] text-slate-400 italic">No approved acceptances available.</p>
          <?php endif; ?>
          <div class="flex items-center gap-1.5">
            <input type="number" id="manual_acc_id" placeholder="Or enter ID" class="flex-1 text-xs border border-blue-200 rounded-lg px-2 py-1.5 bg-white focus:outline-none focus:ring-2 focus:ring-blue-400">
            <button type="button" onclick="importAcceptance(document.getElementById('manual_acc_id').value)" class="px-2 py-1.5 bg-blue-600 text-white text-xs font-bold rounded-lg hover:bg-blue-500">Fetch</button>
          </div>
        </div>
      </div>

      <!-- Draft Summary -->
      <div class="bg-white border border-primary-100 rounded-xl shadow-sm overflow-hidden">
        <div class="px-4 py-3 border-b border-slate-100 flex items-center gap-2" style="background:#0f1e3c">
          <span class="material-symbols-outlined text-amber-400 text-base">edit_note</span>
          <span class="text-sm font-bold text-white">Draft Summary</span>
        </div>
        <div class="p-4 space-y-2.5">
          <div class="flex justify-between items-start">
            <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Type</span>
            <span id="sum-type" class="text-xs font-semibold text-slate-700 text-right max-w-[130px]">--</span>
          </div>
          <div class="flex justify-between items-center">
            <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">PNR</span>
            <span id="sum-pnr" class="text-xs font-black font-mono text-primary">--</span>
          </div>
          <div class="flex justify-between items-center">
            <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Customer</span>
            <span id="sum-name" class="text-xs font-semibold text-slate-800 text-right max-w-[130px] truncate">--</span>
          </div>
          <div class="flex justify-between items-center">
            <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Passengers</span>
            <span id="sum-pax" class="text-xs font-bold text-slate-900">0</span>
          </div>
          <div class="flex justify-between items-center">
            <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Total</span>
            <span id="sum-total" class="text-sm font-black text-emerald-700">--</span>
          </div>
          <div class="flex justify-between items-center">
            <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Step</span>
            <span id="sum-step" class="text-xs font-bold text-primary">1 / 5</span>
          </div>
        </div>
      </div>
    </div><!-- /RIGHT sidebar -->

  </div><!-- /grid -->
</main>



<!-- JavaScript loaded from partial -->
<script>
<?php require __DIR__ . '/create_js.php'; ?>
</script>
</body>
</html>
