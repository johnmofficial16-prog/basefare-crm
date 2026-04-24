<?php
/**
 * E-Ticket Module — List View
 *
 * @var array  $records
 * @var int    $total
 * @var int    $page
 * @var int    $per_page
 * @var int    $total_pages
 * @var array  $filters
 * @var bool   $isAdmin
 * @var string $role
 */

$layout = __DIR__ . '/../layout/base.php';
$pageTitle = 'E-Tickets';

function etStatusBadge(string $status): string {
    return match($status) {
        'acknowledged' => '<span style="display:inline-flex;align-items:center;gap:4px;background:#dcfce7;color:#15803d;border:1px solid #86efac;font-size:10px;font-weight:800;padding:3px 10px;border-radius:999px;text-transform:uppercase;letter-spacing:.5px;">✓ Acknowledged</span>',
        'sent'         => '<span style="display:inline-flex;align-items:center;gap:4px;background:#dbeafe;color:#1d4ed8;border:1px solid #93c5fd;font-size:10px;font-weight:800;padding:3px 10px;border-radius:999px;text-transform:uppercase;letter-spacing:.5px;">✉ Sent</span>',
        'draft'        => '<span style="display:inline-flex;align-items:center;gap:4px;background:#f3f4f6;color:#6b7280;border:1px solid #d1d5db;font-size:10px;font-weight:800;padding:3px 10px;border-radius:999px;text-transform:uppercase;letter-spacing:.5px;">● Draft</span>',
        default        => htmlspecialchars(ucfirst($status)),
    };
}

ob_start();
?>
<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;">
  <div>
    <h1 style="font-size:22px;font-weight:800;color:#0f1e3c;margin:0;">✈ E-Tickets</h1>
    <p style="font-size:13px;color:#64748b;margin:4px 0 0;">Issue and manage customer e-tickets</p>
  </div>
  <a href="/etickets/create" style="display:inline-flex;align-items:center;gap:6px;background:linear-gradient(135deg,#0f1e3c,#1a3a6b);color:#fff;padding:10px 20px;border-radius:8px;font-size:13px;font-weight:700;text-decoration:none;">
    + New E-Ticket
  </a>
</div>

<!-- Filters -->
<form method="GET" action="/etickets" style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:16px 20px;margin-bottom:20px;display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;">
  <div>
    <label style="font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;display:block;margin-bottom:4px;">Status</label>
    <select name="status" style="border:1px solid #e2e8f0;border-radius:6px;padding:7px 12px;font-size:13px;color:#1e293b;background:#fff;">
      <option value="">All Statuses</option>
      <?php foreach (['draft','sent','acknowledged'] as $s): ?>
      <option value="<?= $s ?>" <?= ($filters['status'] ?? '') === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label style="font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;display:block;margin-bottom:4px;">PNR / Search</label>
    <input type="text" name="search" value="<?= htmlspecialchars($filters['search'] ?? '') ?>" placeholder="Name, email, PNR..."
           style="border:1px solid #e2e8f0;border-radius:6px;padding:7px 12px;font-size:13px;width:220px;">
  </div>
  <div>
    <label style="font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;display:block;margin-bottom:4px;">From</label>
    <input type="date" name="date_from" value="<?= htmlspecialchars($filters['date_from'] ?? '') ?>"
           style="border:1px solid #e2e8f0;border-radius:6px;padding:7px 12px;font-size:13px;">
  </div>
  <div>
    <label style="font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;display:block;margin-bottom:4px;">To</label>
    <input type="date" name="date_to" value="<?= htmlspecialchars($filters['date_to'] ?? '') ?>"
           style="border:1px solid #e2e8f0;border-radius:6px;padding:7px 12px;font-size:13px;">
  </div>
  <button type="submit" style="background:#0f1e3c;color:#fff;border:none;padding:8px 18px;border-radius:6px;font-size:13px;font-weight:700;cursor:pointer;">Filter</button>
  <a href="/etickets" style="color:#64748b;font-size:13px;padding:8px 12px;text-decoration:none;">Clear</a>
</form>

