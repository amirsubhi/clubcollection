<?php

namespace App\Models;

use App\Models\Concerns\HasAttachments;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasAttachments, HasFactory;

    public const STATUSES = ['pending', 'paid', 'overdue', 'pending_verification'];

    /** transfer_* and verified_* are not fillable: only TransferVerificationService sets them. */
    protected $fillable = [
        'club_id', 'user_id', 'recorded_by', 'amount', 'frequency',
        'period_start', 'period_end', 'due_date', 'paid_date',
        'status', 'reference', 'discount_id', 'notes',
        'bill_code', 'bill_amount', 'bill_fee_payer', 'bill_fee_sen', 'gateway', 'transaction_id',
        'paid_via', 'fee_absorbed_sen',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'due_date' => 'date',
            'paid_date' => 'date',
            'amount' => 'decimal:2',
            'bill_amount' => 'decimal:2',
            'bill_fee_sen' => 'integer',
            'fee_absorbed_sen' => 'integer',
            'transfer_date' => 'date',
            'transfer_submitted_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // An open gateway bill is for a fixed amount. When the fee amount
        // changes (admin edit or anywhere else), drop the bill so the member
        // gets a fresh one instead of paying the stale amount.
        static::saving(function (Payment $payment) {
            if ($payment->exists && $payment->status !== 'paid'
                && $payment->isDirty('amount') && ! $payment->isDirty('bill_code')
                && $payment->bill_code !== null) {
                $payment->bill_code = null;
                $payment->bill_amount = null;
                $payment->bill_fee_payer = null;
                $payment->bill_fee_sen = null;
            }
        });
    }

    /** Slips are frozen while being checked and once the fee is paid. */
    public function attachmentsLocked(): bool
    {
        return in_array($this->status, ['paid', 'pending_verification'], true);
    }

    public function isVerifying(): bool
    {
        return $this->status === 'pending_verification';
    }

    /** Slips of the latest submission (earlier, rejected slips are kept as history). */
    public function currentSlips()
    {
        return $this->attachments()->where('kind', 'proof_of_payment')
            ->when($this->transfer_submitted_at, fn ($q) => $q->where('created_at', '>=', $this->transfer_submitted_at));
    }

    /** The reference a member types in their bank transfer: YURAN-{id}. */
    public function transferReference(): string
    {
        return 'YURAN-'.$this->id;
    }

    /** True when the open bill was created for the current amount (or predates bill_amount). */
    public function billMatchesAmount(): bool
    {
        return $this->bill_amount === null
            || (int) round(((float) $this->bill_amount) * 100) === (int) round(((float) $this->amount) * 100);
    }

    public function club()
    {
        return $this->belongsTo(Club::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function recordedBy()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function verifiedBy()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function discount()
    {
        return $this->belongsTo(Discount::class);
    }

    public function isOverdue(): bool
    {
        return $this->status === 'pending' && $this->due_date->isPast();
    }
}
