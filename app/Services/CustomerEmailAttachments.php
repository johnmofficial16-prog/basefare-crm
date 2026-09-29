<?php

namespace App\Services;

use App\Models\CustomerEmailAttachment;
use App\Models\CustomerEmailMessage;
use Psr\Http\Message\UploadedFileInterface;

/**
 * CustomerEmailAttachments — validate, store and resolve files attached to
 * outbound customer emails.
 *
 * Flow: the controller calls stage() on the uploaded files BEFORE the message is
 * created — anything invalid is rejected with a clear message and nothing is
 * written. Staged files are linked to the message by attach() inside the same
 * DB transaction, before an auto-approved message is sent, so send() always
 * finds them. If creation fails, discard() removes the staged files.
 *
 * Storage: storage/customer_emails/YYYY/MM/<random>.<ext>. storage/ is denied
 * to the web; files are only reachable through the access-checked controller.
 */
class CustomerEmailAttachments
{
    const MAX_FILES      = 10;
    const MAX_FILE_BYTES = 10 * 1024 * 1024;   // 10 MB per file
    const MAX_TOTAL      = 20 * 1024 * 1024;   // 20 MB per email (Gmail allows 25; leave headroom for encoding)

    /** extension → acceptable detected MIME types */
    const ALLOWED = [
        'pdf'  => ['application/pdf'],
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'gif'  => ['image/gif'],
        'webp' => ['image/webp'],
        'heic' => ['image/heic', 'image/heif', 'application/octet-stream'],
        'doc'  => ['application/msword', 'application/CDFV2', 'application/x-ole-storage', 'application/octet-stream'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
        'xls'  => ['application/vnd.ms-excel', 'application/CDFV2', 'application/x-ole-storage', 'application/octet-stream'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
        'csv'  => ['text/csv', 'text/plain', 'application/csv'],
        'txt'  => ['text/plain'],
    ];

    /** What we send in Content-Type, by extension (never trust the browser's). */
    const SEND_MIME = [
        'pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
        'gif' => 'image/gif', 'webp' => 'image/webp', 'heic' => 'image/heic',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'csv' => 'text/csv', 'txt' => 'text/plain',
    ];

    const ACCEPT_ATTR = '.pdf,.jpg,.jpeg,.png,.gif,.webp,.heic,.doc,.docx,.xls,.xlsx,.csv,.txt';

    private static function baseDir(): string
    {
        return dirname(__DIR__, 2) . '/storage/customer_emails';
    }

    /** Pull the attachments[] entries out of getUploadedFiles(), skipping empty slots. */
    public static function fromRequest(array $uploadedFiles): array
    {
        $files = $uploadedFiles['attachments'] ?? [];
        if ($files instanceof UploadedFileInterface) {
            $files = [$files];
        }
        return array_values(array_filter((array) $files, function ($f) {
            return $f instanceof UploadedFileInterface && $f->getError() !== UPLOAD_ERR_NO_FILE;
        }));
    }

    /**
     * Validate and move uploads into storage.
     *
     * @param UploadedFileInterface[] $files
     * @return array{files: array<int,array>, error: ?string}  files = rows ready for attach()
     */
    public static function stage(array $files): array
    {
        if (!$files) {
            return ['files' => [], 'error' => null];
        }
        if (!CustomerEmailAttachment::tableReady()) {
            return ['files' => [], 'error' => 'Attachments are not switched on yet (database update pending). Send without the files, or ask the admin to run the migration.'];
        }
        if (count($files) > self::MAX_FILES) {
            return ['files' => [], 'error' => 'Too many attachments — at most ' . self::MAX_FILES . ' per email.'];
        }

        $total = 0;
        foreach ($files as $f) {
            $name = self::cleanName((string) $f->getClientFilename());
            $err  = $f->getError();
            if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
                return ['files' => [], 'error' => '"' . $name . '" is larger than the server allows. Max ' . self::mb(self::MAX_FILE_BYTES) . ' per file.'];
            }
            if ($err !== UPLOAD_ERR_OK) {
                return ['files' => [], 'error' => '"' . $name . '" did not upload completely. Please attach it again.'];
            }
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!isset(self::ALLOWED[$ext])) {
                return ['files' => [], 'error' => '"' . $name . '" is not an allowed file type. Allowed: PDF, images, Word, Excel, CSV, TXT.'];
            }
            $size = (int) $f->getSize();
            if ($size <= 0) {
                return ['files' => [], 'error' => '"' . $name . '" is empty.'];
            }
            if ($size > self::MAX_FILE_BYTES) {
                return ['files' => [], 'error' => '"' . $name . '" is ' . self::mb($size) . ' — max ' . self::mb(self::MAX_FILE_BYTES) . ' per file.'];
            }
            $total += $size;
        }
        if ($total > self::MAX_TOTAL) {
            return ['files' => [], 'error' => 'Attachments total ' . self::mb($total) . ' — max ' . self::mb(self::MAX_TOTAL) . ' per email.'];
        }

        $sub = date('Y') . '/' . date('m');
        $dir = self::baseDir() . '/' . $sub;
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return ['files' => [], 'error' => 'Could not save attachments on the server. Please try again.'];
        }

