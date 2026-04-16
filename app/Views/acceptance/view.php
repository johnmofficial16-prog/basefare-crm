<?php
/**
 * Acceptance Request — Detail View
 *
 * @var \App\Models\AcceptanceRequest $acceptance
 * @var string|null $flashSuccess
 * @var string|null $flashError
 */

use App\Models\AcceptanceRequest;
use Carbon\Carbon;

if (!isset($acceptance)) {
    http_response_code(404);
    die('Acceptance request not found.');
}

$isApproved  = $acceptance->isApproved();
$isPending   = $acceptance->isPending();
$isExpired   = $acceptance->isExpired();
$isCancelled = $acceptance->isCancelled();

// Status display config
$statusCfg = match($acceptance->status) {
    'APPROVED'  => ['bg' => 'bg-emerald-100', 'text' => 'text-emerald-800', 'border' => 'border-emerald-200', 'icon' => 'verified', 'dot' => 'bg-emerald-500', 'label' => 'Approved'],
    'PENDING'   => ['bg' => 'bg-amber-100',   'text' => 'text-amber-800',   'border' => 'border-amber-200',   'icon' => 'schedule', 'dot' => 'bg-amber-500',   'label' => 'Pending'],
    'EXPIRED'   => ['bg' => 'bg-rose-100',    'text' => 'text-rose-800',    'border' => 'border-rose-200',    'icon' => 'timer_off', 'dot' => 'bg-rose-500',    'label' => 'Expired'],
    'CANCELLED' => ['bg' => 'bg-slate-100',   'text' => 'text-slate-600',   'border' => 'border-slate-200',   'icon' => 'cancel',    'dot' => 'bg-slate-400',   'label' => 'Cancelled'],
    default     => ['bg' => 'bg-slate-100',   'text' => 'text-slate-600',   'border' => 'border-slate-200',   'icon' => 'help',      'dot' => 'bg-slate-400',   'label' => $acceptance->status],
};

// Flight data helpers
$flightData   = $acceptance->flight_data   ?? [];
$fareBreakdown= $acceptance->fare_breakdown ?? [];
$passengers   = $acceptance->passengers    ?? [];
$additionalCards = $acceptance->additional_cards ?? [];

// Extra data — cancel/refund and cancel/credit fields
$extraData    = $acceptance->extra_data ?? [];
if (is_string($extraData)) $extraData = json_decode($extraData, true) ?: [];
$crRefundAmt  = $extraData['refund_amount']   ?? null;
$crCancelFee  = $extraData['cancel_fee']      ?? null;
$crMethod     = $extraData['refund_method']   ?? null;
$crTimeline   = $extraData['refund_timeline'] ?? null;
$ccCreditAmt  = $extraData['credit_amount']   ?? null;
$ccValidUntil = $extraData['valid_until']     ?? null;
$ccInstructions= $extraData['instructions']   ?? null;
$ccEtktList   = $extraData['etkt_list']       ?? [];

// Primary airline IATA (from first flight segment, if available)
$primaryIata = '';
if (!empty($flightData['flights'][0]['airline_iata'])) {
    $primaryIata = $flightData['flights'][0]['airline_iata'];
} elseif (!empty($flightData['old_flights'][0]['airline_iata'])) {
    $primaryIata = $flightData['old_flights'][0]['airline_iata'];
}
if (empty($primaryIata) && !empty($acceptance->airline)) {
    // Try extracting from airline name match — just skip, logo won't show
}

$logoUrl = $primaryIata ? AcceptanceRequest::airlineLogoUrl($primaryIata, 70) : '';

// Public link
$publicUrl = $acceptance->publicUrl();

// Expiry label
$expiryLabel = $acceptance->expiryLabel();

