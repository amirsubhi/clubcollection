<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Apply security headers to every web response
        // SecurityHeaders (global, below) runs before HandleInertiaRequests, so the
        // CSP nonce (and Vite::useCspNonce) is set before an Inertia page renders.
        // HandleInertiaRequests passes plain Blade responses through untouched.
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
        ]);
        // Security headers (and the CSP nonce) on every response, outermost, so
        // the error pages thrown before the web group runs (404 for an unknown
        // URL, 503 maintenance, 413 upload too large) get the same CSP and can
        // style themselves with the nonce (resources/views/errors).
        $middleware->prepend(\App\Http\Middleware\SecurityHeaders::class);

        // Trusted proxies (TRUSTED_PROXIES) are applied in AppServiceProvider
        // from config('app.trusted_proxies'): this callback runs before .env
        // is loaded, so env() here would only see real environment variables.

        // Only APP_URL's host (and its subdomains) may be used as Host, so links
        // and gateway callback URLs can't be pointed elsewhere. Laravel skips
        // this check in local and in tests.
        $middleware->trustHosts();

        // cp_lang only holds a language code ("bm", "en", ...) and is written by the member app's JavaScript too.
        $middleware->encryptCookies(except: [\App\Http\Middleware\AuthPageLocale::COOKIE]);

        $middleware->alias([
            'auth_locale'           => \App\Http\Middleware\AuthPageLocale::class,
            'super_admin'           => \App\Http\Middleware\SuperAdmin::class,
            'club_admin'            => \App\Http\Middleware\ClubAdmin::class,
            'member'                => \App\Http\Middleware\MemberOnly::class,
            'check_installed'       => \App\Http\Middleware\CheckInstalled::class,
            'redirect_if_installed' => \App\Http\Middleware\RedirectIfInstalled::class,
            'two_factor'            => \App\Http\Middleware\RequiresTwoFactor::class,
            'password_changed'      => \App\Http\Middleware\EnsurePasswordChanged::class,
            'admin_two_factor'      => \App\Http\Middleware\RequireAdminTwoFactor::class,
            'adopt_lang'            => \App\Http\Middleware\AdoptBrowserLanguage::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // A typed URL (GET/HEAD) that exists only for other methods, e.g. /admin/members/{id}
        // after its show route was removed, is a page that doesn't exist: 404, not 405.
        // Trade-off: a GET link wrongly pointing at a POST-only route also shows a 404 in production,
        // so locally the 405 is kept to make such mistakes visible during development.
        $exceptions->map(function (\Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException $e) {
            $typedUrl = request()->isMethod('GET') || request()->isMethod('HEAD');

            return $typedUrl && ! app()->isLocal()
                ? new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException('Not Found', $e)
                : $e;
        });
    })->create();
