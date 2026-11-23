<?php

namespace Tests\Unit;

use App\Support\UiLang;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeMemberLanguages;

class UiLangTest extends TestCase
{
    use FakeMemberLanguages;

    protected function tearDown(): void
    {
        $this->forgetFakeMemberLanguages();
        parent::tearDown();
    }

    public function test_the_old_bm_code_means_malay(): void
    {
        $this->assertSame('ms', UiLang::normalise('bm'));
        $this->assertSame('ms', UiLang::normalise('ms'));
        $this->assertSame('en', UiLang::normalise('en'));
    }

    public function test_anything_else_is_not_a_language(): void
    {
        foreach (['', 'xx', 'BM', 'EN', 'en_US', 'zh', ' bm', null, 1, ['bm'], true] as $value) {
            $this->assertNull(UiLang::normalise($value), var_export($value, true));
        }
    }

    public function test_malay_is_still_stored_as_bm(): void
    {
        $this->assertSame('bm', UiLang::stored('ms'));
        $this->assertSame('en', UiLang::stored('en'));
    }

    /** Names, labels and <html lang> come from each file's _meta. */
    public function test_every_language_describes_itself_and_malay_is_the_default(): void
    {
        $this->assertSame('ms', UiLang::DEFAULT);
        $this->assertSame(UiLang::DEFAULT, UiLang::available()[0]);
        $this->assertSame(['name' => 'Bahasa Melayu', 'label' => 'BM', 'htmlLang' => 'ms', 'localeTag' => 'ms-MY', 'beta' => false], UiLang::meta('ms'));
        $this->assertSame(['name' => 'English', 'label' => 'EN', 'htmlLang' => 'en', 'localeTag' => 'en-MY', 'beta' => false], UiLang::meta('en'));
        foreach (UiLang::available() as $code) {
            $this->assertSame($code, UiLang::normalise(UiLang::stored($code)));
            $this->assertFileExists(dirname(__DIR__, 2)."/lang/member/{$code}.json");
        }
        $this->assertSame('ms', UiLang::htmlLang('ms'));
        $this->assertSame('en', UiLang::htmlLang('en'));
        $this->assertSame('en-US', UiLang::htmlLang('en_US'), 'a locale with no file keeps its own code');
    }

    /**
     * The member app builds its list the same way: the folder through import.meta.glob, the same file-name rule,
     * the default first and the rest by code, with no language written into the code.
     */
    public function test_the_member_app_reads_the_same_folder_the_same_way(): void
    {
        $ts = (string) file_get_contents(dirname(__DIR__, 2).'/resources/js/lib/i18n.ts');
        $this->assertStringContainsString('import.meta.glob<LangFile>("../../../lang/member/*.json", { eager: true, import: "default" })', $ts);
        $this->assertSame(1, preg_match('/const CODE = \/(.+)\/;/', $ts, $m), 'CODE not found in i18n.ts');
        $this->assertSame(UiLang::CODE_PATTERN, '/'.$m[1].'/');
        $this->assertStringContainsString('[...new Set([DEFAULT_LANG, ...Object.keys(FILES).sort()])]', $ts);
        $this->assertStringContainsString('export const DEFAULT_LANG: Lang = "'.UiLang::DEFAULT.'";', $ts);
        // Only English is imported by name (DictKey, the type of a key); no other language is listed in code.
        preg_match_all('/lang\/member\/([a-zA-Z_]+)\.json/', $ts, $imports);
        $this->assertSame(['en'], array_values(array_unique($imports[1])));
    }

    public function test_a_code_without_a_file_is_not_available(): void
    {
        $this->assertNull(UiLang::normalise('fr'));
        $this->assertNull(UiLang::normalise('../ms'));
        $this->assertSame('fr', UiLang::name('fr'));
        $this->assertSame('Bahasa Melayu', UiLang::name('ms'));
    }

    /** A new file is a new language: listed, named and labelled from its _meta, with no code change. */
    public function test_a_new_file_is_a_new_language(): void
    {
        $this->fakeMemberLanguage('zz', ['name' => 'Zedish', 'label' => 'ZZ', 'htmlLang' => 'zz-Hans', 'localeTag' => 'zz-MY', 'beta' => true], [
            'dateFormat' => '{y}年{m}{d}日',
            'monthShort_8' => '8月',
        ]);

        $this->assertSame(['ms', 'en', 'zz'], UiLang::available());
        $this->assertSame('zz', UiLang::normalise('zz'));
        $this->assertSame('Zedish (beta)', UiLang::pickerName('zz'));
        $this->assertSame('Bahasa Melayu', UiLang::pickerName('ms'));
        $this->assertSame('ZZ', UiLang::label('zz'));
        $this->assertSame('zz-Hans', UiLang::htmlLang('zz'));
        $this->assertSame('2026年8月5日', UiLang::date(Carbon::create(2026, 8, 5), 'zz'), 'the date order is the language\'s own');
    }

    public function test_a_file_without_meta_shows_its_code_not_english(): void
    {
        $this->fakeMemberLanguage('qq', null);

        $this->assertSame('qq', UiLang::name('qq'));
        $this->assertSame('QQ', UiLang::label('qq'));
        $this->assertSame('qq', UiLang::htmlLang('qq'));
        $this->assertFalse(UiLang::meta('qq')['beta']);
    }

    /** Email dates use the member app's month names (lang/member), not Carbon's Malay ("Ogs" where the app says "Ogo"). */
    public function test_dates_use_the_member_app_month_names_and_date_order(): void
    {
        $this->assertSame('5 Ogo 2026', UiLang::date(Carbon::create(2026, 8, 5), 'ms'));
        $this->assertSame('5 Aug 2026', UiLang::date(Carbon::create(2026, 8, 5), 'en'));
        $this->assertSame('31 Mac 2026', UiLang::date(Carbon::create(2026, 3, 31), 'ms'));
        $this->assertSame('1 Okt 2026', UiLang::date(Carbon::create(2026, 10, 1), 'ms'));
        $this->assertSame('1 Dis 2026', UiLang::date(Carbon::create(2026, 12, 1), 'ms'));
        $this->assertSame('5 Aug 2026', UiLang::date(Carbon::create(2026, 8, 5), 'xx'), 'an unknown language gets English');
    }

    public function test_short_keys_are_read_without_meta(): void
    {
        $this->assertSame('{d} {m} {y}', UiLang::text('ms', 'dateFormat'));
        $this->assertNull(UiLang::text('ms', '_meta'));
        $this->assertNull(UiLang::text('ms', 'noSuchKey'));
    }
}
