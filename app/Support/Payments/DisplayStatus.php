<?php

namespace App\Support\Payments;

use App\Models\Club;
use App\Models\Payment;
use App\Models\PaymentBill;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;

/**
 * The one status word a fee is shown with, everywhere (member pages, admin
 * React, Blade Payments, the Ledger, dashboards). Stored payments.status is
 * the workflow state; this is what a person should read:
 *
 *   paid        settled
 *   verifying   a transfer slip is waiting for an admin
 *   processing  an FPX bill for it (alone or in a "Bayar semua" group) is
 *               open: the member may have paid and the bank not confirmed yet
 *   due         unpaid, up to overdue_grace_days after the due date
 *   overdue     unpaid, past the grace period
 *
 * "processing" wins over due/overdue so nobody is pushed to pay twice. An
 * open bill counts only for PROCESSING_DAYS after the member was last sent to
 * pay it (payment_bills.opened_at, else created_at): a member
 * who walked away from FPX goes back to due/overdue (and gets reminders).
 */
final class DisplayStatus
{
    public const PAID = 'paid';
    public const VERIFYING = 'verifying';
    public const PROCESSING = 'processing';
    public const DUE = 'due';
    public const OVERDUE = 'overdue';

    public const ALL = [self::PAID, self::VERIFYING, self::PROCESSING, self::DUE, self::OVERDUE];

    public const DEFAULT_GRACE_DAYS = 7;

    public const MAX_GRACE_DAYS = 60;

    /** How long an opened, unsettled FPX bill keeps a fee "in progress". */
    public const PROCESSING_DAYS = 3;

    /** Member wording (BM / EN). Admin pages use adminLabel(). */
    public const LABELS = [
        self::PAID => ['bm' => 'Dibayar', 'en' => 'Paid'],
        self::VERIFYING => ['bm' => 'Menunggu pengesahan', 'en' => 'Awaiting verification'],
        self::PROCESSING => ['bm' => 'Dalam proses', 'en' => 'In progress'],
        self::DUE => ['bm' => 'Perlu dibayar', 'en' => 'Due'],
        self::OVERDUE => ['bm' => 'Lewat', 'en' => 'Overdue'],
    ];

    /** The relation an open FPX bill is cached under on a Payment (null when none). */
    private const BILL_RELATION = 'openFpxBill';

    public static function of(Payment $payment): string
    {
        if ($payment->status === 'paid') {
            return self::PAID;
        }
        if ($payment->status === 'pending_verification') {
            return self::VERIFYING;
        }
        if (self::openBill($payment) !== null) {
            return self::PROCESSING;
        }

        return self::pastGrace($payment) ? self::OVERDUE : self::DUE;
    }

    public static function label(string $status, string $lang = 'en'): string
    {
        return self::LABELS[$status][$lang] ?? $status;
    }

    /** English wording on the admin pages: the same words, with FPX named. */
    public static function adminLabel(string $status): string
    {
        return $status === self::PROCESSING ? 'FPX in progress' : self::label($status);
    }

    /** Bootstrap badge classes for the classic admin pages (one tone per word, never colour alone). */
    public static function badgeClass(string $status): string
    {
        return match ($status) {
            self::PAID => 'bg-success-subtle text-success-emphasis border border-success-subtle',
            self::VERIFYING => 'bg-info-subtle text-info-emphasis border border-info-subtle',
            self::PROCESSING => 'bg-primary-subtle text-primary-emphasis border border-primary-subtle',
            self::OVERDUE => 'bg-warning-subtle text-warning-emphasis border border-warning-subtle',
            default => 'bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle',
        };
    }

    /** Bootstrap icon shown with the badge text. */
    public static function badgeIcon(string $status): string
    {
        return match ($status) {
            self::PAID => 'bi-check-circle',
            self::VERIFYING => 'bi-hourglass-split',
            self::PROCESSING => 'bi-arrow-repeat',
            self::OVERDUE => 'bi-exclamation-triangle',
            default => 'bi-clock',
        };
    }

    public static function graceDays(?Club $club): int
    {
        return $club ? $club->overdueGraceDays() : self::DEFAULT_GRACE_DAYS;
    }

