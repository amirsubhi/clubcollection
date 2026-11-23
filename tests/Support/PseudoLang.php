<?php

namespace Tests\Support;

/**
 * The pseudo-language "xx" (scripts/i18n-pseudo.php): English with accented letters, each text in [ ] and padded
 * about 40% longer, placeholders and markers kept. Test and dev copies only, never shipped.
 */
final class PseudoLang
{
    public const META = ['name' => '[Pseudo]', 'label' => 'XX', 'htmlLang' => 'en-XA', 'localeTag' => 'en-XA', 'beta' => true];

    /** Placeholders and markers a translation must keep as they are. */
    private const KEEP = '/(\{\w+\}|:[A-Za-z_]+|\*\*)/';

    private const ACCENTS = [
        'a' => 'à', 'e' => 'é', 'i' => 'ï', 'o' => 'ô', 'u' => 'ü', 'y' => 'ý', 'c' => 'ç', 'n' => 'ñ',
        'A' => 'Å', 'E' => 'É', 'I' => 'Ï', 'O' => 'Ö', 'U' => 'Ü', 'Y' => 'Ý', 'C' => 'Ç', 'N' => 'Ñ',
    ];

    /** "Pay {x}" -> "[Pàý {x} ~~~]"; each "|" plural form on its own. */
    public static function text(string $english): string
    {
        return implode('|', array_map(self::form(...), explode('|', $english)));
    }

    private static function form(string $text): string
    {
        $parts = preg_split(self::KEEP, $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text];
        $out = '';
        foreach ($parts as $i => $part) {
            $out .= $i % 2 === 1 ? $part : strtr($part, self::ACCENTS);
        }
        $pad = (int) ceil(mb_strlen($text) * 0.4);

        return '['.$out.' '.str_repeat('~', max(1, $pad)).']';
    }

    /**
     * Laravel's PHP lines for the keys of a Malay file, from Laravel's English (attribute names: the key itself).
     *
     * @param  array<string, mixed>  $malay
     * @param  array<string, mixed>  $english
     * @return array<string, mixed>
     */
    public static function lines(array $malay, array $english): array
    {
        $out = [];
        foreach ($malay as $key => $value) {
            if (is_array($value)) {
                $out[$key] = self::lines($value, is_array($english[$key] ?? null) ? $english[$key] : []);
            } else {
                $out[$key] = self::text(is_string($english[$key] ?? null) ? $english[$key] : (string) $key);
            }
        }

        return $out;
    }
}
