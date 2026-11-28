<?php

namespace Tests\Feature\Updates;

use App\Support\Updates\PausePage;
use Tests\TestCase;

class PausePageTest extends TestCase
{
    public function test_the_malay_text_is_exactly_the_approved_wording_with_the_portal_name(): void
    {
        $html = PausePage::render('ms', 'Kelab Contoh');

        $this->assertStringContainsString('<title>Kelab Contoh sedang dikemas kini</title>', $html);
        $this->assertStringContainsString('<h1>Kelab Contoh sedang dikemas kini</h1>', $html);
        $this->assertStringContainsString('<p>Kembali dalam beberapa minit. Halaman ini akan dimuat semula sendiri.</p>', $html);
        $this->assertStringContainsString('<p>Jika anda baru membuat bayaran, bayaran anda selamat. Resit akan dipaparkan sebaik sahaja portal dibuka semula.</p>', $html);
        $this->assertStringContainsString('<p>Jika anda sedang menghantar borang atau slip pindahan, sila hantar semula apabila kami kembali.</p>', $html);
        $this->assertStringContainsString('<html lang="ms"', $html);
    }

    public function test_it_refreshes_to_the_home_page_every_30_seconds_and_uses_no_external_files(): void
    {
        foreach (['ms', 'en', 'bm', 'fr'] as $locale) {
            $html = PausePage::render($locale, 'Club');
            $this->assertStringContainsString('<meta http-equiv="refresh" content="30;url=/">', $html, $locale);
            $this->assertDoesNotMatchRegularExpression('#https?://|//[a-z]#i', $html, $locale);
            $this->assertStringNotContainsString('<script', $html, $locale);
            $this->assertStringNotContainsString('<link', $html, $locale);
        }
    }

    public function test_english_and_every_other_language_get_the_english_text(): void
    {
        $en = PausePage::render('en', 'Club');
        $this->assertStringContainsString('Club is being updated', $en);
        $this->assertStringContainsString('<html lang="en"', $en);
        $this->assertStringContainsString("If you've just paid", html_entity_decode($en, ENT_QUOTES));

        foreach (['es', 'fr', 'ta', 'zh', 'xx'] as $locale) {
            $this->assertSame($en, PausePage::render($locale, 'Club'), $locale);
        }
        $this->assertSame(PausePage::render('ms', 'Club'), PausePage::render('bm', 'Club'));
    }

    public function test_the_portal_name_is_escaped_and_an_empty_one_falls_back(): void
    {
        $html = PausePage::render('en', '<b>"A&B"</b>');
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringContainsString('&lt;b&gt;', $html);

        $this->assertStringContainsString('Club Portal sedang dikemas kini', PausePage::render('ms', '  '));
    }
}
