<?php

namespace App\Http\Controllers;

use App\Contracts\ConfirmsWebhookPayments;
use App\Contracts\PaymentGateway;
use App\Models\AuditLog;
use App\Models\Payment;
use App\Services\AuditService;
use App\Services\PaymentGateways\PaymentGatewayManager;
use App\Services\Payments\SettlementService;
use App\Support\Payments\WebhookResult;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

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

    public function __construct(private SettlementService $settlement) {}

    /**
     * Shared webhook pipeline: parse, find the fee, verify, confirm, then
     * settle through SettlementService (the same code the reconcile and the
     * member status check use). Returns 200 for everything it has handled
     * (gateways retry on non-200): 403 when authentication fails, and 503/409
     * only when a paid callback could not be confirmed or settled yet (409 too
     * when no amount at all is known), so the gateway tries again.
     */
    private function handle(Request $request, PaymentGateway $gateway): Response
    {
        $result = $gateway->parseWebhook($request);
        [$payment, $bill] = $result ? $this->settlement->findPayment($result) : [null, null];

        // Verified against the fee it names: the ToyyibPay token is per bill.
        if (! $gateway->verifyWebhookRequest($request, $payment, $bill)) {
            Log::warning("{$gateway->name()} webhook: invalid signature/token", ['ip' => $request->ip(), 'bill_code' => $result?->billCode]);
            abort(403, 'Invalid webhook.');
        }

        // Allow-listed fields only: the payload can carry payer details.
        Log::info("{$gateway->name()} callback received", $result?->logContext() ?? []);

        // Sandbox test only (docs/SANDBOX-TEST.md): show the tester, in the
        // audit log, which fields ToyyibPay really sent. Never in live mode.
        if ($gateway->name() === 'toyyibpay' && config('toyyibpay.sandbox') && config('toyyibpay.log_callbacks')) {
            $this->auditSandboxCallback($request, $result, $payment);
        }

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
        if ($this->settlement->transactionAlreadyUsed($result)) {
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
                    $this->settlement->auditUnconfirmed($payment, $result, $billCode);
                }

                return response('unconfirmed', 409);
            }
        }

        if (! $payment) {
            // The bill code is not on record. Its reference may still name one of
            // our fees: never settle on it (the bill isn't ours, and anyone can pay
            // a bill of their own and send its code here), but flag it on that fee
            // so the club's admins check ToyyibPay before marking the fee paid (G1).
            $this->settlement->auditUnmatched($result, $this->settlement->referenceHint($result));

            return response('ok', 200);
        }

        $outcome = $this->settlement->settle($result, $payment, $bill, $confirmation, 'webhook');

        return $outcome === SettlementService::AMOUNT_UNKNOWN
            ? response('amount unknown', 409)
            : response('ok', 200);
    }

    private function confirms(PaymentGateway $gateway): bool
    {
        return $gateway instanceof ConfirmsWebhookPayments && $gateway->confirmsCallbacks();
    }

    /**
     * Callback fields whose values the sandbox audit entry may keep: status,
     * ids and amounts only. Every other field is recorded by name alone, so
     * payer names, emails and phones never reach the audit log.
     */
    public const SANDBOX_CALLBACK_FIELDS = [
        'status', 'status_id', 'reason', 'msg', 'billcode', 'order_id', 'refno',
        'transaction_id', 'amount', 'transaction_time', 'billpaymentStatus', 'billpaymentAmount',
    ];

    /** At most this many sandbox entries per bill, and per day overall. */
    private const SANDBOX_CALLBACKS_PER_BILL = 20;

    private const SANDBOX_CALLBACKS_PER_DAY = 200;

    /**
     * A verified ToyyibPay sandbox callback as received (field names, the
     * allow-listed values, content type) next to how parseWebhook() read it,
     * so a field-name or amount-unit mismatch is visible in the audit log.
     */
    private function auditSandboxCallback(Request $request, ?WebhookResult $result, ?Payment $payment): void
    {
        $billCode = $result?->billCode;
        $recent = AuditLog::where('action', 'payment.sandbox_callback')->where('created_at', '>=', now()->subDay())->latest('id')->limit(self::SANDBOX_CALLBACKS_PER_DAY)->get();
        if ($recent->count() >= self::SANDBOX_CALLBACKS_PER_DAY
            || $recent->filter(fn (AuditLog $log) => ($log->new_values['bill_code'] ?? null) === $billCode)->count() >= self::SANDBOX_CALLBACKS_PER_BILL) {
            return;
        }

        $fields = $request->except(['webhook_token']);
        $names = array_slice(array_map(fn ($k) => mb_substr((string) $k, 0, 64), array_keys($fields)), 0, 40);
        $values = [];
        foreach (self::SANDBOX_CALLBACK_FIELDS as $key) {
            if (array_key_exists($key, $fields)) {
                $v = $fields[$key];
                $values[$key] = is_scalar($v) || $v === null ? mb_substr((string) $v, 0, 64) : '['.gettype($v).']';
            }
        }
        $readAs = [
            'paid' => (bool) $result?->paid,
            'amount_sen' => $result?->paidAmountSen,
            'transaction_id' => $result?->transactionId,
            'bill_code' => $billCode,
            'reference' => $result?->reference,
        ];

        $amount = $readAs['amount_sen'] === null ? 'none' : 'RM '.number_format($readAs['amount_sen'] / 100, 2);
        AuditService::log(
            'payment.sandbox_callback',
            'ToyyibPay sandbox callback for bill '.($billCode ?? 'n/a').($payment ? " (Payment #{$payment->id})" : ' (matches no payment)').
                '. Fields received: '.($names ? implode(', ', $names) : 'none').
                '. Read by the app as: '.($readAs['paid'] ? 'PAID' : 'NOT PAID').", amount {$amount}, transaction id ".($readAs['transaction_id'] ?? 'none').'.',
            $payment,
            $payment?->club_id,
            [],
            [
                'bill_code' => $billCode,
                'fields_received' => $names,
                'values' => $values,
                'content_type' => mb_substr((string) $request->header('Content-Type', ''), 0, 100),
                'read_as' => $readAs,
                'matched_payment_id' => $payment?->id,
            ]
        );
    }
}
