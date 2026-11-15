<?php

namespace App\Providers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

/**
 * End-to-end smoke test support (tests/e2e/smoke.mjs). Does nothing unless
 * APP_ENV is exactly "local" AND the process environment has
 * E2E_FAKE_GATEWAY=1, so it can never activate in production.
 *
 * When active:
 *  - ToyyibPay bill creation (createBill) is answered by Http::fake with a
 *    random bill code, so no real gateway is called;
 *  - E2E_PRIVATE_ROOT (optional) moves the private "local" disk, so test
 *    uploads stay out of storage/app/private.
 */
class E2eFakeGatewayServiceProvider extends ServiceProvider
{
    public static function shouldActivate(string $environment, mixed $flag): bool
    {
        return $environment === 'local' && (string) $flag === '1';
    }

    public function boot(): void
    {
        if ($this->app->isProduction() || ! self::shouldActivate((string) $this->app->environment(), env('E2E_FAKE_GATEWAY'))) {
            return;
        }

        Http::fake([
            '*/index.php/api/createBill' => fn () => Http::response([['BillCode' => 'e2e'.Str::lower(Str::random(8))]]),
        ]);

        $root = env('E2E_PRIVATE_ROOT');
        if (is_string($root) && $root !== '') {
            config(['filesystems.disks.local.root' => $root]);
        }
    }
}
