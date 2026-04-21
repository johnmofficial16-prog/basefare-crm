<?php
/**
 * Admin — System Settings
 *
 * @var array       $config       Key→value map from system_config
 * @var string      $activePage
 * @var string|null $flashSuccess
 * @var string|null $flashError
 */
$config = $config ?? [];

// Helper: get config value with fallback
$cfg = function(string $key, $default = '') use ($config) {
    return htmlspecialchars($config[$key] ?? $default);
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Settings — Base Fare CRM</title>
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

<?php $activePage = 'settings'; require __DIR__ . '/../partials/admin_sidebar.php'; ?>

<main class="ml-60 pt-6 pb-20 px-8">

  <!-- Page Header -->
  <div class="flex items-center justify-between mb-6">
    <div>
      <h1 class="text-2xl font-extrabold text-primary tracking-tight flex items-center gap-2" style="font-family:Manrope">
        <span class="material-symbols-outlined text-2xl">settings</span> Admin Settings
      </h1>
      <p class="text-sm text-slate-500 mt-0.5">System-wide configuration. Changes take effect immediately.</p>
    </div>
    <a href="/admin/activity-log"
       class="inline-flex items-center gap-1.5 text-sm font-semibold text-slate-500 hover:text-primary transition-colors border border-slate-200 bg-white rounded-lg px-4 py-2 shadow-sm hover:shadow">
      <span class="material-symbols-outlined text-base">history</span> Activity Log
    </a>
  </div>

  <!-- Flash Messages -->
  <?php if ($flashSuccess): ?>
  <div class="mb-5 px-4 py-3 bg-emerald-50 border border-emerald-200 rounded-xl text-sm font-semibold text-emerald-800 flex items-center gap-2">
    <span class="material-symbols-outlined text-base">check_circle</span> <?= htmlspecialchars($flashSuccess) ?>
  </div>
  <?php endif; ?>
  <?php if ($flashError): ?>
  <div class="mb-5 px-4 py-3 bg-red-50 border border-red-200 rounded-xl text-sm font-semibold text-red-700 flex items-center gap-2">
    <span class="material-symbols-outlined text-base">error</span> <?= htmlspecialchars($flashError) ?>
  </div>
  <?php endif; ?>

  <form method="POST" action="/admin/settings" class="max-w-2xl space-y-5">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">

    <!-- ── Break Abuse Thresholds ────────────────────────────────────────── -->
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
      <div class="px-6 py-4 border-b border-slate-100 bg-slate-50/50 flex items-center gap-2">
        <span class="material-symbols-outlined text-primary text-lg">wc</span>
        <h2 class="font-bold text-slate-900" style="font-family:Manrope">Washroom Break Thresholds</h2>
      </div>
      <div class="p-6 space-y-5">
        <p class="text-xs text-slate-500 -mt-2">Crossing any threshold flags the break and triggers an admin alert.</p>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
          <div>
            <label class="field-label" for="single_washroom_max">
              Single Break Max
              <span class="normal-case text-slate-400 font-normal ml-1">(minutes)</span>
            </label>
            <input class="field-input" type="number" id="single_washroom_max"
                   name="abuse.single_washroom_max"
                   value="<?= $cfg('abuse.single_washroom_max', '15') ?>"
                   min="1" max="120">
            <p class="text-[10px] text-slate-400 mt-1">Flag if a single washroom break exceeds this.</p>
          </div>
          <div>
            <label class="field-label" for="washroom_count_max">
              Max Trips Per Shift
            </label>
            <input class="field-input" type="number" id="washroom_count_max"
                   name="abuse.washroom_count_max"
                   value="<?= $cfg('abuse.washroom_count_max', '4') ?>"
                   min="1" max="30">
            <p class="text-[10px] text-slate-400 mt-1">Flag if total washroom trips exceed this.</p>
          </div>
          <div>
            <label class="field-label" for="washroom_total_max">
              Total Washroom Max
              <span class="normal-case text-slate-400 font-normal ml-1">(minutes)</span>
            </label>
            <input class="field-input" type="number" id="washroom_total_max"
                   name="abuse.washroom_total_max"
                   value="<?= $cfg('abuse.washroom_total_max', '45') ?>"
                   min="5" max="300">
            <p class="text-[10px] text-slate-400 mt-1">Flag if cumulative washroom time exceeds this.</p>
          </div>
        </div>
      </div>
    </div>

    <!-- ── Attendance Defaults ───────────────────────────────────────────── -->
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
      <div class="px-6 py-4 border-b border-slate-100 bg-slate-50/50 flex items-center gap-2">
        <span class="material-symbols-outlined text-primary text-lg">schedule</span>
        <h2 class="font-bold text-slate-900" style="font-family:Manrope">Attendance Defaults</h2>
      </div>
      <div class="p-6">
        <div class="max-w-xs">
          <label class="field-label" for="default_grace_period_mins">
            Default Grace Period
            <span class="normal-case text-slate-400 font-normal ml-1">(minutes)</span>
          </label>
          <input class="field-input" type="number" id="default_grace_period_mins"
                 name="default_grace_period_mins"
                 value="<?= $cfg('default_grace_period_mins', '30') ?>"
                 min="0" max="120">
          <p class="text-[10px] text-slate-400 mt-1">Applied to new users unless overridden per-user. Range: 0–120 min.</p>
        </div>
      </div>
    </div>

    <!-- ── Default Currency ──────────────────────────────────────────────── -->
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
      <div class="px-6 py-4 border-b border-slate-100 bg-slate-50/50 flex items-center gap-2">
        <span class="material-symbols-outlined text-primary text-lg">currency_exchange</span>
        <h2 class="font-bold text-slate-900" style="font-family:Manrope">Default Currency</h2>
      </div>
      <div class="p-6">
        <div class="max-w-xs">
          <label class="field-label" for="default_currency">Display Currency</label>
          <select class="field-input" id="default_currency" name="default_currency">
            <?php foreach (['CAD', 'USD', 'GBP', 'EUR', 'INR', 'AED'] as $c): ?>
            <option value="<?= $c ?>" <?= $cfg('default_currency', 'CAD') === $c ? 'selected' : '' ?>><?= $c ?></option>
            <?php endforeach; ?>
          </select>
          <p class="text-[10px] text-slate-400 mt-1">Used as the currency label across all dashboard views (agent, supervisor, admin).</p>
        </div>
      </div>
    </div>

    <!-- ── System Info (read-only) ───────────────────────────────────────── -->
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
      <div class="px-6 py-4 border-b border-slate-100 bg-slate-50/50 flex items-center gap-2">
        <span class="material-symbols-outlined text-primary text-lg">info</span>
        <h2 class="font-bold text-slate-900" style="font-family:Manrope">System Info</h2>
      </div>
      <div class="p-6 grid grid-cols-2 sm:grid-cols-3 gap-4 text-sm">
        <div>
          <p class="field-label">PHP Version</p>
          <p class="font-mono text-slate-800"><?= phpversion() ?></p>
        </div>
        <div>
          <p class="field-label">Environment</p>
          <p class="font-semibold <?= ($_ENV['APP_ENV'] ?? 'production') === 'local' ? 'text-amber-600' : 'text-emerald-700' ?>">
            <?= htmlspecialchars($_ENV['APP_ENV'] ?? 'production') ?>
          </p>
        </div>
        <div>
          <p class="field-label">Server Time</p>
          <p class="font-mono text-slate-800"><?= date('Y-m-d H:i:s') ?></p>
        </div>
        <div>
          <p class="field-label">Timezone</p>
          <p class="text-slate-800"><?= htmlspecialchars(date_default_timezone_get()) ?></p>
        </div>
      </div>
    </div>

    <!-- Save Button -->
    <div class="flex items-center justify-end gap-3">
      <button type="submit" id="save-btn"
              class="inline-flex items-center gap-2 bg-primary text-white font-bold py-2.5 px-6 rounded-lg text-sm hover:bg-primary/90 transition-colors shadow-sm">
        <span class="material-symbols-outlined text-base">save</span> Save Settings
      </button>
    </div>

  </form>

</main>

<script>
document.querySelector('form').addEventListener('submit', function() {
  var btn = document.getElementById('save-btn');
  btn.disabled = true;
  btn.innerHTML = '<span class="material-symbols-outlined text-base animate-spin">progress_activity</span> Saving…';
});
</script>

</body>
</html>
