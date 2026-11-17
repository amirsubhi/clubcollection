<?php

namespace App\Support\Admin;

use Illuminate\Http\Request;

/**
 * Whether a 403/404 should use the admin-styled error page (errors/_admin): a signed-in admin or
 * super admin on an /admin URL whose session would pass the admin routes' own checks: two-factor done
 * when enabled (RequiresTwoFactor) and the session's password hash still current (AuthenticateSession,
 * stricter: a session without the hash is refused). Needs the session (web middleware; unknown /admin URLs
 * reach it through the "fallback.admin" route), so errors raised before it, or for anyone else, keep the
 * branded BM page, which shows nothing about the portal.
 */
final class AdminErrorPage
{
    public static function applies(Request $request): bool
    {
        try {
            if (! $request->is('admin', 'admin/*') || ! $request->hasSession() || ! $request->session()->isStarted()) {
                return false;
            }
            $user = $request->user();
            if (! $user || ! $user->isAdmin()) {
                return false;
            }
            if ($user->hasEnabledTwoFactor() && ! $request->session()->get('two_factor_verified')) {
                return false;
            }
            $stored = $request->session()->get('password_hash_'.auth()->getDefaultDriver());
            $hash = (string) $user->getAuthPassword();

            return is_string($stored) && $hash !== ''
                && (hash_equals($hash, $stored) || hash_equals(auth()->guard()->hashPasswordForCookie($hash), $stored));
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * The admin-styled page as HTML, or null for the branded page: when applies() is false, or when the
     * admin layout cannot render (e.g. the database behind the sidebar is down).
     *
     * @param  array{code: int, title: string, body: string}  $data
     */
    public static function render(Request $request, array $data): ?string
    {
        if (! self::applies($request)) {
            return null;
        }
        try {
            AdminNav::forRequest($request);

            return view('errors._admin', $data)->render();
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }
}
