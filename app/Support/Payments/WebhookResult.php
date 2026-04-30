<?php

namespace App\Support\Payments;

final readonly class WebhookResult
{
    public function __construct(
        public ?string $billCode,
        public ?string $reference,
        public bool $paid,
        public ?string $transactionId,
        public string $gateway,
        public array $rawPayload,
    ) {}
}
