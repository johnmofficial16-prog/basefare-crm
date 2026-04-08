<?php
/**
 * User Management — Create User Form
 *
 * @var string $activePage
 * @var string|null $flashError
 * @var array  $old          Repopulation after failed submit
 */
$old = $old ?? [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Add User — Base Fare CRM</title>
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
.field-label { display:block; font-size:10px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:0.05em; margin-bottom:0.375rem; }
.field-input { width:100%; border:1px solid #e2e8f0; border-radius:0.5rem; padding:0.5rem 0.75rem; font-size:0.875rem; background:#f8fafc; outline:none; transition:all .15s; }
.field-input:focus { box-shadow:0 0 0 2px rgba(15,30,60,0.2); border-color:rgba(15,30,60,0.4); }
</style>
</head>
<body class="bg-[#f8f9fa] font-sans text-slate-900 antialiased min-h-screen">

<?php $activePage = 'users'; require __DIR__ . '/../partials/admin_sidebar.php'; ?>

<main class="ml-60 pt-6 pb-20 px-8">

  <!-- Page Header -->
  <div class="flex items-center justify-between mb-6">
    <div>
      <h1 class="text-2xl font-extrabold text-primary tracking-tight flex items-center gap-2" style="font-family:Manrope">
        <span class="material-symbols-outlined text-2xl">person_add</span> Add User
      </h1>
      <p class="text-sm text-slate-500 mt-0.5">Create a new agent, manager, or admin account.</p>
    </div>
    <a href="/users" class="inline-flex items-center gap-1.5 text-sm font-semibold text-slate-500 hover:text-primary transition-colors">
      <span class="material-symbols-outlined text-sm">arrow_back</span> Back to Users
    </a>
  </div>

  <?php if ($flashError): ?>
  <div class="mb-5 px-4 py-3 bg-red-50 border border-red-200 rounded-xl text-sm font-semibold text-red-700 flex items-center gap-2">
    <span class="material-symbols-outlined text-base">error</span> <?= htmlspecialchars($flashError) ?>
  </div>
  <?php endif; ?>

  <div class="max-w-2xl">
    <form method="POST" action="/users/create" id="createUserForm">
      <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">

      <!-- Profile Details -->
      <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden mb-5">
        <div class="px-6 py-4 border-b border-slate-100 bg-slate-50/50">
          <h2 class="font-bold text-slate-900 flex items-center gap-2" style="font-family:Manrope">
            <span class="material-symbols-outlined text-base">badge</span> Profile Details
          </h2>
        </div>
        <div class="p-6 space-y-4">

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label class="field-label" for="name">Full Name <span class="text-rose-500">*</span></label>
              <input class="field-input" type="text" id="name" name="name"
                     value="<?= htmlspecialchars($old['name'] ?? '') ?>"
                     placeholder="e.g. Sarah Johnson" autocomplete="off" required>
            </div>
            <div>
              <label class="field-label" for="email">Email Address <span class="text-rose-500">*</span></label>
              <input class="field-input" type="email" id="email" name="email"
                     value="<?= htmlspecialchars($old['email'] ?? '') ?>"
                     placeholder="agent@base-fare.com" autocomplete="off" required>
            </div>
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label class="field-label" for="role">Role <span class="text-rose-500">*</span></label>
              <select class="field-input" id="role" name="role" required>
                <option value="agent"   <?= ($old['role'] ?? 'agent') === 'agent'   ? 'selected' : '' ?>>Agent</option>
                <option value="manager" <?= ($old['role'] ?? '') === 'manager' ? 'selected' : '' ?>>Manager</option>
                <option value="admin"   <?= ($old['role'] ?? '') === 'admin'   ? 'selected' : '' ?>>Admin</option>
              </select>
              <p class="text-[10px] text-slate-400 mt-1">Agents record transactions. Managers see team data. Admins have full access.</p>
            </div>
            <div>
              <label class="field-label" for="grace_period_mins">Late Login Grace Period (minutes)</label>
              <input class="field-input" type="number" id="grace_period_mins" name="grace_period_mins"
                     value="<?= (int) ($old['grace_period_mins'] ?? 30) ?>"
                     min="0" max="120" placeholder="30">
              <p class="text-[10px] text-slate-400 mt-1">How many minutes late before clock-in is blocked. Default: 30.</p>
            </div>
          </div>

        </div>
      </div>

      <!-- Login Credentials -->
      <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden mb-5">
        <div class="px-6 py-4 border-b border-slate-100 bg-slate-50/50">
          <h2 class="font-bold text-slate-900 flex items-center gap-2" style="font-family:Manrope">
            <span class="material-symbols-outlined text-base">lock</span> Login Credentials
          </h2>
        </div>
        <div class="p-6 space-y-4">

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label class="field-label" for="password">Password <span class="text-rose-500">*</span></label>
              <div class="relative">
                <input class="field-input pr-10" type="password" id="password" name="password"
                       placeholder="Min. 8 characters" autocomplete="new-password" required>
                <button type="button" onclick="togglePw('password', this)" tabindex="-1"
                        class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600">
                  <span class="material-symbols-outlined text-lg">visibility</span>
                </button>
              </div>
            </div>
            <div>
              <label class="field-label" for="password_confirm">Confirm Password <span class="text-rose-500">*</span></label>
              <div class="relative">
                <input class="field-input pr-10" type="password" id="password_confirm" name="password_confirm"
                       placeholder="Repeat password" autocomplete="new-password" required>
                <button type="button" onclick="togglePw('password_confirm', this)" tabindex="-1"
                        class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600">
                  <span class="material-symbols-outlined text-lg">visibility</span>
                </button>
              </div>
            </div>
          </div>

          <!-- Strength indicator -->
          <div id="pw-strength-wrap" class="hidden">
            <div class="flex gap-1 mb-1">
              <div id="pw-bar-1" class="h-1.5 flex-1 rounded-full bg-slate-200 transition-colors"></div>
              <div id="pw-bar-2" class="h-1.5 flex-1 rounded-full bg-slate-200 transition-colors"></div>
              <div id="pw-bar-3" class="h-1.5 flex-1 rounded-full bg-slate-200 transition-colors"></div>
              <div id="pw-bar-4" class="h-1.5 flex-1 rounded-full bg-slate-200 transition-colors"></div>
            </div>
            <p id="pw-strength-label" class="text-[10px] text-slate-400"></p>
          </div>

          <p id="confirm-error" class="hidden text-rose-600 text-xs font-medium flex items-center gap-1">
            <span class="material-symbols-outlined text-sm">error</span> Passwords do not match.
          </p>

          <div class="bg-amber-50 border border-amber-200 rounded-lg px-4 py-3 text-xs text-amber-800 flex gap-2">
            <span class="material-symbols-outlined text-base flex-shrink-0 mt-0.5">info</span>
            <span>Share these credentials with the user securely. They should change their password after first login.</span>
          </div>
        </div>
      </div>

      <!-- Submit -->
      <div class="flex items-center justify-end gap-3">
        <a href="/users" class="px-5 py-2.5 border border-slate-200 text-slate-600 font-semibold text-sm rounded-lg hover:bg-slate-50 transition-colors">Cancel</a>
        <button type="submit" id="submit-btn"
                class="inline-flex items-center gap-2 bg-primary text-white font-bold py-2.5 px-6 rounded-lg text-sm hover:bg-primary/90 transition-colors shadow-sm">
          <span class="material-symbols-outlined text-base">person_add</span> Create User
        </button>
      </div>

    </form>
  </div>

</main>

<script>
function togglePw(fieldId, btn) {
  var field = document.getElementById(fieldId);
  var icon  = btn.querySelector('.material-symbols-outlined');
  if (field.type === 'password') {
    field.type = 'text';
    icon.textContent = 'visibility_off';
  } else {
    field.type = 'password';
    icon.textContent = 'visibility';
  }
}

// Password strength meter
document.getElementById('password').addEventListener('input', function() {
  var val  = this.value;
  var wrap = document.getElementById('pw-strength-wrap');
  if (!val) { wrap.classList.add('hidden'); return; }
  wrap.classList.remove('hidden');

  var score = 0;
  if (val.length >= 8)                      score++;
  if (/[A-Z]/.test(val))                    score++;
  if (/[0-9]/.test(val))                    score++;
  if (/[^A-Za-z0-9]/.test(val))             score++;

  var colors = ['bg-rose-400', 'bg-amber-400', 'bg-yellow-400', 'bg-emerald-500'];
  var labels = ['Weak', 'Fair', 'Good', 'Strong'];
  var bars   = ['pw-bar-1', 'pw-bar-2', 'pw-bar-3', 'pw-bar-4'];

  bars.forEach(function(id, i) {
    var el = document.getElementById(id);
    el.className = 'h-1.5 flex-1 rounded-full transition-colors ' + (i < score ? colors[score - 1] : 'bg-slate-200');
  });
  document.getElementById('pw-strength-label').textContent = labels[score - 1] || '';
});

// Match check
document.getElementById('password_confirm').addEventListener('input', function() {
  var pass    = document.getElementById('password').value;
  var errEl   = document.getElementById('confirm-error');
  if (this.value && this.value !== pass) {
    errEl.classList.remove('hidden');
  } else {
    errEl.classList.add('hidden');
  }
});

// Client-side submit guard
document.getElementById('createUserForm').addEventListener('submit', function(e) {
  var pass    = document.getElementById('password').value;
  var confirm = document.getElementById('password_confirm').value;
  if (pass.length < 8) {
    e.preventDefault();
    alert('Password must be at least 8 characters.');
    return;
  }
  if (pass !== confirm) {
    e.preventDefault();
    document.getElementById('confirm-error').classList.remove('hidden');
    document.getElementById('password_confirm').focus();
    return;
  }
  var btn = document.getElementById('submit-btn');
  btn.disabled = true;
  btn.innerHTML = '<span class="material-symbols-outlined text-base animate-spin">progress_activity</span> Creating…';
});
</script>

</body>
</html>
