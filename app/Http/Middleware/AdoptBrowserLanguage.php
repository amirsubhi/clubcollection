<?php

namespace App\Http\Middleware;

use App\Support\UserLocale;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signed-in requests: an account with no saved language takes the one its browser already chose (the cp_lang
 * cookie), once (UserLocale::adoptBrowserChoice). People who picked English before languages were saved keep
 * English, emails included, instead of the portal default.
 */
class AdoptBrowserLanguage
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($user = $request->user()) {
            UserLocale::adoptBrowserChoice($user, $request);
        }

        return $next($request);
    }
}
