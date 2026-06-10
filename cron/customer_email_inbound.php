<?php
/**
 * Customer Email Inbound Poller — IMAP Cron Script
 *
 * Run every 5 minutes via Hostinger cron:
 *   php /home/.../crm/cron/customer_email_inbound.php
 *
 * Pulls customer REPLIES to the Customer Emails module back into the CRM and
 * threads them onto the right conversation.
 *
 * Logic:
 *   1. Exit immediately if IMAP_ENABLED is false.
 *   2. Connect to the IMAP mailbox (same creds as the e-ticket reply cron).
 *   3. Scan all messages from the last 12 hours (not just UNSEEN, so a reply
 *      read in Gmail first is never missed).
 *   4. For each: read Message-ID + In-Reply-To + References headers.
 *      a. Skip if we've already stored this Message-ID (dedup).
 *      b. Match to a thread by our outgoing Message-IDs referenced in
 *         In-Reply-To / References; fall back to the sender's open thread.
 *      c. Store the reply via CustomerEmailService::recordInbound() and flip
 *         the thread to "needs reply".
 *   5. Log to storage/logs/customer_email_imap.log.
 *
 * Fully isolated — failure has zero impact on the web application.
 * Prereqs: PHP ext-imap enabled on Hostinger; IMAP_* set in .env.
 */

require __DIR__ . '/../vendor/autoload.php';

use Illuminate\Database\Capsule\Manager as Capsule;
use Dotenv\Dotenv;
use App\Models\CustomerEmailMessage;
use App\Services\CustomerEmailService;

// ── Bootstrap ───────────────────────────────────────────────────────────────
$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();
date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'Asia/Kolkata');

$capsule = new Capsule;
$capsule->addConnection([
    'driver'    => 'mysql',
    'host'      => $_ENV['DB_HOST'],
    'database'  => $_ENV['DB_DATABASE'],
    'username'  => $_ENV['DB_USERNAME'],
    'password'  => $_ENV['DB_PASSWORD'],
    'charset'   => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
]);
$capsule->setAsGlobal();
$capsule->bootEloquent();

$logFile = __DIR__ . '/../storage/logs/customer_email_imap.log';
function cemLog(string $message): void
{
    global $logFile;
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    echo $line;
}

// ── Guards ──────────────────────────────────────────────────────────────────
if (!filter_var($_ENV['IMAP_ENABLED'] ?? 'false', FILTER_VALIDATE_BOOLEAN)) {
    cemLog('IMAP_ENABLED is false. Skipping. Set IMAP_ENABLED=true in .env to activate.');
    exit(0);
}
if (!function_exists('imap_open')) {
    cemLog('ERROR: PHP ext-imap is not enabled on this server. Cannot poll for replies.');
    exit(1);
}

$imapHost   = $_ENV['IMAP_HOST']   ?? 'imap.gmail.com';
$imapPort   = (int) ($_ENV['IMAP_PORT'] ?? 993);
$imapFolder = $_ENV['IMAP_FOLDER'] ?? 'INBOX';
$imapUser   = $_ENV['IMAP_USER']   ?? ($_ENV['SMTP_USER'] ?? '');
$imapPass   = $_ENV['IMAP_PASS']   ?? ($_ENV['SMTP_PASS'] ?? '');

if (empty($imapUser) || empty($imapPass)) {
    cemLog('ERROR: IMAP credentials not configured. Set IMAP_USER and IMAP_PASS in .env.');
    exit(1);
}

// ── Connect ─────────────────────────────────────────────────────────────────
$mailboxStr = sprintf('{%s:%d/imap/ssl}%s', $imapHost, $imapPort, $imapFolder);
cemLog("Connecting to IMAP: {$imapHost}:{$imapPort} as {$imapUser}");
$inbox = @imap_open($mailboxStr, $imapUser, $imapPass, 0, 1);
if (!$inbox) {
    cemLog('ERROR: Could not connect to IMAP. ' . (imap_last_error() ?: 'Unknown error'));
    exit(1);
}
cemLog('Connected successfully.');

$since  = date('d-M-Y', strtotime('-12 hours'));
$emails = imap_search($inbox, 'SINCE "' . $since . '"');
if (!$emails) {
    cemLog('No messages found in the last 12 hours.');
    imap_close($inbox);
    exit(0);
}
cemLog('Found ' . count($emails) . ' message(s) in last 12 hours. Checking for customer-email replies...');

$service = new CustomerEmailService();
$processed = $matched = $skipped = $errors = 0;

