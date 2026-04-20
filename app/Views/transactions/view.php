<?php
/**
 * Transaction Recorder — Detail / View Page
 * Role-aware display: agents see masked cards, admins get click-to-reveal.
 *
 * @var Transaction $txn  Fully loaded transaction with relationships
 * @var bool $isAdmin     Whether current user is admin/manager
 * @var string $userRole  Current user's role string
 */

use App\Models\Transaction;
use App\Models\User;

$txn         = $txn ?? null;
$isAdmin     = $isAdmin ?? false;
$userRole    = $userRole ?? 'agent';
$flashSuccess = $flashSuccess ?? null;
$flashError   = $flashError ?? null;
[$statusLabel, $statusClass] = $txn->statusBadge();
[$payLabel, $payClass]       = $txn->paymentBadge();
$activePage = 'transactions';

// ── Two-tier data resolution: txn->data first, acceptance fallback ──────────
$d   = $txn->data ?? [];
$acc = $txn->acceptance ?? null;   // Already eager-loaded in controller

// Flight segments
$accFD      = $acc ? ($acc->flight_data ?? []) : [];
$flights    = !empty($d['flights'])    ? $d['flights']    : ($accFD['flights']    ?? []);
$oldFlights = !empty($d['old_flights'])? $d['old_flights']: ($accFD['old_flights']?? []);
$newFlights = !empty($d['new_flights'])? $d['new_flights']: ($accFD['new_flights']?? []);

// Fare breakdown — what the customer actually authorized
$fareItems = !empty($d['fare_breakdown'])
    ? $d['fare_breakdown']
    : ($acc ? ($acc->fare_breakdown ?? []) : []);

// Type-specific overrides (from txn->data, with acceptance fallback where applicable)
$cos          = $d['class_of_service'] ?? null;
$seatNum      = $d['seat_number'] ?? null;
$oldCabin     = $d['old_cabin'] ?? ($accFD['old_cabin'] ?? null);
$newCabin     = $d['new_cabin'] ?? ($accFD['new_cabin'] ?? null);
$oldName      = $d['old_name'] ?? ($accFD['old_name'] ?? null);
$newName      = $d['new_name'] ?? ($accFD['new_name'] ?? null);
$ncReason     = $d['reason'] ?? ($accFD['reason'] ?? null);
$otherTitle   = $d['other_title'] ?? null;
$otherNotes   = $d['other_notes'] ?? null;
$endorsements = $d['endorsements'] ?? ($acc ? ($acc->endorsements ?? null) : null);

// Agent notes — txn field first, fall back to what agent wrote in acceptance
$displayAgentNotes = $txn->agent_notes ?: ($acc ? ($acc->agent_notes ?? '') : '');

// Filter out empty/corrupt flight entries (segments with no from AND no to AND no flight_no)
$filterSegs = fn($arr) => is_array($arr)
    ? array_values(array_filter($arr, fn($s) => !empty($s['from']) || !empty($s['to']) || !empty($s['flight_no'])))
    : [];
$flights    = $filterSegs($flights);
$oldFlights = $filterSegs($oldFlights);
$newFlights = $filterSegs($newFlights);

// Gate flag: do we have anything in the type-specific section to render?
$hasTypeData = !empty($flights) || !empty($oldFlights) || !empty($newFlights)
    || !empty($fareItems) || $cos || $seatNum || $oldCabin || $oldName || $otherTitle;

$CITIES_R = [
  'YYZ'=>'Toronto','YVR'=>'Vancouver','YUL'=>'Montreal','YYC'=>'Calgary',
  'LHR'=>'London','LGW'=>'Gatwick','CDG'=>'Paris','FRA'=>'Frankfurt','AMS'=>'Amsterdam',
  'MAD'=>'Madrid','FCO'=>'Rome','ZRH'=>'Zurich','IST'=>'Istanbul',
  'DXB'=>'Dubai','DOH'=>'Doha','AUH'=>'Abu Dhabi',
  'BOM'=>'Mumbai','DEL'=>'New Delhi','BLR'=>'Bangalore','MAA'=>'Chennai','HYD'=>'Hyderabad',
  'JFK'=>'New York JFK','EWR'=>'Newark','LAX'=>'Los Angeles','SFO'=>'San Francisco',
  'ORD'=>'Chicago','MIA'=>'Miami','DFW'=>'Dallas','SEA'=>'Seattle','BOS'=>'Boston',
  'ATL'=>'Atlanta','DEN'=>'Denver','NRT'=>'Tokyo Narita','HND'=>'Tokyo Haneda',
  'ICN'=>'Seoul','SIN'=>'Singapore','HKG'=>'Hong Kong','BKK'=>'Bangkok',
  'SYD'=>'Sydney','MEL'=>'Melbourne',
];
$AIRLINES_R = [
  'AC'=>'Air Canada','WS'=>'WestJet','AA'=>'American Airlines','DL'=>'Delta Air Lines','UA'=>'United Airlines',
  'BA'=>'British Airways','LH'=>'Lufthansa','AF'=>'Air France','KL'=>'KLM Royal Dutch','EK'=>'Emirates',
  'QR'=>'Qatar Airways','SQ'=>'Singapore Airlines','CX'=>'Cathay Pacific','JL'=>'Japan Airlines',
  'NH'=>'All Nippon Airways','TK'=>'Turkish Airlines','EY'=>'Etihad Airways','LX'=>'Swiss International','OS'=>'Austrian Airlines',
  'AI'=>'Air India','VS'=>'Virgin Atlantic','KE'=>'Korean Air','TG'=>'Thai Airways',
  'MH'=>'Malaysia Airlines','B6'=>'JetBlue Airways','AS'=>'Alaska Airlines',
  'F9'=>'Frontier Airlines','NK'=>'Spirit Airlines','WN'=>'Southwest Airlines','AM'=>'Aeromexico',
  'CM'=>'Copa Airlines','AV'=>'Avianca','LA'=>'LATAM Airlines','QF'=>'Qantas Airways','NZ'=>'Air New Zealand',
  'GA'=>'Garuda Indonesia','PR'=>'Philippine Airlines','UL'=>'SriLankan Airlines',
  'HA'=>'Hawaiian Airlines','G4'=>'Allegiant Air','AD'=>'Azul Brazilian Airlines',
  'TP'=>'TAP Air Portugal','SV'=>'Saudia','MS'=>'EgyptAir','ET'=>'Ethiopian Airlines','AT'=>'Royal Air Maroc',
];

$canApprove = in_array($userRole, [User::ROLE_ADMIN, User::ROLE_MANAGER, User::ROLE_SUPERVISOR])
              && $txn->status === Transaction::STATUS_PENDING;
$canVoid    = in_array($userRole, [User::ROLE_ADMIN, User::ROLE_MANAGER])
              && !$txn->isVoided();
$isAdminOnly = in_array($userRole, [User::ROLE_ADMIN, User::ROLE_MANAGER]);

