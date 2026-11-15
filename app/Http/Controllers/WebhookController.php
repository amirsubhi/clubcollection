<?php

namespace App\Http\Controllers;

use App\Contracts\ConfirmsWebhookPayments;
use App\Contracts\PaymentGateway;
use App\Mail\PaymentConfirmation;
use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\PaymentBill;
use App\Services\AuditService;
use App\Services\PaymentGateways\PaymentGatewayManager;
use App\Support\Payments\GatewayConfirmation;
use App\Support\Payments\WebhookResult;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class WebhookController extends Controller
{
    public function toyyibpay(Request $request, PaymentGatewayManager $manager): Response
    {
        return $this->handle($request, $manager->driver('toyyibpay'));
    }

    public function billplz(Request $request, PaymentGatewayManager $manager): Response
    {
        return $this->handle($request, $manager->driver('billplz'));
    }

    /**
     * Shared webhook pipeline: parse, find the fee, verify, confirm, mark
     * paid, email, audit. Returns 200 for everything it has handled (gateways
     * retry on non-200): 403 when authentication fails, and 503/409 only when
     * a paid callback could not be confirmed yet, so the gateway tries again.
     */
    private function handle(Request $request, PaymentGateway $gateway): Response
    {
        $result = $gateway->parseWebhook($request);
        [$payment, $bill] = $result ? $this->findPayment($result) : [null, null];

        // Verified against the fee it names: the ToyyibPay token is per bill.
        if (! $gateway->verifyWebhookRequest($request, $payment, $bill)) {
            Log::warning("{$gateway->name()} webhook: invalid signature/token", ['ip' => $request->ip(), 'bill_code' => $result?->billCode]);
            abort(403, 'Invalid webhook.');
        }

        // Allow-listed fields only: the payload can carry payer details.
        Log::info("{$gateway->name()} callback received", $result?->logContext() ?? []);

        if (! $result || ! $result->paid) {
            return response('ok', 200);
        }

        // A legacy row found through payments.bill_code/reference must belong
        // to the gateway that is calling (bills in payment_bills always do).
        if ($payment && ! $bill && $payment->gateway !== null && $payment->gateway !== $result->gateway) {
            Log::warning("{$result->gateway}: callback for a payment opened at another gateway ignored", $result->logContext() + ['payment_id' => $payment->id, 'payment_gateway' => $payment->gateway]);

            return response('ok', 200);
        }

        // One gateway transaction settles at most once: a retry, a replay or a
        // callback for a fee an admin has since reverted is ignored.
        if ($this->transactionAlreadyUsed($result)) {
            Log::info("{$result->gateway}: transaction already processed; ignored", $result->logContext());

            return response('ok', 200);
        }

        // Ask the gateway itself before anything is settled or flagged (S1): a
        // leaked callback token alone must not create a payment or an audit row.
        $confirmation = null;
        if ($this->confirms($gateway)) {
            $billCode = $bill?->bill_code ?? $result->billCode ?? $payment?->bill_code;
            if ($billCode === null) {
                // Nothing to ask the gateway about: record nothing.
                Log::warning("{$result->gateway}: paid callback without a bill code can't be confirmed; ignored", $result->logContext() + ['payment_id' => $payment?->id]);

                return $payment ? response('unconfirmed', 409) : response('ok', 200);
            }
            $confirmation = $gateway->confirmPayment($billCode);
            if ($confirmation === null) {
                Log::warning("{$result->gateway}: could not confirm the payment with the gateway; not settled, retry expected", $result->logContext() + ['payment_id' => $payment?->id]);

                return response('retry', 503);
            }
            if (! $confirmation->paid()) {
                Log::warning("{$result->gateway}: gateway reports no successful transaction for this bill; nothing recorded", $result->logContext() + ['payment_id' => $payment?->id]);
                if ($payment) {
                    $this->auditUnconfirmed($payment, $result, $billCode);
                }

                return response('unconfirmed', 409);
            }
        }

        if (! $payment) {
            $this->auditUnmatched($result);

            return response('ok', 200);
        }

        if ($confirmation) {
            // Never settle for more than either the callback or the gateway's own record says.
            $result = $result->withPaidAmountSen($result->paidAmountSen === null ? $confirmation->amountSen : min($result->paidAmountSen, $confirmation->amountSen));
        }

        $payment = $this->markPaymentPaid($payment->id, $bill?->id, $result, $confirmation);

        if ($payment) {
            try {
                Mail::to($payment->user->email)->send(new PaymentConfirmation($payment));
            } catch (\Exception $e) {
                Log::error('Payment confirmation email failed', [
                    'payment_id' => $payment->id,
                    'error' => $e->getMessage(),
                ]);
            }
            Log::info("Payment #{$payment->id} marked as paid via {$gateway->name()} callback");
        }

        return response('ok', 200);
    }

    /**
     * The fee (and the bill) a callback names: every bill ever opened is in
     * payment_bills, superseded ones included; payments.bill_code/reference
     * cover rows from before that table.
     *
     * @return array{0: ?Payment, 1: ?PaymentBill}
     */
    private function findPayment(WebhookResult $result): array
    {
        $column = ! empty($result->billCode) ? 'bill_code' : (! empty($result->reference) ? 'reference' : null);
        if (! $column) {
            return [null, null];
        }
        $value = $column === 'bill_code' ? $result->billCode : $result->reference;

        $bill = PaymentBill::where('gateway', $result->gateway)->where($column, $value)->latest('id')->first();
        $payment = $bill?->payment ?? Payment::where($column, $value)->first();

        return [$payment, $payment ? $bill : null];
    }

    private function transactionAlreadyUsed(WebhookResult $result): bool
    {
        $txn = $result->transactionId;
        if ($txn === null || $txn === '') {
            return false;
        }

        return Payment::where('transaction_id', $txn)->exists()
            || PaymentBill::where('gateway', $result->gateway)->where('transaction_id', $txn)->exists();
    }

    /**
     * An authentic paid callback that matches no fee: money was taken that no
     * record explains. Audited (once per transaction) so the treasurer sees it.
     */
    private function auditUnmatched(WebhookResult $result): void
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

        AuditService::log(
            'payment.unmatched_webhook',
            "{$this->gatewayLabel($result->gateway)} reported a paid bill (".($result->billCode ?? $result->reference ?? 'no bill code').", txn: {$txn}) that matches no payment. Check the gateway account and refund or record it by hand.",
            null,
            null,
            [],
            $result->logContext() + ['reference' => $result->reference]
        );
    }

    /**
     * Atomic update under a row lock so two concurrent webhook deliveries
     * (gateways retry on non-200) cannot both pass the "still pending"
     * check and double-send the confirmation email.
     */
    private function markPaymentPaid(int $paymentId, ?int $billId, WebhookResult $result, ?GatewayConfirmation $confirmation): ?Payment
    {
        return DB::transaction(function () use ($paymentId, $billId, $result, $confirmation) {
            $payment = Payment::whereKey($paymentId)->with('user')->lockForUpdate()->first();
            $bill = $billId ? PaymentBill::find($billId) : null;

            if (! $payment) {
                Log::error("{$result->gateway}: payment record not found", $result->logContext());

                return null;
            }

            // Idempotency: replays / concurrent retries must not re-process.
            if ($payment->status === 'paid') {
                $this->flagExtraPayment($payment, $bill, $result, $confirmation);

                return null;
            }
            if ($this->transactionAlreadyUsed($result)) {
                return null;
            }

            // A transfer slip is still being checked, but the gateway really took
            // the money: settle it as paid via the gateway (ignoring it would lose
            // track of real money) and flag it so the treasurer refunds the transfer.
            $duringVerification = $payment->status === 'pending_verification';

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

                return null;
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
                    ($result->transactionId ?? 'n/a').').',
                $payment,
                $payment->club_id,
                ['status' => $oldStatus],
                $newAttrs + ($bill ? ['bill_code' => $bill->bill_code] : [])
            );

            if ($duringVerification) {
                AuditService::log(
                    'payment.gateway_paid_during_verification',
                    "Payment #{$payment->id} was paid via {$label} while the member's transfer slip was still being checked. ".
                        'If the bank transfer also arrived, refund it to the member.',
                    $payment,
                    $payment->club_id,
                    ['status' => 'pending_verification', 'transfer_date' => $payment->transfer_date?->toDateString(), 'transfer_ref' => $payment->transfer_ref],
                    ['status' => 'paid', 'gateway' => $result->gateway, 'transaction_id' => $result->transactionId]
                );
            }

            return $payment;
        });
    }

    private function confirms(PaymentGateway $gateway): bool
    {
        return $gateway instanceof ConfirmsWebhookPayments && $gateway->confirmsCallbacks();
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
     * transaction ids. Billplz has no confirm step: its x_signature is the proof.
     */
    private function flagExtraPayment(Payment $payment, ?PaymentBill $bill, WebhookResult $result, ?GatewayConfirmation $confirmation): void
    {
        $txn = $result->transactionId ?? 'n/a';
        $billCode = $bill?->bill_code ?? $result->billCode;
        $byTransfer = $payment->paid_via === 'transfer';

        if (! $byTransfer && $payment->paid_via !== $result->gateway) {
            return; // marked paid by hand (cash etc.): nothing reliable to compare with
        }
        if (! $byTransfer && $txn !== 'n/a' && $txn === $payment->transaction_id) {
            return; // the payment that settled the fee
        }

        $flags = AuditLog::whereIn('action', ['payment.gateway_paid_during_verification', 'payment.gateway_paid_twice'])
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
     * A verified callback says "paid" but the gateway reports no successful
     * transaction for the bill yet (409, the gateway retries). Audited once
     * per bill, so retries or a leaked token can't flood the log.
     */
    private function auditUnconfirmed(Payment $payment, WebhookResult $result, ?string $billCode): void
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

    private function gatewayLabel(string $name): string
    {
        return match ($name) {
            'toyyibpay' => 'ToyyibPay',
            'billplz' => 'Billplz',
            default => ucfirst($name),
        };
    }
}
