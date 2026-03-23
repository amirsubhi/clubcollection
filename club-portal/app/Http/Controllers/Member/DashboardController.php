<?php

namespace App\Http\Controllers\Member;

use App\Http\Controllers\Controller;
use App\Models\FeeRate;

class DashboardController extends Controller
{
    public function index()
    {
        $user  = auth()->user();
        $clubs = $user->clubs()->where('clubs.is_active', true)->get();

        // 1 query instead of 4N queries (one per status per club)
        $rawStats = \App\Models\Payment::where('user_id', $user->id)
            ->whereIn('club_id', $clubs->pluck('id'))
            ->selectRaw("
                club_id,
                SUM(CASE WHEN status = 'paid'    THEN 1 ELSE 0 END) as paid_count,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count,
                SUM(CASE WHEN status = 'overdue' THEN 1 ELSE 0 END) as overdue_count,
                SUM(CASE WHEN status = 'paid'    THEN amount ELSE 0 END) as total_paid
            ")
            ->groupBy('club_id')
            ->get()
            ->keyBy('club_id');

        $summary = [];
        foreach ($clubs as $club) {
            $s = $rawStats[$club->id] ?? null;
            $summary[$club->id] = [
                'paid'       => (int)   ($s->paid_count    ?? 0),
                'pending'    => (int)   ($s->pending_count ?? 0),
                'overdue'    => (int)   ($s->overdue_count ?? 0),
                'total_paid' => (float) ($s->total_paid    ?? 0),
            ];
        }

        $jobLevels = FeeRate::jobLevelLabels();

        return view('member.dashboard', compact('clubs', 'summary', 'jobLevels'));
    }
}
