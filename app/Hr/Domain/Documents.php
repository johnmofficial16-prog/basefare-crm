<?php

namespace App\Hr\Domain;

use App\Hr\Http\ApiError;
use App\Hr\Http\Ctx;
use App\Hr\Kernel;
use App\Hr\Support\Audit;
use App\Hr\Support\Fmt;
use App\Hr\Support\Notify;
use App\Hr\Support\Vault;

/**
 * The documents vault: ID proofs, certificates, photos.
 *
 *  - Uploaded as the raw file body (no multipart form, which the hosting
 *    firewall tends to block), max 8 MB, PDF / JPEG / PNG / WebP only,
 *    checked by content, not by name.
 *  - Encrypted with the HR-only key and stored OUTSIDE the web root under a
 *    random name. Opening one is logged, like viewing bank details.
 *  - Staff uploads wait for an admin to verify them (Approvals).
 */
final class Documents
{
    public const KINDS = [
        'aadhaar' => 'Aadhaar card', 'pan' => 'PAN card', 'photo' => 'Photo', 'education' => 'Education certificate',
        'experience' => 'Experience letter', 'bank_proof' => 'Bank proof (cheque or passbook)', 'address_proof' => 'Address proof', 'other' => 'Other',
    ];
    private const MAX_BYTES = 8 * 1024 * 1024;

