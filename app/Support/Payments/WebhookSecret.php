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
    /** Our own HMAC secrets (TOYYIBPAY_WEBHOOK_SECRET) must be at least this long. */
    public const MIN_LENGTH = 32;

    /**
     * $minLength is MIN_LENGTH for secrets we generate; a key issued by the
     * gateway (Billplz x_signature) has the gateway's own format, so its
     * caller passes 1 and only placeholders and blanks are refused.
     */
    public static function isUsable(?string $secret, int $minLength = self::MIN_LENGTH): bool
    {
        $secret = trim((string) $secret);

        if ($secret === '' || mb_strlen($secret) < max(1, $minLength)) {
            return false;
        }

        return ! (str_starts_with($secret, 'your_') && str_ends_with($secret, '_here'));
    }
}
