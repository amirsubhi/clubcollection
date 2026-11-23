<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthPageLocale;
use App\Models\Club;
use Tests\Support\FakeMemberLanguages;
use Tests\TestCase;

/**
 * The language picker on the sign-in pages (layouts/auth: a <details> list of ?lang= links) lists every
 * lang/member file by its own name, and a new file shows up in it, in <html lang> and in the member app with no
 * code change. The member app's own picker (MemberShell) reads the same files in the browser.
 */
class LanguagePickerTest extends TestCase
{
    use FakeMemberLanguages;

    protected function tearDown(): void
    {
        $this->forgetFakeMemberLanguages();
        parent::tearDown();
    }

    public function test_the_sign_in_picker_lists_malay_and_english_by_their_own_names(): void
    {
        $html = $this->get('/login')->assertOk()->getContent();

        $this->assertStringContainsString('data-testid="lang-picker"', $html);
        // Closed, it shows the short label, and its name says what it is.
        $this->assertSame(1, preg_match('/<summary class="auth-langs-btn">.*?<span>BM<span class="sr-only"> – Bahasa: Bahasa Melayu<\/span><\/span>/s', $html));
        // Malay first (the default), current, and still written as "bm" in the link.
        $this->assertSame(1, preg_match('/<a href="[^"]*lang=bm"\s+hreflang="ms"\s+lang="ms"\s+aria-current="true"\s+class="auth-lang-option is-active">Bahasa Melayu<\/a>/', $html));
        $this->assertSame(1, preg_match('/<a href="[^"]*lang=en"\s+hreflang="en"\s+lang="en"\s+class="auth-lang-option ">English<\/a>/', $html));
        $this->assertLessThan(strpos($html, '>English</a>'), strpos($html, '>Bahasa Melayu</a>'));
        $this->assertStringNotContainsString('(beta)', $html);
        // The script that closes the list carries the CSP nonce.
        $this->assertSame(1, preg_match('/<script nonce="[^"]+">\s*\(function \(\) \{\s*var picker = document\.querySelector\(\'\[data-lang-picker\]\'\);/', $html));

        $this->get('/login?lang=en')
            ->assertSee('EN<span class="sr-only"> – Language: English</span>', false)
            ->assertSee('<html lang="en">', false);
    }

    public function test_the_picker_keeps_the_rest_of_the_address(): void
    {
        $this->get('/password/reset/some-token?email=member%40example.test')
            ->assertSee('email=member%40example.test&amp;lang=en', false);
    }

    public function test_a_new_language_file_appears_in_the_picker_and_html_lang(): void
    {
        $this->fakeMemberLanguage('zz', ['name' => 'Zedish', 'label' => 'ZZ', 'htmlLang' => 'zz-Hans', 'localeTag' => 'zz-MY', 'beta' => true]);

        $html = $this->get('/login')->getContent();
        $this->assertSame(1, preg_match('/<a href="[^"]*lang=zz"\s+hreflang="zz-Hans"\s+lang="zz-Hans"\s+class="auth-lang-option ">Zedish \(beta\)<\/a>/', $html));
        $this->assertLessThan(strpos($html, 'Zedish (beta)'), strpos($html, '>English</a>'), 'the default first, then by code');

        $this->get('/login?lang=zz')
            ->assertCookie(AuthPageLocale::COOKIE, 'zz', false)
            ->assertSee('<html lang="zz-Hans">', false)
            ->assertSee('ZZ<span class="sr-only"> – Language: Zedish</span>', false);
    }

    /** Signed in (change password), the same picker posts the choice to the save route: one button per language. */
    public function test_a_signed_in_member_gets_the_picker_as_a_form_with_every_language(): void
    {
        $this->fakeMemberLanguage('zz', ['name' => 'Zedish', 'label' => 'ZZ', 'htmlLang' => 'zz-Hans', 'localeTag' => 'zz-MY', 'beta' => true]);
        $this->withoutVite();
        $member = $this->actingAsMember(Club::factory()->create());

        $html = $this->get(route('password.change'))->assertOk()->getContent();
        $this->assertStringContainsString('data-testid="auth-lang-form"', $html);
        $this->assertSame(1, preg_match('/<button type="submit" name="lang" value="ms"\s+lang="ms"\s+aria-current="true"\s+class="auth-lang-option is-active">Bahasa Melayu<\/button>/', $html));
        $this->assertSame(1, preg_match('/<button type="submit" name="lang" value="zz"\s+lang="zz-Hans"\s+class="auth-lang-option ">Zedish \(beta\)<\/button>/', $html));

        $this->from(route('password.change'))->post(route('member.language.update'), ['lang' => 'zz'])->assertRedirect(route('password.change'));
        $this->assertSame('zz', $member->fresh()->locale);
        $this->get(route('password.change'))->assertSee('<html lang="zz-Hans">', false);
    }

    /** The privacy notice is written in Malay and English only: a third language reads the English. */
    public function test_a_third_language_reads_the_english_privacy_notice(): void
    {
        $this->fakeMemberLanguage('zz', ['name' => 'Zedish', 'label' => 'ZZ', 'htmlLang' => 'zz-Hans', 'localeTag' => 'zz-MY', 'beta' => true]);
        $this->withoutVite();
        \App\Support\Portal\PrivacyNotice::put([
            'privacy_notice_ms' => 'Kami menyimpan nama anda.',
            'privacy_notice_en' => 'We keep your name.',
            'privacy_contact_name' => 'Setiausaha',
            'privacy_contact_email' => 'privasi@example.test',
        ]);

        $this->get(route('privacy', ['lang' => 'zz']))->assertOk()->assertSee('We keep your name.')->assertDontSee('Kami menyimpan');
        $this->get(route('privacy', ['lang' => 'bm']))->assertOk()->assertSee('Kami menyimpan nama anda.');
    }

    public function test_the_member_app_page_gets_the_languages_html_lang(): void
    {
        $this->fakeMemberLanguage('zz', ['name' => 'Zedish', 'label' => 'ZZ', 'htmlLang' => 'zz-Hans', 'localeTag' => 'zz-MY', 'beta' => true]);
        $this->withoutVite();
        $member = $this->actingAsMember(Club::factory()->create());
        $member->forceFill(['locale' => 'zz'])->save();

        $this->get(route('member.dashboard'))->assertOk()->assertSee('<html lang="zz-Hans">', false);

        $this->postJson(route('member.language.update'), ['lang' => 'zz'])->assertNoContent();
    }
}
