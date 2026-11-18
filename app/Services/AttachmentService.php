<?php

namespace App\Services;

use App\Models\Attachment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Stores and serves private attachments.
 *
 * Storage: the private `local` disk (storage/app/private), under
 * clubs/{club_id}/attachments/{Y}/{random}.bin. File contents are encrypted
 * at rest with Laravel's Crypt (AES-256-CBC + HMAC, keyed by APP_KEY), so a
 * leaked backup of storage/ or a misconfigured web root never exposes a
 * readable invoice. Keep APP_KEY safe: without it the files cannot be read.
 * The stored name has no user-controlled part and no executable extension.
 *
 * sha256 and size describe the original (plaintext) upload, so the hash in
 * the AGM report matches the file an auditor downloads.
 *
 * Disk vs database consistency: callers run store()/delete() inside a DB
 * transaction wrapped by tracked(). New files written during it are removed
 * if the transaction rolls back; files of deleted rows are only removed from
 * disk after the transaction commits (DB::afterCommit).
 */
class AttachmentService
{
    public const DISK = 'local';

    /** Paths written inside the current tracked() block, or null when not tracking. */
    private ?array $written = null;

    /**
     * Run $fn (normally a DB::transaction) and delete any file it wrote if it throws.
     *
     * @template T
     * @param  callable(): T  $fn
     * @return T
     */
    public function tracked(callable $fn): mixed
    {
        $outer = $this->written;
        $this->written = [];
        try {
            $result = $fn();
            $this->written = $outer === null ? null : array_merge($outer, $this->written);

            return $result;
        } catch (\Throwable $e) {
            foreach ($this->written as $path) {
                Storage::disk(self::DISK)->delete($path);
            }
            $this->written = $outer;
            throw $e;
        }
    }

    /** Validation rules for one uploaded file. */
    public static function fileRules(): array
    {
        return ['file', 'mimes:'.implode(',', Attachment::MIMES), 'max:'.Attachment::MAX_KB];
    }

    /**
     * @param  Model&\App\Models\Concerns\HasAttachments  $owner
     */
    public function store(Model $owner, int $clubId, UploadedFile $file, string $kind): Attachment
    {
        if ($owner->attachmentsLocked()) {
            throw ValidationException::withMessages(['files' => 'Documents are locked once a record is submitted.']);
        }
        if (! in_array($kind, Attachment::KINDS, true)) {
            $kind = 'other';
        }
        // Transfer slips have their own limits (1–3 per submission, TransferVerificationService::MAX_SLIPS_TOTAL per fee).
        if ($kind !== 'proof_of_payment' && $owner->attachments()->count() >= Attachment::MAX_FILES) {
            throw ValidationException::withMessages(['files' => 'A record can have at most '.Attachment::MAX_FILES.' documents.']);
        }

        $contents = $file->get();
        $sha256 = hash('sha256', $contents);
        $path = sprintf('clubs/%d/attachments/%s/%s.bin', $clubId, now()->format('Y'), Str::random(40));

        Storage::disk(self::DISK)->put($path, Crypt::encryptString($contents));
        if ($this->written !== null) {
            $this->written[] = $path;
        }

        $attachment = $owner->attachments()->create([
            'club_id' => $clubId,
            'kind' => $kind,
            'original_name' => $this->safeName($file->getClientOriginalName()),
            'path' => $path,
            // Server-detected type, not the browser-supplied header.
            'mime' => $file->getMimeType() ?: 'application/octet-stream',
            'size' => strlen($contents),
            'sha256' => $sha256,
            'uploaded_by' => auth()->id(),
        ]);

        AuditService::log(
            'attachment.added',
            'Document "'.$attachment->original_name.'" ('.Attachment::kindLabel($kind).') attached to '.self::ownerLabel($owner).'.',
            $attachment,
            $clubId,
            [],
            ['attachable_id' => $owner->getKey(), 'kind' => $kind, 'sha256' => $sha256, 'size' => $attachment->size]
        );

        return $attachment;
    }

    public function delete(Attachment $attachment): void
    {
        $owner = $attachment->attachable;
        if ($attachment->isFrozen() || ($owner && $owner->attachmentsLocked())) {
            throw ValidationException::withMessages(['files' => 'Documents are locked once a record is submitted.']);
        }

        $old = $attachment->only(['attachable_id', 'kind', 'original_name', 'sha256']);
        $path = $attachment->path;
        $attachment->delete();
        // Remove the file only once the row deletion is committed (runs now outside a transaction).
        DB::afterCommit(fn () => Storage::disk(self::DISK)->delete($path));

        AuditService::log(
            'attachment.removed',
            'Document "'.$old['original_name'].'" removed'.($owner ? ' from '.self::ownerLabel($owner) : '').'.',
            $owner,
            $attachment->club_id,
            $old,
        );
    }

    /**
     * The record a document belongs to, as the audit log names it: the Fund Usage record number (FU-2026-0012),
     * "Payment #42" for a fee (FriendlyDescription shows its transfer reference) or a group slip's reference.
     */
    private static function ownerLabel(Model $owner): string
    {
        return match (true) {
            $owner instanceof \App\Models\Expense => $owner->reference(),
            $owner instanceof \App\Models\Payment => 'Payment #'.$owner->getKey(),
            $owner instanceof \App\Models\PaymentBill && $owner->reference => 'group transfer '.$owner->reference,
            default => class_basename($owner).' #'.$owner->getKey(),
        };
    }

    /** Decrypted file contents. */
    public function contents(Attachment $attachment): string
    {
        $raw = Storage::disk(self::DISK)->get($attachment->path);
        abort_if($raw === null, 404);

        return Crypt::decryptString($raw);
    }

    private function safeName(string $name): string
    {
        $name = preg_replace('/[\x00-\x1F\x7F\/\\\\]+/u', '', $name) ?? '';
        $name = trim($name);

        return Str::limit($name !== '' ? $name : 'document', 200, '');
    }
}
