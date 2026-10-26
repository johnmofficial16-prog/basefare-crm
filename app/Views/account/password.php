<?php
/**
 * Choose your own password — shown after sign-in when someone else reset it.
 *
 * @var ?string $error
 * @var string  $firstName
 */
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
<title>Choose your password — Base Fare CRM</title>
<?php require __DIR__ . '/../partials/signin_style.php'; ?>
</head>
<body>
<main class="card">
  <div class="brand">
    <img src="/assets/img/basefare-logo-email.png" alt="Base Fare logo">
    <div><b>Base Fare</b><span>Sales desk</span></div>
  </div>
  <div>
    <h1>Choose your password</h1>
    <p class="lead">Hi <?= htmlspecialchars($firstName) ?>, your password was reset for you. Pick your own now — only you will know it.</p>
  </div>

  <?php if (!empty($error)): ?>
  <div class="err" role="alert"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <form method="POST" action="/account/password">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
    <input type="text" name="username" autocomplete="username" value="<?= htmlspecialchars($_SESSION['user_email'] ?? '') ?>" hidden>
    <label for="current">Password you were given
      <input id="current" name="current" type="password" autocomplete="current-password" required autofocus>
    </label>
    <label for="new">New password
      <input id="new" name="new" type="password" autocomplete="new-password" minlength="10" required placeholder="At least 10 characters">
    </label>
    <label for="confirm">Type it again
      <input id="confirm" name="confirm" type="password" autocomplete="new-password" minlength="10" required>
    </label>
    <button type="submit" class="go">Save and continue</button>
  </form>

  <p class="help"><a href="/logout" style="color:inherit">Sign out</a></p>
</main>
</body>
</html>
