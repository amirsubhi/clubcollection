<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Club;
use App\Models\FeeRate;
use App\Models\Payment;
use App\Support\Admin\FundFigures;
use Illuminate\Support\Carbon;
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
            SUM(CASE WHEN status = 'pending_verification' THEN 1 ELSE 0 END) as verifying_count
        ")->first();

        $totalPayments = (int)   ($paymentStats->total ?? 0);
        $paidCount     = (int)   ($paymentStats->paid_count ?? 0);
        $pendingCount  = (int)   ($paymentStats->pending_count ?? 0);
        $overdueCount  = (int)   ($paymentStats->overdue_count ?? 0);
        // Transfer slips waiting for a club admin: not paid yet, shown on their own.
        $verifyingCount = (int)  ($paymentStats->verifying_count ?? 0);

        // ── Money: FundFigures per club, so these match each club's dashboard and ledger ──
        // Spent = approved expenses + welfare + bank / gateway charges the club absorbed.
        $today = now()->startOfDay();
        $since = Carbon::create(2000, 1, 1);
        $months = 12;
        $clubStats = Club::withCount('members')
            ->withCount(['payments as paid_count'    => fn($q) => $q->where('status', 'paid')])
            ->withCount(['payments as pending_count' => fn($q) => $q->where('status', 'pending')])
            ->withCount(['payments as overdue_count' => fn($q) => $q->where('status', 'overdue')])
            ->orderBy('name')
            ->get();

        $totals = ['collected' => 0, 'spent' => 0, 'expenses' => 0, 'welfare' => 0, 'charges' => 0, 'balance' => 0];
        $monthly = null;
        foreach ($clubStats as $club) {
            $figures = FundFigures::for($club);
            $split = $figures->spentSplitBetween($since, $today);
            $club->collected_sen = $figures->collectedBetween($since, $today);
            $club->spent_sen = $split['spent'];
            $club->balance_sen = $figures->balanceAt($today);
            foreach (['expenses', 'welfare', 'charges'] as $k) {
                $totals[$k] += $split[$k];
            }
            $totals['collected'] += $club->collected_sen;
            $totals['spent'] += $club->spent_sen;
            $totals['balance'] += $club->balance_sen;

            $rows = $figures->monthly($months);
            $monthly ??= array_map(fn ($r) => ['label' => $r['monthEn'].' '.$r['year'], 'collected' => 0, 'spent' => 0], $rows);
            foreach ($rows as $i => $r) {
                $monthly[$i]['collected'] += $r['collected'];
                $monthly[$i]['spent'] += $r['spent'];
            }
        }
        $clubStats = $clubStats->sortByDesc('collected_sen')->values();
        // No clubs yet: the same 12 empty months.
        $monthly ??= array_map(fn ($i) => ['label' => $today->copy()->startOfMonth()->subMonthsNoOverflow($i)->format('M Y'), 'collected' => 0, 'spent' => 0], range($months - 1, 0));
        $asOf = $today->format('j M Y');

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
            'paidCount', 'pendingCount', 'overdueCount', 'verifyingCount',
            'totals', 'monthly', 'asOf',
            'clubStats', 'jobLevelLabels', 'jobLevelDistribution',
            'jobLevelChartLabels', 'jobLevelChartCounts',
            'recentTransactions'
        ));
    }
}