// Dispute & gateway display data
[$disputeLabel, $disputeClass] = $txn->disputeBadge();
[$gatewayLabel, $gatewayClass] = $txn->gatewayBadge();
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<title>Transaction #<?= $txn->id ?> - Base Fare CRM</title>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
<script src="https://cdn.tailwindcss.com?plugins=forms"></script>
<script>
tailwind.config={darkMode:"class",theme:{extend:{colors:{primary:"#163274","primary-container":"#314a8d",background:"#f8f9fa",surface:"#f8f9fa","surface-container":"#edeeef",
"on-surface":"#191c1d","on-surface-variant":"#434653"},fontFamily:{headline:["Manrope"],body:["Inter"]}}}}
</script>
</head>
<body class="bg-background font-body text-on-surface antialiased min-h-screen">

<?php if ($isAdmin): ?>
<?php $activePage = 'transactions'; require __DIR__ . '/../partials/admin_sidebar.php'; ?>
<?php else: ?>
<?php $activePage = 'transactions'; require __DIR__ . '/../partials/agent_sidebar.php'; ?>
<?php endif; ?>

<main class="ml-60 pt-6 pb-20 px-8">

  <!-- Header -->
  <div class="flex items-center justify-between mb-6">
    <div>
      <div class="flex items-center gap-3">
        <h1 class="text-2xl font-headline font-extrabold text-primary tracking-tight">
          Transaction #<?= $txn->id ?>
        </h1>
        <span class="inline-block px-3 py-1 text-xs font-bold rounded-full <?= $statusClass ?>"><?= $statusLabel ?></span>
        <span class="inline-block px-2 py-0.5 text-xs font-bold rounded-full bg-slate-100 text-slate-700"><?= $txn->typeLabel() ?></span>
      </div>
      <p class="text-sm text-on-surface-variant mt-0.5">
        Recorded by <?= htmlspecialchars($txn->agent->name ?? '—') ?> on <?= date('M d, Y g:i A', strtotime($txn->created_at)) ?>
      </p>
    </div>
  <div class="flex items-center gap-2">
      <?php if ($canApprove): ?>
      <form method="POST" action="/transactions/<?= $txn->id ?>/approve" onsubmit="return confirm('Approve this transaction? It will become locked.')";>
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
        <button type="submit"
          class="inline-flex items-center gap-1 px-4 py-2 text-sm font-semibold bg-emerald-600 text-white rounded-lg hover:bg-emerald-500 transition-colors shadow-sm">
          <span class="material-symbols-outlined text-sm">check_circle</span> Approve
        </button>
      </form>
      <?php endif; ?>
      <?php if ($canVoid): ?>
      <button type="button" onclick="openVoidModal()"
        class="inline-flex items-center gap-1 px-4 py-2 text-sm font-semibold bg-red-600 text-white rounded-lg hover:bg-red-500 transition-colors shadow-sm">
        <span class="material-symbols-outlined text-sm">block</span> Void
      </button>
      <?php endif; ?>
      <?php if ($txn->isEditable($isAdmin)): ?>
      <a href="/transactions/<?= $txn->id ?>/edit" class="inline-flex items-center gap-1 px-4 py-2 text-sm font-semibold text-primary border border-primary rounded-lg hover:bg-primary/5 transition-colors">
        <span class="material-symbols-outlined text-sm">edit</span> Edit
      </a>
      <?php endif; ?>
      <a href="/transactions" class="inline-flex items-center gap-1 text-sm font-semibold text-on-surface-variant hover:text-primary transition-colors">
        <span class="material-symbols-outlined text-sm">arrow_back</span> Back
      </a>
    </div>
  </div>

  <!-- Flash Messages -->
  <?php if ($flashSuccess): ?>
  <div class="mb-4 px-4 py-3 bg-emerald-50 border border-emerald-200 rounded-xl text-sm font-semibold text-emerald-700 flex items-center gap-2">
    <span class="material-symbols-outlined text-base">check_circle</span><?= htmlspecialchars($flashSuccess) ?>
  </div>
  <?php endif; ?>
  <?php if ($flashError): ?>
  <div class="mb-4 px-4 py-3 bg-red-50 border border-red-200 rounded-xl text-sm font-semibold text-red-700 flex items-center gap-2">
    <span class="material-symbols-outlined text-base">error</span><?= htmlspecialchars($flashError) ?>
  </div>
  <?php endif; ?>

  <!-- Void Warning Banner -->
  <?php if ($txn->isVoided()): ?>
  <div class="mb-4 px-4 py-3 bg-red-50 border border-red-300 rounded-xl text-sm text-red-800">
    <p class="font-bold flex items-center gap-1"><span class="material-symbols-outlined text-base">block</span> VOIDED</p>
    <p class="mt-1"><?= htmlspecialchars($txn->void_reason) ?></p>
    <p class="text-xs mt-1 text-red-600">
      Voided by <?= htmlspecialchars($txn->voidedByUser->name ?? '—') ?> on <?= date('M d, Y g:i A', strtotime($txn->voided_at)) ?>
    </p>
  </div>
  <?php endif; ?>

  <!-- Linked Acceptance -->
  <?php if ($txn->acceptance): ?>
  <div class="mb-4 px-4 py-3 bg-blue-50 border border-blue-200 rounded-xl text-sm text-blue-800 flex items-center gap-2">
    <span class="material-symbols-outlined text-base text-blue-600">link</span>
    Linked to <a href="/acceptance/<?= $txn->acceptance_id ?>" class="font-bold underline hover:text-blue-600">Acceptance #<?= $txn->acceptance_id ?></a>
    — <?= htmlspecialchars($txn->acceptance->customer_name ?? '') ?> (<?= $txn->acceptance->status ?? '' ?>)
  </div>
  <?php endif; ?>

  <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

    <!-- ── LEFT COLUMN (2/3) ─────────────────────────────────────── -->
    <div class="lg:col-span-2 space-y-6">

      <!-- Customer & Booking -->
      <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100 bg-slate-50/50">
          <h2 class="font-bold text-slate-900" style="font-family:Manrope">
            <span class="material-symbols-outlined text-base align-text-bottom mr-1">person</span>
            Customer & Booking
          </h2>
        </div>
        <div class="p-6 grid grid-cols-2 sm:grid-cols-3 gap-4 text-sm">
          <div><p class="text-[10px] font-bold text-slate-400 uppercase">Name</p><p class="font-semibold"><?= htmlspecialchars($txn->customer_name) ?></p></div>
          <div><p class="text-[10px] font-bold text-slate-400 uppercase">Email</p><p class="text-slate-700"><?= htmlspecialchars($txn->customer_email) ?></p></div>
          <div><p class="text-[10px] font-bold text-slate-400 uppercase">Phone</p><p class="text-slate-700"><?= htmlspecialchars($txn->customer_phone ?: '—') ?></p></div>
          <div><p class="text-[10px] font-bold text-slate-400 uppercase">PNR</p><p class="font-mono font-bold tracking-wider text-primary"><?= htmlspecialchars($txn->pnr) ?></p></div>
          <div><p class="text-[10px] font-bold text-slate-400 uppercase">Airline</p><p class="text-slate-700"><?= htmlspecialchars($txn->airline ?: '—') ?></p></div>
          <div><p class="text-[10px] font-bold text-slate-400 uppercase">Order ID</p><p class="text-slate-700 font-mono text-xs"><?= htmlspecialchars($txn->order_id ?: '—') ?></p></div>
          <?php if ($txn->travel_date): ?>
          <div><p class="text-[10px] font-bold text-slate-400 uppercase">Travel Date</p><p class="text-slate-700"><?= date('M d, Y', strtotime($txn->travel_date)) ?></p></div>
          <?php endif; ?>
          <?php if ($txn->return_date): ?>
          <div><p class="text-[10px] font-bold text-slate-400 uppercase">Return</p><p class="text-slate-700"><?= date('M d, Y', strtotime($txn->return_date)) ?></p></div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Passengers -->
      <?php if ($txn->passengers->count()): ?>
      <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100 bg-slate-50/50">
          <h2 class="font-bold text-slate-900" style="font-family:Manrope">
            <span class="material-symbols-outlined text-base align-text-bottom mr-1">groups</span>
            Passengers (<?= $txn->passengers->count() ?>)
          </h2>
        </div>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead><tr class="border-b border-slate-100">
              <th class="px-6 py-2 text-left text-[10px] font-bold text-slate-500 uppercase">#</th>
              <th class="px-4 py-2 text-left text-[10px] font-bold text-slate-500 uppercase">Name</th>
              <th class="px-4 py-2 text-left text-[10px] font-bold text-slate-500 uppercase">Type</th>
              <th class="px-4 py-2 text-left text-[10px] font-bold text-slate-500 uppercase">DOB</th>
              <th class="px-4 py-2 text-left text-[10px] font-bold text-slate-500 uppercase">Ticket #</th>
            </tr></thead>
            <tbody class="divide-y divide-slate-50">
              <?php foreach ($txn->passengers as $i => $pax): ?>
              <tr>
                <td class="px-6 py-2 text-xs text-slate-400"><?= $i + 1 ?></td>
                <td class="px-4 py-2 font-semibold"><?= htmlspecialchars($pax->fullName()) ?></td>
                <td class="px-4 py-2"><span class="inline-block px-2 py-0.5 text-[10px] font-bold rounded bg-slate-100 text-slate-600"><?= $pax->paxLabel() ?></span></td>
                <td class="px-4 py-2 text-xs text-slate-500"><?= $pax->dob ? date('M d, Y', strtotime($pax->dob)) : '—' ?></td>
                <td class="px-4 py-2 font-mono text-xs"><?= htmlspecialchars($pax->ticket_number ?: '—') ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php endif; ?>

      <!-- Payment Cards -->
      <?php if ($txn->cards->count()): ?>
      <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100 bg-slate-50/50">
          <h2 class="font-bold text-slate-900" style="font-family:Manrope">
            <span class="material-symbols-outlined text-base align-text-bottom mr-1">credit_card</span>
            Payment Cards (<?= $txn->cards->count() ?>)
          </h2>
        </div>
        <div class="p-6 space-y-3">
          <?php foreach ($txn->cards as $card): ?>
          <div class="flex items-center justify-between p-3 bg-slate-50 border border-slate-200 rounded-lg" id="card-row-<?= $card->id ?>">
            <div class="flex items-center gap-3">
              <div>
                <span class="text-xs font-bold text-slate-500"><?= $card->card_type ?></span>
                <?php if ($card->is_primary): ?><span class="text-[9px] font-bold text-blue-600 ml-1">PRIMARY</span><?php endif; ?>
                <p class="font-mono font-bold tracking-wider text-sm mt-0.5" id="card-num-<?= $card->id ?>"><?= $card->maskedNumber() ?></p>
                <p class="text-[10px] text-slate-500" id="card-info-<?= $card->id ?>"><?= htmlspecialchars($card->holder_name) ?> · Exp <?= $card->expiryFormatted() ?></p>
              </div>
            </div>
            <div class="text-right flex items-center gap-2">
              <p class="font-mono font-bold text-sm"><?= $txn->currency ?> <?= number_format($card->amount, 2) ?></p>
              <?php if ($isAdmin): ?>
              <button type="button" onclick="toggleCardReveal(<?= $card->id ?>)" id="eye-btn-<?= $card->id ?>"
                class="p-1.5 rounded-lg bg-amber-50 border border-amber-200 text-amber-600 hover:bg-amber-100 transition-colors"
                title="Reveal / hide full card details">
                <span class="material-symbols-outlined text-sm" id="eye-icon-<?= $card->id ?>">visibility</span>
              </button>
              <?php endif; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- Acceptance Payment Card Fallback (shows when no TransactionCard rows but acceptance has card data) -->
      <?php if ($txn->cards->count() === 0 && $acc && $acc->card_last_four): ?>
      <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100 bg-slate-50/50 flex items-center gap-2 justify-between">
          <h2 class="font-bold text-slate-900 flex items-center gap-1" style="font-family:Manrope">
            <span class="material-symbols-outlined text-base align-text-bottom mr-1">credit_card</span>
            Payment Authorization
          </h2>
          <a href="/acceptance/<?= $acc->id ?>" class="text-[10px] text-blue-600 font-semibold hover:underline">From Acceptance #<?= $acc->id ?></a>
        </div>
        <div class="p-6 space-y-3">
          <!-- Primary Card — masked always; admins can go to Acceptance to reveal encrypted full details -->
          <div class="flex items-center justify-between p-3 bg-slate-50 border border-slate-200 rounded-lg">
            <div>
              <span class="text-xs font-bold text-slate-500"><?= htmlspecialchars($acc->card_type ?: 'Card') ?></span>
              <span class="text-[9px] font-bold text-blue-600 ml-1">PRIMARY</span>
              <p class="font-mono font-bold tracking-wider text-sm mt-0.5">**** **** **** <?= htmlspecialchars($acc->card_last_four) ?></p>
              <p class="text-[10px] text-slate-500">
                <?= htmlspecialchars($acc->cardholder_name ?? '') ?>
                <?= $acc->billing_address ? ' &middot; ' . htmlspecialchars($acc->billing_address) : '' ?>
              </p>
            </div>
            <div class="text-right">
              <p class="font-mono font-bold text-sm"><?= $txn->currency ?> <?= number_format($txn->total_amount, 2) ?></p>
              <?php if ($isAdmin): ?>
              <a href="/acceptance/<?= $acc->id ?>" class="text-[10px] text-amber-600 hover:underline">Reveal full card →</a>
              <?php endif; ?>
            </div>
          </div>
          <?php if ($acc->split_charge_note): ?>
          <p class="text-xs text-amber-800 bg-amber-50 border border-amber-200 rounded px-3 py-2"><?= htmlspecialchars($acc->split_charge_note) ?></p>
          <?php endif; ?>
          <!-- Additional Cards -->
          <?php foreach (($acc->additional_cards ?? []) as $ac): ?>
          <div class="flex items-center justify-between p-3 bg-slate-50 border border-slate-200 rounded-lg">
            <div>
              <span class="text-xs font-bold text-slate-500"><?= htmlspecialchars($ac['card_type'] ?? 'Card') ?></span>
              <p class="font-mono font-bold tracking-wider text-sm mt-0.5">**** **** **** <?= htmlspecialchars($ac['card_last_four'] ?? '????') ?></p>
              <p class="text-[10px] text-slate-500"><?= htmlspecialchars($ac['cardholder_name'] ?? '') ?></p>
            </div>
          </div>
          <?php endforeach; ?>
          <p class="text-[10px] text-slate-400 mt-1 italic">
            Full card details are secured in the Acceptance record.
            <?php if ($isAdmin): ?>
            <a href="/acceptance/<?= $acc->id ?>" class="text-blue-500 underline">View Acceptance #<?= $acc->id ?></a> to retrieve.
            <?php else: ?>
            Contact an admin to retrieve full card details.
            <?php endif; ?>
          </p>
        </div>
      </div>
      <?php endif; ?>

      <!-- Type-Specific Data (Flight Info, Fare Breakdown, etc.) -->
      <!-- Variables resolved above with acceptance fallback — rendered if any data exists -->
      <?php if ($hasTypeData): ?>
      <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100 bg-slate-50/50 flex items-center gap-2">
          <span class="material-symbols-outlined text-base text-primary">flight</span>
          <h2 class="font-bold text-slate-900" style="font-family:Manrope"><?= $txn->typeLabel() ?> Details</h2>
        </div>
        <div class="p-6 space-y-5">

          <?php if ($cos || $seatNum): ?>
          <div class="flex flex-wrap gap-4">
            <?php if ($cos): ?>
            <div class="px-3 py-2 bg-blue-50 border border-blue-200 rounded-lg">
              <p class="text-[9px] font-bold text-blue-500 uppercase tracking-wider">Class of Service</p>
              <p class="font-semibold text-blue-900 text-sm mt-0.5"><?= htmlspecialchars($cos) ?></p>
            </div>
            <?php endif; ?>
            <?php if ($seatNum): ?>
            <div class="px-3 py-2 bg-violet-50 border border-violet-200 rounded-lg">
              <p class="text-[9px] font-bold text-violet-500 uppercase tracking-wider">Seat Number</p>
              <p class="font-semibold text-violet-900 text-sm mt-0.5 font-mono"><?= htmlspecialchars($seatNum) ?></p>
            </div>
            <?php endif; ?>
          </div>
          <?php endif; ?>

          <?php if ($oldCabin || $newCabin): ?>
          <div class="flex gap-3 items-center">
            <div class="px-3 py-2 bg-slate-100 rounded-lg text-sm font-semibold text-slate-600"><?= htmlspecialchars($oldCabin ?: '—') ?></div>
            <span class="material-symbols-outlined text-primary text-base">arrow_forward</span>
            <div class="px-3 py-2 bg-emerald-50 border border-emerald-200 rounded-lg text-sm font-bold text-emerald-700"><?= htmlspecialchars($newCabin ?: '—') ?></div>
          </div>
          <?php endif; ?>

          <?php if ($oldName || $newName): ?>
          <div class="space-y-2">
            <p class="text-[10px] font-bold text-slate-400 uppercase">Name Correction</p>
            <div class="flex gap-3 items-center flex-wrap">
              <div class="px-3 py-2 bg-red-50 border border-red-200 rounded-lg font-mono text-sm text-red-700 line-through"><?= htmlspecialchars($oldName ?: '—') ?></div>
              <span class="material-symbols-outlined text-primary text-base">arrow_forward</span>
              <div class="px-3 py-2 bg-emerald-50 border border-emerald-200 rounded-lg font-mono text-sm font-bold text-emerald-800"><?= htmlspecialchars($newName ?: '—') ?></div>
            </div>
            <?php if ($ncReason): ?>
            <p class="text-xs text-slate-500 mt-1"><span class="font-bold">Reason:</span> <?= htmlspecialchars($ncReason) ?></p>
            <?php endif; ?>
          </div>
          <?php endif; ?>

          <?php if ($otherTitle || $otherNotes): ?>
          <div>
            <?php if ($otherTitle): ?><p class="font-bold text-slate-700 text-sm mb-1"><?= htmlspecialchars($otherTitle) ?></p><?php endif; ?>
            <?php if ($otherNotes): ?><p class="text-sm text-slate-600 whitespace-pre-line"><?= htmlspecialchars($otherNotes) ?></p><?php endif; ?>
          </div>
          <?php endif; ?>

          <?php if (!empty($oldFlights)): ?>
          <div>
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-2">Original Flights</p>
            <div class="overflow-x-auto"><table class="w-full text-xs"><thead><tr class="border-b border-slate-100">
              <th class="py-2 pr-3 text-left font-bold text-slate-500 uppercase text-[10px]">Flight</th>
              <th class="py-2 pr-3 text-left font-bold text-slate-500 uppercase text-[10px]">Route</th>
              <th class="py-2 pr-3 text-left font-bold text-slate-500 uppercase text-[10px]">Date</th>
              <th class="py-2 pr-3 text-left font-bold text-slate-500 uppercase text-[10px]">Dep</th>
              <th class="py-2 text-left font-bold text-slate-500 uppercase text-[10px]">Arr</th>
            </tr></thead><tbody class="divide-y divide-slate-50">
              <?php foreach ($oldFlights as $seg): ?>
              <tr class="hover:bg-slate-50">
                <td class="py-2 pr-3 font-mono font-bold text-primary">
                  <?php if (!empty($seg['airline_iata'])): ?><img src="https://www.gstatic.com/flights/airline_logos/35px/<?= htmlspecialchars($seg['airline_iata']) ?>.png" class="inline w-5 h-5 mr-1 align-middle" onerror="this.style.display='none'"><?php endif; ?>
                  <?= htmlspecialchars($seg['flight_no'] ?? '—') ?>
                </td>
                <td class="py-2 pr-3 font-bold text-slate-700"><?= htmlspecialchars($seg['from'] ?? '') ?> → <?= htmlspecialchars($seg['to'] ?? '') ?></td>
                <td class="py-2 pr-3 text-slate-500"><?= htmlspecialchars($seg['date'] ?? '') ?></td>
                <td class="py-2 pr-3 text-slate-500"><?= htmlspecialchars($seg['dep_time'] ?? '') ?></td>
                <td class="py-2 text-slate-500"><?= htmlspecialchars($seg['arr_time'] ?? '') ?><?= !empty($seg['arr_next_day']) ? ' <span class="text-amber-600 font-bold">+1</span>' : '' ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody></table></div>
          </div>
          <?php endif; ?>

          <?php if (!empty($newFlights)): ?>
          <div>
            <p class="text-[10px] font-bold text-emerald-600 uppercase tracking-wider mb-2">New Flights (After Change)</p>
            <div class="overflow-x-auto"><table class="w-full text-xs"><thead><tr class="border-b border-emerald-100">
              <th class="py-2 pr-3 text-left font-bold text-slate-500 uppercase text-[10px]">Flight</th>
              <th class="py-2 pr-3 text-left font-bold text-slate-500 uppercase text-[10px]">Route</th>
              <th class="py-2 pr-3 text-left font-bold text-slate-500 uppercase text-[10px]">Date</th>
              <th class="py-2 pr-3 text-left font-bold text-slate-500 uppercase text-[10px]">Dep</th>
              <th class="py-2 text-left font-bold text-slate-500 uppercase text-[10px]">Arr</th>
            </tr></thead><tbody class="divide-y divide-emerald-50">
              <?php foreach ($newFlights as $seg): ?>
              <tr class="hover:bg-emerald-50/50">
                <td class="py-2 pr-3 font-mono font-bold text-primary">
                  <?php if (!empty($seg['airline_iata'])): ?><img src="https://www.gstatic.com/flights/airline_logos/35px/<?= htmlspecialchars($seg['airline_iata']) ?>.png" class="inline w-5 h-5 mr-1 align-middle" onerror="this.style.display='none'"><?php endif; ?>
                  <?= htmlspecialchars($seg['flight_no'] ?? '—') ?>
                </td>
                <td class="py-2 pr-3 font-bold text-slate-700"><?= htmlspecialchars($seg['from'] ?? '') ?> → <?= htmlspecialchars($seg['to'] ?? '') ?></td>
                <td class="py-2 pr-3 text-slate-500"><?= htmlspecialchars($seg['date'] ?? '') ?></td>
                <td class="py-2 pr-3 text-slate-500"><?= htmlspecialchars($seg['dep_time'] ?? '') ?></td>
                <td class="py-2 text-slate-500"><?= htmlspecialchars($seg['arr_time'] ?? '') ?><?= !empty($seg['arr_next_day']) ? ' <span class="text-amber-600 font-bold">+1</span>' : '' ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody></table></div>
          </div>
          <?php endif; ?>

          <?php if (!empty($flights)):
            // Rich flight segment cards
          ?>
          <div>
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-3">Flight Itinerary</p>
            <div class="space-y-2">
            <?php foreach ($flights as $idx => $seg):
              $iata   = strtoupper($seg['airline_iata'] ?? '');
              $aName  = $AIRLINES_R[$iata] ?? '';
              $from   = strtoupper($seg['from'] ?? '');
              $to     = strtoupper($seg['to'] ?? '');
              $fCity  = $CITIES_R[$from] ?? '';
              $tCity  = $CITIES_R[$to] ?? '';
              $logo35 = $iata ? "https://www.gstatic.com/flights/airline_logos/35px/{$iata}.png" : '';
              $nd     = !empty($seg['arr_next_day']);
            ?>
            <div class="flex border border-slate-200 rounded-xl overflow-hidden">
              <!-- Airline Bar -->
              <div class="w-16 flex-none flex flex-col items-center justify-center py-3 gap-1" style="background:#1a3a6b;">
                <?php if ($logo35): ?>
                <img src="<?= htmlspecialchars($logo35) ?>" alt="<?= htmlspecialchars($iata) ?>"
                  class="w-7 h-7 object-contain" onerror="this.style.display='none'">
                <?php endif; ?>
                <span class="text-[10px] font-black text-white"><?= htmlspecialchars($iata) ?></span>
                <?php if ($aName): ?><span class="text-[8px] text-blue-200 text-center leading-tight px-1"><?= htmlspecialchars($aName) ?></span><?php endif; ?>
              </div>
              <!-- Segment Body -->
              <div class="flex-1 grid grid-cols-3 gap-2 items-center px-4 py-3">
                <!-- Departure -->
                <div>
                  <div class="text-xl font-black text-slate-900 leading-none"><?= htmlspecialchars($seg['dep_time'] ?? '—') ?></div>
                  <div class="text-sm font-bold text-primary"><?= htmlspecialchars($from) ?></div>
                  <?php if ($fCity): ?><div class="text-[10px] text-slate-400"><?= htmlspecialchars($fCity) ?></div><?php endif; ?>
                </div>
                <!-- Arrow + date -->
                <div class="text-center">
                  <div class="text-slate-300 text-lg font-bold">→</div>
                  <div class="text-[10px] text-slate-500"><?= htmlspecialchars($seg['date'] ?? '') ?></div>
                  <?php if (!empty($seg['cabin_class'])): ?>
                  <span class="inline-block text-[9px] font-bold px-1.5 py-0.5 rounded bg-slate-100 text-slate-500 font-mono mt-0.5"><?= htmlspecialchars($seg['cabin_class']) ?></span>
                  <?php endif; ?>
                  <?php if (!empty($seg['flight_no'])): ?>
                  <div class="text-[10px] font-mono font-bold text-slate-600 mt-0.5"><?= htmlspecialchars($seg['flight_no']) ?></div>
                  <?php endif; ?>
                </div>
                <!-- Arrival -->
                <div class="text-right">
                  <div class="text-xl font-black text-slate-900 leading-none">
                    <?= htmlspecialchars($seg['arr_time'] ?? '—') ?>
                    <?php if ($nd): ?><span class="text-[10px] font-bold text-amber-600 align-super">+1</span><?php endif; ?>
                  </div>
                  <div class="text-sm font-bold text-primary"><?= htmlspecialchars($to) ?></div>
                  <?php if ($tCity): ?><div class="text-[10px] text-slate-400"><?= htmlspecialchars($tCity) ?></div><?php endif; ?>
                </div>
              </div>
            </div>
            <?php if ($idx < count($flights) - 1): ?>
            <div class="text-center py-1 text-[10px] font-bold text-amber-700 bg-amber-50 border border-amber-200 rounded-lg">⏱ Layover in <?= htmlspecialchars($CITIES_R[$to] ?? $to) ?></div>
            <?php endif; ?>
            <?php endforeach; ?>
            </div>
          </div>
          <?php endif; ?>

          <?php if (!empty($fareItems)): ?>
          <div>
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-2">Fare Breakdown</p>
            <table class="w-full text-sm">
              <tbody class="divide-y divide-slate-100">
                <?php foreach ($fareItems as $fi): ?>
                <tr>
                  <td class="py-1.5 text-slate-600"><?= htmlspecialchars($fi['label'] ?? '') ?></td>
                  <td class="py-1.5 text-right font-mono font-semibold text-slate-800"><?= $txn->currency ?> <?= number_format((float)($fi['amount'] ?? 0), 2) ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
              <tfoot>
                <tr class="border-t-2 border-slate-300">
                  <td class="pt-2 font-bold text-slate-800">Total</td>
                  <td class="pt-2 text-right font-mono font-bold text-emerald-700"><?= $txn->currency ?> <?= number_format($txn->total_amount, 2) ?></td>
                </tr>
              </tfoot>
            </table>
          </div>
          <?php endif; ?>

          <?php if ($endorsements): ?>
          <div>
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1">Endorsements</p>
            <p class="text-xs font-mono font-bold text-red-700"><?= htmlspecialchars($endorsements) ?></p>
          </div>
          <?php endif; ?>

        </div>
      </div>
      <?php endif; ?>

      <!-- Agent Notes / Transaction Summary (txn->agent_notes OR acceptance->agent_notes) -->
      <?php if ($displayAgentNotes): ?>
      <div class="border-2 border-amber-300 rounded-xl overflow-hidden" style="background:linear-gradient(135deg,#fffbeb,#fef9ed);">
        <div class="px-5 py-3 flex items-center gap-2" style="background:#92400e;">
          <span class="material-symbols-outlined text-amber-200 text-base">sticky_note_2</span>
          <span class="font-extrabold text-white text-sm" style="font-family:Manrope">Transaction Summary / Agent Notes</span>
          <span class="ml-auto text-[10px] text-amber-200 font-semibold uppercase tracking-wider">Internal — not shared with customer</span>
        </div>
        <div class="px-5 py-4 text-sm text-amber-900 whitespace-pre-line leading-relaxed"><?= nl2br(htmlspecialchars($displayAgentNotes)) ?></div>
      </div>
      <?php endif; ?>
    </div>

    <!-- ── RIGHT COLUMN (1/3) ────────────────────────────────────── -->
    <div class="space-y-6">

      <!-- Financial Summary -->
      <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100 bg-slate-50/50">
          <h2 class="font-bold text-slate-900" style="font-family:Manrope">
            <span class="material-symbols-outlined text-base align-text-bottom mr-1">payments</span> Financials
          </h2>
        </div>
        <div class="p-6 space-y-0">

          <?php if (!empty($fareItems)): ?>
          <!-- Charge Description / Fare Breakdown -->
          <div class="mb-4">
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-2">Charge Description</p>
            <div class="space-y-1">
              <?php foreach ($fareItems as $fi): ?>
              <div class="flex justify-between items-baseline text-sm">
                <span class="text-slate-600"><?= htmlspecialchars($fi['label'] ?? '') ?></span>
                <span class="font-mono font-semibold text-slate-800"><?= $txn->currency ?> <?= number_format((float)($fi['amount'] ?? 0), 2) ?></span>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="border-t-2 border-slate-800 pt-2 mb-4">
            <div class="flex justify-between items-baseline">
              <span class="text-sm font-bold text-slate-800">Total Charged</span>
              <span class="font-mono font-bold text-lg text-slate-900"><?= $txn->currency ?> <?= number_format($txn->total_amount, 2) ?></span>
            </div>
          </div>
          <?php else: ?>
          <div class="flex justify-between items-baseline mb-4">
            <span class="text-xs text-slate-500">Total Charged</span>
            <span class="font-mono font-bold text-lg"><?= $txn->currency ?> <?= number_format($txn->total_amount, 2) ?></span>
          </div>
          <?php endif; ?>

          <div class="border-t border-slate-100 pt-3 space-y-2">
            <div class="flex justify-between items-baseline">
              <span class="text-xs text-slate-500">Cost Amount</span>
              <?php if ($txn->cost_amount > 0): ?>
              <span class="font-mono text-sm text-slate-600"><?= $txn->currency ?> <?= number_format($txn->cost_amount, 2) ?></span>
              <?php else: ?>
              <span class="text-xs text-slate-400 italic">Not set
                <?php if ($txn->isEditable($isAdmin)): ?>
                <a href="/transactions/<?= $txn->id ?>/edit" class="text-blue-500 not-italic hover:underline">(edit)</a>
                <?php endif; ?>
              </span>
              <?php endif; ?>
            </div>
            <div class="flex justify-between items-baseline">
              <span class="text-xs font-bold text-slate-700">Profit / MCO</span>
              <span class="font-mono font-bold text-lg <?= $txn->profit_mco >= 0 ? 'text-emerald-600' : 'text-red-600' ?>">
                <?= $txn->formattedMco() ?>
              </span>
            </div>
          </div>
          <div class="border-t border-slate-100 mt-3 pt-3 space-y-2">
            <div class="flex justify-between items-baseline">
              <span class="text-xs text-slate-500">Payment Method</span>
              <span class="text-xs font-semibold"><?= $txn->paymentMethodLabel() ?></span>
            </div>
            <div class="flex justify-between items-baseline">
              <span class="text-xs text-slate-500">Payment Status</span>
              <span class="inline-block px-2 py-0.5 text-[10px] font-bold rounded-full <?= $payClass ?>"><?= $payLabel ?></span>
            </div>
          </div>
        </div>
      </div>


      <!-- Proof of Sale Document -->
      <?php if (!empty($txn->proof_of_sale_path)): ?>
      <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100 bg-slate-50/50">
          <h2 class="font-bold text-slate-900" style="font-family:Manrope">
            <span class="material-symbols-outlined text-base align-text-bottom mr-1">receipt_long</span> Proof of Sale
          </h2>
        </div>
        <div class="p-6 text-center">
          <?php if ($isAdmin): ?>
          <a href="/transactions/<?= $txn->id ?>/proof" target="_blank"
             class="inline-flex items-center gap-2 px-4 py-2 bg-slate-800 text-white text-sm font-bold rounded-lg hover:bg-slate-700 transition-colors shadow-sm">
            <span class="material-symbols-outlined text-base">visibility</span> View Document
          </a>
          <p class="text-[10px] text-slate-400 mt-2">Restricted to Managers & Admins</p>
          <?php else: ?>
          <div class="inline-flex items-center gap-2 px-4 py-2 bg-slate-100 text-slate-400 text-sm font-bold rounded-lg border border-slate-200">
            <span class="material-symbols-outlined text-base">lock</span> View Restricted
          </div>
          <p class="text-[10px] text-slate-400 mt-2">Only Managers & Admins can view this document.</p>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>
      <!-- Reversal Info -->
      <?php if ($txn->voidOf): ?>
      <div class="bg-orange-50 border border-orange-200 rounded-xl p-4 text-sm">
        <p class="font-bold text-orange-800 flex items-center gap-1"><span class="material-symbols-outlined text-base">undo</span> Reversal of</p>
        <a href="/transactions/<?= $txn->void_of_transaction_id ?>" class="font-bold text-orange-600 underline">#<?= $txn->void_of_transaction_id ?></a>
      </div>
      <?php endif; ?>
      <?php if ($txn->reversal): ?>
      <div class="bg-red-50 border border-red-200 rounded-xl p-4 text-sm">
        <p class="font-bold text-red-800 flex items-center gap-1"><span class="material-symbols-outlined text-base">block</span> Voided by</p>
        <a href="/transactions/<?= $txn->reversal->id ?>" class="font-bold text-red-600 underline">Reversal #<?= $txn->reversal->id ?></a>
      </div>
      <?php endif; ?>
    </div>
  </div>
