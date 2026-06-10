<?php

namespace App\Services;

use App\Models\CustomerEmailThread;
use App\Models\CustomerEmailMessage;
use App\Models\Transaction;
use App\Models\User;
use App\Models\RecordNote;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as DB;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * CustomerEmailService — business logic for the Customer Emails module.
 *
 * Responsibilities:
 *  - Build a SAFE grounding context from a transaction (whitelist only — never
 *    card data) for the AI drafter.
 *  - Create threads + outbound messages with the approval state machine:
 *      agents/supervisors → pending_approval
 *      managers/admins    → auto-approved and sent immediately
 *  - Approve / reject pending messages (manager/admin).
 *  - Send via SMTP (PHPMailer) wrapped in the branded shell, with a stable
 *    Message-ID + Reply-To + In-Reply-To/References so the IMAP poller can
 *    thread customer replies back to the right conversation.
 *  - Record inbound replies (called by cron/customer_email_inbound.php).
 *
 * Mirrors ETicketEmailService for the SMTP setup and ETicketService for the
 * list/markSent patterns.
 */
class CustomerEmailService
{
    const SUPPORT_EMAIL = 'reservation@base-fare.com';
    const SUPPORT_NAME  = 'Base Fare Reservations';
    const TOLL_FREE     = '888 608 4011';

    /** Roles allowed to approve, and to send their own composition directly. */
    const APPROVER_ROLES = [User::ROLE_ADMIN, User::ROLE_MANAGER];

    // =========================================================================
    // GROUNDING (safe whitelist for the AI)
    // =========================================================================

    /**
     * Build a flat label=>value map of SAFE facts from a transaction, for the
     * AI grounding block. Deliberately excludes any card / payment-instrument
     * data. Empty values are skipped by GeminiService::formatGrounding().
     */
    public function buildGrounding(Transaction $txn): array
    {
        $money = '';
        if ($txn->total_amount !== null) {
            $money = strtoupper($txn->currency ?? 'USD') . ' ' . number_format((float) $txn->total_amount, 2);
        }

        return array_filter([
            'Customer name'            => $txn->customer_name ?? '',
            'Booking reference (PNR)'  => $txn->pnr ?? '',
            'Airline'                  => $txn->airline ?? '',
            'Service type'             => method_exists($txn, 'typeLabel') ? $txn->typeLabel() : ($txn->type ?? ''),
            'Total amount'             => $money,
            'Payment status'           => $txn->payment_status ?? '',
            'Travel date'              => $txn->travel_date ? (string) $txn->travel_date : '',
            'Return date'              => $txn->return_date ? (string) $txn->return_date : '',
        ], fn($v) => trim((string) $v) !== '');
    }

    // =========================================================================
    // CREATE / REPLY
    // =========================================================================

    /**
     * Start a new conversation: create the thread + first outbound message.
     *
     * @return array {thread, message, sent:bool, send_error?:string}
     */
    public function createThread(array $data, User $creator): array
    {
        return DB::connection()->transaction(function () use ($data, $creator) {
            $thread = CustomerEmailThread::create([
                'agent_id'       => $creator->id,
                'transaction_id' => !empty($data['transaction_id']) ? (int) $data['transaction_id'] : null,
                'customer_name'  => trim($data['customer_name']),
                'customer_email' => strtolower(trim($data['customer_email'])),
                'subject'        => trim($data['subject'] ?? ''),
                'status'         => CustomerEmailThread::STATUS_OPEN,
            ]);

            $outcome = $this->addOutboundMessage($thread, $data, $creator, null);

            RecordNote::log('customer_email', $thread->id, $creator->id,
                'Customer email thread created for ' . $thread->customer_email . '.', 'created');

            return $outcome + ['thread' => $thread];
        });
    }

