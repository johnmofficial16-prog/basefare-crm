<?php
/**
 * Customer Authorization & E-Signature Portal
 * Public URL: https://base-fare.com/auth?token={64-char-token}
 *
 * This page is fully standalone — no CRM authentication required.
 * Access is controlled ONLY by the token in the URL.
 *
 * Flow:
 *  1. Token validated → show authorization details
 *  2. Customer uploads docs if required (passport/CC)
 *  3. Customer reads policy, checks consent box
 *  4. Customer signs on canvas
 *  5. Submit → POST to this same page
 *  6. Server: saves signature + files, marks APPROVED, fires confirmation email
 */

// ─── Bootstrap ───────────────────────────────────────────────────────────────
// Adjust path depth based on your project structure
$basePath = dirname(__DIR__, 2); // /public/auth → project root
require $basePath . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable($basePath);
try { $dotenv->load(); } catch (\Throwable $e) {}

// Boot Eloquent
$capsule = new \Illuminate\Database\Capsule\Manager;
$capsule->addConnection([
    'driver'    => 'mysql',
    'host'      => $_ENV['DB_HOST']     ?? '127.0.0.1',
    'port'      => $_ENV['DB_PORT']     ?? '3306',
    'database'  => $_ENV['DB_DATABASE'] ?? 'basefare_crm',
    'username'  => $_ENV['DB_USERNAME'] ?? 'root',
    'password'  => $_ENV['DB_PASSWORD'] ?? '',
    'charset'   => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
]);
$capsule->setAsGlobal();
$capsule->bootEloquent();

use App\Models\AcceptanceRequest;
use App\Services\AcceptanceService;
use App\Services\AcceptanceEmailService;
use Carbon\Carbon;

$service = new AcceptanceService();

// ─── Token resolution ─────────────────────────────────────────────────────────
$rawToken = trim($_GET['token'] ?? $_POST['token'] ?? '');

// Reject obviously invalid tokens early
if (!preg_match('/^[a-f0-9]{64}$/', $rawToken)) {
    $error = 'invalid_token';
    goto render;
}

$acceptance = $service->findValidByToken($rawToken);

if (!$acceptance) {
    $error = 'not_found';
    goto render;
}

// ─── POST: Process submission ─────────────────────────────────────────────────
$submitError   = null;
$submitSuccess = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit') {
    do {
        if (!$acceptance->isActionable()) {
            $submitError = 'This authorization link is no longer active.';
            break;
        }

        // Validate consent checkbox
        if (empty($_POST['consent'])) {
            $submitError = 'You must read and agree to the authorization policy before signing.';
            break;
        }

        // Validate signature
        $signatureData = trim($_POST['signature_data'] ?? '');
        if (empty($signatureData) || strlen($signatureData) < 100) {
            $submitError = 'A digital signature is required. Please sign in the box above.';
            break;
        }

        // Save signature
        $signaturePath = $service->saveSignature($rawToken, $signatureData);
        if (!$signaturePath) {
            $submitError = 'Failed to save your signature. Please try again.';
            break;
        }

        // Save passport / ID (if required)
        // NOTE: UPLOAD_ERR_OK === 0 (falsy) — must use strict comparison, NOT empty()
        $passportPath = null;
        $passportUploaded = isset($_FILES['passport_file']) && $_FILES['passport_file']['error'] === UPLOAD_ERR_OK;
        if ($acceptance->req_passport && $passportUploaded) {
            $passportPath = $service->saveEvidenceFile($rawToken, $_FILES['passport_file'], 'passport');
            if (!$passportPath) {
                $submitError = 'Failed to process the uploaded Passport/ID file. Please ensure it is a valid JPG, PNG or PDF under 10MB.';
                break;
            }
        } elseif ($acceptance->req_passport) {
            $submitError = 'A Passport / Government ID upload is required to proceed.';
            break;
        }

        // Save CC front (if required)
        $cardPath = null;
        $cardUploaded = isset($_FILES['card_file']) && $_FILES['card_file']['error'] === UPLOAD_ERR_OK;
        if ($acceptance->req_cc_front && $cardUploaded) {
            $cardPath = $service->saveEvidenceFile($rawToken, $_FILES['card_file'], 'card');
            if (!$cardPath) {
                $submitError = 'Failed to process the uploaded Credit Card image. Please ensure it is a valid JPG or PNG under 10MB.';
                break;
            }
        } elseif ($acceptance->req_cc_front) {
            $submitError = 'A Credit Card front image upload is required to proceed.';
            break;
        }

        // Collect forensic data
        $fingerprint  = htmlspecialchars(strip_tags($_POST['device_fingerprint'] ?? ''));
        $forensicData = $service->collectForensicData($fingerprint);

        // Approve
        $ok = $service->processApproval($acceptance, $forensicData, $signaturePath, $passportPath, $cardPath);

        if (!$ok) {
            $submitError = 'Authorization could not be processed. The link may have expired. Please contact your travel agent.';
            break;
        }

        $acceptance->refresh();
        $submitSuccess = true;

        // Send confirmation email (best-effort — don't fail the page if email fails)
        try {
            $emailService = new AcceptanceEmailService();
            $emailService->sendConfirmation($acceptance);
        } catch (\Throwable $e) {
            // Log silently — approval already saved
            error_log('[Acceptance] Confirmation email failed for #' . $acceptance->id . ': ' . $e->getMessage());
        }

    } while (false); // do-once pattern
}

// ─── Record first view ────────────────────────────────────────────────────────
if ($acceptance && $acceptance->isPending() && !$submitSuccess) {
    $service->recordViewed($acceptance);
}

// ─── Derive display data ──────────────────────────────────────────────────────
$error = $error ?? null;

render:

// Airline logos for display
$flightData    = $acceptance->flight_data   ?? [];
$fareBreakdown = $acceptance->fare_breakdown ?? [];
$passengers    = $acceptance->passengers    ?? [];

