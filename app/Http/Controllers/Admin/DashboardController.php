<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Club;
use App\Models\Expense;
use App\Support\Admin\AdminNav;
use App\Support\Admin\FundUsagePresenter as P;
use App\Support\Admin\FundFigures as Figures;
use App\Support\Payments\DisplayStatus;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Club dashboard. index() is the Inertia landing page (Fund Usage KPIs,
 * collected vs spent, approvals queue); classic() keeps the earlier Blade
 * report with its month picker and member lists. Both count approved
 * spending only (Expense::counted()) and use plain date-range bounds.
 */
class DashboardController extends Controller
{
    /** Below RM 500 last year, no "% vs last year" is shown (see pct()). */
    public const MIN_DELTA_BASE_SEN = 50_000;

    public function index(Request $request, Club $club): Response
    {
        $me = (int) $request->user()->id;
        $figures = new Figures($club);
        // Every figure on the page comes from FundFigures with its window.
        $ytd = Figures::yearToDate(null, $club);
        $earlier = Figures::yearEarlier($ytd);
        $now = $figures->forWindow($ytd);
        $before = $figures->forWindow($earlier);
        $awaiting = $figures->awaiting($me);
        $chartWindow = Figures::lastMonths(12);

        $queue = Expense::query()->where('club_id', $club->id)->where('status', 'submitted')
            ->with(['category:id,name', 'recordedBy:id,name', 'approver:id,name', 'welfareCase:id,expense_id,beneficiary_user_id'])
            ->withCount('attachments')
            ->orderBy('submitted_at')
            ->limit(6)
            ->get()
            ->map(fn (Expense $e) => P::row($e, $me) + [
                'canApprove' => (int) $e->recorded_by !== $me && (int) ($e->welfareCase?->beneficiary_user_id ?? 0) !== $me,
            ])
            ->values();

        // Transfer slips waiting for an admin: how many and the oldest five. A "Bayar semua"
        // slip covering several fees is one row (as on the Verifications page), with its total.
        $sets = TransferVerificationController::slipSets(
            $club->payments()->where('status', 'pending_verification')
                ->with('user:id,name,email')->orderBy('transfer_submitted_at')->orderBy('id')->get()
        )->sortBy(fn (array $set) => [$set['lead']->transfer_submitted_at?->getTimestamp() ?? 0, $set['lead']->id])->values();
        $slips = [
            'count' => $sets->count(),
            'items' => $sets->take(5)->map(fn (array $set) => [
                'feeCount' => $set['fees']->count(),
                'amount' => $set['fees']->sum(fn ($p) => $p->amountSen()),
            ] + TransferVerificationController::card($set['lead'], false))->values(),
            'transferReady' => $club->transferReady(),
        ];

        return Inertia::render('Admin/Dashboard', [
            'nav' => AdminNav::props($club, $request->user(), $awaiting['mine']),
            'greetingName' => $request->user()->name,
            'year' => now()->year,
            // "2026", or "FY 2026/27" when the club's financial year starts in another month.
            'yearLabel' => Figures::yearLabel(null, $club),
            'kpis' => [
                // Today's baki dana: all collected minus all approved spending to date.
                'balance' => $figures->balance(),
                'balanceAsOf' => now()->toDateString(),
                'window' => $now['window'],
                'collectedYtd' => $now['collected'],
                // Spent always includes welfare; the split is shown beside it.
                'spentYtd' => $now['spent'],
                'expensesYtd' => $now['expenses'],
                'welfareYtd' => $now['welfare'],
                // Gateway fees the club absorbed: bank charges, part of spent.
                'chargesYtd' => $now['charges'],
                'welfareCountYtd' => $now['welfareCount'],
                'collectedDeltaPct' => $this->pct($now['collected'], $before['collected']),
                'spentDeltaPct' => $this->pct($now['spent'], $before['spent']),
                'awaiting' => $awaiting,
                // Who bears the gateway fee; absorbed fees are counted in spent
                // (chargesYtd). When members pay it the page says "dibayar ahli".
                'gatewayFees' => [
                    'absorbedYtd' => $now['charges'],
                    'payer' => $club->gatewayFeePayer(),
                    'perBill' => $club->gatewayFeeSen(),
                ],
            ],
            'monthly' => $figures->monthly(12),
            'monthlyWindow' => Figures::windowProps($chartWindow),
            'categories' => $figures->byCategoryBetween($ytd['from'], $ytd['to']),
            'queue' => $queue,
            'slips' => $slips,
            'activity' => $this->recentActivity($club),
            'urls' => [
                'fundUsage' => route('admin.clubs.fund-usage.index', $club),
                'awaitingMine' => route('admin.clubs.fund-usage.index', [$club, 'tab' => 'mine']),
                'createExpense' => route('admin.clubs.fund-usage.create', $club),
                'classic' => route('admin.clubs.dashboard.classic', $club),
                'ledger' => route('admin.clubs.ledger', $club),
                'auditLog' => route('admin.clubs.audit-logs', $club),
                'billing' => route('admin.clubs.billing.edit', $club),
                'verifications' => route('admin.clubs.verifications.index', $club),
            ],
        ]);
    }

