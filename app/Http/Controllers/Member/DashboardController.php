<?php

namespace App\Http\Controllers\Member;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Support\Member\MemberPaymentPresenter as P;
use App\Support\Payments\DisplayStatus;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(): Response
    {
        $user = auth()->user();
        // Active clubs where the membership is active too (G2): a deactivated
        // club or membership drops off the member's home page.
        $clubs = $user->clubs()->where('clubs.is_active', true)->wherePivot('is_active', true)->orderBy('clubs.name')->get();
        $clubIds = $clubs->pluck('id');
        $year = now()->year;

        // Paid figures in one query; open fees are counted below by DisplayStatus
        // (grace period and FPX bills in progress), from the list loaded anyway.
        $rawStats = Payment::where('user_id', $user->id)
            ->whereIn('club_id', $clubIds)
            ->selectRaw("
                club_id,
                SUM(CASE WHEN status = 'paid'    THEN 1 ELSE 0 END) as paid_count,
                SUM(CASE WHEN status = 'paid'    THEN amount ELSE 0 END) as total_paid
            ")
            ->groupBy('club_id')
            ->get()
            ->keyBy('club_id');

        $open = Payment::where('user_id', $user->id)
            ->whereIn('club_id', $clubIds)
            ->where('status', '!=', 'paid')
            ->orderBy('due_date')
            ->orderBy('period_start')
            ->orderBy('id')
            ->get();
        $statuses = DisplayStatus::many($open);
        $openCounts = fn (int $clubId, string $status) => $open->where('club_id', $clubId)
            ->filter(fn (Payment $p) => $statuses[$p->id] === $status)->count();

        $clubs->load('jobLevels');

        $clubCards = $clubs->map(function ($club) use ($rawStats, $openCounts) {
            $s = $rawStats[$club->id] ?? null;
            return [
                'id' => $club->id,
                'name' => $club->name,
                // The club's own name for the member's level (the code if the level is gone).
                'jobLevel' => $club->jobLevelName($club->pivot->job_level),
                'role' => $club->pivot->role,
                'paymentsUrl' => route('member.payments.index', $club),
                'summary' => [
                    'paid' => (int) ($s->paid_count ?? 0),
                    'due' => $openCounts($club->id, DisplayStatus::DUE),
                    'overdue' => $openCounts($club->id, DisplayStatus::OVERDUE),
                    'processing' => $openCounts($club->id, DisplayStatus::PROCESSING),
                    'verifying' => $openCounts($club->id, DisplayStatus::VERIFYING),
                    'totalPaid' => P::sen($s->total_paid ?? 0),
                ],
                // "Ke mana wang anda pergi" has its own page (Member\FundController).
                'fundUrl' => route('member.clubs.fund', $club),
            ];
        })->values();

        $clubNames = $clubs->pluck('name', 'id');
        $withClub = fn (Payment $p) => P::summary($p) + ['clubName' => $clubNames[$p->club_id] ?? ''];

        $recentPaid = Payment::where('user_id', $user->id)
            ->whereIn('club_id', $clubIds)
            ->where('status', 'paid')
            ->orderByDesc('paid_date')
            ->orderByDesc('id')
            ->limit(3)
            ->get();

        // A fee whose transfer slip is being checked is not owed any more (the
        // member has paid; the treasurer is confirming), and one with an FPX
        // bill in progress may already be paid (the bank has not confirmed):
        // both are left out of the amount due and counted on their own line.
        $is = fn (string $status) => fn (Payment $p) => $statuses[$p->id] === $status;
        $owing = $open->filter(fn (Payment $p) => in_array($statuses[$p->id], [DisplayStatus::DUE, DisplayStatus::OVERDUE], true))->values();
        $processing = $open->filter($is(DisplayStatus::PROCESSING))->values();
        $verifying = $open->filter($is(DisplayStatus::VERIFYING))->values();

        // "Bayar semua" (R1b): every fee owed in the club of the oldest one, with
        // one bill or slip. Offered from two fees on, to active members only,
        // and not while an FPX payment is in progress in that club (it could
        // be paid twice).
        $payAll = null;
        if ($first = $owing->first()) {
            $club = $clubs->firstWhere('id', $first->club_id);
            $inClub = $owing->where('club_id', $first->club_id);
            if ($club && $club->pivot->is_active && $inClub->count() > 1
                && ! $processing->contains('club_id', $first->club_id)) {
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
                'overdueCount' => $owing->filter($is(DisplayStatus::OVERDUE))->count(),
                'verifyingCount' => $verifying->count(),
                'processingCount' => $processing->count(),
                'processingTotal' => $processing->sum(fn (Payment $p) => P::sen($p->amount)),
            ],
            // Oldest fee still owed first (the hero button pays exactly this one),
            // then fees with an FPX payment in progress, then slips being checked.
            'upcoming' => $owing->concat($processing)->concat($verifying)->take(5)->map($withClub)->values(),
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