// Collect all segment groups for display
$allSegGroups = [];
if (!empty($flightData['flights']))     $allSegGroups[] = ['title' => 'Flight Itinerary',             'segs' => $flightData['flights'],     'color' => 'blue'];
if (!empty($flightData['old_flights'])) $allSegGroups[] = ['title' => 'Original Flights',             'segs' => $flightData['old_flights'], 'color' => 'rose'];
if (!empty($flightData['new_flights'])) $allSegGroups[] = ['title' => 'New Flights (After Change)',   'segs' => $flightData['new_flights'], 'color' => 'emerald'];

// Primary airline iata for branding
$primaryIata = '';
foreach ($allSegGroups as $grp) {
    if (!empty($grp['segs'][0]['airline_iata'])) {
        $primaryIata = strtoupper($grp['segs'][0]['airline_iata']);
        break;
    }
}
$headerLogoUrl = $primaryIata ? AcceptanceRequest::airlineLogoUrl($primaryIata, 70) : '';

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0"/>
<title>Authorization Request — Lets Fly Travel DBA Base Fare</title>
<meta name="description" content="Secure customer authorization portal for Lets Fly Travel DBA Base Fare."/>
<meta name="robots" content="noindex, nofollow"/>
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
  /* Canvas signature pad */
  #sig-canvas {
    cursor: crosshair;
    touch-action: none;
    background: #fafafa;
    border: 1.5px solid #e2e8f0;
    border-radius: 12px;
    width: 100%;
  }
  #sig-canvas.active { border-color: #0f1e3c; box-shadow: 0 0 0 3px rgba(15,30,60,0.12); }
  #sig-canvas.signed { border-color: #10b981; background: #f0fdf4; }

  /* Animated background */
  body { background: #f0f4ff; }
  .portal-bg {
    background: linear-gradient(135deg, #0f1e3c 0%, #1a3a6b 50%, #0f1e3c 100%);
    min-height: 100vh;
  }
  .glass-card {
    background: rgba(255,255,255,0.98);
    backdrop-filter: blur(20px);
    box-shadow: 0 20px 60px rgba(15,30,60,0.25), 0 1px 3px rgba(0,0,0,0.1);
  }
  .seg-airline { background: #0f172a; }
  .security-badge { animation: pulse 2s infinite; }
  @keyframes pulse { 0%,100% { opacity:1; } 50% { opacity:0.75; } }

  /* File upload zone */
  .upload-zone { transition: all 0.15s ease; cursor: pointer; }
  .upload-zone:hover { border-color: #0f1e3c; background: #f0f4ff; }
  .upload-zone.has-file { border-color: #10b981; background: #f0fdf4; }

  /* Step indicator */
  .form-step { display: none; }
  .form-step.active { display: block; }

  /* Consent checkbox custom */
  .consent-box { transition: all 0.15s ease; }

  /* Print */
  @media print { .no-print { display:none!important; } }
</style>
</head>

<?php if (!empty($error)): ?>
<!-- ═══ ERROR / INVALID TOKEN PAGE ═══ -->
<body>
<div class="portal-bg flex items-center justify-center min-h-screen p-4">
  <div class="glass-card rounded-2xl p-10 max-w-md w-full text-center">
    <div class="w-16 h-16 bg-rose-100 rounded-full flex items-center justify-center mx-auto mb-4">
      <span class="material-symbols-outlined text-3xl text-rose-600">link_off</span>
    </div>
    <h1 class="text-xl font-bold text-slate-900 mb-2" style="font-family:Manrope,sans-serif;">
      <?= $error === 'not_found' ? 'Link Not Found or Expired' : 'Invalid Authorization Link' ?>
    </h1>
    <p class="text-slate-500 text-sm leading-relaxed mb-6">
      This authorization link is invalid, has already been used, or has expired.
      Authorization links are valid for <strong>12 hours</strong> after creation.
    </p>
    <p class="text-xs text-slate-400">
      Please contact your travel agent at
      <a href="mailto:<?= h(AcceptanceRequest::COMPANY_EMAIL) ?>" class="text-primary-600 font-semibold hover:underline">
        <?= h(AcceptanceRequest::COMPANY_EMAIL) ?>
      </a>
      to request a new link.
    </p>
    <div class="mt-6 p-3 bg-slate-50 rounded-xl inline-flex items-center gap-2">
      <span class="material-symbols-outlined text-sm text-slate-400">business</span>
      <span class="text-xs text-slate-500 font-semibold"><?= h(AcceptanceRequest::COMPANY_NAME) ?></span>
    </div>
  </div>
</div>
</body></html>
<?php return; ?>

<?php elseif ($submitSuccess || $acceptance->isApproved()): ?>
<!-- ═══ SUCCESS / ALREADY APPROVED PAGE ═══ -->
<body>
<div class="portal-bg flex items-center justify-center min-h-screen p-4">
  <div class="glass-card rounded-2xl p-10 max-w-lg w-full text-center">
    <div class="w-20 h-20 bg-emerald-100 rounded-full flex items-center justify-center mx-auto mb-4">
      <span class="material-symbols-outlined text-4xl text-emerald-600">verified</span>
    </div>
    <div class="inline-flex items-center gap-2 mb-4">
      <?php if ($headerLogoUrl): ?>
      <img src="<?= h($headerLogoUrl) ?>" alt="<?= h($primaryIata) ?>" class="w-8 h-8 object-contain" onerror="this.style.display='none'">
      <?php endif; ?>
      <span class="text-xs font-bold text-slate-500"><?= h(AcceptanceRequest::COMPANY_NAME) ?></span>
    </div>
    <h1 class="text-2xl font-black text-emerald-700 mb-2" style="font-family:Manrope,sans-serif;">Authorization Confirmed</h1>
    <p class="text-slate-600 text-sm mb-1">Thank you, <strong><?= h($acceptance->customer_name) ?></strong>.</p>
    <p class="text-slate-500 text-sm leading-relaxed mb-6">
      Your authorization for <strong><?= h($acceptance->typeLabel()) ?></strong>
      (PNR: <code class="font-mono font-bold text-primary-600"><?= h($acceptance->pnr) ?></code>)
      has been successfully recorded.
    </p>
    <div class="grid grid-cols-2 gap-3 mb-6 text-left">
      <div class="p-3 bg-slate-50 border border-slate-200 rounded-xl">
        <p class="text-[9px] font-bold text-slate-400 uppercase">Amount Authorized</p>
        <p class="font-black font-mono text-emerald-700 mt-1"><?= h($acceptance->currency) ?> <?= number_format($acceptance->total_amount, 2) ?></p>
      </div>
      <div class="p-3 bg-slate-50 border border-slate-200 rounded-xl">
        <p class="text-[9px] font-bold text-slate-400 uppercase">Signed At</p>
        <p class="text-xs font-semibold text-slate-800 mt-1">
          <?= $acceptance->approved_at ? Carbon::parse($acceptance->approved_at)->format('M j, Y g:i A') : '—' ?>
        </p>
      </div>
    </div>
    <div class="p-4 bg-amber-50 border border-amber-200 rounded-xl text-xs text-amber-800 text-left">
      <p class="font-bold mb-1">What happens next?</p>
      <p class="leading-relaxed">
        Your travel agent will process your request and you will receive a confirmation email at
        <strong><?= h($acceptance->customer_email) ?></strong>.
        Keep this page or your email for your records.
      </p>
    </div>
    <div class="mt-6 p-3 bg-slate-50 rounded-xl flex items-center justify-center gap-2">
      <span class="material-symbols-outlined text-sm text-slate-400">lock</span>
      <span class="text-[11px] text-slate-400">Secured &amp; encrypted by <?= h(AcceptanceRequest::COMPANY_NAME) ?></span>
    </div>
  </div>
</div>
</body></html>
<?php return; ?>

<?php elseif ($acceptance->isExpired()): ?>
<!-- ═══ EXPIRED PAGE ═══ -->
<body>
<div class="portal-bg flex items-center justify-center min-h-screen p-4">
  <div class="glass-card rounded-2xl p-10 max-w-md w-full text-center">
    <div class="w-16 h-16 bg-amber-100 rounded-full flex items-center justify-center mx-auto mb-4">
      <span class="material-symbols-outlined text-3xl text-amber-600">timer_off</span>
    </div>
    <h1 class="text-xl font-bold text-slate-900 mb-2" style="font-family:Manrope,sans-serif;">Authorization Link Expired</h1>
    <p class="text-slate-500 text-sm leading-relaxed mb-6">
      This link was valid for <strong>12 hours</strong> and has now expired.
      Please contact your travel agent to request a new authorization link.
    </p>
    <p class="text-xs text-slate-400">PNR: <code class="font-mono font-bold"><?= h($acceptance->pnr) ?></code></p>
    <p class="text-xs text-slate-400 mt-1">
      Contact: <a href="mailto:<?= h(AcceptanceRequest::COMPANY_EMAIL) ?>" class="text-primary-600 font-semibold hover:underline"><?= h(AcceptanceRequest::COMPANY_EMAIL) ?></a>
    </p>
  </div>
</div>
</body></html>
<?php return; ?>

<?php elseif ($acceptance->isCancelled()): ?>
<!-- ═══ CANCELLED PAGE ═══ -->
<body>
<div class="portal-bg flex items-center justify-center min-h-screen p-4">
  <div class="glass-card rounded-2xl p-10 max-w-md w-full text-center">
    <div class="w-16 h-16 bg-slate-100 rounded-full flex items-center justify-center mx-auto mb-4">
      <span class="material-symbols-outlined text-3xl text-slate-500">cancel</span>
    </div>
    <h1 class="text-xl font-bold text-slate-900 mb-2" style="font-family:Manrope,sans-serif;">Request Cancelled</h1>
    <p class="text-slate-500 text-sm leading-relaxed">
      This authorization request has been cancelled by your travel agent.
      Please contact us at <a href="mailto:<?= h(AcceptanceRequest::COMPANY_EMAIL) ?>" class="text-primary-600 font-semibold hover:underline"><?= h(AcceptanceRequest::COMPANY_EMAIL) ?></a>
      if you have questions.
    </p>
  </div>
</div>
</body></html>
<?php return; ?>

<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<!-- MAIN AUTHORIZATION FORM                                                     -->
<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<body>
<div class="portal-bg min-h-screen py-8 px-4">

  <!-- Header Bar -->
  <div class="max-w-2xl mx-auto mb-6 flex items-center justify-between">
    <div class="flex items-center gap-3">
      <?php if ($headerLogoUrl): ?>
      <img src="<?= h($headerLogoUrl) ?>" alt="<?= h($primaryIata) ?>" class="w-10 h-10 object-contain rounded-lg bg-white/10 p-1" onerror="this.style.display='none'">
      <?php endif; ?>
      <div>
        <p class="text-white font-black text-sm leading-tight" style="font-family:Manrope,sans-serif;"><?= h(AcceptanceRequest::COMPANY_NAME) ?></p>
        <p class="text-blue-300 text-[10px] font-medium">Secure Authorization Portal</p>
      </div>
    </div>
    <div class="security-badge flex items-center gap-1.5 bg-white/10 border border-white/20 rounded-full px-3 py-1.5">
      <span class="material-symbols-outlined text-emerald-400 text-sm">lock</span>
      <span class="text-white text-[10px] font-bold">SSL Secured</span>
    </div>
  </div>

  <!-- Main Card -->
  <div class="max-w-2xl mx-auto glass-card rounded-2xl overflow-hidden">

    <!-- Card Header -->
    <div class="px-6 py-5 border-b border-slate-100" style="background:linear-gradient(135deg,#0f1e3c,#1a3a6b);">
      <div class="flex items-start justify-between gap-4">
        <div>
          <p class="text-[10px] font-bold text-blue-300 uppercase tracking-widest"><?= h($acceptance->typeLabel()) ?></p>
          <h1 class="text-xl font-black text-white mt-1" style="font-family:Manrope,sans-serif;">Authorization Request</h1>
          <p class="text-blue-200 text-xs mt-1">
            Dear <strong><?= h($acceptance->customer_name) ?></strong> —
            please review and sign below to authorize.
          </p>
        </div>
        <div class="text-right flex-none">
          <p class="text-[9px] font-bold text-blue-300 uppercase">PNR</p>
          <p class="font-black font-mono text-white text-lg"><?= h($acceptance->pnr) ?></p>
          <?php if ($acceptance->order_id): ?>
          <p class="text-[10px] text-blue-300 font-mono"><?= h($acceptance->order_id) ?></p>
          <?php endif; ?>
        </div>
      </div>

      <!-- Timer -->
      <div class="mt-4 flex items-center gap-2 bg-white/10 border border-white/15 rounded-xl px-4 py-2.5">
        <span class="material-symbols-outlined text-amber-300 text-sm">timer</span>
        <div class="flex-1">
          <p class="text-[10px] font-bold text-white"><?= h($acceptance->expiryLabel()) ?></p>
          <p class="text-[9px] text-blue-300">Link expires <?= Carbon::parse($acceptance->expires_at)->format('M j, Y \a\t g:i A') ?></p>
        </div>
        <div id="countdown" class="font-mono font-black text-amber-300 text-base"></div>
      </div>
    </div>

    <!-- Submit Error -->
    <?php if ($submitError): ?>
    <div class="mx-6 mt-5 flex items-start gap-3 bg-rose-50 border border-rose-200 rounded-xl p-4">
      <span class="material-symbols-outlined text-rose-500 flex-none mt-0.5">error</span>
      <p class="text-rose-800 text-sm font-medium"><?= h($submitError) ?></p>
    </div>
    <?php endif; ?>

    <form id="auth-form" method="POST" enctype="multipart/form-data" novalidate>
      <input type="hidden" name="action"            value="submit">
      <input type="hidden" name="token"             value="<?= h($rawToken) ?>">
      <input type="hidden" name="signature_data"    id="hid-signature" value="">
      <input type="hidden" name="device_fingerprint"id="hid-fingerprint" value="">

      <div class="p-6 space-y-6">

        <!-- ── 1. BOOKING SUMMARY ── -->
        <div class="space-y-3">
          <h2 class="text-sm font-bold text-slate-800 flex items-center gap-2" style="font-family:Manrope,sans-serif;">
            <span class="w-5 h-5 rounded-full bg-primary-600 text-white text-[10px] font-black flex items-center justify-center flex-none">1</span>
            Booking Summary
          </h2>
          <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
            <div class="p-3 bg-slate-50 border border-slate-200 rounded-xl">
              <p class="text-[9px] font-bold text-slate-400 uppercase">Customer</p>
              <p class="text-sm font-bold text-slate-900 mt-0.5"><?= h($acceptance->customer_name) ?></p>
            </div>
            <div class="p-3 bg-slate-50 border border-slate-200 rounded-xl">
              <p class="text-[9px] font-bold text-slate-400 uppercase">PNR</p>
              <p class="text-sm font-black font-mono text-primary-600 mt-0.5"><?= h($acceptance->pnr) ?></p>
            </div>
            <div class="p-3 bg-slate-50 border border-slate-200 rounded-xl">
              <p class="text-[9px] font-bold text-slate-400 uppercase">Request Type</p>
              <p class="text-xs font-semibold text-slate-800 mt-0.5"><?= h($acceptance->typeLabel()) ?></p>
            </div>
          </div>

          <!-- Passengers -->
          <?php if (!empty($passengers)): ?>
          <div class="p-3 bg-slate-50 border border-slate-200 rounded-xl">
            <p class="text-[9px] font-bold text-slate-400 uppercase mb-2">Passengers Included</p>
            <div class="flex flex-wrap gap-2">
              <?php foreach ($passengers as $pax): ?>
              <span class="inline-flex items-center gap-1.5 px-2.5 py-1.5 bg-white border border-slate-300 rounded-lg text-xs font-mono font-semibold text-slate-800">
                <span class="material-symbols-outlined text-sm text-slate-400">person</span>
                <?= h($pax['name'] ?? '') ?>
              </span>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endif; ?>
        </div>

        <!-- ── 2. FLIGHT DETAILS ── -->
        <?php if (!empty($allSegGroups)): ?>
        <div class="space-y-3">
          <h2 class="text-sm font-bold text-slate-800 flex items-center gap-2" style="font-family:Manrope,sans-serif;">
            <span class="w-5 h-5 rounded-full bg-primary-600 text-white text-[10px] font-black flex items-center justify-center flex-none">2</span>
            Flight Details
          </h2>
          <?php
          // NOTE: 'static' keyword is only valid inside functions — use plain var here
          $AIRLINES_PUB = [
              'AC'=>'Air Canada','WS'=>'WestJet','AA'=>'American Airlines','DL'=>'Delta','UA'=>'United',
              'BA'=>'British Airways','LH'=>'Lufthansa','AF'=>'Air France','KL'=>'KLM','EK'=>'Emirates',
              'QR'=>'Qatar Airways','SQ'=>'Singapore Airlines','CX'=>'Cathay Pacific','JL'=>'Japan Airlines',
              'NH'=>'ANA','TK'=>'Turkish Airlines','EY'=>'Etihad','LX'=>'Swiss','OS'=>'Austrian',
              'AI'=>'Air India','TP'=>'TAP Portugal','VS'=>'Virgin Atlantic',
              'KE'=>'Korean Air','QF'=>'Qantas','TG'=>'Thai Airways','MH'=>'Malaysia Airlines',
              'SV'=>'Saudia','MS'=>'EgyptAir','ET'=>'Ethiopian','B6'=>'JetBlue','AS'=>'Alaska',
          ];
          $CITIES_PUB = [
              'YYZ'=>'Toronto','YVR'=>'Vancouver','YUL'=>'Montreal','YYC'=>'Calgary',
              'LHR'=>'London','LGW'=>'London Gatwick','CDG'=>'Paris','FRA'=>'Frankfurt',
              'AMS'=>'Amsterdam','MAD'=>'Madrid','FCO'=>'Rome','ZRH'=>'Zurich','IST'=>'Istanbul',
              'DXB'=>'Dubai','DOH'=>'Doha','AUH'=>'Abu Dhabi',
              'BOM'=>'Mumbai','DEL'=>'New Delhi','BLR'=>'Bangalore','MAA'=>'Chennai',
              'JFK'=>'New York','EWR'=>'Newark','LAX'=>'Los Angeles','SFO'=>'San Francisco',
              'ORD'=>'Chicago','MIA'=>'Miami','DFW'=>'Dallas','SEA'=>'Seattle','BOS'=>'Boston',
              'ATL'=>'Atlanta','DEN'=>'Denver','SIN'=>'Singapore','HKG'=>'Hong Kong','BKK'=>'Bangkok',
              'NRT'=>'Tokyo','HND'=>'Tokyo Haneda','ICN'=>'Seoul','SYD'=>'Sydney','MEL'=>'Melbourne',
          ];
          foreach ($allSegGroups as $grp):
            $groupColor = $grp['color'];
            $headerBg = match($groupColor) {
              'rose'    => 'bg-rose-50 border-rose-200 text-rose-800',
              'emerald' => 'bg-emerald-50 border-emerald-200 text-emerald-800',
              default   => 'bg-blue-50 border-blue-200 text-blue-800',
            };
          ?>
          <div class="border border-slate-200 rounded-xl overflow-hidden">
            <div class="px-4 py-2 <?= $headerBg ?> border-b text-[10px] font-bold uppercase tracking-wider">
              <?= h($grp['title']) ?>
            </div>
            <div class="divide-y divide-slate-100 bg-white">
              <?php foreach ($grp['segs'] as $idx => $seg):
                $iata   = strtoupper($seg['airline_iata'] ?? '');
                $aName  = $AIRLINES_PUB[$iata] ?? $iata;
                $from   = strtoupper($seg['from'] ?? '');
                $to     = strtoupper($seg['to'] ?? '');
                $fCity  = $CITIES_PUB[$from] ?? $from;
                $tCity  = $CITIES_PUB[$to] ?? $to;
                $logo   = $iata ? "https://www.gstatic.com/flights/airline_logos/35px/{$iata}.png" : '';
                $nd     = !empty($seg['arr_next_day']);
              ?>
              <div class="flex items-center gap-4 px-4 py-3">
                <div class="flex items-center gap-2 flex-none w-24">
                  <?php if ($logo): ?>
                  <img src="<?= h($logo) ?>" alt="<?= h($iata) ?>" class="w-6 h-6 object-contain" onerror="this.style.display='none'">
                  <?php endif; ?>
                  <div>
                    <p class="text-[11px] font-black text-slate-900"><?= h($seg['flight_no'] ?? '') ?></p>
                    <p class="text-[9px] text-slate-400"><?= h($seg['date'] ?? '') ?></p>
                  </div>
                </div>
                <div class="flex-1 flex items-center gap-2 text-sm font-bold text-slate-900">
                  <div class="text-right min-w-[40px]">
                    <p class="font-black"><?= h($seg['dep_time'] ?? '') ?></p>
                    <p class="text-xs font-bold text-primary-600"><?= h($from) ?></p>
                    <p class="text-[9px] text-slate-400 font-normal"><?= h($fCity) ?></p>
                  </div>
                  <div class="flex-1 text-center text-slate-300 text-lg">→</div>
                  <div class="min-w-[40px]">
                    <div class="flex items-baseline gap-1">
                      <p class="font-black"><?= h($seg['arr_time'] ?? '') ?></p>
                      <?php if ($nd): ?>
                      <span class="text-[9px] font-bold bg-rose-100 text-rose-700 px-1 rounded">+1</span>
                      <?php endif; ?>
                    </div>
                    <p class="text-xs font-bold text-primary-600"><?= h($to) ?></p>
                    <p class="text-[9px] text-slate-400 font-normal"><?= h($tCity) ?></p>
                  </div>
                </div>
                <span class="text-[10px] font-bold text-slate-400 flex-none"><?= h($seg['cabin_class'] ?? '') ?></span>
              </div>
              <?php if ($idx < count($grp['segs'])-1): ?>
              <div class="px-4 py-1.5 bg-amber-50 flex items-center gap-2 text-[10px] font-semibold text-amber-700">
                <span class="material-symbols-outlined text-xs">connecting_airports</span>
                Layover in <?= h($CITIES_PUB[$to] ?? $to) ?>
              </div>
              <?php endif; ?>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Name Correction -->
        <?php if ($acceptance->type === 'name_correction' && !empty($flightData['old_name'])): ?>
        <div class="p-4 bg-yellow-50 border border-yellow-200 rounded-xl">
          <p class="text-[10px] font-bold text-yellow-700 uppercase mb-3">Name Correction</p>
          <div class="flex items-center gap-3 flex-wrap">
            <div class="p-2 bg-rose-100 border border-rose-200 rounded-lg"><p class="text-xs font-mono font-bold text-rose-800"><?= h($flightData['old_name']) ?></p></div>
            <span class="text-slate-400 font-bold">→</span>
            <div class="p-2 bg-emerald-100 border border-emerald-200 rounded-lg"><p class="text-xs font-mono font-bold text-emerald-800"><?= h($flightData['new_name'] ?? '') ?></p></div>
          </div>
        </div>
        <?php endif; ?>

        <!-- Cabin Upgrade -->
        <?php if ($acceptance->type === 'cabin_upgrade' && !empty($flightData['old_cabin'])): ?>
        <div class="p-4 bg-teal-50 border border-teal-200 rounded-xl flex items-center gap-4">
          <span class="material-symbols-outlined text-teal-600">workspace_premium</span>
          <div class="flex items-center gap-2">
            <span class="px-2 py-1 bg-rose-100 text-rose-700 rounded text-xs font-bold"><?= h($flightData['old_cabin']) ?></span>
            <span class="text-slate-400">→</span>
            <span class="px-2 py-1 bg-emerald-100 text-emerald-700 rounded text-xs font-bold"><?= h($flightData['new_cabin'] ?? '') ?></span>
          </div>
        </div>
        <?php endif; ?>

        <!-- Other description -->
        <?php if ($acceptance->type === 'other' && !empty($acceptance->extra_data['description'])): ?>
        <div class="p-4 bg-slate-50 border border-slate-200 rounded-xl">
          <p class="text-[10px] font-bold text-slate-400 uppercase mb-2">Details</p>
          <p class="text-sm text-slate-700 whitespace-pre-wrap"><?= h($acceptance->extra_data['description']) ?></p>
        </div>
        <?php endif; ?>

        <!-- Ticket conditions -->
        <?php if ($acceptance->endorsements || $acceptance->baggage_info): ?>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <?php if ($acceptance->endorsements): ?>
          <div class="p-3 bg-slate-50 border border-slate-200 rounded-xl">
            <p class="text-[9px] font-bold text-slate-400 uppercase mb-1">Endorsements</p>
            <p class="text-xs font-mono text-slate-800"><?= h($acceptance->endorsements) ?></p>
          </div>
          <?php endif; ?>
          <?php if ($acceptance->baggage_info): ?>
          <div class="p-3 bg-slate-50 border border-slate-200 rounded-xl">
            <p class="text-[9px] font-bold text-slate-400 uppercase mb-1">Baggage</p>
            <p class="text-xs text-slate-700"><?= h($acceptance->baggage_info) ?></p>
          </div>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- ── 3. FARE & PAYMENT ── -->
        <div class="space-y-3">
          <h2 class="text-sm font-bold text-slate-800 flex items-center gap-2" style="font-family:Manrope,sans-serif;">
            <span class="w-5 h-5 rounded-full bg-primary-600 text-white text-[10px] font-black flex items-center justify-center flex-none">3</span>
            Amount Being Authorized
          </h2>
          <div class="border border-slate-200 rounded-xl overflow-hidden bg-white">
            <?php foreach ($fareBreakdown as $item): ?>
            <div class="flex justify-between items-center px-4 py-2.5 border-b border-slate-50">
              <span class="text-sm text-slate-600"><?= h($item['label'] ?? '') ?></span>
              <span class="text-sm font-mono font-semibold text-slate-800">
                <?= h($acceptance->currency) ?> <?= number_format($item['amount'] ?? 0, 2) ?>
              </span>
            </div>
            <?php endforeach; ?>
            <div class="flex justify-between items-center px-4 py-3" style="background:#0f1e3c;">
              <span class="font-bold text-sm text-white">Total Authorized Amount</span>
              <span class="font-black font-mono text-xl text-emerald-400">
                <?= h($acceptance->currency) ?> <?= number_format($acceptance->total_amount, 2) ?>
              </span>
            </div>
          </div>

          <!-- Card -->
          <div class="flex items-center gap-3 p-4 bg-slate-50 border border-slate-200 rounded-xl">
            <span class="material-symbols-outlined text-slate-500">credit_card</span>
            <div>
              <p class="text-sm font-bold text-slate-900"><?= h($acceptance->cardholder_name) ?></p>
              <p class="text-xs text-slate-500 font-mono"><?= h($acceptance->card_type) ?> &middot; <?= h($acceptance->maskedCard()) ?></p>
            </div>
          </div>

          <?php if ($acceptance->statement_descriptor): ?>
          <p class="text-[10px] text-slate-400 font-medium px-1">
            Statement descriptor: <span class="font-semibold text-slate-600"><?= h($acceptance->statement_descriptor) ?></span>
          </p>
          <?php endif; ?>
        </div>

        <!-- ── 4. DOCUMENT UPLOADS ── -->
        <?php if ($acceptance->req_passport || $acceptance->req_cc_front): ?>
        <div class="space-y-3">
          <h2 class="text-sm font-bold text-slate-800 flex items-center gap-2" style="font-family:Manrope,sans-serif;">
            <span class="w-5 h-5 rounded-full bg-primary-600 text-white text-[10px] font-black flex items-center justify-center flex-none">4</span>
            Required Documents
          </h2>
          <?php if ($acceptance->req_passport): ?>
          <div>
            <div class="upload-zone border-2 border-dashed border-slate-300 rounded-xl p-5 text-center" id="zone-passport" onclick="document.getElementById('passport_file').click()">
              <span class="material-symbols-outlined text-3xl text-slate-400 mb-2 block">badge</span>
              <p class="text-sm font-bold text-slate-700">Passport / Government-Issued ID</p>
              <p class="text-xs text-slate-400 mt-1">JPG, PNG or PDF &mdash; max 10MB</p>
              <p id="passport-name" class="text-xs font-semibold text-emerald-600 mt-2 hidden"></p>
            </div>
            <input type="file" id="passport_file" name="passport_file" class="hidden"
              accept="image/jpeg,image/png,image/gif,application/pdf"
              onchange="handleUpload(this,'zone-passport','passport-name')">
          </div>
          <?php endif; ?>
          <?php if ($acceptance->req_cc_front): ?>
          <div>
            <div class="upload-zone border-2 border-dashed border-slate-300 rounded-xl p-5 text-center" id="zone-card" onclick="document.getElementById('card_file').click()">
              <span class="material-symbols-outlined text-3xl text-slate-400 mb-2 block">credit_card</span>
              <p class="text-sm font-bold text-slate-700">Credit Card Front Image</p>
              <p class="text-xs text-slate-400 mt-1">JPG or PNG &mdash; max 10MB</p>
              <p id="card-name" class="text-xs font-semibold text-emerald-600 mt-2 hidden"></p>
            </div>
            <input type="file" id="card_file" name="card_file" class="hidden"
              accept="image/jpeg,image/png"
              onchange="handleUpload(this,'zone-card','card-name')">
          </div>
          <?php endif; ?>
        </div>
        <?php
        $docStep = 5;
        $sigStep = 5;
        $conStep = 6;
        else:
        $docStep = null;
        $sigStep = 4;
        $conStep = 5;
        endif; ?>

        <!-- ── SIGNATURE (Step 4 or 5) ── -->
        <div class="space-y-3">
          <h2 class="text-sm font-bold text-slate-800 flex items-center gap-2" style="font-family:Manrope,sans-serif;">
            <span class="w-5 h-5 rounded-full bg-primary-600 text-white text-[10px] font-black flex items-center justify-center flex-none"><?= $sigStep ?></span>
            Digital Signature
          </h2>

          <!-- Authorization Policy -->
          <div class="bg-slate-50 border border-slate-200 rounded-xl p-4 max-h-52 overflow-y-auto">
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-2">Authorization Policy — Please Read</p>
            <pre class="text-[11px] text-slate-600 whitespace-pre-wrap font-sans leading-relaxed"><?= h($acceptance->policy_text ?? '') ?></pre>
          </div>

          <!-- Signature Canvas -->
          <div>
            <div class="flex items-center justify-between mb-2">
              <p class="text-xs font-bold text-slate-600">Sign here (mouse or finger):</p>
              <button type="button" onclick="clearSig()" class="text-[11px] text-slate-400 hover:text-rose-600 font-semibold transition-colors inline-flex items-center gap-1">
                <span class="material-symbols-outlined text-sm">delete</span> Clear
              </button>
            </div>
            <canvas id="sig-canvas" width="560" height="160"></canvas>
            <p id="sig-hint" class="text-[10px] text-slate-400 mt-1 text-center">Draw your signature above</p>
          </div>
        </div>

        <!-- ── CONSENT ── -->
        <div class="space-y-3">
          <h2 class="text-sm font-bold text-slate-800 flex items-center gap-2" style="font-family:Manrope,sans-serif;">
            <span class="w-5 h-5 rounded-full bg-primary-600 text-white text-[10px] font-black flex items-center justify-center flex-none"><?= $conStep ?></span>
            Confirm Authorization
          </h2>
          <label class="consent-box flex items-start gap-3 p-4 bg-primary-50 border-2 border-primary-100 hover:border-primary-600 rounded-xl cursor-pointer" id="consent-label">
            <input type="checkbox" name="consent" id="consent-check" value="1" class="mt-0.5 w-5 h-5 rounded accent-primary flex-none" onchange="updateConsentStyle()">
            <div>
              <p class="text-sm font-bold text-primary-900 leading-tight">I confirm I have read, understood, and agree to the above authorization policy.</p>
              <p class="text-xs text-primary-600 mt-0.5 leading-relaxed">
                By checking and signing, I authorize <strong><?= h(AcceptanceRequest::COMPANY_NAME) ?></strong> to charge
                <strong><?= h($acceptance->currency) ?> <?= number_format($acceptance->total_amount, 2) ?></strong>
                to my <?= h($acceptance->card_type) ?> card ending in <?= h($acceptance->card_last_four) ?>.
              </p>
            </div>
          </label>
          <div id="consent-error" class="hidden text-rose-600 text-xs font-medium flex items-center gap-1.5">
            <span class="material-symbols-outlined text-sm">error</span>
            Please check the box to confirm your authorization.
          </div>
          <div id="sig-error" class="hidden text-rose-600 text-xs font-medium flex items-center gap-1.5">
            <span class="material-symbols-outlined text-sm">draw</span>
            Please provide your digital signature above.
          </div>
        </div>

        <!-- ── SUBMIT ── -->
        <button type="button" onclick="submitAuth()" id="btn-submit"
          class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-black text-base py-4 rounded-xl transition-colors shadow-lg flex items-center justify-center gap-2">
          <span class="material-symbols-outlined">verified</span>
          Submit Authorization
        </button>

        <!-- Security notice -->
        <div class="flex items-start gap-3 p-4 bg-slate-50 border border-slate-200 rounded-xl">
          <span class="material-symbols-outlined text-slate-400 text-base flex-none mt-0.5">security</span>
          <div class="text-[10px] text-slate-500 leading-relaxed">
            <strong class="text-slate-700">Forensic Security Notice:</strong> By submitting, your IP address, browser fingerprint, and digital signature will be securely recorded as evidence of your authorization.
            This information may be used to defend against credit card disputes or chargebacks.
          </div>
        </div>

        <!-- Footer -->
        <div class="text-center pt-2">
          <p class="text-[10px] text-slate-400">
            <?= h(AcceptanceRequest::COMPANY_NAME) ?> &mdash;
            <a href="mailto:<?= h(AcceptanceRequest::COMPANY_EMAIL) ?>" class="hover:underline"><?= h(AcceptanceRequest::COMPANY_EMAIL) ?></a>
          </p>
        </div>

      </div><!-- /p-6 -->
    </form>
  </div><!-- /glass-card -->
</div><!-- /portal-bg -->

<script>
// ─── Signature Pad ────────────────────────────────────────────────────────────
const canvas = document.getElementById('sig-canvas');
const ctx    = canvas.getContext('2d');
let drawing  = false;
let hasSig   = false;

function resizeCanvas() {
  const rect = canvas.getBoundingClientRect();
  const ratio = window.devicePixelRatio || 1;
  const data  = ctx.getImageData(0, 0, canvas.width, canvas.height);
  canvas.width  = rect.width  * ratio;
  canvas.height = 160         * ratio;
  canvas.style.height = '160px';
  ctx.scale(ratio, ratio);
  ctx.putImageData(data, 0, 0);
  ctx.strokeStyle = '#0f1e3c';
  ctx.lineWidth   = 2;
  ctx.lineCap     = 'round';
  ctx.lineJoin    = 'round';
}
resizeCanvas();
window.addEventListener('resize', resizeCanvas);

function getPos(e) {
  const rect  = canvas.getBoundingClientRect();
  const touch = e.touches ? e.touches[0] : e;
  return {
    x: (touch.clientX - rect.left),
    y: (touch.clientY - rect.top)
  };
}

canvas.addEventListener('mousedown',  e => { drawing = true; ctx.beginPath(); const p = getPos(e); ctx.moveTo(p.x, p.y); canvas.classList.add('active'); });
canvas.addEventListener('mousemove',  e => { if (!drawing) return; const p = getPos(e); ctx.lineTo(p.x, p.y); ctx.stroke(); hasSig = true; });
canvas.addEventListener('mouseup',    () => { drawing = false; canvas.classList.remove('active'); if (hasSig) canvas.classList.add('signed'); updateSigHint(); });
canvas.addEventListener('mouseleave', () => { drawing = false; });

canvas.addEventListener('touchstart', e => { e.preventDefault(); drawing = true; ctx.beginPath(); const p = getPos(e); ctx.moveTo(p.x, p.y); canvas.classList.add('active'); }, { passive:false });
canvas.addEventListener('touchmove',  e => { e.preventDefault(); if (!drawing) return; const p = getPos(e); ctx.lineTo(p.x, p.y); ctx.stroke(); hasSig = true; }, { passive:false });
canvas.addEventListener('touchend',   () => { drawing = false; canvas.classList.remove('active'); if (hasSig) canvas.classList.add('signed'); updateSigHint(); });

function clearSig() {
  const ratio = window.devicePixelRatio || 1;
  ctx.clearRect(0, 0, canvas.width / ratio, canvas.height / ratio);
  hasSig = false;
  canvas.classList.remove('signed','active');
  document.getElementById('sig-hint').textContent = 'Draw your signature above';
  document.getElementById('hid-signature').value  = '';
}

function updateSigHint() {
  if (hasSig) {
    document.getElementById('sig-hint').textContent = '✓ Signature captured';
    document.getElementById('sig-hint').className   = 'text-[10px] text-emerald-600 font-semibold mt-1 text-center';
    document.getElementById('sig-error').classList.add('hidden');
  }
}

// ─── Countdown Timer ──────────────────────────────────────────────────────────
(function() {
  const expiresAt = new Date('<?= $acceptance->expires_at->toIso8601String() ?>').getTime();
  const el = document.getElementById('countdown');
  if (!el) return;
  function tick() {
    const diff = expiresAt - Date.now();
    if (diff <= 0) { el.textContent = 'EXPIRED'; return; }
    const h = Math.floor(diff / 3600000);
    const m = Math.floor((diff % 3600000) / 60000);
    const s = Math.floor((diff % 60000) / 1000);
    el.textContent = (h>0?h+'h ':'') + m + 'm ' + s + 's';
    setTimeout(tick, 1000);
  }
  tick();
})();

// ─── Device Fingerprint ───────────────────────────────────────────────────────
(function() {
  const fp = [
    navigator.userAgent,
    navigator.language,
    navigator.platform,
    screen.width + 'x' + screen.height,
    new Date().getTimezoneOffset(),
    navigator.hardwareConcurrency || 0,
    typeof window.devicePixelRatio !== 'undefined' ? window.devicePixelRatio : 1,
  ].join('||');
  document.getElementById('hid-fingerprint').value = btoa(fp).substring(0, 128);
})();

// ─── File Upload Handler ──────────────────────────────────────────────────────
function handleUpload(input, zoneId, nameId) {
  const zone    = document.getElementById(zoneId);
  const nameEl  = document.getElementById(nameId);
  if (input.files && input.files[0]) {
    const file = input.files[0];
    zone.classList.add('has-file');
    nameEl.textContent = '✓ ' + file.name;
    nameEl.classList.remove('hidden');
  } else {
    zone.classList.remove('has-file');
    nameEl.classList.add('hidden');
  }
}

// ─── Consent Styling ──────────────────────────────────────────────────────────
function updateConsentStyle() {
  const checked = document.getElementById('consent-check').checked;
  const label   = document.getElementById('consent-label');
  label.style.borderColor = checked ? '#0f1e3c' : '';
  label.style.background  = checked ? '#dde8ff' : '';
  if (checked) document.getElementById('consent-error').classList.add('hidden');
}

// ─── Submit ───────────────────────────────────────────────────────────────────
function submitAuth() {
  let valid = true;

  // Check consent
  const consent = document.getElementById('consent-check').checked;
  if (!consent) {
    document.getElementById('consent-error').classList.remove('hidden');
    document.getElementById('consent-label').scrollIntoView({ behavior:'smooth', block:'center' });
    valid = false;
  } else {
    document.getElementById('consent-error').classList.add('hidden');
  }

  // Check signature
  if (!hasSig) {
    document.getElementById('sig-error').classList.remove('hidden');
    canvas.scrollIntoView({ behavior:'smooth', block:'center' });
    valid = false;
  } else {
    document.getElementById('sig-error').classList.add('hidden');
  }

  if (!valid) return;

  // Capture signature PNG
  document.getElementById('hid-signature').value = canvas.toDataURL('image/png');

  // Lock button
  const btn = document.getElementById('btn-submit');
  btn.disabled = true;
  btn.innerHTML = '<span class="material-symbols-outlined animate-spin">progress_activity</span> Submitting...';
  btn.classList.add('opacity-75');

  document.getElementById('auth-form').submit();
}
</script>
</body>
</html>
