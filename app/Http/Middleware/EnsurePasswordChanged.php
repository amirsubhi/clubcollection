<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * An account created with an emailed temporary password (users.must_change_password) must choose its own
 * password before anything else. Runs after auth + two_factor. Only the change-password page itself, its language
 * switch (member.language.update, so a new member can read that page in their language) and signing out are
 * allowed. Inertia visits get a 409 + X-Inertia-Location (Inertia::location), so the
 * browser does a full page load of the Blade page instead of a broken XHR redirect.
 */
class EnsurePasswordChanged
{
    public const ALLOWED_ROUTES = ['password.change', 'password.change.update', 'member.language.update', 'logout'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->must_change_password && ! $request->routeIs(...self::ALLOWED_ROUTES)) {
            return Inertia::location(route('password.change'));
        }

        return $next($request);
    }
}
