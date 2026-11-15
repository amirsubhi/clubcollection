<?php

namespace App\Providers;

use App\Services\PaymentGateways\PaymentGatewayManager;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PaymentGatewayManager::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Views use Bootstrap 5; the default Tailwind pagination renders unstyled.
        Paginator::useBootstrapFive();

        // Named limiters get their own counters. A plain throttle:N,M on guest
        // routes shares one per-IP counter across every such route, so gateway
        // callbacks would eat into the login limit (and the other way round).
        // Webhooks: 300/min per IP. A gateway sends every club's callbacks from
        // a few IPs, and a busy collection day (or retries after an outage)
        // must never be cut off; the limit only stops a flood from one source.
        RateLimiter::for('webhooks', fn (Request $request) => Limit::perMinute(300)->by('webhook|'.$request->ip()));
        RateLimiter::for('password-reset', fn (Request $request) => Limit::perMinute(5)->by('password|'.$request->ip()));

        if ($this->app->isProduction()) {
            self::pinRootUrl();
        }
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