<?php
$notes         = $txn->notes ?? collect([]);
$notePostUrl   = '/transactions/' . $txn->id . '/note';
$recordId      = $txn->id;
$currentUserId = $_SESSION['user_id'] ?? 0;
$currentRole   = $_SESSION['role'] ?? 'agent';
?>

<?php if ($txn->hasDispute()): ?>
<div class="mt-6 mb-4 px-5 py-4 bg-red-50 border border-red-300 rounded-xl flex items-start gap-3 shadow-sm">
  <span class="material-symbols-outlined text-red-600 text-2xl mt-0.5">warning</span>
  <div class="flex-1">
    <p class="font-bold text-red-800 text-sm">Dispute / Chargeback Alert</p>
    <p class="text-xs text-red-700 mt-0.5">
      Status: <span class="font-semibold"><?= htmlspecialchars($disputeLabel) ?></span>
      <?php if (!empty($txn->dispute_notes)): ?>
        — <?= htmlspecialchars($txn->dispute_notes) ?>
      <?php endif; ?>
    </p>
  </div>
  <span class="inline-block px-2.5 py-1 text-[10px] font-bold rounded-full <?= $disputeClass ?>"><?= htmlspecialchars($disputeLabel) ?></span>
</div>
<?php endif; ?>

<?php if ($isAdminOnly): ?>
<div class="mt-6 mb-4 grid grid-cols-1 sm:grid-cols-2 gap-4">

  <!-- ── DISPUTE / CHARGEBACK PANEL ── -->
  <div id="dispute-panel" class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
    <div class="px-5 py-3 border-b border-slate-100 bg-red-50/60 flex items-center justify-between">
      <h2 class="font-bold text-slate-900 text-sm flex items-center gap-1.5" style="font-family:Manrope">
        <span class="material-symbols-outlined text-base text-red-600">gavel</span> Dispute &amp; Chargeback
      </h2>
      <span id="dispute-badge" class="inline-block px-2.5 py-1 text-[10px] font-bold rounded-full <?= $disputeClass ?>">
        <?= htmlspecialchars($disputeLabel) ?>
      </span>
    </div>
    <div class="p-5 space-y-3">
      <div>
        <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Dispute Status</label>
        <select id="dispute_status_sel" class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-slate-50 focus:ring-2 focus:ring-red-400">
          <option value="dispute_opened"     <?= $txn->dispute_status === 'dispute_opened'     ? 'selected' : '' ?>>⚠ Dispute Opened</option>
          <option value="chargeback_received" <?= $txn->dispute_status === 'chargeback_received' ? 'selected' : '' ?>>🔴 Chargeback Received</option>
          <option value="refunded_dispute"   <?= $txn->dispute_status === 'refunded_dispute'   ? 'selected' : '' ?>>↩ Refunded (Dispute)</option>
          <option value="resolved"           <?= $txn->dispute_status === 'resolved'           ? 'selected' : '' ?>>✓ Resolved</option>
        </select>
      </div>
      <div>
        <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Admin Notes</label>
        <textarea id="dispute_notes_txt" rows="3" placeholder="Describe the dispute or resolution…"
          class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-slate-50 resize-none focus:ring-2 focus:ring-red-400"><?= htmlspecialchars($txn->dispute_notes ?? '') ?></textarea>
      </div>
      <button onclick="saveDispute()" class="w-full py-2 bg-red-600 hover:bg-red-700 text-white text-sm font-bold rounded-lg transition-colors flex items-center justify-center gap-2">
        <span class="material-symbols-outlined text-base">save</span> Save Dispute Status
      </button>
      <p id="dispute-msg" class="text-xs text-center hidden"></p>
      <?php if ($txn->dispute_flagged_at): ?>
      <p class="text-[10px] text-slate-400 text-center">Last updated: <?= date('M d, Y g:i A', strtotime($txn->dispute_flagged_at)) ?></p>
      <?php endif; ?>
    </div>
  </div>

  <!-- ── PAYMENT GATEWAY PANEL ── -->
  <div id="gateway-panel" class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
    <div class="px-5 py-3 border-b border-slate-100 bg-emerald-50/60 flex items-center justify-between">
      <h2 class="font-bold text-slate-900 text-sm flex items-center gap-1.5" style="font-family:Manrope">
        <span class="material-symbols-outlined text-base text-emerald-600">credit_card</span> Payment Gateway
      </h2>
      <span id="gateway-badge" class="inline-block px-2.5 py-1 text-[10px] font-bold rounded-full <?= $gatewayClass ?>">
        <?= htmlspecialchars($gatewayLabel) ?>
      </span>
    </div>
    <div class="p-5 space-y-3">
      <div>
        <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Gateway Result</label>
        <select id="gateway_status_sel" class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-slate-50 focus:ring-2 focus:ring-emerald-400" onchange="toggleGatewayTxnId()">
          <option value="charge_successful" <?= $txn->gateway_status === 'charge_successful' ? 'selected' : '' ?>>✓ Charge Successful</option>
          <option value="charge_declined"   <?= $txn->gateway_status === 'charge_declined'   ? 'selected' : '' ?>>✗ Charge Declined</option>
        </select>
      </div>
      <div id="gateway-txnid-wrap">
        <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Gateway Transaction ID <span class="text-rose-500">*</span></label>
        <input type="text" id="gateway_transaction_id_inp"
          value="<?= htmlspecialchars($txn->gateway_transaction_id ?? '') ?>"
          placeholder="e.g. ch_1ABC2DEF3GHI…"
          class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm font-mono bg-slate-50 focus:ring-2 focus:ring-emerald-400">
        <p class="text-[10px] text-slate-400 mt-1">Required when charge is successful</p>
      </div>
      <button onclick="saveGateway()" class="w-full py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-bold rounded-lg transition-colors flex items-center justify-center gap-2">
        <span class="material-symbols-outlined text-base">save</span> Save Gateway Status
      </button>
      <p id="gateway-msg" class="text-xs text-center hidden"></p>
      <?php if ($txn->gateway_actioned_at): ?>
      <p class="text-[10px] text-slate-400 text-center">Last updated: <?= date('M d, Y g:i A', strtotime($txn->gateway_actioned_at)) ?></p>
      <?php endif; ?>
    </div>
  </div>

