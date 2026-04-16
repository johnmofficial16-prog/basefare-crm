<?php
/**
 * Admin — Activity Log
 *
 * @var object $records
 * @var int    $total
 * @var int    $page
 * @var int    $totalPages
 * @var array  $filters
 * @var array  $allUsers
 * @var array  $entityTypes
 *
 * @var string $activePage
 */

// Build base query string for pagination
$queryBase = http_build_query(array_filter([
    'user_id'     => $filters['user_id'] ?? '',
    'action'      => $filters['action'] ?? '',
    'entity_type' => $filters['entity_type'] ?? '',
    'date_from'   => $filters['date_from'] ?? '',
    'date_to'     => $filters['date_to'] ?? '',
]));

function formatDetailsJSON(?string $json): string {
    if (!$json) return '';
    $arr = json_decode($json, true);
    if (!is_array($arr) || empty($arr)) return '';

    // If it's a simple key-value structure, format it nicely
    $html = '<ul class="mt-1.5 space-y-1">';
    foreach ($arr as $k => $v) {
        if (is_array($v)) {
            $v = json_encode($v); // stringify inner arrays
        } else {
            $v = htmlspecialchars((string) $v);
        }
        $k = htmlspecialchars($k);
        $html .= "<li class='text-[10px] bg-slate-100 rounded px-1.5 py-0.5 inline-block mr-1 mb-1 border border-slate-200'><span class='text-slate-500 font-medium'>{$k}:</span> <span class='text-slate-700 font-mono'>{$v}</span></li>";
    }
    $html .= '</ul>';
    return $html;
}

