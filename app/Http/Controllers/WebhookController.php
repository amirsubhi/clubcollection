<?php

namespace App\Http\Controllers;

use App\Mail\PaymentConfirmation;
use App\Models\Payment;
use App\Services\AuditService;
use App\Services\ToyyibPayService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class WebhookController extends Controller
{
    public function toyyibpay(Request $request, ToyyibPayService $toyyibPay)
    {
        // Verify shared secret token — prevents unauthorized parties from
        // triggering payment confirmations by posting to the webhook URL.
        $token = $request->query('webhook_token', '');
        if (! $toyyibPay->verifyWebhookSecret($token)) {
            Log::warning('ToyyibPay webhook: invalid token', ['ip' => $request->ip()]);
            abort(403, 'Invalid webhook token.');
        }

        // Log payload without the secret-bearing query parameter.
        $payload = $request->except(['webhook_token']);
        Log::info('ToyyibPay callback received', $payload);

        if (! $toyyibPay->verifyCallback($payload)) {
            Log::warning('ToyyibPay: payment not successful', $payload);
            return response('ok', 200);
        }

        // Atomic update under a row lock so two concurrent webhook deliveries
        // (ToyyibPay retries on non-200) cannot both pass the "still pending"
        // check and double-send the confirmation email.
        $confirmedPayment = DB::transaction(function () use ($payload) {
            $query = null;

            if (! empty($payload['billcode'])) {
                $query = Payment::where('bill_code', $payload['billcode']);
            } elseif (! empty($payload['order_id'])) {
                $query = Payment::where('reference', $payload['order_id']);
            }

            if (! $query) {
                Log::error('ToyyibPay: missing billcode/order_id', $payload);
                return null;
            }

            $payment = $query->lockForUpdate()->first();

            if (! $payment) {
                Log::error('ToyyibPay: payment record not found', $payload);
                return null;
            }

            // Idempotency: if already paid (concurrent delivery, retry, or
            // replay) bail without re-running the email or audit.
            if ($payment->status === 'paid') {
                return null;
            }

            $oldStatus = $payment->status;

            $payment->update([
                'status'         => 'paid',
                'paid_date'      => now()->toDateString(),
                'transaction_id' => $payload['transaction_id'] ?? null,
            ]);

            AuditService::log(
                'payment.paid_via_webhook',
                "Payment #{$payment->id} marked paid via ToyyibPay (txn: ".
                    ($payload['transaction_id'] ?? 'n/a').').',
                $payment,
                $payment->club_id,
                ['status' => $oldStatus],
                ['status' => 'paid', 'paid_date' => now()->toDateString(), 'transaction_id' => $payload['transaction_id'] ?? null]
            );

            return $payment;
        });

        if ($confirmedPayment) {
            try {
                Mail::to($confirmedPayment->user->email)->send(new PaymentConfirmation($confirmedPayment));
            } catch (\Exception $e) {
                Log::error('Payment confirmation email failed', [
                    'payment_id' => $confirmedPayment->id,
                    'error'      => $e->getMessage(),
                ]);
            }
            Log::info("Payment #{$confirmedPayment->id} marked as paid via ToyyibPay callback");
        }

        return response('ok', 200);
    }
}