    /**
     * Add a follow-up outbound message to an existing thread (optionally a reply
     * to a specific prior message, for email threading).
     *
     * @return array {message, sent:bool, send_error?:string}
     */
    public function addOutboundMessage(
        CustomerEmailThread $thread,
        array $data,
        User $creator,
        ?CustomerEmailMessage $inReplyTo = null
    ): array {
        $autoApprove = $this->canApprove($creator->role);

        $message = CustomerEmailMessage::create([
            'thread_id'     => $thread->id,
            'direction'     => CustomerEmailMessage::DIR_OUTBOUND,
            'status'        => $autoApprove
                                ? CustomerEmailMessage::STATUS_APPROVED
                                : CustomerEmailMessage::STATUS_PENDING_APPROVAL,
            'category'      => $data['category']      ?? 'custom',
            'intent_prompt' => trim($data['intent_prompt'] ?? ''),
            'ai_model'      => $data['ai_model']      ?? null,
            'ai_subject'    => $data['ai_subject']    ?? null,
            'ai_body'       => $data['ai_body']       ?? null,
            'final_subject' => trim($data['final_subject'] ?? ''),
            'final_body'    => trim($data['final_body'] ?? ''),
            'created_by'    => $creator->id,
            'in_reply_to'   => $inReplyTo?->message_id,
            'approved_by'   => $autoApprove ? $creator->id : null,
            'approved_at'   => $autoApprove ? Carbon::now() : null,
            'created_at'    => date('Y-m-d H:i:s'),
        ]);

        if ($autoApprove) {
            $sendResult = $this->send($thread, $message);
            $this->touchThread($thread, $sendResult['success']
                ? CustomerEmailThread::STATUS_AWAITING_CUSTOMER
                : CustomerEmailThread::STATUS_OPEN);
            return ['message' => $message->fresh(), 'sent' => $sendResult['success'],
                    'send_error' => $sendResult['error'] ?? null];
        }

        // Needs manager approval — notify, do not send.
        RecordNote::log('customer_email', $thread->id, $creator->id,
            'Email drafted and submitted for approval.', 'note');
        $this->touchThread($thread, $thread->status);
        return ['message' => $message, 'sent' => false];
    }

    // =========================================================================
    // APPROVE / REJECT
    // =========================================================================

    /**
     * Approve a pending message and send it. Returns the send result.
     *
     * @return array {success:bool, error?:string}
     */
    public function approveAndSend(CustomerEmailMessage $message, User $approver): array
    {
        if ($message->status !== CustomerEmailMessage::STATUS_PENDING_APPROVAL) {
            return ['success' => false, 'error' => 'This message is not pending approval.'];
        }

        $message->update([
            'status'      => CustomerEmailMessage::STATUS_APPROVED,
            'approved_by' => $approver->id,
            'approved_at' => Carbon::now(),
        ]);

        $thread     = $message->thread;
        $sendResult = $this->send($thread, $message);

        RecordNote::log('customer_email', $thread->id, $approver->id,
            $sendResult['success'] ? 'Email approved and sent to customer.'
                                   : 'Email approved but send FAILED: ' . ($sendResult['error'] ?? 'unknown'),
            'approved');

        $this->touchThread($thread, $sendResult['success']
            ? CustomerEmailThread::STATUS_AWAITING_CUSTOMER
            : $thread->status);

        return $sendResult;
    }

    public function reject(CustomerEmailMessage $message, User $approver, string $reason): bool
    {
        if ($message->status !== CustomerEmailMessage::STATUS_PENDING_APPROVAL) {
            return false;
        }
        $message->update([
            'status'          => CustomerEmailMessage::STATUS_REJECTED,
            'approved_by'     => $approver->id,
            'approved_at'     => Carbon::now(),
            'rejected_reason' => mb_substr(trim($reason), 0, 500),
        ]);
        RecordNote::log('customer_email', $message->thread_id, $approver->id,
            'Email rejected: ' . $reason, 'note');
        return true;
    }

    // =========================================================================
    // SEND (SMTP)
    // =========================================================================

