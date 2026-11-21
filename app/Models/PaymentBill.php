<?php

namespace App\Models;

use App\Models\Concerns\HasAttachments;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * One gateway bill opened for a fee. The current bill is also mirrored on
 * payments.bill_code; older ones are kept with superseded_at set so a late
 * payment against them can still be matched by the webhook.
 *
 * A group bill ("Bayar semua", R1b) has no payment_id: payment_bill_items
 * lists the fees it covers. method 'gateway' is one FPX bill for the sum;
 * method 'transfer' is one bank transfer slip (attached here) for the set.
 * A group is settled all at once or not at all.
 */
class PaymentBill extends Model
{
    use HasAttachments;

    public const UPDATED_AT = null;

    protected $fillable = [
        'payment_id', 'club_id', 'user_id', 'method', 'gateway', 'bill_code', 'reference', 'amount_sen',
        'fee_payer', 'fee_sen', 'transaction_id', 'paid_at', 'superseded_at', 'opened_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_sen' => 'integer',
            'fee_sen' => 'integer',
            'paid_at' => 'datetime',
            'superseded_at' => 'datetime',
            'opened_at' => 'datetime',
        ];
    }

    /** The member was sent to pay this bill now (new or reused): DisplayStatus measures "in progress" from here. */
    public function markOpened(): void
    {
        $this->forceFill(['opened_at' => now()])->saveQuietly();
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    /** The fees of a group bill, with the amount each was billed for (pivot amount_sen). */
    public function payments()
    {
        return $this->belongsToMany(Payment::class, 'payment_bill_items')->withPivot('amount_sen');
    }

    public function club()
    {
        return $this->belongsTo(Club::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isSuperseded(): bool
    {
        return $this->superseded_at !== null;
    }

    public function isGroup(): bool
    {
        return $this->payment_id === null;
    }

    public function isTransfer(): bool
    {
        return $this->method === 'transfer';
    }

    /** Neither settled nor replaced. */
    public function isOpen(): bool
    {
        return $this->paid_at === null && $this->superseded_at === null;
    }

    /** Slips are frozen once sent. */
    public function attachmentsLocked(): bool
    {
        return $this->isTransfer() && ! $this->isOpen();
    }

    /** The group's fees, oldest first. */
    public function orderedPayments()
    {
        return $this->payments()->orderBy('payments.due_date')->orderBy('payments.period_start')->orderBy('payments.id');
    }

    /** payment_id => amount_sen billed, for every fee of a group. */
    public function itemAmounts(): array
    {
        return DB::table('payment_bill_items')->where('payment_bill_id', $this->id)
            ->pluck('amount_sen', 'payment_id')->map(fn ($v) => (int) $v)->all();
    }

    /** The reference a member types for a group transfer: YURAN-G{oldest fee id}. */
    public static function groupTransferReference(int $leadPaymentId): string
    {
        return 'YURAN-G'.$leadPaymentId;
    }

    /** Open (unpaid, not superseded) gateway group bills that cover the fee. */
    public static function openGatewayGroupsContaining(int $paymentId): Builder
    {
        return static::query()
            ->whereNull('payment_id')
            ->where('method', 'gateway')
            ->whereNull('paid_at')
            ->whereNull('superseded_at')
            ->whereIn('id', fn ($q) => $q->select('payment_bill_id')->from('payment_bill_items')->where('payment_id', $paymentId));
    }

    /**
     * Supersede the open gateway group bills that cover the fee. The ids come
     * first and the UPDATE goes by primary key: as one UPDATE with the subquery,
     * MariaDB / MySQL scan the payment_id index for "payment_id IS NULL" and lock
     * every member's group bills, so two settlements at once deadlocked (1213).
     * The id read is a locking one so it sees committed rows, not the snapshot.
     * payment_id and method never change, so they are checked here rather than
     * in the UPDATE, whose WHERE then has no index but the primary key.
     */
    public static function supersedeOpenGroupsContaining(int $paymentId): void
    {
        $ids = DB::table('payment_bill_items')->where('payment_id', $paymentId)
            ->orderBy('payment_bill_id')->sharedLock()->pluck('payment_bill_id')->all();
        $ids = static::query()->whereKey($ids)->whereNull('payment_id')->where('method', 'gateway')
            ->orderBy('id')->pluck('id')->all();
        if ($ids === []) {
            return;
        }
        static::query()->whereKey($ids)
            ->whereNull('paid_at')
            ->whereNull('superseded_at')
            ->update(['superseded_at' => now()]);
    }
}
