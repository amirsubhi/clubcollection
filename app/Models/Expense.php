<?php

namespace App\Models;

use App\Models\Concerns\HasAttachments;
use Database\Factories\ExpenseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A Fund Usage record: an expense or a welfare payout.
 *
 * Workflow (App\Services\FundUsageService): draft → submitted → approved,
 * submitted → draft (returned / edited), approved → void (two admins).
 * Only approved rows count in balances: use the counted() scope.
 */
class Expense extends Model
{
    /** @use HasFactory<ExpenseFactory> */
    use HasAttachments, HasFactory;

    public const TYPES = ['expense', 'welfare'];

    public const STATUSES = ['draft', 'submitted', 'approved', 'void'];

    protected $fillable = [
        'club_id', 'expense_category_id', 'recorded_by',
        'type', 'description', 'payee', 'invoice_no', 'amount', 'expense_date', 'paid_date', 'minute_ref',
    ];

    protected $attributes = [
        'type' => 'expense',
        'status' => 'draft',
    ];

    protected function casts(): array
    {
        return [
            'expense_date' => 'date',
            'paid_date' => 'date',
            'amount' => 'decimal:2',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'void_requested_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    /** Only approved items count towards balances, reports and member summaries. */
    public function scopeCounted(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('status'), 'approved');
    }

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function voidRequester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function voidApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'void_approved_by');
    }

    public function welfareCase(): HasOne
    {
        return $this->hasOne(WelfareCase::class);
    }

    public function isWelfare(): bool
    {
        return $this->type === 'welfare';
    }

    public function isVoidPending(): bool
    {
        return $this->status === 'approved' && $this->voided_by !== null;
    }

    /** Files can be added or removed only while the record is a draft. */
    public function attachmentsLocked(): bool
    {
        return $this->status !== 'draft';
    }

    public function reference(): string
    {
        return 'FU-'.($this->created_at?->year ?? now()->year).'-'.str_pad((string) $this->id, 4, '0', STR_PAD_LEFT);
    }
}
