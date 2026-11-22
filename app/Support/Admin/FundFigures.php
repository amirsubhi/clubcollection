<?php

namespace App\Support\Admin;

use App\Models\Club;
use App\Models\Expense;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * FundFigures: the one definition of the club's fund figures. The admin
 * dashboard, the Fund Usage KPIs, the member "where your money goes" card
 * and the ledger all read their money from here, so they always agree.
 *
 *  - Collected: fees with status "paid", placed by paid_date.
 *  - Spent: money that left the fund, always reported with its split:
 *      expenses + welfare: approved (counted) Fund Usage records, placed by
 *        cash date (paid_date, else the invoice date; see cashDate()).
 *        Drafts, records awaiting approval and void records never count;
 *      bank / gateway charges (Caj bank / gateway): the gateway fee the club
 *        absorbed on a fee paid online (payments.fee_absorbed_sen), placed by
 *        that fee's paid_date. The gateway pays out the fee minus its charge,
 *        so the charge is money out like any other.
 *  - Fund balance (baki dana) at a date: everything collected up to and
 *    including that date minus everything spent up to and including it.
 *    "Today's balance" is balanceAt(today); the ledger's opening balance
 *    for a From date is balanceAt(the day before).
 *  - Windows: a figure for a period always comes with its window (from,
 *    to and a label), so a screen never shows an unlabelled total.
 *
 * Sums are done in SQL and returned as integer sen. Date filters are plain
 * range bounds, so this works on SQLite and MySQL alike.
 */
final class FundFigures
{
    /** Name of the bank / gateway charges spending line. */
    public const CHARGES_LABEL = 'Bank / gateway charges';

    /** The same line in Bahasa Melayu (member pages). */
    public const CHARGES_LABEL_MS = 'Caj bank / gateway';

    /** Name of the welfare spending line (a total only, never a case count). */
    public const WELFARE_LABEL = 'Welfare';

    /** The same line in Bahasa Melayu (member pages). */
    public const WELFARE_LABEL_MS = 'Kebajikan';

    /** SQL expression for an expense's cash date: COALESCE(paid_date, expense_date). */
    public static function cashDate(string $table = 'expenses'): string
    {
        return "COALESCE({$table}.paid_date, {$table}.expense_date)";
    }

    public function __construct(private Club $club) {}

    public static function for(Club $club): self
    {
        return new self($club);
    }

    // ── Windows ──────────────────────────────────────────────────────────

    /**
     * The year so far, up to today: from 1 January, or from the first day of the club's financial year when it
     * starts in another month (Club::financialYearStartMonth(); with 1, the calendar year, the label and dates
     * are exactly what they always were). Pass the Club for its own start month.
     *
     * @return array{from: Carbon, to: Carbon, key: string, label: string, range: string, short: string}
     */
    public static function yearToDate(?CarbonInterface $today = null, Club|int $startMonth = 1): array
    {
        $month = self::startMonth($startMonth);
        $to = Carbon::parse(($today ?? now())->toDateString());
        if ($month === 1) {
            return self::makeWindow($to->copy()->startOfYear(), $to, 'year', 'This year so far', 'This year');
        }
        $label = self::yearLabel($to, $month);

        return self::makeWindow(self::financialYearStart($to, $month), $to, 'year', "This financial year so far ({$label})", $label);
    }

    /** The first day of the financial year (starting in $startMonth) that $date falls in. */
    public static function financialYearStart(CarbonInterface $date, Club|int $startMonth = 1): Carbon
    {
        $month = self::startMonth($startMonth);
        $d = Carbon::parse($date->toDateString());
        // Built from day 1, never by setting the month on e.g. the 31st (which would overflow).
        $year = $d->month >= $month ? $d->year : $d->year - 1;

        return Carbon::create($year, $month, 1)->startOfDay();
    }

    /**
     * The year a date falls in, as people say it: "2026" for a calendar year, "FY 2026/27" for a financial year
     * starting in another month (the year it starts in / the year it ends in).
     */
    public static function yearLabel(?CarbonInterface $date = null, Club|int $startMonth = 1): string
    {
        $month = self::startMonth($startMonth);
        $date ??= now();
        if ($month === 1) {
            return (string) $date->year;
        }
        $start = self::financialYearStart($date, $month)->year;

        return 'FY '.$start.'/'.substr((string) ($start + 1), -2);
    }

    private static function startMonth(Club|int $startMonth): int
    {
        $month = $startMonth instanceof Club ? $startMonth->financialYearStartMonth() : $startMonth;

        return $month >= 1 && $month <= 12 ? $month : 1;
    }