    public static function dir(): string
    {
        $dir = Setup::storageDir();
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new ApiError(503, 'storage', "Documents can't be saved right now: the storage folder isn't ready.");
        }
        return $dir;
    }

    /** What the bytes really are (the name and declared type are not trusted). */
    public static function sniff(string $bytes): ?string
    {
        return match (true) {
            str_starts_with($bytes, '%PDF-') => 'application/pdf',
            str_starts_with($bytes, "\xFF\xD8\xFF") => 'image/jpeg',
            str_starts_with($bytes, "\x89PNG\r\n\x1A\n") => 'image/png',
            substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP' => 'image/webp',
            default => null,
        };
    }

    public static function forEmployee(int $employeeId): array
    {
        $names = Kernel::hr()->table('hr_accounts')->pluck('display_name', 'id')->all();
        return Kernel::hr()->table('hr_documents')->where('employee_id', $employeeId)->whereNull('deleted_at')->orderByDesc('created_at')->get()
            ->map(fn($d) => [
                'id' => (int) $d->id, 'kind' => $d->kind, 'kind_label' => self::KINDS[$d->kind] ?? $d->kind, 'title' => $d->title,
                'file_name' => $d->file_name, 'mime' => $d->mime, 'size' => (int) $d->size, 'status' => $d->status,
                'expires_on' => $d->expires_on, 'uploaded_by' => $names[$d->uploaded_by] ?? null, 'at' => $d->created_at,
                'verified_by' => $names[$d->verified_by] ?? null, 'reject_note' => $d->reject_note,
            ])->all();
    }

    /** Store an upload. Admin uploads are verified at once; staff uploads wait for a check. */
    public static function upload(Ctx $c, int $employeeId, bool $byStaff): array
    {
        if (!Kernel::hr()->table('hr_employees')->where('id', $employeeId)->exists()) {
            throw ApiError::notFound('That person');
        }
        [$bytes, $mime] = self::readUpload($c);
        $size = strlen($bytes);
        $kind = $c->req->getHeaderLine('X-Doc-Kind');
        if (!isset(self::KINDS[$kind])) {
            throw ApiError::invalid('Please choose what kind of document it is.');
        }
        $title = trim(mb_substr(rawurldecode($c->req->getHeaderLine('X-Doc-Title')), 0, 160)) ?: self::KINDS[$kind];
        $fileName = trim(mb_substr(rawurldecode($c->req->getHeaderLine('X-File-Name')), 0, 160)) ?: 'document';
        $fileName = preg_replace('/[^\p{L}\p{N} ._()-]/u', '_', $fileName) ?? 'document';
        $expires = $c->req->getHeaderLine('X-Doc-Expires');
        $expires = preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $expires, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $expires : null;

        $key = bin2hex(random_bytes(20));
        $path = self::dir() . DIRECTORY_SEPARATOR . $key . '.bin';
        if (file_put_contents($path, Vault::encrypt($bytes), LOCK_EX) === false) {
            throw new ApiError(503, 'storage', "The file couldn't be saved. Please try again.");
        }
        $now = Kernel::now();
        $id = Kernel::hr()->table('hr_documents')->insertGetId([
            'employee_id' => $employeeId, 'kind' => $kind, 'title' => $title, 'file_name' => $fileName, 'storage_key' => $key,
            'mime' => $mime, 'size' => $size, 'sha256' => hash('sha256', $bytes), 'expires_on' => $expires,
            'status' => $byStaff ? 'pending' : 'verified', 'uploaded_by' => $c->accountId(),
            'verified_by' => $byStaff ? null : $c->accountId(), 'verified_at' => $byStaff ? null : $now, 'created_at' => $now,
        ]);
        $name = (string) Kernel::hr()->table('hr_employees')->where('id', $employeeId)->value('full_name');
        if ($byStaff) {
            $rid = Kernel::hr()->table('hr_requests')->insertGetId([
                'employee_id' => $employeeId, 'type' => 'document', 'status' => 'pending',
                'summary' => self::KINDS[$kind] . ($title !== self::KINDS[$kind] ? ' · ' . $title : ''),
                'payload' => json_encode(['document_id' => $id]), 'created_at' => $now, 'updated_at' => $now,
            ]);
            $what = $kind === 'other' ? 'a document (' . $title . ')' : 'their ' . Fmt::lower(self::KINDS[$kind]);
            $summary = Fmt::first($name) . ' sent ' . $what . ' to check';
            Audit::log('request.create', ['employee_id' => $employeeId, 'entity' => 'hr_requests', 'entity_id' => $rid, 'summary' => $summary]);
            Notify::admins('request.new', $summary, 'Waiting for a decision in Approvals.', '#/approvals?id=' . $rid);
        } else {
            Audit::log('document.upload', ['employee_id' => $employeeId, 'entity' => 'hr_documents', 'entity_id' => $id,
                'summary' => Fmt::first((string) $c->auth['display_name']) . ' added ' . $name . "'s " . ($kind === 'other' ? 'document (' . $title . ')' : Fmt::lower(self::KINDS[$kind]))]);
        }
        return ['id' => $id, 'message' => $byStaff ? 'Uploaded. An admin will check it.' : 'Added to ' . Fmt::first($name) . "'s documents."];
    }

    /**
     * Read a raw-body upload: at most 8 MB, and only a real PDF or photo
     * (checked by content). Returns [bytes, mime].
     */
    public static function readUpload(Ctx $c): array
    {
        $declared = (int) $c->req->getHeaderLine('Content-Length');
        if ($declared > self::MAX_BYTES) {
            throw ApiError::invalid('That file is too big. Please keep it under 8 MB (a phone photo usually is).');
        }
        // Read at most 8 MB + 1 byte, so an oversized body is refused without holding all of it.
        $stream = $c->req->getBody();
        $bytes = '';
        while (!$stream->eof() && strlen($bytes) <= self::MAX_BYTES) {
            $chunk = $stream->read(min(1048576, self::MAX_BYTES + 1 - strlen($bytes)));
            if ($chunk === '') {
                break;
            }
            $bytes .= $chunk;
        }
        if ($bytes === '') {
            throw ApiError::invalid('The file was empty. Please choose it again.');
        }
        if (strlen($bytes) > self::MAX_BYTES) {
            throw ApiError::invalid('That file is too big. Please keep it under 8 MB.');
        }
        $mime = self::sniff($bytes);
        if (!$mime) {
            throw ApiError::invalid('Please upload a PDF or a photo (JPG, PNG or WebP).');
        }
        return [$bytes, $mime];
    }

    /** The decrypted file, for an admin or for the document's owner. Logged as a view. */
    public static function open(Ctx $c, int $id): array
    {
        $d = Kernel::hr()->table('hr_documents')->where('id', $id)->whereNull('deleted_at')->first();
        $isOwner = $d && $c->employeeId() !== null && (int) $d->employee_id === $c->employeeId();
        if (!$d || (!$c->isAdmin() && !$isOwner)) {
            throw ApiError::notFound('That document');
        }
        if (!$c->isAdmin()) {
            Me::needsPasswordSession($c, 'open your ID documents');
        }
        $path = self::dir() . DIRECTORY_SEPARATOR . $d->storage_key . '.bin';
        if (!is_file($path)) {
            throw ApiError::notFound('That file');
        }
        $bytes = Vault::decrypt((string) file_get_contents($path));
        if (!hash_equals($d->sha256, hash('sha256', $bytes))) {
            throw new ApiError(500, 'integrity', 'This file does not match what was uploaded. Please upload it again.');
        }
        Audit::log('document.view', ['employee_id' => (int) $d->employee_id, 'entity' => 'hr_documents', 'entity_id' => $id, 'detail' => ['kind' => $d->kind]]);
        return ['bytes' => $bytes, 'mime' => $d->mime, 'name' => $d->file_name];
    }

    /** Is the document still there (not removed)? */
    public static function live(int $id): bool
    {
        return Kernel::hr()->table('hr_documents')->where('id', $id)->whereNull('deleted_at')->exists();
    }

    public static function setStatus(int $id, string $status, int $byAccount, ?string $note = null): object
    {
        $d = Kernel::hr()->table('hr_documents')->where('id', $id)->whereNull('deleted_at')->first();
        if (!$d) {
            throw ApiError::notFound('That document');
        }
        Kernel::hr()->table('hr_documents')->where('id', $id)->update([
            'status' => $status, 'verified_by' => $byAccount, 'verified_at' => Kernel::now(), 'reject_note' => $status === 'rejected' ? $note : null,
        ]);
        return $d;
    }

    public static function delete(Ctx $c, int $id): array
    {
        $d = Kernel::hr()->table('hr_documents')->where('id', $id)->whereNull('deleted_at')->first();
        if (!$d) {
            throw ApiError::notFound('That document');
        }
        Kernel::hr()->table('hr_documents')->where('id', $id)->update(['deleted_at' => Kernel::now()]);
        // A staff upload still waiting for a check goes back to them: there is nothing left to check.
        $waiting = Kernel::hr()->table('hr_requests')->where('employee_id', $d->employee_id)->where('type', 'document')->where('status', 'pending')->get();
        foreach ($waiting as $r) {
            if ((int) (json_decode((string) $r->payload, true)['document_id'] ?? 0) !== $id) {
                continue;
            }
            Kernel::hr()->table('hr_requests')->where('id', $r->id)->where('status', 'pending')->update([
                'status' => 'sent_back', 'decided_by' => $c->accountId(), 'decided_at' => Kernel::now(),
                'decision_note' => 'The file was removed. Please upload it again if HR asks for it.', 'updated_at' => Kernel::now(),
            ]);
            Notify::employee((int) $d->employee_id, 'request.decided', 'Your upload was removed: ' . $r->summary,
                'Please upload it again if HR asks for it.', '#/me/documents', false);
        }
        Audit::log('document.delete', ['employee_id' => (int) $d->employee_id, 'entity' => 'hr_documents', 'entity_id' => $id,
            'summary' => Fmt::first((string) $c->auth['display_name']) . ' removed a document (' . Fmt::lower(self::KINDS[$d->kind] ?? 'document') . ')']);
        return ['message' => 'Removed. It stays in the encrypted archive for the audit trail.'];
    }
}