    /**
     * Change against the same days last year, or null when last year is too
     * small to compare with: "+2,400%" on a RM 20 base alarms more than it tells.
     */
    private function pct(int $now, int $before): ?float
    {
        return $before >= self::MIN_DELTA_BASE_SEN ? round(($now - $before) / $before * 100, 1) : null;
    }

    /**
     * Latest money events: paid fees and Fund Usage records (any status, so
     * admins see what is not counted yet). No welfare personal data.
     *
     * @return array<int, array<string, mixed>>
     */
    private function recentActivity(Club $club): array
    {
        $fees = $club->payments()->with('user:id,name')->where('status', 'paid')
            ->orderByDesc('paid_date')->orderByDesc('id')->limit(5)->get()
            ->map(fn ($p) => [
                'id' => 'p'.$p->id,
                'kind' => 'fee',
                'title' => 'Fee paid · '.($p->user?->name ?? 'Member'),
                'who' => $p->gateway ? ucfirst($p->gateway) : ($p->paid_via === 'transfer' ? 'Bank transfer (slip checked)' : 'Recorded by admin'),
                'at' => ($p->paid_date ?? $p->updated_at)->format('Y-m-d'),
                'sort' => ($p->paid_date ?? $p->updated_at)->format('Y-m-d').' '.$p->updated_at->format('H:i:s'),
                'amount' => P::sen($p->amount),
                'direction' => 'in',
                'pending' => false,
                'voidPending' => false,
                'url' => null,
            ]);

        $funds = $club->expenses()->with(['category:id,name', 'recordedBy:id,name'])
            ->where('status', '!=', 'void')
            ->latest('updated_at')->limit(5)->get()
            ->map(fn (Expense $e) => [
                'id' => 'e'.$e->id,
                'kind' => $e->status === 'approved' ? 'approved' : ($e->isWelfare() ? 'welfare' : 'expense'),
                'title' => $e->reference().' · '.($e->isWelfare() ? 'Welfare' : ($e->payee ?: $e->category?->name)),
                'who' => $e->recordedBy?->name ?? 'Unknown',
                'at' => $e->updated_at->format('Y-m-d\TH:i'),
                'sort' => $e->updated_at->format('Y-m-d H:i:s'),
                'amount' => P::sen($e->amount),
                'direction' => 'out',
                'pending' => $e->status !== 'approved',
                'voidPending' => $e->isVoidPending(),
                'url' => route('admin.fund-usage.show', $e),
            ]);

        return $fees->concat($funds)->sortByDesc('sort')->take(6)
            ->map(fn ($a) => collect($a)->except('sort')->all())->values()->all();
    }

