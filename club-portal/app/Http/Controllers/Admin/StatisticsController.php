<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Club;
use App\Models\Expense;
use App\Models\FeeRate;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

class StatisticsController extends Controller
{
    public function index()
    {
        // ── Club counts — 1 query instead of 2 ───────────────────────────────
        $clubCounts  = Club::selectRaw("COUNT(*) as total, SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_count")->first();
        $totalClubs  = (int) ($clubCounts->total ?? 0);
        $activeClubs = (int) ($clubCounts->active_count ?? 0);

        $totalMembers = DB::table('club_user')->distinct('user_id')->count('user_id');

        // ── Payment KPIs — 1 query instead of 5 ──────────────────────────────
        $paymentStats = Payment::selectRaw("
            COUNT(*) as total,
            SUM(CASE WHEN status = 'paid'    THEN 1 ELSE 0 END) as paid_count,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count,
            SUM(CASE WHEN status = 'overdue' THEN 1 ELSE 0 END) as overdue_count,
            SUM(CASE WHEN status = 'paid'    THEN amount ELSE 0 END) as total_revenue
        ")->first();

        $totalPayments = (int)   ($paymentStats->total ?? 0);
        $paidCount     = (int)   ($paymentStats->paid_count ?? 0);
        $pendingCount  = (int)   ($paymentStats->pending_count ?? 0);
        $overdueCount  = (int)   ($paymentStats->overdue_count ?? 0);
        $totalRevenue  = (float) ($paymentStats->total_revenue ?? 0);
        $totalExpenses = (float) Expense::sum('amount');
        $netBalance    = $totalRevenue - $totalExpenses;

        // ── Monthly Trends — 2 queries instead of 24 ─────────────────────────
        $cutoff = now()->subMonths(11)->startOfMonth()->toDateString();

        $revenueByMonth = Payment::where('status', 'paid')
            ->where('paid_date', '>=', $cutoff)
            ->selectRaw("strftime('%Y-%m', paid_date) as month, SUM(amount) as total")
            ->groupBy('month')
            ->pluck('total', 'month');

        $expensesByMonth = Expense::where('expense_date', '>=', $cutoff)
            ->selectRaw("strftime('%Y-%m', expense_date) as month, SUM(amount) as total")
            ->groupBy('month')
            ->pluck('total', 'month');

        $monthlyRevenue  = [];
        $monthlyExpenses = [];
        for ($i = 11; $i >= 0; $i--) {
            $m     = now()->subMonths($i);
            $key   = $m->format('Y-m');
            $label = $m->format('M Y');
            $monthlyRevenue[$label]  = (float) ($revenueByMonth[$key] ?? 0);
            $monthlyExpenses[$label] = (float) ($expensesByMonth[$key] ?? 0);
        }

        // ── Per-Club Breakdown — 1 query instead of 5N+1 ─────────────────────
        // withCount/withSum injects subqueries into a single SELECT — no N+1.
        $clubStats = Club::withCount('members')
            ->withCount(['payments as paid_count'    => fn($q) => $q->where('status', 'paid')])
            ->withCount(['payments as pending_count' => fn($q) => $q->where('status', 'pending')])
            ->withCount(['payments as overdue_count' => fn($q) => $q->where('status', 'overdue')])
            ->withSum(['payments as total_revenue'   => fn($q) => $q->where('status', 'paid')], 'amount')
            ->withSum('expenses as total_expenses', 'amount')
            ->get()
            ->each(function ($club) {
                // Cast nulls (clubs with no payments/expenses) to numeric types
                $club->total_revenue  = (float) ($club->total_revenue ?? 0);
                $club->total_expenses = (float) ($club->total_expenses ?? 0);
            })
            ->sortByDesc('total_revenue')
            ->values();

        // ── Job Level Distribution ────────────────────────────────────────────
        $jobLevelLabels  = FeeRate::jobLevelLabels();
        $rawJobLevels    = DB::table('club_user')
            ->select('job_level', DB::raw('COUNT(*) as count'))
            ->groupBy('job_level')
            ->pluck('count', 'job_level');

        // Pre-build ordered arrays matching jobLevelLabels (consistent chart order,
        // avoids array_map logic in the Blade template).
        $jobLevelDistribution = collect($jobLevelLabels)
            ->mapWithKeys(fn($label, $key) => [$key => (int) ($rawJobLevels[$key] ?? 0)]);

        $jobLevelChartLabels = array_values($jobLevelLabels);
        $jobLevelChartCounts = $jobLevelDistribution->values()->all();

        // ── Recent Transactions ───────────────────────────────────────────────
        $recentTransactions = Payment::with(['user', 'club'])
            ->where('status', 'paid')
            ->latest('paid_date')
            ->limit(10)
            ->get();

        return view('admin.statistics.index', compact(
            'totalClubs', 'activeClubs', 'totalMembers', 'totalPayments',
            'totalRevenue', 'paidCount', 'pendingCount', 'overdueCount',
            'totalExpenses', 'netBalance',
            'monthlyRevenue', 'monthlyExpenses',
            'clubStats', 'jobLevelLabels', 'jobLevelDistribution',
            'jobLevelChartLabels', 'jobLevelChartCounts',
            'recentTransactions'
        ));
    }
}
