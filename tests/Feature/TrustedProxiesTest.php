<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * TRUSTED_PROXIES is read through config('app.trusted_proxies') and applied in
 * AppServiceProvider, so it works from .env (bootstrap/app.php runs before
 * .env is loaded) and with a cached config.
 */
class TrustedProxiesTest extends TestCase
{
    protected function tearDown(): void
    {
        TrustProxies::flushState();
        parent::tearDown();
    }

    private function clientIp(): string
    {
        $request = Request::create('/', 'GET', server: [
            'REMOTE_ADDR' => '10.0.0.5',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.9',
        ]);

        return app(TrustProxies::class)->handle($request, fn (Request $r) => $r)->ip();
    }

    public function test_no_proxy_is_trusted_by_default(): void
    {
        config(['app.trusted_proxies' => '']);
        AppServiceProvider::configureTrustedProxies();

        $this->assertSame('10.0.0.5', $this->clientIp());
    }

    public function test_a_listed_proxy_is_trusted(): void
    {
        config(['app.trusted_proxies' => '192.0.2.1, 10.0.0.0/8']);
        AppServiceProvider::configureTrustedProxies();

        $this->assertSame('203.0.113.9', $this->clientIp());
    }

    public function test_an_unlisted_proxy_is_not_trusted(): void
    {
        config(['app.trusted_proxies' => '192.0.2.1']);
        AppServiceProvider::configureTrustedProxies();

        $this->assertSame('10.0.0.5', $this->clientIp());
    }

    public function test_the_value_comes_from_config(): void
    {
        $this->assertArrayHasKey('trusted_proxies', config('app'));
    }
}
