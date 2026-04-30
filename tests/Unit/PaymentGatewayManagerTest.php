<?php

namespace Tests\Unit;

use App\Models\Club;
use App\Services\PaymentGateways\BillplzGateway;
use App\Services\PaymentGateways\PaymentGatewayManager;
use App\Services\PaymentGateways\ToyyibPayGateway;
use Tests\TestCase;

class PaymentGatewayManagerTest extends TestCase
{
    public function test_resolves_toyyibpay_when_club_has_toyyibpay_active(): void
    {
        $club = Club::factory()->create(['payment_gateway' => 'toyyibpay']);

        $gateway = app(PaymentGatewayManager::class)->for($club);

        $this->assertInstanceOf(ToyyibPayGateway::class, $gateway);
        $this->assertSame('toyyibpay', $gateway->name());
    }

    public function test_resolves_billplz_when_club_has_billplz_active(): void
    {
        $club = Club::factory()->create(['payment_gateway' => 'billplz']);

        $gateway = app(PaymentGatewayManager::class)->for($club);

        $this->assertInstanceOf(BillplzGateway::class, $gateway);
        $this->assertSame('billplz', $gateway->name());
    }

    public function test_falls_back_to_toyyibpay_when_payment_gateway_is_empty(): void
    {
        // Defensive: if a row somehow has an empty string in payment_gateway
        // (legacy or external write), the manager must still resolve a driver.
        $club = Club::factory()->create();
        $club->forceFill(['payment_gateway' => ''])->saveQuietly();
        $club->refresh();

        $gateway = app(PaymentGatewayManager::class)->for($club);

        $this->assertInstanceOf(ToyyibPayGateway::class, $gateway);
    }
}
