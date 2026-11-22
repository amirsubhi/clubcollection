<?php

namespace App\Support\Payments;

use App\Models\Club;
use App\Models\Payment;
use App\Support\Admin\FundFigures;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Receipt numbers per club (settings phase 2): {PREFIX}-{FY start year}-{NNNN}, e.g. RCP-2026-0001. The
 * sequence belongs to the club and its financial year (clubs.financial_year_start_month) and starts again at 1
 * each year; the prefix (clubs.receipt_prefix) is read when a number is issued, so changing it affects only
 * later receipts and the sequence carries on.
 *
 * Issued when a fee becomes paid: Payment::booted() for one fee (the same hook as the issuer snapshot), and
 * SettlementService::markGroupPaid() / TransferVerificationService::approveGroup() for a "Bayar semua" bill or
 * slip, which is one receipt document with one number shared by its fees.
 *
 * Promise: a number is NEVER reused. It is not gap-free: a fee taken back from paid keeps its number recorded
 * as voided (receipt_allocation_payments.voided_at), so that number never appears on a valid receipt again, and
 * paying the fee again issues a new number. (A settlement that rolls back also rolls back its counter step, so
 * a number nobody was ever given is simply issued to the next receipt.)
 *
 * The financial year is the one the number is issued in (today in the app timezone), not the fee's paid_date
 * (which an admin may backdate), so each year's sequence runs in the order receipts were issued.
 *
 * Locking: the counter row (receipt_counters, one per club and year) is advanced with one upsert
 * (INSERT … ON DUPLICATE KEY UPDATE / ON CONFLICT DO UPDATE), which takes the row's exclusive lock directly,
 * and read back under that lock (lockForUpdate). No locking read comes first: on MariaDB / MySQL a locking read
 * that finds no row takes a gap lock, and two first receipts of a year would then deadlock on the insert. The
 * lock is held to the end of the caller's transaction, which serialises receipts within one club and year.
 * It is taken after the caller's own locks (payment_bill_items → payments → payment_bills → receipt_counters):
 * see CLAUDE.MD "Lock order".
 */
final class ReceiptNumbers
{
    public const DEFAULT_PREFIX = 'RCP';

    /** 2-6 capital letters or digits (\z, not $: $ would also accept a trailing newline). */
    public const PREFIX_PATTERN = '/\A[A-Z0-9]{2,6}\z/';

    /**
     * Take the next number for the club: a new allocation row, never reused. Runs in the caller's transaction
     * when there is one (a savepoint), else in its own.
     *
     * @return array{id: int, receipt_no: string}
     */
    public static function allocate(Club $club, ?int $paymentBillId = null, \DateTimeInterface|string|null $paidOn = null): array
    {
        // The financial year of the day the money was paid (the date on the receipt and in the ledger), so a
        // slip sent on 28 June and approved on 2 July is numbered in the year it was paid. Fixed at issue: a later
        // edit of the paid date never renumbers.
        $fy = self::financialYear($club, $paidOn);
        $prefix = self::prefix($club);

        return DB::transaction(function () use ($club, $fy, $prefix, $paymentBillId) {
            DB::table('receipt_counters')->upsert(
                [['club_id' => $club->id, 'fy_start_year' => $fy, 'last_no' => 1]],
                ['club_id', 'fy_start_year'],
                ['last_no' => DB::raw('last_no + 1')]
            );
            $seq = (int) DB::table('receipt_counters')
                ->where('club_id', $club->id)->where('fy_start_year', $fy)
                ->lockForUpdate()->value('last_no');
            $receiptNo = self::format($prefix, $fy, $seq);
            // The unique indexes (club_id + receipt_no, club_id + year + seq) are the backstop: a duplicate fails
            // the whole transaction rather than issue a number twice.
            $id = (int) DB::table('receipt_allocations')->insertGetId([
                'club_id' => $club->id,
                'fy_start_year' => $fy,
                'seq' => $seq,
                'receipt_no' => $receiptNo,
                'payment_bill_id' => $paymentBillId,
                'created_at' => now(),
            ]);

            return ['id' => $id, 'receipt_no' => $receiptNo];
        });
    }

    /** Record that an allocation was issued to a fee (after the fee row exists). */
    public static function link(int $allocationId, int $paymentId): void
    {
        DB::table('receipt_allocation_payments')->insertOrIgnore([
            'receipt_allocation_id' => $allocationId,
            'payment_id' => $paymentId,
            'created_at' => now(),
        ]);
    }

    /** The fee was taken back from paid: its number stays spent, recorded as voided. */
    public static function void(int $paymentId): void
    {
        DB::table('receipt_allocation_payments')->where('payment_id', $paymentId)->whereNull('voided_at')
            ->update(['voided_at' => now()]);
    }

    /**
     * The numbers a fee held and lost when it was taken back from paid, oldest first.
     *
     * @return list<string>
     */
    public static function voidedFor(Payment $payment): array
    {
        return DB::table('receipt_allocation_payments')
            ->join('receipt_allocations', 'receipt_allocations.id', '=', 'receipt_allocation_payments.receipt_allocation_id')
            ->where('receipt_allocation_payments.payment_id', $payment->id)
            ->whereNotNull('receipt_allocation_payments.voided_at')
            ->orderBy('receipt_allocations.id')
            ->pluck('receipt_allocations.receipt_no')->all();
    }

    /** What the club's next receipt would be numbered now (shown on the club profile; nothing is reserved). */
    public static function preview(Club $club, ?string $prefix = null): string
    {
        $fy = self::financialYear($club);
        $last = (int) DB::table('receipt_counters')->where('club_id', $club->id)->where('fy_start_year', $fy)->value('last_no');

        return self::format($prefix ?? self::prefix($club), $fy, $last + 1);
    }

    public static function format(string $prefix, int $fyStartYear, int $seq): string
    {
        return $prefix.'-'.$fyStartYear.'-'.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }

    /** The club's prefix, or RCP when it is unset or not valid. */
    public static function prefix(Club $club): string
    {
        $prefix = (string) ($club->receipt_prefix ?? '');

        return preg_match(self::PREFIX_PATTERN, $prefix) === 1 ? $prefix : self::DEFAULT_PREFIX;
    }

    /** The year the club's current financial year started in (today, app timezone). */
    public static function financialYear(Club $club, \DateTimeInterface|string|null $paidOn = null): int
    {
        $day = $paidOn === null || $paidOn === '' ? Carbon::now() : Carbon::parse($paidOn);

        return FundFigures::financialYearStart($day, $club)->year;
    }
}