// Action text color formatting
function actionClass(string $action): string {
    if (str_contains($action, 'delete') || str_contains($action, 'suspend') || str_contains($action, 'deny')) {
        return 'text-rose-600 bg-rose-50 border-rose-200';
    }
    if (str_contains($action, 'create') || str_contains($action, 'approve')) {
        return 'text-emerald-700 bg-emerald-50 border-emerald-200';
    }
    if (str_contains($action, 'update') || str_contains($action, 'edit')) {
        return 'text-blue-700 bg-blue-50 border-blue-200';
    }
    return 'text-slate-600 bg-slate-50 border-slate-200';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Activity Log — Base Fare CRM</title>
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

<?php $activePage = 'activity_log'; require __DIR__ . '/../partials/admin_sidebar.php'; ?>

<div class="ml-60 min-h-screen">
  <div class="max-w-7xl mx-auto px-8 py-8 space-y-6">

    <!-- Page Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
      <div>
        <div class="flex items-center gap-2 mb-1 text-slate-500">
          <a href="/admin/settings" class="text-[10px] font-bold uppercase tracking-wider hover:text-primary transition-colors">Admin Settings</a>
          <span class="material-symbols-outlined text-[10px]">chevron_right</span>
          <span class="text-[10px] font-bold uppercase tracking-wider text-primary">Activity Log</span>
        </div>
        <h1 class="text-2xl font-bold text-slate-900" style="font-family:Manrope">Audit Trail</h1>
        <p class="text-slate-500 text-sm mt-1"><?= number_format($total) ?> events recorded</p>
      </div>
    </div>

    <!-- Filters -->
    <form method="GET" action="/admin/activity-log" class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm">
      <div class="grid grid-cols-2 lg:grid-cols-6 gap-3 items-end">

        <div class="col-span-2 lg:col-span-1">
          <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1">User</label>
          <select name="user_id" class="w-full text-sm border-slate-200 rounded-lg focus:ring-primary focus:border-primary">
            <option value="">All Users</option>
            <?php foreach ($allUsers as $u): ?>
            <option value="<?= $u->id ?>" <?= ((int)($filters['user_id'] ?? 0) === $u->id) ? 'selected' : '' ?>>
              <?= htmlspecialchars($u->name) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-span-2 lg:col-span-1">
          <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1">Action (Partial Match)</label>
          <input type="text" name="action" value="<?= htmlspecialchars($filters['action'] ?? '') ?>"
                 placeholder="e.g. login, create"
                 class="w-full text-sm border-slate-200 rounded-lg focus:ring-primary focus:border-primary">
        </div>

        <div class="col-span-2 lg:col-span-1">
          <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1">Entity / Module</label>
          <select name="entity_type" class="w-full text-sm border-slate-200 rounded-lg focus:ring-primary focus:border-primary">
            <option value="">All Entities</option>
            <?php foreach ($entityTypes as $et): if (!$et) continue; ?>
            <option value="<?= htmlspecialchars($et) ?>" <?= (($filters['entity_type'] ?? '') === $et) ? 'selected' : '' ?>>
              <?= htmlspecialchars($et) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1">From Date</label>
          <input type="date" name="date_from" value="<?= htmlspecialchars($filters['date_from'] ?? '') ?>"
                 class="w-full text-sm border-slate-200 rounded-lg focus:ring-primary focus:border-primary">
        </div>

        <div>
          <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1">To Date</label>
          <input type="date" name="date_to" value="<?= htmlspecialchars($filters['date_to'] ?? '') ?>"
                 class="w-full text-sm border-slate-200 rounded-lg focus:ring-primary focus:border-primary">
        </div>

        <div class="flex gap-2 w-full lg:w-auto mt-2 lg:mt-0">
          <button type="submit" class="flex-1 lg:flex-none bg-primary text-white px-4 py-2 rounded-lg text-sm font-semibold hover:bg-primary/90 transition-colors flex items-center justify-center gap-1">
            <span class="material-symbols-outlined text-base">search</span> Filter
          </button>
          <a href="/admin/activity-log" class="bg-slate-100 text-slate-600 px-3 py-2 rounded-lg hover:bg-slate-200 transition-colors flex items-center justify-center" title="Clear Filters">
            <span class="material-symbols-outlined text-base">refresh</span>
          </a>
        </div>

      </div>
    </form>

    <!-- Results Table -->
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-left text-sm">
          <thead class="bg-slate-50 border-b border-slate-100 text-slate-500 text-[10px] font-bold uppercase tracking-wider">
            <tr>
              <th class="px-5 py-3 whitespace-nowrap">Timestamp</th>
              <th class="px-5 py-3">User</th>
              <th class="px-5 py-3">Action</th>
              <th class="px-5 py-3">Entity</th>
              <th class="px-5 py-3 w-[45%]">Payload / Details</th>
              <th class="px-5 py-3 text-right">IP Address</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <?php if (count($records) === 0): ?>
            <tr>
              <td colspan="6" class="py-20 text-center">
                <div class="flex flex-col items-center gap-3 text-slate-400">
                  <span class="material-symbols-outlined text-5xl opacity-30">history</span>
                  <p class="font-semibold text-slate-500">No events found matching your criteria.</p>
                </div>
              </td>
            </tr>
            <?php else: ?>
            <?php foreach ($records as $log): ?>
            <?php
              $time = $log->created_at ? date('M j \a\t H:i:s', strtotime($log->created_at)) : '—';
              $aCls = actionClass($log->action);
            ?>
            <tr class="hover:bg-slate-50/50 transition-colors">
              <td class="px-5 py-3.5 text-xs text-slate-500 font-mono whitespace-nowrap"><?= $time ?></td>
              <td class="px-5 py-3.5">
                <?php if ($log->user_name): ?>
                  <div class="font-semibold text-slate-900"><?= htmlspecialchars($log->user_name) ?></div>
                  <div class="text-[10px] text-slate-400 uppercase tracking-wider mix-blend-multiply"><?= htmlspecialchars($log->user_role) ?></div>
                <?php else: ?>
                  <span class="text-slate-400 font-mono text-xs">System / #<?= $log->user_id ?></span>
                <?php endif; ?>
              </td>
              <td class="px-5 py-3.5">
                <span class="inline-block border px-2 py-0.5 rounded text-[11px] font-mono font-bold tracking-tight <?= $aCls ?>">
                  <?= htmlspecialchars($log->action) ?>
                </span>
              </td>
              <td class="px-5 py-3.5">
                <?php if ($log->entity_type): ?>
                  <span class="text-[11px] text-slate-600 font-semibold bg-slate-100 px-1.5 py-0.5 rounded">
                    <?= htmlspecialchars($log->entity_type) ?>
                    <?= $log->entity_id ? ' <span class="text-slate-400 font-mono">#' . $log->entity_id . '</span>' : '' ?>
                  </span>
                <?php else: ?>
                  <span class="text-slate-300">—</span>
                <?php endif; ?>
              </td>
              <td class="px-5 py-3.5 align-top">
                <?php if ($log->details && $log->details !== '[]' && $log->details !== '{}'): ?>
                  <?= formatDetailsJSON($log->details) ?>
                <?php else: ?>
                  <span class="text-slate-300">—</span>
                <?php endif; ?>
              </td>
              <td class="px-5 py-3.5 text-right font-mono text-xs text-slate-400">
                <?= htmlspecialchars($log->ip_address ?? '—') ?>
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
          Page <?= $page ?> of <?= $totalPages ?>
        </span>
        <div class="flex gap-1">
          <?php if ($page > 1): ?>
          <a href="/admin/activity-log?page=<?= $page - 1 ?>&<?= $queryBase ?>"
             class="w-8 h-8 flex items-center justify-center rounded-lg text-xs font-bold bg-white text-slate-600 border border-slate-200 hover:bg-slate-50 transition-colors">
            <span class="material-symbols-outlined text-sm">chevron_left</span>
          </a>
          <?php endif; ?>
          <?php
          $start = max(1, $page - 2);
          $end   = min($totalPages, $page + 2);
          for ($i = $start; $i <= $end; $i++):
          ?>
          <a href="/admin/activity-log?page=<?= $i ?>&<?= $queryBase ?>"
             class="w-8 h-8 flex items-center justify-center rounded-lg text-xs font-bold transition-colors <?= $i === $page ? 'bg-primary text-white shadow-sm' : 'bg-white text-slate-600 border border-slate-200 hover:bg-slate-50' ?>">
            <?= $i ?>
          </a>
          <?php endfor; ?>
          <?php if ($page < $totalPages): ?>
          <a href="/admin/activity-log?page=<?= $page + 1 ?>&<?= $queryBase ?>"
             class="w-8 h-8 flex items-center justify-center rounded-lg text-xs font-bold bg-white text-slate-600 border border-slate-200 hover:bg-slate-50 transition-colors">
            <span class="material-symbols-outlined text-sm">chevron_right</span>
          </a>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

    </div><!-- /table card -->
  </div>
</div>

</body>
</html>
