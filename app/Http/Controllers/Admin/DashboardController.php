<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Club;
use App\Models\Expense;
use App\Models\FeeRate;
use App\Support\Admin\AdminNav;
use App\Support\Admin\FundUsagePresenter as P;
use App\Support\Admin\FundUsageStats as Stats;
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
    public function index(Request $request, Club $club): Response
    {
        $me = (int) $request->user()->id;
        $stats = new Stats($club);
        $yearStart = now()->startOfYear();
        $yearEnd = now()->endOfYear();
        $lastYearStart = $yearStart->copy()->subYear();
        $lastYearSameDay = now()->subYear();

        $collected = $stats->collectedBetween($yearStart, $yearEnd);
        $spent = $stats->spentBetween($yearStart, $yearEnd);
        $collectedLast = $stats->collectedBetween($lastYearStart, $lastYearSameDay);
        $spentLast = $stats->spentBetween($lastYearStart, $lastYearSameDay);
        $awaiting = $stats->awaiting($me);

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

        // Transfer slips waiting for an admin: count and the oldest five.
        $slipQuery = $club->payments()->where('status', 'pending_verification');
        $slips = [
            'count' => (clone $slipQuery)->count(),
            'items' => (clone $slipQuery)->with('user:id,name,email')->orderBy('transfer_submitted_at')->orderBy('id')->limit(5)->get()
                ->map(fn ($p) => TransferVerificationController::card($p, false))->values(),
            'transferReady' => $club->transferReady(),
        ];

        return Inertia::render('Admin/Dashboard', [
            'nav' => AdminNav::props($club, $request->user(), $awaiting['mine']),
            'greetingName' => $request->user()->name,
            'year' => now()->year,
            'kpis' => [
                'balance' => $stats->balance(),
                'collectedYtd' => $collected,
                'spentYtd' => $spent,
                'collectedDeltaPct' => $this->pct($collected, $collectedLast),
                'spentDeltaPct' => $this->pct($spent, $spentLast),
                'awaiting' => $awaiting,
                'welfareYtd' => $stats->spentBetween($yearStart, $yearEnd, 'welfare'),
                'welfareCountYtd' => $stats->welfareCountBetween($yearStart, $yearEnd),
                // Fees on online payments this year that the club absorbed; when
                // members pay the fee the page shows "dibayar ahli" instead.
                'gatewayFees' => [
                    'absorbedYtd' => $stats->gatewayFeesAbsorbedBetween($yearStart, $yearEnd),
                    'payer' => $club->gatewayFeePayer(),
                    'perBill' => $club->gatewayFeeSen(),
                ],
            ],
            'monthly' => $stats->monthly(12),
            'categories' => $stats->byCategoryBetween($yearStart, $yearEnd),
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

    /** Percentage change, or null when there is nothing to compare with. */
    private function pct(int $now, int $before): ?float
    {
        return $before > 0 ? round(($now - $before) / $before * 100, 1) : null;
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
        [$inMonth, $inYear] = Stats::sumByRanges($paid, 'paid_date', $periods);
        $incomeThisMonth = $inMonth / 100;
        $incomeThisYear  = $inYear / 100;
        $incomeAllTime   = P::sen((clone $paid)->sum('amount')) / 100;

        // ── Expense KPIs (approved only) — 1 query ───────────────────────────
        $counted = $club->expenses()->counted();
        [$outMonth, $outYear] = Stats::sumByRanges($counted, Stats::cashDate(), $periods);
        $expenseThisMonth = $outMonth / 100;
        $expenseThisYear  = $outYear / 100;
        $expenseAllTime   = P::sen((clone $counted)->sum('amount')) / 100;

        $balance = (P::sen($incomeAllTime) - P::sen($expenseAllTime)) / 100;

        // ── Monthly trend charts — 2 queries ─────────────────────────────────
        $monthlyIncome  = [];
        $monthlyExpense = [];
        foreach ((new Stats($club))->monthly(12) as $i => $row) {
            $label = now()->startOfMonth()->subMonthsNoOverflow(11 - $i)->format('M Y');
            $monthlyIncome[$label]  = $row['collected'] / 100;
            $monthlyExpense[$label] = $row['spent'] / 100;
        }

        // ── Payment status counts — 1 query instead of 3 ─────────────────────
        $statusStats = $club->payments()
            ->selectRaw("
                SUM(CASE WHEN status = 'paid'    THEN 1 ELSE 0 END) as paid_count,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count,
                SUM(CASE WHEN status = 'overdue' THEN 1 ELSE 0 END) as overdue_count,
                SUM(CASE WHEN status = 'pending_verification' THEN 1 ELSE 0 END) as verifying_count
            ")
            ->first();

        $paidCount    = (int) ($statusStats->paid_count    ?? 0);
        $pendingCount = (int) ($statusStats->pending_count ?? 0);
        $overdueCount = (int) ($statusStats->overdue_count ?? 0);
        $verifyingCount = (int) ($statusStats->verifying_count ?? 0);

        // ── Members paid vs unpaid this month ─────────────────────────────────
        // Two targeted queries instead of loading all members then filtering in PHP
        $paidMemberIds = Stats::inRange($club->payments()->where('status', 'paid'), 'paid_date', $monthStart, $monthEnd)
            ->pluck('user_id');

        $paidMembers   = $club->members()->wherePivot('is_active', true)->whereIn('users.id', $paidMemberIds)->get();
        $unpaidMembers = $club->members()->wherePivot('is_active', true)->whereNotIn('users.id', $paidMemberIds)->get();

        // ── Income by job level — 1 JOIN query instead of 5 whereHas queries ──
        $jobLevels          = FeeRate::jobLevelLabels();
        $incomeByJobLevelRaw = $club->payments()
            ->where('payments.status', 'paid')
            ->tap(fn ($q) => Stats::inRange($q, 'payments.paid_date', $monthStart, $monthEnd))
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

        // ── Expenses by category ──────────────────────────────────────────────
        $expenseByCategory = $club->expenseCategories()
            ->withSum(['expenses as total' => fn($q) => Stats::inRange($q->counted(), Stats::cashDate(), $monthStart, $monthEnd)], 'amount')
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
            'paidCount', 'pendingCount', 'overdueCount', 'verifyingCount',
            'paidMembers', 'unpaidMembers',
            'jobLevels', 'incomeByJobLevel',
            'expenseByCategory',
            'recentPayments', 'recentExpenses',
            'availableYears'
        ));
    }
}