    /**
     * The same days one year earlier (for "vs last year").
     *
     * @param  array{from: CarbonInterface, to: CarbonInterface}  $window
     * @return array{from: Carbon, to: Carbon, key: string, label: string, range: string}
     */
    public static function yearEarlier(array $window): array
    {
        return self::makeWindow(Carbon::parse($window['from'])->subYearNoOverflow(), Carbon::parse($window['to'])->subYearNoOverflow(), 'year-earlier', 'Same period last year');
    }

    /**
     * The first day of the month $months-1 months ago to today ("last 12 months").
     *
     * @return array{from: Carbon, to: Carbon, key: string, label: string, range: string}
     */
    public static function lastMonths(int $months = 12, ?CarbonInterface $today = null): array
    {
        $to = Carbon::parse(($today ?? now())->toDateString());

        return self::makeWindow($to->copy()->startOfMonth()->subMonthsNoOverflow($months - 1), $to, "{$months}m", "Last {$months} months");
    }

    /**
     * $short: a short name for a period tab (e.g. "This year", "FY 2026/27"); the label when not given.
     *
     * @return array{from: Carbon, to: Carbon, key: string, label: string, range: string, short: string}
     */
    public static function makeWindow(CarbonInterface $from, CarbonInterface $to, string $key, string $label, ?string $short = null): array
    {
        $from = Carbon::parse($from->toDateString());
        $to = Carbon::parse($to->toDateString());

        return ['from' => $from, 'to' => $to, 'key' => $key, 'label' => $label, 'range' => self::rangeText($from, $to), 'short' => $short ?? $label];
    }

    /** "1 Jan – 15 Nov 2026", or "1 Dec 2025 – 15 Nov 2026" across years. */
    public static function rangeText(CarbonInterface $from, CarbonInterface $to): string
    {
        return $from->year === $to->year
            ? $from->format('j M').' – '.$to->format('j M Y')
            : $from->format('j M Y').' – '.$to->format('j M Y');
    }

    /**
     * A window as page props: ISO dates plus its label and range text.
     *
     * @return array{key: string, label: string, short: string, range: string, from: string, to: string}
     */
    public static function windowProps(array $window): array
    {
        return [
            'key' => $window['key'],
            'label' => $window['label'],
            'short' => $window['short'] ?? $window['label'],
            'range' => $window['range'],
            'from' => $window['from']->toDateString(),
            'to' => $window['to']->toDateString(),
        ];
    }

    // ── Figures ──────────────────────────────────────────────────────────

    public function collectedBetween(CarbonInterface $from, CarbonInterface $to): int
    {
        return FundUsagePresenter::sen($this->club->payments()
            ->where('status', 'paid')
            ->tap(fn ($q) => self::inRange($q, 'paid_date', $from, $to))
            ->sum('amount'));
    }

    /**
     * Gateway fees the club absorbed (sen) on fees paid online in the range:
     * fee_absorbed_sen is set by the verified webhook when it marks a fee paid
     * (0 when the member paid the fee; null for fees settled by hand).
     */
    public function gatewayFeesAbsorbedBetween(CarbonInterface $from, CarbonInterface $to): int
    {
        return (int) $this->chargesQuery()
            ->tap(fn ($q) => self::inRange($q, 'paid_date', $from, $to))
            ->sum('fee_absorbed_sen');
    }

    /**
     * Paid fees carrying a gateway charge the club absorbed (fee_absorbed_sen, already sen).
     * Not filtered on paid_via: an admin who reverts an online-paid fee and then
     * marks it paid by hand clears paid_via, but the gateway still took its charge,
     * so the charge counts again (the edit form warns about this before reverting).
     * A transfer-slip approval resets fee_absorbed_sen to 0, and a new online
     * payment overwrites it.
     */
    private function chargesQuery()
    {
        return $this->club->payments()
            ->where('status', 'paid')
            ->where('fee_absorbed_sen', '>', 0);
    }

    /** Approved Fund Usage spending in the range (no bank charges): both types unless $type ('expense' or 'welfare') is given. */
    public function spentBetween(CarbonInterface $from, CarbonInterface $to, ?string $type = null): int
    {
        return FundUsagePresenter::sen($this->club->expenses()->counted()
            ->when($type, fn ($q) => $q->where('type', $type))
            ->tap(fn ($q) => self::inRange($q, self::cashDate(), $from, $to))
            ->sum('amount'));
    }

