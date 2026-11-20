<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGateway;
use App\Models\Payment;
use App\Models\User;
use App\Services\AuditService;
use App\Support\Payments\GatewayFee;
use App\Support\Payments\PaymentBillResult;
use Illuminate\Support\Facades\DB;

/**
 * Opens a new gateway bill for one fee and records it (payment_bills row,
 * the fee's bill fields, and a payment.bill_replaced audit entry when an
 * earlier bill is superseded). Shared by the member pay flow and the
 * toyyibpay:sandbox-check command so both use the same code path.
 *
 * The caller holds the per-fee pay lock and has already checked that the
 * fee can be paid online (not paid, no slip being checked, gateway configured).
 */
class FeeBillService
{
    public function open(Payment $payment, PaymentGateway $gateway, User $payer): ?PaymentBillResult
    {
        $club = $payment->club;
        $fee = GatewayFee::forClub($club);
        $refNo = 'PAY-'.$payment->id.'-'.time();
        $callbackRoute = match ($gateway->name()) {
            'toyyibpay' => 'webhook.toyyibpay',
            'billplz' => 'webhook.billplz',
            default => throw new \LogicException("No webhook route registered for gateway '{$gateway->name()}'"),
        };

        $result = $gateway->createBill([
            'club' => $club,
            'bill_name' => "Club Fee – {$club->name}",
            'description' => "Payment for {$payment->period_start->format('M Y')} to {$payment->period_end->format('M Y')}",
            'amount' => $payment->amount,
            'return_url' => route('member.payments.thankyou', $payment),
            'callback_url' => route($callbackRoute),
            'reference_no' => $refNo,
            'payer_name' => $payer->name,
            'payer_email' => $payer->email,
            'club_name' => $club->name,
            'fee' => $fee,
        ]);

        if (! $result) {
            return null;
        }

        $billFields = ['bill_code', 'bill_amount', 'bill_fee_payer', 'bill_fee_sen', 'gateway', 'reference'];
        $old = $payment->only($billFields);

        // bill_amount is the fee amount; the fee mode is stored alongside so the
        // bill is reused only while amount, gateway and fee mode all still match,
        // and so the dashboard can total the fees the club absorbed.
        DB::transaction(function () use ($payment, $result, $fee, $refNo) {
            $payment->bills()->whereNull('superseded_at')->update(['superseded_at' => now()]);
            $payment->bills()->create([
                'gateway' => $result->gateway,
                'bill_code' => $result->billCode,
                'reference' => $refNo,
                'amount_sen' => $payment->amountSen(),
                'fee_payer' => $fee->payer,
                'fee_sen' => $fee->feeSen,
                'opened_at' => now(),
            ]);
            $payment->update([
                'bill_code' => $result->billCode,
                'bill_amount' => $payment->amount,
                'bill_fee_payer' => $fee->payer,
                'bill_fee_sen' => $fee->feeSen,
                'reference' => $refNo,
                'gateway' => $result->gateway,
            ]);
        });

        // An earlier bill (other gateway, or a different amount) is superseded:
        // it stays in payment_bills, so a late payment against it still settles
        // (or is flagged when the amount is wrong).
        if (! empty($old['bill_code'])) {
            AuditService::log(
                'payment.bill_replaced',
                "Payment #{$payment->id}: bill {$old['bill_code']} (".($old['gateway'] ?? 'n/a').") replaced by {$result->billCode} ({$result->gateway}).",
                $payment,
                $payment->club_id,
                $old,
                $payment->only($billFields)
            );
        }

        return $result;
    }
}