        $staged = [];
        foreach ($files as $f) {
            $name   = self::cleanName((string) $f->getClientFilename());
            $ext    = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $stored = 'storage/customer_emails/' . $sub . '/' . bin2hex(random_bytes(16)) . '.' . $ext;
            $abs    = dirname(__DIR__, 2) . '/' . $stored;

            try {
                $f->moveTo($abs);
            } catch (\Throwable $e) {
                error_log('[CustomerEmailAttachments] moveTo failed: ' . $e->getMessage());
                self::discard($staged);
                return ['files' => [], 'error' => 'Could not save "' . $name . '". Please try again.'];
            }

            // Content must match the extension — a renamed .exe is not a PDF
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $real  = $finfo ? (string) finfo_file($finfo, $abs) : '';
            if ($finfo) finfo_close($finfo);
            if (!in_array($real, self::ALLOWED[$ext], true)) {
                @unlink($abs);
                self::discard($staged);
                return ['files' => [], 'error' => '"' . $name . '" does not look like a real .' . $ext . ' file.'];
            }

            $staged[] = [
                'original_name' => $name,
                'stored_path'   => $stored,
                'mime_type'     => self::SEND_MIME[$ext],
                'size_bytes'    => (int) filesize($abs),
            ];
        }

        return ['files' => $staged, 'error' => null];
    }

    /** Link staged files to a message. Called inside the message's DB transaction. */
    public static function attach(CustomerEmailMessage $message, array $staged, ?int $userId): void
    {
        foreach ($staged as $row) {
            CustomerEmailAttachment::create($row + [
                'message_id'  => $message->id,
                'uploaded_by' => $userId,
                'created_at'  => date('Y-m-d H:i:s'),
            ]);
        }
    }

    /** Remove staged files whose message was never created. */
    public static function discard(array $staged): void
    {
        foreach ($staged as $row) {
            $abs = self::safePath($row['stored_path'] ?? '');
            if ($abs) @unlink($abs);
        }
    }

    /**
     * Absolute path for a stored path, or null if it escapes the attachments
     * directory, fails the pattern, or is missing. Stored paths are treated as
     * untrusted input.
     */
    public static function safePath(string $stored): ?string
    {
        if (preg_match('#^storage/customer_emails/\d{4}/\d{2}/[a-f0-9]{32}\.[a-z]{2,5}$#', $stored) !== 1) {
            return null;
        }
        $base = realpath(self::baseDir());
        $real = realpath(dirname(__DIR__, 2) . '/' . $stored);
        if ($base === false || $real === false) {
            return null;
        }
        return str_starts_with($real, $base . DIRECTORY_SEPARATOR) ? $real : null;
    }

    /** Strip path parts, control chars and header-breaking characters; keep it readable. */
    public static function cleanName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F"\\\\\/:*?<>|]+/u', '_', $name) ?? 'file';
        $name = trim($name, " .\t");
        if ($name === '') $name = 'file';
        if (mb_strlen($name) > 150) {
            $ext  = pathinfo($name, PATHINFO_EXTENSION);
            $name = mb_substr(pathinfo($name, PATHINFO_FILENAME), 0, 140) . ($ext !== '' ? '.' . $ext : '');
        }
        return $name;
    }

    private static function mb(int $bytes): string
    {
        return round($bytes / 1048576, 1) . ' MB';
    }
}