foreach ($emails as $emailId) {
    try {
        $header = imap_headerinfo($inbox, $emailId);
        if (!$header) { $skipped++; continue; }

        $fromAddress = '';
        if (!empty($header->from[0]->mailbox) && !empty($header->from[0]->host)) {
            $fromAddress = strtolower(trim($header->from[0]->mailbox . '@' . $header->from[0]->host));
        }
        $subject = trim(imap_utf8($header->subject ?? ''));

        $rawHeaderStr = imap_fetchheader($inbox, $emailId);

        // Our own Message-ID (for dedup) + the IDs this email is replying to.
        $messageId = cemHeaderValue($rawHeaderStr, 'Message-ID');
        $refIds    = cemReferenceIds($rawHeaderStr);

        cemLog("  [#{$emailId}] From: {$fromAddress} | Subject: {$subject}");

        // Dedup — never store the same inbound twice.
        if ($messageId && CustomerEmailMessage::where('message_id', $messageId)->exists()) {
            cemLog("  [#{$emailId}] Already processed (Message-ID match). Skipping.");
            $skipped++; $processed++; continue;
        }

        $thread = $service->findThreadForReply($refIds, $fromAddress);
        if (!$thread) {
            // Not a reply to one of our customer emails (could be an e-ticket
            // reply handled by the other cron, or unrelated). Leave it alone.
            cemLog("  [#{$emailId}] No matching customer-email thread. Skipping.");
            $skipped++; $processed++; continue;
        }

        // Decode body (prefer text/plain).
        $structure = imap_fetchstructure($inbox, $emailId);
        $body = ($structure->type === 0)
            ? cemDecodeBody(imap_fetchbody($inbox, $emailId, '1'), $structure->encoding ?? 0)
            : cemExtractPlainText($inbox, $emailId, $structure);
        $body = trim($body) ?: '[No message body]';

        $service->recordInbound(
            $thread,
            $body,
            $fromAddress,
            $subject,
            $messageId,
            $refIds[0] ?? null,
            substr($rawHeaderStr, 0, 8192)
        );

        cemLog("  [#{$emailId}] ✓ Threaded onto conversation #{$thread->id}.");
        $matched++; $processed++;

    } catch (\Throwable $e) {
        cemLog("  [#{$emailId}] ERROR: " . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        $errors++;
    }
}

imap_close($inbox);
cemLog(sprintf('Done. Total: %d | Threaded: %d | Skipped: %d | Errors: %d', $processed, $matched, $skipped, $errors));
exit(0);

// ── Helpers ──────────────────────────────────────────────────────────────────

/** Extract a single header value (e.g. Message-ID) from a raw header block. */
function cemHeaderValue(string $rawHeaders, string $name): ?string
{
    if (preg_match('/^' . preg_quote($name, '/') . ':\s*(.+)$/im', $rawHeaders, $m)) {
        if (preg_match('/<[^>]+>/', $m[1], $mm)) return trim($mm[0]);
        return trim($m[1]);
    }
    return null;
}

/**
 * Collect the Message-IDs this email references, In-Reply-To first (most
 * specific), then any from the References chain — newest-most-relevant order.
 */
function cemReferenceIds(string $rawHeaders): array
{
    $ids = [];
    if (preg_match('/^In-Reply-To:\s*(.+)$/im', $rawHeaders, $m)) {
        if (preg_match_all('/<[^>]+>/', $m[1], $mm)) $ids = array_merge($ids, $mm[0]);
    }
    if (preg_match('/^References:\s*(.+(?:\r?\n\s+.+)*)$/im', $rawHeaders, $m)) {
        if (preg_match_all('/<[^>]+>/', $m[1], $mm)) $ids = array_merge($ids, array_reverse($mm[0]));
    }
    return array_values(array_unique(array_map('trim', $ids)));
}

function cemDecodeBody(string $rawBody, int $encoding): string
{
    return match ($encoding) {
        3 => base64_decode($rawBody),
        4 => quoted_printable_decode($rawBody),
        default => $rawBody,
    };
}

function cemExtractPlainText($inbox, int $emailId, object $structure, string $prefix = ''): string
{
    $plainText = '';
    $htmlText  = '';
    if (!isset($structure->parts)) return '';

    foreach ($structure->parts as $partNum => $part) {
        $partId = ($prefix === '') ? (string)($partNum + 1) : $prefix . '.' . ($partNum + 1);
        if ($part->type === 0) {
            $subtype = strtolower($part->subtype ?? '');
            $decoded = cemDecodeBody(imap_fetchbody($inbox, $emailId, $partId), $part->encoding ?? 0);
            $charset = 'UTF-8';
            foreach ($part->parameters ?? [] as $param) {
                if (strtolower($param->attribute) === 'charset') { $charset = strtoupper($param->value); break; }
            }
            if ($charset !== 'UTF-8') $decoded = @mb_convert_encoding($decoded, 'UTF-8', $charset);
            if ($subtype === 'plain' && $plainText === '')      $plainText = $decoded;
            elseif ($subtype === 'html' && $htmlText === '')    $htmlText  = strip_tags($decoded);
        } elseif (isset($part->parts)) {
            $nested = cemExtractPlainText($inbox, $emailId, $part, $partId);
            if ($nested !== '' && $plainText === '') $plainText = $nested;
        }
    }
    return $plainText ?: $htmlText;
}
