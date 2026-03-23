<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Club;
use App\Models\FeeRate;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class MemberController extends Controller
{
    public function index(Club $club)
    {
        $members = $club->members()->paginate(20);
        return view('admin.members.index', compact('club', 'members'));
    }

    public function create(Club $club)
    {
        $jobLevels = FeeRate::jobLevelLabels();
        return view('admin.members.create', compact('club', 'jobLevels'));
    }

    public function store(Request $request, Club $club)
    {
        $data = $request->validate([
            'name'        => 'required|string|max:255',
            'email'       => 'required|email|max:255|unique:users,email',
            'job_level'   => 'required|in:gm,agm,manager,executive,non_exec',
            'role'        => 'required|in:admin,member',
            'joined_date' => 'required|date',
        ]);

        $user = User::create([
            'name'     => $data['name'],
            'email'    => $data['email'],
            'password' => Hash::make('Welcome@123'),
            'role'     => $data['role'] === 'admin' ? 'admin' : 'member',
        ]);

        $club->members()->attach($user->id, [
            'role'        => $data['role'],
            'job_level'   => $data['job_level'],
            'joined_date' => $data['joined_date'],
            'is_active'   => true,
        ]);

        return redirect()->route('admin.members.index', $club)
            ->with('success', 'Member added. Default password: Welcome@123');
    }

    public function edit(Club $club, User $member)
    {
        $jobLevels = FeeRate::jobLevelLabels();
        $pivot = $club->members()->where('users.id', $member->id)->first()?->pivot;
        return view('admin.members.edit', compact('club', 'member', 'jobLevels', 'pivot'));
    }

    public function update(Request $request, Club $club, User $member)
    {
        $data = $request->validate([
            'job_level'   => 'required|in:gm,agm,manager,executive,non_exec',
            'role'        => 'required|in:admin,member',
            'joined_date' => 'required|date',
            'is_active'   => 'boolean',
        ]);

        $club->members()->updateExistingPivot($member->id, [
            'role'        => $data['role'],
            'job_level'   => $data['job_level'],
            'joined_date' => $data['joined_date'],
            'is_active'   => $request->boolean('is_active'),
        ]);

        if ($data['role'] === 'admin') {
            $member->update(['role' => 'admin']);
        }

        return redirect()->route('admin.members.index', $club)
            ->with('success', 'Member updated successfully.');
    }

    public function destroy(Club $club, User $member)
    {
        $club->members()->detach($member->id);
        return redirect()->route('admin.members.index', $club)
            ->with('success', 'Member removed from club.');
    }
}
