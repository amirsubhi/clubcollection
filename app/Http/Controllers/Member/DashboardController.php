<?php

namespace App\Http\Controllers\Member;

use App\Http\Controllers\Controller;
use App\Models\FeeRate;
use App\Models\Payment;
use App\Support\Admin\FundFigures;
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
                SUM(CASE WHEN status = 'pending_verification' THEN 1 ELSE 0 END) as verifying_count,
                SUM(CASE WHEN status = 'paid'    THEN amount ELSE 0 END) as total_paid
            ", [today()->toDateString(), today()->toDateString()])
            ->groupBy('club_id')
            ->get()
            ->keyBy('club_id');

        // "Where your money goes": club-wide aggregates for this year so far,
        // from FundFigures (the same figures the club's admins see). Totals per
        // club and per expense category only; no descriptions, payees, receipts
        // or other members' names leave the database.
        $ytd = FundFigures::yearToDate();
        $fundFor = function ($club) use ($ytd) {
            $figures = new FundFigures($club);
            $w = $figures->forWindow($ytd);

            return [
                'window' => $w['window'],
                'collected' => $w['collected'],
                'spent' => $w['spent'],
                'expenses' => $w['expenses'],
                'welfare' => $w['welfare'],
                'charges' => $w['charges'],
                // Spending above what came in this year was paid from earlier savings.
                'fromReserves' => max(0, $w['spent'] - $w['collected']),
                'categories' => $figures->byCategoryBetween($ytd['from'], $ytd['to']),
            ];
        };

        $jobLevels = FeeRate::jobLevelLabels();

        $clubCards = $clubs->map(function ($club) use ($rawStats, $fundFor, $jobLevels) {
            $s = $rawStats[$club->id] ?? null;
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
                    'verifying' => (int) ($s->verifying_count ?? 0),
                    'totalPaid' => P::sen($s->total_paid ?? 0),
                ],
                'fund' => $fundFor($club),
            ];
        })->values();

        $clubNames = $clubs->pluck('name', 'id');
        $withClub = fn (Payment $p) => P::summary($p) + ['clubName' => $clubNames[$p->club_id] ?? ''];

        $open = Payment::where('user_id', $user->id)
            ->whereIn('club_id', $clubIds)
            ->where('status', '!=', 'paid')
            ->orderBy('due_date')
            ->orderBy('period_start')
            ->orderBy('id')
            ->get();

        $recentPaid = Payment::where('user_id', $user->id)
            ->whereIn('club_id', $clubIds)
            ->where('status', 'paid')
            ->orderByDesc('paid_date')
            ->orderByDesc('id')
            ->limit(3)
            ->get();

        // A fee whose transfer slip is being checked is not owed any more (the
        // member has paid; the treasurer is confirming), so it is left out of
        // the amount due and counted on its own line.
        $owing = $open->reject(fn (Payment $p) => $p->isVerifying());

        // "Bayar semua" (R1b): every fee owed in the club of the oldest one, with
        // one bill or slip. Offered from two fees on, to active members only.
        $payAll = null;
        if ($first = $owing->first()) {
            $club = $clubs->firstWhere('id', $first->club_id);
            $inClub = $owing->where('club_id', $first->club_id);
            if ($club && $club->pivot->is_active && $inClub->count() > 1) {
                $payAll = [
                    'url' => route('member.payments.review-all', $club),
                    'totalSen' => $inClub->sum(fn (Payment $p) => P::sen($p->amount)),
                    'count' => $inClub->count(),
                    'clubName' => $club->name,
                ];
            }
        }

        return Inertia::render('Member/Home', [
            'year' => $year,
            'outstanding' => [
                'total' => $owing->sum(fn (Payment $p) => P::sen($p->amount)),
                'count' => $owing->count(),
                'overdueCount' => $owing->filter(fn (Payment $p) => P::displayStatus($p) === 'overdue')->count(),
                'verifyingCount' => $open->count() - $owing->count(),
            ],
            // Oldest fee still owed first (the hero button pays exactly this one),
            // then fees whose slip is being checked.
            'upcoming' => $owing->concat($open->filter(fn (Payment $p) => $p->isVerifying()))->take(5)->map($withClub)->values(),
            'recentPaid' => $recentPaid->map($withClub)->values(),
            'payAll' => $payAll,
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
