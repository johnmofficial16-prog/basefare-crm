<?php
/**
 * Public E-Ticket Portal
 * URL: https://crm.base-fare.com/eticket/?token={token}
 *       OR /eticket/index.php?token={token}
 *
 * Fully standalone — no Slim, no CRM auth required.
 * Access is controlled ONLY by the token.
 *
 * Flow:
 *   GET  ?token=xxx   → show e-ticket (or "already acknowledged" state)
 *   POST token=xxx    → record acknowledgment → redirect back to GET
 */

// ─── Bootstrap ────────────────────────────────────────────────────────────────
$basePath = dirname(__DIR__, 2); // /public/eticket → project root
require $basePath . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable($basePath);
try { $dotenv->load(); } catch (\Throwable $e) {}

// Boot Eloquent (standalone — no Slim)
$capsule = new \Illuminate\Database\Capsule\Manager;
$capsule->addConnection([
    'driver'    => 'mysql',
    'host'      => $_ENV['DB_HOST']     ?? '127.0.0.1',
    'port'      => $_ENV['DB_PORT']     ?? '3306',
    'database'  => $_ENV['DB_DATABASE'] ?? 'basefare_crm',
    'username'  => $_ENV['DB_USERNAME'] ?? 'root',
    'password'  => $_ENV['DB_PASSWORD'] ?? '',
    'charset'   => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
]);
$capsule->setAsGlobal();
$capsule->bootEloquent();

use App\Models\ETicket;
use App\Models\RecordNote;
use App\Services\ETicketEmailService;
use Carbon\Carbon;

// ─── Session ──────────────────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ─── Token resolution ─────────────────────────────────────────────────────────
$rawToken = trim($_GET['token'] ?? $_POST['token'] ?? '');
$eticket  = $rawToken ? ETicket::where('token', $rawToken)->first() : null;

// ─── Helper: record acknowledgment ───────────────────────────────────────────
function recordAcknowledgment(ETicket $eticket, string $rawToken): void {
    if ($eticket->isAcknowledged()) return;

    $ip = $_SERVER['HTTP_X_FORWARDED_FOR']
        ?? $_SERVER['HTTP_X_REAL_IP']
        ?? $_SERVER['REMOTE_ADDR']
        ?? 'unknown';
    $ip = trim(explode(',', $ip)[0]);
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';

    $eticket->update([
        'status'          => ETicket::STATUS_ACKNOWLEDGED,
        'acknowledged_at' => Carbon::now(),
        'acknowledged_ip' => $ip,
        'acknowledged_ua' => $ua,
    ]);

    try {
        RecordNote::log('eticket', $eticket->id, 0,
            "E-Ticket acknowledged by customer. IP: {$ip}", 'acknowledged');
    } catch (\Throwable $e) {}

    try {
        (new ETicketEmailService())->sendAcknowledgmentNotice($eticket->fresh());
    } catch (\Throwable $e) {}
}

// ─── GET: One-click acknowledge from email button ─────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET'
    && ($rawToken !== '')
    && (($_GET['action'] ?? '') === 'acknowledge')
    && $eticket) {
    recordAcknowledgment($eticket, $rawToken);
    header('Location: /eticket/?token=' . urlencode($rawToken) . '&acked=1', true, 303);
    exit;
}

// ─── POST: On-page form acknowledge (fallback) ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $eticket) {
    recordAcknowledgment($eticket, $rawToken);
    header('Location: /eticket/?token=' . urlencode($rawToken) . '&acked=1', true, 303);
    exit;
}

// ─── Invalid token ────────────────────────────────────────────────────────────
if (!$eticket) {
    http_response_code(404);
    ?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <title>E-Ticket Not Found — Lets Fly Travel</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&display=swap" rel="stylesheet">
  <style>
    body { font-family: Inter, sans-serif; background: #f0f4f8; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
    .card { background: #fff; border-radius: 16px; padding: 48px 40px; text-align: center; max-width: 440px; box-shadow: 0 8px 40px rgba(0,0,0,.1); }
    h1 { font-size: 48px; margin: 0 0 12px; }
    h2 { color: #0f1e3c; font-size: 20px; font-weight: 800; margin: 0 0 8px; }
    p  { color: #64748b; font-size: 14px; line-height: 1.7; }
    a  { color: #1d4ed8; font-weight: 600; }
  </style>
</head>
<body>
  <div class="card">
    <h1>🔍</h1>
    <h2>E-Ticket Not Found</h2>
    <p>This link appears to be invalid or may have already expired.<br>
       Please contact us at <a href="mailto:reservation@base-fare.com">reservation@base-fare.com</a> if you believe this is an error.</p>
  </div>
</body>
</html><?php
    exit;
}

// ─── Render e-ticket view ─────────────────────────────────────────────────────
// Delegate to the existing polished view — just include it with $eticket in scope
require $basePath . '/app/Views/eticket/public_eticket.php';
