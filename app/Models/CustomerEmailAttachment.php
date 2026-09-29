<?php

namespace App\Models;

use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Eloquent\Model;

/**
 * CustomerEmailAttachment — one file attached to an outbound customer email.
 *
 * Files are stored privately under storage/customer_emails/ and served only via
 * CustomerEmailController::attachment(). See CustomerEmailAttachments service.
 *
 * @property int         $id
 * @property int         $message_id
 * @property string      $original_name
 * @property string      $stored_path
 * @property string      $mime_type
 * @property int         $size_bytes
 * @property int|null    $uploaded_by
 * @property string      $created_at
 */
class CustomerEmailAttachment extends Model
{
    protected $table      = 'customer_email_attachments';
    public    $timestamps = false; // created_at via DB default

    protected $fillable = [
        'message_id',
        'original_name',
        'stored_path',
        'mime_type',
        'size_bytes',
        'uploaded_by',
        'created_at',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
    ];

    private static ?bool $tableReady = null;

    /**
     * Whether the attachments migration has run. Code can land before the
     * migration does; the email pages must keep working in between.
     */
    public static function tableReady(): bool
    {
        if (self::$tableReady === null) {
            try {
                self::$tableReady = DB::schema()->hasTable('customer_email_attachments');
            } catch (\Throwable $e) {
                self::$tableReady = false;
            }
        }
        return self::$tableReady;
    }

    public function message()
    {
        return $this->belongsTo(CustomerEmailMessage::class, 'message_id');
    }

    /** "1.4 MB" / "320 KB" */
    public function humanSize(): string
    {
        $b = (int) $this->size_bytes;
        if ($b >= 1048576) return round($b / 1048576, 1) . ' MB';
        return max(1, (int) round($b / 1024)) . ' KB';
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }

    public function icon(): string
    {
        if ($this->isImage()) return 'image';
        if ($this->mime_type === 'application/pdf') return 'picture_as_pdf';
        return 'description';
    }
}