    public function classic(Club $club)
    {
        $year  = (int) request('year', now()->year);
        $month = (int) request('month', now()->month);

        // Clamp to valid ranges
        if ($year < 2000 || $year > now()->year + 1) {
            $year = now()->year;
        }
        if ($month < 1 || $month > 12) {
            $month = now()->month;
        }

        // Date-range bounds (portable; no strftime). Spending counts approved records only.
        $monthStart = Carbon::create($year, $month, 1)->startOfMonth();
        $monthEnd   = $monthStart->copy()->endOfMonth();
        $yearStart  = Carbon::create($year, 1, 1)->startOfYear();
        $yearEnd    = $yearStart->copy()->endOfYear();
        $periods    = [[$monthStart, $monthEnd], [$yearStart, $yearEnd]];

        // ── Income KPIs — 1 query ────────────────────────────────────────────
        $paid = $club->payments()->where('status', 'paid');
        [$inMonth, $inYear] = Figures::sumByRanges($paid, 'paid_date', $periods);
        $incomeThisMonth = $inMonth / 100;
        $incomeThisYear  = $inYear / 100;
        $incomeAllTime   = P::sen((clone $paid)->sum('amount')) / 100;

        // ── Expense KPIs (approved only) — 1 query ───────────────────────────
        $counted = $club->expenses()->counted();
        [$outMonth, $outYear] = Figures::sumByRanges($counted, Figures::cashDate(), $periods);
        $expenseThisMonth = $outMonth / 100;
        $expenseThisYear  = $outYear / 100;
        $expenseAllTime   = P::sen((clone $counted)->sum('amount')) / 100;

        // Fund balance as defined once in FundFigures (to date, approved spending of both types).
        $balance = (new Figures($club))->balance() / 100;

        // ── Monthly trend charts — 2 queries ─────────────────────────────────
        $monthlyIncome  = [];
        $monthlyExpense = [];
        foreach ((new Figures($club))->monthly(12) as $i => $row) {
            $label = now()->startOfMonth()->subMonthsNoOverflow(11 - $i)->format('M Y');
            $monthlyIncome[$label]  = $row['collected'] / 100;
            $monthlyExpense[$label] = $row['spent'] / 100;
        }

        // ── Payment status counts: the same words as Payments and the Ledger ──
        $statusCounts = DisplayStatus::counts($club->payments()->getQuery());
        $paidCount    = $statusCounts[DisplayStatus::PAID];
        $pendingCount = $statusCounts[DisplayStatus::DUE];
        $overdueCount = $statusCounts[DisplayStatus::OVERDUE];
        $processingCount = $statusCounts[DisplayStatus::PROCESSING];
        $verifyingCount = $statusCounts[DisplayStatus::VERIFYING];

        // ── Members paid vs unpaid this month ─────────────────────────────────
        // Two targeted queries instead of loading all members then filtering in PHP
        $paidMemberIds = Figures::inRange($club->payments()->where('status', 'paid'), 'paid_date', $monthStart, $monthEnd)
            ->pluck('user_id');

        $paidMembers   = $club->members()->wherePivot('is_active', true)->whereIn('users.id', $paidMemberIds)->get();
        $unpaidMembers = $club->members()->wherePivot('is_active', true)->whereNotIn('users.id', $paidMemberIds)->get();

        // ── Income by job level — 1 JOIN query instead of 5 whereHas queries ──
        // Every level of the club (inactive ones too: members may still be on them).
        $jobLevels          = $club->jobLevelNames();
        $incomeByJobLevelRaw = $club->payments()
            ->where('payments.status', 'paid')
            ->tap(fn ($q) => Figures::inRange($q, 'payments.paid_date', $monthStart, $monthEnd))
            ->join('club_user', function ($join) use ($club) {
                $join->on('payments.user_id', '=', 'club_user.user_id')
                     ->where('club_user.club_id', '=', $club->id);
            })
            ->selectRaw("club_user.job_level, SUM(payments.amount) as total")
            ->groupBy('club_user.job_level')
            ->pluck('total', 'job_level');

        $incomeByJobLevel = [];
        foreach (array_keys($jobLevels) as $level) {
            $incomeByJobLevel[$level] = (float) ($incomeByJobLevelRaw[$level] ?? 0);
        }
        // A code with income but no level row (shouldn't happen) still shows, under its code.
        foreach ($incomeByJobLevelRaw as $level => $total) {
            if (! isset($jobLevels[$level])) {
                $jobLevels[$level] = (string) $level;
                $incomeByJobLevel[$level] = (float) $total;
            }
        }

        // ── Expenses by category ──────────────────────────────────────────────
        $expenseByCategory = $club->expenseCategories()
            ->withSum(['expenses as total' => fn($q) => Figures::inRange($q->counted(), Figures::cashDate(), $monthStart, $monthEnd)], 'amount')
            ->get();

        // ── Recent transactions ───────────────────────────────────────────────
        $recentPayments = $club->payments()->with('user')->where('status', 'paid')
            ->latest('paid_date')->limit(5)->get();
        $recentExpenses = $club->expenses()->counted()->with('category')
            ->latest('expense_date')->limit(5)->get();

        $availableYears = range(now()->year, max(now()->year - 4, 2020));

        return view('admin.dashboard.index', compact(
            'club',
            'year', 'month',
            'incomeThisMonth', 'incomeThisYear', 'incomeAllTime',
            'expenseThisMonth', 'expenseThisYear', 'expenseAllTime',
            'balance',
            'monthlyIncome', 'monthlyExpense',
            'paidCount', 'pendingCount', 'overdueCount', 'processingCount', 'verifyingCount',
            'paidMembers', 'unpaidMembers',
            'jobLevels', 'incomeByJobLevel',
            'expenseByCategory',
            'recentPayments', 'recentExpenses',
            'availableYears'
        ));
    }
}
