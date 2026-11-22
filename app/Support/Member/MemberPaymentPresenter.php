<?php

namespace App\Support\Member;

use App\Models\Club;
use App\Models\Payment;
use App\Models\PaymentBill;
use App\Services\LogoService;
use App\Support\Payments\DisplayStatus;
use Illuminate\Support\Collection;

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
     * The status a member should see: paid, verifying, processing (an FPX
     * bill is open), due (within the club's grace period) or overdue. One
     * rule for every page: App\Support\Payments\DisplayStatus.
     */
    public static function displayStatus(Payment $payment): string
    {
        return DisplayStatus::of($payment);
    }

    public static function invoiceNo(Payment $payment): string
    {
        return 'INV-'.str_pad((string) $payment->id, 6, '0', STR_PAD_LEFT);
    }

    /** The fee's receipt number (per club and financial year), or RCP-{id} for a fee paid before they existed. */
    public static function receiptNo(Payment $payment): string
    {
        return $payment->receiptNumber();
    }

    /** A group receipt's number: the one shared by its fees, or RCP-G{bill id} for a group paid before. */
    public static function groupReceiptNo(PaymentBill $bill): string
    {
        return $bill->receipt_no ?? 'RCP-G'.str_pad((string) $bill->id, 6, '0', STR_PAD_LEFT);
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
        // Still to pay and its due date has passed: the member sees it under "Perlu dibayar".
        // During the club's grace period (still "due") the row says when it turns "Lewat".
        $pastDue = in_array($status, [DisplayStatus::DUE, DisplayStatus::OVERDUE], true) && $payment->due_date->lt(today());

        return [
            'id' => $payment->id,
            'invoiceNo' => self::invoiceNo($payment),
            'periodStart' => $payment->period_start->toDateString(),
            'periodEnd' => $payment->period_end->toDateString(),
            'frequency' => $payment->frequency,
            'amount' => self::sen($payment->amount),
            'dueDate' => $payment->due_date->toDateString(),
            'status' => $status,
            'pastDue' => $pastDue,
            // The last day of the grace period (shown as "Lewat selepas <date>"); null unless due and past its due date.
            'lateAfter' => $pastDue && $status === DisplayStatus::DUE
                ? $payment->due_date->copy()->addDays(DisplayStatus::graceDays($payment->club))->toDateString()
                : null,
            'paidDate' => $payment->paid_date?->toDateString(),
            'clubId' => $payment->club_id,
            'urls' => [
                'invoice' => route('member.payments.invoice', $payment),
                'review' => $status === 'paid' ? null : route('member.payments.review', $payment),
                // An FPX payment was started: where to check it before paying again.
                'billStatus' => $status === DisplayStatus::PROCESSING ? DisplayStatus::memberBillUrl($payment) : null,
            ],
        ];
    }

    /**
     * The club as a receipt names it: the details the fee was issued with (payments.issuer_snapshot, written
     * when it became paid), or, for a fee paid before snapshots existed, the club as it is now. The footer comes
     * from the snapshot only: a snapshot written before receipt footers existed has none and shows none.
     *
     * @return array{name: string, registrationNo: ?string, address: ?string, email: ?string, logoUrl: ?string, footer: ?string}
     */
    public static function issuer(Payment $payment): array
    {
        $snap = is_array($payment->issuer_snapshot) ? $payment->issuer_snapshot : null;
        if ($snap === null || ! filled($snap['name'] ?? null)) {
            return self::issuerOf($payment->club);
        }

        return [
            'name' => (string) $snap['name'],
            'registrationNo' => ($snap['registration_no'] ?? null) ?: null,
            'address' => ($snap['address'] ?? null) ?: null,
            'email' => ($snap['email'] ?? null) ?: null,
            'logoUrl' => LogoService::url($snap['logo'] ?? null),
            'footer' => is_string($snap['footer'] ?? null) && $snap['footer'] !== '' ? $snap['footer'] : null,
        ];
    }

    /**
     * The club's details as they are now (invoices, and old receipts without a snapshot).
     *
     * @return array{name: string, registrationNo: ?string, address: ?string, email: ?string, logoUrl: ?string, footer: ?string}
     */
    public static function issuerOf(Club $club): array
    {
        return [
            'name' => (string) $club->name,
            'registrationNo' => $club->registration_no ?: null,
            'address' => $club->address ?: null,
            'email' => $club->email ?: null,
            'logoUrl' => $club->logo_url,
            'footer' => $club->receipt_footer ?: null,
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
            // 'gateway' (FPX), 'transfer' (slip checked by the treasurer) or 'manual'.
            'method' => $payment->gateway ? 'gateway' : ($payment->paid_via === 'transfer' ? 'transfer' : 'manual'),
            'transactionId' => $payment->transaction_id,
            // A transfer shows the bank reference (admin's, else the member's), not the internal bill reference.
            'reference' => $payment->paid_via === 'transfer'
                ? ($payment->transfer_bank_ref ?? $payment->transfer_ref ?? $payment->transferReference())
                : $payment->reference,
            // Who issued it, as it was when the fee was paid (issuer_snapshot; the live club for older fees).
            'issuer' => self::issuer($payment),
        ];
    }

    /**
     * One receipt for a settled group ("Bayar semua"): one receipt number for
     * the set, the total, how it was paid, and one line per fee. Null
     * until the group is settled.
     *
     * @param  Collection<int, Payment>  $fees
     * @return array<string, mixed>|null
     */
    public static function groupReceipt(PaymentBill $bill, Collection $fees): ?array
    {
        if ($bill->paid_at === null || $fees->isEmpty()) {
            return null;
        }
        $amounts = $bill->itemAmounts();
        $transfer = $bill->isTransfer();
        $all = $fees;
        if ($transfer) {
            // A slip receipt covers only what the slip settled: a fee paid online
            // while the slip was checked has its own receipt and is noted instead.
            $fees = $all->filter(fn (Payment $p) => $p->paid_via === 'transfer' && (int) $p->transfer_group_id === (int) $bill->id)->values();
            if ($fees->isEmpty()) {
                return null;
            }
        }
        $lead = $fees->first();
        $amount = $fees->sum(fn (Payment $p) => $amounts[$p->id] ?? self::sen($p->amount));

        return [
            'receiptNo' => self::groupReceiptNo($bill),
            'amount' => $amount,
            'paidAt' => ($lead->paid_date ?? $bill->paid_at)->toDateString(),
            'gateway' => $transfer ? null : self::gatewayLabel($bill->gateway),
            'method' => $transfer ? 'transfer' : 'gateway',
            'transactionId' => $transfer ? null : $bill->transaction_id,
            'reference' => $transfer ? ($lead->transfer_bank_ref ?? $lead->transfer_ref ?? $bill->reference) : $bill->reference,
            // Every fee of the set was paid at once, so the first fee's snapshot names the issuer.
            'issuer' => self::issuer($lead),
            'lines' => $fees->map(fn (Payment $p) => [
                'id' => $p->id,
                // The group's own number for fees settled with it (older groups: each fee's RCP-{id}).
                'receiptNo' => self::receiptNo($p),
                'invoiceNo' => self::invoiceNo($p),
                'periodStart' => $p->period_start->toDateString(),
                'periodEnd' => $p->period_end->toDateString(),
                'amount' => $amounts[$p->id] ?? self::sen($p->amount),
                'invoiceUrl' => route('member.payments.invoice', $p),
            ])->values()->all(),
            // Fees of the slip that were paid online instead (their own receipt has the details).
            'paidOnline' => $all->reject(fn (Payment $p) => $fees->contains('id', $p->id))
                ->filter(fn (Payment $p) => $p->status === 'paid')
                ->map(fn (Payment $p) => [
                    'id' => $p->id,
                    'periodStart' => $p->period_start->toDateString(),
                    'periodEnd' => $p->period_end->toDateString(),
                    'amount' => $amounts[$p->id] ?? self::sen($p->amount),
                    'invoiceUrl' => route('member.payments.invoice', $p),
                ])->values()->all(),
        ];
    }
}
