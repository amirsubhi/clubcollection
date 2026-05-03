<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\AuthorizesClubResource;
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
    use AuthorizesClubResource;

    public function index(Club $club)
    {
        $this->authorizeClubAdmin($club);
        $members = $club->members()->paginate(20);
        $jobLevels = FeeRate::jobLevelLabels();
        return view('admin.members.index', compact('club', 'members', 'jobLevels'));
    }

    public function create(Club $club)
    {
        $this->authorizeClubAdmin($club);
        $jobLevels = FeeRate::jobLevelLabels();
        return view('admin.members.create', compact('club', 'jobLevels'));
    }

    public function store(Request $request, Club $club)
    {
        $this->authorizeClubAdmin($club);
        $data = $request->validate([
            'name'        => 'required|string|max:255',
            'email'       => 'required|email|max:255|unique:users,email',
            'job_level'   => 'required|in:gm,agm,manager,executive,non_exec',
            'role'        => 'required|in:admin,member',
            'joined_date' => 'required|date',
        ]);

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
        $club  = $this->resolveClubForMember($club, $member);
        $this->authorizeClubAdmin($club);

        $jobLevels = FeeRate::jobLevelLabels();
        $pivot     = $club->members()->where('users.id', $member->id)->first()?->pivot;
        return view('admin.members.edit', compact('club', 'member', 'jobLevels', 'pivot'));
    }

    public function update(Request $request, Club $club, User $member)
    {
        $club = $this->resolveClubForMember($club, $member);
        $this->authorizeClubAdmin($club);

        $data = $request->validate([
            'job_level'   => 'required|in:gm,agm,manager,executive,non_exec',
            'role'        => 'required|in:admin,member',
            'joined_date' => 'required|date',
            'is_active'   => 'boolean',
        ]);

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

        // pivot role is club-scoped; users.role is system-wide, managed by AdminUserController.

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
        $club = $this->resolveClubForMember($club, $member);
        $this->authorizeClubAdmin($club);

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
        $this->authorizeClubAdmin($club);
        return view('admin.members.import', compact('club'));
    }

    /**
     * Hard cap on rows we will process from a single CSV upload. Past this
     * point we stop reading. The file size is also capped at 2 MB by the
     * validator below, but a small CSV can still contain a lot of rows.
     */
    private const CSV_ROW_LIMIT = 1000;

    public function importProcess(Request $request, Club $club)
    {
        $this->authorizeClubAdmin($club);
        $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:2048',
        ]);

        $handle  = fopen($request->file('file')->getRealPath(), 'r');
        $rawRows = [];
        $errors  = [];
        $row     = 0;

        // First pass: buffer rows into memory, enforcing the cap.
        while (($line = fgetcsv($handle)) !== false) {
            $row++;
            if ($row === 1) continue;
            if ($row > self::CSV_ROW_LIMIT + 1) {
                $errors[] = [
                    'row'    => $row,
                    'email'  => '—',
                    'reason' => 'CSV exceeds the '.self::CSV_ROW_LIMIT.'-row import limit. Split the file and retry.',
                ];
                break;
            }
            $rawRows[] = [$row, $line];
        }
        fclose($handle);

        // One query to check existing emails instead of one per row.
        $candidateEmails = [];
        foreach ($rawRows as [$rowNum, $line]) {
            if (count($line) >= 2) {
                $email = $this->stripFormulaPrefix(trim($line[1]));
                if ($email) $candidateEmails[] = $email;
            }
        }
        $existingEmails = User::whereIn('email', $candidateEmails)->pluck('email', 'email')->all();

        $imported       = 0;
        $validJobLevels = ['gm', 'agm', 'manager', 'executive', 'non_exec'];
        $validRoles     = ['member', 'admin'];

        foreach ($rawRows as [$rowNum, $line]) {
            // Expect: name, email, job_level, role, joined_date
            if (count($line) < 5) {
                $errors[] = ['row' => $rowNum, 'email' => '—', 'reason' => 'Row has fewer than 5 columns.'];
                continue;
            }

            [$name, $email, $jobLevel, $role, $joinedDate] = array_map('trim', array_slice($line, 0, 5));

            // Strip leading characters that Excel / Sheets interpret as a
            // formula. A hostile uploader could plant `=cmd|'...'!A1` in the
            // name field; when an admin later exports the member list to
            // CSV/XLSX this would execute on open. Defang at write time.
            $name  = $this->stripFormulaPrefix($name);
            $email = $this->stripFormulaPrefix($email);

            if (empty($name)) {
                $errors[] = ['row' => $rowNum, 'email' => $email ?: '—', 'reason' => 'Name is required.'];
                continue;
            }

            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = ['row' => $rowNum, 'email' => $email ?: '—', 'reason' => 'Invalid email address.'];
                continue;
            }

            if (! in_array($jobLevel, $validJobLevels, true)) {
                $errors[] = ['row' => $rowNum, 'email' => $email, 'reason' => "Invalid job_level '{$jobLevel}'. Must be: " . implode(', ', $validJobLevels) . '.'];
                continue;
            }

            if (! in_array($role, $validRoles, true)) {
                $errors[] = ['row' => $rowNum, 'email' => $email, 'reason' => "Invalid role '{$role}'. Must be: member or admin."];
                continue;
            }

            $ts = strtotime($joinedDate);
            if (! $ts) {
                $errors[] = ['row' => $rowNum, 'email' => $email, 'reason' => "Invalid joined_date '{$joinedDate}'. Use YYYY-MM-DD format."];
                continue;
            }

            if (isset($existingEmails[$email])) {
                $errors[] = ['row' => $rowNum, 'email' => $email, 'reason' => 'A user with this email already exists. Add them manually.'];
                continue;
            }

            // Mark as seen to prevent duplicate rows in the same file.
            $existingEmails[$email] = $email;

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
                'joined_date' => date('Y-m-d', $ts),
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
        $this->authorizeClubAdmin($club);

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

    /**
     * Strip leading characters that spreadsheets interpret as the start of a
     * formula. Excel / Google Sheets evaluate cells beginning with `=`, `+`,
     * `-`, `@` (and various tab/CR variants) — turning a CSV-injected name
     * into code execution when an admin later exports the member list.
     */
    private function stripFormulaPrefix(string $value): string
    {
        return ltrim($value, "=+@-\t\r\x00");
    }

    /**
     * On shallow routes (`/admin/members/{member}`) the {club} URL segment is
     * absent and Laravel binds an empty Club instance. Resolve the canonical
     * club for the member: the single club the current user administers that
     * also contains $member. Aborts 404 if none — never silently scope down to
     * "any club admin happens to share with the target".
     */
    private function resolveClubForMember(Club $club, User $member): Club
    {
        if ($club->exists) {
            return $club;
        }

        $user = auth()->user();

        // Super admin: the member's first club. (Super admins always pass
        // authorizeClubAdmin downstream.)
        if ($user->isSuperAdmin()) {
            $resolved = $member->clubs()->first();
            abort_if(! $resolved, 404, 'Member is not attached to any club.');
            return $resolved;
        }

        // Club admin: the member must belong to a club THIS user administers.
        $resolved = $user->clubs()
            ->wherePivot('role', 'admin')
            ->whereHas('members', fn ($q) => $q->where('users.id', $member->id))
            ->first();

        abort_if(! $resolved, 404, 'Member not found in any of your clubs.');
        return $resolved;
    }
}