    /**
     * Send an approved outbound message via SMTP, wrapped in the branded shell.
     * Sets a stable Message-ID + Reply-To (+ threading headers for replies) and
     * records sent/failed state on the message.
     *
     * @return array {success:bool, error?:string}
     */
    public function send(CustomerEmailThread $thread, CustomerEmailMessage $message): array
    {
        $messageId = $this->generateMessageId($thread->id, $message->id);

        try {
            $mail = $this->getMailer();
            $mail->addAddress($thread->customer_email, $thread->customer_name);
            $mail->addReplyTo(self::SUPPORT_EMAIL, self::SUPPORT_NAME);
            $mail->Subject = $message->final_subject ?: ('Message from ' . self::SUPPORT_NAME);
            $mail->isHTML(true);
            $mail->Body    = $this->buildHtml($thread, $message);
            $mail->AltBody = $message->final_body;
            $mail->MessageID = $messageId;

            // Threading: if this is a reply to a prior message, link it so the
            // customer's mail client (and our IMAP poller) keep the conversation.
            if (!empty($message->in_reply_to)) {
                $mail->addCustomHeader('In-Reply-To', $message->in_reply_to);
                $mail->addCustomHeader('References', $message->in_reply_to);
            }

            $mail->send();

            $message->update([
                'status'     => CustomerEmailMessage::STATUS_SENT,
                'message_id' => $messageId,
                'sent_at'    => Carbon::now(),
                'sent_to'    => $thread->customer_email,
                'error'      => null,
            ]);
            return ['success' => true];

        } catch (Exception $e) {
            $err = $mail->ErrorInfo ?? $e->getMessage();
            error_log('[CustomerEmail] send failed (thread ' . $thread->id . '): ' . $err);
            $message->update([
                'status'     => CustomerEmailMessage::STATUS_FAILED,
                'message_id' => $messageId,
                'error'      => $err,
            ]);
            return ['success' => false, 'error' => $err];
        }
    }

    // =========================================================================
    // INBOUND (called by cron/customer_email_inbound.php)
    // =========================================================================

    /**
     * Record an inbound customer reply against the thread it belongs to and
     * flip the thread to "needs reply". Body is stored verbatim; the view layer
     * is responsible for escaping it (customer content is untrusted).
     *
     * @return CustomerEmailMessage
     */
    public function recordInbound(
        CustomerEmailThread $thread,
        string $body,
        string $senderEmail,
        ?string $subject,
        ?string $messageId,
        ?string $inReplyTo,
        ?string $rawHeaders
    ): CustomerEmailMessage {
        $message = CustomerEmailMessage::create([
            'thread_id'     => $thread->id,
            'direction'     => CustomerEmailMessage::DIR_INBOUND,
            'status'        => CustomerEmailMessage::STATUS_RECEIVED,
            'final_subject' => $subject ? mb_substr($subject, 0, 500) : null,
            'final_body'    => $body,
            'message_id'    => $messageId,
            'in_reply_to'   => $inReplyTo,
            'sender_email'  => $senderEmail,
            'raw_headers'   => $rawHeaders,
            'created_at'    => date('Y-m-d H:i:s'),
        ]);

        $this->touchThread($thread, CustomerEmailThread::STATUS_AWAITING_AGENT);

        RecordNote::log('customer_email', $thread->id, null,
            'Customer replied via email. From: ' . $senderEmail, 'note');

        return $message;
    }

    /**
     * Find the thread an inbound reply belongs to, by matching the reply's
     * In-Reply-To / References Message-IDs against our sent messages, falling
     * back to the most recent open thread for the sender's email address.
     */
    public function findThreadForReply(array $referenceIds, string $senderEmail): ?CustomerEmailThread
    {
        if ($referenceIds) {
            $msg = CustomerEmailMessage::whereIn('message_id', $referenceIds)
                ->where('direction', CustomerEmailMessage::DIR_OUTBOUND)
                ->orderByDesc('id')
                ->first();
            if ($msg) {
                return $msg->thread;
            }
        }
        return CustomerEmailThread::where('customer_email', strtolower(trim($senderEmail)))
            ->where('status', '!=', CustomerEmailThread::STATUS_CLOSED)
            ->orderByDesc('last_message_at')
            ->first();
    }

    // =========================================================================
    // LIST
    // =========================================================================