</div>
<?php endif; ?>

<?php require __DIR__ . '/../partials/notes_panel.php'; ?>
</main>



<!-- ── VOID MODAL ────────────────────────────────────────────────────────── -->
<?php if ($canVoid): ?>
<div id="void_modal" class="hidden fixed inset-0 bg-black/40 flex items-center justify-center z-50 backdrop-blur-sm">
  <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm p-6">
    <h3 class="text-lg font-bold text-red-700 flex items-center gap-2 mb-1">
      <span class="material-symbols-outlined">block</span> Void Transaction #<?= $txn->id ?>
    </h3>
    <p class="text-xs text-slate-500 mb-4">This is irreversible. A reversal record will be created automatically.</p>
    <form method="POST" action="/transactions/<?= $txn->id ?>/void">
      <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
      <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Reason <span class="text-rose-500">*</span></label>
      <textarea name="void_reason" rows="3" required minlength="10"
        class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm mb-4 focus:ring-2 focus:ring-red-400 resize-none"
        placeholder="Minimum 10 characters explaining this void…"></textarea>
      <div class="flex items-center gap-3">
        <button type="button" onclick="document.getElementById('void_modal').classList.add('hidden')" class="flex-1 py-2 text-sm font-semibold text-slate-500 border border-slate-200 rounded-lg hover:bg-slate-50">Cancel</button>
        <button type="submit" class="flex-1 py-2 bg-red-600 text-white text-sm font-bold rounded-lg hover:bg-red-500">Confirm Void</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<script>
