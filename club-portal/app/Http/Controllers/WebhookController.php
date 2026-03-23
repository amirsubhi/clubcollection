<?php

namespace App\Http\Controllers;

use App\Mail\PaymentConfirmation;
use App\Models\Payment;
use App\Services\ToyyibPayService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class WebhookController extends Controller
{
    public function toyyibpay(Request $request, ToyyibPayService $toyyibPay)
    {
        // Verify shared secret token — prevents unauthorized parties from
        // triggering payment confirmations by posting to the webhook URL.
        $token = $request->query('webhook_token', '');
        if (!$toyyibPay->verifyWebhookSecret($token)) {
            Log::warning('ToyyibPay webhook: invalid token', ['ip' => $request->ip()]);
            abort(403, 'Invalid webhook token.');
        }

        // Log payload without sensitive query parameters
        Log::info('ToyyibPay callback received', $request->except(['webhook_token']));

        $payload = $request->except(['webhook_token']);

        if (!$toyyibPay->verifyCallback($payload)) {
            Log::warning('ToyyibPay: payment not successful', $payload);
            return response('ok', 200);
        }

        // Find payment by bill_code or reference
        $payment = null;

        if (!empty($payload['billcode'])) {
            $payment = Payment::where('bill_code', $payload['billcode'])->first();
        }

        if (!$payment && !empty($payload['order_id'])) {
            $payment = Payment::where('reference', $payload['order_id'])->first();
        }

        if (!$payment) {
            Log::error('ToyyibPay: payment record not found', $payload);
            return response('ok', 200);
        }

        if ($payment->status === 'paid') {
            return response('ok', 200);
        }

        $payment->update([
            'status'         => 'paid',
            'paid_date'      => now()->toDateString(),
            'transaction_id' => $payload['transaction_id'] ?? null,
        ]);

        try {
            Mail::to($payment->user->email)->send(new PaymentConfirmation($payment));
        } catch (\Exception $e) {
            Log::error('Payment confirmation email failed', ['error' => $e->getMessage()]);
        }

        Log::info("Payment #{$payment->id} marked as paid via ToyyibPay callback");

        return response('ok', 200);
    }
}
