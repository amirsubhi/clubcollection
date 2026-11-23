<?php

namespace Tests\Unit;

use App\Support\UiLang;
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
        $this->assertSame(UiLang::CODES[0], UiLang::DEFAULT);
        $this->assertSame(UiLang::CODES, array_keys(UiLang::LABELS));
        $this->assertSame(UiLang::CODES, array_keys(UiLang::NAMES));
        foreach (UiLang::CODES as $code) {
            $this->assertSame($code, UiLang::normalise(UiLang::stored($code)));
            $this->assertFileExists(dirname(__DIR__, 2)."/lang/member/{$code}.json");
        }
    }
}