function openVoidModal() { document.getElementById('void_modal').classList.remove('hidden'); }

// ── Eye-icon card reveal toggle ──────────────────────────────────────────
const _cardCache = {};

function toggleCardReveal(cardId) {
  const numEl  = document.getElementById('card-num-' + cardId);
  const infoEl = document.getElementById('card-info-' + cardId);
  const icon   = document.getElementById('eye-icon-' + cardId);

  // If already revealed → hide it
  if (_cardCache[cardId] && _cardCache[cardId].revealed) {
    numEl.textContent  = _cardCache[cardId].masked;
    infoEl.textContent = _cardCache[cardId].maskedInfo;
    icon.textContent = 'visibility';
    _cardCache[cardId].revealed = false;
    return;
  }

  // If cached → show instantly
  if (_cardCache[cardId]) {
    _showCard(cardId);
    return;
  }

  // Fetch from server
  icon.textContent = 'progress_activity';
  icon.classList.add('animate-spin');
  fetch('/transactions/reveal-card', {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': '<?= $_SESSION['csrf_token'] ?? '' ?>'},
    body: `card_id=${cardId}&password=__session__`
  })
  .then(r => r.json())
  .then(res => {
    icon.classList.remove('animate-spin');
    if (res.success) {
      _cardCache[cardId] = {
        masked: numEl.textContent,
        maskedInfo: infoEl.textContent,
        full: res.data.card_number,
        cvv: res.data.cvv || '—',
        expiry: res.data.expiry,
        holder: res.data.holder_name,
        revealed: false,
      };
      _showCard(cardId);
    } else {
      icon.textContent = 'visibility';
      alert(res.error || 'Could not reveal card.');
    }
  })
  .catch(() => {
    icon.classList.remove('animate-spin');
    icon.textContent = 'visibility';
    alert('Network error.');
  });
}