// Agent name
$agentName = '';
try {
    if ($acceptance->agent) {
        $agentName = $acceptance->agent->name ?? ('Agent #' . $acceptance->agent_id);
    }
} catch (\Throwable $e) {
    $agentName = 'Agent #' . $acceptance->agent_id;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Acceptance #<?= $acceptance->id ?> — <?= htmlspecialchars($acceptance->customer_name) ?> — Base Fare CRM</title>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@400,0&display=swap" rel="stylesheet"/>
<script src="https://cdn.tailwindcss.com?plugins=forms"></script>
<script>
tailwind.config = {
  theme: {
    extend: {
      fontFamily: { sans: ['Inter','Manrope','sans-serif'] },
      colors: {
        primary: { DEFAULT:'#0f1e3c', 50:'#f0f4ff', 100:'#dde8ff', 500:'#1a3a6b', 600:'#0f1e3c' },
        gold: { DEFAULT:'#c9a84c', light:'#f5e6c0' }
      }
    }
  }
}
</script>
<style>
  .sig-img { image-rendering: pixelated; }
  .copy-btn { transition: all 0.15s ease; }
  .copy-btn:active { transform: scale(0.95); }
  .audit-row:hover { background: #f8fafc; }
  @media print {
    .no-print { display: none !important; }
    body { background: white; }
  }
</style>
</head>
<body class="bg-slate-50 font-sans">

<?php require __DIR__ . '/../layout/sidebar.php'; ?>

<div class="ml-60 min-h-screen">
<div class="max-w-5xl mx-auto px-6 py-8 space-y-5">

  <!-- Flash messages -->
  <?php if (!empty($flashSuccess)): ?>
  <div class="flex items-center gap-3 bg-emerald-50 border border-emerald-200 rounded-xl p-4">
    <span class="material-symbols-outlined text-emerald-600 flex-none">check_circle</span>
    <p class="text-emerald-800 text-sm font-medium"><?= htmlspecialchars($flashSuccess) ?></p>
  </div>
  <?php endif; ?>
  <?php if (!empty($flashError)): ?>
  <div class="flex items-center gap-3 bg-rose-50 border border-rose-200 rounded-xl p-4">
    <span class="material-symbols-outlined text-rose-500 flex-none">error</span>
    <p class="text-rose-800 text-sm font-medium"><?= htmlspecialchars($flashError) ?></p>
  </div>
  <?php endif; ?>

  <!-- ── PAGE HEADER ── -->
  <div class="flex items-start justify-between gap-4 flex-wrap">
    <div>
      <div class="flex items-center gap-2 text-[11px] text-slate-400 font-medium mb-1">
        <a href="/acceptance" class="hover:text-primary-600 transition-colors">Acceptance Requests</a>
        <span class="material-symbols-outlined text-xs">chevron_right</span>
        <span class="text-slate-600 font-semibold">#<?= $acceptance->id ?></span>
      </div>
      <div class="flex items-center gap-3 flex-wrap">
        <h1 class="text-2xl font-bold text-slate-900" style="font-family:Manrope,sans-serif;">
          <?= htmlspecialchars($acceptance->customer_name) ?>
        </h1>
        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-sm font-bold <?= $statusCfg['bg'] ?> <?= $statusCfg['text'] ?> border <?= $statusCfg['border'] ?>">
          <span class="w-1.5 h-1.5 rounded-full <?= $statusCfg['dot'] ?>"></span>
          <?= $statusCfg['label'] ?>
        </span>
        <?php if ($isPending && $expiryLabel): ?>
        <span class="inline-flex items-center gap-1 text-xs text-amber-700 bg-amber-50 border border-amber-200 px-2 py-1 rounded-full">
          <span class="material-symbols-outlined text-sm">timer</span>
          <?= htmlspecialchars($expiryLabel) ?>
        </span>
        <?php endif; ?>
      </div>
      <p class="text-slate-500 text-sm mt-1">
        <?= htmlspecialchars($acceptance->typeLabel()) ?> &middot;
        PNR: <strong class="font-mono text-primary-600"><?= htmlspecialchars($acceptance->pnr) ?></strong>
        &middot; Created <?= Carbon::parse($acceptance->created_at)->format('M j, Y \a\t g:i A') ?>
        <?php if (!empty($acceptance->is_preauth)): ?>
        &middot; <span class="inline-flex items-center gap-0.5 text-[10px] font-black text-amber-700 bg-amber-100 px-2 py-0.5 rounded-full border border-amber-200 ml-1"><span class="material-symbols-outlined text-[10px]">bolt</span>PRE-AUTH</span>
        <?php endif; ?>
      </p>
    </div>

    <!-- Action Buttons -->
    <div class="flex gap-2 flex-wrap no-print">
      <?php if ($isPending): ?>
      <button onclick="doResend()" id="btn-resend"
        class="inline-flex items-center gap-2 bg-primary-600 hover:bg-primary-500 text-white font-bold py-2 px-4 rounded-lg text-sm transition-colors shadow-sm">
        <span class="material-symbols-outlined text-base">send</span> Resend Link
      </button>
      <button onclick="confirmCancel()"
        class="inline-flex items-center gap-2 bg-white border border-rose-200 text-rose-700 hover:bg-rose-50 font-semibold py-2 px-4 rounded-lg text-sm transition-colors">
        <span class="material-symbols-outlined text-base">cancel</span> Cancel
      </button>
      <?php endif; ?>
      <?php if ($isApproved): ?>
      <?php if (!empty($acceptance->is_preauth)): ?>
      <!-- Pre-auth approved: show promote button -->
      <a href="/acceptance/create?from_preauth=<?= $acceptance->id ?>"
        class="inline-flex items-center gap-2 bg-indigo-600 hover:bg-indigo-500 text-white font-bold py-2 px-4 rounded-lg text-sm transition-colors shadow-sm">
        <span class="material-symbols-outlined text-base">upgrade</span> Create Full Acceptance
      </a>
      <?php else: ?>
      <a href="/transactions/create?autofill=<?= $acceptance->id ?>"
        class="inline-flex items-center gap-2 bg-emerald-600 hover:bg-emerald-500 text-white font-bold py-2 px-4 rounded-lg text-sm transition-colors shadow-sm">
        <span class="material-symbols-outlined text-base">add_card</span> Record Transaction
      </a>
      <?php endif; ?>
      <?php endif; ?>
      <button onclick="window.print()"
        class="inline-flex items-center gap-2 bg-white border border-slate-200 text-slate-700 hover:bg-slate-50 font-semibold py-2 px-3 rounded-lg text-sm transition-colors">
        <span class="material-symbols-outlined text-base">print</span>
      </button>
      <a href="/acceptance" class="inline-flex items-center gap-2 bg-white border border-slate-200 text-slate-700 hover:bg-slate-50 font-semibold py-2 px-4 rounded-lg text-sm transition-colors">
        <span class="material-symbols-outlined text-base">list</span> All Requests
      </a>
    </div>
  </div>

  <!-- Pre-auth relationship banner -->
  <?php if (!empty($acceptance->is_preauth)): ?>
  <div class="bg-amber-50 border border-amber-200 rounded-xl px-5 py-3.5 flex items-center gap-3">
    <span class="material-symbols-outlined text-amber-600 text-lg">bolt</span>
    <div class="flex-1">
      <p class="font-bold text-amber-900 text-sm">Quick Pre-Authorization</p>
      <p class="text-amber-700 text-xs mt-0.5">This is a simplified pre-auth. Once approved, create a Full Acceptance with the complete fare breakdown.</p>
    </div>
    <?php if ($isApproved): ?>
    <a href="/acceptance/create?from_preauth=<?= $acceptance->id ?>" class="flex-none inline-flex items-center gap-1 bg-indigo-600 hover:bg-indigo-500 text-white font-bold py-2 px-3 rounded-lg text-xs transition-colors">
      <span class="material-symbols-outlined text-sm">upgrade</span> Create Full Acceptance
    </a>
    <?php endif; ?>
  </div>
  <?php elseif ($acceptance->preauth_id): ?>
  <?php $parentPreauth = \App\Models\AcceptanceRequest::find($acceptance->preauth_id); ?>
  <?php if ($parentPreauth): ?>
  <div class="bg-indigo-50 border border-indigo-200 rounded-xl px-5 py-3.5 flex items-center gap-3">
    <span class="material-symbols-outlined text-indigo-600 text-lg">link</span>
    <div class="flex-1">
      <p class="font-bold text-indigo-900 text-sm">Full Acceptance — Promoted from Pre-Auth</p>
      <p class="text-indigo-600 text-xs mt-0.5">This record was created from Pre-Auth <a href="/acceptance/<?= $parentPreauth->id ?>" class="font-bold underline">#<?= $parentPreauth->id ?></a> (<?= htmlspecialchars($parentPreauth->customer_name) ?>, approved <?= $parentPreauth->approved_at ? Carbon::parse($parentPreauth->approved_at)->format('M j g:i A') : '—' ?>).</p>
    </div>
  </div>
  <?php endif; ?>
  <?php endif; ?>

  <!-- ── MAIN GRID ── -->
  <div class="grid grid-cols-1 xl:grid-cols-[1fr_300px] gap-5">

    <!-- LEFT COLUMN -->
    <div class="space-y-5">

      <!-- ── AUTHORIZATION LINK PANEL ── -->
      <?php if ($isPending): ?>
      <div class="bg-white border border-primary-100 rounded-xl shadow-sm overflow-hidden">
        <div class="px-5 py-3 flex items-center gap-2" style="background:linear-gradient(135deg,#0f1e3c,#1a3a6b);">
          <span class="material-symbols-outlined text-gold text-base">link</span>
          <span class="text-white font-bold text-sm">Customer Authorization Link</span>
          <span class="ml-auto text-[10px] text-blue-300">Expires <?= htmlspecialchars($acceptance->expires_at->format('M j \a\t g:i A')) ?></span>
        </div>
        <div class="p-4">
          <div class="flex items-center gap-2">
            <code class="flex-1 text-xs font-mono bg-slate-50 border border-slate-200 rounded-lg px-3 py-2.5 text-primary-600 overflow-x-auto whitespace-nowrap" id="public-url"><?= htmlspecialchars($publicUrl) ?></code>
            <button onclick="copyLink()" id="copy-btn" class="copy-btn flex-none inline-flex items-center gap-1.5 bg-primary-600 hover:bg-primary-500 text-white font-bold text-xs py-2.5 px-3 rounded-lg transition-colors">
              <span class="material-symbols-outlined text-sm">content_copy</span>
              <span id="copy-label">Copy</span>
            </button>
          </div>
          <p class="text-[11px] text-slate-400 mt-2">Send this link to the customer via email or messaging. It opens a secure signing portal on base-fare.com.</p>
        </div>
      </div>
      <?php endif; ?>

      <!-- ── CUSTOMER & BOOKING INFO ── -->
      <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
        <div class="px-5 py-3.5 border-b border-slate-100 bg-slate-50/50 flex items-center gap-2">
          <span class="material-symbols-outlined text-slate-500 text-base">person</span>
          <h2 class="font-bold text-slate-900 text-sm" style="font-family:Manrope,sans-serif;">Customer & Booking</h2>
        </div>
        <div class="p-5 grid grid-cols-2 sm:grid-cols-3 gap-4">
          <div>
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Customer Name</p>
            <p class="text-sm font-semibold text-slate-900 mt-1"><?= htmlspecialchars($acceptance->customer_name) ?></p>
          </div>
          <div>
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Email</p>
            <a href="mailto:<?= htmlspecialchars($acceptance->customer_email) ?>" class="text-sm text-primary-600 font-medium hover:underline mt-1 block truncate">
              <?= htmlspecialchars($acceptance->customer_email) ?>
            </a>
          </div>
          <?php if ($acceptance->customer_phone): ?>
          <div>
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Phone</p>
            <p class="text-sm text-slate-700 mt-1"><?= htmlspecialchars($acceptance->customer_phone) ?></p>
          </div>
          <?php endif; ?>
          <div>
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">PNR</p>
            <p class="text-sm font-black font-mono text-primary-600 mt-1"><?= htmlspecialchars($acceptance->pnr) ?></p>
          </div>
          <?php if ($acceptance->order_id): ?>
          <div>
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Order ID</p>
            <p class="text-sm font-mono text-slate-700 mt-1"><?= htmlspecialchars($acceptance->order_id) ?></p>
          </div>
          <?php endif; ?>
          <?php if ($acceptance->airline): ?>
          <div>
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Airline</p>
            <div class="flex items-center gap-2 mt-1">
              <?php if ($logoUrl): ?>
              <img src="<?= htmlspecialchars($logoUrl) ?>" alt="<?= htmlspecialchars($primaryIata) ?>"
                class="w-6 h-6 object-contain" onerror="this.style.display='none'">
              <?php endif; ?>
              <p class="text-sm font-semibold text-slate-800"><?= htmlspecialchars($acceptance->airline) ?></p>
            </div>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- ── PASSENGERS ── -->
      <?php if (!empty($passengers)): ?>
      <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
        <div class="px-5 py-3.5 border-b border-slate-100 bg-slate-50/50 flex items-center justify-between">
          <div class="flex items-center gap-2">
            <span class="material-symbols-outlined text-slate-500 text-base">group</span>
            <h2 class="font-bold text-slate-900 text-sm" style="font-family:Manrope,sans-serif;">Passengers</h2>
          </div>
          <span class="text-xs font-bold text-slate-500"><?= count($passengers) ?> pax</span>
        </div>
        <div class="p-5">
          <div class="flex flex-wrap gap-2">
            <?php foreach ($passengers as $pax):
              $pType = strtolower($pax['type'] ?? 'adult');
              $typeColor = match($pType) {
                'child'  => 'bg-violet-100 text-violet-800',
                'infant' => 'bg-pink-100 text-pink-800',
                default  => 'bg-slate-100 text-slate-800',
              };
            ?>
            <div class="inline-flex items-center gap-2 px-3 py-2 <?= $typeColor ?> rounded-lg">
              <span class="material-symbols-outlined text-sm">person</span>
              <div>
                <p class="text-xs font-bold font-mono"><?= htmlspecialchars($pax['name'] ?? '') ?></p>
                <?php if (!empty($pax['dob'])): ?>
                <p class="text-[10px] opacity-70">DOB: <?= htmlspecialchars($pax['dob']) ?></p>
                <?php endif; ?>
              </div>
              <span class="text-[10px] font-bold uppercase opacity-60"><?= htmlspecialchars($pType) ?></span>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <!-- ── FLIGHT SECTIONS (Type-Aware) ── -->

      <?php
      // Helper to render a list of segment cards
      function renderSegs(array $segs, string $theme = 'blue'): void {
          static $AIRLINES = [
              'AC'=>'Air Canada','WS'=>'WestJet','AA'=>'American Airlines','DL'=>'Delta','UA'=>'United',
              'BA'=>'British Airways','LH'=>'Lufthansa','AF'=>'Air France','KL'=>'KLM','EK'=>'Emirates',
              'QR'=>'Qatar Airways','SQ'=>'Singapore Airlines','CX'=>'Cathay Pacific','JL'=>'Japan Airlines',
              'NH'=>'ANA','TK'=>'Turkish Airlines','EY'=>'Etihad','LX'=>'Swiss','OS'=>'Austrian',
              'AI'=>'Air India','TP'=>'TAP Portugal','VS'=>'Virgin Atlantic','AM'=>'Aeromexico',
              'KE'=>'Korean Air','QF'=>'Qantas','BR'=>'EVA Air','CI'=>'China Airlines',
              'TG'=>'Thai Airways','VN'=>'Vietnam Airlines','MH'=>'Malaysia Airlines','SV'=>'Saudia',
              'MS'=>'EgyptAir','ET'=>'Ethiopian Airlines','AT'=>'Royal Air Maroc',
              'F9'=>'Frontier','NK'=>'Spirit','B6'=>'JetBlue','WN'=>'Southwest','AS'=>'Alaska Airlines',
          ];
          static $CITIES = [
              'YYZ'=>'Toronto','YVR'=>'Vancouver','YUL'=>'Montreal','YYC'=>'Calgary',
              'LHR'=>'London Heathrow','LGW'=>'London Gatwick','CDG'=>'Paris CDG','FRA'=>'Frankfurt',
              'AMS'=>'Amsterdam','MAD'=>'Madrid','FCO'=>'Rome','MXP'=>'Milan','ZRH'=>'Zurich',
              'IST'=>'Istanbul','DXB'=>'Dubai','DOH'=>'Doha','AUH'=>'Abu Dhabi',
              'BOM'=>'Mumbai','DEL'=>'Delhi','BLR'=>'Bangalore','MAA'=>'Chennai','HYD'=>'Hyderabad',
              'JFK'=>'New York JFK','EWR'=>'Newark','LAX'=>'Los Angeles','SFO'=>'San Francisco',
              'ORD'=>'Chicago','MIA'=>'Miami','DFW'=>'Dallas','SEA'=>'Seattle','BOS'=>'Boston',
              'ATL'=>'Atlanta','DEN'=>'Denver','SIN'=>'Singapore','HKG'=>'Hong Kong','BKK'=>'Bangkok',
              'NRT'=>'Tokyo Narita','HND'=>'Tokyo Haneda','ICN'=>'Seoul','SYD'=>'Sydney','MEL'=>'Melbourne',
          ];
          if (empty($segs)) {
              echo '<p class="text-xs text-slate-400 italic">No segments recorded.</p>';
              return;
          }
          $accent = $theme === 'rose' ? 'bg-rose-700' : ($theme === 'emerald' ? 'bg-emerald-700' : 'bg-slate-800');
          foreach ($segs as $i => $seg):
              $iata   = strtoupper($seg['airline_iata'] ?? '');
              $aName  = $AIRLINES[$iata] ?? $iata;
              $from   = strtoupper($seg['from'] ?? '');
              $to     = strtoupper($seg['to'] ?? '');
              $fCity  = $CITIES[$from] ?? $from;
              $tCity  = $CITIES[$to] ?? $to;
              $logo   = $iata ? "https://www.gstatic.com/flights/airline_logos/70px/{$iata}.png" : '';
              $nextDay = !empty($seg['arr_next_day']);
              ?>
              <div class="flex items-stretch bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm">
                <div class="<?= $accent ?> px-4 py-3 flex flex-col items-center justify-center gap-1.5 min-w-[80px]">
                  <?php if ($logo): ?>
                  <img src="<?= htmlspecialchars($logo) ?>" alt="<?= htmlspecialchars($iata) ?>"
                    class="w-9 h-9 object-contain" onerror="this.style.display='none'">
                  <?php endif; ?>
                  <span class="text-[11px] font-black text-white"><?= htmlspecialchars($iata) ?></span>
                  <span class="text-[9px] text-slate-300 text-center leading-tight"><?= htmlspecialchars($aName) ?></span>
                </div>
                <div class="flex-1 p-4 grid grid-cols-[1fr_auto_1fr] gap-2 items-center">
                  <div class="text-right">
                    <div class="text-xl font-black text-slate-900"><?= htmlspecialchars($seg['dep_time'] ?? '') ?></div>
                    <div class="text-sm font-bold text-primary-600"><?= htmlspecialchars($from) ?></div>
                    <div class="text-[10px] text-slate-400"><?= htmlspecialchars($fCity) ?></div>
                  </div>
                  <div class="flex flex-col items-center px-2 gap-0.5">
                    <div class="text-[10px] font-bold text-slate-500"><?= htmlspecialchars($seg['flight_no'] ?? '') ?></div>
                    <div class="text-[9px] text-slate-400 bg-slate-100 px-1.5 py-0.5 rounded font-mono"><?= htmlspecialchars($seg['cabin_class'] ?? '') ?></div>
                    <div class="w-16 h-px bg-slate-300 relative my-1">
                      <div class="absolute -right-2 -top-2 text-blue-500 text-sm">✈</div>
                    </div>
                    <div class="text-[9px] text-slate-400"><?= htmlspecialchars($seg['date'] ?? '') ?></div>
                  </div>
                  <div>
                    <div class="flex items-baseline gap-1">
                      <span class="text-xl font-black text-slate-900"><?= htmlspecialchars($seg['arr_time'] ?? '') ?></span>
                      <?php if ($nextDay): ?>
                      <span class="px-1 py-0.5 bg-rose-100 text-rose-700 text-[9px] font-bold rounded">+1d</span>
                      <?php endif; ?>
                    </div>
                    <div class="text-sm font-bold text-primary-600"><?= htmlspecialchars($to) ?></div>
                    <div class="text-[10px] text-slate-400"><?= htmlspecialchars($tCity) ?></div>
                  </div>
                </div>
              </div>
              <?php if ($i < count($segs)-1): ?>
              <div class="flex items-center gap-2 px-3 py-1.5 bg-amber-50 border border-amber-200 rounded-lg text-xs font-semibold text-amber-700">
                <span class="material-symbols-outlined text-sm">connecting_airports</span>
                Connection in <?= htmlspecialchars($CITIES[$to] ?? $to) ?>
              </div>
              <?php endif; ?>
          <?php endforeach;
      }
      ?>

      <?php if ($acceptance->hasItinerary() && !empty($flightData['flights'])): ?>
      <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
        <div class="px-5 py-3.5 border-b border-slate-100 bg-slate-50/50 flex items-center gap-2">
          <span class="material-symbols-outlined text-blue-500 text-base">flight_takeoff</span>
          <h2 class="font-bold text-slate-900 text-sm" style="font-family:Manrope,sans-serif;">Flight Itinerary</h2>
          <span class="ml-auto text-[10px] font-bold text-slate-400"><?= count($flightData['flights']) ?> segment<?= count($flightData['flights'])>1?'s':'' ?></span>
        </div>
        <div class="p-5 space-y-2"><?php renderSegs($flightData['flights'], 'blue'); ?></div>
      </div>
      <?php endif; ?>

      <?php if ($acceptance->hasOldFlights() && !empty($flightData['old_flights'])): ?>
      <div class="bg-white border border-rose-200 rounded-xl shadow-sm overflow-hidden">
        <div class="px-5 py-3.5 border-b border-rose-100 bg-rose-50/40 flex items-center gap-2">
          <span class="material-symbols-outlined text-rose-500 text-base">flight_land</span>
          <h2 class="font-bold text-rose-900 text-sm" style="font-family:Manrope,sans-serif;">Original Flights (Before Change)</h2>
        </div>
        <div class="p-5 space-y-2"><?php renderSegs($flightData['old_flights'], 'rose'); ?></div>
      </div>
      <?php endif; ?>

      <?php if ($acceptance->hasNewFlights() && !empty($flightData['new_flights'])): ?>
      <div class="bg-white border border-emerald-200 rounded-xl shadow-sm overflow-hidden">
        <div class="px-5 py-3.5 border-b border-emerald-100 bg-emerald-50/40 flex items-center gap-2">
          <span class="material-symbols-outlined text-emerald-600 text-base">flight_takeoff</span>
          <h2 class="font-bold text-emerald-900 text-sm" style="font-family:Manrope,sans-serif;">New Flights (After Change)</h2>
        </div>
        <div class="p-5 space-y-2"><?php renderSegs($flightData['new_flights'], 'emerald'); ?></div>
      </div>
      <?php endif; ?>

      <!-- ── NAME CORRECTION ── -->
      <?php if ($acceptance->type === 'name_correction' && !empty($flightData['old_name'])): ?>
      <div class="bg-white border border-yellow-200 rounded-xl shadow-sm overflow-hidden">
        <div class="px-5 py-3.5 border-b border-yellow-100 bg-yellow-50/40 flex items-center gap-2">
          <span class="material-symbols-outlined text-yellow-600 text-base">badge</span>
          <h2 class="font-bold text-slate-900 text-sm" style="font-family:Manrope,sans-serif;">Name Correction</h2>
        </div>
        <div class="p-5 grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div class="p-3 bg-rose-50 border border-rose-200 rounded-lg">
            <p class="text-[10px] font-bold text-rose-500 uppercase mb-1">Current (Wrong) Name</p>
            <p class="font-mono font-bold text-rose-800"><?= htmlspecialchars($flightData['old_name']) ?></p>
          </div>
          <div class="p-3 bg-emerald-50 border border-emerald-200 rounded-lg">
            <p class="text-[10px] font-bold text-emerald-600 uppercase mb-1">Corrected Name</p>
            <p class="font-mono font-bold text-emerald-800"><?= htmlspecialchars($flightData['new_name'] ?? '') ?></p>
          </div>
          <?php if (!empty($flightData['reason'])): ?>
          <div class="sm:col-span-2">
            <p class="text-[10px] font-bold text-slate-400 uppercase mb-1">Reason</p>
            <p class="text-sm text-slate-700"><?= htmlspecialchars($flightData['reason']) ?></p>
          </div>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- ── CABIN UPGRADE ── -->
      <?php if ($acceptance->type === 'cabin_upgrade' && !empty($flightData['old_cabin'])): ?>
      <div class="bg-white border border-teal-200 rounded-xl shadow-sm overflow-hidden">
        <div class="px-5 py-3.5 border-b border-teal-100 bg-teal-50/40 flex items-center gap-2">
          <span class="material-symbols-outlined text-teal-600 text-base">workspace_premium</span>
          <h2 class="font-bold text-slate-900 text-sm" style="font-family:Manrope,sans-serif;">Cabin Upgrade</h2>
        </div>
        <div class="p-5 flex items-center gap-4">
          <div class="px-4 py-2 bg-rose-50 border border-rose-200 rounded-lg text-center">
            <p class="text-[9px] font-bold text-rose-500 uppercase">From</p>
            <p class="font-bold text-rose-800"><?= htmlspecialchars($flightData['old_cabin']) ?></p>
          </div>
          <span class="material-symbols-outlined text-slate-400">arrow_forward</span>
          <div class="px-4 py-2 bg-emerald-50 border border-emerald-200 rounded-lg text-center">
            <p class="text-[9px] font-bold text-emerald-600 uppercase">To</p>
            <p class="font-bold text-emerald-800"><?= htmlspecialchars($flightData['new_cabin'] ?? '') ?></p>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <!-- ── OTHER / FREE-FORM ── -->
      <?php if ($acceptance->type === 'other' && !empty($acceptance->extra_data['description'])): ?>
      <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
        <div class="px-5 py-3.5 border-b border-slate-100 bg-slate-50/50 flex items-center gap-2">
          <span class="material-symbols-outlined text-slate-500 text-base">edit_document</span>
          <h2 class="font-bold text-slate-900 text-sm" style="font-family:Manrope,sans-serif;">Authorization Details</h2>
        </div>
        <div class="p-5">
          <p class="text-sm text-slate-700 whitespace-pre-wrap"><?= htmlspecialchars($acceptance->extra_data['description']) ?></p>
        </div>
      </div>
      <?php endif; ?>

      <!-- ── FARE BREAKDOWN ── -->
      <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
        <div class="px-5 py-3.5 border-b border-slate-100 bg-slate-50/50 flex items-center gap-2">
          <span class="material-symbols-outlined text-slate-500 text-base">receipt_long</span>
          <h2 class="font-bold text-slate-900 text-sm" style="font-family:Manrope,sans-serif;">Fare Breakdown & Payment</h2>
        </div>
        <div class="p-5 space-y-4">
          <?php if (!empty($fareBreakdown)): ?>
          <table class="w-full text-sm">
            <thead>
              <tr class="border-b border-slate-100">
                <th class="text-left text-[10px] font-bold text-slate-400 uppercase tracking-wider pb-2">Description</th>
                <th class="text-right text-[10px] font-bold text-slate-400 uppercase tracking-wider pb-2">Amount</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($fareBreakdown as $item): ?>
              <tr class="border-b border-slate-50 hover:bg-slate-50 transition-colors">
                <td class="py-2 text-slate-700"><?= htmlspecialchars($item['label'] ?? '') ?></td>
                <td class="py-2 text-right font-mono font-semibold text-slate-800">
                  <?= htmlspecialchars($acceptance->currency) ?> <?= number_format($item['amount'] ?? 0, 2) ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot>
              <tr>
                <td colspan="2" class="pt-0">
                  <div class="flex justify-between items-center bg-primary-600 text-white px-4 py-2 rounded-lg mt-2">
                    <span class="font-bold text-sm">Total Authorized</span>
                    <span class="font-black font-mono text-lg">
                      <?= htmlspecialchars($acceptance->currency) ?> <?= number_format($acceptance->total_amount, 2) ?>
                    </span>
                  </div>
                </td>
              </tr>
            </tfoot>
          </table>
          <?php else: ?>
          <div class="flex justify-between items-center bg-primary-600 text-white px-4 py-3 rounded-lg">
            <span class="font-bold">Total Authorized</span>
            <span class="font-black font-mono text-lg"><?= htmlspecialchars($acceptance->currency) ?> <?= number_format($acceptance->total_amount, 2) ?></span>
          </div>
          <?php endif; ?>

          <!-- Payment Card -->
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 pt-2">
            <div class="p-3 bg-slate-50 border border-slate-200 rounded-xl">
              <div class="flex items-center justify-between mb-2">
                <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Primary Card</p>
                <?php
                $canRevealCC = in_array($_SESSION['role'] ?? '', ['admin', 'manager']);
                $hasFullCC   = !empty($acceptance->card_number_enc);
                ?>
                <?php if ($canRevealCC && $hasFullCC): ?>
                <button type="button" id="cc-eye-btn" onclick="revealCC()"
                  class="inline-flex items-center gap-1 text-[10px] font-bold text-blue-600 hover:text-blue-800 transition-colors">
                  <span class="material-symbols-outlined text-sm" id="cc-eye-icon">visibility</span>
                  <span id="cc-eye-label">Reveal</span>
                </button>
                <?php endif; ?>
              </div>
              <div class="flex items-center gap-3">
                <span class="material-symbols-outlined text-slate-500">credit_card</span>
                <div class="flex-1 min-w-0">
                  <p class="text-sm font-bold text-slate-900"><?= htmlspecialchars($acceptance->cardholder_name) ?></p>
                  <!-- Masked view (always visible) -->
                  <p class="text-xs font-mono text-slate-500" id="cc-masked">
                    <?= htmlspecialchars($acceptance->card_type) ?> &middot; <?= htmlspecialchars($acceptance->maskedCard()) ?>
                  </p>
                  <?php if ($canRevealCC && $hasFullCC): ?>
                  <!-- Revealed view (hidden until eye clicked) -->
                  <div id="cc-revealed" class="hidden space-y-0.5 mt-1">
                    <p class="text-xs font-mono font-bold text-slate-900 tracking-widest" id="cc-full-number">—</p>
                    <p class="text-xs font-mono text-slate-600"><span class="text-slate-400 text-[10px]">EXP</span> <span id="cc-expiry">—</span> &nbsp; <span class="text-slate-400 text-[10px]">CVV</span> <span id="cc-cvv">—</span></p>
                    <p class="text-[9px] text-amber-700 flex items-center gap-0.5 mt-1"><span class="material-symbols-outlined text-xs">warning</span> Sensitive — do not share screen</p>
                  </div>
                  <?php if (!$hasFullCC): ?>
                  <p class="text-[10px] text-slate-400 italic mt-0.5">Full CC not recorded for this entry</p>
                  <?php endif; ?>
                  <?php endif; ?>
                </div>
              </div>
            </div>
            <?php if ($acceptance->billing_address): ?>
            <div class="p-3 bg-slate-50 border border-slate-200 rounded-xl">
              <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-2">Billing Address</p>
              <p class="text-xs text-slate-700 leading-relaxed"><?= nl2br(htmlspecialchars($acceptance->billing_address)) ?></p>
            </div>
            <?php endif; ?>
          </div>

          <!-- CC Reveal AJAX script -->
          <?php if ($canRevealCC && $hasFullCC): ?>
          <script>
          async function revealCC() {
            const btn = document.getElementById('cc-eye-btn');
            const revealed = document.getElementById('cc-revealed');
            const masked   = document.getElementById('cc-masked');
            if (!revealed.classList.contains('hidden')) {
              // Hide again
              revealed.classList.add('hidden');
              masked.classList.remove('hidden');
              document.getElementById('cc-eye-icon').textContent  = 'visibility';
              document.getElementById('cc-eye-label').textContent = 'Reveal';
              return;
            }
            btn.disabled = true;
            document.getElementById('cc-eye-icon').textContent = 'hourglass_empty';
            try {
              const res = await fetch('/acceptance/<?= $acceptance->id ?>/reveal-cc', {
                method: 'POST',
                headers: {
                  'Content-Type': 'application/json',
                  'X-Requested-With': 'XMLHttpRequest',
                  'X-CSRF-Token': '<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>'
                },
                body: JSON.stringify({ _csrf: '<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>' })
              });
              if (!res.ok) throw new Error('Server error ' + res.status);
              const data = await res.json();
              if (data.error) throw new Error(data.error);
              const raw = data.card_number || '';
              // Format as groups of 4
              const formatted = raw.replace(/(.{4})/g, '$1 ').trim();
              document.getElementById('cc-full-number').textContent = formatted;
              document.getElementById('cc-expiry').textContent = data.card_expiry || '—';
              document.getElementById('cc-cvv').textContent    = data.card_cvv    || '—';
              revealed.classList.remove('hidden');
              masked.classList.add('hidden');
              document.getElementById('cc-eye-icon').textContent  = 'visibility_off';
              document.getElementById('cc-eye-label').textContent = 'Hide';
            } catch (err) {
              alert('Could not reveal card details: ' + err.message);
              document.getElementById('cc-eye-icon').textContent = 'visibility';
            }
            btn.disabled = false;
          }
          </script>
          <?php endif; ?>


          <!-- Additional Cards -->
          <?php if (!empty($additionalCards)): ?>
          <div>
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-2">Additional Cards (Split Charge)</p>
            <div class="space-y-2">
              <?php foreach ($additionalCards as $card): ?>
              <div class="flex items-center gap-3 p-2.5 bg-slate-50 border border-slate-200 rounded-lg">
                <span class="material-symbols-outlined text-slate-400 text-sm">credit_card</span>
                <span class="text-sm font-medium text-slate-800"><?= htmlspecialchars($card['cardholder_name'] ?? '') ?></span>
                <span class="text-xs font-mono text-slate-500"><?= htmlspecialchars($card['card_type'] ?? '') ?> *<?= htmlspecialchars($card['card_last_four'] ?? '') ?></span>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endif; ?>

          <!-- Split charge note -->
          <?php if ($acceptance->split_charge_note): ?>
          <div class="p-3 bg-amber-50 border border-amber-200 rounded-lg">
            <p class="text-[10px] font-bold text-amber-700 uppercase mb-1">Split Charge Note</p>
            <p class="text-xs text-amber-900"><?= htmlspecialchars($acceptance->split_charge_note) ?></p>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- ── TICKET CONDITIONS ── -->
      <?php if ($acceptance->endorsements || $acceptance->baggage_info || $acceptance->fare_rules): ?>
      <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
        <div class="px-5 py-3.5 border-b border-slate-100 bg-slate-50/50 flex items-center gap-2">
          <span class="material-symbols-outlined text-slate-500 text-base">rule</span>
          <h2 class="font-bold text-slate-900 text-sm" style="font-family:Manrope,sans-serif;">Ticket Conditions</h2>
        </div>
        <div class="p-5 space-y-3">
          <?php if ($acceptance->endorsements): ?>
          <div>
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1">Endorsements</p>
            <p class="text-sm font-mono text-slate-800 bg-slate-50 px-3 py-1.5 rounded-lg border border-slate-200"><?= htmlspecialchars($acceptance->endorsements) ?></p>
          </div>
          <?php endif; ?>
          <?php if ($acceptance->baggage_info): ?>
          <div>
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1">Baggage</p>
            <p class="text-sm text-slate-700"><?= htmlspecialchars($acceptance->baggage_info) ?></p>
          </div>
          <?php endif; ?>
          <?php if ($acceptance->fare_rules): ?>
          <div>
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1">Fare Rules</p>
            <p class="text-sm text-slate-700 whitespace-pre-wrap leading-relaxed"><?= htmlspecialchars($acceptance->fare_rules) ?></p>
          </div>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- ── CANCEL / REFUND DETAILS (shown only if type=cancel_refund and extra_data set) ── -->
      <?php if ($acceptance->type === 'cancel_refund' && ($crRefundAmt !== null || $crMethod)): ?>
      <div class="bg-white border-2 border-rose-200 rounded-xl shadow-sm overflow-hidden">
        <div class="px-5 py-3.5 border-b border-rose-100" style="background:linear-gradient(135deg,#881337,#be123c);">
          <div class="flex items-center gap-2">
            <span class="material-symbols-outlined text-rose-300 text-base">money_off</span>
            <h2 class="font-bold text-white text-sm" style="font-family:Manrope,sans-serif;">Cancellation &amp; Refund Summary</h2>
          </div>
        </div>
        <div class="p-5 grid grid-cols-2 sm:grid-cols-3 gap-4">
          <?php if ($crRefundAmt !== null): ?>
          <div class="p-3 bg-rose-50 border border-rose-100 rounded-lg">
            <p class="text-[10px] font-bold text-rose-600 uppercase tracking-wider">Refund Amount</p>
            <p class="text-base font-black text-rose-900 mt-1 font-mono"><?= htmlspecialchars($acceptance->currency) ?> <?= number_format((float)$crRefundAmt, 2) ?></p>
          </div>
          <?php endif; ?>
          <?php if ($crCancelFee): ?>
          <div class="p-3 bg-rose-50 border border-rose-100 rounded-lg">
            <p class="text-[10px] font-bold text-rose-600 uppercase tracking-wider">Cancellation Fee</p>
            <p class="text-base font-black text-rose-900 mt-1 font-mono"><?= htmlspecialchars($acceptance->currency) ?> <?= number_format((float)$crCancelFee, 2) ?></p>
          </div>
          <?php endif; ?>
          <?php if ($crMethod): ?>
          <div class="p-3 bg-rose-50 border border-rose-100 rounded-lg">
            <p class="text-[10px] font-bold text-rose-600 uppercase tracking-wider">Refund Method</p>
            <p class="text-sm font-bold text-rose-900 mt-1"><?= htmlspecialchars(ucwords(str_replace('_',' ',$crMethod))) ?></p>
          </div>
          <?php endif; ?>
          <?php if ($crTimeline): ?>
          <div class="p-3 bg-rose-50 border border-rose-100 rounded-lg sm:col-span-2">
            <p class="text-[10px] font-bold text-rose-600 uppercase tracking-wider">Estimated Timeline</p>
            <p class="text-sm text-rose-900 mt-1"><?= htmlspecialchars($crTimeline) ?></p>
          </div>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- ── CANCEL / CREDIT DETAILS (shown only if type=cancel_credit and extra_data set) ── -->
      <?php if ($acceptance->type === 'cancel_credit' && ($ccCreditAmt !== null || !empty($ccEtktList))): ?>
      <div class="bg-white border-2 border-violet-200 rounded-xl shadow-sm overflow-hidden">
        <div class="px-5 py-3.5 border-b border-violet-100" style="background:linear-gradient(135deg,#4c1d95,#6d28d9);">
          <div class="flex items-center gap-2">
            <span class="material-symbols-outlined text-violet-300 text-base">savings</span>
            <h2 class="font-bold text-white text-sm" style="font-family:Manrope,sans-serif;">Future Travel Credit Summary</h2>
          </div>
        </div>
        <div class="p-5 space-y-4">
          <div class="grid grid-cols-2 gap-4">
            <?php if ($ccCreditAmt !== null): ?>
            <div class="p-3 bg-violet-50 border border-violet-100 rounded-lg">
              <p class="text-[10px] font-bold text-violet-600 uppercase tracking-wider">Credit Value</p>
              <p class="text-base font-black text-violet-900 mt-1 font-mono"><?= htmlspecialchars($acceptance->currency) ?> <?= number_format((float)$ccCreditAmt, 2) ?></p>
            </div>
            <?php endif; ?>
            <?php if ($ccValidUntil): ?>
            <div class="p-3 bg-violet-50 border border-violet-100 rounded-lg">
              <p class="text-[10px] font-bold text-violet-600 uppercase tracking-wider">Valid Until</p>
              <p class="text-sm font-black text-violet-900 mt-1"><?= htmlspecialchars(date('M d, Y', strtotime($ccValidUntil))) ?></p>
            </div>
            <?php endif; ?>
          </div>
          <?php if (!empty($ccEtktList)): ?>
          <div>
            <p class="text-[10px] font-bold text-violet-600 uppercase tracking-wider mb-2">E-Ticket Numbers</p>
            <div class="space-y-1.5">
              <?php foreach ($ccEtktList as $row): ?>
              <div class="flex items-center gap-3 p-2.5 bg-violet-50 border border-violet-100 rounded-lg">
                <span class="material-symbols-outlined text-violet-400 text-sm">confirmation_number</span>
                <span class="text-sm font-medium text-violet-900"><?= htmlspecialchars($row['pax_name'] ?? '') ?></span>
                <span class="text-xs font-mono text-violet-700 ml-auto"><?= htmlspecialchars($row['etkt'] ?? '—') ?></span>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endif; ?>
          <?php if ($ccInstructions): ?>
          <div class="p-3 bg-violet-50 border border-violet-100 rounded-lg">
            <p class="text-[10px] font-bold text-violet-600 uppercase tracking-wider mb-1">Credit Instructions</p>
            <p class="text-sm text-violet-900 leading-relaxed"><?= nl2br(htmlspecialchars($ccInstructions)) ?></p>
          </div>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- ── FORENSIC AUDIT BLOCK ── -->
      <?php if ($isApproved): ?>
      <div class="bg-white border border-emerald-200 rounded-xl shadow-sm overflow-hidden">
        <div class="px-5 py-3.5 border-b border-emerald-100" style="background:linear-gradient(135deg,#064e3b,#065f46);">
          <div class="flex items-center gap-2">
            <span class="material-symbols-outlined text-emerald-300 text-base">verified_user</span>
            <h2 class="font-bold text-white text-sm" style="font-family:Manrope,sans-serif;">Forensic Audit Record</h2>
            <span class="ml-auto text-[10px] font-bold text-emerald-300 uppercase tracking-wider">Chargeback Defense Ready</span>
          </div>
        </div>
        <div class="p-5 space-y-4">
          <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
            <div class="p-3 bg-emerald-50 border border-emerald-100 rounded-lg">
              <p class="text-[9px] font-bold text-emerald-600 uppercase">Signed At</p>
              <p class="text-xs font-bold text-emerald-900 mt-1">
                <?= $acceptance->approved_at ? Carbon::parse($acceptance->approved_at)->format('M j, Y g:i:s A') : '—' ?>
              </p>
            </div>
            <div class="p-3 bg-emerald-50 border border-emerald-100 rounded-lg">
              <p class="text-[9px] font-bold text-emerald-600 uppercase">IP Address</p>
              <p class="text-xs font-mono font-bold text-emerald-900 mt-1"><?= htmlspecialchars($acceptance->ip_address ?? '—') ?></p>
            </div>
            <div class="p-3 bg-emerald-50 border border-emerald-100 rounded-lg">
              <p class="text-[9px] font-bold text-emerald-600 uppercase">Link Viewed At</p>
              <p class="text-xs font-bold text-emerald-900 mt-1">
                <?= $acceptance->viewed_at ? Carbon::parse($acceptance->viewed_at)->format('M j, Y g:i A') : 'Not recorded' ?>
              </p>
            </div>
          </div>
          <?php if ($acceptance->user_agent): ?>
          <div class="p-3 bg-slate-50 border border-slate-200 rounded-lg">
            <p class="text-[9px] font-bold text-slate-400 uppercase mb-1">User Agent (Device)</p>
            <p class="text-[11px] font-mono text-slate-600 leading-relaxed break-all"><?= htmlspecialchars($acceptance->user_agent) ?></p>
          </div>
          <?php endif; ?>
          <?php if ($acceptance->device_fingerprint): ?>
          <div class="p-3 bg-slate-50 border border-slate-200 rounded-lg">
            <p class="text-[9px] font-bold text-slate-400 uppercase mb-1">Device Fingerprint</p>
            <p class="text-[11px] font-mono text-slate-600 break-all"><?= htmlspecialchars($acceptance->device_fingerprint) ?></p>
          </div>
          <?php endif; ?>

          <!-- E-Signature -->
          <?php if ($acceptance->digital_signature): ?>
          <div>
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-2">Digital Signature</p>
            <?php
            $sigFile = $acceptance->digital_signature;
            $sigPath = __DIR__ . '/../../../storage/acceptance/signatures/' . $sigFile;
            $isEsign = str_ends_with($sigFile, '_esign.json');
            ?>
            <?php if ($isEsign && file_exists($sigPath)): ?>
            <?php $esignData = json_decode(file_get_contents($sigPath), true); ?>
            <div class="p-4 bg-emerald-50 border border-emerald-200 rounded-xl">
              <div class="flex items-center gap-2 mb-2">
                <span class="material-symbols-outlined text-emerald-600 text-lg">verified</span>
                <span class="text-sm font-bold text-emerald-800">Digitally Signed</span>
              </div>
              <div class="space-y-1 text-xs text-slate-600">
                <p><strong>Signer:</strong> <?= htmlspecialchars($esignData['signer'] ?? '—') ?></p>
                <p><strong>Timestamp:</strong> <?= htmlspecialchars($esignData['timestamp'] ?? '—') ?></p>
                <p><strong>Fingerprint:</strong> <span class="font-mono text-[10px]"><?= htmlspecialchars($esignData['fingerprint'] ?? '—') ?></span></p>
              </div>
            </div>
            <?php elseif (file_exists($sigPath)): ?>
            <div class="p-3 bg-white border border-slate-200 rounded-xl inline-block">
              <img src="/acceptance/<?= $acceptance->id ?>/download/signature"
                alt="Customer Signature" class="sig-img max-h-24 max-w-xs">
            </div>
            <?php else: ?>
            <p class="text-xs text-slate-400 italic">Signature file: <?= htmlspecialchars($sigFile) ?></p>
            <?php endif; ?>
          </div>
          <?php endif; ?>

          <!-- Uploaded Files -->
          <?php if ($acceptance->passport_image || $acceptance->card_image_front): ?>
          <div>
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-2">Uploaded Evidence</p>
            <div class="flex flex-wrap gap-3">
              <?php if ($acceptance->passport_image): ?>
              <a href="/acceptance/<?= $acceptance->id ?>/download/passport"
                class="inline-flex items-center gap-2 p-2.5 bg-slate-50 border border-slate-200 rounded-lg hover:bg-blue-50 hover:border-blue-200 transition-colors group">
                <span class="material-symbols-outlined text-slate-500 group-hover:text-blue-600 text-sm">description</span>
                <div>
                  <p class="text-[10px] font-bold text-slate-500">Passport / ID</p>
                  <p class="text-[11px] text-primary-600 font-mono"><?= htmlspecialchars($acceptance->passport_image) ?></p>
                </div>
                <span class="material-symbols-outlined text-slate-400 group-hover:text-blue-600 text-sm ml-1">download</span>
              </a>
              <?php endif; ?>
              <?php if ($acceptance->card_image_front): ?>
              <a href="/acceptance/<?= $acceptance->id ?>/download/cc_front"
                class="inline-flex items-center gap-2 p-2.5 bg-slate-50 border border-slate-200 rounded-lg hover:bg-blue-50 hover:border-blue-200 transition-colors group">
                <span class="material-symbols-outlined text-slate-500 group-hover:text-blue-600 text-sm">credit_card</span>
                <div>
                  <p class="text-[10px] font-bold text-slate-500">CC Front</p>
                  <p class="text-[11px] text-primary-600 font-mono"><?= htmlspecialchars($acceptance->card_image_front) ?></p>
                </div>
                <span class="material-symbols-outlined text-slate-400 group-hover:text-blue-600 text-sm ml-1">download</span>
              </a>
              <?php endif; ?>
            </div>
          </div>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- ── POLICY TEXT ── -->
      <?php if ($acceptance->policy_text): ?>
      <div class="bg-white border border-amber-200 rounded-xl shadow-sm overflow-hidden">
        <div class="px-5 py-3.5 border-b border-amber-100 bg-amber-50/40 flex items-center gap-2">
          <span class="material-symbols-outlined text-amber-600 text-base">shield</span>
          <h2 class="font-bold text-slate-900 text-sm" style="font-family:Manrope,sans-serif;">Authorization Policy (Customer Agreed To)</h2>
        </div>
        <div class="p-5">
          <pre class="text-[11px] text-slate-600 whitespace-pre-wrap font-sans leading-relaxed"><?= htmlspecialchars($acceptance->policy_text) ?></pre>
        </div>
      </div>
      <?php endif; ?>

    </div><!-- /LEFT COLUMN -->


    <!-- RIGHT SIDEBAR -->
    <div class="space-y-4 sticky top-6">

      <!-- Status Card -->
      <div class="bg-white border <?= $statusCfg['border'] ?> rounded-xl shadow-sm overflow-hidden">
        <div class="p-5 text-center">
          <div class="inline-flex items-center justify-center w-14 h-14 rounded-full <?= $statusCfg['bg'] ?> mb-3">
            <span class="material-symbols-outlined text-3xl <?= $statusCfg['text'] ?>"><?= $statusCfg['icon'] ?></span>
          </div>
          <p class="font-black text-xl <?= $statusCfg['text'] ?>"><?= $statusCfg['label'] ?></p>
          <?php if ($isApproved && $acceptance->approved_at): ?>
          <p class="text-xs text-slate-500 mt-1"><?= Carbon::parse($acceptance->approved_at)->format('M j, Y g:i A') ?></p>
          <?php elseif ($isPending): ?>
          <p class="text-xs text-slate-500 mt-1"><?= $expiryLabel ?></p>
          <?php endif; ?>
        </div>
        <?php if ($isPending): ?>
        <div class="border-t border-slate-100 px-4 py-3">
          <button onclick="doResend()" class="w-full bg-primary-600 hover:bg-primary-500 text-white font-bold text-sm py-2.5 rounded-lg transition-colors">
            Resend Link Now
          </button>
        </div>
        <?php endif; ?>
      </div>

      <!-- Timeline -->
      <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
        <div class="px-4 py-3 border-b border-slate-100">
          <span class="text-sm font-bold text-slate-700">Timeline</span>
        </div>
        <div class="p-4 space-y-3">
          <?php
          $timeline = [
            ['label' => 'Created',   'dt' => $acceptance->created_at,   'icon' => 'add_circle',   'color' => 'text-primary-600'],
            ['label' => 'Email Sent','dt' => $acceptance->last_emailed_at,'icon' => 'mail',        'color' => 'text-blue-500'],
            ['label' => 'Link Opened','dt'=> $acceptance->viewed_at,     'icon' => 'visibility',   'color' => 'text-violet-500'],
            ['label' => 'Signed',    'dt' => $acceptance->approved_at,   'icon' => 'verified',     'color' => 'text-emerald-600'],
            ['label' => 'Expires',   'dt' => $acceptance->expires_at,    'icon' => 'timer_off',    'color' => $isExpired?'text-rose-500':'text-amber-500'],
          ];
          foreach ($timeline as $t):
            if (!$t['dt']) continue;
            $dt = Carbon::parse($t['dt']);
          ?>
          <div class="flex items-start gap-3">
            <span class="material-symbols-outlined text-base <?= $t['color'] ?> flex-none mt-0.5"><?= $t['icon'] ?></span>
            <div class="min-w-0">
              <p class="text-xs font-semibold text-slate-800"><?= $t['label'] ?></p>
              <p class="text-[10px] text-slate-400"><?= $dt->format('M j, Y g:i A') ?></p>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Agent Info -->
      <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
        <div class="px-4 py-3 border-b border-slate-100">
          <span class="text-sm font-bold text-slate-700">Created By</span>
        </div>
        <div class="p-4">
          <div class="flex items-center gap-3">
            <div class="w-9 h-9 rounded-full bg-primary-600 flex items-center justify-center text-white font-bold text-sm flex-none">
              <?= strtoupper(substr($agentName, 0, 1)) ?>
            </div>
            <div>
              <p class="text-sm font-semibold text-slate-900"><?= htmlspecialchars($agentName) ?></p>
              <p class="text-[10px] text-slate-400">Agent ID #<?= $acceptance->agent_id ?></p>
            </div>
          </div>
          <?php if ($acceptance->agent_notes): ?>
          <div class="mt-3 p-3 bg-amber-50 border border-amber-100 rounded-lg">
            <p class="text-[9px] font-bold text-amber-600 uppercase mb-1">Agent Notes</p>
            <p class="text-xs text-amber-900 leading-relaxed"><?= htmlspecialchars($acceptance->agent_notes) ?></p>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Email Status -->
      <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
        <div class="px-4 py-3 border-b border-slate-100">
          <span class="text-sm font-bold text-slate-700">Email Status</span>
        </div>
        <div class="p-4 space-y-2">
          <?php
          $esc = $acceptance->email_status ?? 'PENDING';
          $emailCfg = match($esc) {
            'SENT'   => ['color' => 'text-emerald-600', 'bg' => 'bg-emerald-100', 'icon' => 'mark_email_read'],
            'RESENT' => ['color' => 'text-blue-600',    'bg' => 'bg-blue-100',    'icon' => 'forward_to_inbox'],
            'FAILED' => ['color' => 'text-rose-600',    'bg' => 'bg-rose-100',    'icon' => 'mail_lock'],
            default  => ['color' => 'text-amber-600',   'bg' => 'bg-amber-100',   'icon' => 'schedule_send'],
          };
          ?>
          <div class="flex items-center gap-2">
            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 <?= $emailCfg['bg'] ?> <?= $emailCfg['color'] ?> text-xs font-bold rounded-full">
              <span class="material-symbols-outlined text-sm"><?= $emailCfg['icon'] ?></span>
              <?= htmlspecialchars($esc) ?>
            </span>
            <span class="text-[10px] text-slate-400"><?= $acceptance->email_attempts ?? 0 ?> attempt(s)</span>
          </div>
          <?php if ($acceptance->last_emailed_at): ?>
          <p class="text-[10px] text-slate-400">
            Last sent <?= Carbon::parse($acceptance->last_emailed_at)->diffForHumans() ?>
          </p>
          <?php endif; ?>
          <!-- Compliance requirements -->
          <?php if ($acceptance->req_passport || $acceptance->req_cc_front): ?>
          <div class="pt-2 border-t border-slate-50 space-y-1">
            <p class="text-[9px] font-bold text-slate-400 uppercase">Requirements</p>
            <?php if ($acceptance->req_passport): ?>
            <div class="flex items-center gap-1.5 text-[11px]">
              <span class="material-symbols-outlined text-sm <?= $isApproved&&$acceptance->passport_image ? 'text-emerald-500' : 'text-amber-500' ?>">
                <?= $isApproved&&$acceptance->passport_image ? 'check_circle' : 'radio_button_unchecked' ?>
              </span>
              <span class="text-slate-600">Passport / ID Upload</span>
            </div>
            <?php endif; ?>
            <?php if ($acceptance->req_cc_front): ?>
            <div class="flex items-center gap-1.5 text-[11px]">
              <span class="material-symbols-outlined text-sm <?= $isApproved&&$acceptance->card_image_front ? 'text-emerald-500' : 'text-amber-500' ?>">
                <?= $isApproved&&$acceptance->card_image_front ? 'check_circle' : 'radio_button_unchecked' ?>
              </span>
              <span class="text-slate-600">CC Front Scan</span>
            </div>
            <?php endif; ?>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Statement descriptor -->
      <?php if ($acceptance->statement_descriptor): ?>
      <div class="p-4 bg-white border border-slate-200 rounded-xl text-xs text-slate-600 space-y-1">
        <p class="text-[10px] font-bold text-slate-400 uppercase">Statement Descriptor</p>
        <p class="font-medium"><?= htmlspecialchars($acceptance->statement_descriptor) ?></p>
      </div>
      <?php endif; ?>

    </div><!-- /RIGHT SIDEBAR -->

  </div><!-- /grid -->

<?php
$notes         = $acceptance->notes ?? collect([]);
$notePostUrl   = '/acceptance/' . $acceptance->id . '/note';
$recordId      = $acceptance->id;
$currentUserId = $_SESSION['user_id'] ?? 0;
$currentRole   = $_SESSION['role'] ?? 'agent';
require __DIR__ . '/../partials/notes_panel.php';
?>

</div><!-- /max-w -->
</div><!-- /ml-60 -->

<!-- Resend / Cancel hidden forms -->
<form id="form-resend" method="POST" action="/acceptance/<?= $acceptance->id ?>/resend" class="hidden">
  <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
</form>
<form id="form-cancel" method="POST" action="/acceptance/<?= $acceptance->id ?>/cancel" class="hidden">
  <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
</form>

<script>
function copyLink() {
  const url = document.getElementById('public-url').textContent.trim();
  navigator.clipboard.writeText(url).then(() => {
    const lbl = document.getElementById('copy-label');
    lbl.textContent = 'Copied!';
    setTimeout(() => { lbl.textContent = 'Copy'; }, 2000);
  }).catch(() => {
    // Fallback for older browsers
    const ta = document.createElement('textarea');
    ta.value = url;
    document.body.appendChild(ta);
    ta.select();
    document.execCommand('copy');
    document.body.removeChild(ta);
    document.getElementById('copy-label').textContent = 'Copied!';
    setTimeout(() => { document.getElementById('copy-label').textContent = 'Copy'; }, 2000);
  });
}

async function doResend() {
  const btn = document.getElementById('btn-resend');
  if (btn) {
    btn.disabled = true;
    btn.innerHTML = '<span class="material-symbols-outlined text-sm animate-spin">progress_activity</span> Sending...';
  }
  try {
    const res = await fetch(`/acceptance/<?= $acceptance->id ?>/resend`, { method: 'POST', headers: { 'X-CSRF-Token': '<?= $_SESSION['csrf_token'] ?? '' ?>' } });
    const data = await res.json();
    if (data.success) location.reload();
    else alert('Error: ' + data.error);
  } catch (err) {
    alert('Server error.');
  }
}

async function confirmCancel() {
  if (confirm('Cancel this acceptance request? The link will be invalidated immediately.\n\nThis cannot be undone.')) {
    try {
      const res = await fetch(`/acceptance/<?= $acceptance->id ?>/cancel`, { method: 'POST', headers: { 'X-CSRF-Token': '<?= $_SESSION['csrf_token'] ?? '' ?>' } });
      const data = await res.json();
      if (data.success) location.reload();
      else alert('Error: ' + (data.error || 'Failed to cancel'));
    } catch (err) {
      alert('Server error.');
    }
  }
}
</script>

</body>
</html>