<!-- Table -->
<div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;">
  <?php if ($total === 0): ?>
  <div style="text-align:center;padding:60px 20px;color:#94a3b8;">
    <div style="font-size:48px;margin-bottom:12px;">✈</div>
    <div style="font-size:16px;font-weight:700;color:#475569;margin-bottom:6px;">No e-tickets found</div>
    <p style="font-size:13px;">Create your first e-ticket by clicking "New E-Ticket" above.</p>
  </div>
  <?php else: ?>
  <table style="width:100%;border-collapse:collapse;font-size:13px;">
    <thead>
      <tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0;">
        <th style="padding:12px 16px;text-align:left;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.8px;color:#94a3b8;">ID</th>
        <th style="padding:12px 16px;text-align:left;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.8px;color:#94a3b8;">Customer</th>
        <th style="padding:12px 16px;text-align:left;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.8px;color:#94a3b8;">PNR</th>
        <th style="padding:12px 16px;text-align:left;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.8px;color:#94a3b8;">Airline</th>
        <th style="padding:12px 16px;text-align:right;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.8px;color:#94a3b8;">Amount</th>
        <th style="padding:12px 16px;text-align:left;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.8px;color:#94a3b8;">Status</th>
        <?php if ($isAdmin): ?><th style="padding:12px 16px;text-align:left;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.8px;color:#94a3b8;">Agent</th><?php endif; ?>
        <th style="padding:12px 16px;text-align:left;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.8px;color:#94a3b8;">Created</th>
        <th style="padding:12px 16px;"></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($records as $et): ?>
      <tr style="border-bottom:1px solid #f1f5f9;transition:background .12s;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
        <td style="padding:14px 16px;font-family:monospace;font-weight:700;color:#0f1e3c;">ET-<?= str_pad($et->id, 6, '0', STR_PAD_LEFT) ?></td>
        <td style="padding:14px 16px;">
          <div style="font-weight:700;color:#1e293b;"><?= htmlspecialchars($et->customer_name) ?></div>
          <div style="font-size:11px;color:#94a3b8;"><?= htmlspecialchars($et->customer_email) ?></div>
        </td>
        <td style="padding:14px 16px;font-family:monospace;font-weight:800;color:#0f1e3c;letter-spacing:1px;"><?= htmlspecialchars($et->pnr) ?></td>
        <td style="padding:14px 16px;color:#475569;"><?= htmlspecialchars($et->airline ?? '—') ?></td>
        <td style="padding:14px 16px;text-align:right;font-weight:700;color:#1e293b;font-family:monospace;"><?= htmlspecialchars($et->currency) ?> <?= number_format($et->total_amount, 2) ?></td>
        <td style="padding:14px 16px;"><?= etStatusBadge($et->status) ?>
          <?php if ($et->acknowledged_at): ?>
          <div style="font-size:10px;color:#64748b;margin-top:3px;"><?= $et->acknowledged_at->format('M j, g:i A') ?></div>
          <?php endif; ?>
        </td>
        <?php if ($isAdmin): ?>
        <td style="padding:14px 16px;color:#64748b;font-size:12px;"><?= htmlspecialchars($et->agent?->name ?? '—') ?></td>
        <?php endif; ?>
        <td style="padding:14px 16px;color:#94a3b8;font-size:12px;"><?= $et->created_at->format('M j, Y') ?></td>
        <td style="padding:14px 16px;text-align:right;">
          <a href="/etickets/<?= $et->id ?>" style="font-size:12px;font-weight:700;color:#0f1e3c;text-decoration:none;padding:6px 14px;border:1px solid #e2e8f0;border-radius:6px;transition:background .12s;" onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background=''">View</a>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <!-- Pagination -->
  <?php if ($total_pages > 1): ?>
  <div style="padding:14px 16px;display:flex;align-items:center;justify-content:space-between;border-top:1px solid #f1f5f9;">
    <div style="font-size:12px;color:#94a3b8;">
      Showing <?= (($page-1)*$per_page)+1 ?>–<?= min($page*$per_page, $total) ?> of <?= $total ?> e-tickets
    </div>
    <div style="display:flex;gap:6px;">
      <?php for ($i = 1; $i <= $total_pages; $i++): ?>
      <a href="?page=<?= $i ?>&<?= http_build_query(array_filter($filters)) ?>"
         style="padding:5px 10px;border-radius:5px;font-size:12px;font-weight:700;text-decoration:none;<?= $i === $page ? 'background:#0f1e3c;color:#fff;' : 'background:#f1f5f9;color:#475569;' ?>"><?= $i ?></a>
      <?php endfor; ?>
    </div>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
require $layout;
