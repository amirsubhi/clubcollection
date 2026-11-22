<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Club;
use App\Support\Admin\AdminNav;
use App\Support\Admin\FundFigures;
use App\Support\Payments\DisplayStatus;
use App\Support\Export\CsvSafe;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class LedgerController extends Controller
{
    public function index(Request $request, Club $club): Response
    {
        $this->authorizeClub($club);
        $filters = $this->parseFilters($club);
        $data = $this->buildLedgerData($club, $filters);
        $figures = new FundFigures($club);
        $today = Carbon::parse(now()->toDateString());

        $presets = [
            FundFigures::yearToDate($today, $club),
            FundFigures::lastMonths(12, $today),
        ];
        $preset = 'custom';
        foreach ($presets as $w) {
            if ($w['from']->isSameDay($filters['from']) && $w['to']->isSameDay($filters['to'])) {
                $preset = $w['key'];
            }
        }

        $exportQuery = array_filter([
            'from' => $filters['from']->toDateString(),
            'to' => $filters['to']->toDateString(),
            'opening_balance' => $filters['openingSource'] === 'manual' ? number_format($filters['openingSen'] / 100, 2, '.', '') : null,
            'opening_for' => $filters['openingSource'] === 'manual' ? $filters['from']->toDateString() : null,
        ], fn ($v) => $v !== null);

        $outstanding = fn ($payments, string $status) => $payments->map(fn ($p) => [
            'id' => $p->id,
            'member' => $p->user?->name ?? 'Former member',
            'periodStart' => $p->period_start->toDateString(),
            'dueDate' => $p->due_date?->toDateString(),
            'amount' => self::sen($p->amount),
            'status' => $status,
        ]);

        $transactions = collect($data['transactions']);

        return Inertia::render('Admin/Ledger/Index', [
            'nav' => AdminNav::props($club, $request->user(), $figures->awaiting((int) $request->user()->id)['mine']),
            'clubName' => $club->name,
            'filters' => [
                'from' => $filters['from']->toDateString(),
                'to' => $filters['to']->toDateString(),
                'preset' => $preset,
                'openingBalance' => $filters['openingSource'] === 'manual' ? number_format($filters['openingSen'] / 100, 2, '.', '') : '',
            ],
            'presets' => array_map(fn ($w) => FundFigures::windowProps($w), $presets),
            'maxMonths' => self::MAX_RANGE_MONTHS,
            'today' => $today->toDateString(),
            'range' => FundFigures::rangeText($filters['from'], $filters['to']),
            'opening' => [
                'amount' => $filters['openingSen'],
                'source' => $filters['openingSource'],
                // What the records say, so a manual figure can be compared with it.
                'computed' => $filters['computedOpeningSen'],
                'asOf' => $filters['from']->copy()->subDay()->toDateString(),
            ],
            'summary' => [
                'opening' => $filters['openingSen'],
                'in' => $data['incomeSen'],
                'out' => $data['expenseSen'],
                'outExpenses' => $data['expenseOnlySen'],
                'outWelfare' => $data['welfareSen'],
                'outCharges' => $data['chargesSen'],
                'net' => $data['incomeSen'] - $data['expenseSen'],
                'closing' => $data['closingSen'],
                'inCount' => $transactions->where('type', 'Income')->count(),
                'outCount' => $transactions->where('type', 'Expense')->where('kind', '!=', 'charges')->count(),
            ],
            'monthly' => array_map(fn ($m) => [
                'key' => $m['key'],
                'month' => $m['month'],
                'in' => $m['in_sen'],
                'out' => $m['out_sen'],
                'net' => $m['in_sen'] - $m['out_sen'],
                'balance' => $m['balance_sen'],
            ], $data['monthlySummary']),
            'transactions' => $transactions->values()->map(fn ($t, $i) => [
                'n' => $i + 1,
                'date' => $t['date'],
                'direction' => $t['type'] === 'Income' ? 'in' : 'out',
                'kind' => $t['kind'],
                'description' => $t['description'],
                'party' => $t['party'],
                'reference' => $t['reference'],
                'receiptNo' => ($t['receipt'] ?? '') ?: null,
                'amount' => $t['in_sen'] ?: $t['out_sen'],
                'balance' => $t['balance_sen'],
            ])->all(),
            'outstanding' => [
                'rows' => $outstanding($data['overduePayments'], DisplayStatus::OVERDUE)
                    ->concat($outstanding($data['pendingPayments'], DisplayStatus::DUE))
                    ->concat($outstanding($data['processingPayments'], DisplayStatus::PROCESSING))
                    ->concat($outstanding($data['verifyingPayments'], DisplayStatus::VERIFYING))
                    ->values()->all(),
                'overdue' => self::sen($data['overdueTotal']),
                'due' => self::sen($data['pendingTotal']),
                'processing' => self::sen($data['processingTotal']),
                'graceDays' => $club->overdueGraceDays(),
                'verifying' => self::sen($data['verifyingTotal']),
                'total' => self::sen($data['outstandingTotal']),
            ],
            'urls' => [
                'index' => route('admin.clubs.ledger', $club),
                'csv' => route('admin.clubs.ledger.export', $club).'?'.http_build_query($exportQuery),
                'pdf' => route('admin.clubs.ledger.export-pdf', $club).'?'.http_build_query($exportQuery),
                'dashboard' => route('admin.clubs.dashboard', $club),
            ],
        ]);
    }

    public function export(Club $club)
    {
        $this->authorizeClub($club);
        $filters     = $this->parseFilters($club);
        $data        = $this->buildLedgerData($club, $filters);
        $generatedAt = now()->format('d M Y H:i');
        $generatedBy = auth()->user()->name;

        $headers = [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => CsvSafe::disposition($this->fileBase($club, $filters), 'csv'),
            'Cache-Control'       => 'no-store, no-cache',
        ];

        $callback = function () use ($club, $filters, $data, $generatedAt, $generatedBy) {
            $h = fopen('php://output', 'w');
            fputs($h, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel

            // ── Report header ─────────────────────────────────────────────
            CsvSafe::put($h, ['CLUB FINANCIAL LEDGER REPORT']);
            CsvSafe::put($h, ['Club', $club->name]);
            CsvSafe::put($h, ['Period', $filters['from']->format('d M Y') . ' — ' . $filters['to']->format('d M Y')]);
            CsvSafe::put($h, ['Generated', $generatedAt]);
            CsvSafe::put($h, ['Generated By', $generatedBy]);
            CsvSafe::put($h, ['Opening Balance (RM)', number_format($filters['openingBalance'], 2)]);
            CsvSafe::put($h, ['Opening Balance Source', $filters['openingSource'] === 'manual'
                ? 'Set manually'
                : 'Fund balance at the end of '.$filters['from']->copy()->subDay()->format('d M Y').', from the records']);
            CsvSafe::put($h, []);

            // ── Summary ───────────────────────────────────────────────────
            CsvSafe::put($h, ['SUMMARY']);
            CsvSafe::put($h, ['Total Income (RM)',   number_format($data['totalIncome'], 2)]);
            CsvSafe::put($h, ['Total Expenses (RM)', number_format($data['totalExpenses'], 2)]);
            CsvSafe::put($h, ['  of which Expenses (RM)', number_format($data['expenseOnlySen'] / 100, 2)]);
            CsvSafe::put($h, ['  of which Welfare (RM)', number_format($data['welfareSen'] / 100, 2)]);
            CsvSafe::put($h, ['  of which Bank / gateway charges (RM)', number_format($data['chargesSen'] / 100, 2)]);
            CsvSafe::put($h, ['Net Movement (RM)',   number_format($data['totalIncome'] - $data['totalExpenses'], 2)]);
            CsvSafe::put($h, ['Closing Balance (RM)', number_format($data['closingBalance'], 2)]);
            CsvSafe::put($h, []);

            // ── Monthly breakdown ─────────────────────────────────────────
            CsvSafe::put($h, ['MONTHLY BREAKDOWN']);
            CsvSafe::put($h, ['Month', 'Income (RM)', 'Expenses (RM)', 'Net (RM)', 'Running Balance (RM)']);
            foreach ($data['monthlySummary'] as $row) {
                CsvSafe::put($h, [
                    $row['month'],
                    number_format($row['income'], 2),
                    number_format($row['expenses'], 2),
                    number_format($row['net'], 2),
                    number_format($row['balance'], 2),
                ]);
            }
            CsvSafe::put($h, []);

            // ── Transactions ──────────────────────────────────────────────
            CsvSafe::put($h, ['TRANSACTIONS']);
            CsvSafe::put($h, ['#', 'Date', 'Type', 'Description', 'Member / Category', 'Reference', 'Receipt No.', 'Amount In (RM)', 'Amount Out (RM)', 'Running Balance (RM)']);
            foreach ($data['transactions'] as $i => $t) {
                CsvSafe::put($h, [
                    $i + 1,
                    $t['date'],
                    $t['type'],
                    $t['description'],
                    $t['party'],
                    $t['reference'],
                    $t['receipt'] ?? '',
                    $t['amount_in']  !== null ? number_format($t['amount_in'],  2) : '',
                    $t['amount_out'] !== null ? number_format($t['amount_out'], 2) : '',
                    number_format($t['balance'], 2),
                ]);
            }
            CsvSafe::put($h, []);

            // ── Outstanding ───────────────────────────────────────────────
            $hasOutstanding = $data['overduePayments']->isNotEmpty() || $data['pendingPayments']->isNotEmpty() || $data['processingPayments']->isNotEmpty() || $data['verifyingPayments']->isNotEmpty();
            if ($hasOutstanding) {
                CsvSafe::put($h, ['OUTSTANDING PAYMENTS (all time)']);

                if ($data['overduePayments']->isNotEmpty()) {
                    CsvSafe::put($h, ['OVERDUE']);
                    CsvSafe::put($h, ['#', 'Member', 'Period', 'Due Date', 'Amount (RM)']);
                    foreach ($data['overduePayments'] as $i => $p) {
                        CsvSafe::put($h, [$i + 1, $p->user?->name ?? 'Former member', $p->period_start->format('M Y'), $p->due_date->format('d M Y'), number_format($p->amount, 2)]);
                    }
                    CsvSafe::put($h, ['', '', '', 'TOTAL OVERDUE (RM)', number_format($data['overdueTotal'], 2)]);
                    CsvSafe::put($h, []);
                }

                if ($data['pendingPayments']->isNotEmpty()) {
                    CsvSafe::put($h, ['DUE']);
                    CsvSafe::put($h, ['#', 'Member', 'Period', 'Due Date', 'Amount (RM)']);
                    foreach ($data['pendingPayments'] as $i => $p) {
                        CsvSafe::put($h, [$i + 1, $p->user?->name ?? 'Former member', $p->period_start->format('M Y'), $p->due_date->format('d M Y'), number_format($p->amount, 2)]);
                    }
                    CsvSafe::put($h, ['', '', '', 'TOTAL DUE (RM)', number_format($data['pendingTotal'], 2)]);
                    CsvSafe::put($h, []);
                }

                if ($data['processingPayments']->isNotEmpty()) {
                    CsvSafe::put($h, ['FPX IN PROGRESS (online payment started, bank not confirmed yet)']);
                    CsvSafe::put($h, ['#', 'Member', 'Period', 'Due Date', 'Amount (RM)']);
                    foreach ($data['processingPayments'] as $i => $p) {
                        CsvSafe::put($h, [$i + 1, $p->user?->name ?? 'Former member', $p->period_start->format('M Y'), $p->due_date->format('d M Y'), number_format($p->amount, 2)]);
                    }
                    CsvSafe::put($h, ['', '', '', 'TOTAL FPX IN PROGRESS (RM)', number_format($data['processingTotal'], 2)]);
                    CsvSafe::put($h, []);
                }

                if ($data['verifyingPayments']->isNotEmpty()) {
                    CsvSafe::put($h, ['AWAITING VERIFICATION (transfer slip not checked yet)']);
                    CsvSafe::put($h, ['#', 'Member', 'Period', 'Due Date', 'Amount (RM)']);
                    foreach ($data['verifyingPayments'] as $i => $p) {
                        CsvSafe::put($h, [$i + 1, $p->user?->name ?? 'Former member', $p->period_start->format('M Y'), $p->due_date->format('d M Y'), number_format($p->amount, 2)]);
                    }
                    CsvSafe::put($h, ['', '', '', 'TOTAL AWAITING VERIFICATION (RM)', number_format($data['verifyingTotal'], 2)]);
                }
            }

            fclose($h);
        };

        return response()->stream($callback, 200, $headers);
    }

    public function exportPdf(Club $club)
    {
        $this->authorizeClub($club);
        $filters     = $this->parseFilters($club);
        $data        = $this->buildLedgerData($club, $filters);
        $generatedAt = now()->format('d M Y H:i');
        $generatedBy = auth()->user()->name;

        $pdf = Pdf::loadView('admin.ledger.pdf', array_merge(
            ['club' => $club, 'filters' => $filters, 'generatedAt' => $generatedAt, 'generatedBy' => $generatedBy],
            $data
        ))->setPaper('a4', 'landscape');

        return $pdf->download(Str::slug($this->fileBase($club, $filters)).'.pdf');
    }

    /** Download name without extension; slugged by the caller (S1: no header injection via the club name). */
    private function fileBase(Club $club, array $filters): string
    {
        return sprintf('%s-ledger-%s-to-%s', $club->name, $filters['from']->format('Y-m-d'), $filters['to']->format('Y-m-d'));
    }

    /** Integer sen back to ringgit for display. */
    private static function rm(int $sen): float
    {
        return $sen / 100;
    }

    /** Ringgit (decimal string or float) to integer sen. */
    private static function sen(mixed $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    /** 24-month cap prevents loading huge result sets and monthly-loop bloat into memory. */
    private const MAX_RANGE_MONTHS = 24;

    /**
     * From / To (default: this year so far) and the opening balance. The
     * opening balance is the fund balance at the end of the day before From
     * (FundFigures), so the ledger agrees with the dashboard; an admin may
     * still type a figure (e.g. cash held before the club used this system),
     * which is then shown as set manually.
     */
    private function parseFilters(Club $club): array
    {
        // Validate all three inputs before touching them
        request()->validate([
            'from'            => 'nullable|date_format:Y-m-d',
            'to'              => 'nullable|date_format:Y-m-d',
            'opening_balance' => 'nullable|numeric|min:0|max:999999999.99',
            // The From date a manual opening balance was typed for (see below).
            'opening_for'     => 'nullable|date_format:Y-m-d',
        ]);

        $from = request('from')
            ? Carbon::createFromFormat('Y-m-d', request('from'))->startOfDay()
            : FundFigures::financialYearStart(Carbon::now(), $club);

        $to = request('to')
            ? Carbon::createFromFormat('Y-m-d', request('to'))->endOfDay()
            : Carbon::now()->endOfDay();

        if ($from->gt($to)) {
            $from = FundFigures::financialYearStart($to, $club);
        }

        // Reject ranges that would force loading huge result sets into memory.
        // Exactly 24 months (e.g. 1 Jan 2025 to 1 Jan 2027) is still allowed.
        if ($to->gt($from->copy()->addMonthsNoOverflow(self::MAX_RANGE_MONTHS)->endOfDay())) {
            throw ValidationException::withMessages([
                'to' => 'Choose a period of '.self::MAX_RANGE_MONTHS.' months or less: the To date can be '.$from->copy()->addMonthsNoOverflow(self::MAX_RANGE_MONTHS)->format('j M Y').' at the latest.',
            ]);
        }

        $computedOpeningSen = (new FundFigures($club))->openingBalance($from);
        // A typed opening balance belongs to the From date it was typed for:
        // when From moves, it is dropped and the computed balance is used.
        $manual = request()->filled('opening_balance')
            && (! request()->filled('opening_for') || request('opening_for') === $from->toDateString());
        $openingSen = $manual ? self::sen(request('opening_balance')) : $computedOpeningSen;
        $openingSource = $manual ? 'manual' : 'computed';
        $openingBalance = self::rm($openingSen);

        return compact('from', 'to', 'openingBalance', 'openingSen', 'openingSource', 'computedOpeningSen');
    }

    private function buildLedgerData(Club $club, array $filters): array
    {
        ['from' => $from, 'to' => $to, 'openingBalance' => $openingBalance] = $filters;
        // All money is added up in integer sen and turned back into ringgit only
        // for display, so long ledgers never drift by a float rounding sen (S1).
        $openingSen = $filters['openingSen'] ?? self::sen($openingBalance);

        // ── Income: paid payments within the date range ───────────────────
        $payments = $club->payments()
            ->with('user')
            ->where('status', 'paid')
            ->tap(fn ($q) => FundFigures::inRange($q, 'paid_date', $from, $to))
            ->orderBy('paid_date')->orderBy('id')
            ->get();

        // ── Approved (counted) expenses within the date range ─────────────
        // Drafts, items awaiting approval and voided items never reach the ledger.
        $expenses = $club->expenses()
            ->counted()
            ->with('category')
            // Cash date: paid_date, else the invoice date (FundFigures::cashDate).
            ->tap(fn ($q) => FundFigures::inRange($q, FundFigures::cashDate(), $from, $to))
            ->orderByRaw(FundFigures::cashDate())->orderBy('id')
            ->get();

        // ── Merge into chronological rows ─────────────────────────────────
        $rows = collect();

        foreach ($payments as $p) {
            $rows->push([
                'sort_key'   => $p->paid_date->format('Y-m-d') . 'A' . str_pad($p->id, 10, '0', STR_PAD_LEFT),
                'date'       => $p->paid_date->format('Y-m-d'),
                'type'       => 'Income',
                'kind'       => 'fee',
                'description'=> 'Fee payment (' . $p->frequency . ') — ' . $p->period_start->format('M Y'),
                'party'      => $p->user?->name ?? 'Former member',
                'reference'  => $p->reference ?? '',
                // The fee's receipt number (per club and financial year; RCP-{id} for fees paid before).
                'receipt'    => $p->receiptNumber(),
                'amount_in'  => (float) $p->amount,
                'amount_out' => null,
                'in_sen'     => self::sen($p->amount),
                'out_sen'    => 0,
                'balance'    => 0.0,
            ]);
        }

        foreach ($expenses as $e) {
            $cashDate = ($e->paid_date ?? $e->expense_date)->format('Y-m-d');
            $rows->push([
                'sort_key'   => $cashDate . 'B' . str_pad($e->id, 10, '0', STR_PAD_LEFT),
                'date'       => $cashDate,
                'type'       => 'Expense',
                'kind'       => $e->isWelfare() ? 'welfare' : 'expense',
                'description'=> ($e->isWelfare() ? 'Welfare: ' : '') . $e->description,
                'party'      => $e->category->name,
                'reference'  => $e->reference(),
                'receipt'    => '',
                'amount_in'  => null,
                'amount_out' => (float) $e->amount,
                'in_sen'     => 0,
                'out_sen'    => self::sen($e->amount),
                'balance'    => 0.0,
            ]);
        }

        // Bank / gateway charges: the gateway fee the club absorbed on fees paid
        // online (fee_absorbed_sen). One "out" row per month, dated on the last
        // such payment of that month, so the statement stays readable.
        // Same rule as FundFigures::chargesQuery(): paid fees with an absorbed charge.
        $payments->filter(fn ($p) => (int) $p->fee_absorbed_sen > 0)
            ->groupBy(fn ($p) => $p->paid_date->format('Y-m'))
            ->each(function ($group, $month) use ($rows) {
                $last = $group->max(fn ($p) => $p->paid_date->format('Y-m-d'));
                $sen = (int) $group->sum('fee_absorbed_sen');
                $rows->push([
                    'sort_key'   => $last . 'C' . $month,
                    'date'       => $last,
                    'type'       => 'Expense',
                    'kind'       => 'charges',
                    'description'=> 'Bank / gateway charges — ' . Carbon::createFromFormat('Y-m-d', $month.'-01')->format('M Y'),
                    'party'      => $group->count() . ' online payment' . ($group->count() === 1 ? '' : 's'),
                    'reference'  => '',
                    'receipt'    => '',
                    'amount_in'  => null,
                    'amount_out' => self::rm($sen),
                    'in_sen'     => 0,
                    'out_sen'    => $sen,
                    'balance'    => 0.0,
                ]);
            });

        $rows = $rows->sortBy('sort_key')->values();

        // Compute running balance
        $runSen = $openingSen;
        $rows = $rows->map(function (array $row) use (&$runSen) {
            $runSen += $row['in_sen'] - $row['out_sen'];
            $row['balance'] = self::rm($runSen);
            $row['balance_sen'] = $runSen;

            return $row;
        });

        $incomeSen   = (int) $rows->sum('in_sen');
        $expenseSen  = (int) $rows->sum('out_sen');
        $totalIncome   = self::rm($incomeSen);
        $totalExpenses = self::rm($expenseSen);
        $closingSen = $openingSen + $incomeSen - $expenseSen;
        $closingBalance = self::rm($closingSen);
        $welfareSen = (int) $rows->where('kind', 'welfare')->sum('out_sen');
        $chargesSen = (int) $rows->where('kind', 'charges')->sum('out_sen');
        $expenseOnlySen = $expenseSen - $welfareSen - $chargesSen;

        // ── Monthly breakdown ─────────────────────────────────────────────
        $monthlySummary   = [];
        $current          = $from->copy()->startOfMonth();
        $endMonth         = $to->copy()->startOfMonth();
        $monthRunSen      = $openingSen;

        while ($current->lte($endMonth)) {
            $key      = $current->format('Y-m');
            $inMonth  = $rows->filter(fn ($r) => str_starts_with($r['date'], $key));
            $monthInc = (int) $inMonth->sum('in_sen');
            $monthExp = (int) $inMonth->sum('out_sen');
            $monthRunSen += $monthInc - $monthExp;

            $monthlySummary[] = [
                'key'      => $key,
                'month'    => $current->format('M Y'),
                'in_sen'   => $monthInc,
                'out_sen'  => $monthExp,
                'balance_sen' => $monthRunSen,
                'income'   => self::rm($monthInc),
                'expenses' => self::rm($monthExp),
                'net'      => self::rm($monthInc - $monthExp),
                'balance'  => self::rm($monthRunSen),
            ];
            $current->addMonth();
        }

        // ── Outstanding (all-time, not date-filtered) ─────────────────────
        // Bucketed by DisplayStatus, the words members and Payments use: due
        // (within the grace period), overdue, FPX in progress, slip being checked.
        $open = $club->payments()->with('user')
            ->whereIn('status', ['pending', 'overdue', 'pending_verification'])
            ->orderBy('due_date')->orderBy('id')->get();
        $statuses = DisplayStatus::many($open);
        $bucket = fn (string $status) => $open->filter(fn ($p) => $statuses[$p->id] === $status)->values();
        $overduePayments = $bucket(DisplayStatus::OVERDUE);
        $pendingPayments = $bucket(DisplayStatus::DUE);
        $processingPayments = $bucket(DisplayStatus::PROCESSING);
        // Transfer slip uploaded, not checked yet: still outstanding until approved.
        $verifyingPayments = $bucket(DisplayStatus::VERIFYING);

        $transactions = $rows->all();

        $totalOf = fn ($payments) => self::rm((int) $payments->sum(fn ($p) => self::sen($p->amount)));
        $overdueTotal = $totalOf($overduePayments);
        $pendingTotal = $totalOf($pendingPayments);
        $processingTotal = $totalOf($processingPayments);
        $verifyingTotal = $totalOf($verifyingPayments);
        $outstandingTotal = self::rm((int) $open->sum(fn ($p) => self::sen($p->amount)));

        return compact(
            'incomeSen',
            'expenseSen',
            'expenseOnlySen',
            'welfareSen',
            'chargesSen',
            'closingSen',
            'transactions',
            'totalIncome',
            'totalExpenses',
            'closingBalance',
            'monthlySummary',
            'pendingPayments',
            'overduePayments',
            'verifyingPayments',
            'processingPayments',
            'processingTotal',
            'overdueTotal',
            'pendingTotal',
            'verifyingTotal',
            'outstandingTotal',
        );
    }

    private function authorizeClub(Club $club): void
    {
        $user = auth()->user();
        if ($user->isSuperAdmin()) {
            return;
        }
        abort_unless(
            $club->is_active && $user->clubs()->wherePivot('role', 'admin')->wherePivot('is_active', true)->where('clubs.id', $club->id)->exists(),
            403
        );
    }
}
