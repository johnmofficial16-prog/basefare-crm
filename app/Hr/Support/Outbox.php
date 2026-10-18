<?php

namespace App\Hr\Support;

use App\Hr\Kernel;
use PHPMailer\PHPMailer\PHPMailer;
use Throwable;

/**
 * Every HR email goes through the outbox ("never twice, never silently").
 *
 *  - queue() inserts with a unique dedupe key, so the same email can't be
 *    queued twice (a double-click or a retried request is harmless).
 *  - flush() sends queued mail. Call it AFTER the database work has been
 *    committed, never inside a transaction. A cron flush covers anything a
 *    request didn't get to. Requests use flushAfterResponse(), so nobody
 *    waits on the mail server.
 *  - Each row is claimed before it is sent, so two flushes can't send it twice.
 *  - Bodies are wiped once sent (they can hold set-up and reset links), and
 *    anything still unsent after 8 days is given up and wiped too.
 *
 * Mail settings are HR's own (HR_SMTP_*), so HR mail can come from an hr@
 * Trio mailbox rather than the CRM's.
 */
final class Outbox
{
    private const GIVE_UP_DAYS = 8; // set-up links last 7 days

    private static int $deferred = 0;
    /**
     * Is HR email switched on? Only when HR_SMTP_HOST is set. With it off,
     * nothing is queued at all (no invite or reset links sitting in the
     * database), messages stay inside Trio HR and the CRM bell, and admins
     * hand people a one-time link instead (John, 18 Oct: the only mailbox
     * available is shared with managers).
     */
    public static function enabled(): bool
    {
        return trim((string) Kernel::env('HR_SMTP_HOST', '')) !== '';
    }

    public static function queue(string $dedupeKey, string $to, string $subject, string $text, ?string $html = null): void
    {
        if (!self::enabled()) {
            return;
        }
        Kernel::hr()->table('hr_outbox')->insertOrIgnore([
            'dedupe_key' => substr($dedupeKey, 0, 120),
            'to_email'   => $to,
            'subject'    => mb_substr($subject, 0, 200),
            'body_text'  => $text,
            'body_html'  => $html,
            'status'     => 'queued',
            'created_at' => Kernel::now(),
        ]);
    }

    /**
     * Send queued mail once the reply has gone to the browser, where the
     * server allows it (PHP-FPM or LiteSpeed). Elsewhere it still runs at the
     * end of the request, as before. Several calls in one request (a bulk
     * approval) add up and send in one go.
     */
    public static function flushAfterResponse(int $limit = 10): void
    {
        if (self::$deferred === 0) {
            register_shutdown_function(static function (): void {
                ignore_user_abort(true);
                while (ob_get_level() > 0) {
                    @ob_end_flush();
                }
                if (function_exists('fastcgi_finish_request')) {
                    fastcgi_finish_request();
                } elseif (function_exists('litespeed_finish_request')) {
                    litespeed_finish_request();
                }
                try {
                    self::flush(self::$deferred);
                } catch (Throwable $e) {
                    error_log('[Trio HR] mail send after response failed: ' . $e->getMessage());
                }
            });
        }
        self::$deferred = min(60, self::$deferred + max(1, $limit));
    }

