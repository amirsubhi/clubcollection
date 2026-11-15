<?php

namespace App\Support\Payments;

use App\Models\Club;

/**
 * The gateway-fee mode for a bill: who bears the per-bill gateway fee and how
 * much it is expected to be (in sen), taken from the club's settings.
 *
 * - payer 'club':   the club absorbs the fee; the member pays the fee amount only.
 * - payer 'member': the member pays the fee amount plus the gateway fee.
 *   ToyyibPay adds it itself (billChargeToCustomer); Billplz has no such
 *   flag, so BillplzGateway adds it to the bill amount.
 */
final class GatewayFee
{
    public function __construct(
        public readonly string $payer,
        public readonly int $feeSen,
    ) {}

    public static function forClub(Club $club): self
    {
        return new self($club->gatewayFeePayer(), $club->gatewayFeeSen());
    }

    public function memberPays(): bool
    {
        return $this->payer === 'member';
    }

    /** The extra the member pays on top of the fee amount (0 when the club absorbs it). */
    public function memberSurchargeSen(): int
    {
        return $this->memberPays() ? $this->feeSen : 0;
    }

    /** What the member pays in total for a fee amount in sen. */
    public function totalSen(int $amountSen): int
    {
        return $amountSen + $this->memberSurchargeSen();
    }
}
