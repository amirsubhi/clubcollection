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

        $summary = [];
        foreach ($clubs as $club) {
            $summary[$club->id] = [
                'paid'       => $club->payments()->where('user_id', $user->id)->where('status', 'paid')->count(),
                'pending'    => $club->payments()->where('user_id', $user->id)->where('status', 'pending')->count(),
                'overdue'    => $club->payments()->where('user_id', $user->id)->where('status', 'overdue')->count(),
                'total_paid' => $club->payments()->where('user_id', $user->id)->where('status', 'paid')->sum('amount'),
            ];
        }

        $jobLevels = FeeRate::jobLevelLabels();

        return view('member.dashboard', compact('clubs', 'summary', 'jobLevels'));
    }
}
