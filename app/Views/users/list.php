<?php
/**
 * User Management — List Page
 *
 * @var array  $data         { records, total, page, per_page, total_pages }
 * @var array  $filters      Active filter values
 * @var string $activePage
 * @var string|null $flashSuccess
 * @var string|null $flashError
 */

$records    = $data['records'];
$total      = $data['total'];
$page       = $data['page'];
$totalPages = $data['total_pages'];
$filters    = $filters ?? [];

// Build base query string for pagination (preserving filters)
$queryBase = http_build_query(array_filter([
    'search' => $filters['search'] ?? '',
    'role'   => $filters['role'] ?? '',
    'status' => $filters['status'] ?? '',
]));

function userRoleBadge(string $role): string {
    return match($role) {
        'admin'   => '<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider bg-primary/10 text-primary border border-primary/20"><span class="material-symbols-outlined text-[11px]">shield</span>Admin</span>',
        'manager' => '<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider bg-violet-100 text-violet-800 border border-violet-200"><span class="material-symbols-outlined text-[11px]">manage_accounts</span>Manager</span>',
        default   => '<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider bg-slate-100 text-slate-600 border border-slate-200"><span class="material-symbols-outlined text-[11px]">person</span>Agent</span>',
    };
}

function userStatusBadge(string $status): string {
    return match($status) {
        'active'    => '<span class="inline-flex items-center px-2.5 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider bg-emerald-100 text-emerald-800 border border-emerald-200">Active</span>',
        'suspended' => '<span class="inline-flex items-center px-2.5 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider bg-rose-100 text-rose-700 border border-rose-200">Suspended</span>',
        'inactive'  => '<span class="inline-flex items-center px-2.5 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider bg-slate-100 text-slate-500 border border-slate-200">Inactive</span>',
        default     => '<span class="inline-flex items-center px-2.5 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider bg-slate-100 text-slate-500 border border-slate-200">' . htmlspecialchars($status) . '</span>',
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>User Management — Base Fare CRM</title>
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
</head>
<body class="bg-slate-50 font-sans">

<?php $activePage = 'users'; require __DIR__ . '/../partials/admin_sidebar.php'; ?>

<div class="ml-60 min-h-screen">
  <div class="max-w-7xl mx-auto px-8 py-8 space-y-6">

    <!-- Page Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
      <div>
        <p class="text-[10px] font-bold text-primary uppercase tracking-wider mb-1">Admin</p>
        <h1 class="text-2xl font-bold text-slate-900" style="font-family:Manrope">User Management</h1>
        <p class="text-slate-500 text-sm mt-1"><?= number_format($total) ?> user<?= $total !== 1 ? 's' : '' ?> total</p>
      </div>
      <a href="/users/create"
         class="inline-flex items-center gap-2 bg-primary text-white font-bold py-2.5 px-5 rounded-lg text-sm transition-colors shadow-sm hover:bg-primary/90">
        <span class="material-symbols-outlined text-base">person_add</span>
        Add User
      </a>
    </div>

    <!-- Flash Messages -->
    <?php if ($flashSuccess): ?>
    <div class="bg-emerald-50 border border-emerald-200 text-emerald-800 px-4 py-3 rounded-lg text-sm flex items-center gap-2">
      <span class="material-symbols-outlined text-base">check_circle</span>
      <?= htmlspecialchars($flashSuccess) ?>
    </div>
    <?php endif; ?>
    <?php if ($flashError): ?>
    <div class="bg-rose-50 border border-rose-200 text-rose-700 px-4 py-3 rounded-lg text-sm flex items-center gap-2">
      <span class="material-symbols-outlined text-base">error</span>
      <?= htmlspecialchars($flashError) ?>
    </div>
    <?php endif; ?>

    <!-- Filters -->
    <form method="GET" action="/users" class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm">
      <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 items-end">

        <div>
          <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1">Search</label>
          <input type="text" name="search" value="<?= htmlspecialchars($filters['search'] ?? '') ?>"
                 placeholder="Name or email…"
                 class="w-full text-sm border-slate-200 rounded-lg focus:ring-primary focus:border-primary">
        </div>

        <div>
          <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1">Role</label>
          <select name="role" class="w-full text-sm border-slate-200 rounded-lg focus:ring-primary focus:border-primary">
            <option value="">All Roles</option>
            <option value="admin"   <?= ($filters['role'] ?? '') === 'admin'   ? 'selected' : '' ?>>Admin</option>
            <option value="manager" <?= ($filters['role'] ?? '') === 'manager' ? 'selected' : '' ?>>Manager</option>
            <option value="agent"   <?= ($filters['role'] ?? '') === 'agent'   ? 'selected' : '' ?>>Agent</option>
          </select>
        </div>

        <div>
          <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1">Status</label>
          <select name="status" class="w-full text-sm border-slate-200 rounded-lg focus:ring-primary focus:border-primary">
            <option value="">All Statuses</option>
            <option value="active"    <?= ($filters['status'] ?? '') === 'active'    ? 'selected' : '' ?>>Active</option>
            <option value="suspended" <?= ($filters['status'] ?? '') === 'suspended' ? 'selected' : '' ?>>Suspended</option>
            <option value="inactive"  <?= ($filters['status'] ?? '') === 'inactive'  ? 'selected' : '' ?>>Inactive</option>
          </select>
        </div>

        <div class="flex gap-2">
          <button type="submit" class="flex-1 bg-primary text-white px-4 py-2 rounded-lg text-sm font-semibold hover:bg-primary/90 transition-colors flex items-center justify-center gap-1">
            <span class="material-symbols-outlined text-base">search</span> Filter
          </button>
          <a href="/users" class="bg-slate-100 text-slate-600 px-3 py-2 rounded-lg hover:bg-slate-200 transition-colors flex items-center justify-center">
            <span class="material-symbols-outlined text-base">refresh</span>
          </a>
        </div>

      </div>
    </form>

    <!-- Users Table -->
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-left text-sm">
          <thead class="bg-slate-50 border-b border-slate-100 text-slate-500 text-[10px] font-bold uppercase tracking-wider">
            <tr>
              <th class="px-5 py-3">#</th>
              <th class="px-5 py-3">Name</th>
              <th class="px-5 py-3">Email</th>
              <th class="px-5 py-3">Role</th>
              <th class="px-5 py-3">Status</th>
              <th class="px-5 py-3">Grace Period</th>
              <th class="px-5 py-3">Created</th>
              <th class="px-5 py-3 text-right">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <?php if ($records->isEmpty()): ?>
            <tr>
              <td colspan="8" class="py-20 text-center">
                <div class="flex flex-col items-center gap-3 text-slate-400">
                  <span class="material-symbols-outlined text-5xl opacity-30">group</span>
                  <p class="font-semibold text-slate-500">No users found.</p>
                  <a href="/users/create" class="text-primary text-sm font-semibold hover:underline">Add your first user →</a>
                </div>
              </td>
            </tr>
            <?php else: ?>
            <?php foreach ($records as $u): ?>
            <?php
              $isSelf    = ($u->id === (int) ($_SESSION['user_id'] ?? 0));
              $createdAt = $u->created_at ? date('M j, Y', strtotime($u->created_at)) : '—';
            ?>
            <tr class="hover:bg-slate-50/50 transition-colors <?= $u->status === 'suspended' ? 'opacity-60' : '' ?>">
              <td class="px-5 py-3.5 text-slate-400 text-xs font-mono"><?= $u->id ?></td>
              <td class="px-5 py-3.5">
                <div class="flex items-center gap-2.5">
                  <div class="w-8 h-8 rounded-full bg-primary/10 flex items-center justify-center text-primary font-bold text-xs flex-shrink-0">
                    <?= strtoupper(substr($u->name, 0, 1)) ?>
                  </div>
                  <div>
                    <div class="font-semibold text-slate-900">
                      <?= htmlspecialchars($u->name) ?>
                      <?php if ($isSelf): ?>
                      <span class="ml-1 text-[10px] bg-primary/10 text-primary px-1.5 py-0.5 rounded font-bold">You</span>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
              </td>
              <td class="px-5 py-3.5 text-slate-500 text-xs"><?= htmlspecialchars($u->email) ?></td>
              <td class="px-5 py-3.5"><?= userRoleBadge($u->role) ?></td>
              <td class="px-5 py-3.5"><?= userStatusBadge($u->status) ?></td>
              <td class="px-5 py-3.5 text-xs text-slate-500"><?= $u->grace_period_mins ?> min</td>
              <td class="px-5 py-3.5 text-xs text-slate-400 whitespace-nowrap"><?= $createdAt ?></td>
              <td class="px-5 py-3.5">
                <div class="flex items-center gap-1.5 justify-end">
                  <!-- Edit -->
                  <a href="/users/<?= $u->id ?>/edit"
                     class="inline-flex items-center gap-1 bg-slate-50 hover:bg-slate-100 text-slate-700 border border-slate-200 font-semibold py-1.5 px-2.5 rounded-lg text-xs transition-colors">
                    <span class="material-symbols-outlined text-sm">edit</span> Edit
                  </a>

                  <!-- Reset Password -->
                  <?php if (!$isSelf): ?>
                  <button onclick="openResetModal(<?= $u->id ?>, '<?= htmlspecialchars(addslashes($u->name)) ?>')"
                          class="inline-flex items-center gap-1 bg-amber-50 hover:bg-amber-100 text-amber-700 border border-amber-200 font-semibold py-1.5 px-2.5 rounded-lg text-xs transition-colors">
                    <span class="material-symbols-outlined text-sm">lock_reset</span> Reset
                  </button>

                  <!-- Suspend / Reactivate -->
                  <?php if ($u->status === 'active'): ?>
                  <button onclick="toggleStatus(<?= $u->id ?>, 'suspend', '<?= htmlspecialchars(addslashes($u->name)) ?>')"
                          class="inline-flex items-center gap-1 bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-200 font-semibold py-1.5 px-2.5 rounded-lg text-xs transition-colors">
                    <span class="material-symbols-outlined text-sm">block</span>
                  </button>
                  <?php else: ?>
                  <button onclick="toggleStatus(<?= $u->id ?>, 'activate', '<?= htmlspecialchars(addslashes($u->name)) ?>')"
                          class="inline-flex items-center gap-1 bg-emerald-50 hover:bg-emerald-100 text-emerald-700 border border-emerald-200 font-semibold py-1.5 px-2.5 rounded-lg text-xs transition-colors">
                    <span class="material-symbols-outlined text-sm">check_circle</span>
                  </button>
                  <?php endif; ?>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <?php if ($totalPages > 1): ?>
      <div class="px-5 py-4 border-t border-slate-100 bg-slate-50/50 flex items-center justify-between">
        <span class="text-xs text-slate-500">
          Page <?= $page ?> of <?= $totalPages ?> &middot; <?= number_format($total) ?> users
        </span>
        <div class="flex gap-1">
          <?php if ($page > 1): ?>
          <a href="/users?page=<?= $page - 1 ?>&<?= $queryBase ?>"
             class="w-8 h-8 flex items-center justify-center rounded-lg text-xs font-bold bg-white text-slate-600 border border-slate-200 hover:bg-slate-50 transition-colors">
            <span class="material-symbols-outlined text-sm">chevron_left</span>
          </a>
          <?php endif; ?>
          <?php
          $start = max(1, $page - 2);
          $end   = min($totalPages, $page + 2);
          for ($i = $start; $i <= $end; $i++):
          ?>
          <a href="/users?page=<?= $i ?>&<?= $queryBase ?>"
             class="w-8 h-8 flex items-center justify-center rounded-lg text-xs font-bold transition-colors <?= $i === $page ? 'bg-primary text-white shadow-sm' : 'bg-white text-slate-600 border border-slate-200 hover:bg-slate-50' ?>">
            <?= $i ?>
          </a>
          <?php endfor; ?>
          <?php if ($page < $totalPages): ?>
          <a href="/users?page=<?= $page + 1 ?>&<?= $queryBase ?>"
             class="w-8 h-8 flex items-center justify-center rounded-lg text-xs font-bold bg-white text-slate-600 border border-slate-200 hover:bg-slate-50 transition-colors">
            <span class="material-symbols-outlined text-sm">chevron_right</span>
          </a>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>
    </div><!-- /table card -->

  </div><!-- /max-w -->
</div><!-- /ml-60 -->

<!-- ── Reset Password Modal ───────────────────────────────────────────── -->
<div id="reset-modal" class="hidden fixed inset-0 z-50 flex items-center justify-center p-4">
  <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" onclick="closeResetModal()"></div>
  <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-sm p-6 z-10">
    <div class="flex items-center gap-3 mb-5">
      <div class="w-10 h-10 rounded-full bg-amber-100 flex items-center justify-center">
        <span class="material-symbols-outlined text-amber-600">lock_reset</span>
      </div>
      <div>
        <h3 class="font-bold text-slate-900 text-sm" style="font-family:Manrope">Reset Password</h3>
        <p id="reset-modal-name" class="text-xs text-slate-500"></p>
      </div>
    </div>
    <div class="space-y-4">
      <div>
        <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1">New Password <span class="text-rose-500">*</span></label>
        <input type="password" id="reset-new-pass" placeholder="Min. 8 characters"
               class="w-full text-sm border-slate-200 rounded-lg focus:ring-primary focus:border-primary">
      </div>
      <div>
        <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1">Confirm Password <span class="text-rose-500">*</span></label>
        <input type="password" id="reset-confirm-pass" placeholder="Repeat password"
               class="w-full text-sm border-slate-200 rounded-lg focus:ring-primary focus:border-primary">
      </div>
      <p id="reset-error" class="hidden text-rose-600 text-xs font-medium flex items-center gap-1">
        <span class="material-symbols-outlined text-sm">error</span> <span id="reset-error-msg"></span>
      </p>
    </div>
    <div class="flex gap-2 mt-6">
      <button onclick="closeResetModal()" class="flex-1 border border-slate-200 text-slate-600 font-semibold py-2.5 rounded-lg text-sm hover:bg-slate-50 transition-colors">Cancel</button>
      <button onclick="submitReset()" id="reset-submit-btn"
              class="flex-1 bg-primary text-white font-bold py-2.5 rounded-lg text-sm hover:bg-primary/90 transition-colors flex items-center justify-center gap-1">
        <span class="material-symbols-outlined text-base">lock_reset</span> Reset Password
      </button>
    </div>
  </div>
</div>

<script>
var _resetUserId = null;

function openResetModal(userId, name) {
  _resetUserId = userId;
  document.getElementById('reset-modal-name').textContent = name;
  document.getElementById('reset-new-pass').value = '';
  document.getElementById('reset-confirm-pass').value = '';
  document.getElementById('reset-error').classList.add('hidden');
  document.getElementById('reset-modal').classList.remove('hidden');
  setTimeout(function(){ document.getElementById('reset-new-pass').focus(); }, 50);
}

function closeResetModal() {
  document.getElementById('reset-modal').classList.add('hidden');
  _resetUserId = null;
}

function submitReset() {
  var pass    = document.getElementById('reset-new-pass').value;
  var confirm = document.getElementById('reset-confirm-pass').value;
  var errEl   = document.getElementById('reset-error');
  var errMsg  = document.getElementById('reset-error-msg');
  var btn     = document.getElementById('reset-submit-btn');

  errEl.classList.add('hidden');

  if (pass.length < 8) {
    errMsg.textContent = 'Password must be at least 8 characters.';
    errEl.classList.remove('hidden');
    return;
  }
  if (pass !== confirm) {
    errMsg.textContent = 'Passwords do not match.';
    errEl.classList.remove('hidden');
    return;
  }

  btn.disabled = true;
  btn.innerHTML = '<span class="material-symbols-outlined text-base animate-spin">progress_activity</span> Resetting…';

  var body = new URLSearchParams();
  body.append('new_password', pass);

  fetch('/users/' + _resetUserId + '/reset-password', {
    method: 'POST',
    headers: { 
      'Content-Type': 'application/x-www-form-urlencoded',
      'X-CSRF-Token': '<?= $_SESSION['csrf_token'] ?? '' ?>'
    },
    body: body.toString()
  })
  .then(function(r){ return r.json(); })
  .then(function(data) {
    if (data.success) {
      closeResetModal();
      showToast('Password reset successfully.', 'success');
    } else {
      errMsg.textContent = data.error || 'Failed to reset password.';
      errEl.classList.remove('hidden');
      btn.disabled = false;
      btn.innerHTML = '<span class="material-symbols-outlined text-base">lock_reset</span> Reset Password';
    }
  })
  .catch(function() {
    errMsg.textContent = 'Network error. Please try again.';
    errEl.classList.remove('hidden');
    btn.disabled = false;
    btn.innerHTML = '<span class="material-symbols-outlined text-base">lock_reset</span> Reset Password';
  });
}

function toggleStatus(userId, action, name) {
  var msg = action === 'suspend'
    ? 'Suspend ' + name + '? They will be unable to log in.'
    : 'Reactivate ' + name + '?';
  if (!confirm(msg)) return;

  fetch('/users/' + userId + '/toggle-status', { 
    method: 'POST',
    headers: { 'X-CSRF-Token': '<?= $_SESSION['csrf_token'] ?? '' ?>' }
  })
    .then(function(r){ return r.json(); })
    .then(function(data) {
      if (data.success) {
        location.reload();
      } else {
        alert('Error: ' + (data.error || 'Could not update status.'));
      }
    })
    .catch(function() { alert('Network error. Please try again.'); });
}

function showToast(msg, type) {
  var toast = document.createElement('div');
  toast.className = 'fixed bottom-6 right-6 z-50 flex items-center gap-2 px-4 py-3 rounded-xl shadow-lg text-sm font-semibold transition-all '
    + (type === 'success' ? 'bg-emerald-600 text-white' : 'bg-rose-600 text-white');
  toast.innerHTML = '<span class="material-symbols-outlined text-base">'
    + (type === 'success' ? 'check_circle' : 'error')
    + '</span>' + msg;
  document.body.appendChild(toast);
  setTimeout(function(){ toast.remove(); }, 3000);
}
</script>

</body>
</html>
