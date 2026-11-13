<?php

namespace App\Support\Payments;

/**
 * Guards webhook verification against unusable secrets.
 *
 * `.env.example` ships placeholder values (e.g. `your_webhook_secret_here`).
 * Those strings are public, so accepting them as a real secret would let
 * anyone forge a "paid" webhook. Treat them exactly like a missing secret.
 */
final class WebhookSecret
{
    public static function isUsable(?string $secret): bool
    {
        $secret = trim((string) $secret);

        if ($secret === '') {
            return false;
        }

        return ! (str_starts_with($secret, 'your_') && str_ends_with($secret, '_here'));
    }
}
