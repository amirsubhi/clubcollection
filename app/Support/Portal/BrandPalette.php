<?php

namespace App\Support\Portal;

/**
 * The portal's brand colour (settings phase 2): one of a fixed list of presets, chosen by the super admin on
 * Portal settings. Only the preset KEY is stored (portal_settings.brand); a free colour or CSS is never accepted,
 * and every colour that reaches a page comes from the constants below.
 *
 * A preset sets the hue-bound tokens of tokens.css for light and dark (primary, ring, deep, accent, info and their
 * foregrounds) plus the Bootstrap comma triplets bootstrap-theme.css writes out by hand. Everything else (the
 * neutrals, success / warning / danger, the categorical chart colours) is shared by every preset, so status
 * colours and chart series stay distinct whatever the brand. 'lavender' is tokens.css itself: choosing it (or
 * nothing) adds no <style> at all, so the default renders exactly as before.
 *
 * The other presets were made from lavender by swapping the hue at the same relative luminance, so every
 * contrast pair keeps lavender's ratio; tests/Unit/BrandPaletteTest.php checks WCAG contrast for all of them.
 */
final class BrandPalette
{
    public const DEFAULT = 'lavender';

    /** Shared surfaces (tokens.css), for the contrast test and the manifest background. */
    public const SURFACES = [
        'light' => ['background' => '#F6F5FB', 'card' => '#FFFFFF'],
        'dark' => ['background' => '#1E1F23', 'card' => '#26272C'],
    ];

    /**
     * key => [label, light base colours, dark base colours]. Base colours: primary, primary-foreground, deep,
     * deep-foreground, accent, accent-foreground. ring and info equal primary; info-soft / info-foreground equal
     * accent / accent-foreground (as in tokens.css).
     *
     * @var array<string, array{label: string, light: array<string, string>, dark: array<string, string>}>
     */
    public const PRESETS = [
        'lavender' => [
            'label' => 'Lavender (default)',
            'light' => ['primary' => '#5B4FCF', 'primary-foreground' => '#FFFFFF', 'deep' => '#E3DFFC', 'deep-foreground' => '#241C6B', 'accent' => '#E9E6FB', 'accent-foreground' => '#4338A8'],
            'dark' => ['primary' => '#9B8CFA', 'primary-foreground' => '#14112E', 'deep' => '#3B2F8A', 'deep-foreground' => '#F3F1FF', 'accent' => '#352C6E', 'accent-foreground' => '#CEC6FF'],
        ],
        'teal' => [
            'label' => 'Teal',
            'light' => ['primary' => '#266D6D', 'primary-foreground' => '#FFFFFF', 'deep' => '#CBE8E8', 'deep-foreground' => '#102E2F', 'accent' => '#D9ECEC', 'accent-foreground' => '#215252'],
            'dark' => ['primary' => '#15ABAC', 'primary-foreground' => '#0A1717', 'deep' => '#1C4344', 'deep-foreground' => '#E5F5F5', 'accent' => '#1B3B3B', 'accent-foreground' => '#95D8D8'],
        ],
        'blue' => [
            'label' => 'Blue',
            'light' => ['primary' => '#3362AA', 'primary-foreground' => '#FFFFFF', 'deep' => '#D9E3F0', 'deep-foreground' => '#152949', 'accent' => '#E2E9F2', 'accent-foreground' => '#2B4A79'],
            'dark' => ['primary' => '#609BF4', 'primary-foreground' => '#0D1622', 'deep' => '#253E65', 'deep-foreground' => '#EEF3FA', 'accent' => '#233653', 'accent-foreground' => '#BCCEEA'],
        ],
        'green' => [
            'label' => 'Green',
            'light' => ['primary' => '#336E48', 'primary-foreground' => '#FFFFFF', 'deep' => '#D3E7DB', 'deep-foreground' => '#152E1E', 'accent' => '#DEEBE3', 'accent-foreground' => '#2A5339'],
            'dark' => ['primary' => '#2DB15D', 'primary-foreground' => '#0D1711', 'deep' => '#23442F', 'deep-foreground' => '#EBF5EE', 'accent' => '#223C2B', 'accent-foreground' => '#AAD7BB'],
        ],
        'maroon' => [
            'label' => 'Maroon',
            'light' => ['primary' => '#823447', 'primary-foreground' => '#FFFFFF', 'deep' => '#EFDEE3', 'deep-foreground' => '#471B26', 'accent' => '#F1E5E8', 'accent-foreground' => '#662F3C'],
            'dark' => ['primary' => '#E67894', 'primary-foreground' => '#211014', 'deep' => '#622D3B', 'deep-foreground' => '#F9F1F3', 'accent' => '#522A34', 'accent-foreground' => '#E7C4CD'],
        ],
        'slate' => [
            'label' => 'Slate',
            'light' => ['primary' => '#556478', 'primary-foreground' => '#FFFFFF', 'deep' => '#DFE3E7', 'deep-foreground' => '#242A33', 'accent' => '#E6E8EC', 'accent-foreground' => '#414A58'],
            'dark' => ['primary' => '#879CBB', 'primary-foreground' => '#131619', 'deep' => '#373E4A', 'deep-foreground' => '#F1F3F5', 'accent' => '#31373F', 'accent-foreground' => '#C7CDD7'],
        ],
    ];

