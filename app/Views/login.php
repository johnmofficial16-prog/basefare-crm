<?php
/**
 * Sign-in page — Peacock design (Oct 2026 theme).
 * Self-contained: no Tailwind runtime, nothing loaded from outside except the font.
 *
 * @var ?string $error     Shown above the form
 * @var ?string $redirect  Where to go after signing in
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<script src="<?= \App\Services\Asset::url('assets/js/error-beacon.js') ?>"></script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&amp;family=Source+Serif+4:opsz,wght@8..60,500;8..60,600&amp;display=swap" rel="stylesheet"/>
<title>Sign in — Base Fare CRM</title>
<?php require __DIR__ . '/partials/signin_style.php'; ?>
</head>
<body>
<main class="card">
  <div class="brand">
    <img src="/assets/img/basefare-logo-email.png" alt="Base Fare logo">
    <div><b>Base Fare</b><span>Sales desk</span></div>
  </div>
  <div>
    <h1>Welcome back</h1>
    <p class="lead">Sign in to start your shift.</p>
  </div>

  <?php if (isset($error) && $error): ?>
  <div class="err" role="alert"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <form method="POST" action="/login">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
    <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect ?? '/dashboard') ?>">
    <label for="email">Work email
      <input id="email" name="email" type="email" placeholder="you@base-fare.com" autocomplete="username" required autofocus>
    </label>
    <label for="password">Password
      <span class="field">
        <input id="password" name="password" type="password" placeholder="Your password" autocomplete="current-password" required>
        <button type="button" class="eye" aria-label="Show password" aria-pressed="false"
                onclick="var p=document.getElementById('password'),s=p.type==='password';p.type=s?'text':'password';this.setAttribute('aria-pressed',s?'true':'false');this.setAttribute('aria-label',s?'Hide password':'Show password');">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>
        </button>
      </span>
    </label>
    <button type="submit" class="go">Sign in</button>
  </form>

  <p class="help">Forgot your password or locked out? Ask your team leader or the admin to reset it.</p>
  <p class="foot">© <?= date('Y') ?> Base Fare</p>
</main>
</body>
</html>
