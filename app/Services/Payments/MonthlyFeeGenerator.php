<?php

namespace App\Services\Payments;

use App\Models\Club;
use App\Models\Payment;
use App\Models\User;
use App\Services\AuditService;
use App\Support\Payments\FeeRates;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * "Generate fees": one monthly fee for every member of a club who should pay for the month, at the rate in force
 * (FeeRates). preview() lists every member with what will happen; generate() recomputes the same list and creates
 * the fees one by one, each under the pay-ahead lock of that member, so it never doubles a fee the member (or
 * another run) is creating at the same moment. No email is sent: members see the fee in the portal, and the
 * overdue reminder follows the due date as for any fee.
 */
class MonthlyFeeGenerator
{
    public const BILL = 'bill';

    public const SKIP_INACTIVE = 'inactive';

    public const SKIP_JOINED_LATER = 'joined_later';

    public const SKIP_ALREADY_BILLED = 'already_billed';

    public const SKIP_NO_RATE = 'no_rate';

    /** The level's rate for the month is RM 0 (a waived month): no fee is created. */
    public const SKIP_ZERO_RATE = 'zero_rate';

    /** Only at create time: another request held the member's lock (a pay-ahead in progress). */
    public const SKIP_BUSY = 'busy';

    /** Skip reasons in the order they are checked (the first that applies is shown). */
    public const SKIP_LABELS = [
        self::SKIP_INACTIVE => 'Inactive membership',
        self::SKIP_JOINED_LATER => 'Joined after the month',
        self::SKIP_ALREADY_BILLED => 'Already has a fee covering the month',
        self::SKIP_NO_RATE => 'No fee rate for their level',
        self::SKIP_ZERO_RATE => 'Rate is RM 0',
        self::SKIP_BUSY => 'Busy (another change was in progress): generate again to include them',
    ];

    /** Days after the month's 1st for the default due date. */
    public const DEFAULT_DUE_AFTER_DAYS = 14;

    /**
     * The months that may be generated: this month and next month (first days).
     *
     * @return array<string, Carbon> keyed Y-m
     */
    public static function allowedMonths(): array
    {
        $this_ = now()->startOfMonth()->startOfDay();
        $next = $this_->copy()->addMonthNoOverflow();

        return [$this_->format('Y-m') => $this_, $next->format('Y-m') => $next];
    }

    /** "2026-11" → the month's first day, or null when it is not an allowed month. */
    public static function parseMonth(mixed $value): ?Carbon
    {
        return is_string($value) ? (self::allowedMonths()[$value] ?? null)?->copy() : null;
    }

    /** 14 days after the month's 1st, never before today. */
    public static function defaultDueDate(CarbonInterface $month): Carbon
    {
        $due = Carbon::parse($month)->startOfMonth()->addDays(self::DEFAULT_DUE_AFTER_DAYS)->startOfDay();

        return $due->lt(today()) ? today() : $due;
    }

    /** The latest due date accepted: the last day of the month after the billed month (catches a mistyped year). */
    public static function latestDueDate(CarbonInterface $month): Carbon
    {
        return Carbon::parse($month)->startOfMonth()->addMonthNoOverflow()->endOfMonth()->startOfDay();
    }

    /**
     * Every member of the club with the outcome for $month, sorted by name.
     *
     * @return array{rows: list<array{user: User, level: ?string, levelName: string, outcome: string, sen: ?int}>,
     *               count: int, totalSen: int, skipped: array<string, int>}
     */
    public function preview(Club $club, CarbonInterface $month): array
    {
        $start = Carbon::parse($month)->startOfMonth();
        $end = $start->copy()->endOfMonth();

        // A deleted account takes its club_user row (and fees) with it, so it never shows here.
        $members = $club->members()->orderBy('users.name')->orderBy('users.id')->get();
        $rates = FeeRates::inForce($club, $start);
        $billed = $club->payments()
            ->whereDate('period_start', '<=', $end->toDateString())
            ->whereDate('period_end', '>=', $start->toDateString())
            ->distinct()->pluck('user_id')->flip();

        $rows = [];
        foreach ($members as $user) {
            $pivot = $user->pivot;
            $level = $pivot->job_level !== null && $pivot->job_level !== '' ? (string) $pivot->job_level : null;
            $sen = $level !== null ? FeeRates::sen($rates->get($level)) : null;
            $outcome = match (true) {
                ! $pivot->is_active => self::SKIP_INACTIVE,
                $pivot->joined_date !== null && Carbon::parse($pivot->joined_date)->toDateString() > $end->toDateString() => self::SKIP_JOINED_LATER,
                $billed->has($user->id) => self::SKIP_ALREADY_BILLED,
                $sen === null => self::SKIP_NO_RATE,
                $sen === 0 => self::SKIP_ZERO_RATE,
                default => self::BILL,
            };
            $rows[] = [
                'user' => $user,
                'level' => $level,
                'levelName' => $level !== null ? $club->jobLevelName($level) : '—',
                'outcome' => $outcome,
                'sen' => $outcome === self::BILL ? $sen : null,
                'rateSen' => $sen,
            ];
        }

        return $this->summarise($rows);
    }

