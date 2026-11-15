<?php

namespace App\Support\Admin;

use App\Models\Club;
use App\Models\Expense;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Money figures for the Fund Usage and admin dashboard pages. Sums are done
 * in SQL and returned as integer sen; spending uses only approved (counted)
 * records. Date filters are plain range bounds, so this works on SQLite and
 * MySQL alike (no strftime / DATE_FORMAT).
 *
 * Spending is placed in a period by its cash date: the date the money left
 * (paid_date), falling back to the invoice date (expense_date) when no paid
 * date was recorded. See cashDate(); the ledger, dashboards and statistics
 * use the same rule.
 */
final class FundUsageStats
{
    /** SQL expression for an expense's cash date: COALESCE(paid_date, expense_date). */
    public static function cashDate(string $table = 'expenses'): string
    {
        return "COALESCE({$table}.paid_date, {$table}.expense_date)";
    }

    public function __construct(private Club $club) {}

    public function collectedBetween(CarbonInterface $from, CarbonInterface $to): int
    {
        return FundUsagePresenter::sen($this->club->payments()
            ->where('status', 'paid')
            ->tap(fn ($q) => self::inRange($q, 'paid_date', $from, $to))
            ->sum('amount'));
    }

    public function spentBetween(CarbonInterface $from, CarbonInterface $to, ?string $type = null): int
    {
        return FundUsagePresenter::sen($this->club->expenses()->counted()
            ->when($type, fn ($q) => $q->where('type', $type))
            ->tap(fn ($q) => self::inRange($q, self::cashDate(), $from, $to))
            ->sum('amount'));
    }

    public function welfareCountBetween(CarbonInterface $from, CarbonInterface $to): int
    {
        return $this->club->expenses()->counted()->where('type', 'welfare')
            ->tap(fn ($q) => self::inRange($q, self::cashDate(), $from, $to))
            ->count();
    }

    /** All-time collected minus all-time approved spending. */
    public function balance(): int
    {
        $in = FundUsagePresenter::sen($this->club->payments()->where('status', 'paid')->sum('amount'));
        $out = FundUsagePresenter::sen($this->club->expenses()->counted()->sum('amount'));

        return $in - $out;
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
     * Collected vs approved spending for the last $months months (oldest first).
     *
     * @return array<int, array{month: string, monthEn: string, collected: int, spent: int}>
     */
    public function monthly(int $months = 12): array
    {
        $buckets = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $m = now()->startOfMonth()->subMonthsNoOverflow($i);
            $buckets[] = [$m->copy(), $m->copy()->endOfMonth()];
        }

        $in = self::sumByRanges($this->club->payments()->where('status', 'paid'), 'paid_date', $buckets);
        $out = self::sumByRanges($this->club->expenses()->counted(), self::cashDate(), $buckets);

        $bm = ['Jan', 'Feb', 'Mac', 'Apr', 'Mei', 'Jun', 'Jul', 'Ogo', 'Sep', 'Okt', 'Nov', 'Dis'];

        return array_map(fn ($b, $i) => [
            'month' => $bm[$b[0]->month - 1],
            'monthEn' => $b[0]->format('M'),
            'collected' => $in[$i],
            'spent' => $out[$i],
        ], $buckets, array_keys($buckets));
    }

    /**
     * One query: SUM(amount) per date range, as integer sen.
     *
     * @param  array<int, array{0: Carbon, 1: Carbon}>  $ranges
     * @return array<int, int>
     */
    public static function sumByRanges($query, string $column, array $ranges): array
    {
        if ($ranges === []) {
            return [];
        }
        $table = $query->getModel()->getTable();
        // A column name, or a trusted expression such as cashDate().
        $col = str_contains($column, '(') ? $column : $table.'.'.$column;
        $amount = $table.'.amount';
        $parts = [];
        $bindings = [];
        foreach ($ranges as $i => [$from, $to]) {
            $parts[] = "COALESCE(SUM(CASE WHEN {$col} >= ? AND {$col} < ? THEN {$amount} ELSE 0 END), 0) as r{$i}";
            $bindings[] = $from->toDateString();
            $bindings[] = $to->copy()->addDay()->toDateString();
        }
        $row = (clone $query)->selectRaw(implode(', ', $parts), $bindings)->toBase()->first();

        return array_map(fn ($i) => FundUsagePresenter::sen($row->{"r{$i}"} ?? 0), array_keys($ranges));
    }

    /**
     * Approved spending this year by category (largest first).
     *
     * @return array<int, array{name: string, amount: int}>
     */
    public function byCategoryBetween(CarbonInterface $from, CarbonInterface $to): array
    {
        return $this->club->expenses()->counted()
            ->join('expense_categories', 'expense_categories.id', '=', 'expenses.expense_category_id')
            ->tap(fn ($q) => self::inRange($q, self::cashDate(), $from, $to))
            ->groupBy('expense_categories.name')
            ->selectRaw('expense_categories.name as name, SUM(expenses.amount) as total')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($r) => ['name' => $r->name, 'amount' => FundUsagePresenter::sen($r->total)])
            ->all();
    }
}
