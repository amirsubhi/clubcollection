<?php

namespace App\Http\Controllers\Member;

use App\Http\Controllers\Controller;
use App\Models\Club;
use App\Support\Admin\FundFigures;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Ke mana wang anda pergi" (where your money goes): one club's fund for this year so far, for its members.
 * Club-wide aggregates from FundFigures (the same figures the club's admins see): totals per club, per
 * expense category, one welfare total (never a case count) and bank charges only. No descriptions, payees, receipts or other members' names leave the database.
 */
class FundController extends Controller
{
    public function show(Club $club): Response
    {
        $user = auth()->user();
        // Active members of an active club only, as the club's payments page (G2).
        abort_unless($club->is_active
            && $club->members()->where('users.id', $user->id)->where('club_user.is_active', true)->exists(), 403);

        $ytd = FundFigures::yearToDate();
        $figures = new FundFigures($club);
        $w = $figures->forWindow($ytd);

        return Inertia::render('Member/Fund', [
            'club' => [
                'id' => $club->id,
                'name' => $club->name,
                'paymentsUrl' => route('member.payments.index', $club),
            ],
            'fund' => [
                'window' => $w['window'],
                'collected' => $w['collected'],
                'spent' => $w['spent'],
                'expenses' => $w['expenses'],
                'welfare' => $w['welfare'],
                'charges' => $w['charges'],
                // Spending above what came in this year was paid from the fund balance.
                'fromReserves' => max(0, $w['spent'] - $w['collected']),
                // Fund balance (baki dana) at the end of today: the window ends today.
                'balance' => $figures->balanceAt($ytd['to']),
                'categories' => $figures->byCategoryBetween($ytd['from'], $ytd['to']),
            ],
            'dashboardUrl' => route('member.dashboard'),
        ]);
    }
}
