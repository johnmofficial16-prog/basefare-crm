<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ETicketReply — Customer reply message for an e-ticket.
 *
 * One e-ticket can have many replies. Each reply is either:
 *   - 'web_contact' : customer typed a message on the public e-ticket page
 *   - 'email_reply' : customer replied to the e-ticket email conventionally
 *
 * Replies are append-only (immutable once stored).
 *
 * @property int         $id
 * @property int         $eticket_id
 * @property string      $source         web_contact|email_reply
 * @property string|null $subject        Email subject line (email_reply only)
 * @property string      $body           The customer's message content
 * @property string|null $sender_email   Email address the reply came from
 * @property string|null $sender_ip      Client IP (web_contact only)
 * @property string|null $sender_ua      User agent (web_contact only)
 * @property string|null $raw_headers    Raw email headers (email_reply only)
 * @property string|null $message_id     RFC 2822 Message-ID for IMAP deduplication
 * @property string      $created_at
 *
 * @property-read ETicket $eticket
 */
class ETicketReply extends Model
{
    protected $table = 'eticket_replies';

    // No updated_at — replies are append-only
    public $timestamps = false;

    // =========================================================================
    // Source constants
    // =========================================================================
    const SOURCE_WEB_CONTACT  = 'web_contact';
    const SOURCE_EMAIL_REPLY  = 'email_reply';

    // =========================================================================
    // Fillable
    // =========================================================================
    protected $fillable = [
        'eticket_id',
        'source',
        'subject',
        'body',
        'sender_email',
        'sender_ip',
        'sender_ua',
        'raw_headers',
        'message_id',
        'created_at',
    ];

    // =========================================================================
    // Casts
    // =========================================================================
    protected $casts = [
        'eticket_id' => 'integer',
        'created_at' => 'datetime',
    ];

    // =========================================================================
    // Relationships
    // =========================================================================

    public function eticket()
    {
        return $this->belongsTo(ETicket::class, 'eticket_id');
    }

    // =========================================================================
    // Scopes
    // =========================================================================

    public function scopeWebContact($query)
    {
        return $query->where('source', self::SOURCE_WEB_CONTACT);
    }

    public function scopeEmailReply($query)
    {
        return $query->where('source', self::SOURCE_EMAIL_REPLY);
    }

    // =========================================================================
    // Display Helpers
    // =========================================================================

    /**
     * Human-readable source label.
     */
    public function sourceLabel(): string
    {
        return match($this->source) {
            self::SOURCE_WEB_CONTACT => 'Web Contact Form',
            self::SOURCE_EMAIL_REPLY => 'Email Reply',
            default                  => ucfirst($this->source),
        };
    }

    /**
     * Icon emoji for UI display.
     */
    public function sourceIcon(): string
    {
        return match($this->source) {
            self::SOURCE_WEB_CONTACT => '💬',
            self::SOURCE_EMAIL_REPLY => '📧',
            default                  => '✉',
        };
    }

    /**
     * Factory method — create a web contact reply.
     */
    public static function createWebContact(
        int    $eticketId,
        string $body,
        string $senderEmail,
        string $senderIp,
        string $senderUa
    ): self {
        return self::create([
            'eticket_id'   => $eticketId,
            'source'       => self::SOURCE_WEB_CONTACT,
            'body'         => $body,
            'sender_email' => $senderEmail,
            'sender_ip'    => $senderIp,
            'sender_ua'    => $senderUa,
            'created_at'   => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Factory method — create an email reply.
     */
    public static function createEmailReply(
        int     $eticketId,
        string  $body,
        string  $senderEmail,
        ?string $subject    = null,
        ?string $rawHeaders = null,
        ?string $messageId  = null
    ): self {
        return self::create([
            'eticket_id'   => $eticketId,
            'source'       => self::SOURCE_EMAIL_REPLY,
            'subject'      => $subject,
            'body'         => $body,
            'sender_email' => $senderEmail,
            'raw_headers'  => $rawHeaders,
            'message_id'   => $messageId,
            'created_at'   => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Check if an email reply with this RFC 2822 Message-ID already exists.
     * Used by the IMAP cron to deduplicate without relying on SEEN/UNSEEN status.
     */
    public static function messageIdExists(string $messageId): bool
    {
        return self::where('message_id', $messageId)
            ->where('source', self::SOURCE_EMAIL_REPLY)
            ->exists();
    }
}
