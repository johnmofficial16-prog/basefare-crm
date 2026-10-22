<?php

namespace App\Services;

use Illuminate\Database\Capsule\Manager as DB;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Files on CRM Messages (John, 25 Oct: "add emoji and attachment option").
 *
 * stage() checks the uploads and moves them into storage/chat/YYYY/MM/ before
 * the message is written; attach() links them to the message inside the same
 * transaction; discard() removes staged files if the message was never saved.
 * storage/ is denied to the web, so files are only reachable through
 * ChatController::file (members of the conversation, and admins).
 *
 * Same file rules as Customer Emails. A TXT/CSV containing a card number is
 * refused, like a message would be.
 */
class ChatFiles
{
    public const MAX_FILES      = 10;
    public const MAX_FILE_BYTES = 10 * 1024 * 1024;
    public const MAX_TOTAL      = 20 * 1024 * 1024;
    public const ACCEPT         = '.pdf,.jpg,.jpeg,.png,.gif,.webp,.heic,.doc,.docx,.xls,.xlsx,.csv,.txt';

    /** extension => detected MIME types we accept for it */
    private const ALLOWED = CustomerEmailAttachments::ALLOWED;
    private const SEND_MIME = CustomerEmailAttachments::SEND_MIME;
    /** Shown inline in the conversation (browsers can't show HEIC). */
    private const INLINE_IMAGES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    private const KIND = ['pdf' => 'PDF', 'jpg' => 'Image', 'jpeg' => 'Image', 'png' => 'Image', 'gif' => 'Image', 'webp' => 'Image', 'heic' => 'Image',
        'doc' => 'Word', 'docx' => 'Word', 'xls' => 'Excel', 'xlsx' => 'Excel', 'csv' => 'CSV', 'txt' => 'Text'];

    private static ?bool $ready = null;

    public static function tableReady(): bool
    {
        if (self::$ready === null) {
            try {
                self::$ready = DB::schema()->hasTable('chat_attachments');
            } catch (\Throwable) {
                self::$ready = false;
            }
        }
        return self::$ready;
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /** The files[] entries of a request, skipping empty slots. */
    public static function fromRequest(array $uploaded): array
    {
        $files = $uploaded['files'] ?? [];
        if ($files instanceof UploadedFileInterface) {
            $files = [$files];
        }
        return array_values(array_filter((array) $files, fn($f) => $f instanceof UploadedFileInterface && $f->getError() !== UPLOAD_ERR_NO_FILE));
    }

    /**
     * Check and store the uploads. Throws ChatError (nothing kept) if any is refused.
     *
     * @param UploadedFileInterface[] $files
     * @return array<int, array{original_name: string, stored_path: string, mime_type: string, size_bytes: int}>
     */
    public static function stage(array $files): array
    {
        if (!$files) {
            return [];
        }
        if (!self::tableReady()) {
            throw new ChatError('Files can’t be sent yet (a database update is pending). Send the message without them.', 503, 'files');
        }
        if (count($files) > self::MAX_FILES) {
            throw new ChatError('Up to ' . self::MAX_FILES . ' files in one message.', 422, 'files');
        }
        $total = 0;
        foreach ($files as $f) {
            $name = CustomerEmailAttachments::cleanName((string) $f->getClientFilename());
            $err = $f->getError();
            if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
                throw new ChatError('"' . $name . '" is too big. Up to ' . self::mb(self::MAX_FILE_BYTES) . ' per file.', 422, 'files');
            }
            if ($err !== UPLOAD_ERR_OK) {
                throw new ChatError('"' . $name . '" did not upload completely. Please add it again.', 422, 'files');
            }
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!isset(self::ALLOWED[$ext])) {
                throw new ChatError('"' . $name . '" can’t be sent. Allowed: PDF, pictures, Word, Excel, CSV and text files.', 422, 'files');
            }
            $size = (int) $f->getSize();
            if ($size <= 0) {
                throw new ChatError('"' . $name . '" is empty.', 422, 'files');
            }
            if ($size > self::MAX_FILE_BYTES) {
                throw new ChatError('"' . $name . '" is ' . self::mb($size) . '. Up to ' . self::mb(self::MAX_FILE_BYTES) . ' per file.', 422, 'files');
            }
            $total += $size;
        }
        if ($total > self::MAX_TOTAL) {
            throw new ChatError('These files add up to ' . self::mb($total) . '. Up to ' . self::mb(self::MAX_TOTAL) . ' in one message.', 422, 'files');
        }

