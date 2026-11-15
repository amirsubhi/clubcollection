<?php

namespace App\Contracts;

use App\Support\Payments\GatewayConfirmation;

/**
 * A gateway whose "paid" callback is confirmed with its own API before the
 * fee is settled (S1). A callback URL token can leak; the API answer can't
 * be forged by whoever holds it.
 */
interface ConfirmsWebhookPayments
{
    /** Whether paid callbacks must be confirmed before settling. */
    public function confirmsCallbacks(): bool;

    /**
     * The successful payments the gateway reports for the bill (largest amount
     * in sen and count; both 0 when there are none), or null when it could not
     * be asked (unreachable, error or an unreadable answer), so the caller
     * fails closed and lets the gateway retry.
     */
    public function confirmPayment(string $billCode): ?GatewayConfirmation;
}
