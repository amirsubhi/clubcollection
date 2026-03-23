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
        $middleware->web(append: [
            \App\Http\Middleware\SecurityHeaders::class,
        ]);

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
