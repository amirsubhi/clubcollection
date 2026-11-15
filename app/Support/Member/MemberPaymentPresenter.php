<?php

namespace App\Support\Member;

use App\Models\Payment;

/**
 * Shapes a Payment for the member-facing Inertia pages.
 *
 * Fields are listed explicitly so admin-only columns (notes, recorded_by,
 * bill_code, ...) never reach the browser. Money is integer sen.
 */
final class MemberPaymentPresenter
{
    /** Decimal ringgit (string or float) to integer sen. */
    public static function sen(mixed $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    /**
     * The status a member should see: a pending fee whose due date is before
     * today is shown as overdue even before the nightly reminder job flips it.
     * The summary SQL in the member controllers uses the same rule.
     */
    public static function displayStatus(Payment $payment): string
    {
        if ($payment->status === 'paid') {
            return 'paid';
        }

        return ($payment->status === 'overdue' || $payment->due_date->lt(today())) ? 'overdue' : 'pending';
    }

    public static function invoiceNo(Payment $payment): string
    {
        return 'INV-'.str_pad((string) $payment->id, 6, '0', STR_PAD_LEFT);
    }

    public static function receiptNo(Payment $payment): string
    {
        return 'RCP-'.str_pad((string) $payment->id, 6, '0', STR_PAD_LEFT);
    }

    public static function gatewayLabel(?string $gateway): ?string
    {
        return match ($gateway) {
            null, '' => null,
            'toyyibpay' => 'ToyyibPay',
            'billplz' => 'Billplz',
            default => ucfirst($gateway),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public static function summary(Payment $payment): array
    {
        $status = self::displayStatus($payment);

        return [
            'id' => $payment->id,
            'invoiceNo' => self::invoiceNo($payment),
            'periodStart' => $payment->period_start->toDateString(),
            'periodEnd' => $payment->period_end->toDateString(),
            'frequency' => $payment->frequency,
            'amount' => self::sen($payment->amount),
            'dueDate' => $payment->due_date->toDateString(),
            'status' => $status,
            'paidDate' => $payment->paid_date?->toDateString(),
            'clubId' => $payment->club_id,
            'urls' => [
                'invoice' => route('member.payments.invoice', $payment),
                'review' => $status === 'paid' ? null : route('member.payments.review', $payment),
            ],
        ];
    }

    /**
     * Receipt fields for a paid fee (null otherwise).
     *
     * @return array<string, mixed>|null
     */
    public static function receipt(Payment $payment): ?array
    {
        if ($payment->status !== 'paid') {
            return null;
        }

        return [
            'receiptNo' => self::receiptNo($payment),
            'amount' => self::sen($payment->amount),
            'paidAt' => ($payment->paid_date ?? $payment->updated_at)->toDateString(),
            'gateway' => self::gatewayLabel($payment->gateway),
            'transactionId' => $payment->transaction_id,
            'reference' => $payment->reference,
        ];
    }
}
