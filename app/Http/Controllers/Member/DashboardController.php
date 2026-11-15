<?php

namespace App\Http\Controllers\Member;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\FeeRate;
use App\Models\Payment;
use App\Support\Member\MemberPaymentPresenter as P;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(): Response
    {
        $user = auth()->user();
        $clubs = $user->clubs()->where('clubs.is_active', true)->orderBy('clubs.name')->get();
        $clubIds = $clubs->pluck('id');
        $year = now()->year;

        // 1 query instead of 4N queries (one per status per club)
        $rawStats = Payment::where('user_id', $user->id)
            ->whereIn('club_id', $clubIds)
            ->selectRaw("
                club_id,
                SUM(CASE WHEN status = 'paid'    THEN 1 ELSE 0 END) as paid_count,
                SUM(CASE WHEN status = 'pending' AND due_date >= ? THEN 1 ELSE 0 END) as pending_count,
                SUM(CASE WHEN status = 'overdue' OR (status = 'pending' AND due_date < ?) THEN 1 ELSE 0 END) as overdue_count,
                SUM(CASE WHEN status = 'paid'    THEN amount ELSE 0 END) as total_paid
            ", [today()->toDateString(), today()->toDateString()])
            ->groupBy('club_id')
            ->get()
            ->keyBy('club_id');

        // "Where your money goes": club-wide aggregates for this year only.
        // Totals per club and per expense category; no descriptions, payees,
        // receipts or other members' names leave the database.
        $collected = Payment::whereIn('club_id', $clubIds)
            ->where('status', 'paid')
            ->whereYear('paid_date', $year)
            ->selectRaw('club_id, SUM(amount) as total')
            ->groupBy('club_id')
            ->pluck('total', 'club_id');

        $spending = Expense::query()
            ->leftJoin('expense_categories', 'expense_categories.id', '=', 'expenses.expense_category_id')
            ->whereIn('expenses.club_id', $clubIds)
            ->whereYear('expenses.expense_date', $year)
            ->selectRaw('expenses.club_id as club_id, expense_categories.name as category, SUM(expenses.amount) as total')
            ->groupBy('expenses.club_id', 'expense_categories.name')
            ->get()
            ->groupBy('club_id');

        $jobLevels = FeeRate::jobLevelLabels();

        $clubCards = $clubs->map(function ($club) use ($rawStats, $collected, $spending, $jobLevels) {
            $s = $rawStats[$club->id] ?? null;
            $categories = collect($spending[$club->id] ?? [])
                ->map(fn ($row) => ['name' => $row->category, 'amount' => P::sen($row->total)])
                ->sortByDesc('amount')
                ->values();

            return [
                'id' => $club->id,
                'name' => $club->name,
                'jobLevel' => $jobLevels[$club->pivot->job_level] ?? $club->pivot->job_level,
                'role' => $club->pivot->role,
                'paymentsUrl' => route('member.payments.index', $club),
                'summary' => [
                    'paid' => (int) ($s->paid_count ?? 0),
                    'pending' => (int) ($s->pending_count ?? 0),
                    'overdue' => (int) ($s->overdue_count ?? 0),
                    'totalPaid' => P::sen($s->total_paid ?? 0),
                ],
                'fund' => [
                    'collected' => P::sen($collected[$club->id] ?? 0),
                    'spent' => $categories->sum('amount'),
                    'categories' => $categories,
                ],
            ];
        })->values();

        $clubNames = $clubs->pluck('name', 'id');
        $withClub = fn (Payment $p) => P::summary($p) + ['clubName' => $clubNames[$p->club_id] ?? ''];

        $open = Payment::where('user_id', $user->id)
            ->whereIn('club_id', $clubIds)
            ->where('status', '!=', 'paid')
            ->orderBy('due_date')
            ->get();

        $recentPaid = Payment::where('user_id', $user->id)
            ->whereIn('club_id', $clubIds)
            ->where('status', 'paid')
            ->orderByDesc('paid_date')
            ->orderByDesc('id')
            ->limit(3)
            ->get();

        return Inertia::render('Member/Home', [
            'year' => $year,
            'outstanding' => [
                'total' => $open->sum(fn (Payment $p) => P::sen($p->amount)),
                'count' => $open->count(),
                'overdueCount' => $open->filter(fn (Payment $p) => P::displayStatus($p) === 'overdue')->count(),
            ],
            // Oldest open fee first: the hero button pays exactly this one.
            'upcoming' => $open->take(5)->map($withClub)->values(),
            'recentPaid' => $recentPaid->map($withClub)->values(),
            'clubs' => $clubCards,
            'dashboardUrl' => route('member.dashboard'),
            'account' => [
                'isAdmin' => $user->isAdmin(),
                'adminUrl' => $user->isAdmin() ? route('home') : null,
                'securityUrl' => route('profile.security'),
                'logoutUrl' => route('logout'),
            ],
        ]);
    }
}
