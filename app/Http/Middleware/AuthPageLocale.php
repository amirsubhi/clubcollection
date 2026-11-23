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
 * UserLocale::resolve() decides. A guest (and an admin): the browser's choice (?lang=bm|en from the page's BM/EN
 * links, else the cp_lang cookie, which the member app also writes when its switch changes), else the portal's
 * default language. A signed-in member: the language saved on the account, else the portal default; ?lang= and the
 * cookie are ignored and nothing is saved from them. Their switch is a small POST form to member.language.update
 * instead (layouts/auth.blade.php, $authLangForm), the one way an account's language is written.
 * The cookie is not encrypted (see bootstrap/app.php): it only ever holds a language code. Values are read through
 * UiLang::normalise() ("bm" = "ms"); the page gets the code ("ms" / "en") as $authLang, and the cookie is still
 * written as "bm" (UiLang::stored()).
 */
class AuthPageLocale
{
    public const COOKIE = 'cp_lang';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $member = $user !== null && $user->role === 'member';
        $lang = UserLocale::resolve($user, $request);

        App::setLocale($lang);
        View::share('authLang', $lang);
        // A signed-in member switches with a form that saves on the account (not ?lang= links).
        View::share('authLangForm', $member);
        // Chosen on purpose (the switch, an earlier cookie, or the member's saved language): the page also copies
        // it to the member app's stored choice. A portal default is never written down as a choice.
        View::share('authLangChosen', $member ? UserLocale::saved($user) !== null : UserLocale::browserChoice($request) !== null);

        $response = $next($request);

        // The browser's choice is remembered for the next sign-in page; a signed-in member's ?lang= is ignored.
        $asked = $member ? null : UiLang::normalise($request->query('lang'));
        if ($asked !== null) {
            $response->headers->setCookie(Cookie::make(self::COOKIE, UiLang::stored($asked), 60 * 24 * 365, '/', null, $request->secure(), false, false, 'lax'));
        }

        return $response;
    }
}
