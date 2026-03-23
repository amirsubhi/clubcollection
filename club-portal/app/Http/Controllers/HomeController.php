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
            $totalClubs   = \App\Models\Club::count();
            $totalMembers = \App\Models\User::where('role', 'member')->count();
            $totalAdmins  = \App\Models\User::whereIn('role', ['admin', 'super_admin'])->count();
            return view('home', compact('totalClubs', 'totalMembers', 'totalAdmins'));
        }

        $clubs = $user->clubs()->where('clubs.is_active', true)->get();
        return view('home', compact('clubs'));
    }
}
