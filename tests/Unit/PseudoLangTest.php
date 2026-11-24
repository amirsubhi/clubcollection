<?php

namespace Tests\Unit;

use App\Support\UiLang;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeMemberLanguages;
use Tests\Support\PseudoLang;

/**
 * The pseudo-language "xx" (scripts/i18n-pseudo.php) used to check a copy before translating: every text bracketed,
 * about 40% longer, placeholders and markers kept, never shipped.
 */
class PseudoLangTest extends TestCase
{
    use FakeMemberLanguages;

    protected function tearDown(): void
    {
        $this->forgetFakeMemberLanguages();
        parent::tearDown();
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    public function test_texts_are_bracketed_longer_and_keep_placeholders_and_markers(): void
    {
        $this->assertSame('[Pàý {x} ~~~]', PseudoLang::text('Pay {x}'));
        $this->assertSame('[Déàr **:name**, ~~~~~~]', PseudoLang::text('Dear **:name**,'));
        $this->assertSame('[:count dàý ôvérdüé ~~~~~~~~]|[:count dàýs ôvérdüé ~~~~~~~~]', PseudoLang::text(':count day overdue|:count days overdue'));

        $en = json_decode((string) file_get_contents(self::root().'/lang/member/en.json'), true, 512, JSON_THROW_ON_ERROR);
        unset($en['_meta']);
        foreach ($en as $key => $text) {
            $pseudo = PseudoLang::text($text);
            preg_match_all('/\{\w+\}/', $text, $a);
            preg_match_all('/\{\w+\}/', $pseudo, $b);
            $this->assertSame($a[0], $b[0], $key);
            $this->assertStringStartsWith('[', $pseudo, $key);
            $this->assertGreaterThanOrEqual(mb_strlen($text) * 1.4, mb_strlen($pseudo), $key);
        }
    }

    public function test_it_is_a_language_like_any_other_with_no_code(): void
    {
        $en = json_decode((string) file_get_contents(self::root().'/lang/member/en.json'), true, 512, JSON_THROW_ON_ERROR);
        unset($en['_meta']);
        $this->fakeMemberLanguage('xx', PseudoLang::META, array_map(PseudoLang::text(...), $en));

        $this->assertContains('xx', UiLang::available());
        $this->assertSame('[Pseudo] (beta)', UiLang::pickerName('xx'));
        $this->assertSame('en-XA', UiLang::htmlLang('xx'));
        $this->assertSame('[5 [Åüg ~~] 2026 ~~~~~]', UiLang::date(Carbon::create(2026, 8, 5), 'xx'), 'dates follow its own dateFormat');
    }

    /** Never shipped: the generated files are ignored by git, and none is in this checkout's lang folder. */
    public function test_the_pseudo_language_is_never_committed(): void
    {
        $ignore = (string) file_get_contents(self::root().'/.gitignore');
        foreach (['/lang/member/xx.json', '/lang/xx.json', '/lang/xx/'] as $path) {
            $this->assertStringContainsString("\n{$path}\n", $ignore);
        }
        $this->assertStringContainsString("APP_ENV=production", (string) file_get_contents(self::root().'/scripts/i18n-pseudo.php'));
    }
}
