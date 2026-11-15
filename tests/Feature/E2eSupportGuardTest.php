<?php

namespace Tests\Feature;

use App\Providers\E2eFakeGatewayServiceProvider;
use Database\Seeders\E2eSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** The e2e smoke-test helpers must never be usable outside a local machine. */
class E2eSupportGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_fake_gateway_only_activates_for_local_with_explicit_flag(): void
    {
        $this->assertTrue(E2eFakeGatewayServiceProvider::shouldActivate('local', '1'));

        $this->assertFalse(E2eFakeGatewayServiceProvider::shouldActivate('production', '1'));
        $this->assertFalse(E2eFakeGatewayServiceProvider::shouldActivate('staging', '1'));
        $this->assertFalse(E2eFakeGatewayServiceProvider::shouldActivate('testing', '1'));
        $this->assertFalse(E2eFakeGatewayServiceProvider::shouldActivate('local', null));
        $this->assertFalse(E2eFakeGatewayServiceProvider::shouldActivate('local', 'true'));
        $this->assertFalse(E2eFakeGatewayServiceProvider::shouldActivate('local', '0'));
    }

    public function test_provider_does_not_fake_http_in_production_even_with_flag(): void
    {
        putenv('E2E_FAKE_GATEWAY=1');
        $_ENV['E2E_FAKE_GATEWAY'] = $_SERVER['E2E_FAKE_GATEWAY'] = '1';
        $this->app['env'] = 'production';

        try {
            (new E2eFakeGatewayServiceProvider($this->app))->boot();
            Http::preventStrayRequests();

            $this->expectException(\RuntimeException::class);
            Http::post('https://dev.toyyibpay.com/index.php/api/createBill');
        } finally {
            putenv('E2E_FAKE_GATEWAY');
            unset($_ENV['E2E_FAKE_GATEWAY'], $_SERVER['E2E_FAKE_GATEWAY']);
            $this->app['env'] = 'testing';
        }
    }

    public function test_seeder_refuses_to_run_in_production(): void
    {
        $this->app['env'] = 'production';

        try {
            $this->expectException(\RuntimeException::class);
            (new E2eSeeder)->run();
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    public function test_seeder_creates_fixtures_in_testing(): void
    {
        $this->seed(E2eSeeder::class);

        $this->assertDatabaseHas('users', ['email' => E2eSeeder::ADMIN_A_EMAIL, 'role' => 'admin']);
        $this->assertDatabaseHas('users', ['email' => E2eSeeder::ADMIN_B_EMAIL, 'role' => 'admin']);
        $this->assertDatabaseHas('payments', ['status' => 'pending', 'amount' => 50.00]);
    }
}
