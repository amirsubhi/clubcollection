<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditService;
use App\Support\ReleaseInfo;
use App\Support\Updates\PreChecks;
use App\Support\Updates\UpdateCheck;
use App\Support\Updates\UpdateJob;
use App\Support\Updates\UpdaterFiles;
use App\Support\Updates\UpdaterHost;
use App\Support\Updates\UpdateStatus;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * The in-app updater, Laravel side: tells the super admin what is new and whether this host could update itself, and
 * tells a club admin only that a security update is waiting (spec J1, J2, J3). The re-confirmed "Update to vX" writes
 * the update job and hands over to the standalone updater page (spec section 4).
 */
class UpdatesController extends Controller
{
    /** Where the manual steps live; the anchor is the "Upgrade a site with the zip" heading. */
    public const MANUAL_STEPS_URL = 'https://github.com/amirsubhi/clubcollection/blob/master/docs/RELEASE.md#2-upgrade-a-site-with-the-zip';

    public function index(UpdateCheck $check): View
    {
        return $this->page($check->status());
    }

    /** "Check now": ask GitHub again, ignoring the cached answer. */
    public function check(UpdateCheck $check): RedirectResponse
    {
        $check->status(true);

        return redirect()->route('admin.updates.index');
    }

    /** "Update to vX": password (and the 2FA code when it is on), then the job is written and /update/ takes over. */
    public function start(Request $request, UpdateCheck $check): RedirectResponse
    {
        $user = $request->user();
        $request->validate(['password' => ['required', 'string'], 'code' => ['nullable', 'string', 'max:20']]);

        $lockKey = TwoFactorChallengeController::lockKey($user);
        if ($user->hasEnabledTwoFactor() && RateLimiter::tooManyAttempts($lockKey, TwoFactorChallengeController::MAX_FAILURES)) {
            $minutes = (int) ceil(RateLimiter::availableIn($lockKey) / 60);

            return $this->refuse($request, "Too many wrong codes. Try again in $minutes minute(s).");
        }
        if (! Hash::check((string) $request->input('password'), (string) $user->password)) {
            AuditService::log('portal.update_failed_check', 'Update not started: wrong password.');

            return $this->refuse($request, 'That password is not right.');
        }
        if ($user->hasEnabledTwoFactor()) {
            $code = trim((string) $request->input('code'));
            if ($code === '' || ! $user->verifyTwoFactorCode($code)) {
                RateLimiter::hit($lockKey, TwoFactorChallengeController::LOCK_SECONDS);
                AuditService::log('portal.update_failed_check', 'Update not started: wrong or missing authenticator code.');

                return $this->refuse($request, $code === ''
                    ? 'Enter the 6-digit code from your authenticator app.'
                    : 'That code is not right. Enter the current 6-digit code from your authenticator app.');
            }
            RateLimiter::clear($lockKey);
        }

        if (UpdateJob::isRunning()) {
            return $this->refuse($request, 'An update is already running. Wait for it to finish.');
        }
        if (is_file(UpdaterFiles::blockedPath())) {
            return $this->refuse($request, 'The update button is switched off until a failed update has been looked at.');
        }

        $s = $check->status();
        $m = $s->available ? $s->latest : null;
        if ($m === null) {
            return $this->refuse($request, 'There is no newer version to update to right now.');
        }
        if ($request->filled('version') && (string) $request->input('version') !== $m->version) {
            return $this->refuse($request, 'A different version came out while this page was open. Read what is new, then try again.');
        }

        $dbTicked = $request->boolean('db_backup_ticked');
        $manualTicked = $request->boolean('manual_ticked');
        $checks = PreChecks::run($m, true);
        if (! PreChecks::canStart($checks, $dbTicked, $manualTicked)) {
            $blocked = ! PreChecks::allPass($checks);

            return $this->refuse($request, $blocked
                ? "Your site isn't ready to update right now. The page shows what needs fixing."
                : 'Tick the box for each thing that has to be done first, once it has been done.');
        }

        try {
            $token = UpdateJob::create($m, $user, $dbTicked, $manualTicked);
        } catch (Throwable) {
            return $this->refuse($request, "The update could not be started: the portal's update folder cannot be written to.");
        }
        AuditService::log('portal.update_started', "Update to v{$m->version} started.", null, null, [],
            ['from' => $s->installed, 'to' => $m->version]);

        return redirect('/update/')->withCookie(cookie('cp_update', $token, 60, '/update/', null, $request->isSecure(), true, false, 'strict'));
    }

    private function refuse(Request $request, string $message): RedirectResponse
    {
        return redirect()->route('admin.updates.index')
            ->withErrors(['update' => $message])
            ->withInput($request->only('db_backup_ticked', 'manual_ticked'));
    }

