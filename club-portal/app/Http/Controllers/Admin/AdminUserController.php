<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AdminUserController extends Controller
{
    public function index()
    {
        $admins = User::whereIn('role', ['admin', 'super_admin'])->latest()->paginate(20);
        return view('admin.admins.index', compact('admins'));
    }

    public function create()
    {
        return view('admin.admins.create');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'  => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'role'  => 'required|in:admin,super_admin',
        ]);

        User::create([
            'name'     => $data['name'],
            'email'    => $data['email'],
            'password' => Hash::make('Admin@123'),
            'role'     => $data['role'],
        ]);

        return redirect()->route('admin.admins.index')
            ->with('success', 'Administrator created. Default password: Admin@123');
    }

    public function edit(User $admin)
    {
        return view('admin.admins.edit', compact('admin'));
    }

    public function update(Request $request, User $admin)
    {
        $data = $request->validate([
            'name'  => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email,' . $admin->id,
            'role'  => 'required|in:admin,super_admin',
        ]);

        $admin->update($data);

        return redirect()->route('admin.admins.index')
            ->with('success', 'Administrator updated.');
    }

    public function destroy(User $admin)
    {
        if ($admin->id === auth()->id()) {
            return back()->with('error', 'You cannot delete your own account.');
        }

        $admin->delete();
        return redirect()->route('admin.admins.index')->with('success', 'Administrator removed.');
    }
}
