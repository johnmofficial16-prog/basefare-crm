<?php
/**
 * E-Ticket Email Reply Checker — IMAP Cron Script
 *
 * Run every 5 minutes via Hostinger cron:
 *   php /home/u501549865/domains/base-fare.com/public_html/crm/cron/check_email_replies.php
 *
 * Logic:
 *   1. Check IMAP_ENABLED in .env — if false, exit immediately (feature is off by default).
 *   2. Connect to the IMAP mailbox.
 *   3. Search for ALL emails received in the last 12 hours (NOT just UNSEEN).
 *      - Using 12 hours instead of UNSEEN means we never miss a reply because
 *        someone read it in Gmail before the cron ran.
 *   4. For each email:
 *      a. Extract the RFC 2822 Message-ID from headers.
 *      b. Skip immediately if Message-ID already exists in eticket_replies — no reprocessing.
 *      c. Parse the subject for a PNR (6-char alphanumeric).
 *      d. Match the sender email to etickets.customer_email + etickets.pnr.
 *      e. If matched: call ETicketService::processEmailReplyAcknowledgment().
 *   5. Log a summary to storage/logs/eticket_imap.log.
 *
 * This script is fully isolated — failure has zero impact on the web application.
 *
 * Prerequisites:
 *   - PHP ext-imap must be enabled on Hostinger (it is by default).
 *   - IMAP_ENABLED=true in .env.
 *   - IMAP_HOST, IMAP_PORT, IMAP_USER, IMAP_PASS in .env.
 *     (Falls back to SMTP_HOST/SMTP_USER/SMTP_PASS if not set.)
 */

require __DIR__ . '/../vendor/autoload.php';

use Illuminate\Database\Capsule\Manager as Capsule;
use Dotenv\Dotenv;
use App\Models\ETicket;
use App\Services\ETicketService;
use App\Services\ETicketEmailService;
use App\Models\ETicketReply;

// ── Bootstrap ──────────────────────────────────────────────────────────────────

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

// ── Log file ───────────────────────────────────────────────────────────────────

$logFile = __DIR__ . '/../storage/logs/eticket_imap.log';

function imapLog(string $message): void
{
    global $logFile;
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    echo $line;
}

// ── Guard: feature must be explicitly enabled ──────────────────────────────────

$enabled = filter_var($_ENV['IMAP_ENABLED'] ?? 'false', FILTER_VALIDATE_BOOLEAN);
if (!$enabled) {
    imapLog('IMAP_ENABLED is false. Skipping. Set IMAP_ENABLED=true in .env to activate.');
    exit(0);
}

// ── IMAP credentials ───────────────────────────────────────────────────────────

$imapHost   = $_ENV['IMAP_HOST']   ?? 'imap.gmail.com';
$imapPort   = (int) ($_ENV['IMAP_PORT']   ?? 993);
$imapFolder = $_ENV['IMAP_FOLDER'] ?? 'INBOX';
$imapUser   = $_ENV['IMAP_USER']   ?? ($_ENV['SMTP_USER'] ?? '');
$imapPass   = $_ENV['IMAP_PASS']   ?? ($_ENV['SMTP_PASS'] ?? '');

if (empty($imapUser) || empty($imapPass)) {
    imapLog('ERROR: IMAP credentials not configured. Set IMAP_USER and IMAP_PASS in .env.');
    exit(1);
}

// ── Connect ────────────────────────────────────────────────────────────────────

$mailboxStr = sprintf('{%s:%d/imap/ssl}%s', $imapHost, $imapPort, $imapFolder);

imapLog("Connecting to IMAP: {$imapHost}:{$imapPort} as {$imapUser}");

$inbox = @imap_open($mailboxStr, $imapUser, $imapPass, 0, 1);

if (!$inbox) {
    $err = imap_last_error();
    imapLog("ERROR: Could not connect to IMAP. " . ($err ?: 'Unknown error'));
    exit(1);
}

imapLog('Connected successfully.');

// ── Search for messages from the last 12 hours (ALL, not just UNSEEN) ─────────
// We search ALL recent emails and deduplicate using the stored Message-ID.
// This prevents missing replies that were read in Gmail before the cron ran.

$since   = date('d-M-Y', strtotime('-12 hours'));
$emails  = imap_search($inbox, 'SINCE "' . $since . '"');

if (!$emails || empty($emails)) {
    imapLog('No messages found in the last 12 hours.');
    imap_close($inbox);
    exit(0);
}

imapLog('Found ' . count($emails) . ' message(s) in last 12 hours. Checking for new replies...');

// ── Services ───────────────────────────────────────────────────────────────────

$eticketService = new ETicketService();

// ── Process each email ────────────────────────────────────────────────────────

$processed  = 0;
$matched    = 0;
$skipped    = 0;
$errors     = 0;

