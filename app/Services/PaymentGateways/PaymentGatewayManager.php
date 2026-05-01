<?php

namespace App\Services\PaymentGateways;

use App\Contracts\PaymentGateway;
use App\Models\Club;
use Illuminate\Support\Manager;

class PaymentGatewayManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return 'toyyibpay';
    }

    /**
     * Resolve the gateway driver for a given club.
     */
    public function for(Club $club): PaymentGateway
    {
        return $this->driver($club->activeGateway());
    }

    protected function createToyyibpayDriver(): ToyyibPayGateway
    {
        return $this->container->make(ToyyibPayGateway::class);
    }

    protected function createBillplzDriver(): BillplzGateway
    {
        return $this->container->make(BillplzGateway::class);
    }
}
