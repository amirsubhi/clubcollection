<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Updates\PreChecks;
use App\Support\Updates\UpdateCheck;
use App\Support\Updates\UpdateStatus;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Stage 1 of the in-app updater: tells the super admin what is new and whether this host could update itself, and
 * tells a club admin only that a security update is waiting (spec J1, J2, J3). There is no update button yet.
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
        $checks = $s->available && $latest !== null ? PreChecks::run($latest, false) : [];
        // Stage 1 has no ticks: the two "someone must act" rows (not blocking) are advice to read before the manual steps.
        $blockers = array_values(array_filter($checks, fn ($c) => ! $c->ok && $c->blocking));
        $before = array_values(array_filter($checks, fn ($c) => ! $c->ok && ! $c->blocking));
        $ready = $checks !== [] && $blockers === [];
        $hostMessage = implode("\n\n", array_values(array_filter(array_map(fn ($c) => $c->hostMessage, $blockers))));

        return view('admin.updates.index', [
            'status' => $s,
            'latest' => $latest,
            'security' => $latest !== null && $latest->isSecurityFor($s->installed),
            'checks' => $checks,
            'ready' => $ready,
            'blockers' => $blockers,
            'before' => $before,
            'hostMessage' => $hostMessage,
            'releasedOn' => $latest === null ? null : $this->date($latest->releasedAt),
            'checkedAt' => $s->checkedAt,
            'manualStepsUrl' => self::MANUAL_STEPS_URL,
        ]);
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
