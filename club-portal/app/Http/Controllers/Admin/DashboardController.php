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

        // --- Income ---
        $incomeThisMonth = $club->payments()
            ->where('status', 'paid')
            ->whereRaw("strftime('%Y-%m', paid_date) = ?", [sprintf('%04d-%02d', $year, $month)])
            ->sum('amount');

        $incomeThisYear = $club->payments()
            ->where('status', 'paid')
            ->whereRaw("strftime('%Y', paid_date) = ?", [(string) $year])
            ->sum('amount');

        $incomeAllTime = $club->payments()->where('status', 'paid')->sum('amount');

        // Monthly income chart (last 12 months)
        $monthlyIncome = [];
        for ($i = 11; $i >= 0; $i--) {
            $m   = now()->subMonths($i);
            $key = $m->format('Y-m');
            $monthlyIncome[$m->format('M Y')] = $club->payments()
                ->where('status', 'paid')
                ->whereRaw("strftime('%Y-%m', paid_date) = ?", [$key])
                ->sum('amount');
        }

        // --- Expenses ---
        $expenseThisMonth = $club->expenses()
            ->whereRaw("strftime('%Y-%m', expense_date) = ?", [sprintf('%04d-%02d', $year, $month)])
            ->sum('amount');

        $expenseThisYear = $club->expenses()
            ->whereRaw("strftime('%Y', expense_date) = ?", [(string) $year])
            ->sum('amount');

        $expenseAllTime = $club->expenses()->sum('amount');

        // Monthly expense chart (last 12 months)
        $monthlyExpense = [];
        for ($i = 11; $i >= 0; $i--) {
            $m   = now()->subMonths($i);
            $key = $m->format('Y-m');
            $monthlyExpense[$m->format('M Y')] = $club->expenses()
                ->whereRaw("strftime('%Y-%m', expense_date) = ?", [$key])
                ->sum('amount');
        }

        // --- Balance ---
        $balance = $incomeAllTime - $expenseAllTime;

        // --- Payment status breakdown ---
        $paidCount    = $club->payments()->where('status', 'paid')->count();
        $pendingCount = $club->payments()->where('status', 'pending')->count();
        $overdueCount = $club->payments()->where('status', 'overdue')->count();

        // --- Members paid vs unpaid this month ---
        $allMembers    = $club->members()->wherePivot('is_active', true)->get();
        $paidMemberIds = $club->payments()
            ->where('status', 'paid')
            ->whereRaw("strftime('%Y-%m', paid_date) = ?", [sprintf('%04d-%02d', $year, $month)])
            ->pluck('user_id')->toArray();

        $paidMembers   = $allMembers->filter(fn($m) => in_array($m->id, $paidMemberIds));
        $unpaidMembers = $allMembers->filter(fn($m) => !in_array($m->id, $paidMemberIds));

        // --- Income by job level this month ---
        $jobLevels        = FeeRate::jobLevelLabels();
        $incomeByJobLevel = [];
        foreach (array_keys($jobLevels) as $level) {
            $incomeByJobLevel[$level] = $club->payments()
                ->where('status', 'paid')
                ->whereRaw("strftime('%Y-%m', paid_date) = ?", [sprintf('%04d-%02d', $year, $month)])
                ->whereHas('user.clubs', fn($q) => $q->where('clubs.id', $club->id)->where('club_user.job_level', $level))
                ->sum('amount');
        }

        // --- Expenses by category this month ---
        $expenseByCategory = $club->expenseCategories()
            ->withSum(['expenses as total' => fn($q) => $q->whereRaw("strftime('%Y-%m', expense_date) = ?", [sprintf('%04d-%02d', $year, $month)])], 'amount')
            ->get();

        // --- Recent transactions ---
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
