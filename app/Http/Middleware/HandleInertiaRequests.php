<?php

namespace App\Http\Middleware;

use App\Models\Club;
use App\Support\Portal\PortalSettings;
use App\Support\UserLocale;
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
            // A signed-in member's language switch saves the choice at `url` (resources/js/lib/langSave.ts); `saved`:
            // the account has a language, so the browser copies it (else the portal default is never written down).
            'langSave' => fn () => $request->user()?->role === 'member'
                ? ['url' => route('member.language.update'), 'saved' => UserLocale::saved($request->user()) !== null]
                : null,
            'club' => fn () => $this->currentClub($request),
            // Portal settings: the name for page titles (app.tsx) and fallbacks.
            'portal' => fn () => ['name' => PortalSettings::name()],
        ];
    }

    /**
     * Members (and guests) get the member-side language (UserLocale: saved on the account, else the browser's
     * choice, else the portal default); admins get the app locale (admin pages stay English). For a signed-in
     * member app.tsx starts from this value rather than the browser's stored one, so a choice saved on another
     * device wins over an old one here.
     */
    protected function locale(Request $request): string
    {
        $user = $request->user();

        return ($user === null || $user->role === 'member') ? UserLocale::resolve($user, $request) : (string) config('app.locale');
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