    /** Due dates before this day are past the grace period (overdue) today. */
    public static function overdueCutoff(int $graceDays, ?Carbon $today = null): Carbon
    {
        return ($today ?? today())->copy()->subDays($graceDays);
    }

    /** Unpaid and today is later than due date + grace. */
    public static function pastGrace(Payment $payment): bool
    {
        return $payment->due_date->lt(self::overdueCutoff(self::graceDays($payment->club)));
    }

    /** Opened bills newer than this still count as in progress. */
    public static function processingSince(): Carbon
    {
        return now()->subDays(self::PROCESSING_DAYS);
    }

    /**
     * The open FPX bill (single or group) that makes the fee "processing",
     * or null. Cached on the model; prime() fills it for a whole list.
     */
    public static function openBill(Payment $payment): ?PaymentBill
    {
        if (! in_array($payment->status, ['pending', 'overdue'], true)) {
            return null;
        }
        if (! $payment->relationLoaded(self::BILL_RELATION)) {
            self::prime(new EloquentCollection([$payment]));
        }

        return $payment->getRelation(self::BILL_RELATION);
    }

    /**
     * Member page where the started payment can be checked: the single fee's
     * pay result, or the group's result page. Null when nothing is open.
     */
    public static function memberBillUrl(Payment $payment): ?string
    {
        $bill = self::openBill($payment);
        if ($bill === null) {
            return null;
        }

        return $bill->isGroup()
            ? route('member.payments.group-result', $bill)
            : route('member.payments.thankyou', $payment);
    }

    /**
     * Load, for every fee in the list, its club (for the grace period) and
     * open FPX bill, in a fixed number of queries.
     *
     * @param  iterable<Payment>  $payments
     */
    public static function prime(iterable $payments): void
    {
        $list = $payments instanceof EloquentCollection ? $payments : new EloquentCollection(collect($payments)->all());
        if ($list->isEmpty()) {
            return;
        }
        $list->loadMissing('club');

        $open = $list->filter(fn (Payment $p) => in_array($p->status, ['pending', 'overdue'], true) && ! $p->relationLoaded(self::BILL_RELATION));
        $bills = self::openBillsFor($open->pluck('id')->all());
        foreach ($list as $p) {
            if (! $p->relationLoaded(self::BILL_RELATION)) {
                $p->setRelation(self::BILL_RELATION, $bills[$p->id] ?? null);
            }
        }
    }

    /**
     * paymentId => status for a list (primes it first).
     *
     * @param  iterable<Payment>  $payments
     * @return array<int, string>
     */
    public static function many(iterable $payments): array
    {
        $list = $payments instanceof EloquentCollection ? $payments : new EloquentCollection(collect($payments)->all());
        self::prime($list);

        return $list->mapWithKeys(fn (Payment $p) => [$p->id => self::of($p)])->all();
    }

    /**
     * Fee counts per status for a payments query (paid and verifying counted
     * in SQL; open fees loaded lean to apply grace and open bills).
     *
     * @return array<string, int>
     */
    public static function counts(Builder $query): array
    {
        $total = array_fill_keys(self::ALL, 0);
        foreach (self::countsByClub($query) as $counts) {
            foreach ($counts as $status => $n) {
                $total[$status] += $n;
            }
        }

        return $total;
    }

    /**
     * The same counts per club_id, in one pass (Statistics: every club at once).
     *
     * @return array<int, array<string, int>>
     */
    public static function countsByClub(Builder $query): array
    {
        $byClub = [];
        $row = function (int $clubId) use (&$byClub) {
            return $byClub[$clubId] ??= array_fill_keys(self::ALL, 0);
        };
        (clone $query)->whereIn('payments.status', ['paid', 'pending_verification'])
            ->selectRaw('payments.club_id as cid, payments.status as st, COUNT(*) as n')
            ->groupBy('payments.club_id', 'payments.status')
            ->toBase()->get()
            ->each(function ($r) use (&$byClub, $row) {
                $row((int) $r->cid);
                $byClub[(int) $r->cid][$r->st === 'paid' ? self::PAID : self::VERIFYING] += (int) $r->n;
            });
        (clone $query)->whereIn('payments.status', ['pending', 'overdue'])
            ->select('payments.id', 'payments.club_id', 'payments.status', 'payments.due_date')
            ->chunkById(500, function ($chunk) use (&$byClub, $row) {
                $statuses = self::many($chunk);
                foreach ($chunk as $p) {
                    $row((int) $p->club_id);
                    $byClub[(int) $p->club_id][$statuses[$p->id]]++;
                }
            }, 'payments.id', 'id');

        return $byClub;
    }