    /**
     * Create the fees previewed for $month. Each member is billed on their own (lock, then in one small
     * transaction: still an active member of an active club, overlap re-check, insert and its audit row), so one
     * member's clash never undoes the others.
     *
     * @param  array{rows: list<array<string, mixed>>}  $preview  a fresh preview() of the same club and month
     * @return array{runKey: string, created: int, totalSen: int, skipped: array<string, int>}
     */
    public function generate(Club $club, CarbonInterface $month, CarbonInterface $due, User $admin, array $preview): array
    {
        $start = Carbon::parse($month)->startOfMonth();
        $end = $start->copy()->endOfMonth();
        $runKey = 'gen-'.$start->format('Ym').'-'.Str::lower(Str::random(8));
        $created = 0;
        $totalSen = 0;
        $skipped = array_fill_keys(array_keys(self::SKIP_LABELS), 0);

        foreach ($preview['rows'] as $row) {
            if ($row['outcome'] !== self::BILL) {
                $skipped[$row['outcome']]++;

                continue;
            }
            $user = $row['user'];
            $sen = (int) $row['sen'];

            // Same lock as pay ahead: the member can't create an overlapping fee while this one is made.
            // Taken outside the transaction (the cache store is the database).
            $lock = Cache::lock("pay-ahead:{$club->id}:{$user->id}", 10);
            if (! $lock->get()) {
                $skipped[self::SKIP_BUSY]++;

                continue;
            }
            try {
                $made = DB::transaction(function () use ($club, $user, $start, $end, $due, $admin, $sen, $runKey) {
                    // Still an active member of an active club (deactivated since the list was worked out)?
                    $active = DB::table('club_user')
                        ->join('clubs', 'clubs.id', '=', 'club_user.club_id')
                        ->where('club_user.club_id', $club->id)
                        ->where('club_user.user_id', $user->id)
                        ->where('club_user.is_active', true)
                        ->where('clubs.is_active', true)
                        ->exists();
                    if (! $active) {
                        return self::SKIP_INACTIVE;
                    }
                    $overlaps = $club->payments()->where('user_id', $user->id)
                        ->whereDate('period_start', '<=', $end->toDateString())
                        ->whereDate('period_end', '>=', $start->toDateString())
                        ->exists();
                    if ($overlaps) {
                        return self::SKIP_ALREADY_BILLED;
                    }
                    $amount = number_format($sen / 100, 2, '.', '');
                    $payment = Payment::create([
                        'club_id' => $club->id,
                        'user_id' => $user->id,
                        'recorded_by' => $admin->id,
                        'amount' => $amount,
                        'frequency' => 'monthly',
                        'period_start' => $start->toDateString(),
                        'period_end' => $end->toDateString(),
                        'due_date' => $due->toDateString(),
                        'paid_date' => null,
                        'status' => 'pending',
                        'discount_id' => null,
                    ]);
                    AuditService::log(
                        'payment.created',
                        "Payment of RM {$amount} created for user ID {$user->id} (period {$start->toDateString()}) by Generate fees ({$runKey}).",
                        $payment,
                        $club->id,
                        [],
                        ['run' => $runKey, 'amount' => $amount, 'period_start' => $start->toDateString(), 'due_date' => $due->toDateString()]
                    );

                    return self::BILL;
                });
            } catch (UniqueConstraintViolationException) {
                // payments_club_user_period_unique: a fee for this start month arrived in between.
                $made = self::SKIP_ALREADY_BILLED;
            } finally {
                $lock->release();
            }

            if ($made === self::BILL) {
                $created++;
                $totalSen += $sen;
            } else {
                $skipped[$made]++;
            }
        }

        $skipped = array_filter($skipped);
        $monthLabel = $start->format('M Y');
        AuditService::log(
            'payment.generated',
            "Generated {$created} monthly fee(s) for {$monthLabel}, total RM ".number_format($totalSen / 100, 2, '.', '')
                .', due '.$due->toDateString().'.'
                .($skipped ? ' Skipped: '.collect($skipped)->map(fn ($n, $k) => self::SKIP_LABELS[$k].' '.$n)->implode(', ').'.' : ''),
            null,
            $club->id,
            [],
            [
                'run' => $runKey,
                'month' => $start->format('Y-m'),
                'due_date' => $due->toDateString(),
                'count' => $created,
                'total' => number_format($totalSen / 100, 2, '.', ''),
                'skipped' => $skipped,
            ]
        );

        return ['runKey' => $runKey, 'created' => $created, 'totalSen' => $totalSen, 'skipped' => $skipped];
    }

    /**
     * Who is billed what, for which month and due date: an HMAC (app key) of the sorted "user_id:sen" pairs of
     * the billed rows. The preview posts it and the POST refuses when its own list gives another one (a member
     * swapped for another with the same amount keeps the count and total but not this).
     *
     * @param  array{rows: list<array<string, mixed>>}  $preview
     */
    public static function fingerprint(array $preview, CarbonInterface $month, CarbonInterface $due): string
    {
        $pairs = [];
        foreach ($preview['rows'] as $row) {
            if ($row['outcome'] === self::BILL) {
                $pairs[$row['user']->id] = $row['user']->id.':'.(int) $row['sen'];
            }
        }
        ksort($pairs);

        return hash_hmac('sha256', implode('|', [
            Carbon::parse($month)->format('Y-m'),
            Carbon::parse($due)->toDateString(),
            implode(',', $pairs),
        ]), (string) config('app.key'));
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function summarise(array $rows): array
    {
        $bill = array_filter($rows, fn ($r) => $r['outcome'] === self::BILL);
        $skipped = [];
        foreach ($rows as $r) {
            if ($r['outcome'] !== self::BILL) {
                $skipped[$r['outcome']] = ($skipped[$r['outcome']] ?? 0) + 1;
            }
        }

        return [
            'rows' => $rows,
            'count' => count($bill),
            'totalSen' => array_sum(array_map(fn ($r) => (int) $r['sen'], $bill)),
            'skipped' => $skipped,
        ];
    }
}
