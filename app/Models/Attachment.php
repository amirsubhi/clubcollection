<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A private file attached to a record (e.g. an expense's invoice).
 *
 * The file on disk is encrypted (see AttachmentService); `size` and `sha256`
 * describe the original upload. Never expose `path` to the browser: use the
 * audited admin.attachments.show route instead.
 */
class Attachment extends Model
{
    public const KINDS = ['invoice', 'receipt', 'bank_slip', 'acknowledgement', 'other', 'proof_of_payment'];

    /** Kinds an admin may pick for Fund Usage documents (proof_of_payment is a member's transfer slip). */
    public const FUND_KINDS = ['invoice', 'receipt', 'bank_slip', 'acknowledgement', 'other'];

    /** How people read each kind (admin UI is English). */
    public const KIND_LABELS = [
        'invoice' => 'Invoice',
        'receipt' => 'Receipt',
        'bank_slip' => 'Bank slip',
        'acknowledgement' => 'Acknowledgement',
        'other' => 'Other',
        'proof_of_payment' => 'Proof of payment',
    ];

    public static function kindLabel(string $kind): string
    {
        return self::KIND_LABELS[$kind] ?? ucfirst(str_replace('_', ' ', $kind));
    }

    public const MIMES = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];

    public const MAX_KB = 10240;

    public const MAX_FILES = 10;

    protected $fillable = [
        'club_id', 'kind', 'original_name', 'path', 'mime', 'size', 'sha256',
        'uploaded_by', 'frozen_at',
    ];

    protected $hidden = ['path'];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'frozen_at' => 'datetime',
        ];
    }

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function isFrozen(): bool
    {
        return $this->frozen_at !== null;
    }
}
