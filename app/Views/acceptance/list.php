<?php
/**
 * Acceptance List View
 *
 * @var array  $data       From AcceptanceService::list()
 * @var array  $filters    Active filter values
 *
 * $data keys: records, total, page, per_page, total_pages
 */

use App\Models\AcceptanceRequest;

$records    = $data['records'];
$total      = $data['total'];
$page       = $data['page'];
$totalPages = $data['total_pages'];
$filters    = $filters ?? [];

$userRole   = $_SESSION['user_role'] ?? 'agent';

$flashError   = $_SESSION['flash_error'] ?? null;
$flashSuccess = $_SESSION['flash_success'] ?? null;
unset($_SESSION['flash_error'], $_SESSION['flash_success']);

// Helper: status badge classes
function acceptanceStatusBadge(string $status): string {
    return match($status) {
        'PENDING'   => 'bg-amber-100 text-amber-800 border border-amber-200',
        'APPROVED'  => 'bg-emerald-100 text-emerald-800 border border-emerald-200',
        'EXPIRED'   => 'bg-slate-100 text-slate-600 border border-slate-200',
        'CANCELLED' => 'bg-rose-100 text-rose-700 border border-rose-200',
        default     => 'bg-slate-100 text-slate-600 border border-slate-200',
    };
}

// Helper: type label badge
function acceptanceTypeBadge(string $type): string {
    $labels = [
        'new_booking'     => ['label' => 'New Booking',      'class' => 'bg-blue-100 text-blue-800'],
        'exchange'        => ['label' => 'Exchange',          'class' => 'bg-violet-100 text-violet-800'],
        'cancel_refund'   => ['label' => 'Cancel / Refund',   'class' => 'bg-rose-100 text-rose-800'],
        'cancel_credit'   => ['label' => 'Cancel / Credit',   'class' => 'bg-orange-100 text-orange-800'],
        'seat_purchase'   => ['label' => 'Seat Purchase',     'class' => 'bg-cyan-100 text-cyan-800'],
        'cabin_upgrade'   => ['label' => 'Cabin Upgrade',     'class' => 'bg-teal-100 text-teal-800'],
        'name_correction' => ['label' => 'Name Correction',   'class' => 'bg-yellow-100 text-yellow-800'],
        'other'           => ['label' => 'Other',             'class' => 'bg-slate-100 text-slate-700'],
    ];
    $t = $labels[$type] ?? ['label' => ucfirst($type), 'class' => 'bg-slate-100 text-slate-700'];
    return "<span class='inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider {$t['class']}'>{$t['label']}</span>";
}

// Build query string for pagination (preserving filters)
$queryBase = http_build_query(array_filter([
    'status'    => $filters['status'] ?? '',
    'type'      => $filters['type'] ?? '',
    'pnr'       => $filters['pnr'] ?? '',
    'email'     => $filters['email'] ?? '',
    'date_from' => $filters['date_from'] ?? '',
    'date_to'   => $filters['date_to'] ?? '',
]));
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<title>Acceptance Requests — Base Fare CRM</title>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
<script src="https://cdn.tailwindcss.com?plugins=forms"></script>
<script>
tailwind.config = {
  darkMode: "class",
  theme: {
    extend: {
      fontFamily: { sans: ['Inter', 'Manrope', 'sans-serif'] },
      colors: {
        primary: { DEFAULT: '#0f1e3c', 50: '#f0f4ff', 100: '#dde8ff', 500: '#1a3a6b', 600: '#0f1e3c' },
        gold:    { DEFAULT: '#c9a84c', light: '#f5e6c0' }
      }
    }
  }
}
</script>
</head>
<body class="bg-slate-50 font-sans">

<?php require __DIR__ . '/../layout/sidebar.php'; ?>

