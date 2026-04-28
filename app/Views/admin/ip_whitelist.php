<?php
/**
 * Admin — IP Whitelist
 *
 * @var array       $ips
 * @var string      $activePage
 * @var string|null $flashSuccess
 * @var string|null $flashError
 * @var bool        $whitelistingEnabled
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>IP Whitelist — Base Fare CRM</title>
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

<?php $activePage = 'ip_whitelist'; require __DIR__ . '/../partials/admin_sidebar.php'; ?>

<main class="ml-60 pt-6 pb-20 px-8">

  <!-- Page Header -->
  <div class="flex items-center justify-between mb-6">
    <div>
      <h1 class="text-2xl font-extrabold text-primary tracking-tight flex items-center gap-2" style="font-family:Manrope">
        <span class="material-symbols-outlined text-2xl">shield</span> IP Whitelist
      </h1>
      <p class="text-sm text-slate-500 mt-0.5">Restrict CRM access to specific office networks.</p>
    </div>
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

  <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

    <!-- Left Column: Master Switch & Add Form -->
    <div class="lg:col-span-1 space-y-6">

      <!-- Master Switch -->
      <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100 bg-slate-50/50 flex items-center justify-between">
          <div class="flex items-center gap-2">
            <span class="material-symbols-outlined text-primary text-lg">power_settings_new</span>
            <h2 class="font-bold text-slate-900" style="font-family:Manrope">Master Switch</h2>
          </div>
        </div>
        <div class="p-6">
          <form method="POST" action="/admin/ip-whitelist/toggle">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
            <input type="hidden" name="enabled" value="<?= $whitelistingEnabled ? '0' : '1' ?>">
            <div class="flex items-center justify-between mb-2">
                <span class="text-sm font-semibold text-slate-700">Status:</span>
                <?php if ($whitelistingEnabled): ?>
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-emerald-100 text-emerald-800">
                      Active
                    </span>
                <?php else: ?>
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                      Disabled
                    </span>
                <?php endif; ?>
            </div>
            <p class="text-xs text-slate-500 mb-4">
              When disabled, agents can log in from any location. Admins are always exempt.
            </p>
            <button type="submit" class="w-full justify-center inline-flex items-center gap-2 <?= $whitelistingEnabled ? 'bg-red-50 text-red-700 hover:bg-red-100' : 'bg-emerald-50 text-emerald-700 hover:bg-emerald-100' ?> font-semibold py-2 px-4 border <?= $whitelistingEnabled ? 'border-red-200' : 'border-emerald-200' ?> rounded-lg text-sm transition-colors shadow-sm">
              <span class="material-symbols-outlined text-base"><?= $whitelistingEnabled ? 'toggle_off' : 'toggle_on' ?></span>
              <?= $whitelistingEnabled ? 'Disable Whitelisting' : 'Enable Whitelisting' ?>
            </button>
          </form>
        </div>
      </div>

      <!-- Add New IP -->
      <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100 bg-slate-50/50 flex items-center gap-2">
          <span class="material-symbols-outlined text-primary text-lg">add_location_alt</span>
          <h2 class="font-bold text-slate-900" style="font-family:Manrope">Add Office IP</h2>
        </div>
        <div class="p-6">
          <form method="POST" action="/admin/ip-whitelist">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
            <div class="space-y-4">
              <div>
                <label class="field-label" for="ip_address">IP Address or DDNS Hostname</label>
                <input class="field-input" type="text" id="ip_address" name="ip_address" placeholder="e.g. 192.168.1.100 or center1.ddns.net" required>
              </div>
              <div>
                <label class="field-label" for="location_name">Location Name</label>
                <input class="field-input" type="text" id="location_name" name="location_name" placeholder="e.g. Chicago Main Office" required>
              </div>
              <button type="submit" class="w-full justify-center inline-flex items-center gap-2 bg-primary text-white font-bold py-2.5 px-4 rounded-lg text-sm hover:bg-primary/90 transition-colors shadow-sm">
                <span class="material-symbols-outlined text-base">add</span> Add to Whitelist
              </button>
            </div>
          </form>
        </div>
      </div>

    </div>

    <!-- Right Column: IP List -->
    <div class="lg:col-span-2">
      <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100 bg-slate-50/50 flex items-center justify-between">
          <div class="flex items-center gap-2">
            <span class="material-symbols-outlined text-primary text-lg">list_alt</span>
            <h2 class="font-bold text-slate-900" style="font-family:Manrope">Allowed Networks</h2>
          </div>
          <span class="text-xs font-semibold text-slate-500 bg-white border border-slate-200 px-2.5 py-1 rounded-md shadow-sm"><?= count($ips) ?> IPs</span>
        </div>
        
        <?php if (empty($ips)): ?>
          <div class="p-8 text-center">
            <span class="material-symbols-outlined text-4xl text-slate-300 mb-2">dns</span>
            <p class="text-slate-500 text-sm">No IP addresses have been whitelisted yet.</p>
            <p class="text-slate-400 text-xs mt-1">If the master switch is active, all agents are currently blocked.</p>
          </div>
        <?php else: ?>
          <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
              <thead>
                <tr class="bg-slate-50 text-[10px] uppercase tracking-wider text-slate-500 font-bold border-b border-slate-200">
                  <th class="px-6 py-3">Location Name</th>
                  <th class="px-6 py-3">IP Address / Hostname</th>
                  <th class="px-6 py-3">Added</th>
                  <th class="px-6 py-3 text-right">Actions</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-100 text-sm">
                <?php foreach ($ips as $ip): ?>
                <tr class="hover:bg-slate-50/50 transition-colors">
                  <td class="px-6 py-3 font-semibold text-slate-900"><?= htmlspecialchars($ip->location_name) ?></td>
                  <td class="px-6 py-3 font-mono text-slate-700 bg-slate-50/50 rounded inline-block mt-2 ml-6 px-2 py-0.5 border border-slate-100"><?= htmlspecialchars($ip->ip_address) ?></td>
                  <td class="px-6 py-3 text-slate-500 text-xs"><?= date('M j, Y', strtotime($ip->created_at)) ?></td>
                  <td class="px-6 py-3 text-right">
                    <form method="POST" action="/admin/ip-whitelist/<?= (int)$ip->id ?>/delete" class="inline-block" onsubmit="return confirm('Remove this IP from the whitelist? Agents at this location will be locked out immediately.');">
                      <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
                      <button type="submit" class="text-red-500 hover:text-red-700 p-1 rounded hover:bg-red-50 transition-colors" title="Delete">
                        <span class="material-symbols-outlined text-lg">delete</span>
                      </button>
                    </form>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>

  </div>
</main>
</body>
</html>
