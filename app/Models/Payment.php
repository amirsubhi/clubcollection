<?php

namespace App\Models;

use App\Models\Concerns\HasAttachments;
use App\Support\Payments\ReceiptNumbers;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasAttachments, HasFactory;

    public const STATUSES = ['pending', 'paid', 'overdue', 'pending_verification'];

    /**
     * The receipt_allocations row this save issued to the fee (by the hook, or a group settlement that set the
     * shared number before saving): linked to the fee once the row is saved. Not an attribute.
     */
    public ?int $receiptAllocationId = null;

    /** This save took the fee back from paid: its number is marked voided once saved. Not an attribute. */
    public bool $receiptNumberVoided = false;

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
            'transfer_group_id' => 'integer',
            'transfer_submitted_at' => 'datetime',
            'verified_at' => 'datetime',
            'issuer_snapshot' => 'array',
        ];
    }

    protected static function booted(): void
    {
        // Receipt snapshot (settings phase 1): the club details a receipt is issued with, written once when the
        // fee becomes paid, whatever the path (gateway settlement, transfer approval, an admin recording it, a
        // group bill, a fee created as paid). Never overwritten, so later edits to the club profile don't change
        // an issued receipt. Not fillable: only this hook writes it.
        //
        // Receipt number (settings phase 2), same moment: the next number of the club's financial year
        // (ReceiptNumbers), unless the caller already gave the fee its group's shared number. Not fillable.
        // A fee taken back from paid loses its number (kept in the log as voided, never reused) and gets a new
        // one when paid again.
        static::saving(function (Payment $payment) {
            // A fee taken back from paid is no longer a receipt: a later settlement is a new receipt with the
            // club's details at that time.
            if ($payment->exists && $payment->isDirty('status') && $payment->getOriginal('status') === 'paid' && $payment->status !== 'paid') {
                $payment->issuer_snapshot = null;
                if ($payment->receipt_no !== null) {
                    $payment->receiptNumberVoided = true;
                }
                $payment->receipt_no = null;
                $payment->receiptAllocationId = null;
            }
            $becamePaid = $payment->status === 'paid' && (! $payment->exists || $payment->isDirty('status'));
            $club = null;
            if ($becamePaid && $payment->issuer_snapshot === null) {
                $club = Club::find($payment->club_id);
                if ($club !== null) {
                    $payment->issuer_snapshot = $club->issuerSnapshot();
                }
            }
            if ($becamePaid && $payment->receipt_no === null) {
                $club ??= Club::find($payment->club_id);
                if ($club !== null) {
                    // Inside the caller's transaction, after its own row locks (CLAUDE.MD "Lock order").
                    $receipt = ReceiptNumbers::allocate($club);
                    $payment->receipt_no = $receipt['receipt_no'];
                    $payment->receiptAllocationId = $receipt['id'];
                }
            }
        });

        // The receipt log needs the fee's id, which a fee created as paid only has now.
        static::saved(function (Payment $payment) {
            if ($payment->receiptNumberVoided) {
                ReceiptNumbers::void($payment->id);
                $payment->receiptNumberVoided = false;
            }
            if ($payment->receiptAllocationId !== null) {
                ReceiptNumbers::link($payment->receiptAllocationId, $payment->id);
                $payment->receiptAllocationId = null;
            }
        });

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

        // A dropped bill stays payable at the gateway: keep it on record as
        // superseded so a late payment against it is still matched (S1).
        static::saved(function (Payment $payment) {
            if ($payment->wasChanged('bill_code') && $payment->bill_code === null) {
                $payment->bills()->whereNull('superseded_at')->update(['superseded_at' => now()]);
            }

            // "Bayar semua" (R1b): a group bill is for a fixed set of fees at fixed
            // amounts. When one of them changes amount, is settled, or goes to a
            // transfer check outside the group, the group bill is superseded, so
            // it is not reused; a late payment of it is settled only if every fee
            // is still open at its billed amount, otherwise flagged for a refund.
            if ($payment->wasChanged('amount')
                || ($payment->wasChanged('status') && in_array($payment->status, ['paid', 'pending_verification'], true))) {
                PaymentBill::supersedeOpenGroupsContaining($payment->id);
            }
        });

        static::deleting(function (Payment $payment) {
            // A group slip covers this fee: the set is evidence, like a slip of its own.
            if ($payment->inAnyTransferGroup()) {
                throw new \LogicException('Cannot delete a fee that is part of a group transfer slip.');
            }
            PaymentBill::supersedeOpenGroupsContaining($payment->id);
            // A deleted fee's receipt number is no longer a valid receipt (it is still never reused).
            if ($payment->receipt_no !== null) {
                \App\Support\Payments\ReceiptNumbers::void($payment->id);
            }
        });
    }

    /**
     * The number the fee's receipt shows: its receipt_no (per club and financial year; shared by the fees of one
     * group receipt), or RCP-{id} for a fee paid before receipt numbers existed.
     */
    public function receiptNumber(): string
    {
        return $this->receipt_no ?? self::legacyReceiptNumber((int) $this->id);
    }

    public static function legacyReceiptNumber(int $paymentId): string
    {
        return 'RCP-'.str_pad((string) $paymentId, 6, '0', STR_PAD_LEFT);
    }

    /** Fee amount in sen. */
    public function amountSen(): int
    {
        return (int) round(((float) $this->amount) * 100);
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

    /**
     * Slips of the latest submission (earlier, rejected slips are kept as
     * history). A slip sent for several fees at once lives on the group.
     */
    public function currentSlips()
    {
        $owner = $this->transferGroup() ?? $this;

        return $owner->attachments()->where('kind', 'proof_of_payment')
            ->when($this->transfer_submitted_at, fn ($q) => $q->where('created_at', '>=', $this->transfer_submitted_at));
    }

    /**
     * The group transfer ("Bayar semua" by slip) this fee's current slip
     * submission belongs to (payments.transfer_group_id), while the fee is
     * being checked or paid. Null for a fee sent or paid on its own.
     */
    public function transferGroup(): ?PaymentBill
    {
        if ($this->transfer_group_id === null || ! in_array($this->status, ['pending_verification', 'paid'], true)) {
            return null;
        }

        return PaymentBill::find($this->transfer_group_id);
    }

    /** Whether the fee was ever part of a group slip (kept as evidence: never deleted). */
    public function inAnyTransferGroup(): bool
    {
        return $this->groupBills()->where('method', 'transfer')->exists();
    }

    /** The open transfer group (slip still being checked) this fee is in, if any. */
    public function openTransferGroup(): ?PaymentBill
    {
        $group = $this->isVerifying() ? $this->transferGroup() : null;

        return $group && $group->isOpen() ? $group : null;
    }

    /** The reference a member types in their bank transfer: YURAN-{id}, or YURAN-G{id} for a group. */
    public function transferReference(): string
    {
        return $this->transferGroup()?->reference ?? 'YURAN-'.$this->id;
    }

    /** True when the open bill was created for the current amount (or predates bill_amount). */
    public function billMatchesAmount(): bool
    {
        return $this->bill_amount === null
            || (int) round(((float) $this->bill_amount) * 100) === (int) round(((float) $this->amount) * 100);
    }

    /** Group bills ("Bayar semua") that cover this fee. */
    public function groupBills()
    {
        return $this->belongsToMany(PaymentBill::class, 'payment_bill_items')->withPivot('amount_sen');
    }

    /** Every gateway bill opened for this fee, the current one included. */
    public function bills()
    {
        return $this->hasMany(PaymentBill::class);
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

    /** Shown as overdue: unpaid, no FPX bill in progress, past the club's grace period (DisplayStatus). */
    public function isOverdue(): bool
    {
        return \App\Support\Payments\DisplayStatus::of($this) === \App\Support\Payments\DisplayStatus::OVERDUE;
    }
}
