<?php
use App\Models\User;

/**
 * User Management — Edit User Form
 *
 * @var User   $user         The user being edited
 * @var string $activePage
 * @var string|null $flashError
 * @var array  $old          Repopulation after failed submit
 */
$old = $old ?? [];
// Prefer $old values on re-submit, otherwise fall back to $user model
$val = function(string $key) use ($old, $user) {
    return htmlspecialchars($old[$key] ?? $user->$key ?? '');
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Edit User — Base Fare CRM</title>
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
        <span class="material-symbols-outlined text-2xl">manage_accounts</span> Edit User
      </h1>
      <p class="text-sm text-slate-500 mt-0.5">Editing account for <strong><?= htmlspecialchars($user->name) ?></strong></p>
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

  <!-- Status Banner (if suspended) -->
  <?php if ($user->status === 'suspended'): ?>
  <div class="mb-5 px-4 py-3 bg-rose-50 border border-rose-200 rounded-xl text-sm font-semibold text-rose-700 flex items-center gap-2">
    <span class="material-symbols-outlined text-base">block</span>
    This account is currently <strong>suspended</strong>. The user cannot log in.
  </div>
  <?php endif; ?>

  <div class="max-w-2xl">
    <form method="POST" action="/users/<?= $user->id ?>/edit" id="editUserForm">
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
                     value="<?= $val('name') ?>" required>
            </div>
            <div>
              <label class="field-label" for="email">Email Address <span class="text-rose-500">*</span></label>
              <input class="field-input" type="email" id="email" name="email"
                     value="<?= $val('email') ?>" required>
            </div>
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label class="field-label" for="role">Role <span class="text-rose-500">*</span></label>
              <select class="field-input" id="role" name="role" required
                <?= $user->id === (int)($_SESSION['user_id'] ?? 0) ? 'disabled title="You cannot change your own role."' : '' ?>>
                <option value="agent"   <?= ($old['role'] ?? $user->role) === 'agent'   ? 'selected' : '' ?>>Agent</option>
                <option value="manager" <?= ($old['role'] ?? $user->role) === 'manager' ? 'selected' : '' ?>>Manager</option>
                <option value="admin"   <?= ($old['role'] ?? $user->role) === 'admin'   ? 'selected' : '' ?>>Admin</option>
              </select>
              <?php if ($user->id === (int)($_SESSION['user_id'] ?? 0)): ?>
              <input type="hidden" name="role" value="<?= htmlspecialchars($user->role) ?>">
              <p class="text-[10px] text-slate-400 mt-1">You cannot change your own role.</p>
              <?php endif; ?>
            </div>
            <div>
              <label class="field-label" for="grace_period_mins">Late Login Grace Period (minutes)</label>
              <input class="field-input" type="number" id="grace_period_mins" name="grace_period_mins"
                     value="<?= (int) ($old['grace_period_mins'] ?? $user->grace_period_mins) ?>"
                     min="0" max="120">
            </div>
          </div>

        </div>
      </div>

      <!-- Metadata Card (read-only) -->
      <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden mb-5">
        <div class="px-6 py-4 border-b border-slate-100 bg-slate-50/50">
          <h2 class="font-bold text-slate-900 flex items-center gap-2" style="font-family:Manrope">
            <span class="material-symbols-outlined text-base">info</span> Account Info
          </h2>
        </div>
        <div class="p-6 grid grid-cols-2 sm:grid-cols-3 gap-4">
          <div>
            <p class="field-label">User ID</p>
            <p class="text-sm font-mono font-semibold text-slate-900">#<?= $user->id ?></p>
          </div>
          <div>
            <p class="field-label">Current Status</p>
            <?php
            $statusClass = match($user->status) {
                'active'    => 'text-emerald-700 font-semibold',
                'suspended' => 'text-rose-600 font-semibold',
                default     => 'text-slate-500',
            };
            ?>
            <p class="text-sm <?= $statusClass ?> capitalize"><?= $user->status ?></p>
          </div>
          <div>
            <p class="field-label">Member Since</p>
            <p class="text-sm text-slate-600"><?= $user->created_at ? date('M j, Y', strtotime($user->created_at)) : '—' ?></p>
          </div>
        </div>
        <div class="px-6 pb-4">
          <p class="text-[10px] text-slate-400">To change the password or suspend this account, use the Actions on the Users list page.</p>
        </div>
      </div>

      <!-- Submit -->
      <div class="flex items-center justify-end gap-3">
        <a href="/users" class="px-5 py-2.5 border border-slate-200 text-slate-600 font-semibold text-sm rounded-lg hover:bg-slate-50 transition-colors">Cancel</a>
        <button type="submit" id="submit-btn"
                class="inline-flex items-center gap-2 bg-primary text-white font-bold py-2.5 px-6 rounded-lg text-sm hover:bg-primary/90 transition-colors shadow-sm">
          <span class="material-symbols-outlined text-base">save</span> Save Changes
        </button>
      </div>

    </form>
  </div>

</main>

<script>
document.getElementById('editUserForm').addEventListener('submit', function() {
  var btn = document.getElementById('submit-btn');
  btn.disabled = true;
  btn.innerHTML = '<span class="material-symbols-outlined text-base animate-spin">progress_activity</span> Saving…';
});
</script>

</body>
</html>
