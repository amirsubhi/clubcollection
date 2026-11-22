<?php

namespace App\Services\Payments;

use App\Mail\PaymentCancelled;
use App\Models\Payment;
use App\Models\PaymentBill;
use App\Models\PaymentCancellation;
use App\Models\User;
use App\Services\AuditService;
use App\Support\Payments\OwnFeeRule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * "Cancel payment" (Batalkan bayaran): an admin takes a paid fee back to unpaid, with a reason, keeping everything
 * on record. For an approved slip that turns out fake, money that never arrived, a payment recorded by mistake or
 * an online payment refunded.
 *
 * - One fee, or the whole set it was paid with: a fee whose receipt number is shared with a "Bayar semua" bill or
 *   slip group (payment_bills.payment_id NULL) is cancelled with every fee of that set that still carries the
 *   number (a fee taken back and paid again since has a number of its own and is left alone).
 * - Each fee gets a payment_cancellations row first (receipt number, paid date and method, gateway transaction,
 *   bank references, issuer snapshot, reason, internal note), then goes back to 'pending' through Eloquent, so
 *   the Payment hook voids its receipt number (ReceiptNumbers::void) and clears the issuer snapshot. Paying it
 *   again issues a new number.
 * - payments.transaction_id and the bill's transaction_id / paid_at are KEPT: SettlementService refuses a
 *   gateway transaction it has seen (transactionAlreadyUsed(); a settled group bill's own transaction), so a
 *   replayed callback of the cancelled payment never marks the fee paid again. transactionAlreadyUsed() also
 *   reads payment_cancellations.transaction_id, so the cancelled transaction stays used even after the fee
 *   stops carrying it (paid again, or recorded by hand, which clears payments.transaction_id).
 * - transfer_group_id is cleared, as when a group slip is rejected: it means "the slip this fee is being checked
 *   or was paid with", and PaymentBill/receipt code picks a slip set's fees by it, so a fee later recorded by hand
 *   as a bank transfer must not land back on the cancelled slip's receipt. The set stays traceable through
 *   payment_bill_items (never detached; the slips still show on the fee's admin page and keep it undeletable) and
 *   payment_cancellations.payment_bill_id.
 * - One audit row per fee ('payment.cancelled'), one email per cancel to the member, after the commit (a mail
 *   failure is logged and never undoes the cancel). The note is internal: never shown or sent to the member.
 *
 * Lock order (CLAUDE.MD "Lock order"): the same order as the path that settled the set. A slip group: its
 * payment_bills row first, then the fees by id (TransferVerificationService::lockGroup). An online group bill or
 * a single fee: the fees by id, then the group bill (SettlementService::markGroupPaid takes fees before the bill).
 * No receipt counter is taken. Rows written after these (receipt_allocation_payments via the hook) are reached
 * only through fee rows already held.
 */
class PaymentCancellationService
{
    /** How the fee was paid, for the reasons offered. */
    public const METHOD_SLIP = 'slip';

    public const METHOD_MANUAL = 'manual';

    public const METHOD_GATEWAY = 'gateway';

    /** Reasons per paid method. Online: the gateway confirmed the money, so never "not genuine" / "not received". */
    public const REASONS = [
        self::METHOD_SLIP => ['slip_not_genuine', 'money_not_received', 'recorded_by_mistake', 'other'],
        self::METHOD_MANUAL => ['money_not_received', 'recorded_by_mistake', 'other'],
        self::METHOD_GATEWAY => ['refunded', 'other'],
    ];

    /** Admin wording. */
    public const REASON_LABELS = [
        'slip_not_genuine' => 'The slip is not genuine',
        'money_not_received' => 'The money did not reach the club',
        'recorded_by_mistake' => 'Recorded by mistake',
        'refunded' => 'Refunded to the member',
        'other' => 'Other',
    ];

    /**
     * What the member is told, per reason (BM, EN): neutral wording, never the admin's note. The member pages use
     * the same sentences (resources/js/lib/i18n.ts cancelReason_*).
     */
    public const MEMBER_WORDING = [
        'slip_not_genuine' => ['Kami tidak dapat mengesahkan bayaran ini.', 'We could not confirm this payment.'],
        'money_not_received' => ['Kami tidak dapat mengesahkan bayaran ini.', 'We could not confirm this payment.'],
        'recorded_by_mistake' => ['Bayaran ini telah direkodkan secara tersilap.', 'This payment was recorded by mistake.'],
        'refunded' => ['Bayaran ini telah dikembalikan kepada anda.', 'This payment was refunded to you.'],
        'other' => ['Hubungi bendahari kelab untuk maklumat lanjut.', 'Contact the club treasurer for details.'],
    ];

    public const METHOD_LABELS = [
        self::METHOD_SLIP => 'Bank transfer / DuitNow with a slip checked by an admin',
        self::METHOD_MANUAL => 'Recorded by an admin ("Record payment…")',
        self::METHOD_GATEWAY => 'Online (FPX)',
    ];

    public const NOTE_MAX = 500;

    /**
     * How a paid fee was paid: online (paid_via is the gateway's name), by a slip an admin checked, or recorded by hand
     * (cash / transfer / other with "Record payment…", and older fees with no method). A slip transfer has
     * transfer_submitted_at or verified_by (as SettlementService and the admin page read it) and no rejection
     * left over: "Record payment…" after a rejected slip keeps the rejection, an approval clears it.
     */
    public static function paidMethod(Payment $payment): string
    {
        $via = $payment->paid_via;
        if (! in_array($via, [null, 'transfer', 'cash', 'manual'], true)) {
            return self::METHOD_GATEWAY;
        }
        if ($via === 'transfer' && ($payment->transfer_submitted_at !== null || $payment->verified_by !== null)
            && $payment->rejection_reason === null) {
            return self::METHOD_SLIP;
        }

        return self::METHOD_MANUAL;
    }

    /** @return list<string> */
    public static function reasonsFor(Payment $payment): array
    {
        return self::REASONS[self::paidMethod($payment)];
    }

    /**
     * What a cancel of $payment would take back: the set's bill or slip group (null for one fee) and the fees,
     * oldest first. A plain read for the form; cancel() locks and reads again.
     *
     * @return array{0: ?PaymentBill, 1: Collection<int, Payment>}
     */
    public static function scope(Payment $payment): array
    {
        $bill = self::setBill($payment);
        if ($bill === null) {
            return [null, collect($payment->status === 'paid' ? [$payment] : [])];
        }
        $fees = Payment::query()->where('club_id', $payment->club_id)->where('receipt_no', $payment->receipt_no)
            ->where('status', 'paid')->orderBy('due_date')->orderBy('period_start')->orderBy('id')->get();

        return [$bill, $fees];
    }

    /**
     * The "Bayar semua" bill or slip group whose receipt number the fee carries, or null when it was paid on its
     * own. A fee with no number (paid before receipt numbers) is always on its own: never match NULL to NULL.
     */
    public static function setBill(Payment $payment): ?PaymentBill
    {
        if ($payment->receipt_no === null || $payment->status !== 'paid') {
            return null;
        }

        return PaymentBill::query()->whereNull('payment_id')->where('club_id', $payment->club_id)
            ->where('receipt_no', $payment->receipt_no)->first();
    }

    /** Why $admin may not cancel this fee (or a fee of its set), or null. Club scoping is the controller's. */
    public static function blocker(Payment $payment, User $admin): ?string
    {
        foreach (self::scope($payment)[1]->push($payment) as $fee) {
            if ($own = OwnFeeRule::blocker($fee, $admin, 'cancel it')) {
                return $own;
            }
        }

        return null;
    }

    /**
     * Cancel the payment of $payment (and of its set). Returns the cancellation rows written.
     *
     * @return Collection<int, PaymentCancellation>
     *
     * @throws ValidationException when it is no longer paid, or the reason doesn't fit how it was paid
     */
    public function cancel(Payment $payment, User $admin, string $reasonCode, ?string $note): Collection
    {
        $note = ($n = trim((string) $note)) === '' ? null : $n;
        if ($note !== null && mb_strlen($note) > self::NOTE_MAX) {
            throw ValidationException::withMessages(['note' => 'The note can be at most '.self::NOTE_MAX.' characters.']);
        }

        // Resolved from a plain read, then locked and checked again below.
        $current = Payment::find($payment->id);
        if ($current === null || $current->status !== 'paid') {
            throw ValidationException::withMessages(['status' => self::notPaidMessage()]);
        }
        $receiptNo = $current->receipt_no;
        $bill = self::setBill($current);
        $ids = $bill
            ? Payment::query()->where('club_id', $current->club_id)->where('receipt_no', $receiptNo)->pluck('id')->push($current->id)->unique()->sort()->values()->all()
            : [$current->id];

        [$rows, $fees, $bill] = DB::transaction(function () use ($current, $receiptNo, $bill, $ids, $admin, $reasonCode, $note) {
            // A slip group: its row before its fees (TransferVerificationService::lockGroup).
            if ($bill?->isTransfer()) {
                $bill = PaymentBill::whereKey($bill->id)->lockForUpdate()->first();
            }
            $locked = Payment::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            // An online group bill: after its fees (SettlementService::markGroupPaid).
            if ($bill !== null && ! $bill->isTransfer()) {
                $bill = PaymentBill::whereKey($bill->id)->lockForUpdate()->first();
            }

            $target = $locked->firstWhere('id', $current->id);
            if ($target === null || $target->status !== 'paid' || $target->receipt_no !== $receiptNo) {
                throw ValidationException::withMessages(['status' => self::notPaidMessage()]);
            }
            $method = self::paidMethod($target);
            if (! in_array($reasonCode, self::REASONS[$method], true)) {
                throw ValidationException::withMessages(['reason' => 'Choose one of the reasons offered for a payment made this way.']);
            }

            // The set: every fee still paid with this receipt number (none taken back and paid again since).
            $fees = $locked->filter(fn (Payment $p) => $p->status === 'paid'
                && ($p->id === $target->id || ($bill !== null && $p->receipt_no === $receiptNo)))
                ->sortBy(fn (Payment $p) => [$p->due_date->toDateString(), $p->period_start->toDateString(), $p->id])->values();
            foreach ($fees as $fee) {
                if ($own = OwnFeeRule::blocker($fee, $admin, 'cancel it')) {
                    abort(403, $own);
                }
            }

            $eventKey = (string) Str::uuid();
            $now = now();
            $rows = collect();
            $setIds = $fees->pluck('id')->all();
            foreach ($fees as $fee) {
                // The record first, while the fee still holds its number and snapshot (the save clears both).
                $row = new PaymentCancellation;
                $row->forceFill([
                    'club_id' => $fee->club_id,
                    'payment_id' => $fee->id,
                    'event_key' => $eventKey,
                    'receipt_no' => $fee->receipt_no,
                    'amount_sen' => $fee->amountSen(),
                    'paid_date' => $fee->paid_date?->toDateString(),
                    'paid_via' => $fee->paid_via,
                    'gateway' => $fee->gateway,
                    'transaction_id' => $fee->transaction_id,
                    'transfer_bank_ref' => $fee->transfer_bank_ref,
                    'transfer_ref' => $fee->transfer_ref,
                    'payment_bill_id' => $bill?->id,
                    'issuer_snapshot' => $fee->issuer_snapshot,
                    'reason_code' => $reasonCode,
                    'note' => $note,
                    'cancelled_by' => $admin->id,
                    'created_at' => $now,
                ])->save();
                $rows->push($row);

                $old = $fee->only(['status', 'paid_date', 'paid_via', 'gateway', 'transaction_id', 'receipt_no', 'transfer_bank_ref', 'transfer_ref', 'verified_by']);
                $old['paid_date'] = $fee->paid_date?->toDateString();
                $receiptBefore = $fee->receipt_no;
                // As a rejected slip leaves it: owed again. transaction_id stays (replays are refused); the
                // Payment hook voids the receipt number and clears the issuer snapshot.
                // Paid online while the member's slip for a set was still being checked (the webhook settled it
                // and flagged the slip share for a refund): cancelling the online payment puts the fee back
                // into that slip set, still awaiting verification, so the slip can be approved or rejected.
                // The same for a slip of the fee's own that was sent and never decided (no checker yet).
                $slipStillOpen = $fee->paid_via !== 'transfer' && (
                    ($fee->transfer_group_id !== null
                        && ($group = PaymentBill::find($fee->transfer_group_id)) !== null
                        && $group->isTransfer() && $group->isOpen())
                    || ($fee->transfer_group_id === null && $fee->transfer_submitted_at !== null && $fee->verified_by === null)
                );
                $fee->forceFill($slipStillOpen ? [
                    'status' => 'pending_verification',
                    'paid_date' => null,
                    'paid_via' => null,
                    'gateway' => null,
                    'verified_by' => null,
                    'verified_at' => null,
                ] : [
                    'status' => 'pending',
                    'paid_date' => null,
                    'paid_via' => null,
                    'gateway' => null,
                    'verified_by' => null,
                    'verified_at' => null,
                    'transfer_group_id' => null,
                    'transfer_submitted_at' => null,
                    'transfer_bank_ref' => null,
                    'transfer_ref' => null,
                ])->save();

                AuditService::log(
                    'payment.cancelled',
                    "Payment #{$fee->id} cancelled (".self::REASON_LABELS[$reasonCode].'): '.($slipStillOpen ? 'the fee is back awaiting the check of its transfer slip.' : 'the fee is unpaid again.')
                        .($receiptBefore !== null ? " Receipt {$receiptBefore} voided (the number is never reused)." : '')
                        .(count($setIds) > 1 ? ' Cancelled with its set ('.count($setIds).' fees).' : ''),
                    $fee,
                    $fee->club_id,
                    $old,
                    [
                        'status' => 'pending',
                        'reason_code' => $reasonCode,
                        'note' => $note,
                        'receipt_voided' => $receiptBefore,
                        'event_key' => $eventKey,
                        'payment_bill_id' => $bill?->id,
                        'payment_ids' => $setIds,
                    ]
                );
            }

            return [$rows, $fees, $bill];
        });

        $this->notify($fees, $rows, $bill);

        return $rows;
    }

    /** "Kami tidak dapat mengesahkan…": one email per cancel, after the commit. A failure is only logged. */
    private function notify(Collection $fees, Collection $rows, ?PaymentBill $bill): void
    {
        $lead = $fees->first();
        if ($lead === null) {
            return;
        }
        $lead->load(['user', 'club']);
        if ($lead->user === null || ! filled($lead->user->email)) {
            return;
        }
        try {
            Mail::to($lead->user->email)->send(new PaymentCancelled($lead, $fees, $rows->first(), $bill));
        } catch (\Throwable $e) {
            Log::error('Payment cancelled email failed', ['payment_id' => $lead->id, 'error' => $e->getMessage()]);
        }
    }

    private static function notPaidMessage(): string
    {
        return 'This payment is no longer paid (it may have just been cancelled by another admin). Reload the page.';
    }
}
