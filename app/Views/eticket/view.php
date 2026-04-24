<?php
/**
 * E-Ticket — Internal Agent View
 *
 * @var \App\Models\ETicket $eticket
 * @var \Illuminate\Support\Collection $notes
 * @var bool $created
 * @var bool $sent
 */

use App\Models\ETicket;

$layout    = __DIR__ . '/../layout/base.php';
$pageTitle = 'E-Ticket — ' . $eticket->pnr;

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf   = $_SESSION['csrf_token'];
$et     = $eticket;
$etId   = 'ET-' . str_pad($et->id, 6, '0', STR_PAD_LEFT);
$pax    = $et->ticketDataWithAutoNumbers();

ob_start();
?>
<style>
.et-detail-card { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:24px 28px; margin-bottom:20px; }
.et-detail-title { font-size:11px; font-weight:800; color:#94a3b8; text-transform:uppercase; letter-spacing:1px; margin:0 0 14px; }
.et-kv { display:flex; flex-direction:column; gap:2px; margin-bottom:14px; }
.et-kv-label { font-size:10px; color:#94a3b8; text-transform:uppercase; letter-spacing:.5px; font-weight:700; }
.et-kv-value { font-size:14px; color:#1e293b; font-weight:600; }
.status-badge-lg { display:inline-flex; align-items:center; gap:8px; font-size:13px; font-weight:800; padding:8px 20px; border-radius:999px; text-transform:uppercase; letter-spacing:.5px; }
.note-bubble { background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:12px 16px; margin-bottom:10px; }
</style>

<!-- Back + ID -->
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;">
  <div style="display:flex;align-items:center;gap:16px;">
    <a href="/etickets" style="color:#64748b;font-size:13px;text-decoration:none;padding:7px 14px;border:1px solid #e2e8f0;border-radius:7px;">← Back</a>
    <div>
      <div style="font-size:11px;color:#94a3b8;font-weight:700;text-transform:uppercase;">E-Ticket</div>
      <div style="font-size:22px;font-weight:800;color:#0f1e3c;font-family:monospace;"><?= $etId ?></div>
    </div>
  </div>

  <!-- Status Badge -->
  <div>
    <?php
    $badgeStyle = match($et->status) {
        ETicket::STATUS_ACKNOWLEDGED => 'background:#dcfce7;color:#15803d;border:2px solid #86efac;',
        ETicket::STATUS_SENT         => 'background:#dbeafe;color:#1d4ed8;border:2px solid #93c5fd;',
        default                      => 'background:#f3f4f6;color:#6b7280;border:2px solid #d1d5db;',
    };
    $badgeIcon = match($et->status) {
        ETicket::STATUS_ACKNOWLEDGED => '✅',
        ETicket::STATUS_SENT         => '✉',
        default                      => '●',
    };
    ?>
    <span class="status-badge-lg" style="<?= $badgeStyle ?>"><?= $badgeIcon ?> <?= $et->statusLabel() ?></span>
  </div>
</div>

<!-- Alert Banners -->
<?php if ($created): ?><div style="background:#ecfdf5;border:1px solid #6ee7b7;border-radius:8px;padding:12px 18px;margin-bottom:16px;font-size:13px;color:#065f46;font-weight:600;">✅ E-Ticket created successfully.</div><?php endif; ?>
<?php if ($sent): ?><div style="background:#eff6ff;border:1px solid #93c5fd;border-radius:8px;padding:12px 18px;margin-bottom:16px;font-size:13px;color:#1d4ed8;font-weight:600;">✉ E-Ticket emailed successfully.</div><?php endif; ?>
<?php if (isset($_GET['send_error'])): ?><div style="background:#fee2e2;border:1px solid #fca5a5;border-radius:8px;padding:12px 18px;margin-bottom:16px;font-size:13px;color:#991b1b;font-weight:600;">⚠ Email failed to send. Please check SMTP settings and try again.</div><?php endif; ?>

<div style="display:grid;grid-template-columns:2fr 1fr;gap:20px;">

  <!-- LEFT COLUMN -->
  <div>

    <!-- Customer + Booking -->
    <div class="et-detail-card">
      <div class="et-detail-title">Customer & Booking</div>
      <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;">
        <div class="et-kv">
          <span class="et-kv-label">Customer Name</span>
          <span class="et-kv-value"><?= htmlspecialchars($et->customer_name) ?></span>
        </div>
        <div class="et-kv">
          <span class="et-kv-label">Email</span>
          <span class="et-kv-value" style="font-size:12px;"><?= htmlspecialchars($et->customer_email) ?></span>
        </div>
        <div class="et-kv">
          <span class="et-kv-label">Phone</span>
          <span class="et-kv-value"><?= htmlspecialchars($et->customer_phone ?: '—') ?></span>
        </div>
        <div class="et-kv">
          <span class="et-kv-label">PNR</span>
          <span class="et-kv-value" style="font-family:monospace;font-size:18px;letter-spacing:2px;color:#0f1e3c;"><?= htmlspecialchars($et->pnr) ?></span>
        </div>
        <div class="et-kv">
          <span class="et-kv-label">Airline</span>
          <span class="et-kv-value"><?= htmlspecialchars($et->airline ?: '—') ?></span>
        </div>
        <div class="et-kv">
          <span class="et-kv-label">Order #</span>
          <span class="et-kv-value"><?= htmlspecialchars($et->order_id ?: '—') ?></span>
        </div>
        <div class="et-kv">
          <span class="et-kv-label">Total Amount</span>
          <span class="et-kv-value" style="font-size:18px;color:#065f46;"><?= htmlspecialchars($et->currency) ?> <?= number_format($et->total_amount, 2) ?></span>
        </div>
        <div class="et-kv">
          <span class="et-kv-label">Transaction #</span>
          <a href="/transactions/<?= $et->transaction_id ?>" style="font-size:13px;color:#1a3a6b;font-weight:700;text-decoration:none;">TXN-<?= $et->transaction_id ?> ↗</a>
        </div>
        <?php if ($et->acceptance_id): ?>
        <div class="et-kv">
          <span class="et-kv-label">Acceptance #</span>
          <a href="/acceptance/<?= $et->acceptance_id ?>" style="font-size:13px;color:#1a3a6b;font-weight:700;text-decoration:none;">ACC-<?= $et->acceptance_id ?> ↗</a>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Passengers -->
    <div class="et-detail-card">
      <div class="et-detail-title">Passengers & E-Ticket Numbers</div>
      <table style="width:100%;border-collapse:collapse;">
        <thead>
          <tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0;">
            <th style="padding:8px 14px;text-align:left;font-size:10px;font-weight:800;text-transform:uppercase;color:#94a3b8;">#</th>
            <th style="padding:8px 14px;text-align:left;font-size:10px;font-weight:800;text-transform:uppercase;color:#94a3b8;">Passenger</th>
            <th style="padding:8px 14px;text-align:left;font-size:10px;font-weight:800;text-transform:uppercase;color:#94a3b8;">Type</th>
            <th style="padding:8px 14px;text-align:left;font-size:10px;font-weight:800;text-transform:uppercase;color:#94a3b8;">E-Ticket #</th>
            <th style="padding:8px 14px;text-align:left;font-size:10px;font-weight:800;text-transform:uppercase;color:#94a3b8;">Seat</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($pax as $i => $p): ?>
          <tr style="border-bottom:1px solid #f1f5f9;">
            <td style="padding:10px 14px;font-size:12px;color:#94a3b8;"><?= $i+1 ?></td>
            <td style="padding:10px 14px;font-weight:700;color:#1e293b;"><?= htmlspecialchars($p['pax_name'] ?? '') ?></td>
            <td style="padding:10px 14px;color:#64748b;font-size:12px;text-transform:capitalize;"><?= htmlspecialchars($p['pax_type'] ?? 'adult') ?></td>
            <td style="padding:10px 14px;font-family:monospace;font-weight:800;font-size:13px;color:#1e40af;"><?= htmlspecialchars($p['ticket_number'] ?? '—') ?></td>
            <td style="padding:10px 14px;font-weight:600;color:#6366f1;"><?= htmlspecialchars($p['seat'] ?? '—') ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- Flight Itinerary -->
    <?php if ($et->flight_data): ?>
    <div class="et-detail-card">
      <div class="et-detail-title">Flight Itinerary</div>
      <?php foreach ((array)$et->flight_data as $i => $f): ?>
      <div style="display:flex;align-items:center;gap:16px;padding:16px 0;<?= $i > 0 ? 'border-top:1px solid #f1f5f9;' : '' ?>">
        <div style="text-align:center;min-width:64px;">
          <div style="font-size:22px;font-weight:900;color:#0f1e3c;font-family:monospace;"><?= htmlspecialchars($f['departure_airport'] ?? $f['from'] ?? '???') ?></div>
          <div style="font-size:11px;color:#94a3b8;"><?= htmlspecialchars($f['departure_date'] ?? $f['date'] ?? '') ?></div>
          <div style="font-size:12px;font-weight:700;color:#475569;"><?= htmlspecialchars($f['departure_time'] ?? $f['time'] ?? '') ?></div>
        </div>
        <div style="flex:1;text-align:center;">
          <div style="font-size:11px;color:#94a3b8;margin-bottom:4px;">
            <?= htmlspecialchars($f['flight_number'] ?? $f['flight'] ?? '') ?>
            <?php if (!empty($f['cabin_class'] ?? $f['class'] ?? '')): ?>&nbsp;·&nbsp;<?= htmlspecialchars($f['cabin_class'] ?? $f['class'] ?? '') ?><?php endif; ?>
          </div>
          <div style="display:flex;align-items:center;gap:4px;">
            <div style="flex:1;height:1px;background:#cbd5e1;"></div>
            <span style="font-size:16px;color:#94a3b8;">✈</span>
            <div style="flex:1;height:1px;background:#cbd5e1;"></div>
          </div>
          <?php if (!empty($f['duration'])): ?>
          <div style="font-size:10px;color:#94a3b8;margin-top:2px;"><?= htmlspecialchars($f['duration']) ?></div>
          <?php endif; ?>
        </div>
        <div style="text-align:center;min-width:64px;">
          <div style="font-size:22px;font-weight:900;color:#0f1e3c;font-family:monospace;"><?= htmlspecialchars($f['arrival_airport'] ?? $f['to'] ?? '???') ?></div>
          <div style="font-size:11px;color:#94a3b8;"><?= htmlspecialchars($f['arrival_date'] ?? '') ?></div>
          <div style="font-size:12px;font-weight:700;color:#475569;"><?= htmlspecialchars($f['arrival_time'] ?? '') ?></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Conditions -->
    <?php if ($et->endorsements || $et->baggage_info || $et->fare_rules): ?>
    <div class="et-detail-card">
      <div class="et-detail-title">Ticket Conditions</div>
      <?php if ($et->endorsements): ?>
      <div class="et-kv" style="margin-bottom:16px;">
        <span class="et-kv-label">Endorsements / Restrictions</span>
        <span class="et-kv-value" style="font-size:13px;font-family:monospace;"><?= nl2br(htmlspecialchars($et->endorsements)) ?></span>
      </div>
      <?php endif; ?>
      <?php if ($et->baggage_info): ?>
      <div class="et-kv" style="margin-bottom:16px;">
        <span class="et-kv-label">Baggage Allowance</span>
        <span class="et-kv-value" style="font-size:13px;"><?= nl2br(htmlspecialchars($et->baggage_info)) ?></span>
      </div>
      <?php endif; ?>
      <?php if ($et->fare_rules): ?>
      <div class="et-kv">
        <span class="et-kv-label">Fare Rules</span>
        <span class="et-kv-value" style="font-size:13px;"><?= nl2br(htmlspecialchars($et->fare_rules)) ?></span>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Notes -->
    <div class="et-detail-card">
      <div class="et-detail-title">Activity Log</div>
      <?php if ($notes->isEmpty()): ?>
      <p style="color:#94a3b8;font-size:13px;">No notes yet.</p>
      <?php else: ?>
      <?php foreach ($notes as $note): ?>
      <div class="note-bubble">
        <div style="display:flex;justify-content:space-between;margin-bottom:4px;">
          <span style="font-size:12px;font-weight:700;color:#475569;"><?= htmlspecialchars($note->user?->name ?? ($note->user_id == 0 ? 'Customer' : 'System')) ?></span>
          <span style="font-size:11px;color:#94a3b8;"><?= $note->created_at->format('M j, Y g:i A') ?></span>
        </div>
        <p style="font-size:13px;color:#1e293b;margin:0;"><?= nl2br(htmlspecialchars($note->content ?? $note->note ?? '')) ?></p>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>

      <!-- Add Note -->
      <form method="POST" action="/etickets/<?= $et->id ?>/note" style="margin-top:14px;display:flex;gap:10px;">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="text" name="note" placeholder="Add internal note..." required
               style="flex:1;border:1px solid #e2e8f0;border-radius:7px;padding:9px 13px;font-size:13px;font-family:inherit;">
        <button type="submit" style="background:#0f1e3c;color:#fff;border:none;padding:9px 18px;border-radius:7px;font-size:13px;font-weight:700;cursor:pointer;">Add</button>
      </form>
    </div>

  </div>

  <!-- RIGHT COLUMN -->
  <div>

    <!-- Actions -->
    <div class="et-detail-card">
      <div class="et-detail-title">Actions</div>

      <!-- Send E-Ticket -->
      <div style="margin-bottom:16px;">
        <div style="font-size:12px;font-weight:700;color:#475569;margin-bottom:8px;">✉ Send E-Ticket</div>
        <form method="POST" action="/etickets/<?= $et->id ?>/send">
          <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
          <button type="submit" style="width:100%;background:linear-gradient(135deg,#0f1e3c,#1a3a6b);color:#fff;border:none;padding:12px;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;margin-bottom:8px;">
            <?= $et->isSent() ? '✉ Resend E-Ticket' : '✉ Send E-Ticket' ?>
          </button>
          <?php if ($et->last_emailed_at): ?>
          <div style="font-size:11px;color:#94a3b8;text-align:center;">Last sent: <?= $et->last_emailed_at->format('M j, g:i A') ?> to <?= htmlspecialchars($et->sent_to_email ?? $et->customer_email) ?></div>
          <?php endif; ?>
        </form>
      </div>

      <!-- Resend to Different Email -->
      <div style="border-top:1px solid #f1f5f9;padding-top:14px;">
        <div style="font-size:12px;font-weight:700;color:#475569;margin-bottom:8px;">↪ Resend to Different Email</div>
        <form method="POST" action="/etickets/<?= $et->id ?>/send">
          <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
          <input type="email" name="resend_email" placeholder="Alternate email address" required
                 style="width:100%;border:1px solid #e2e8f0;border-radius:7px;padding:9px 13px;font-size:13px;margin-bottom:8px;box-sizing:border-box;font-family:inherit;">
          <button type="submit" style="width:100%;background:#f8fafc;border:1px solid #e2e8f0;color:#475569;padding:10px;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;">
            Send to This Address
          </button>
        </form>
      </div>
    </div>

    <!-- Acknowledgment Info -->
    <div class="et-detail-card">
      <div class="et-detail-title">Acknowledgment</div>
      <?php if ($et->isAcknowledged()): ?>
      <div style="background:#dcfce7;border:1px solid #86efac;border-radius:8px;padding:14px;margin-bottom:14px;text-align:center;">
        <div style="font-size:24px;margin-bottom:4px;">✅</div>
        <div style="font-size:13px;font-weight:800;color:#15803d;">Acknowledged</div>
        <div style="font-size:11px;color:#16a34a;margin-top:2px;"><?= $et->acknowledged_at->format('F j, Y \a\t g:i:s A') ?></div>
      </div>
      <div style="font-size:11px;color:#64748b;line-height:1.6;">
        <div><strong>IP:</strong> <?= htmlspecialchars($et->acknowledged_ip ?? 'N/A') ?></div>
      </div>
      <?php else: ?>
      <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:14px;text-align:center;margin-bottom:12px;">
        <div style="font-size:13px;color:#94a3b8;">Awaiting customer acknowledgment</div>
      </div>
      <?php endif; ?>
      <div style="font-size:10px;color:#94a3b8;word-break:break-all;margin-top:8px;">
        <div style="font-weight:700;margin-bottom:2px;">Public Link:</div>
        <a href="<?= htmlspecialchars($et->publicUrl()) ?>" target="_blank" style="color:#1a3a6b;text-decoration:none;"><?= htmlspecialchars($et->publicUrl()) ?></a>
      </div>
    </div>

    <!-- Email Status -->
    <div class="et-detail-card">
      <div class="et-detail-title">Email Status</div>
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;">
        <?php
        $emailIcon = match($et->email_status) {
            'SENT','RESENT' => '✅', 'FAILED' => '❌', default => '⏳',
        };
        $emailColor = match($et->email_status) {
            'SENT','RESENT' => '#15803d', 'FAILED' => '#991b1b', default => '#94a3b8',
        };
        ?>
        <span style="font-size:20px;"><?= $emailIcon ?></span>
        <span style="font-size:13px;font-weight:700;color:<?= $emailColor ?>;"><?= htmlspecialchars($et->email_status) ?></span>
      </div>
      <div style="font-size:11px;color:#94a3b8;line-height:1.8;">
        <div>Attempts: <strong><?= $et->email_attempts ?></strong></div>
        <?php if ($et->sent_to_email): ?>
        <div>Last to: <strong><?= htmlspecialchars($et->sent_to_email) ?></strong></div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Meta -->
    <div class="et-detail-card">
      <div class="et-detail-title">Record Info</div>
      <div style="font-size:11px;color:#94a3b8;line-height:2;">
        <div>Agent: <strong style="color:#475569;"><?= htmlspecialchars($et->agent?->name ?? '—') ?></strong></div>
        <div>Created: <strong style="color:#475569;"><?= $et->created_at->format('M j, Y g:i A') ?></strong></div>
        <div>Updated: <strong style="color:#475569;"><?= $et->updated_at->format('M j, Y g:i A') ?></strong></div>
      </div>
    </div>

  </div><!-- end right col -->
</div>

<?php
$content = ob_get_clean();
require $layout;
