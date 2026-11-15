<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Welfare (sumbangan kebajikan) details for a welfare-type expense.
 *
 * Personal data (subject, relationship, recipient) is encrypted at rest and
 * shown to club admins only. retention_until marks when it may be purged
 * (about 7 years; the purge command is deferred).
 */
class WelfareCase extends Model
{
    public const RETENTION_YEARS = 7;

    public const RELATIONSHIPS = ['Self', 'Spouse', 'Father', 'Mother', 'Child', 'Father-in-law', 'Mother-in-law', 'Sibling', 'Other'];

    protected $fillable = [
        'expense_id', 'beneficiary_user_id', 'subject_name', 'relationship', 'recipient_name',
        'certificate_sighted_by', 'certificate_sighted_at', 'retention_until',
    ];

    protected $hidden = ['subject_name', 'relationship', 'recipient_name'];

    protected function casts(): array
    {
        return [
            'subject_name' => 'encrypted',
            'relationship' => 'encrypted',
            'recipient_name' => 'encrypted',
            'certificate_sighted_at' => 'date',
            'retention_until' => 'date',
        ];
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(User::class, 'beneficiary_user_id');
    }

    public function sightedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'certificate_sighted_by');
    }

    public function certificateSighted(): bool
    {
        return $this->certificate_sighted_by !== null && $this->certificate_sighted_at !== null;
    }
}
