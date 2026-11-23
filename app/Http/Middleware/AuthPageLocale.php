<?php

namespace App\Http\Middleware;

use App\Support\UiLang;
use App\Support\UserLocale;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Language of the sign-in pages (login, two-factor challenge, password reset and change, privacy notice).
 *
 * UserLocale::resolve() decides: a signed-in account's saved language, else the browser's choice (?lang=bm|en from
 * the page's BM/EN switch, else the cp_lang cookie, which the member app also writes when its switch changes, so
 * both sides stay in step), else the portal's default language (Malay unless changed on Portal settings). A switch
 * clicked while signed in (the change-password page) is saved on the account like the member app's switch.
 * The cookie is not encrypted (see bootstrap/app.php): it only ever holds a language code. Values are read through
 * UiLang::normalise() ("bm" = "ms"); the page gets the code ("ms" / "en") as $authLang, and the cookie is still
 * written as "bm" (UiLang::stored()).
 */
class AuthPageLocale
{
    public const COOKIE = 'cp_lang';

    public function handle(Request $request, Closure $next): Response
    {
        $asked = UiLang::normalise($request->query('lang'));
        $user = $request->user();
        if ($asked !== null && $user !== null) {
            UserLocale::save($user, $asked);
        }
        $lang = UserLocale::resolve($user, $request);

        App::setLocale($lang);
        View::share('authLang', $lang);
        // Chosen on purpose (switch, earlier cookie or a saved language): the page also copies it to the member
        // app's saved choice. A cookie holding anything else is ignored, so it does not count as a choice.
        View::share('authLangChosen', UserLocale::saved($user) !== null || UserLocale::browserChoice($request) !== null);

        $response = $next($request);

        if ($asked !== null) {
            $response->headers->setCookie(Cookie::make(self::COOKIE, UiLang::stored($asked), 60 * 24 * 365, '/', null, $request->secure(), false, false, 'lax'));
        }

        return $response;
    }
}
