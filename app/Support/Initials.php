<?php

namespace App\Support;

/**
 * Avatar initials, one rule for the Blade admin pages and the React ones
 * (AdminNav sends them as a prop; Visuals.tsx initials() mirrors this for
 * other names): the first letters of the first two words, skipping the
 * Malay/Indian patronymic links bin, binti, a/l and a/p.
 * "Siti Aminah binti Hassan" → "SA", "Lim Siew Ling" → "LS".
 */
final class Initials
{
    public static function of(string $name): string
    {
        $words = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words = array_values(array_filter($words, fn (string $w) => ! in_array(mb_strtolower($w), ['bin', 'binti', 'a/l', 'a/p'], true)));

        $first = isset($words[0]) ? mb_substr($words[0], 0, 1) : '';
        $second = isset($words[1]) ? mb_substr($words[1], 0, 1) : '';

        return mb_strtoupper($first.$second);
    }
}
