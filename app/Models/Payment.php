<?php

namespace App\Models;

use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    protected $fillable = [
        'club_id', 'user_id', 'recorded_by', 'amount', 'frequency',
        'period_start', 'period_end', 'due_date', 'paid_date',
        'status', 'reference', 'discount_id', 'notes',
        'bill_code', 'bill_amount', 'gateway', 'transaction_id',
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
            }
        });
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

    public function discount()
    {
        return $this->belongsTo(Discount::class);
    }

    public function isOverdue(): bool
    {
        return $this->status === 'pending' && $this->due_date->isPast();
    }
}
