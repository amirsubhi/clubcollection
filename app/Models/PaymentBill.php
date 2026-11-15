<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One gateway bill opened for a fee. The current bill is also mirrored on
 * payments.bill_code; older ones are kept with superseded_at set so a late
 * payment against them can still be matched by the webhook.
 */
class PaymentBill extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'payment_id', 'gateway', 'bill_code', 'reference', 'amount_sen',
        'fee_payer', 'fee_sen', 'transaction_id', 'paid_at', 'superseded_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_sen' => 'integer',
            'fee_sen' => 'integer',
            'paid_at' => 'datetime',
            'superseded_at' => 'datetime',
        ];
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    public function isSuperseded(): bool
    {
        return $this->superseded_at !== null;
    }
}
