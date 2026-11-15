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
        // HandleInertiaRequests comes after SecurityHeaders so the CSP nonce
        // (and Vite::useCspNonce) is set before an Inertia page renders. It
        // passes plain Blade responses through untouched.
        $middleware->web(append: [
            \App\Http\Middleware\SecurityHeaders::class,
            \App\Http\Middleware\HandleInertiaRequests::class,
        ]);

        // Trusted proxies (TRUSTED_PROXIES) are applied in AppServiceProvider
        // from config('app.trusted_proxies'): this callback runs before .env
        // is loaded, so env() here would only see real environment variables.

        // Only APP_URL's host (and its subdomains) may be used as Host, so links
        // and gateway callback URLs can't be pointed elsewhere. Laravel skips
        // this check in local and in tests.
        $middleware->trustHosts();

        // cp_lang only holds "bm" or "en" and is written by the member app's JavaScript too.
        $middleware->encryptCookies(except: [\App\Http\Middleware\AuthPageLocale::COOKIE]);

        $middleware->alias([
            'auth_locale'           => \App\Http\Middleware\AuthPageLocale::class,
            'super_admin'           => \App\Http\Middleware\SuperAdmin::class,
            'club_admin'            => \App\Http\Middleware\ClubAdmin::class,
            'member'                => \App\Http\Middleware\MemberOnly::class,
            'check_installed'       => \App\Http\Middleware\CheckInstalled::class,
            'redirect_if_installed' => \App\Http\Middleware\RedirectIfInstalled::class,
            'two_factor'            => \App\Http\Middleware\RequiresTwoFactor::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