    /** Send up to $limit queued emails. Returns how many went out. */
    public static function flush(int $limit = 10): int
    {
        $db = Kernel::hr();
        $now = Kernel::now();
        // Give up on mail that never went out (its links have expired) and wipe it.
        $db->table('hr_outbox')->whereIn('status', ['queued', 'sending'])
            ->where('created_at', '<', date('Y-m-d H:i:s', time() - self::GIVE_UP_DAYS * 86400))
            ->update(['status' => 'failed', 'last_error' => 'Not sent within ' . self::GIVE_UP_DAYS . ' days', 'body_text' => '', 'body_html' => null]);
        $db->table('hr_outbox')->where('status', 'failed')->where('body_text', '!=', '')
            ->where('created_at', '<', date('Y-m-d H:i:s', time() - self::GIVE_UP_DAYS * 86400))
            ->update(['body_text' => '', 'body_html' => null]);
        // A send that died half-way (claimed but never finished) goes back in the queue.
        $db->table('hr_outbox')->where('status', 'sending')->where('claimed_at', '<', date('Y-m-d H:i:s', time() - 600))
            ->update(['status' => 'queued', 'claimed_at' => null]);

        $host = Kernel::env('HR_SMTP_HOST');
        if ($host === null || $limit < 1) {
            return 0; // not configured: mail stays queued and visible on the Health page
        }
        $rows = $db->table('hr_outbox')->where('status', 'queued')->where('attempts', '<', 5)
            ->orderBy('id')->limit($limit)->get();
        $sent = 0;
        foreach ($rows as $row) {
            // Claim it first, so a second flush running at the same time skips it.
            if (!$db->table('hr_outbox')->where('id', $row->id)->where('status', 'queued')->update(['status' => 'sending', 'claimed_at' => $now])) {
                continue;
            }
            try {
                $mail = new PHPMailer(true);
                $mail->isSMTP();
                $mail->Host = $host;
                $mail->Port = (int) Kernel::env('HR_SMTP_PORT', '587');
                $mail->SMTPAuth = Kernel::env('HR_SMTP_USER') !== null;
                $mail->Username = (string) Kernel::env('HR_SMTP_USER', '');
                $mail->Password = (string) Kernel::env('HR_SMTP_PASS', '');
                $mail->SMTPSecure = Kernel::env('HR_SMTP_SECURE', 'tls') === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Timeout = 10;
                $mail->CharSet = 'UTF-8';
                $mail->setFrom((string) Kernel::env('HR_MAIL_FROM', 'hr@triotoursandtravels.in'), 'Trio HR');
                $mail->addAddress($row->to_email);
                $mail->Subject = $row->subject;
                if ($row->body_html) {
                    $mail->isHTML(true);
                    $mail->Body = $row->body_html;
                    $mail->AltBody = $row->body_text;
                } else {
                    $mail->Body = $row->body_text;
                }
                $mail->send();
                // Sent: keep the record (who, what, when) but not the body, which can hold a live link.
                $db->table('hr_outbox')->where('id', $row->id)->update([
                    'status' => 'sent', 'sent_at' => Kernel::now(), 'attempts' => $row->attempts + 1, 'last_error' => null,
                    'claimed_at' => null, 'body_text' => '', 'body_html' => null,
                ]);
                $sent++;
            } catch (Throwable $e) {
                $attempts = $row->attempts + 1;
                $db->table('hr_outbox')->where('id', $row->id)->update([
                    'attempts'   => $attempts,
                    'status'     => $attempts >= 5 ? 'failed' : 'queued',
                    'claimed_at' => null,
                    'last_error' => mb_substr($e->getMessage(), 0, 255),
                ]);
            }
        }
        return $sent;
    }

    /** A plain, branded email body. $lines are paragraphs; $button is [label, url]. */
    public static function html(string $heading, array $lines, ?array $button = null): string
    {
        $e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $p = '';
        foreach ($lines as $l) {
            $p .= '<p style="margin:0 0 14px;font-size:16px;line-height:1.55;color:#1F2430">' . $e($l) . '</p>';
        }
        $btn = $button ? '<p style="margin:22px 0"><a href="' . $e($button[1]) . '" style="display:inline-block;background:#0B2353;color:#ffffff;text-decoration:none;font-weight:600;font-size:16px;padding:13px 22px;border-radius:10px">' . $e($button[0]) . '</a></p>' : '';
        return '<!doctype html><html><body style="margin:0;background:#F7F5F0;font-family:Segoe UI,Arial,sans-serif">'
            . '<div style="max-width:560px;margin:0 auto;padding:32px 20px">'
            . '<div style="font-family:Georgia,serif;font-size:24px;color:#0B2353;margin-bottom:18px">Trio <span style="color:#7A5F1C;font-style:italic">HR</span></div>'
            . '<div style="background:#ffffff;border:1px solid #E6E1D6;border-radius:14px;padding:26px 24px">'
            . '<h1 style="margin:0 0 14px;font-size:21px;color:#1F2430">' . $e($heading) . '</h1>' . $p . $btn
            . '</div><p style="font-size:13px;color:#59606F;margin:16px 4px">Trio Tours and Travels Pvt Ltd · Mohali</p></div></body></html>';
    }
}
