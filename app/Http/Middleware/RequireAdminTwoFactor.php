<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * With config('security.require_admin_two_factor') (REQUIRE_ADMIN_2FA, default on), a super admin or club
 * admin without 2FA can only use the Security page until 2FA is confirmed: its setup and confirm steps (the
 * recovery codes are shown there once), the change-password page and logout. Everything else goes to the
 * Security page (Inertia::location, so Inertia visits get a full page load). Members are never forced.
 * Runs after two_factor and password_changed.
 */
class RequireAdminTwoFactor
{
    public const ALLOWED_ROUTES = [
        'profile.security', 'profile.2fa.enable', 'profile.2fa.confirm',
        'password.change', 'password.change.update', 'logout',
    ];

    /** Does the rule apply to this user right now (an admin, 2FA not yet on, the setting on)? */
    public static function applies(?User $user): bool
    {
        return $user !== null
            && config('security.require_admin_two_factor')
            && $user->isAdmin()
            && ! $user->hasEnabledTwoFactor();
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (self::applies($request->user()) && ! $request->routeIs(...self::ALLOWED_ROUTES)) {
            return Inertia::location(route('profile.security'));
        }

        return $next($request);
    }
}
