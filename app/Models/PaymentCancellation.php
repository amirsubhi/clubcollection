<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One fee taken back from paid with "Cancel payment" (PaymentCancellationService): the paid details as they were
 * and why. Written once, never updated. payment_id has no foreign key, so the row outlives a deleted fee.
 */
class PaymentCancellation extends Model
{
    public const UPDATED_AT = null;

    /** Written only by PaymentCancellationService. */
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'amount_sen' => 'integer',
            'paid_date' => 'date',
            'issuer_snapshot' => 'array',
            'payment_bill_id' => 'integer',
            'payment_id' => 'integer',
        ];
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    public function cancelledBy()
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function club()
    {
        return $this->belongsTo(Club::class);
    }
}