    /**
     * Restrict a payments query to one display status, in SQL (one club's
     * grace period). Unknown values leave the query unchanged.
     *
     * @param  Builder|\Illuminate\Database\Eloquent\Relations\Relation  $query
     */
    public static function scope($query, string $status, Club $club)
    {
        $cutoff = self::overdueCutoff($club->overdueGraceDays())->toDateString();

        return match ($status) {
            self::PAID => $query->where('payments.status', 'paid'),
            self::VERIFYING => $query->where('payments.status', 'pending_verification'),
            self::PROCESSING => $query->whereIn('payments.status', ['pending', 'overdue'])
                ->where(fn ($q) => self::whereHasOpenBill($q)),
            self::DUE => $query->whereIn('payments.status', ['pending', 'overdue'])
                ->whereDate('payments.due_date', '>=', $cutoff)
                ->whereNot(fn ($q) => self::whereHasOpenBill($q)),
            self::OVERDUE => $query->whereIn('payments.status', ['pending', 'overdue'])
                ->whereDate('payments.due_date', '<', $cutoff)
                ->whereNot(fn ($q) => self::whereHasOpenBill($q)),
            default => $query,
        };
    }

    /** payments.id has an open, recent FPX bill (its own or a group's). */
    private static function whereHasOpenBill($q): void
    {
        $since = self::processingSince();
        $q->whereIn('payments.id', fn ($s) => self::openBillQuery($s->from('payment_bills'), $since)
            ->whereNotNull('payment_bills.payment_id')->select('payment_bills.payment_id'))
            ->orWhereIn('payments.id', fn ($s) => self::openBillQuery($s->from('payment_bill_items')
                ->join('payment_bills', 'payment_bills.id', '=', 'payment_bill_items.payment_bill_id'), $since)
                ->whereNull('payment_bills.payment_id')->select('payment_bill_items.payment_id'));
    }

    private static function openBillQuery($q, Carbon $since)
    {
        return $q->where('payment_bills.method', 'gateway')
            ->whereNotNull('payment_bills.bill_code')
            ->whereNull('payment_bills.paid_at')
            ->whereNull('payment_bills.superseded_at')
            // From the last time the member was sent to pay it (opened_at), else its creation.
            ->where(fn ($w) => $w->where('payment_bills.opened_at', '>=', $since)
                ->orWhere(fn ($w) => $w->whereNull('payment_bills.opened_at')->where('payment_bills.created_at', '>=', $since)));
    }

    /**
     * paymentId => the newest open, recent FPX bill covering it.
     *
     * @param  list<int>  $ids
     * @return array<int, PaymentBill>
     */
    private static function openBillsFor(array $ids): array
    {
        $found = [];
        $since = self::processingSince();
        foreach (array_chunk($ids, 500) as $chunk) {
            $single = self::openBillQuery(PaymentBill::query(), $since)
                ->whereIn('payment_bills.payment_id', $chunk)
                ->get();
            $group = self::openBillQuery(PaymentBill::query(), $since)
                ->whereNull('payment_bills.payment_id')
                ->join('payment_bill_items', 'payment_bill_items.payment_bill_id', '=', 'payment_bills.id')
                ->whereIn('payment_bill_items.payment_id', $chunk)
                ->select('payment_bills.*', 'payment_bill_items.payment_id as covered_payment_id')
                ->get();
            foreach ($single as $b) {
                if (! isset($found[$b->payment_id]) || $b->id > $found[$b->payment_id]->id) {
                    $found[$b->payment_id] = $b;
                }
            }
            foreach ($group as $b) {
                $pid = (int) $b->covered_payment_id;
                unset($b->covered_payment_id);
                if (! isset($found[$pid]) || $b->id > $found[$pid]->id) {
                    $found[$pid] = $b;
                }
            }
        }

        return $found;
    }
}
