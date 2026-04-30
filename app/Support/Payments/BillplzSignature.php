<?php

namespace App\Support\Payments;

/**
 * Source-string construction for Billplz X-Signature verification.
 *
 * Billplz computes HMAC-SHA256 of a deterministic string built from the
 * payload's keys (sorted alphabetically), excluding the signature itself,
 * concatenated as `key1value1|key2value2|…`. Centralising the algorithm
 * here keeps production and test code in sync.
 */
final class BillplzSignature
{
    /**
     * Build the source string for HMAC computation.
     *
     * Excludes `x_signature` (the field we're validating) from the payload.
     */
    public static function source(array $payload): string
    {
        $payload = array_filter(
            $payload,
            fn ($k) => $k !== 'x_signature',
            ARRAY_FILTER_USE_KEY,
        );
        ksort($payload);

        $parts = [];
        foreach ($payload as $k => $v) {
            $parts[] = $k.(is_scalar($v) ? (string) $v : json_encode($v));
        }

        return implode('|', $parts);
    }

    public static function compute(array $payload, string $key): string
    {
        return hash_hmac('sha256', self::source($payload), $key);
    }
}