    public function status(Request $request, UpdateCheck $check): JsonResponse
    {
        $super = $request->user()?->isSuperAdmin() === true;
        try {
            $s = $check->status();

            if (! $super) {
                $waiting = $this->securityWaiting($s);

                return response()->json([
                    'security_waiting' => $waiting,
                    'super_admins' => $waiting ? $this->superAdminNames() : [],
                ]);
            }

            return response()->json([
                'installed' => $s->installed,
                'available' => $s->available,
                'latest' => $s->latest === null ? null : [
                    'version' => $s->latest->version,
                    'security' => $s->latest->isSecurityFor($s->installed),
                    'summary' => $s->latest->summary,
                    'released_at' => $s->latest->releasedAt,
                ],
                'error' => $s->error,
            ]);
        } catch (Throwable) {
            return response()->json($super
                ? ['installed' => null, 'available' => false, 'latest' => null, 'error' => 'The update check failed, try again later.']
                : ['security_waiting' => false, 'super_admins' => []]);
        }
    }

    private function securityWaiting(UpdateStatus $s): bool
    {
        return $s->available && $s->latest !== null && $s->latest->isSecurityFor($s->installed);
    }

    /** @return list<string> */
    private function superAdminNames(): array
    {
        return User::query()->where('role', 'super_admin')->orderBy('name')->pluck('name')->map(fn ($n) => (string) $n)->all();
    }

    private function page(UpdateStatus $s): View
    {
        $latest = $s->latest;
        $job = UpdateJob::current();
        $running = $job !== null && ! in_array($job['status'] ?? null, UpdaterHost::TERMINAL_JOB_STATUSES, true);
        $blockedByFailure = is_file(UpdaterFiles::blockedPath());
        $checks = ! $running && $s->available && $latest !== null ? PreChecks::run($latest, false) : [];
        $blockers = array_values(array_filter($checks, fn ($c) => ! $c->ok && $c->blocking));
        // Rows that are advice, not a failure: the two "a person must act" rows (cleared by a tick) and a warning that
        // passes but carries a note and a fix (tight disk).
        $before = array_values(array_filter($checks, fn ($c) => ! $c->blocking && (! $c->ok || $c->message !== '')));
        $ready = $checks !== [] && $blockers === [];
        $hostMessage = implode("\n\n", array_values(array_filter(array_map(fn ($c) => $c->hostMessage, $blockers))));
        $user = auth()->user();

        return view('admin.updates.index', [
            'status' => $s,
            'latest' => $latest,
            'security' => $latest !== null && $latest->isSecurityFor($s->installed),
            'checks' => $checks,
            'ready' => $ready,
            'canUpdate' => $ready && ! $blockedByFailure,
            'blockers' => $blockers,
            'before' => $before,
            'hostMessage' => $hostMessage,
            'releasedOn' => $latest === null ? null : $this->date($latest->releasedAt),
            'checkedAt' => $s->checkedAt,
            'manualStepsUrl' => self::MANUAL_STEPS_URL,
            'installedCommit' => $this->installedCommit(),
            'running' => $running ? $this->runningLine($job, $user?->id) : null,
            'blockedByFailure' => $blockedByFailure,
            'twoFactorOn' => $user?->hasEnabledTwoFactor() === true,
        ]);
    }

    /** @return array{who: ?string, to: ?string, started: ?string, mine: bool} the J8 line's parts */
    private function runningLine(array $job, ?int $userId): array
    {
        $started = null;
        if (isset($job['created_at']) && is_int($job['created_at'])) {
            try {
                $started = CarbonImmutable::createFromTimestamp($job['created_at'], (string) ($job['timezone'] ?? config('app.timezone')))->format('g:i a');
            } catch (Throwable) {
                $started = null;
            }
        }

        return [
            'who' => is_string($job['user_name'] ?? null) ? $job['user_name'] : null,
            'to' => is_string($job['to_version'] ?? null) ? $job['to_version'] : null,
            'started' => $started,
            'mine' => $userId !== null && ($job['user_id'] ?? null) === $userId,
        ];
    }

    /** The short form of the commit the installed zip was built from; null for a git checkout. */
    private function installedCommit(): ?string
    {
        $commit = ReleaseInfo::read((string) config('updates.release_file'))['commit'] ?? null;

        return $commit === null ? null : substr($commit, 0, 7);
    }

    private function date(string $iso): string
    {
        try {
            return CarbonImmutable::parse($iso)->format('j M Y');
        } catch (Throwable) {
            return $iso;
        }
    }
}