    /**
     * Everything spent in the range, split: expenses + welfare + bank / gateway charges.
     * welfareCount is the number of approved welfare records in the range (for
     * admin screens only; member pages must not receive it).
     *
     * @return array{spent: int, expenses: int, welfare: int, charges: int, welfareCount: int}
     */
    public function spentSplitBetween(CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = $this->club->expenses()->counted()
            ->tap(fn ($q) => self::inRange($q, self::cashDate(), $from, $to))
            ->groupBy('type')
            ->selectRaw('type, COUNT(*) as c, COALESCE(SUM(amount), 0) as s')
            ->toBase()
            ->get()
            ->keyBy('type');

        $expenses = FundUsagePresenter::sen($rows['expense']->s ?? 0);
        $welfare = FundUsagePresenter::sen($rows['welfare']->s ?? 0);
        $charges = $this->gatewayFeesAbsorbedBetween($from, $to);

        return [
            'spent' => $expenses + $welfare + $charges,
            'expenses' => $expenses,
            'welfare' => $welfare,
            'charges' => $charges,
            'welfareCount' => (int) ($rows['welfare']->c ?? 0),
        ];
    }

    public function welfareCountBetween(CarbonInterface $from, CarbonInterface $to): int
    {
        return $this->club->expenses()->counted()->where('type', 'welfare')
            ->tap(fn ($q) => self::inRange($q, self::cashDate(), $from, $to))
            ->count();
    }

    /**
     * Fund balance at the end of $asOf: everything collected minus everything
     * spent (approved expenses and welfare, and absorbed bank / gateway
     * charges) dated on or before that day.
     */
    public function balanceAt(CarbonInterface $asOf): int
    {
        $before = $asOf->copy()->addDay()->toDateString();
        $in = FundUsagePresenter::sen($this->club->payments()->where('status', 'paid')
            ->where('paid_date', '<', $before)->sum('amount'));
        $out = FundUsagePresenter::sen($this->club->expenses()->counted()
            ->whereRaw(self::cashDate().' < ?', [$before])->sum('amount'));
        $charges = (int) $this->chargesQuery()->where('paid_date', '<', $before)->sum('fee_absorbed_sen');

        return $in - $out - $charges;
    }

    /** Today's fund balance (baki dana). */
    public function balance(): int
    {
        return $this->balanceAt(now());
    }

    /** What the fund held when $from began: the balance at the end of the day before. */
    public function openingBalance(CarbonInterface $from): int
    {
        return $this->balanceAt($from->copy()->subDay());
    }

    /**
     * Every figure for one window, labelled.
     *
     * @param  array{from: CarbonInterface, to: CarbonInterface, key: string, label: string, range: string}  $window
     * @return array{window: array, collected: int, spent: int, expenses: int, welfare: int, charges: int, welfareCount: int, net: int}
     */
    public function forWindow(array $window): array
    {
        $collected = $this->collectedBetween($window['from'], $window['to']);
        $split = $this->spentSplitBetween($window['from'], $window['to']);

        return [
            'window' => self::windowProps($window),
            'collected' => $collected,
            ...$split,
            'net' => $collected - $split['spent'],
        ];
    }

    /**
     * Records awaiting approval: how many, their total, and how many the
     * given admin could approve.
     *
     * @return array{count: int, sum: int, mine: int}
     */
    public function awaiting(int $userId): array
    {
        $row = $this->club->expenses()->where('status', 'submitted')
            ->selectRaw('COUNT(*) as c, COALESCE(SUM(amount), 0) as s')->first();

        return [
            'count' => (int) ($row->c ?? 0),
            'sum' => FundUsagePresenter::sen($row->s ?? 0),
            'mine' => self::awaitingFor(Expense::query()->where('expenses.club_id', $this->club->id), $userId)->count(),
        ];
    }

    /**
     * Inclusive day range as half-open bounds (>= from, < day after to). Date
     * casts may store "Y-m-d 00:00:00" on SQLite, so "<= to" would drop the last day.
     */
    public static function inRange($q, string $column, CarbonInterface $from, CarbonInterface $to)
    {
        if (str_contains($column, '(')) {
            // A trusted SQL expression such as cashDate(); values stay bound.
            return $q->whereRaw("{$column} >= ?", [$from->toDateString()])
                ->whereRaw("{$column} < ?", [$to->copy()->addDay()->toDateString()]);
        }

        return $q->where($column, '>=', $from->toDateString())
            ->where($column, '<', $to->copy()->addDay()->toDateString());
    }

    /** Narrow $q to submitted records that $userId may approve (not the maker, not the beneficiary). */
    public static function awaitingFor(Builder $q, int $userId): Builder
    {
        return $q->where('status', 'submitted')
            ->where('recorded_by', '!=', $userId)
            ->whereDoesntHave('welfareCase', fn ($w) => $w->where('beneficiary_user_id', $userId));
    }

