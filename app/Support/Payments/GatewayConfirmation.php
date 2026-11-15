<?php

namespace App\Support\Payments;

/**
 * What a gateway's own API says about a bill (S1): the largest successful
 * payment in sen and how many successful payments it has. Used before
 * settling a fee and before writing any refund flag, so a leaked callback
 * token can't create either on its own.
 */
final readonly class GatewayConfirmation
{
    public function __construct(
        public int $amountSen,
        public int $successCount,
    ) {}

    public function paid(): bool
    {
        return $this->successCount > 0 && $this->amountSen > 0;
    }
}
