<?php

namespace Tests\Unit;

use App\Support\UiLang;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class UiLangTest extends TestCase
{
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

    public function test_every_code_has_a_label_and_a_name_and_malay_is_the_default(): void
    {
        $this->assertSame('ms', UiLang::DEFAULT);
        $this->assertSame(UiLang::DEFAULT, UiLang::available()[0]);
        $this->assertEqualsCanonicalizing(UiLang::available(), array_keys(UiLang::LABELS));
        $this->assertEqualsCanonicalizing(UiLang::available(), array_keys(UiLang::NAMES));
        foreach (UiLang::available() as $code) {
            $this->assertSame($code, UiLang::normalise(UiLang::stored($code)));
            $this->assertFileExists(dirname(__DIR__, 2)."/lang/member/{$code}.json");
        }
    }

    /** The PHP list (the files in lang/member) is the member app's: FILES in resources/js/lib/i18n.ts. */
    public function test_available_languages_match_the_member_app_files(): void
    {
        $ts = (string) file_get_contents(dirname(__DIR__, 2).'/resources/js/lib/i18n.ts');
        $this->assertSame(1, preg_match('/const FILES = \{([^}]*)\}/', $ts, $m), 'FILES not found in i18n.ts');
        $files = array_map(fn ($entry) => trim(explode(':', $entry)[0]), array_filter(array_map('trim', explode(',', $m[1]))));
        $this->assertEqualsCanonicalizing(UiLang::available(), array_values($files));

        foreach (UiLang::available() as $code) {
            $this->assertStringContainsString('import '.$code.' from "../../../lang/member/'.$code.'.json";', $ts);
        }
    }

    public function test_a_code_without_a_file_is_not_available(): void
    {
        $this->assertNull(UiLang::normalise('fr'));
        $this->assertNull(UiLang::normalise('../ms'));
        $this->assertSame('fr', UiLang::name('fr'));
        $this->assertSame('Bahasa Melayu', UiLang::name('ms'));
    }

    /** Email dates use the member app's month names (lang/member), not Carbon's Malay ("Ogs" where the app says "Ogo"). */
    public function test_dates_use_the_member_app_month_names(): void
    {
        $this->assertSame('5 Ogo 2026', UiLang::date(Carbon::create(2026, 8, 5), 'ms'));
        $this->assertSame('5 Aug 2026', UiLang::date(Carbon::create(2026, 8, 5), 'en'));
        $this->assertSame('31 Mac 2026', UiLang::date(Carbon::create(2026, 3, 31), 'ms'));
        $this->assertSame('1 Okt 2026', UiLang::date(Carbon::create(2026, 10, 1), 'ms'));
        $this->assertSame('1 Dis 2026', UiLang::date(Carbon::create(2026, 12, 1), 'ms'));
    }
}
