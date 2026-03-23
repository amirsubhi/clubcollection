<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\MemberWelcome;
use App\Models\Club;
use App\Models\FeeRate;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class MemberController extends Controller
{
    public function index(Club $club)
    {
        $members = $club->members()->paginate(20);
        $jobLevels = FeeRate::jobLevelLabels();
        return view('admin.members.index', compact('club', 'members', 'jobLevels'));
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

        // Generate a cryptographically random temporary password
        $temporaryPassword = Str::password(12, letters: true, numbers: true, symbols: false);

        $user = User::create([
            'name'     => $data['name'],
            'email'    => $data['email'],
            'password' => Hash::make($temporaryPassword),
            'role'     => $data['role'] === 'admin' ? 'admin' : 'member',
        ]);

        $club->members()->attach($user->id, [
            'role'        => $data['role'],
            'job_level'   => $data['job_level'],
            'joined_date' => $data['joined_date'],
            'is_active'   => true,
        ]);

        // Send welcome email with credentials — never expose password in flash messages
        try {
            Mail::to($user->email)->send(new MemberWelcome($user, $club, $temporaryPassword));
        } catch (\Exception $e) {
            Log::error('Failed to send welcome email to member', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);
        }

        AuditService::log(
            'member.added',
            "Member '{$user->name}' ({$user->email}) added to club as {$data['role']} / {$data['job_level']}.",
            $user,
            $club->id,
            [],
            ['role' => $data['role'], 'job_level' => $data['job_level'], 'joined_date' => $data['joined_date']]
        );

        return redirect()->route('admin.clubs.members.index', $club)
            ->with('success', "Member added. Login credentials have been sent to {$user->email}.");
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

        // On shallow routes {member}, $club is not bound from the URL; resolve it
        if (! $club->id) {
            $club = auth()->user()->clubs()
                ->wherePivot('role', 'admin')
                ->whereHas('members', fn ($q) => $q->where('users.id', $member->id))
                ->first();
        }

        $pivot  = $club->members()->where('users.id', $member->id)->first()?->pivot;
        $oldPivot = $pivot ? [
            'role'      => $pivot->role,
            'job_level' => $pivot->job_level,
            'is_active' => (bool) $pivot->is_active,
        ] : [];

        $club->members()->updateExistingPivot($member->id, [
            'role'        => $data['role'],
            'job_level'   => $data['job_level'],
            'joined_date' => $data['joined_date'],
            'is_active'   => $request->boolean('is_active'),
        ]);

        if ($data['role'] === 'admin') {
            $member->update(['role' => 'admin']);
        }

        AuditService::log(
            'member.updated',
            "Member '{$member->name}' updated in club.",
            $member,
            $club->id,
            $oldPivot,
            ['role' => $data['role'], 'job_level' => $data['job_level'], 'is_active' => $request->boolean('is_active')]
        );

        return redirect()->route('admin.clubs.members.index', $club)
            ->with('success', 'Member updated successfully.');
    }

    public function destroy(Club $club, User $member)
    {
        // On shallow routes {member}, $club is not bound from the URL; resolve it
        if (! $club->id) {
            $club = auth()->user()->clubs()
                ->wherePivot('role', 'admin')
                ->whereHas('members', fn ($q) => $q->where('users.id', $member->id))
                ->first();
        }

        $club->members()->detach($member->id);

        AuditService::log(
            'member.removed',
            "Member '{$member->name}' ({$member->email}) removed from club.",
            $member,
            $club->id
        );

        return redirect()->route('admin.clubs.members.index', $club)
            ->with('success', 'Member removed from club.');
    }

    public function import(Club $club)
    {
        return view('admin.members.import', compact('club'));
    }

    public function importProcess(Request $request, Club $club)
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:2048',
        ]);

        $handle   = fopen($request->file('file')->getRealPath(), 'r');
        $imported = 0;
        $errors   = [];
        $row      = 0;

        $validJobLevels = ['gm', 'agm', 'manager', 'executive', 'non_exec'];
        $validRoles     = ['member', 'admin'];

        while (($line = fgetcsv($handle)) !== false) {
            $row++;

            // Skip header row
            if ($row === 1) {
                continue;
            }

            // Expect: name, email, job_level, role, joined_date
            if (count($line) < 5) {
                $errors[] = ['row' => $row, 'email' => '—', 'reason' => 'Row has fewer than 5 columns.'];
                continue;
            }

            [$name, $email, $jobLevel, $role, $joinedDate] = array_map('trim', array_slice($line, 0, 5));

            // Validate fields
            if (empty($name)) {
                $errors[] = ['row' => $row, 'email' => $email ?: '—', 'reason' => 'Name is required.'];
                continue;
            }

            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = ['row' => $row, 'email' => $email ?: '—', 'reason' => 'Invalid email address.'];
                continue;
            }

            if (! in_array($jobLevel, $validJobLevels, true)) {
                $errors[] = ['row' => $row, 'email' => $email, 'reason' => "Invalid job_level '{$jobLevel}'. Must be: " . implode(', ', $validJobLevels) . '.'];
                continue;
            }

            if (! in_array($role, $validRoles, true)) {
                $errors[] = ['row' => $row, 'email' => $email, 'reason' => "Invalid role '{$role}'. Must be: member or admin."];
                continue;
            }

            if (! strtotime($joinedDate)) {
                $errors[] = ['row' => $row, 'email' => $email, 'reason' => "Invalid joined_date '{$joinedDate}'. Use YYYY-MM-DD format."];
                continue;
            }

            // Check for duplicate email
            if (User::where('email', $email)->exists()) {
                $errors[] = ['row' => $row, 'email' => $email, 'reason' => 'A user with this email already exists. Add them manually.'];
                continue;
            }

            $temporaryPassword = Str::password(12, letters: true, numbers: true, symbols: false);

            $user = User::create([
                'name'     => $name,
                'email'    => $email,
                'password' => Hash::make($temporaryPassword),
                'role'     => $role === 'admin' ? 'admin' : 'member',
            ]);

            $club->members()->attach($user->id, [
                'role'        => $role,
                'job_level'   => $jobLevel,
                'joined_date' => date('Y-m-d', strtotime($joinedDate)),
                'is_active'   => true,
            ]);

            try {
                Mail::to($user->email)->send(new MemberWelcome($user, $club, $temporaryPassword));
            } catch (\Exception $e) {
                Log::error('Failed to send welcome email during CSV import', [
                    'user_id' => $user->id,
                    'error'   => $e->getMessage(),
                ]);
            }

            $imported++;
        }

        fclose($handle);

        if ($imported > 0) {
            AuditService::log(
                'member.imported',
                "{$imported} member(s) imported via CSV into club.",
                null,
                $club->id,
                [],
                ['imported_count' => $imported, 'error_count' => count($errors)]
            );
        }

        return back()
            ->with('import_imported', $imported)
            ->with('import_errors', $errors);
    }

    public function downloadTemplate(Club $club)
    {
        $headers = [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="members-import-template.csv"',
        ];

        $callback = function () {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['name', 'email', 'job_level', 'role', 'joined_date']);
            fputcsv($handle, ['Ahmad Razif', 'razif@example.com', 'manager', 'member', date('Y-m-d')]);
            fclose($handle);
        };

        return response()->stream($callback, 200, $headers);
    }
}
