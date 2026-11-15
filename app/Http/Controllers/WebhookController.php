<?php

namespace App\Http\Controllers;

use App\Contracts\PaymentGateway;
use App\Mail\PaymentConfirmation;
use App\Models\Payment;
use App\Services\AuditService;
use App\Services\PaymentGateways\PaymentGatewayManager;
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
     * Shared webhook pipeline: verify, parse, mark paid, email, audit.
     * Always returns HTTP 200 except when authentication fails (403),
     * because providers retry on non-200 responses.
     */
    private function handle(Request $request, PaymentGateway $gateway): Response
    {
        if (! $gateway->verifyWebhookRequest($request)) {
            Log::warning("{$gateway->name()} webhook: invalid signature/token", ['ip' => $request->ip()]);
            abort(403, 'Invalid webhook.');
        }

        $result = $gateway->parseWebhook($request);

        Log::info("{$gateway->name()} callback received", $result?->rawPayload ?? []);

        if (! $result || ! $result->paid) {
            return response('ok', 200);
        }

        $payment = $this->markPaymentPaid($result);

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
     * Atomic update under a row lock so two concurrent webhook deliveries
     * (gateways retry on non-200) cannot both pass the "still pending"
     * check and double-send the confirmation email.
     */
    private function markPaymentPaid(WebhookResult $result): ?Payment
    {
        return DB::transaction(function () use ($result) {
            $query = null;

            if (! empty($result->billCode)) {
                $query = Payment::where('bill_code', $result->billCode);
            } elseif (! empty($result->reference)) {
                $query = Payment::where('reference', $result->reference);
            }

            if (! $query) {
                Log::error("{$result->gateway}: missing billcode/reference", $result->rawPayload);

                return null;
            }

            $payment = $query->with('user')->lockForUpdate()->first();

            if (! $payment) {
                Log::error("{$result->gateway}: payment record not found", $result->rawPayload);

                return null;
            }

            // Idempotency: replays / concurrent retries must not re-process.
            if ($payment->status === 'paid') {
                return null;
            }

            // Never mark a fee paid for less than it is worth: the gateway's
            // reported amount when it sends one, otherwise the amount the bill
            // was created for. Return 200 upstream (no retries); audit for the treasurer.
            // Compared with the fee amount only, never amount + gateway fee: a
            // member-borne charge (Billplz adds it to the bill; ToyyibPay may or
            // may not include it in `amount`) makes the paid amount higher, which
            // is accepted.
            $dueSen = (int) round(((float) $payment->amount) * 100);
            $short = $result->paidAmountSen !== null
                ? $result->paidAmountSen < $dueSen
                : ! $payment->billMatchesAmount();
            if ($short) {
                Log::warning("{$result->gateway}: paid amount below fee; payment #{$payment->id} left unpaid", [
                    'paid_amount_sen' => $result->paidAmountSen,
                    'bill_amount' => $payment->bill_amount,
                    'amount' => $payment->amount,
                ]);
                AuditService::log(
                    'payment.underpaid_webhook',
                    "Payment #{$payment->id}: {$this->gatewayLabel($result->gateway)} reported a payment below the fee amount; not marked paid (txn: ".
                        ($result->transactionId ?? 'n/a').').',
                    $payment,
                    $payment->club_id,
                    ['status' => $payment->status, 'amount' => $payment->amount, 'bill_amount' => $payment->bill_amount],
                    ['paid_amount_sen' => $result->paidAmountSen, 'transaction_id' => $result->transactionId]
                );

                return null;
            }

            $oldStatus = $payment->status;
            $newAttrs = [
                'status' => 'paid',
                'paid_date' => now()->toDateString(),
                'transaction_id' => $result->transactionId,
                'gateway' => $result->gateway,
                // Paid online: record it, and the gateway fee the club absorbed on
                // this bill (0 when the member paid it) for the dashboard KPI.
                'paid_via' => $result->gateway,
                'fee_absorbed_sen' => $payment->bill_fee_payer === 'member' ? 0 : (int) ($payment->bill_fee_sen ?? 0),
            ];

            $payment->update($newAttrs);

            $label = $this->gatewayLabel($result->gateway);

            AuditService::log(
                'payment.paid_via_webhook',
                "Payment #{$payment->id} marked paid via {$label} (txn: ".
                    ($result->transactionId ?? 'n/a').').',
                $payment,
                $payment->club_id,
                ['status' => $oldStatus],
                $newAttrs
            );

            return $payment;
        });
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
