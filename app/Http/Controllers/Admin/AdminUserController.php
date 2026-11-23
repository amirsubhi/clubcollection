<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\MemberWelcome;
use App\Models\Club;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

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

        $temporaryPassword = Str::password(12, letters: true, numbers: true, symbols: false);

        $user = User::forceCreate([
            'must_change_password' => true, // emailed temporary password: choose their own at first sign-in
            'name'     => $data['name'],
            'email'    => $data['email'],
            'password' => Hash::make($temporaryPassword),
            'role'     => $data['role'],
        ]);

        AuditService::log(
            'admin_user.created',
            "Administrator '{$user->name}' ({$user->email}) created as {$user->role}.",
            $user,
            null,
            [],
            ['name' => $user->name, 'email' => $user->email, 'role' => $user->role]
        );

        // Use a placeholder club for the welcome email (admin isn't tied to a specific club)
        try {
            $dummyClub = new Club(['name' => config('app.name', 'Club Portal')]);
            // An admin account: admin emails stay in English, whatever the portal's default language.
            Mail::to($user)->locale('en')->send(new MemberWelcome($user, $dummyClub, $temporaryPassword));
        } catch (\Exception $e) {
            Log::error('Failed to send welcome email to admin', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);
        }

        return redirect()->route('admin.admins.index')
            ->with('success', "Administrator created. Login credentials have been sent to {$user->email}.");
    }

    public function edit(User $admin)
    {
        $this->assertIsAdmin($admin);

        return view('admin.admins.edit', compact('admin'));
    }

    public function update(Request $request, User $admin)
    {
        $this->assertIsAdmin($admin);
        $data = $request->validate([
            'name'  => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email,' . $admin->id,
            'role'  => 'required|in:admin,super_admin',
        ]);

        if ($admin->isSuperAdmin() && $data['role'] !== 'super_admin') {
            if ($admin->id === auth()->id()) {
                return back()->withInput()->with('error', 'You cannot remove your own super admin role. Ask another super admin.');
            }
            if ($this->isLastSuperAdmin($admin)) {
                return back()->withInput()->with('error', 'This is the last super admin. Make someone else a super admin first.');
            }
        }

        $old = $admin->only(['name', 'email', 'role']);
        $admin->update($data);

        AuditService::log(
            'admin_user.updated',
            "Administrator '{$admin->name}' updated".($old['role'] !== $admin->role ? " (role {$old['role']} → {$admin->role})" : '').'.',
            $admin,
            null,
            $old,
            $admin->only(['name', 'email', 'role'])
        );

        return redirect()->route('admin.admins.index')
            ->with('success', 'Administrator updated.');
    }

    public function destroy(User $admin)
    {
        $this->assertIsAdmin($admin);
        if ($admin->id === auth()->id()) {
            return back()->with('error', 'You cannot delete your own account.');
        }
        if ($admin->isSuperAdmin() && $this->isLastSuperAdmin($admin)) {
            return back()->with('error', 'This is the last super admin and cannot be removed.');
        }

        $old = $admin->only(['id', 'name', 'email', 'role']);
        try {
            $admin->delete();
        } catch (QueryException $e) {
            // Records they made (payments, expenses, documents) keep them on file.
            return back()->with('error', "{$admin->name} has financial records in the system, so the account can't be deleted.");
        }

        AuditService::log('admin_user.deleted', "Administrator '{$old['name']}' ({$old['email']}, {$old['role']}) deleted.", null, null, $old);

        return redirect()->route('admin.admins.index')->with('success', 'Administrator removed.');
    }

    /** {admin} only ever binds an admin or super admin (S1), never a member. */
    private function assertIsAdmin(User $admin): void
    {
        abort_unless(in_array($admin->role, ['admin', 'super_admin'], true), 404);
    }

    private function isLastSuperAdmin(User $admin): bool
    {
        return $admin->isSuperAdmin()
            && User::where('role', 'super_admin')->whereKeyNot($admin->id)->doesntExist();
    }
}
