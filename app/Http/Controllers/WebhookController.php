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

            $oldStatus = $payment->status;
            $newAttrs = [
                'status' => 'paid',
                'paid_date' => now()->toDateString(),
                'transaction_id' => $result->transactionId,
                'gateway' => $result->gateway,
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