        $sub = date('Y') . '/' . date('m');
        $dir = self::root() . '/storage/chat/' . $sub;
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new ChatError('The files could not be saved. Please try again.', 500, 'files');
        }
        $staged = [];
        foreach ($files as $f) {
            $name = CustomerEmailAttachments::cleanName((string) $f->getClientFilename());
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $stored = 'storage/chat/' . $sub . '/' . bin2hex(random_bytes(16)) . '.' . $ext;
            $abs = self::root() . '/' . $stored;
            try {
                $f->moveTo($abs);
            } catch (\Throwable $e) {
                error_log('[messages] file move failed: ' . $e->getMessage());
                self::discard($staged);
                throw new ChatError('"' . $name . '" could not be saved. Please try again.', 500, 'files');
            }
            // The content must match the extension: a renamed .exe is not a PDF.
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $real = $finfo ? (string) finfo_file($finfo, $abs) : '';
            if ($finfo) {
                finfo_close($finfo);
            }
            $refusal = !in_array($real, self::ALLOWED[$ext], true) ? '"' . $name . '" does not look like a real .' . $ext . ' file.'
                : (in_array($ext, ['txt', 'csv'], true) && ChatService::hasCardNumber((string) file_get_contents($abs))
                    ? '"' . $name . '" has a card number in it, and card numbers can’t be sent in messages.' : null);
            if ($refusal) {
                @unlink($abs);
                self::discard($staged);
                throw new ChatError($refusal, 422, 'files');
            }
            $staged[] = ['original_name' => $name, 'stored_path' => $stored, 'mime_type' => self::SEND_MIME[$ext], 'size_bytes' => (int) filesize($abs)];
        }
        return $staged;
    }

    /** Link staged files to a message (inside the message's transaction). */
    public static function attach(int $messageId, int $threadId, int $userId, array $staged, string $now): void
    {
        foreach ($staged as $row) {
            DB::table('chat_attachments')->insert($row + ['message_id' => $messageId, 'thread_id' => $threadId, 'user_id' => $userId, 'created_at' => $now]);
        }
    }

    public static function discard(array $staged): void
    {
        foreach ($staged as $row) {
            if ($abs = self::safePath($row['stored_path'] ?? '')) {
                @unlink($abs);
            }
        }
    }

    /** Files per message, ready for the page: [message_id => [file, ...]]. */
    public static function forMessages(array $messageIds): array
    {
        if (!$messageIds || !self::tableReady()) {
            return [];
        }
        $out = [];
        foreach (DB::table('chat_attachments')->whereIn('message_id', $messageIds)->orderBy('id')->get() as $a) {
            $out[(int) $a->message_id][] = self::describe($a);
        }
        return $out;
    }

    public static function describe(object $a): array
    {
        $ext = strtolower(pathinfo((string) $a->original_name, PATHINFO_EXTENSION));
        return ['id' => (int) $a->id, 'name' => $a->original_name, 'size' => (int) $a->size_bytes, 'mime' => $a->mime_type,
            'image' => in_array($a->mime_type, self::INLINE_IMAGES, true), 'kind' => self::KIND[$ext] ?? 'File',
            'size_label' => self::human((int) $a->size_bytes)];
    }

    /** Absolute path of a stored file, or null if it is missing or escapes storage/chat. */
    public static function safePath(string $stored): ?string
    {
        if (preg_match('#^storage/chat/\d{4}/\d{2}/[a-f0-9]{32}\.[a-z]{2,5}$#', $stored) !== 1) {
            return null;
        }
        $base = realpath(self::root() . '/storage/chat');
        $real = realpath(self::root() . '/' . $stored);
        return $base !== false && $real !== false && str_starts_with($real, $base . DIRECTORY_SEPARATOR) ? $real : null;
    }

    public static function human(int $bytes): string
    {
        return $bytes >= 1048576 ? round($bytes / 1048576, 1) . ' MB' : max(1, (int) round($bytes / 1024)) . ' KB';
    }

    private static function mb(int $bytes): string
    {
        return round($bytes / 1048576, 1) . ' MB';
    }
}