<div class="ml-64 min-h-screen">
  <div class="max-w-7xl mx-auto px-6 py-8 space-y-6">

    <!-- Page Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
      <div>
        <p class="text-[10px] font-bold text-primary uppercase tracking-wider mb-1">Acceptance Module</p>
        <h1 class="text-2xl font-bold text-slate-900">Authorization Requests</h1>
        <p class="text-slate-500 text-sm mt-1"><?= number_format($total) ?> total record<?= $total !== 1 ? 's' : '' ?></p>
      </div>
      <a href="/acceptance/create"
         class="inline-flex items-center gap-2 bg-primary-600 hover:bg-primary-500 text-white font-bold py-2.5 px-5 rounded-lg text-sm transition-colors shadow-sm">
        <span class="material-symbols-outlined text-base">add_circle</span>
        New Acceptance Request
      </a>
    </div>

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
    <form method="GET" action="/acceptance" class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm">
      <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">

        <div>
          <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1">Status</label>
          <select name="status" class="w-full text-sm border-slate-200 rounded-lg focus:ring-primary-600 focus:border-primary-600">
            <option value="">All</option>
            <option value="PENDING"   <?= ($filters['status'] ?? '') === 'PENDING'   ? 'selected' : '' ?>>Pending</option>
            <option value="APPROVED"  <?= ($filters['status'] ?? '') === 'APPROVED'  ? 'selected' : '' ?>>Approved</option>
            <option value="EXPIRED"   <?= ($filters['status'] ?? '') === 'EXPIRED'   ? 'selected' : '' ?>>Expired</option>
            <option value="CANCELLED" <?= ($filters['status'] ?? '') === 'CANCELLED' ? 'selected' : '' ?>>Cancelled</option>
          </select>
        </div>

        <div>
          <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1">Type</label>
          <select name="type" class="w-full text-sm border-slate-200 rounded-lg focus:ring-primary-600 focus:border-primary-600">
            <option value="">All Types</option>
            <option value="new_booking"     <?= ($filters['type'] ?? '') === 'new_booking'     ? 'selected' : '' ?>>New Booking</option>
            <option value="exchange"        <?= ($filters['type'] ?? '') === 'exchange'        ? 'selected' : '' ?>>Exchange</option>
            <option value="cancel_refund"   <?= ($filters['type'] ?? '') === 'cancel_refund'   ? 'selected' : '' ?>>Cancel / Refund</option>
            <option value="cancel_credit"   <?= ($filters['type'] ?? '') === 'cancel_credit'   ? 'selected' : '' ?>>Cancel / Credit</option>
            <option value="seat_purchase"   <?= ($filters['type'] ?? '') === 'seat_purchase'   ? 'selected' : '' ?>>Seat Purchase</option>
            <option value="cabin_upgrade"   <?= ($filters['type'] ?? '') === 'cabin_upgrade'   ? 'selected' : '' ?>>Cabin Upgrade</option>
            <option value="name_correction" <?= ($filters['type'] ?? '') === 'name_correction' ? 'selected' : '' ?>>Name Correction</option>
            <option value="other"           <?= ($filters['type'] ?? '') === 'other'           ? 'selected' : '' ?>>Other</option>
          </select>
        </div>

        <div>
          <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1">PNR</label>
          <input type="text" name="pnr" value="<?= htmlspecialchars($filters['pnr'] ?? '') ?>"
                 placeholder="e.g. ABCD12"
                 class="w-full text-sm border-slate-200 rounded-lg focus:ring-primary-600 focus:border-primary-600 uppercase">
        </div>

        <div>
          <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1">Customer Email</label>
          <input type="email" name="email" value="<?= htmlspecialchars($filters['email'] ?? '') ?>"
                 placeholder="customer@email.com"
                 class="w-full text-sm border-slate-200 rounded-lg focus:ring-primary-600 focus:border-primary-600">
        </div>

        <div>
          <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1">From Date</label>
          <input type="date" name="date_from" value="<?= htmlspecialchars($filters['date_from'] ?? '') ?>"
                 class="w-full text-sm border-slate-200 rounded-lg focus:ring-primary-600 focus:border-primary-600">
        </div>

        <div class="flex items-end gap-2">
          <div class="flex-1">
            <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1">To Date</label>
            <input type="date" name="date_to" value="<?= htmlspecialchars($filters['date_to'] ?? '') ?>"
                   class="w-full text-sm border-slate-200 rounded-lg focus:ring-primary-600 focus:border-primary-600">
          </div>
          <button type="submit" class="bg-primary-600 text-white px-3 py-2 rounded-lg hover:bg-primary-500 transition-colors">
            <span class="material-symbols-outlined text-base">search</span>
          </button>
          <a href="/acceptance" class="bg-slate-100 text-slate-600 px-3 py-2 rounded-lg hover:bg-slate-200 transition-colors">
            <span class="material-symbols-outlined text-base">refresh</span>
          </a>
        </div>

      </div>
    </form>

    <!-- Table -->
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-left text-sm">
          <thead class="bg-slate-50 border-b border-slate-100 text-slate-500 text-[10px] font-bold uppercase tracking-wider">
            <tr>
              <th class="px-5 py-3">#ID</th>
              <th class="px-5 py-3">Date</th>
              <th class="px-5 py-3">Customer</th>
              <th class="px-5 py-3">PNR</th>
              <th class="px-5 py-3">Type</th>
              <th class="px-5 py-3">Amount</th>
              <th class="px-5 py-3">Status</th>
              <th class="px-5 py-3">Email</th>
              <?php if (in_array($userRole, ['admin', 'manager'])): ?>
              <th class="px-5 py-3">Agent</th>
              <?php endif; ?>
              <th class="px-5 py-3 text-right">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <?php if ($records->isEmpty()): ?>
            <tr>
              <td colspan="10" class="py-20 text-center">
                <div class="flex flex-col items-center gap-3 text-slate-400">
                  <span class="material-symbols-outlined text-5xl opacity-30">inbox</span>
                  <p class="font-semibold text-slate-500">No acceptance requests found.</p>
                  <a href="/acceptance/create" class="text-primary-600 text-sm font-semibold hover:underline">
                    Create your first one →
                  </a>
                </div>
              </td>
            </tr>
            <?php else: ?>
            <?php foreach ($records as $row): ?>
            <?php
              $created    = $row->created_at ? $row->created_at->format('M j, Y · H:i') : '—';
              $statusBadge = acceptanceStatusBadge($row->status);
              $typeBadge   = acceptanceTypeBadge($row->type);

              // Email status display
              $emailIcon  = match($row->email_status) {
                'SENT'   => ['icon' => 'check_circle',   'color' => 'text-emerald-600'],
                'RESENT' => ['icon' => 'sync',           'color' => 'text-blue-600'],
                'FAILED' => ['icon' => 'error',          'color' => 'text-rose-600'],
                default  => ['icon' => 'schedule',       'color' => 'text-slate-400'],
              };
            ?>
            <tr class="hover:bg-slate-50/50 transition-colors">
              <td class="px-5 py-3.5 text-slate-400 text-xs font-mono">#<?= $row->id ?></td>
              <td class="px-5 py-3.5 text-xs text-slate-500 whitespace-nowrap"><?= $created ?></td>
              <td class="px-5 py-3.5">
                <div class="font-semibold text-slate-900"><?= htmlspecialchars($row->customer_name) ?></div>
                <div class="text-[11px] text-slate-400 mt-0.5"><?= htmlspecialchars($row->customer_email) ?></div>
              </td>
              <td class="px-5 py-3.5">
                <span class="font-mono font-bold text-primary-600 tracking-widest text-sm">
                  <?= htmlspecialchars($row->pnr) ?>
                </span>
              </td>
              <td class="px-5 py-3.5"><?= $typeBadge ?></td>
              <td class="px-5 py-3.5">
                <span class="font-semibold text-slate-700"><?= htmlspecialchars($row->currency) ?></span>
                <span class="font-mono text-slate-900 ml-0.5"><?= number_format($row->total_amount, 2) ?></span>
              </td>
              <td class="px-5 py-3.5">
                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider <?= $statusBadge ?>">
                  <?= $row->status ?>
                </span>
              </td>
              <td class="px-5 py-3.5">
                <div class="flex items-center gap-1">
                  <span class="material-symbols-outlined text-base <?= $emailIcon['color'] ?>"><?= $emailIcon['icon'] ?></span>
                  <span class="text-xs text-slate-500"><?= $row->email_status ?></span>
                </div>
                <?php if ($row->email_attempts > 1): ?>
                <div class="text-[10px] text-slate-400 mt-0.5"><?= $row->email_attempts ?> attempts</div>
                <?php endif; ?>
              </td>
              <?php if (in_array($userRole, ['admin', 'manager'])): ?>
              <td class="px-5 py-3.5 text-xs text-slate-500">
                <?= htmlspecialchars($row->agent->name ?? '—') ?>
              </td>
              <?php endif; ?>
              <td class="px-5 py-3.5">
                <div class="flex items-center gap-1.5 justify-end">
                  <?php if ($row->status === 'APPROVED'): ?>
                  <a href="/acceptance/<?= $row->id ?>/receipt" target="_blank"
                     class="inline-flex items-center gap-1 bg-emerald-50 hover:bg-emerald-100 text-emerald-700 border border-emerald-200 font-semibold py-1.5 px-2.5 rounded-lg text-xs transition-colors">
                    <span class="material-symbols-outlined text-sm">receipt_long</span> Receipt
                  </a>
                  <?php else: ?>
                  <a href="/acceptance/<?= $row->id ?>"
                     class="inline-flex items-center gap-1 bg-slate-50 hover:bg-slate-100 text-slate-700 border border-slate-200 font-semibold py-1.5 px-2.5 rounded-lg text-xs transition-colors">
                    <span class="material-symbols-outlined text-sm">visibility</span> View
                  </a>
                  <?php endif; ?>

                  <?php if (in_array($row->email_status, ['PENDING', 'FAILED']) && $row->status === 'PENDING'): ?>
                  <button onclick="resendAcceptance(<?= $row->id ?>)"
                          class="inline-flex items-center gap-1 bg-amber-50 hover:bg-amber-100 text-amber-700 border border-amber-200 font-semibold py-1.5 px-2.5 rounded-lg text-xs transition-colors">
                    <span class="material-symbols-outlined text-sm">send</span> Resend
                  </button>
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
          Page <?= $page ?> of <?= $totalPages ?> &middot; <?= number_format($total) ?> records
        </span>
        <div class="flex gap-1">
          <?php if ($page > 1): ?>
          <a href="/acceptance?page=<?= $page - 1 ?>&<?= $queryBase ?>"
             class="w-8 h-8 flex items-center justify-center rounded-lg text-xs font-bold bg-white text-slate-600 border border-slate-200 hover:bg-slate-50 transition-colors">
            <span class="material-symbols-outlined text-sm">chevron_left</span>
          </a>
          <?php endif; ?>

          <?php
          // Show smart page range (max 5 pages shown)
          $start = max(1, $page - 2);
          $end   = min($totalPages, $page + 2);
          for ($i = $start; $i <= $end; $i++):
          ?>
          <a href="/acceptance?page=<?= $i ?>&<?= $queryBase ?>"
             class="w-8 h-8 flex items-center justify-center rounded-lg text-xs font-bold transition-colors <?= $i === $page ? 'bg-primary-600 text-white shadow-sm' : 'bg-white text-slate-600 border border-slate-200 hover:bg-slate-50' ?>">
            <?= $i ?>
          </a>
          <?php endfor; ?>

          <?php if ($page < $totalPages): ?>
          <a href="/acceptance?page=<?= $page + 1 ?>&<?= $queryBase ?>"
             class="w-8 h-8 flex items-center justify-center rounded-lg text-xs font-bold bg-white text-slate-600 border border-slate-200 hover:bg-slate-50 transition-colors">
            <span class="material-symbols-outlined text-sm">chevron_right</span>
          </a>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

    </div><!-- /table card -->

  </div><!-- /max-w -->
</div><!-- /ml-64 -->

<script>
async function resendAcceptance(id) {
  if (!confirm('Resend authorization email? This will reset the expiry to 12 hours from now.')) return;

  const btn = event.currentTarget;
  btn.disabled = true;
  btn.textContent = 'Sending...';

  try {
    const res  = await fetch(`/acceptance/${id}/resend`, { method: 'POST' });
    const data = await res.json();

    if (data.success) {
      // Show the link in case email service is stubbed
      if (data.note) {
        const link = data.link;
        prompt('Email sent (stub mode). Copy the link to send manually:', link);
      } else {
        alert('Email resent successfully!');
      }
      location.reload();
    } else {
      alert('Error: ' + (data.error || 'Failed to resend.'));
      btn.disabled = false;
      btn.textContent = 'Resend';
    }
  } catch (e) {
    alert('Network error. Please try again.');
    btn.disabled = false;
    btn.textContent = 'Resend';
  }
}
</script>

</body>
</html>