function _showCard(cardId) {
  const c = _cardCache[cardId];
  document.getElementById('card-num-' + cardId).textContent = c.full;
  document.getElementById('card-info-' + cardId).textContent = c.holder + ' · Exp ' + c.expiry + ' · CVV ' + c.cvv;
  document.getElementById('eye-icon-' + cardId).textContent = 'visibility_off';
  c.revealed = true;
}

// ── Dispute Panel ─────────────────────────────────────────────────────────
function saveDispute() {
  const status = document.getElementById('dispute_status_sel').value;
  const notes  = document.getElementById('dispute_notes_txt').value;
  const msg    = document.getElementById('dispute-msg');
  msg.className = 'text-xs text-center text-slate-400'; msg.textContent = 'Saving…'; msg.classList.remove('hidden');
  fetch('/transactions/<?= $txn->id ?>/dispute', {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: 'csrf_token=<?= htmlspecialchars($_SESSION["csrf_token"] ?? "") ?>&dispute_status=' + encodeURIComponent(status) + '&dispute_notes=' + encodeURIComponent(notes)
  })
  .then(r => r.json())
  .then(res => {
    if (res.success) {
      const badge = document.getElementById('dispute-badge');
      badge.textContent = res.label;
      badge.className = 'inline-block px-2.5 py-1 text-[10px] font-bold rounded-full ' + res.class;
      msg.className = 'text-xs text-center text-emerald-600'; msg.textContent = '✓ Saved successfully';
      // Update alert banner if exists
      const banner = document.querySelector('.dispute-banner-label');
      if (banner) banner.textContent = res.label;
    } else {
      msg.className = 'text-xs text-center text-red-600'; msg.textContent = res.error || 'Error saving.';
    }
  })
  .catch(() => { msg.className = 'text-xs text-center text-red-600'; msg.textContent = 'Network error.'; });
}

