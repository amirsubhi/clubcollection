<?php

namespace App\Services\Payments;

use App\Contracts\ConfirmsWebhookPayments;
use App\Mail\PaymentConfirmation;
use App\Models\AuditLog;
use App\Models\Club;
use App\Models\Payment;
use App\Models\PaymentBill;
use App\Services\AuditService;
use App\Services\PaymentGateways\PaymentGatewayManager;
use App\Support\Payments\GatewayConfirmation;
use App\Support\Payments\ReceiptNumbers;
use App\Support\Payments\WebhookResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Settles a gateway payment the gateway has reported (G1): the one code path
 * behind the webhook, the scheduled reconcile of open bills
 * (payments:reconcile-open-bills) and the member's "Semak status bayaran"
 * requery. Amount checks, transaction dedupe, row locks, refund flags,
 * audit entries and receipt emails are therefore the same whichever way
 * the payment was learned of.
 *
 * Lock order (PR #25; CLAUDE.MD "Lock order"): payment_bill_items (shared,
 * group only) → payments (by id) → payment_bills → receipt_counters. The
 * receipt counter (settings phase 2) is taken when a fee is saved as paid,
 * after the fee and bill locks; whatever is locked after it in the same
 * transaction (this fee's own bill, open group bills covering this fee) is
 * reached only through fee rows this transaction already holds.
 */
class SettlementService
{
    /** At least one fee was marked paid. */
    public const SETTLED = 'settled';

    /** Handled: nothing to settle (already settled, a duplicate, or flagged for the treasurer). */
    public const NOTHING = 'nothing';

    /** Neither a paid amount nor a bill amount is known: not settled (fail closed). */
    public const AMOUNT_UNKNOWN = 'amount_unknown';

    /** Requery: the gateway reports no successful payment for the bill (yet). */
    public const UNPAID = 'unpaid';

    /** Requery: the gateway could not be asked (unreachable, error, unreadable answer). */
    public const UNAVAILABLE = 'unavailable';

    /** Requery: this gateway has no API to ask, or asking it is turned off. */
    public const UNSUPPORTED = 'unsupported';

    /** Member status check: at most one requery per bill in this many seconds. */
    public const REQUERY_THROTTLE_SECONDS = 30;

    public function __construct(private PaymentGatewayManager $gateways) {}

    // ── Finding the fee ──────────────────────────────────────────────────

    /**
     * The fee (and the bill) a callback names: every bill ever opened is in
     * payment_bills, superseded ones included; payments.bill_code/reference
     * cover rows from before that table. A bill code that is not on record
     * never falls back to the reference: see referenceHint().
     *
     * @return array{0: ?Payment, 1: ?PaymentBill}
     */
    public function findPayment(WebhookResult $result): array
    {
        if (! empty($result->billCode)) {
            return $this->lookup($result->gateway, 'bill_code', $result->billCode);
        }
        if (! empty($result->reference)) {
            return $this->lookup($result->gateway, 'reference', $result->reference);
        }

        return [null, null];
    }

    /** @return array{0: ?Payment, 1: ?PaymentBill} */
    private function lookup(string $gateway, string $column, string $value): array
    {
        $bill = PaymentBill::where('gateway', $gateway)->where('method', 'gateway')->where($column, $value)->latest('id')->first();
        if ($bill?->isGroup()) {
            // A "Bayar semua" bill: its oldest fee stands for the group when the
            // request is verified (same club, so the same gateway credentials).
            $lead = $bill->orderedPayments()->first();

            return [$lead, $lead ? $bill : null];
        }
        $payment = $bill?->payment ?? Payment::where($column, $value)->first();

        return [$payment, $payment ? $bill : null];
    }

    /**
     * The fee a callback's reference points at, when its bill code matched
     * nothing (G1). Used only to file the unmatched-payment flag under that
     * fee and club. Never to settle: the bill is not one we opened, and
     * getBillTransactions is not tied to our merchant account or reference.
     */
    public function referenceHint(WebhookResult $result): ?Payment
    {
        if (empty($result->reference)) {
            return null;
        }

        return $this->lookup($result->gateway, 'reference', $result->reference)[0];
    }

    public function transactionAlreadyUsed(WebhookResult $result): bool
    {
        $txn = $result->transactionId;
        if ($txn === null || $txn === '') {
            return false;
        }

        return Payment::where('transaction_id', $txn)->exists()
            || PaymentBill::where('gateway', $result->gateway)->where('transaction_id', $txn)->exists();
    }

    // ── Settling ─────────────────────────────────────────────────────────

    /**
     * Settle a paid, verified (and, where the gateway confirms, confirmed)
     * payment for the fee or group bill it was matched to, then email the
     * receipt. $source is 'webhook', 'reconcile' or 'status_check'.
     *
     * @return string self::SETTLED, self::NOTHING or self::AMOUNT_UNKNOWN
     */
    public function settle(WebhookResult $result, Payment $payment, ?PaymentBill $bill, ?GatewayConfirmation $confirmation, string $source = 'webhook'): string
    {
        if ($confirmation) {
            // Never settle for more than either the callback or the gateway's own record says.
            $result = $result->withPaidAmountSen($result->paidAmountSen === null ? $confirmation->amountSen : min($result->paidAmountSen, $confirmation->amountSen));
        }
        $via = $source === 'webhook' ? 'callback' : $source;

        if ($bill?->isGroup()) {
            $fees = $this->markGroupPaid($bill->id, $result, $confirmation);
            if (! $fees) {
                return self::NOTHING;
            }
            $lead = $fees->first();
            if ($source !== 'webhook') {
                $this->auditReconciled($lead, $bill, $result, $source, $fees->pluck('id')->all());
            }
            try {
                Mail::to($lead->user->email)->send(new PaymentConfirmation($lead, $fees, $bill->fresh() ?? $bill));
            } catch (\Exception $e) {
                Log::error('Payment confirmation email failed', ['payment_id' => $lead->id, 'error' => $e->getMessage()]);
            }
            Log::info("Group bill #{$bill->id}: {$fees->count()} payments marked as paid via {$result->gateway} {$via}");

            return self::SETTLED;
        }

        $outcome = $this->markPaymentPaid($payment->id, $bill?->id, $result, $confirmation);
        if (! $outcome instanceof Payment) {
            return $outcome;
        }
        $payment = $outcome;
        if ($source !== 'webhook') {
            $this->auditReconciled($payment, $bill, $result, $source, [$payment->id]);
        }
        try {
            Mail::to($payment->user->email)->send(new PaymentConfirmation($payment));
        } catch (\Exception $e) {
            Log::error('Payment confirmation email failed', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);
        }
        Log::info("Payment #{$payment->id} marked as paid via {$result->gateway} {$via}");

        return self::SETTLED;
    }

    /**
     * Atomic update under a row lock so two concurrent deliveries (gateways
     * retry on non-200, and the reconcile may run at the same moment) cannot
     * both pass the "still pending" check and double-send the receipt.
     *
     * @return Payment|string the fee settled, or self::NOTHING / self::AMOUNT_UNKNOWN
     */
    private function markPaymentPaid(int $paymentId, ?int $billId, WebhookResult $result, ?GatewayConfirmation $confirmation): Payment|string
    {
        return DB::transaction(function () use ($paymentId, $billId, $result, $confirmation) {
            $payment = Payment::whereKey($paymentId)->with('user')->lockForUpdate()->first();
            $bill = $billId ? PaymentBill::find($billId) : null;

            if (! $payment) {
                Log::error("{$result->gateway}: payment record not found", $result->logContext());

                return self::NOTHING;
            }

            // Idempotency: replays / concurrent retries must not re-process.
            if ($payment->status === 'paid') {
                $this->flagExtraPayment($payment, $bill, $result, $confirmation);

                return self::NOTHING;
            }
            if ($this->transactionAlreadyUsed($result)) {
                return self::NOTHING;
            }

            // Fail closed (G1): with no paid amount from the gateway, no bill
            // record and no bill_amount on the fee, nothing says how much was
            // paid, so it is not settled. Non-200 upstream; audited for the treasurer.
            if ($result->paidAmountSen === null && $bill === null && $payment->bill_amount === null) {
                $this->auditAmountUnknown($payment, $result);

                return self::AMOUNT_UNKNOWN;
            }

            // A transfer slip is still being checked, but the gateway really took
            // the money: settle it as paid via the gateway (ignoring it would lose
            // track of real money) and flag it so the treasurer refunds the transfer.
            $duringVerification = $payment->status === 'pending_verification';
            $verifyingGroup = $duringVerification ? $payment->openTransferGroup() : null;

            // Never mark a fee paid for less than it is worth: the gateway's
            // reported amount when it sends one, otherwise the amount the bill
            // was created for. Return 200 upstream (no retries); audit for the treasurer.
            // Compared with the fee amount only, never amount + gateway fee: a
            // member-borne charge (Billplz adds it to the bill; ToyyibPay may or
            // may not include it in `amount`) makes the paid amount higher, which
            // is accepted. A superseded bill is checked against its own amount.
            $dueSen = $payment->amountSen();
            $short = match (true) {
                $result->paidAmountSen !== null => $result->paidAmountSen < $dueSen,
                $bill !== null => $bill->amount_sen !== $dueSen,
                default => ! $payment->billMatchesAmount(),
            };
            $billNote = $bill?->isSuperseded() ? " on superseded bill {$bill->bill_code}" : '';
            if ($short) {
                if ($this->underpaidSeen($payment, $result, $bill?->bill_code ?? $result->billCode)) {
                    return self::NOTHING; // a retry, or the next reconcile run: flagged once
                }
                Log::warning("{$result->gateway}: paid amount below fee; payment #{$payment->id} left unpaid", [
                    'paid_amount_sen' => $result->paidAmountSen,
                    'bill_amount_sen' => $bill?->amount_sen,
                    'bill_amount' => $payment->bill_amount,
                    'amount' => $payment->amount,
                ]);
                AuditService::log(
                    'payment.underpaid_webhook',
                    "Payment #{$payment->id}: {$this->gatewayLabel($result->gateway)} reported a payment below the fee amount{$billNote}; not marked paid (txn: ".
                        ($result->transactionId ?? 'n/a').').',
                    $payment,
                    $payment->club_id,
                    ['status' => $payment->status, 'amount' => $payment->amount, 'bill_amount' => $payment->bill_amount],
                    ['paid_amount_sen' => $result->paidAmountSen, 'transaction_id' => $result->transactionId, 'bill_code' => $bill?->bill_code ?? $result->billCode]
                );

                return self::NOTHING;
            }

            // The bill that was paid decides who bore the gateway fee: a
            // superseded bill keeps its own fee mode.
            $feePayer = $bill ? $bill->fee_payer : $payment->bill_fee_payer;
            $feeSen = $bill ? $bill->fee_sen : $payment->bill_fee_sen;

            $oldStatus = $payment->status;
            $newAttrs = [
                'status' => 'paid',
                'paid_date' => now()->toDateString(),
                'transaction_id' => $result->transactionId,
                'gateway' => $result->gateway,
                // Paid online: record it, and the gateway fee the club absorbed on
                // this bill (0 when the member paid it) for the dashboard KPI.
                'paid_via' => $result->gateway,
                'fee_absorbed_sen' => $feePayer === 'member' ? 0 : (int) ($feeSen ?? 0),
            ];

            $payment->update($newAttrs);
            $bill?->forceFill(['transaction_id' => $result->transactionId, 'paid_at' => now()])->save();

            $label = $this->gatewayLabel($result->gateway);

            AuditService::log(
                'payment.paid_via_webhook',
                "Payment #{$payment->id} marked paid via {$label}{$billNote} (txn: ".
                    ($result->transactionId ?? 'n/a').'). Receipt '.($payment->receipt_no ?? 'n/a').'.',
                $payment,
                $payment->club_id,
                ['status' => $oldStatus],
                $newAttrs + ($bill ? ['bill_code' => $bill->bill_code] : [])
            );

            if ($duringVerification) {
                // A "Bayar semua" slip covering this fee: name the set and this fee's
                // share, which is what to refund. The rest of the set stays approvable.
                $group = $verifyingGroup;
                $share = $group ? ($group->itemAmounts()[$payment->id] ?? $payment->amountSen()) : null;
                AuditService::log(
                    'payment.gateway_paid_during_verification',
                    "Payment #{$payment->id} was paid via {$label} while the member's transfer slip was still being checked. ".
                        ($group
                            ? 'If the bank transfer also arrived, refund RM '.number_format($share / 100, 2)." to the member (this fee's share of group slip {$group->reference}, RM ".number_format($group->amount_sen / 100, 2).'). The other fees of the slip can still be approved.'
                            : 'If the bank transfer also arrived, refund it to the member.'),
                    $payment,
                    $payment->club_id,
                    ['status' => 'pending_verification', 'transfer_date' => $payment->transfer_date?->toDateString(), 'transfer_ref' => $payment->transfer_ref],
                    ['status' => 'paid', 'gateway' => $result->gateway, 'transaction_id' => $result->transactionId]
                        + ($group ? ['group' => $group->reference, 'refund_sen' => $share] : [])
                );
            }

            return $payment;
        });
    }

    /**
     * Settle a "Bayar semua" bill: every fee it covers is marked paid in one
     * transaction, or none is. Same rules as a single bill: the amount paid
     * must cover the bill, a transaction settles once, and the request was
     * already verified and confirmed with the gateway.
     *
     * The set must still be intact: every fee still owed (pending/overdue) at
     * the amount it was billed for. Otherwise (a fee was edited, paid another
     * way, or its slip is being checked) nothing is applied and the payment is
     * flagged once for a refund. A settled bill paid again is flagged the same
     * way. The club absorbs at most one gateway fee per bill, recorded on the
     * oldest fee.
     *
     * @return \Illuminate\Support\Collection<int, Payment>|null the fees settled
     */
    private function markGroupPaid(int $billId, WebhookResult $result, ?GatewayConfirmation $confirmation)
    {
        return DB::transaction(function () use ($billId, $result, $confirmation) {
            // Fee rows first, then the bill: the same order as markPaymentPaid
            // (fee, then bill), so the two paths can't deadlock. A locking read
            // here, not a plain one: on MariaDB / MySQL (REPEATABLE READ) the first
            // plain read fixes the snapshot, so a delivery that waited for these
            // locks would not see the refund flag the previous one just wrote,
            // and would write it again.
            $ids = DB::table('payment_bill_items')->where('payment_bill_id', $billId)->sharedLock()->pluck('payment_id')->all();
            $fees = Payment::whereIn('id', $ids)->with('user')
                ->orderBy('id')->lockForUpdate()->get()
                ->sortBy(fn (Payment $p) => [$p->due_date->toDateString(), $p->period_start->toDateString(), $p->id])->values();
            $bill = PaymentBill::whereKey($billId)->lockForUpdate()->first();
            if (! $bill) {
                return null;
            }
            $amounts = $bill->itemAmounts();
            $lead = $fees->first();
            $label = $this->gatewayLabel($result->gateway);
            $txn = $result->transactionId ?? 'n/a';

            if ($bill->paid_at !== null) {
                if ($txn !== 'n/a' && $txn === $bill->transaction_id) {
                    return null; // the payment that settled it
                }
                $this->flagGroup($bill, $lead, $result, $confirmation, 'payment.gateway_paid_twice',
                    "Group bill {$bill->bill_code} was already paid via {$label} (txn: ".($bill->transaction_id ?? 'n/a')."), but {$label} reported another payment (txn: {$txn}). The member paid twice: refund one of them.");

                return null;
            }
            if ($this->transactionAlreadyUsed($result)) {
                return null;
            }

            $note = $bill->isSuperseded() ? ' (superseded bill)' : '';
            if ($result->paidAmountSen !== null && $result->paidAmountSen < $bill->amount_sen) {
                if ($lead && $this->underpaidSeen($lead, $result, $bill->bill_code)) {
                    return null;
                }
                Log::warning("{$result->gateway}: paid amount below group bill; nothing marked paid", ['bill_id' => $bill->id, 'paid_amount_sen' => $result->paidAmountSen, 'bill_amount_sen' => $bill->amount_sen]);
                AuditService::log(
                    'payment.underpaid_webhook',
                    "Group bill {$bill->bill_code}{$note}: {$label} reported a payment below the bill amount (RM ".number_format($bill->amount_sen / 100, 2).' for '.count($amounts)." fees); none marked paid (txn: {$txn}).",
                    $lead,
                    $bill->club_id,
                    ['amount_sen' => $bill->amount_sen, 'payment_ids' => array_keys($amounts)],
                    ['paid_amount_sen' => $result->paidAmountSen, 'transaction_id' => $result->transactionId, 'bill_code' => $bill->bill_code]
                );

                return null;
            }

            $intact = $lead !== null && $fees->count() === count($amounts)
                && array_sum($amounts) === $bill->amount_sen
                && $fees->every(fn (Payment $p) => in_array($p->status, ['pending', 'overdue'], true) && $p->amountSen() === $amounts[$p->id]);
            if (! $intact) {
                $why = $fees->reject(fn (Payment $p) => in_array($p->status, ['pending', 'overdue'], true) && $p->amountSen() === ($amounts[$p->id] ?? -1))
                    ->map(fn (Payment $p) => "#{$p->id} is now {$p->status}".($p->amountSen() !== ($amounts[$p->id] ?? -1) ? ' at RM '.number_format($p->amountSen() / 100, 2) : ''))
                    ->implode('; ') ?: 'a fee was removed';
                $this->flagGroup($bill, $lead, $result, $confirmation, 'payment.group_bill_refund',
                    "Group bill {$bill->bill_code}{$note} was paid via {$label} (txn: {$txn}, RM ".number_format(($result->paidAmountSen ?? $bill->amount_sen) / 100, 2)."), but its fees changed since it was opened ({$why}). Nothing was marked paid: refund the member, or record the fees by hand.");

                return null;
            }

            // One bill, one receipt: one number for the whole set, taken after the fee and bill locks
            // (CLAUDE.MD "Lock order"; later locks are only rows reached through these fees), kept on the bill
            // and on every fee.
            $receipt = ReceiptNumbers::allocate(Club::findOrFail($bill->club_id), $bill->id);

            // The bill first: once it is paid the Payment hook no longer supersedes it.
            $bill->forceFill(['transaction_id' => $result->transactionId, 'paid_at' => now(), 'receipt_no' => $receipt['receipt_no']])->save();
            $absorbed = $bill->fee_payer === 'member' ? 0 : (int) ($bill->fee_sen ?? 0);

            foreach ($fees->values() as $i => $payment) {
                // The shared number, set before the save so the Payment hook doesn't issue one of its own.
                $payment->receipt_no = $receipt['receipt_no'];
                $payment->receiptAllocationId = $receipt['id'];
                $oldStatus = $payment->status;
                $newAttrs = [
                    'status' => 'paid',
                    'paid_date' => now()->toDateString(),
                    'transaction_id' => $result->transactionId,
                    'gateway' => $result->gateway,
                    'paid_via' => $result->gateway,
                    // One bill, one gateway fee: counted once, on the oldest fee.
                    'fee_absorbed_sen' => $i === 0 ? $absorbed : 0,
                ];
                $payment->update($newAttrs);

                AuditService::log(
                    'payment.paid_via_webhook',
                    "Payment #{$payment->id} marked paid via {$label} with group bill {$bill->bill_code}{$note} ({$fees->count()} fees, RM ".number_format($bill->amount_sen / 100, 2)."; txn: {$txn}). Receipt {$receipt['receipt_no']} (shared by the set).",
                    $payment,
                    $payment->club_id,
                    ['status' => $oldStatus],
                    $newAttrs + ['bill_code' => $bill->bill_code, 'group_payment_ids' => $fees->pluck('id')->all()]
                );
            }

            return $fees;
        });
    }

    // ── Flags and audit ──────────────────────────────────────────────────

    /**
     * A group payment that settles nothing (paid twice, or its fees changed):
     * audited once per transaction on the oldest fee, for a refund. With a
     * confirm step, flags for one bill never exceed the successful payments
     * the gateway reports for it (minus the one that settled it).
     */
    private function flagGroup(PaymentBill $bill, ?Payment $lead, WebhookResult $result, ?GatewayConfirmation $confirmation, string $action, string $message): void
    {
        $txn = $result->transactionId ?? 'n/a';
        $flags = AuditLog::whereIn('action', ['payment.gateway_paid_twice', 'payment.group_bill_refund'])
            ->where('club_id', $bill->club_id)
            ->latest('id')->limit(500)->get()
            ->filter(fn (AuditLog $log) => ($log->new_values['bill_code'] ?? null) === $bill->bill_code);
        if ($flags->contains(fn (AuditLog $log) => ($log->new_values['transaction_id'] ?? 'n/a') === $txn)) {
            return;
        }
        if ($confirmation) {
            $allowed = $confirmation->successCount - ($bill->paid_at !== null ? 1 : 0);
            if ($flags->count() >= $allowed) {
                Log::warning("{$result->gateway}: extra group payment not confirmed by the gateway; not flagged", $result->logContext() + ['bill_id' => $bill->id]);

                return;
            }
        }
        Log::warning("{$result->gateway}: group bill payment needs a refund", $result->logContext() + ['bill_id' => $bill->id]);

        AuditService::log(
            $action,
            $message,
            $lead,
            $bill->club_id,
            ['amount_sen' => $bill->amount_sen, 'payment_ids' => array_keys($bill->itemAmounts())],
            ['gateway' => $result->gateway, 'transaction_id' => $result->transactionId, 'bill_code' => $bill->bill_code, 'paid_amount_sen' => $result->paidAmountSen]
        );
    }

    /**
     * A gateway payment for a fee that is already paid: by transfer slip
     * (payment.gateway_paid_during_verification) or by another gateway payment
     * (payment.gateway_paid_twice). The member paid twice, so it is recorded
     * once per transaction for a refund.
     *
     * When the gateway confirms callbacks, the flags for one bill never exceed
     * the successful payments it reports for that bill (minus the one that
     * settled the fee), so a leaked token can't fill the log with invented
     * transaction ids, and a callback arriving after the reconcile settled the
     * same payment is not mistaken for a second one. Billplz has no confirm
     * step: its x_signature is the proof.
     */
    private function flagExtraPayment(Payment $payment, ?PaymentBill $bill, WebhookResult $result, ?GatewayConfirmation $confirmation): void
    {
        $txn = $result->transactionId ?? 'n/a';
        $billCode = $bill?->bill_code ?? $result->billCode;
        // Recorded by an admin ("Record payment…": cash, transfer, other) or settled
        // by a checked slip: any gateway payment on top is the member paying twice.
        $byTransfer = in_array($payment->paid_via, ['transfer', 'cash', 'manual'], true);

        if (! $byTransfer && $payment->paid_via !== $result->gateway) {
            return; // legacy row marked paid by hand with no method: nothing reliable to compare with
        }
        if (! $byTransfer && $txn !== 'n/a' && $txn === $payment->transaction_id) {
            return; // the payment that settled the fee
        }

        $flags = AuditLog::whereIn('action', ['payment.gateway_paid_during_verification', 'payment.gateway_paid_twice', 'payment.gateway_paid_after_manual'])
            ->where('auditable_type', Payment::class)
            ->where('auditable_id', $payment->id)
            ->get();
        if ($flags->contains(fn (AuditLog $log) => ($log->new_values['transaction_id'] ?? 'n/a') === $txn)) {
            return;
        }

        if ($confirmation) {
            // This bill settled the fee: one of its successful payments is that one.
            $settledByThisBill = ! $byTransfer && $billCode !== null
                && ($bill
                    ? $bill->transaction_id !== null && $bill->transaction_id === $payment->transaction_id
                    : $payment->bill_code === $billCode); // legacy row with no payment_bills entry
            $allowed = $confirmation->successCount - ($settledByThisBill ? 1 : 0);
            $already = $flags->filter(fn (AuditLog $log) => ($log->new_values['bill_code'] ?? null) === $billCode)->count();
            if ($already >= $allowed) {
                Log::warning("{$result->gateway}: extra payment not confirmed by the gateway; not flagged", $result->logContext() + ['payment_id' => $payment->id]);

                return;
            }
        }

        $label = $this->gatewayLabel($result->gateway);
        // Recorded by an admin with "Record payment…" (cash, other, or a transfer
        // without a slip; a slip transfer has transfer_submitted_at / verified_by): both payments reached the club.
        $recordedByHand = in_array($payment->paid_via, ['cash', 'manual'], true)
            || ($payment->paid_via === 'transfer' && $payment->verified_by === null && $payment->transfer_submitted_at === null);
        if ($recordedByHand) {
            $as = ['cash' => 'cash', 'transfer' => 'bank transfer', 'manual' => 'other'][$payment->paid_via];
            AuditService::log(
                'payment.gateway_paid_after_manual',
                "Payment #{$payment->id} was paid online via {$label} (txn: {$txn}) after it was recorded as {$as}. Both payments reached the club: refund the online payment to the member.",
                $payment,
                $payment->club_id,
                ['status' => 'paid', 'paid_via' => $payment->paid_via],
                ['paid_via' => $payment->paid_via, 'gateway' => $result->gateway, 'transaction_id' => $result->transactionId, 'bill_code' => $billCode, 'paid_amount_sen' => $result->paidAmountSen]
            );

            return;
        }
        if ($byTransfer) {
            AuditService::log(
                'payment.gateway_paid_during_verification',
                "Payment #{$payment->id} was already settled by bank transfer, but {$label} also reported a payment (txn: {$txn}). Refund one of them to the member.",
                $payment,
                $payment->club_id,
                ['status' => 'paid', 'paid_via' => 'transfer'],
                ['gateway' => $result->gateway, 'transaction_id' => $result->transactionId, 'bill_code' => $billCode, 'paid_amount_sen' => $result->paidAmountSen]
            );

            return;
        }

        AuditService::log(
            'payment.gateway_paid_twice',
            "Payment #{$payment->id} was already paid via {$label} (txn: ".($payment->transaction_id ?? 'n/a')."), but {$label} reported another payment (txn: {$txn}, bill ".($billCode ?? 'n/a').'). The member paid twice: refund one of them.',
            $payment,
            $payment->club_id,
            ['status' => 'paid', 'paid_via' => $payment->paid_via, 'transaction_id' => $payment->transaction_id],
            ['gateway' => $result->gateway, 'transaction_id' => $result->transactionId, 'bill_code' => $billCode, 'paid_amount_sen' => $result->paidAmountSen]
        );
    }

    /**
     * An authentic paid callback that matches no fee: money was taken that no
     * record explains. Audited (once per bill, or per transaction without a
     * bill code) so the treasurer sees it. When the callback's reference
     * points at a fee, the entry is filed under that fee's club and fee, so
     * the club's admins see it in-app (G1), not only the super admin.
     */
    public function auditUnmatched(WebhookResult $result, ?Payment $hint = null): void
    {
        Log::error("{$result->gateway}: paid callback matches no payment", $result->logContext());

        $txn = $result->transactionId ?? 'n/a';
        // Once per bill: the gateway confirmed the bill was paid, but a leaked
        // token could still vary the transaction id on every retry.
        $seen = AuditLog::where('action', 'payment.unmatched_webhook')->latest('id')->limit(500)->get()
            ->contains(fn (AuditLog $log) => $result->billCode !== null
                ? ($log->new_values['bill_code'] ?? null) === $result->billCode
                : ($log->new_values['transaction_id'] ?? 'n/a') === $txn);
        if ($seen) {
            return;
        }

        $label = $this->gatewayLabel($result->gateway);
        $bill = $result->billCode ?? $result->reference ?? 'no bill code';
        AuditService::log(
            'payment.unmatched_webhook',
            $hint
                ? "A payment for {$label} bill {$bill} (txn: {$txn}) referencing Payment #{$hint->id} ({$result->reference}) was reported, but the bill isn't ours. Nothing was marked paid: check {$label} before marking the fee paid."
                : "{$label} reported a paid bill ({$bill}, txn: {$txn}) that matches no payment. Check the gateway account and refund or record it by hand.",
            $hint,
            $hint?->club_id,
            [],
            $result->logContext() + ['reference' => $result->reference]
        );
    }

    /**
     * A verified callback says "paid" but the gateway reports no successful
     * transaction for the bill yet (409, the gateway retries). Audited once
     * per bill, so retries or a leaked token can't flood the log.
     */
    public function auditUnconfirmed(Payment $payment, WebhookResult $result, ?string $billCode): void
    {
        $seen = AuditLog::where('action', 'payment.webhook_unconfirmed')
            ->where('auditable_type', Payment::class)
            ->where('auditable_id', $payment->id)
            ->get()
            ->contains(fn (AuditLog $log) => ($log->new_values['bill_code'] ?? null) === $billCode);
        if ($seen) {
            return;
        }

        AuditService::log(
            'payment.webhook_unconfirmed',
            "Payment #{$payment->id}: {$this->gatewayLabel($result->gateway)} sent a paid callback for bill ".($billCode ?? 'n/a').', but its records show no successful payment yet. Not marked paid; the gateway will retry.',
            $payment,
            $payment->club_id,
            ['status' => $payment->status],
            ['bill_code' => $billCode, 'transaction_id' => $result->transactionId]
        );
    }

    /** An underpaid flag for this fee, bill and transaction already exists. */
    private function underpaidSeen(Payment $payment, WebhookResult $result, ?string $billCode): bool
    {
        return AuditLog::where('action', 'payment.underpaid_webhook')
            ->where('auditable_type', Payment::class)
            ->where('auditable_id', $payment->id)
            ->get()
            ->contains(fn (AuditLog $log) => ($log->new_values['bill_code'] ?? null) === $billCode
                && ($log->new_values['transaction_id'] ?? null) === $result->transactionId);
    }

    /** Fail closed: no amount known at all. Once per fee and bill code. */
    private function auditAmountUnknown(Payment $payment, WebhookResult $result): void
    {
        Log::warning("{$result->gateway}: paid callback with no paid amount and no bill amount; payment #{$payment->id} not settled", $result->logContext());
        $billCode = $result->billCode ?? $payment->bill_code;
        $seen = AuditLog::where('action', 'payment.webhook_amount_unknown')
            ->where('auditable_type', Payment::class)
            ->where('auditable_id', $payment->id)
            ->get()
            ->contains(fn (AuditLog $log) => ($log->new_values['bill_code'] ?? null) === $billCode);
        if ($seen) {
            return;
        }

        AuditService::log(
            'payment.webhook_amount_unknown',
            "Payment #{$payment->id}: {$this->gatewayLabel($result->gateway)} reported a payment (txn: ".($result->transactionId ?? 'n/a').'), but neither the amount paid nor the bill amount is known. Not marked paid: check the gateway account, then record the fee by hand.',
            $payment,
            $payment->club_id,
            ['status' => $payment->status],
            ['bill_code' => $billCode, 'transaction_id' => $result->transactionId]
        );
    }

    /** A payment learned of by asking the gateway (no callback): source reconcile or status_check. */
    private function auditReconciled(Payment $payment, ?PaymentBill $bill, WebhookResult $result, string $source, array $paymentIds): void
    {
        AuditService::log(
            'payment.reconciled',
            ($source === 'status_check' ? 'Member status check' : 'Scheduled check').': '.$this->gatewayLabel($result->gateway).' reports bill '.($bill?->bill_code ?? $result->billCode ?? 'n/a').
                ' paid (txn: '.($result->transactionId ?? 'n/a').', RM '.number_format(($result->paidAmountSen ?? 0) / 100, 2).'), but its callback never arrived. Marked paid: '.
                implode(', ', array_map(fn ($id) => "#{$id}", $paymentIds)).'.',
            $payment,
            $payment->club_id,
            [],
            ['source' => $source, 'gateway' => $result->gateway, 'bill_code' => $bill?->bill_code ?? $result->billCode, 'transaction_id' => $result->transactionId, 'paid_amount_sen' => $result->paidAmountSen, 'payment_ids' => $paymentIds]
        );
    }

    // ── Asking the gateway (reconcile and the member status check) ──────

    /**
     * Ask the gateway whether an open bill was paid and, if so, settle it
     * exactly as its callback would have. For a bill in payment_bills (single
     * or group); requeryLegacy() covers fees from before that table.
     */
    public function requery(PaymentBill $bill, string $source = 'reconcile'): string
    {
        if ($bill->method !== 'gateway' || $bill->bill_code === null || ! $bill->isOpen()) {
            return self::NOTHING;
        }
        $payment = $bill->isGroup() ? $bill->orderedPayments()->first() : $bill->payment;
        if (! $payment) {
            return self::NOTHING;
        }

        return $this->ask($bill->gateway, $bill->bill_code, $bill->reference, $payment, $bill, $source);
    }

    /** A fee with payments.bill_code but no payment_bills row (opened before that table). */
    public function requeryLegacy(Payment $payment, string $source = 'reconcile'): string
    {
        if (empty($payment->bill_code) || $payment->gateway === null || ! in_array($payment->status, ['pending', 'overdue'], true)) {
            return self::NOTHING;
        }

        return $this->ask($payment->gateway, $payment->bill_code, $payment->reference, $payment, null, $source);
    }

    /** Whether this gateway can be asked about a bill (ToyyibPay getBillTransactions, with confirmation on). */
    public function canRequery(string $gatewayName): bool
    {
        try {
            $gateway = $this->gateways->driver($gatewayName);
        } catch (\Throwable) {
            return false;
        }

        return $gateway instanceof ConfirmsWebhookPayments && $gateway->confirmsCallbacks();
    }

    private function ask(string $gatewayName, string $billCode, ?string $reference, Payment $payment, ?PaymentBill $bill, string $source): string
    {
        if (! $this->canRequery($gatewayName)) {
            return self::UNSUPPORTED;
        }
        /** @var ConfirmsWebhookPayments $gateway */
        $gateway = $this->gateways->driver($gatewayName);

        try {
            $confirmation = $gateway->confirmPayment($billCode);
        } catch (\Throwable $e) {
            Log::warning("{$gatewayName}: requery failed", ['bill_code' => $billCode, 'error' => $e->getMessage()]);
            $confirmation = null;
        }
        if ($confirmation === null) {
            return self::UNAVAILABLE;
        }
        if (! $confirmation->paid()) {
            return self::UNPAID;
        }

        // The gateway's own payment id when it gives one; otherwise one fixed
        // per bill, so a repeat of this requery dedupes. A later callback with
        // a different id is not taken for a second payment: the confirm step
        // counts the bill's successful payments (see flagExtraPayment).
        $result = new WebhookResult(
            billCode: $billCode,
            reference: $reference,
            paid: true,
            transactionId: $confirmation->transactionId ?? 'requery-'.$billCode,
            gateway: $gatewayName,
            rawPayload: [],
            paidAmountSen: null,
        );
        if ($this->transactionAlreadyUsed($result)) {
            return self::NOTHING;
        }

        // Written as the system: a member's status check only started it.
        return AuditService::asSystem(function () use ($result, $payment, $bill, $confirmation, $source) {
            $outcome = $this->settle($result, $payment, $bill, $confirmation, $source);
            // The gateway lists more than one successful payment for the bill:
            // flag the extra ones for a refund, as their callbacks would have
            // (flagExtraPayment / flagGroup cap the flags at that count).
            if ($outcome === self::SETTLED) {
                for ($n = 2; $n <= min($confirmation->successCount, 5); $n++) {
                    $extra = new WebhookResult($result->billCode, $result->reference, true, $result->transactionId.'#'.$n, $result->gateway, [], null);
                    $this->settle($extra, $payment, $bill?->fresh(), $confirmation, $source);
                }
            }

            return $outcome;
        });
    }

    /**
     * "Semak status bayaran" (G1): when the member's fee has an open bill at a
     * gateway that can be asked, ask it at most once per bill every 30 s and
     * settle a payment whose callback was lost. The caller has already checked
     * that the fee belongs to the signed-in member. Never throws: the stored
     * status is returned to the member either way. Audit entries are written as
     * the system, not as the member.
     */
    public function requeryForMember(Payment $payment): void
    {
        if (! in_array($payment->status, ['pending', 'overdue'], true)) {
            return;
        }
        $bill = PaymentBill::where('payment_id', $payment->id)->where('method', 'gateway')->whereNotNull('bill_code')
            ->whereNull('paid_at')->whereNull('superseded_at')->latest('id')->first();
        $group = PaymentBill::openGatewayGroupsContaining($payment->id)->whereNotNull('bill_code')->latest('id')->first();
        $bill = ($group && (! $bill || $group->id > $bill->id)) ? $group : $bill;

        if ($bill) {
            $this->throttled('bill:'.$bill->id, $bill->gateway, fn () => $this->requery($bill, 'status_check'));
        } elseif ($payment->bill_code && $payment->gateway && ! $payment->bills()->exists()) {
            $this->throttled('fee:'.$payment->id, $payment->gateway, fn () => $this->requeryLegacy($payment, 'status_check'));
        }
    }

    /** The group-bill version of requeryForMember(); the caller has authorized the member. */
    public function requeryGroupForMember(PaymentBill $bill): void
    {
        if ($bill->isGroup() && $bill->method === 'gateway' && $bill->bill_code !== null && $bill->isOpen()) {
            $this->throttled('bill:'.$bill->id, $bill->gateway, fn () => $this->requery($bill, 'status_check'));
        }
    }

    private function throttled(string $key, ?string $gatewayName, callable $run): void
    {
        if ($gatewayName === null || ! $this->canRequery($gatewayName)) {
            return;
        }
        if (! Cache::add('payments:requery:'.$key, true, self::REQUERY_THROTTLE_SECONDS)) {
            return;
        }

        try {
            $run();
        } catch (\Throwable $e) {
            Log::warning('Payment status requery failed', ['key' => $key, 'error' => $e->getMessage()]);
        }
    }

    public function gatewayLabel(string $name): string
    {
        return match ($name) {
            'toyyibpay' => 'ToyyibPay',
            'billplz' => 'Billplz',
            default => ucfirst($name),
        };
    }
}
