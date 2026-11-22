<?php

namespace Tests\Unit;

use App\Support\Portal\BrandPalette;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Settings phase 2: every brand colour preset is readable (WCAG 2.x contrast) in light and dark. */
class BrandPaletteTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function presetsAndThemes(): iterable
    {
        foreach (BrandPalette::keys() as $key) {
            foreach (['light', 'dark'] as $theme) {
                yield "{$key} {$theme}" => [$key, $theme];
            }
        }
    }

    #[DataProvider('presetsAndThemes')]
    public function test_every_preset_meets_wcag_contrast(string $key, string $theme): void
    {
        $t = BrandPalette::tokens($key, $theme);
        ['background' => $bg, 'card' => $card] = BrandPalette::SURFACES[$theme];

        $pairs = [
            // Text on a filled button / badge.
            ['primary-foreground on primary', $t['primary-foreground'], $t['primary'], 4.5],
            // Primary as link / text colour on the page and on cards.
            ['primary on background', $t['primary'], $bg, 4.5],
            ['primary on card', $t['primary'], $card, 4.5],
            // Focus outline (non-text UI, WCAG 1.4.11).
            ['ring on background', $t['ring'], $bg, 3.0],
            ['ring on card', $t['ring'], $card, 3.0],
            // Link hover colour (Bootstrap) and accent chips / active nav.
            ['accent-foreground on background', $t['accent-foreground'], $bg, 4.5],
            ['accent-foreground on card', $t['accent-foreground'], $card, 4.5],
            ['accent-foreground on accent', $t['accent-foreground'], $t['accent'], 4.5],
            ['info-foreground on info-soft', $t['info-foreground'], $t['info-soft'], 4.5],
            ['deep-foreground on deep', $t['deep-foreground'], $t['deep'], 4.5],
        ];
        foreach ($pairs as [$what, $fg, $back, $min]) {
            $ratio = BrandPalette::contrast($fg, $back);
            $this->assertGreaterThanOrEqual($min, $ratio, sprintf('%s %s: %s %s / %s = %.2f:1, needs %.1f:1', $key, $theme, $what, $fg, $back, $ratio, $min));
        }
    }

    public function test_contrast_formula_matches_known_values(): void
    {
        $this->assertEqualsWithDelta(21.0, BrandPalette::contrast('#000000', '#FFFFFF'), 0.001);
        $this->assertEqualsWithDelta(1.0, BrandPalette::contrast('#777777', '#777777'), 0.001);
        $this->assertEqualsWithDelta(4.48, BrandPalette::contrast('#777777', '#FFFFFF'), 0.01);
    }

    /** The default preset is tokens.css itself, so choosing it changes nothing. */
    public function test_the_default_preset_mirrors_tokens_css(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2).'/resources/css/tokens.css');
        $blocks = array_values(array_filter(preg_split('/\}\s*/', $css), fn ($b) => str_contains($b, '--')));
        $parse = function (string $block) {
            preg_match_all('/--([a-z0-9-]+):\s*(\d+) (\d+) (\d+);/', $block, $m, PREG_SET_ORDER);
            $out = [];
            foreach ($m as $r) {
                $out[$r[1]] = sprintf('#%02X%02X%02X', $r[2], $r[3], $r[4]);
            }

            return $out;
        };
        [$light, $dark] = [$parse($blocks[0]), $parse($blocks[2])];

        foreach (['light' => $light, 'dark' => $dark] as $theme => $tokens) {
            foreach (BrandPalette::tokens(BrandPalette::DEFAULT, $theme) as $token => $hex) {
                $this->assertSame($tokens[$token], $hex, "lavender {$theme} --{$token}");
            }
            foreach (BrandPalette::SURFACES[$theme] as $token => $hex) {
                $this->assertSame($tokens[$token], $hex, "{$theme} --{$token}");
            }
        }
        $this->assertNull(BrandPalette::css(BrandPalette::DEFAULT));
    }

    public function test_css_is_only_built_for_known_presets(): void
    {
        $this->assertNull(BrandPalette::css('nope'));
        $this->assertNull(BrandPalette::css('teal;}body{display:none'));
        $this->assertFalse(BrandPalette::isValid('#ff0000'));
        $this->assertFalse(BrandPalette::isValid(null));

        $css = (string) BrandPalette::css('teal');
        $this->assertStringContainsString('--primary:38 109 109;', $css);
        $this->assertStringContainsString('--bs-primary-rgb:38, 109, 109;', $css);
        $this->assertStringContainsString('--bs-link-hover-color-rgb:33, 82, 82;', $css);
        $this->assertStringContainsString('@media (prefers-color-scheme: dark)', $css);
        $this->assertStringContainsString('[data-theme="dark"]', $css);
        // Only custom properties: nothing that could hide, move or load anything.
        $this->assertDoesNotMatchRegularExpression('/url\(|@import|expression|<|>/i', $css);
        foreach (BrandPalette::keys() as $key) {
            foreach (['light', 'dark'] as $theme) {
                foreach (BrandPalette::tokens($key, $theme) as $hex) {
                    $this->assertMatchesRegularExpression('/^#[0-9A-F]{6}$/', $hex);
                }
            }
        }
    }
}