    /**
     * Collected vs spent (expenses, welfare and bank charges) per month for the window
     * lastMonths($months), oldest first.
     *
     * @return array<int, array{month: string, monthEn: string, year: int, collected: int, spent: int}>
     */
    public function monthly(int $months = 12): array
    {
        // Same window as lastMonths($months): the current month runs to today.
        $today = Carbon::parse(now()->toDateString());
        $buckets = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $m = $today->copy()->startOfMonth()->subMonthsNoOverflow($i);
            $buckets[] = [$m->copy(), $i === 0 ? $today->copy() : $m->copy()->endOfMonth()];
        }

        $in = self::sumByRanges($this->club->payments()->where('status', 'paid'), 'paid_date', $buckets);
        $out = self::sumByRanges($this->club->expenses()->counted(), self::cashDate(), $buckets);
        $charges = self::sumByRanges($this->chargesQuery(), 'paid_date', $buckets, 'fee_absorbed_sen');

        $bm = ['Jan', 'Feb', 'Mac', 'Apr', 'Mei', 'Jun', 'Jul', 'Ogo', 'Sep', 'Okt', 'Nov', 'Dis'];

        return array_map(fn ($b, $i) => [
            'month' => $bm[$b[0]->month - 1],
            'monthEn' => $b[0]->format('M'),
            'year' => $b[0]->year,
            'collected' => $in[$i],
            'spent' => $out[$i] + $charges[$i],
        ], $buckets, array_keys($buckets));
    }

    /**
     * One query: SUM(amount) per date range, as integer sen. Pass a sen
     * column (e.g. fee_absorbed_sen) as $amountColumn to sum it instead.
     *
     * @param  array<int, array{0: Carbon, 1: Carbon}>  $ranges
     * @return array<int, int>
     */
    public static function sumByRanges($query, string $column, array $ranges, ?string $senColumn = null): array
    {
        if ($ranges === []) {
            return [];
        }
        $table = $query->getModel()->getTable();
        // A column name, or a trusted expression such as cashDate().
        $col = str_contains($column, '(') ? $column : $table.'.'.$column;
        $amount = $table.'.'.($senColumn ?? 'amount');
        $parts = [];
        $bindings = [];
        foreach ($ranges as $i => [$from, $to]) {
            $parts[] = "COALESCE(SUM(CASE WHEN {$col} >= ? AND {$col} < ? THEN {$amount} ELSE 0 END), 0) as r{$i}";
            $bindings[] = $from->toDateString();
            $bindings[] = $to->copy()->addDay()->toDateString();
        }
        $row = (clone $query)->selectRaw(implode(', ', $parts), $bindings)->toBase()->first();

        return array_map(fn ($i) => $senColumn
            ? (int) ($row->{"r{$i}"} ?? 0)
            : FundUsagePresenter::sen($row->{"r{$i}"} ?? 0), array_keys($ranges));
    }

    /**
     * Spending in the range by line (largest first): approved expenses by their
     * category, then one "Welfare" line for all approved welfare (a total only:
     * never a case count, which could identify a family in a small club) and one
     * "Bank / gateway charges" line. Welfare and charges are always their own
     * lines, never folded into an expense category or "Others". Each line carries
     * its kind (expense, welfare or charges) and its BM name (nameMs) for member
     * pages when there is one. The amounts add up to spentSplitBetween()['spent'].
     *
     * @return array<int, array{name: string, nameMs?: string, kind: string, amount: int}>
     */
    public function byCategoryBetween(CarbonInterface $from, CarbonInterface $to): array
    {
        $lines = $this->club->expenses()->counted()
            ->where('expenses.type', 'expense')
            ->join('expense_categories', 'expense_categories.id', '=', 'expenses.expense_category_id')
            ->tap(fn ($q) => self::inRange($q, self::cashDate(), $from, $to))
            ->groupBy('expense_categories.name', 'expense_categories.name_ms')
            ->selectRaw('expense_categories.name as name, expense_categories.name_ms as name_ms, SUM(expenses.amount) as total')
            ->get()
            // nameMs: the category's BM name for member pages (they fall back to name when it is not set).
            ->map(fn ($r) => array_filter(['name' => $r->name, 'nameMs' => $r->name_ms, 'kind' => 'expense', 'amount' => FundUsagePresenter::sen($r->total)], fn ($v) => $v !== null));

        if ($welfare = $this->spentBetween($from, $to, 'welfare')) {
            $lines->push(['name' => self::WELFARE_LABEL, 'nameMs' => self::WELFARE_LABEL_MS, 'kind' => 'welfare', 'amount' => $welfare]);
        }
        // Absorbed gateway charges are spending too, so the shares add up to spent.
        if ($charges = $this->gatewayFeesAbsorbedBetween($from, $to)) {
            $lines->push(['name' => self::CHARGES_LABEL, 'nameMs' => self::CHARGES_LABEL_MS, 'kind' => 'charges', 'amount' => $charges]);
        }

        return $lines->sortByDesc('amount')->values()->all();
    }
}