foreach ($emails as $emailId) {
    try {
        // Fetch headers
        $header = imap_headerinfo($inbox, $emailId);
        if (!$header) {
            imapLog("  [#{$emailId}] Could not read headers. Skipping.");
            imap_setflag_full($inbox, (string) $emailId, '\\Seen');
            $skipped++;
            continue;
        }

        // Extract sender email
        $fromAddress = '';
        if (!empty($header->from[0]->mailbox) && !empty($header->from[0]->host)) {
            $fromAddress = strtolower(trim($header->from[0]->mailbox . '@' . $header->from[0]->host));
        }

        // Extract and decode subject
        $subjectRaw = $header->subject ?? '';
        $subject    = trim(imap_utf8($subjectRaw));

        // Extract RFC 2822 Message-ID for deduplication
        $rawHeaderStr = imap_fetchheader($inbox, $emailId);
        $messageId    = null;
        if (preg_match('/^Message-ID:\s*(.+)$/im', $rawHeaderStr, $midMatch)) {
            $messageId = trim($midMatch[1]);
        }

        imapLog("  [#{$emailId}] From: {$fromAddress} | Subject: {$subject}");

        // ── Deduplication check — skip if already stored ───────────────────────
        if ($messageId && ETicketReply::messageIdExists($messageId)) {
            imapLog("  [#{$emailId}] Already processed (Message-ID match). Skipping.");
            $skipped++;
            $processed++;
            continue;
        }

        // Extract PNR from subject (6-char alphanumeric, may appear as: PNR: ABC123 or PNR: ABC123)
        $pnr = null;
        if (preg_match('/PNR[:\s]+([A-Z0-9]{5,8})/i', $subject, $m)) {
            $pnr = strtoupper(trim($m[1]));
        }

        // If we have a PNR, try to match an eticket
        $eticket = null;
        if ($pnr && $fromAddress) {
            $eticket = ETicket::where('pnr', $pnr)
                ->where('customer_email', $fromAddress)
                ->where('status', '!=', 'draft')
                ->first();
        }

        // Fallback: match by sender email only (latest sent eticket for that customer)
        if (!$eticket && $fromAddress) {
            $eticket = ETicket::where('customer_email', $fromAddress)
                ->whereIn('status', ['sent', 'acknowledged'])
                ->orderByDesc('last_emailed_at')
                ->first();
        }

        if (!$eticket) {
            imapLog("  [#{$emailId}] No matching e-ticket found for {$fromAddress}. Marking seen and skipping.");
            imap_setflag_full($inbox, (string) $emailId, '\\Seen');
            $skipped++;
            continue;
        }

        imapLog("  [#{$emailId}] Matched → ET-" . str_pad($eticket->id, 6, '0', STR_PAD_LEFT) . " | PNR: {$eticket->pnr}");

        // Decode email body (prefer plain text, fallback to HTML stripped)
        $body = '';
        $structure = imap_fetchstructure($inbox, $emailId);

        if ($structure->type === 0) {
            // Simple single-part message
            $rawBody = imap_fetchbody($inbox, $emailId, '1');
            $body    = decodeEmailBody($rawBody, $structure->encoding ?? 0);
        } else {
            // Multipart — find the first text/plain part
            $body = extractPlainText($inbox, $emailId, $structure);
        }

        $body = trim($body);
        if (empty($body)) {
            $body = '[No message body]';
        }

        // Fetch raw headers for audit trail (limited to 8KB)
        // Re-use the already-fetched rawHeaderStr from dedup check above
        $rawHeaders = substr($rawHeaderStr, 0, 8192);

        // Process the reply — passes messageId so it gets stored for future dedup
        $eticketService->processEmailReplyAcknowledgment(
            eticket:     $eticket,
            subject:     $subject,
            body:        $body,
            senderEmail: $fromAddress,
            rawHeaders:  $rawHeaders,
            messageId:   $messageId
        );

        imapLog("  [#{$emailId}] ✓ Processed successfully.");
        $matched++;

    } catch (\Throwable $e) {
        imapLog("  [#{$emailId}] ERROR: " . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        $errors++;
    }

    $processed++;
}

// ── Summary ────────────────────────────────────────────────────────────────────

imap_close($inbox);
imapLog(sprintf(
    'Done. Total: %d | Matched & processed: %d | Skipped (no match): %d | Errors: %d',
    $processed, $matched, $skipped, $errors
));

exit(0);

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Decode an email body part based on its encoding type.
 */
function decodeEmailBody(string $rawBody, int $encoding): string
{
    return match($encoding) {
        3 => base64_decode($rawBody),           // BASE64
        4 => quoted_printable_decode($rawBody), // QUOTED-PRINTABLE
        default => $rawBody,
    };
}

/**
 * Walk a multipart MIME structure and extract the first text/plain part.
 * Falls back to text/html stripped of tags.
 */
function extractPlainText($inbox, int $emailId, object $structure, string $prefix = ''): string
{
    $plainText = '';
    $htmlText  = '';

    if (!isset($structure->parts)) {
        return '';
    }

    foreach ($structure->parts as $partNum => $part) {
        $partId = ($prefix === '') ? (string)($partNum + 1) : $prefix . '.' . ($partNum + 1);

        if ($part->type === 0) { // text
            $subtype = strtolower($part->subtype ?? '');
            $raw     = imap_fetchbody($inbox, $emailId, $partId);
            $decoded = decodeEmailBody($raw, $part->encoding ?? 0);

            // Handle charset
            $charset = 'UTF-8';
            if (!empty($part->parameters)) {
                foreach ($part->parameters as $param) {
                    if (strtolower($param->attribute) === 'charset') {
                        $charset = strtoupper($param->value);
                        break;
                    }
                }
            }
            if ($charset !== 'UTF-8') {
                $decoded = mb_convert_encoding($decoded, 'UTF-8', $charset);
            }

            if ($subtype === 'plain' && empty($plainText)) {
                $plainText = $decoded;
            } elseif ($subtype === 'html' && empty($htmlText)) {
                $htmlText = strip_tags($decoded);
            }
        } elseif (isset($part->parts)) {
            // Nested multipart
            $nested = extractPlainText($inbox, $emailId, $part, $partId);
            if (!empty($nested) && empty($plainText)) {
                $plainText = $nested;
            }
        }
    }

    return $plainText ?: $htmlText;
}
