<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Language of the sign-in pages (login, two-factor challenge, password reset).
 *
 * Bahasa Melayu by default, English on request. The choice comes from ?lang=bm|en
 * (the BM/EN switch on the page), else the cp_lang cookie, which the member app
 * also writes when its BM/EN toggle changes, so both sides stay in step. The
 * cookie is not encrypted (see bootstrap/app.php): it only ever holds "bm" or "en".
 */
class AuthPageLocale
{
    public const COOKIE = 'cp_lang';

    public function handle(Request $request, Closure $next): Response
    {
        $asked = self::normalise($request->query('lang'));
        $lang = $asked ?? self::normalise($request->cookie(self::COOKIE)) ?? 'bm';

        App::setLocale($lang === 'en' ? 'en' : 'ms');
        View::share('authLang', $lang);
        // Chosen on purpose (switch or earlier cookie): the page also copies it to the member app's saved choice.
        View::share('authLangChosen', $asked !== null || $request->cookie(self::COOKIE) !== null);

        $response = $next($request);

        if ($asked !== null) {
            $response->headers->setCookie(Cookie::make(self::COOKIE, $asked, 60 * 24 * 365, '/', null, $request->secure(), false, false, 'lax'));
        }

        return $response;
    }

    private static function normalise(mixed $value): ?string
    {
        return in_array($value, ['bm', 'en'], true) ? $value : null;
    }
}
