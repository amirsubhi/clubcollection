<?php

namespace App\Providers;

use App\Models\Club;
use App\Services\PaymentGateways\PaymentGatewayManager;
use App\Support\Auth\AccountLoginBlock;
use App\Support\Contact\WhatsAppNumber;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\NotPwnedVerifier;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PaymentGatewayManager::class);

        // The breached-password check (Password::uncompromised(), Have I Been Pwned's k-anonymity range API: only
        // the first 5 characters of the SHA-1 leave the server) passes when the API can't be reached, so an
        // offline host never blocks a password reset. 5 s instead of the framework's 30 s keeps that wait short.
        // extend(), not singleton(): ValidationServiceProvider is deferred and would replace a binding when it loads.
        $this->app->extend(UncompromisedVerifier::class, fn ($verifier, $app) => new NotPwnedVerifier($app[HttpFactory::class], 5));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Views use Bootstrap 5; the default Tailwind pagination renders unstyled.
        Paginator::useBootstrapFive();

        // New passwords (reset, change): 12+ characters and not in a known breach. No "mixed case / symbol"
        // rules (NIST SP 800-63B): a few ordinary words together is long, strong and easy to remember.
        Password::defaults(fn () => Password::min(12)->uncompromised());

        // Named limiters get their own counters. A plain throttle:N,M on guest
        // routes shares one per-IP counter across every such route, so gateway
        // callbacks would eat into the login limit (and the other way round).
        // Webhooks: 300/min per IP. A gateway sends every club's callbacks from
        // a few IPs, and a busy collection day (or retries after an outage)
        // must never be cut off; the limit only stops a flood from one source.
        RateLimiter::for('webhooks', fn (Request $request) => Limit::perMinute(300)->by('webhook|'.$request->ip()));
        RateLimiter::for('password-reset', fn (Request $request) => Limit::perMinute(5)->by('password|'.$request->ip()));
        // Turning 2FA on checks the password (and a TOTP code): 5 tries a minute per user, its own bucket.
        RateLimiter::for('two-factor-setup', fn (Request $request) => Limit::perMinute(5)->by('2fa-setup|'.($request->user()?->id ?? $request->ip())));

        // Sign-in (POST /login): 5 a minute per account (or unknown email) + IP, and 30 a minute per IP. A staff club often signs in
        // from one office network (one public IP), so the per-IP ceiling is high enough for many colleagues at
        // once; the per-email limit stops guessing one account. On top of that, LoginController pauses an
        // account after 10 wrong passwords from any IP (App\Support\Auth\AccountLoginBlock). A hit limit is
        // audited (Lockout event) and answered with the friendly 429 page.
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(30)->by('login-ip|'.$request->ip())->response(self::loginLimitResponse(...)),
            Limit::perMinute(5)->by('login|'.AccountLoginBlock::accountKey(is_string($email = $request->input('email')) ? $email : '').'|'.$request->ip())
                ->response(self::loginLimitResponse(...)),
        ]);
        // The 2FA challenge POST and the installer each have their own bucket (they used to share the
        // unnamed throttle:5,1 counter with sign-in). The challenge: 5 a minute per pending account (the
        // session's two_factor_user_id) + IP, 30 a minute per IP (admins in one office share an IP); it also
        // locks per account after 5 wrong codes (TwoFactorChallengeController).
        RateLimiter::for('two-factor', fn (Request $request) => [
            Limit::perMinute(30)->by('2fa-ip|'.$request->ip()),
            Limit::perMinute(5)->by('2fa|'.(int) ($request->hasSession() ? $request->session()->get('two_factor_user_id') : 0).'|'.$request->ip()),
        ]);
        // Change password checks the current one: 5 tries a minute per user.
        RateLimiter::for('password-change', fn (Request $request) => Limit::perMinute(5)->by('password-change|'.($request->user()?->id ?? $request->ip())));
        RateLimiter::for('install', fn (Request $request) => Limit::perMinute(5)->by('install|'.$request->ip()));

        self::configureTrustedProxies();

        // Sign-in pages: the "Need help?" section names a club contact only when exactly one active club exists
        // (a guest's club is unknown, and listing several clubs' contacts would hand them to anyone).
        // auth.login too: its "how to join" section is rendered before the layout, so the layout's data is not there yet.
        View::composer(['layouts.auth', 'auth.login'], function ($view) {
            // Error pages use this layout too, and must render without a database: no contact then.
            $clubs = rescue(fn () => Club::query()->where('is_active', true)->limit(2)->get(['id', 'name', 'email', 'whatsapp_number']), collect(), false);
            $club = $clubs->count() === 1 ? $clubs->first() : null;
            $wa = $club ? WhatsAppNumber::waDigits($club->whatsapp_number) : null;
            $view->with('authHelpClub', $club && ($wa || $club->email)
                ? ['name' => $club->name, 'whatsapp' => $wa, 'email' => $wa ? null : $club->email]
                : null);
        });

        // A fresh install still says APP_NAME=Laravel: members should see the product name instead.
        if (in_array(config('app.name'), [null, '', 'Laravel'], true)) {
            config(['app.name' => 'Club Portal']);
        }

        if ($this->app->isProduction()) {
            self::pinRootUrl();
        }
    }

    /**
     * A sign-in limit was hit: record a Lockout (audit log), then answer with the usual "Too many attempts,
     * please wait about N seconds" page (resources/views/errors/429, BM/EN, sign-in styling). One Lockout per
     * IP per wait, however many more posts arrive, so a flood can't fill the audit log.
     */
    public static function loginLimitResponse(Request $request, array $headers): never
    {
        $seconds = max(1, (int) ($headers['Retry-After'] ?? 60));
        if (Cache::add('login-lockout-audited|'.$request->ip(), true, $seconds)) {
            event(new Lockout($request));
        }

        throw new ThrottleRequestsException('Too Many Attempts.', null, $headers);
    }

    /**
     * Trust X-Forwarded-For/Host/Port/Proto only from the proxies listed in
     * config('app.trusted_proxies') (TRUSTED_PROXIES), so request()->ip(),
     * rate limits and audit-log IPs see the real client behind them. Empty
     * (the default) trusts nothing. Read from config, not env(), so it works
     * from .env and with a cached config.
     */
    public static function configureTrustedProxies(): void
    {
        $proxies = trim((string) config('app.trusted_proxies', ''));
        if ($proxies === '') {
            TrustProxies::flushState();

            return;
        }

        TrustProxies::at($proxies === '*' ? '*' : array_values(array_filter(array_map('trim', explode(',', $proxies)))));
        TrustProxies::withHeaders(
            Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PROTO
        );
    }

    /**
     * Build every absolute URL (gateway callback and return URLs, email
     * links) from APP_URL and its scheme, never from the request's Host or
     * X-Forwarded-Host, which a client can forge (S1). https is strongly
     * recommended (the installer warns otherwise), but an http APP_URL is
     * followed rather than silently turned into links that don't work.
     */
    public static function pinRootUrl(): void
    {
        $root = rtrim((string) config('app.url'), '/');
        if ($root === '') {
            return;
        }
        URL::forceRootUrl($root);
        $scheme = strtolower((string) parse_url($root, PHP_URL_SCHEME));
        URL::forceScheme(in_array($scheme, ['http', 'https'], true) ? $scheme : 'https');
    }
}
