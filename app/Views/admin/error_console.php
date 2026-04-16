<?php
/**
 * Admin — Error Console
 * Restricted to admin and manager roles.
 *
 * @var array  $errors      Paginated error records
 * @var int    $total
 * @var int    $page
 * @var int    $totalPages
 * @var array  $filters
 * @var string $activePage
 */

$queryBase = http_build_query(array_filter([
    'severity' => $filters['severity'] ?? '',
    'date_from' => $filters['date_from'] ?? '',
    'date_to'   => $filters['date_to'] ?? '',
    'search'    => $filters['search'] ?? '',
]));

function severityBadge(string $sev): string {
    return match(strtolower($sev)) {
        'error', 'critical', 'fatal' => 'bg-red-100 text-red-700 border-red-200',
        'warning'                    => 'bg-amber-100 text-amber-700 border-amber-200',
        'notice', 'info'             => 'bg-blue-100 text-blue-700 border-blue-200',
        default                      => 'bg-slate-100 text-slate-600 border-slate-200',
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Error Console — Base Fare CRM</title>
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
      }
    }
  }
}
</script>
</head>
<body class="bg-slate-50 font-sans">

<?php $activePage = 'error_console'; require __DIR__ . '/../partials/admin_sidebar.php'; ?>

<div class="ml-60 min-h-screen">
  <div class="max-w-7xl mx-auto px-8 py-8 space-y-6">

    <!-- Page Header -->
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-bold text-slate-900" style="font-family:Manrope">Error Console</h1>
        <p class="text-slate-500 text-sm mt-1"><?= number_format($total) ?> errors logged</p>
      </div>
      <div class="flex items-center gap-2">
        <?php if ($total > 0): ?>
        <form method="POST" action="/admin/error-console/clear" onsubmit="return confirm('Clear ALL error logs? This cannot be undone.')">
          <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
          <button type="submit" class="inline-flex items-center gap-1.5 px-4 py-2 bg-red-600 text-white text-sm font-semibold rounded-lg hover:bg-red-500 transition-colors">
            <span class="material-symbols-outlined text-sm">delete_sweep</span> Clear All Logs
          </button>
        </form>
        <?php endif; ?>
      </div>
    </div>

    <!-- Filters -->
    <form method="GET" action="/admin/error-console" class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm">
      <div class="grid grid-cols-2 lg:grid-cols-5 gap-3 items-end">

        <div>
          <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1">Severity</label>
          <select name="severity" class="w-full text-sm border-slate-200 rounded-lg focus:ring-primary focus:border-primary">
            <option value="">All Levels</option>
            <?php foreach (['error','warning','notice','info','critical','fatal'] as $sev): ?>
            <option value="<?= $sev ?>" <?= (($filters['severity'] ?? '') === $sev) ? 'selected' : '' ?>><?= ucfirst($sev) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-span-2 lg:col-span-2">
          <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1">Search Message</label>
          <input type="text" name="search" value="<?= htmlspecialchars($filters['search'] ?? '') ?>"
                 placeholder="Search error message…"
                 class="w-full text-sm border-slate-200 rounded-lg focus:ring-primary focus:border-primary">
        </div>

        <div>
          <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1">From Date</label>
          <input type="date" name="date_from" value="<?= htmlspecialchars($filters['date_from'] ?? '') ?>"
                 class="w-full text-sm border-slate-200 rounded-lg focus:ring-primary focus:border-primary">
        </div>

        <div class="flex gap-2 items-end">
          <div class="flex-1">
            <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1">To Date</label>
            <input type="date" name="date_to" value="<?= htmlspecialchars($filters['date_to'] ?? '') ?>"
                   class="w-full text-sm border-slate-200 rounded-lg focus:ring-primary focus:border-primary">
          </div>
          <button type="submit" class="flex-shrink-0 bg-primary text-white px-4 py-2 rounded-lg text-sm font-semibold hover:bg-primary/90 transition-colors flex items-center gap-1">
            <span class="material-symbols-outlined text-base">search</span>
          </button>
          <a href="/admin/error-console" class="flex-shrink-0 bg-slate-100 text-slate-600 px-3 py-2 rounded-lg hover:bg-slate-200 transition-colors flex items-center justify-center" title="Clear Filters">
            <span class="material-symbols-outlined text-base">refresh</span>
          </a>
        </div>

      </div>
    </form>

    <!-- Error List -->
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
      <?php if (count($errors) === 0): ?>
      <div class="py-24 text-center">
        <span class="material-symbols-outlined text-6xl text-emerald-400 opacity-60">check_circle</span>
        <p class="mt-3 font-semibold text-slate-500">No errors found<?= !empty($filters['severity'] || $filters['search']) ? ' matching your filters' : '' ?>.</p>
      </div>
      <?php else: ?>
      <div class="divide-y divide-slate-100">
        <?php foreach ($errors as $i => $err): ?>
        <?php $sevCls = severityBadge($err->severity ?? 'error'); ?>
        <div class="p-5 hover:bg-slate-50/50 transition-colors">
          <div class="flex items-start justify-between gap-4">
            <div class="flex-1 min-w-0">
              <div class="flex items-center gap-2 mb-2 flex-wrap">
                <span class="inline-block border px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider <?= $sevCls ?>"><?= htmlspecialchars(strtoupper($err->severity ?? 'ERROR')) ?></span>
                <span class="text-[11px] text-slate-400 font-mono"><?= htmlspecialchars($err->created_at ?? '—') ?></span>
                <?php if (!empty($err->url)): ?>
                <span class="text-[10px] text-slate-400 truncate max-w-xs"><?= htmlspecialchars($err->url) ?></span>
                <?php endif; ?>
              </div>
              <p class="text-sm font-semibold text-slate-800 break-all"><?= htmlspecialchars($err->message ?? '—') ?></p>
              <?php if (!empty($err->file)): ?>
              <p class="text-[11px] font-mono text-slate-400 mt-1">
                <?= htmlspecialchars($err->file) ?><?= !empty($err->line) ? ':' . $err->line : '' ?>
              </p>
              <?php endif; ?>
              <?php if (!empty($err->trace)): ?>
              <button type="button" onclick="this.nextElementSibling.classList.toggle('hidden'); this.textContent = this.nextElementSibling.classList.contains('hidden') ? '▸ Show Stack Trace' : '▾ Hide Stack Trace';"
                class="mt-1 text-[10px] text-blue-500 hover:text-blue-700 font-semibold transition-colors">
                ▸ Show Stack Trace
              </button>
              <pre class="hidden mt-2 text-[10px] font-mono bg-slate-900 text-slate-100 p-3 rounded-lg overflow-x-auto leading-relaxed whitespace-pre-wrap max-h-64"><?= htmlspecialchars($err->trace) ?></pre>
              <?php endif; ?>
            </div>
            <div class="flex-shrink-0 text-right">
              <?php if (!empty($err->ip_address)): ?>
              <p class="text-[10px] font-mono text-slate-400"><?= htmlspecialchars($err->ip_address) ?></p>
              <?php endif; ?>
              <?php if (!empty($err->user_id)): ?>
              <p class="text-[10px] text-slate-400">User #<?= (int)$err->user_id ?></p>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <!-- Pagination -->
      <?php if ($totalPages > 1): ?>
      <div class="px-5 py-4 border-t border-slate-100 bg-slate-50/50 flex items-center justify-between">
        <span class="text-xs text-slate-500">Page <?= $page ?> of <?= $totalPages ?></span>
        <div class="flex gap-1">
          <?php if ($page > 1): ?>
          <a href="/admin/error-console?page=<?= $page - 1 ?>&<?= $queryBase ?>"
             class="w-8 h-8 flex items-center justify-center rounded-lg text-xs font-bold bg-white text-slate-600 border border-slate-200 hover:bg-slate-50">
            <span class="material-symbols-outlined text-sm">chevron_left</span>
          </a>
          <?php endif; ?>
          <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
          <a href="/admin/error-console?page=<?= $i ?>&<?= $queryBase ?>"
             class="w-8 h-8 flex items-center justify-center rounded-lg text-xs font-bold <?= $i === $page ? 'bg-primary text-white' : 'bg-white text-slate-600 border border-slate-200 hover:bg-slate-50' ?>">
            <?= $i ?>
          </a>
          <?php endfor; ?>
          <?php if ($page < $totalPages): ?>
          <a href="/admin/error-console?page=<?= $page + 1 ?>&<?= $queryBase ?>"
             class="w-8 h-8 flex items-center justify-center rounded-lg text-xs font-bold bg-white text-slate-600 border border-slate-200 hover:bg-slate-50">
            <span class="material-symbols-outlined text-sm">chevron_right</span>
          </a>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>
      <?php endif; ?>
    </div>

  </div>
</div>

</body>
</html>
