<?php

namespace App\Support\Contact;

/**
 * The club's optional WhatsApp help number (clubs.whatsapp_number).
 *
 * Stored in E.164 ("+60123456789"). Admins may type it the Malaysian way
 * ("012-345 6789", "03-1234 5678"), with the country code ("60 12 345 6789",
 * "+60 12-345 6789", "0060…") or as any other E.164 number ("+65 9123 4567").
 * Spaces, dashes, dots and brackets are ignored.
 */
final class WhatsAppNumber
{
    /**
     * The number in E.164, or null when it is not a valid phone number.
     */
    public static function normalise(?string $input): ?string
    {
        $raw = trim((string) $input);
        if ($raw === '') {
            return null;
        }
        $compact = preg_replace('/[\s\-.()\x{00A0}]/u', '', $raw) ?? '';
        if (str_starts_with($compact, '00')) {
            $compact = '+'.substr($compact, 2);
        }

        if (str_starts_with($compact, '+')) {
            $digits = substr($compact, 1);
            if (! preg_match('/^[1-9]\d{7,14}$/', $digits)) {
                return null;
            }

            return str_starts_with($digits, '60') ? self::malaysian(substr($digits, 2)) : '+'.$digits;
        }

        if (! preg_match('/^\d+$/', $compact)) {
            return null;
        }
        if (str_starts_with($compact, '0')) {
            return self::malaysian(substr($compact, 1));
        }
        if (str_starts_with($compact, '60')) {
            return self::malaysian(substr($compact, 2));
        }

        return null;
    }

    /** The digits wa.me expects (no "+"), or null. */
    public static function waDigits(?string $e164): ?string
    {
        return $e164 !== null && preg_match('/^\+([1-9]\d{7,14})$/', $e164, $m) ? $m[1] : null;
    }

    /** "+60 12-345 6789" style, for showing the stored number back to admins. */
    public static function display(?string $e164): string
    {
        if ($e164 === null || $e164 === '') {
            return '';
        }
        if (preg_match('/^\+60(1\d)(\d{3,4})(\d{4})$/', $e164, $m)) {
            return "+60 {$m[1]}-{$m[2]} {$m[3]}";
        }

        return $e164;
    }

    /**
     * Malaysian national number (after 60; a stray trunk 0 is dropped): mobile 1x + 7–8 digits,
     * or a fixed line 3–9 + 7–8 digits. Toll-free 1-800 / 1-300 / 1-700 numbers are refused.
     */
    private static function malaysian(string $national): ?string
    {
        // "+60 012-…": a trunk 0 typed after the country code is dropped.
        if (str_starts_with($national, '0')) {
            $national = substr($national, 1);
        }
        // Toll-free / shared-cost lines (1-800, 1-300, 1-700: 10 digits) cannot take WhatsApp.
        // Mobiles 013 / 017 / 018 have 9 digits, so "013-007 1234" is still fine.
        if (preg_match('/^1[378]00\d{6}$/', $national)) {
            return null;
        }

        return preg_match('/^(1\d{8,9}|[3-9]\d{7,8})$/', $national) ? '+60'.$national : null;
    }
}