    /**
     * Unique id used only to raise the override's specificity above every selector in tokens.css and
     * bootstrap-theme.css (an :not(#id) counts as an id), so the block wins wherever it sits in <head>.
     */
    private const BUMP = ':not(#cp-brand)';

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::PRESETS);
    }

    public static function isValid(mixed $key): bool
    {
        return is_string($key) && array_key_exists($key, self::PRESETS);
    }

    /** The saved preset, or the default when none is saved, the stored key is unknown, or the database is down. */
    public static function current(): string
    {
        $key = PortalSettings::get('brand');

        return self::isValid($key) ? $key : self::DEFAULT;
    }

    public static function label(string $key): string
    {
        return self::PRESETS[$key]['label'] ?? self::PRESETS[self::DEFAULT]['label'];
    }

    /**
     * Every brand token of a preset for one theme, as hex (tokens.css names).
     *
     * @param  'light'|'dark'  $theme
     * @return array<string, string>
     */
    public static function tokens(string $key, string $theme): array
    {
        $base = (self::PRESETS[$key] ?? self::PRESETS[self::DEFAULT])[$theme];

        return $base + [
            'ring' => $base['primary'],
            'info' => $base['primary'],
            'info-soft' => $base['accent'],
            'info-foreground' => $base['accent-foreground'],
        ];
    }

    /** @param 'light'|'dark' $theme */
    public static function hex(string $key, string $theme, string $token = 'primary'): string
    {
        return self::tokens($key, $theme)[$token];
    }

    /**
     * The <style> body that re-brands the page, or null for the default (tokens.css as built). Built only from
     * the constants above; never from user input.
     */
    public static function css(?string $key = null): ?string
    {
        $key ??= self::current();
        if (! self::isValid($key) || $key === self::DEFAULT) {
            return null;
        }
        $b = self::BUMP;

        return ":root{$b}{".self::declarations($key, 'light').'}'
            ."@media (prefers-color-scheme: dark){:root{$b}:not([data-theme=\"light\"]){".self::declarations($key, 'dark').'}}'
            .":root{$b}[data-theme=\"dark\"]{".self::declarations($key, 'dark').'}';
    }

    /** @param 'light'|'dark' $theme */
    private static function declarations(string $key, string $theme): string
    {
        $t = self::tokens($key, $theme);
        $out = '';
        foreach ($t as $token => $hex) {
            $out .= "--{$token}:".implode(' ', self::rgb($hex)).';';
        }
        // bootstrap-theme.css writes these four as comma triplets (Bootstrap builds rgba() from them).
        foreach (['primary' => 'primary', 'info' => 'info', 'link-color' => 'primary', 'link-hover-color' => 'accent-foreground'] as $bs => $token) {
            $out .= "--bs-{$bs}-rgb:".implode(', ', self::rgb($t[$token])).';';
        }

        return $out;
    }

    /** @return array{0: int, 1: int, 2: int} */
    public static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [(int) hexdec(substr($hex, 0, 2)), (int) hexdec(substr($hex, 2, 2)), (int) hexdec(substr($hex, 4, 2))];
    }

    /** WCAG 2.x relative luminance. */
    public static function luminance(string $hex): float
    {
        $c = array_map(function (int $v) {
            $v /= 255;

            return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        }, self::rgb($hex));

        return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
    }

    /** WCAG 2.x contrast ratio of two colours (1 to 21). */
    public static function contrast(string $a, string $b): float
    {
        [$hi, $lo] = [max(self::luminance($a), self::luminance($b)), min(self::luminance($a), self::luminance($b))];

        return ($hi + 0.05) / ($lo + 0.05);
    }
}
