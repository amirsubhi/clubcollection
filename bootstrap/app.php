<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Apply security headers to every web response
        $middleware->web(append: [
            \App\Http\Middleware\SecurityHeaders::class,
        ]);

        // Trust the X-Forwarded-* headers from the reverse proxy/load
        // balancer in front of the app so request()->ip(), $request->secure()
        // and rate limiting all see the real client IP/scheme. Configure the
        // proxy address(es) via TRUSTED_PROXIES in .env (use '*' only when
        // your edge strips client-supplied X-Forwarded-* headers).
        $proxies = env('TRUSTED_PROXIES', '');
        if ($proxies !== '') {
            $middleware->trustProxies(
                at: $proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)),
                headers: Request::HEADER_X_FORWARDED_FOR
                       | Request::HEADER_X_FORWARDED_HOST
                       | Request::HEADER_X_FORWARDED_PORT
                       | Request::HEADER_X_FORWARDED_PROTO,
            );
        }

        $middleware->alias([
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
