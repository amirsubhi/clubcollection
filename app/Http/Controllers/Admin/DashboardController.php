<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Club;
use App\Models\FeeRate;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Club $club)
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

        $periodKey = sprintf('%04d-%02d', $year, $month);

        // ── Income KPIs — 1 query instead of 3 ───────────────────────────────
        $incomeStats = $club->payments()
            ->where('status', 'paid')
            ->selectRaw("
                SUM(CASE WHEN strftime('%Y-%m', paid_date) = ? THEN amount ELSE 0 END) as this_month,
                SUM(CASE WHEN strftime('%Y', paid_date) = ?   THEN amount ELSE 0 END) as this_year,
                SUM(amount) as all_time
            ", [$periodKey, (string) $year])
            ->first();

        $incomeThisMonth = (float) ($incomeStats->this_month ?? 0);
        $incomeThisYear  = (float) ($incomeStats->this_year  ?? 0);
        $incomeAllTime   = (float) ($incomeStats->all_time   ?? 0);

        // ── Expense KPIs — 1 query instead of 3 ──────────────────────────────
        $expenseStats = $club->expenses()
            ->selectRaw("
                SUM(CASE WHEN strftime('%Y-%m', expense_date) = ? THEN amount ELSE 0 END) as this_month,
                SUM(CASE WHEN strftime('%Y', expense_date) = ?   THEN amount ELSE 0 END) as this_year,
                SUM(amount) as all_time
            ", [$periodKey, (string) $year])
            ->first();

        $expenseThisMonth = (float) ($expenseStats->this_month ?? 0);
        $expenseThisYear  = (float) ($expenseStats->this_year  ?? 0);
        $expenseAllTime   = (float) ($expenseStats->all_time   ?? 0);

        $balance = $incomeAllTime - $expenseAllTime;

        // ── Monthly trend charts — 2 queries instead of 24 ───────────────────
        $cutoff = now()->subMonths(11)->startOfMonth()->toDateString();

        $incomeByMonthRaw = $club->payments()
            ->where('status', 'paid')
            ->where('paid_date', '>=', $cutoff)
            ->selectRaw("strftime('%Y-%m', paid_date) as month, SUM(amount) as total")
            ->groupBy('month')
            ->pluck('total', 'month');

        $expenseByMonthRaw = $club->expenses()
            ->where('expense_date', '>=', $cutoff)
            ->selectRaw("strftime('%Y-%m', expense_date) as month, SUM(amount) as total")
            ->groupBy('month')
            ->pluck('total', 'month');

        $monthlyIncome  = [];
        $monthlyExpense = [];
        for ($i = 11; $i >= 0; $i--) {
            $m     = now()->subMonths($i);
            $key   = $m->format('Y-m');
            $label = $m->format('M Y');
            $monthlyIncome[$label]  = (float) ($incomeByMonthRaw[$key]  ?? 0);
            $monthlyExpense[$label] = (float) ($expenseByMonthRaw[$key] ?? 0);
        }

        // ── Payment status counts — 1 query instead of 3 ─────────────────────
        $statusStats = $club->payments()
            ->selectRaw("
                SUM(CASE WHEN status = 'paid'    THEN 1 ELSE 0 END) as paid_count,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count,
                SUM(CASE WHEN status = 'overdue' THEN 1 ELSE 0 END) as overdue_count
            ")
            ->first();

        $paidCount    = (int) ($statusStats->paid_count    ?? 0);
        $pendingCount = (int) ($statusStats->pending_count ?? 0);
        $overdueCount = (int) ($statusStats->overdue_count ?? 0);

        // ── Members paid vs unpaid this month ─────────────────────────────────
        // Two targeted queries instead of loading all members then filtering in PHP
        $paidMemberIds = $club->payments()
            ->where('status', 'paid')
            ->whereRaw("strftime('%Y-%m', paid_date) = ?", [$periodKey])
            ->pluck('user_id');

        $paidMembers   = $club->members()->wherePivot('is_active', true)->whereIn('users.id', $paidMemberIds)->get();
        $unpaidMembers = $club->members()->wherePivot('is_active', true)->whereNotIn('users.id', $paidMemberIds)->get();

        // ── Income by job level — 1 JOIN query instead of 5 whereHas queries ──
        $jobLevels          = FeeRate::jobLevelLabels();
        $incomeByJobLevelRaw = $club->payments()
            ->where('payments.status', 'paid')
            ->whereRaw("strftime('%Y-%m', paid_date) = ?", [$periodKey])
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
            ->withSum(['expenses as total' => fn($q) => $q->whereRaw("strftime('%Y-%m', expense_date) = ?", [$periodKey])], 'amount')
            ->get();

        // ── Recent transactions ───────────────────────────────────────────────
        $recentPayments = $club->payments()->with('user')->where('status', 'paid')
            ->latest('paid_date')->limit(5)->get();
        $recentExpenses = $club->expenses()->with('category')
            ->latest('expense_date')->limit(5)->get();

        $availableYears = range(now()->year, max(now()->year - 4, 2020));

        return view('admin.dashboard.index', compact(
            'club',
            'year', 'month',
            'incomeThisMonth', 'incomeThisYear', 'incomeAllTime',
            'expenseThisMonth', 'expenseThisYear', 'expenseAllTime',
            'balance',
            'monthlyIncome', 'monthlyExpense',
            'paidCount', 'pendingCount', 'overdueCount',
            'paidMembers', 'unpaidMembers',
            'jobLevels', 'incomeByJobLevel',
            'expenseByCategory',
            'recentPayments', 'recentExpenses',
            'availableYears'
        ));
    }
}
