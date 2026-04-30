<?php

namespace App\Support\Payments;

final readonly class PaymentBillResult
{
    public function __construct(
        public string $billCode,
        public string $paymentUrl,
        public string $gateway,
    ) {}
}
