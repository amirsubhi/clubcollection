<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\AuthorizesClubResource;
use App\Http\Controllers\Controller;
use App\Models\Club;
use App\Models\ClubJobLevel;
use App\Services\AuditService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A club's job levels (tahap jawatan): members are put on a level and fee rates are set per level. The code is
 * made from the first name and never changes; the name can be edited. A level used by a member or a fee rate
 * can only be deactivated (no longer offered for new members and fee rates; existing records keep its name).
 */
class JobLevelController extends Controller
{
    use AuthorizesClubResource;

    public function index(Club $club)
    {
        $levels = $club->jobLevels()->get();
        $memberCounts = DB::table('club_user')->where('club_id', $club->id)
            ->selectRaw('job_level, COUNT(*) as n')->groupBy('job_level')->pluck('n', 'job_level');
        $rateCounts = DB::table('fee_rates')->where('club_id', $club->id)
            ->selectRaw('job_level, COUNT(*) as n')->groupBy('job_level')->pluck('n', 'job_level');

        return view('admin.job-levels.index', compact('club', 'levels', 'memberCounts', 'rateCounts'));
    }

    public function store(Request $request, Club $club)
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'sort_order' => 'nullable|integer|min:0|max:9999',
        ]);
        $this->assertNameFree($club, $data['name']);

        try {
            $level = $club->jobLevels()->create([
                'code' => ClubJobLevel::codeFor($club, $data['name']),
                'name' => $data['name'],
                'sort_order' => $data['sort_order'] ?? ((int) $club->jobLevels()->max('sort_order') + 1),
                'is_active' => true,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Another admin added a level with the same code a moment ago.
            throw ValidationException::withMessages(['name' => 'That job level was just added. Reload the page and try again.']);
        }

        AuditService::log(
            'job_level.created',
            "Job level '{$level->name}' ({$level->code}) added.",
            $level,
            $club->id,
            [],
            $level->only(['code', 'name', 'sort_order'])
        );

        return redirect()->route('admin.clubs.job-levels.index', $club)->with('success', "Job level '{$level->name}' added.");
    }

    /** Rename and/or reorder. The code stays the same, so members and fee rates follow the new name. */
    public function update(Request $request, ClubJobLevel $jobLevel)
    {
        $club = $jobLevel->club;
        $this->authorizeClubAdmin($club);

        $data = $request->validate([
            'name' => 'required|string|max:100',
            'sort_order' => 'required|integer|min:0|max:9999',
        ]);
        $this->assertNameFree($club, $data['name'], $jobLevel);

        $old = $jobLevel->only(['name', 'sort_order']);
        $jobLevel->update(['name' => $data['name'], 'sort_order' => (int) $data['sort_order']]);

        AuditService::log(
            'job_level.updated',
            $old['name'] === $jobLevel->name
                ? "Job level '{$jobLevel->name}' updated."
                : "Job level '{$old['name']}' renamed to '{$jobLevel->name}'.",
            $jobLevel,
            $club->id,
            $old,
            $jobLevel->only(['name', 'sort_order'])
        );

        return redirect()->route('admin.clubs.job-levels.index', $club)->with('success', 'Job level saved.');
    }

    /** Deactivate (no longer offered) or reactivate a level. */
    public function setActive(Request $request, ClubJobLevel $jobLevel)
    {
        $club = $jobLevel->club;
        $this->authorizeClubAdmin($club);
        $active = $request->validate(['is_active' => 'required|boolean'])['is_active'];
        $active = filter_var($active, FILTER_VALIDATE_BOOLEAN);

        if (! $active && $jobLevel->is_active && $this->isLastActive($jobLevel)) {
            return back()->with('error', 'A club needs at least one active job level. Add or reactivate another level first.');
        }

        if ($jobLevel->is_active !== $active) {
            $jobLevel->update(['is_active' => $active]);
            AuditService::log(
                $active ? 'job_level.reactivated' : 'job_level.deactivated',
                "Job level '{$jobLevel->name}' ".($active ? 'reactivated' : 'deactivated').'.',
                $jobLevel,
                $club->id,
                ['is_active' => ! $active],
                ['is_active' => $active]
            );
        }

        return redirect()->route('admin.clubs.job-levels.index', $club)
            ->with('success', $active ? "Job level '{$jobLevel->name}' reactivated." : "Job level '{$jobLevel->name}' deactivated. Members already on it keep it.");
    }

    /** Only a level no member or fee rate uses can be deleted; others can be deactivated. */
    public function destroy(ClubJobLevel $jobLevel)
    {
        $club = $jobLevel->club;
        $this->authorizeClubAdmin($club);

        if ($jobLevel->isInUse()) {
            return back()->with('error', "'{$jobLevel->name}' is used by {$jobLevel->memberCount()} member(s) and {$jobLevel->feeRateCount()} fee rate(s), so it can't be deleted. Deactivate it instead.");
        }
        if ($jobLevel->is_active && $this->isLastActive($jobLevel)) {
            return back()->with('error', 'A club needs at least one active job level. Add another level first.');
        }

        $old = $jobLevel->only(['code', 'name', 'sort_order', 'is_active']);
        $jobLevel->delete();

        AuditService::log(
            'job_level.deleted',
            "Job level '{$old['name']}' ({$old['code']}) deleted.",
            null,
            $club->id,
            $old
        );

        return redirect()->route('admin.clubs.job-levels.index', $club)->with('success', "Job level '{$old['name']}' deleted.");
    }

    private function isLastActive(ClubJobLevel $level): bool
    {
        return ! ClubJobLevel::where('club_id', $level->club_id)->where('is_active', true)->whereKeyNot($level->id)->exists();
    }

    /**
     * Names are unique in a club without regard to case, and may not equal another level's code: the CSV import
     * finds a level by code or by name, so each must point at one level only.
     */
    private function assertNameFree(Club $club, string $name, ?ClubJobLevel $except = null): void
    {
        $wanted = mb_strtolower(trim($name));
        $clash = ClubJobLevel::where('club_id', $club->id)
            ->when($except, fn ($q) => $q->whereKeyNot($except->id))
            ->get()
            ->first(fn (ClubJobLevel $l) => mb_strtolower($l->name) === $wanted || mb_strtolower($l->code) === $wanted);

        if ($clash) {
            throw ValidationException::withMessages(['name' => mb_strtolower($clash->name) === $wanted
                ? "The club already has a job level called '{$clash->name}'."
                : "'{$name}' is the code of the job level '{$clash->name}' (used by the CSV import). Choose another name, or rename '{$clash->name}' instead."]);
        }
    }
}
