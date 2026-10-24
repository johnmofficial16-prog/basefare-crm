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
<link href="https://fonts.googleapis.com/css2?family=Hanken+Grotesk:wght@400;500;600;700;800&amp;display=swap" rel="stylesheet"/>
<title>Sign in — Base Fare CRM</title>
<style>
  * { box-sizing: border-box; }
  html, body { margin: 0; min-height: 100%; }
  body {
    min-height: 100vh; display: flex; align-items: center; padding: 32px 16px;
    font-family: 'Hanken Grotesk', system-ui, -apple-system, 'Segoe UI', sans-serif; color: #E6ECF7;
    background: #05070D url('<?= \App\Services\Asset::url('assets/img/login-peacock.jpg') ?>') right center / cover no-repeat;
  }
  .card {
    width: 100%; max-width: 460px; margin: 0 auto; padding: 40px 36px;
    border-radius: 22px; background: rgba(8, 12, 22, 0.9); border: 1px solid #1E2A42;
    box-shadow: 0 30px 80px -20px rgba(0, 0, 0, 0.7); display: flex; flex-direction: column; gap: 20px;
  }
  @media (min-width: 1100px) { .card { margin: 0 0 0 120px; padding: 44px; } }
  .brand { display: flex; align-items: center; gap: 14px; }
  .brand img { width: 52px; height: 52px; border-radius: 14px; display: block; }
  .brand b { display: block; font-size: 22px; font-weight: 800; color: #FFFFFF; }
  .brand span { display: block; font-size: 14px; color: #8FA0BF; }
  h1 { margin: 6px 0 0; font-size: 30px; font-weight: 800; letter-spacing: -0.02em; color: #FFFFFF; }
  .lead { margin: 6px 0 0; font-size: 16px; color: #A9B6CF; }
  .err { display: flex; gap: 10px; align-items: flex-start; padding: 12px 14px; border-radius: 12px;
    background: #3A1620; border: 1px solid #5C2433; color: #FECDD3; font-size: 15px; font-weight: 600; }
  form { display: flex; flex-direction: column; gap: 18px; margin: 0; }
  label { display: flex; flex-direction: column; gap: 8px; font-size: 15px; font-weight: 600; color: #D7E0F0; }
  .field { position: relative; }
  input[type=email], input[type=password], input[type=text] {
    width: 100%; height: 52px; padding: 0 16px; border-radius: 12px; border: 1px solid #2A3756;
    background: #0F1626; color: #FFFFFF; font: inherit; font-size: 16px; font-weight: 500;
  }
  input::placeholder { color: #7383A3; }
  input:focus { outline: none; border-color: #4D8DF5; box-shadow: 0 0 0 3px rgba(31, 111, 235, 0.35); }
  .field input { padding-right: 52px; }
  .eye { position: absolute; right: 6px; top: 6px; width: 40px; height: 40px; border: 0; border-radius: 10px;
    background: transparent; color: #8FA0BF; display: flex; align-items: center; justify-content: center; cursor: pointer; }
  .eye:hover { color: #FFFFFF; background: #18223A; }
  .eye:focus-visible { outline: 2px solid #4D8DF5; }
  button.go { height: 54px; border: 0; border-radius: 12px; background: #1F6FEB; color: #FFFFFF;
    font: inherit; font-size: 17px; font-weight: 700; cursor: pointer; margin-top: 4px; }
  button.go:hover { background: #1A5FD0; }
  button.go:focus-visible { outline: 3px solid #9CC3FF; outline-offset: 2px; }
  .help { margin: 0; font-size: 14px; color: #8FA0BF; text-align: center; line-height: 1.5; }
  .foot { margin: 0; font-size: 13px; color: #6F7E9C; text-align: center; }
</style>
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
