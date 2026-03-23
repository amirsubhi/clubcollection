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
        // ── KPI Totals ────────────────────────────────────────────────────────
        $totalClubs    = Club::count();
        $activeClubs   = Club::where('is_active', true)->count();
        $totalMembers  = DB::table('club_user')->distinct('user_id')->count('user_id');
        $totalPayments = Payment::count();
        $totalRevenue  = Payment::where('status', 'paid')->sum('amount');
        $paidCount     = Payment::where('status', 'paid')->count();
        $pendingCount  = Payment::where('status', 'pending')->count();
        $overdueCount  = Payment::where('status', 'overdue')->count();
        $totalExpenses = Expense::sum('amount');
        $netBalance    = $totalRevenue - $totalExpenses;

        // ── Monthly Revenue Trend (last 12 months, all clubs) ─────────────────
        $monthlyRevenue = [];
        for ($i = 11; $i >= 0; $i--) {
            $m   = now()->subMonths($i);
            $key = $m->format('Y-m');
            $monthlyRevenue[$m->format('M Y')] = Payment::where('status', 'paid')
                ->whereRaw("strftime('%Y-%m', paid_date) = ?", [$key])
                ->sum('amount');
        }

        // ── Monthly Expense Trend (last 12 months, all clubs) ─────────────────
        $monthlyExpenses = [];
        for ($i = 11; $i >= 0; $i--) {
            $m   = now()->subMonths($i);
            $key = $m->format('Y-m');
            $monthlyExpenses[$m->format('M Y')] = Expense::whereRaw("strftime('%Y-%m', expense_date) = ?", [$key])
                ->sum('amount');
        }

        // ── Per-Club Breakdown ────────────────────────────────────────────────
        $clubStats = Club::withCount('members')->get()->map(function ($club) {
            $club->total_revenue  = $club->payments()->where('status', 'paid')->sum('amount');
            $club->total_expenses = $club->expenses()->sum('amount');
            $club->paid_count     = $club->payments()->where('status', 'paid')->count();
            $club->pending_count  = $club->payments()->where('status', 'pending')->count();
            $club->overdue_count  = $club->payments()->where('status', 'overdue')->count();
            return $club;
        })->sortByDesc('total_revenue')->values();

        // ── Member Distribution by Job Level ─────────────────────────────────
        $jobLevelLabels       = FeeRate::jobLevelLabels();
        $jobLevelDistribution = DB::table('club_user')
            ->select('job_level', DB::raw('COUNT(*) as count'))
            ->groupBy('job_level')
            ->pluck('count', 'job_level');

        // ── Recent Transactions (last 10 paid, across all clubs) ──────────────
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
            'recentTransactions'
        ));
    }
}