    public function listThreads(int $page = 1, int $perPage = 25, array $filters = [], ?int $agentId = null, ?array $agentIds = null): array
    {
        $query = CustomerEmailThread::with(['agent'])->orderByDesc('last_message_at')->orderByDesc('id');

        if ($agentId !== null)      $query->where('agent_id', $agentId);
        elseif ($agentIds !== null) $query->whereIn('agent_id', $agentIds);

        if (!empty($filters['status'])) $query->where('status', $filters['status']);
        if (!empty($filters['search'])) {
            $term = '%' . $filters['search'] . '%';
            $query->where(fn($q) => $q->where('customer_name', 'LIKE', $term)
                ->orWhere('customer_email', 'LIKE', $term)
                ->orWhere('subject', 'LIKE', $term));
        }

        $total   = $query->count();
        $pages   = (int) ceil($total / $perPage);
        $page    = max(1, min($page, $pages ?: 1));
        $records = $query->skip(($page - 1) * $perPage)->take($perPage)->get();

        return ['records' => $records, 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'total_pages' => $pages];
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    public function canApprove(?string $role): bool
    {
        return in_array($role, self::APPROVER_ROLES, true);
    }

    private function touchThread(CustomerEmailThread $thread, string $status): void
    {
        $thread->update(['status' => $status, 'last_message_at' => Carbon::now()]);
    }

    private function generateMessageId(int $threadId, int $messageId): string
    {
        $domain = 'base-fare.com';
        $from   = $_ENV['SMTP_FROM'] ?? getenv('SMTP_FROM') ?: '';
        if ($from && str_contains($from, '@')) {
            $domain = substr(strrchr($from, '@'), 1);
        }
        return sprintf('<ce.%d.%d.%s@%s>', $threadId, $messageId, bin2hex(random_bytes(8)), $domain);
    }

    private function getMailer(): PHPMailer
    {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->CharSet    = 'UTF-8';
        $mail->Host       = $_ENV['SMTP_HOST'] ?? 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = $_ENV['SMTP_USER'] ?? '';
        $mail->Password   = $_ENV['SMTP_PASS'] ?? '';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = $_ENV['SMTP_PORT'] ?? 587;
        // Fail fast instead of hanging the (synchronous) approve→send request.
        // Normal Gmail/Workspace sends complete in 1-3s; 30s is a generous ceiling.
        $mail->Timeout    = 30;

        $fromName  = $_ENV['SMTP_FROM_NAME'] ?? self::SUPPORT_NAME;
        $fromEmail = $_ENV['SMTP_FROM'] ?? $_ENV['SMTP_USER'] ?? '';
        if ($fromEmail) {
            $mail->setFrom($fromEmail, $fromName);
        }
        return $mail;
    }

    /**
     * Wrap the (plain-text, AI-generated) message body in the branded HTML
     * shell. The body is escaped + nl2br'd — the AI is instructed to return no
     * HTML, and we never trust it to, so this is both branding and a safety net.
     */
    private function buildHtml(CustomerEmailThread $thread, CustomerEmailMessage $message): string
    {
        $bodyHtml = nl2br(htmlspecialchars($message->final_body, ENT_QUOTES, 'UTF-8'));
        $tollFree = self::TOLL_FREE;
        $support  = self::SUPPORT_EMAIL;

        return <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="font-family:'Segoe UI',Arial,sans-serif;background:#f0f4f8;margin:0;padding:20px;color:#333;">
  <div style="max-width:600px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.08);">
    <div style="background:linear-gradient(135deg,#0f1e3c 0%,#1a3a6b 100%);padding:24px 30px;text-align:center;">
      <div style="color:#fff;font-size:18px;font-weight:800;letter-spacing:.5px;">Base Fare</div>
      <div style="color:#c9a84c;font-size:11px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;margin-top:2px;">Lets Fly Travel DBA Base Fare</div>
    </div>
    <div style="padding:28px 30px;font-size:14px;line-height:1.75;color:#1e293b;">
      {$bodyHtml}
    </div>
    <div style="background:linear-gradient(135deg,#0f1e3c,#1a3a6b);padding:22px 30px;text-align:center;">
      <div style="color:rgba(255,255,255,0.85);font-size:13px;line-height:1.7;">
        Questions? Just reply to this email — we're here to help.
      </div>
      <div style="margin-top:10px;color:#fff;font-size:14px;font-weight:700;">
        &#9742;&nbsp; Toll-Free: <a href="tel:+1{$tollFree}" style="color:#fff;text-decoration:none;">{$tollFree}</a>
      </div>
    </div>
    <div style="background:#f8fafc;padding:16px 30px;text-align:center;font-size:11px;color:#94a3b8;border-top:1px solid #e2e8f0;">
      <strong style="color:#0f1e3c;">Base Fare Reservations</strong> &nbsp;&middot;&nbsp; {$support}
    </div>
  </div>
</body>
</html>
HTML;
    }
}
