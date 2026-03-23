<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class HomeController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('auth');
    }

    /**
     * Show the application dashboard.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function index()
    {
        $user = auth()->user();

        // Pure members go straight to member portal
        if ($user->role === 'member') {
            return redirect()->route('member.dashboard');
        }

        if ($user->isSuperAdmin()) {
            $totalClubs = \App\Models\Club::count();

            // 1 query instead of 2 separate User counts
            $userStats    = \App\Models\User::selectRaw("
                SUM(CASE WHEN role = 'member' THEN 1 ELSE 0 END) as member_count,
                SUM(CASE WHEN role IN ('admin','super_admin') THEN 1 ELSE 0 END) as admin_count
            ")->first();
            $totalMembers = (int) ($userStats->member_count ?? 0);
            $totalAdmins  = (int) ($userStats->admin_count ?? 0);

            return view('home', compact('totalClubs', 'totalMembers', 'totalAdmins'));
        }

        $clubs = $user->clubs()->where('clubs.is_active', true)->get();
        return view('home', compact('clubs'));
    }
}