// ── Gateway Panel ─────────────────────────────────────────────────────────
function toggleGatewayTxnId() {
  const status = document.getElementById('gateway_status_sel').value;
  const wrap   = document.getElementById('gateway-txnid-wrap');
  wrap.style.display = (status === 'charge_successful') ? '' : 'none';
}

function saveGateway() {
  const status = document.getElementById('gateway_status_sel').value;
  const txnId  = document.getElementById('gateway_transaction_id_inp').value.trim();
  const msg    = document.getElementById('gateway-msg');
  if (status === 'charge_successful' && !txnId) {
    msg.className = 'text-xs text-center text-red-600'; msg.textContent = 'Gateway Transaction ID is required.'; msg.classList.remove('hidden'); return;
  }
  msg.className = 'text-xs text-center text-slate-400'; msg.textContent = 'Saving…'; msg.classList.remove('hidden');
  fetch('/transactions/<?= $txn->id ?>/gateway', {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: 'csrf_token=<?= htmlspecialchars($_SESSION["csrf_token"] ?? "") ?>&gateway_status=' + encodeURIComponent(status) + '&gateway_transaction_id=' + encodeURIComponent(txnId)
  })
  .then(r => r.json())
  .then(res => {
    if (res.success) {
      const badge = document.getElementById('gateway-badge');
      badge.textContent = res.label;
      badge.className = 'inline-block px-2.5 py-1 text-[10px] font-bold rounded-full ' + res.class;
      msg.className = 'text-xs text-center text-emerald-600'; msg.textContent = '✓ Saved successfully';
    } else {
      msg.className = 'text-xs text-center text-red-600'; msg.textContent = res.error || 'Error saving.';
    }
  })
  .catch(() => { msg.className = 'text-xs text-center text-red-600'; msg.textContent = 'Network error.'; });
}

// ── Init gateway panel visibility ─────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
  if (document.getElementById('gateway_status_sel')) toggleGatewayTxnId();
});
</script>
</body>
</html>
