<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\AuthorizesClubResource;
use App\Http\Controllers\Controller;
use App\Mail\MemberWelcome;
use App\Models\Club;
use App\Models\User;
use App\Services\AuditService;
use App\Support\Export\CsvSafe;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class MemberController extends Controller
{
    use AuthorizesClubResource;

    public function index(Club $club)
    {
        $this->authorizeClubAdmin($club);
        $members = $club->members()->paginate(20);
        $jobLevels = $club->jobLevelNames();
        return view('admin.members.index', compact('club', 'members', 'jobLevels'));
    }

    public function create(Club $club)
    {
        $this->authorizeClubAdmin($club);
        // New members go on an active level only.
        $jobLevels = $club->activeJobLevelNames();
        return view('admin.members.create', compact('club', 'jobLevels'));
    }

    public function store(Request $request, Club $club)
    {
        $this->authorizeClubAdmin($club);
        $data = $request->validate([
            'name'        => 'required|string|max:255',
            'email'       => 'required|email|max:255|unique:users,email',
            'job_level'   => ['required', 'string', Rule::in(array_keys($club->activeJobLevelNames()))],
            // Club admins don't see the role field: a missing role is 'member'.
            'role'        => 'nullable|in:admin,member',
            'joined_date' => 'required|date',
        ]);
        $data['role'] ??= 'member';
        $this->assertMayGrant($data['role']);

        $temporaryPassword = Str::password(12, letters: true, numbers: true, symbols: false);

        $user = User::forceCreate([
            'must_change_password' => true, // emailed temporary password: choose their own at first sign-in
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
            "Member '{$user->name}' ({$user->email}) added to club as {$data['role']} / {$club->jobLevelName($data['job_level'])}.",
            $user,
            $club->id,
            [],
            ['role' => $data['role'], 'job_level' => $data['job_level'], 'joined_date' => $data['joined_date']]
        );
        if ($data['role'] === 'admin') {
            $this->recordAdminGrant($club, $user);
        }

        return redirect()->route('admin.clubs.members.index', $club)
            ->with('success', "Member added. Login credentials have been sent to {$user->email}.");
    }

    public function edit(Club $club, User $member)
    {
        $club  = $this->resolveClubForMember($club, $member);
        $this->authorizeClubAdmin($club);

        $pivot     = $club->members()->where('users.id', $member->id)->first()?->pivot;
        $this->assertMayManageRow($pivot?->role);
        $jobLevels = $this->levelChoices($club, $pivot?->job_level);
        return view('admin.members.edit', compact('club', 'member', 'jobLevels', 'pivot'));
    }

    public function update(Request $request, Club $club, User $member)
    {
        $club = $this->resolveClubForMember($club, $member);
        $this->authorizeClubAdmin($club);

        $pivot  = $club->members()->where('users.id', $member->id)->first()?->pivot;
        abort_unless($pivot, 404);

        $data = $request->validate([
            // An active level, or the one the member is on now (even if it has been deactivated since).
            'job_level'   => ['required', 'string', Rule::in(array_keys($this->levelChoices($club, $pivot->job_level)))],
            // Club admins don't see the role field: a missing role keeps the current one.
            'role'        => 'nullable|in:admin,member',
            'joined_date' => 'required|date',
            'is_active'   => 'boolean',
        ]);

        $data['role'] ??= $pivot->role;
        // Club admin rows and the admin role are the super admin's to manage.
        $this->assertMayManageRow($pivot->role);
        $this->assertMayGrant($data['role']);
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
        if ($data['role'] === 'admin' && $pivot->role !== 'admin') {
            $this->recordAdminGrant($club, $member);
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
        $club = $this->resolveClubForMember($club, $member);
        $this->authorizeClubAdmin($club);
        $pivot = $club->members()->where('users.id', $member->id)->first()?->pivot;
        $this->assertMayManageRow($pivot?->role);

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
        $jobLevels = $club->activeJobLevelNames();
        return view('admin.members.import', compact('club', 'jobLevels'));
    }

    /**
     * Levels to offer in a member's form: the active ones, plus the member's current level when it has been
     * deactivated (so saving the form doesn't force a change).
     *
     * @return array<string, string> code => name
     */
    private function levelChoices(Club $club, ?string $current): array
    {
        $choices = $club->activeJobLevelNames();
        if ($current !== null && $current !== '' && ! isset($choices[$current])) {
            $choices[$current] = $club->jobLevelName($current).' (inactive)';
        }

        return $choices;
    }

    /**
     * The CSV job_level cell to a level code: the club's active levels by code, then by name, both without
     * regard to case (the Job levels page keeps names unique and distinct from other levels' codes). Null when
     * nothing matches.
     *
     * @param  array<string, string>  $levels  code => name
     */
    private static function resolveLevel(string $cell, array $levels): ?string
    {
        $wanted = mb_strtolower(trim($cell));
        if ($wanted === '') {
            return null;
        }
        foreach ($levels as $code => $name) {
            if (mb_strtolower($code) === $wanted) {
                return $code;
            }
        }
        foreach ($levels as $code => $name) {
            if (mb_strtolower($name) === $wanted) {
                return $code;
            }
        }

        return null;
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
            // Excel on Windows saves "CSV" as Windows-1252, not UTF-8; MySQL / MariaDB
            // refuse such bytes (error 1366), so convert them.
            $rawRows[] = [$row, array_map(
                fn ($cell) => is_string($cell) && ! mb_check_encoding($cell, 'UTF-8') ? mb_convert_encoding($cell, 'UTF-8', 'Windows-1252') : $cell,
                $line
            )];
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
        // Keyed in lower case: MySQL / MariaDB compare emails without case, and two
        // spellings of one address must not become two rows (or a 1062 error).
        $existingEmails = User::query()
            ->whereIn(DB::raw('LOWER(email)'), array_map('mb_strtolower', $candidateEmails))
            ->pluck('email')->mapWithKeys(fn ($e) => [mb_strtolower($e) => true])->all();

        $imported       = 0;
        $levels         = $club->activeJobLevelNames();
        $validRoles     = ['member', 'admin'];
        $canGrant       = auth()->user()->isSuperAdmin();

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

            // The Add member form's limit (and the column's: MySQL / MariaDB reject longer).
            // filter_var above already refuses an email of 255+ characters.
            if (mb_strlen($name) > 255) {
                $errors[] = ['row' => $rowNum, 'email' => $email, 'reason' => 'Name is longer than 255 characters.'];
                continue;
            }

            $levelCode = self::resolveLevel($jobLevel, $levels);
            if ($levelCode === null) {
                $accepted = $levels ? implode(', ', array_map(fn ($code, $name) => "{$name} ({$code})", array_keys($levels), $levels)) : 'none: add a job level first';
                $errors[] = ['row' => $rowNum, 'email' => $email, 'reason' => "Invalid job_level '{$jobLevel}'. Use a job level name or code of this club: {$accepted}."];
                continue;
            }

            if (! in_array($role, $validRoles, true)) {
                $errors[] = ['row' => $rowNum, 'email' => $email, 'reason' => "Invalid role '{$role}'. Must be: member or admin."];
                continue;
            }

            if ($role === 'admin' && ! $canGrant) {
                $errors[] = ['row' => $rowNum, 'email' => $email, 'reason' => 'Only the super admin can make someone a club admin. Change the role to member, or ask the super admin to import this row.'];
                continue;
            }

            $ts = strtotime($joinedDate);
            if (! $ts) {
                $errors[] = ['row' => $rowNum, 'email' => $email, 'reason' => "Invalid joined_date '{$joinedDate}'. Use YYYY-MM-DD format."];
                continue;
            }

            if (isset($existingEmails[mb_strtolower($email)])) {
                $errors[] = ['row' => $rowNum, 'email' => $email, 'reason' => 'A user with this email already exists. Add them manually.'];
                continue;
            }

            // Mark as seen to prevent duplicate rows in the same file.
            $existingEmails[mb_strtolower($email)] = true;

            $temporaryPassword = Str::password(12, letters: true, numbers: true, symbols: false);

            try {
                $user = User::forceCreate([
                    'must_change_password' => true, // emailed temporary password: choose their own at first sign-in
                    'name'     => $name,
                    'email'    => $email,
                    'password' => Hash::make($temporaryPassword),
                    'role'     => $role === 'admin' ? 'admin' : 'member',
                ]);
            } catch (UniqueConstraintViolationException) {
                // Someone added the same address while the file was being imported.
                $errors[] = ['row' => $rowNum, 'email' => $email, 'reason' => 'A user with this email already exists. Add them manually.'];
                continue;
            }

            $club->members()->attach($user->id, [
                'role'        => $role,
                'job_level'   => $levelCode,
                'joined_date' => date('Y-m-d', $ts),
                'is_active'   => true,
            ]);

            if ($role === 'admin') {
                $this->recordAdminGrant($club, $user);
            }

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

        // The sample row uses one of the club's own levels (by name; its code works too).
        $sampleLevel = array_values($club->activeJobLevelNames())[0] ?? 'Ahli';

        $callback = function () use ($sampleLevel) {
            $handle = fopen('php://output', 'w');
            CsvSafe::put($handle, ['name', 'email', 'job_level', 'role', 'joined_date']);
            CsvSafe::put($handle, ['Ahmad Razif', 'razif@example.com', $sampleLevel, 'member', date('Y-m-d')]);
            fclose($handle);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Only the super admin grants the club-admin role (S1): a club admin can
     * add and edit members only.
     */
    private function assertMayGrant(string $role): void
    {
        if ($role === 'admin' && ! auth()->user()->isSuperAdmin()) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'role' => 'Only the super admin can make someone a club admin.',
            ]);
        }
    }

    /** A club admin can't change or remove another club-admin row (nor their own). */
    private function assertMayManageRow(?string $currentRole): void
    {
        if ($currentRole === 'admin' && ! auth()->user()->isSuperAdmin()) {
            abort(403, 'Only the super admin can change or remove a club admin.');
        }
    }

    /** Audit a club-admin grant and tell every admin of the club. */
    private function recordAdminGrant(Club $club, User $grantee): void
    {
        $by = auth()->user();
        AuditService::log(
            'member.admin_granted',
            "{$grantee->name} ({$grantee->email}) was made a club admin by {$by->name}.",
            $grantee,
            $club->id,
            [],
            ['role' => 'admin', 'granted_by' => $by->id]
        );

        $club->notifyAdmins(
            'New club admin',
            [
                "{$grantee->name} ({$grantee->email}) is now an admin of {$club->name}.",
                "Granted by {$by->name} on ".now()->format('j M Y, H:i').'.',
                'Club admins can manage members, fees, payments and fund usage for this club.',
            ],
            route('admin.clubs.audit-logs', $club),
            'Open the audit log'
        );
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
    private function managesClub(User $user, Club $club): bool
    {
        return $user->isSuperAdmin() || ($club->is_active && $club->members()
            ->where('users.id', $user->id)
            ->wherePivot('role', 'admin')
            ->wherePivot('is_active', true)
            ->exists());
    }

    private function resolveClubForMember(Club $club, User $member): Club
    {
        if ($club->exists) {
            return $club;
        }

        $user = auth()->user();

        // The members list links here with ?club={id}. Honoured only for a club
        // the current user manages, so it can't be used to probe who belongs to
        // other clubs: otherwise it is ignored and the fallbacks below apply.
        if (($id = request()->integer('club')) && ($asked = Club::find($id))
            && $this->managesClub($user, $asked)
            && $asked->members()->where('users.id', $member->id)->exists()) {
            return $asked;
        }

        // Super admin: the member's first club. (Super admins always pass
        // authorizeClubAdmin downstream.)
        if ($user->isSuperAdmin()) {
            $resolved = $member->clubs()->first();
            abort_if(! $resolved, 404, 'Member is not attached to any club.');
            return $resolved;
        }

        // Club admin: the member must belong to a club THIS user still administers
        // (active admin pivot, active club), the same test as managesClub().
        $resolved = $user->clubs()
            ->wherePivot('role', 'admin')
            ->wherePivot('is_active', true)
            ->where('clubs.is_active', true)
            ->whereHas('members', fn ($q) => $q->where('users.id', $member->id))
            ->first();

        abort_if(! $resolved, 404, 'Member not found in any of your clubs.');
        return $resolved;
    }
}
