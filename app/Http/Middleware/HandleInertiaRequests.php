<?php

namespace App\Http\Middleware;

use App\Models\Club;
use App\Support\Portal\PortalSettings;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Props shared with every Inertia page. Keep this minimal: everything here
     * is serialised into the page HTML. Only id / name / role of the user.
     *
     * The CSP nonce is not shared as a prop: the root view exposes it through
     * <meta property="csp-nonce">, which Vite's client and app.tsx read.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => fn () => $request->user()?->only(['id', 'name', 'role']),
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
            'locale' => fn () => $this->locale($request),
            'club' => fn () => $this->currentClub($request),
            // Portal settings: the name for page titles (app.tsx) and fallbacks.
            'portal' => fn () => ['name' => PortalSettings::name()],
        ];
    }

    /**
     * Members (and guests) get Bahasa Melayu by default; admins get the app locale.
     */
    protected function locale(Request $request): string
    {
        $user = $request->user();

        return ($user === null || $user->role === 'member') ? 'ms' : (string) config('app.locale');
    }

    /**
     * The club bound to the current route's {club} parameter, if any. Users can
     * belong to several clubs via club_user, so nothing is guessed otherwise.
     *
     * @return array{id: int, name: string}|null
     */
    protected function currentClub(Request $request): ?array
    {
        $club = $request->route('club');

        return $club instanceof Club ? $club->only(['id', 'name']) : null;
    }
}
