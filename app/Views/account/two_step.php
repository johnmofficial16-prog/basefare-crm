<?php
/**
 * Admin sign-in, second step (TwoStepController).
 *
 * @var string  $mode       setup | code | backup
 * @var string  $firstName
 * @var ?string $error
 * @var string  $secret     setup: the key to type in by hand
 * @var string  $setupUri   setup: otpauth:// link drawn as the QR code
 * @var array   $codes      backup: the one-time backup codes
 * @var string  $next       backup: where to go afterwards
 */
$e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$titles = ['setup' => 'Set up your sign-in app', 'code' => 'Enter your sign-in code', 'backup' => 'Save your backup codes'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<meta name="robots" content="noindex, nofollow">
<script src="<?= \App\Services\Asset::url('assets/js/error-beacon.js') ?>"></script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&amp;family=Source+Serif+4:opsz,wght@8..60,500;8..60,600&amp;display=swap" rel="stylesheet"/>
<title><?= $e($titles[$mode]) ?> — Base Fare CRM</title>
<?php require __DIR__ . '/../partials/signin_style.php'; ?>
<style>
  .qr { background: #fff; border-radius: 14px; padding: 12px; width: 220px; margin: 4px auto 0; }
  .qr svg { display: block; width: 100%; height: auto; }
  .key { font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 15px; letter-spacing: .08em; word-break: break-all;
         background: rgba(255,255,255,.06); border: 1px solid rgba(255,255,255,.14); border-radius: 10px; padding: 10px 12px; color: #EAF0FA; text-align: center; }
  .steps { margin: 0; padding-left: 22px; color: #C9D4E8; font-size: 15px; line-height: 1.6; }
  .codes { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
  .codes span { font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 17px; text-align: center; padding: 10px;
                border-radius: 10px; background: rgba(255,255,255,.06); border: 1px solid rgba(255,255,255,.14); color: #F2F6FC; }
  .check { flex-direction: row; align-items: center; gap: 10px; font-weight: 500; }
  .check input { width: 18px; height: 18px; }
  input.otp { font-size: 24px; letter-spacing: .3em; text-align: center; }
</style>
</head>
<body>
<main class="card">
  <div class="brand">
    <img src="/assets/img/basefare-logo-email.png" alt="Base Fare logo">
    <div><b>Base Fare</b><span>Sales desk</span></div>
  </div>

<?php if ($mode === 'setup'): ?>
  <div>
    <h1><?= $e($titles['setup']) ?></h1>
    <p class="lead">Admin accounts need a second step to sign in. You only do this once per phone.</p>
  </div>
  <ol class="steps">
    <li>On your phone, install <b>Google Authenticator</b> (or Microsoft Authenticator).</li>
    <li>In the app, tap <b>+</b> then <b>Scan a QR code</b>, and scan this:</li>
  </ol>
  <div class="qr" id="qr" role="img" aria-label="QR code for the authenticator app"></div>
  <p class="help">Setting up on this phone? <a href="<?= $e($setupUri) ?>" style="color:#9CC3FF;font-weight:600">Tap here to add it to your app</a>.</p>
  <p class="help">Can't scan? Choose <b>Enter a setup key</b> in the app and type:</p>
  <div class="key"><?= $e(trim(chunk_split($secret, 4, ' '))) ?></div>
  <p class="help">Sharing this account? Scan the same code on each person's phone now — every phone will show the same codes.</p>
<?php elseif ($mode === 'code'): ?>
  <div>
    <h1><?= $e($titles['code']) ?></h1>
    <p class="lead">Hi <?= $e($firstName) ?>, open your authenticator app and type the 6-digit code for <b>Base Fare CRM</b>.</p>
  </div>
<?php else: ?>
  <div>
    <h1><?= $e($titles['backup']) ?></h1>
    <p class="lead">If a phone is lost, each of these codes signs you in once instead of the app code. Write them down or keep them somewhere safe — they won't be shown again.</p>
  </div>
  <div class="codes"><?php foreach ($codes as $c): ?><span><?= $e($c) ?></span><?php endforeach; ?></div>
  <a class="go" href="<?= $e($next) ?>" style="display:flex;align-items:center;justify-content:center;text-decoration:none;">I've saved them — continue</a>
<?php endif; ?>

<?php if (!empty($error)): ?>
  <div class="err" role="alert"><?= $e($error) ?></div>
<?php endif; ?>

<?php if ($mode !== 'backup'): ?>
  <form method="POST" action="/login/two-step">
    <input type="hidden" name="csrf_token" value="<?= $e($_SESSION['csrf_token'] ?? '') ?>">
    <label for="code"><?= $mode === 'setup' ? 'Then type the 6-digit code the app shows' : 'Code' ?>
      <input id="code" name="code" class="otp" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="9"
             placeholder="123456" required autofocus>
    </label>
    <label class="check" for="remember">
      <input id="remember" name="remember" type="checkbox" value="1">
      Remember this computer for <?= \App\Services\TwoStep::REMEMBER_DAYS ?> days
    </label>
    <button type="submit" class="go"><?= $mode === 'setup' ? 'Finish setup' : 'Sign in' ?></button>
  </form>
  <?php if ($mode === 'code'): ?>
  <p class="help">Lost your phone? Type one of your backup codes instead.</p>
  <?php endif; ?>
  <p class="help"><a href="/logout" style="color:inherit">Cancel and sign out</a></p>
<?php endif; ?>
</main>
<?php if ($mode === 'setup'): ?>
<script type="module">
  import { qrSvg } from '/assets/js/qr.js';
  document.getElementById('qr').innerHTML = qrSvg(<?= json_encode($setupUri, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>, { label: 'Scan with your authenticator app', dark: '#0B1220' });
</script>
<?php endif; ?>
</body>
</html>
