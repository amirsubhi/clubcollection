<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthPageLocale;
use App\Models\User;
use Tests\TestCase;

/** The sign-in pages (layouts/auth.blade.php): brand, language, accessible errors, no legal threats. */
class AuthPagesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_login_is_bahasa_melayu_by_default_with_the_product_name(): void
    {
        config(['app.name' => 'Laravel']);
        // The provider swaps the framework default at boot; repeat it for this already-booted app.
        (new \App\Providers\AppServiceProvider($this->app))->boot();

        $this->get('/login')
            ->assertOk()
            ->assertSee('<html lang="ms">', false)
            ->assertSee('<title>Log masuk · Club Portal</title>', false)
            ->assertSee('Club Portal')
            ->assertDontSee('Laravel')
            ->assertSee('Untuk ahli kelab sahaja')
            ->assertSee('Perlukan bantuan? Hubungi pentadbir kelab')
            ->assertSee('Kata laluan')
            ->assertDontSee('Authorized Users Only')
            ->assertDontSee('legal action')
            ->assertDontSee('cdn.jsdelivr.net/npm/bootstrap', false);
    }

    public function test_english_switch_sets_lang_and_remembers_the_choice(): void
    {
        $this->get('/login?lang=en')
            ->assertOk()
            ->assertSee('<html lang="en">', false)
            ->assertSee('<title>Sign in · ', false)
            ->assertSee('For club members only')
            ->assertSee('Need help? Contact your club administrator')
            ->assertCookie(AuthPageLocale::COOKIE, 'en', false);

        // The cookie (also written by the member app's BM/EN toggle) keeps the next page in English.
        $this->withUnencryptedCookie(AuthPageLocale::COOKIE, 'en')
            ->get('/password/reset')
            ->assertSee('<html lang="en">', false)
            ->assertSee('Send reset link');
    }

    public function test_unknown_lang_values_are_ignored(): void
    {
        $this->get('/login?lang=xx')->assertSee('<html lang="ms">', false)->assertCookieMissing(AuthPageLocale::COOKIE);
    }

    public function test_failed_login_error_is_an_alert_tied_to_the_field_in_the_chosen_language(): void
    {
        User::factory()->create(['email' => 'ahli@example.test']);

        $this->from('/login')->post('/login', ['email' => 'ahli@example.test', 'password' => 'wrong-password'])
            ->assertSessionHasErrors(['email' => 'E-mel atau kata laluan tidak betul.']);

        $this->followingRedirects()->from('/login')->post('/login', ['email' => 'ahli@example.test', 'password' => 'wrong-password'])
            ->assertSee('E-mel atau kata laluan tidak betul.')
            ->assertSee('id="auth-error" role="alert"', false)
            ->assertSee('aria-describedby="auth-error"', false);

        $this->followRedirects = false;
        $this->withUnencryptedCookie(AuthPageLocale::COOKIE, 'en')
            ->from('/login')->post('/login', ['email' => 'ahli@example.test', 'password' => 'wrong-password'])
            ->assertSessionHasErrors(['email' => trans('auth.failed', [], 'en')]);
    }

    public function test_password_pages_and_two_factor_challenge_use_the_same_layout(): void
    {
        $this->get('/password/reset')->assertOk()->assertSee('<html lang="ms">', false)->assertSee('Hantar pautan');
        $this->get('/password/reset/some-token?email=a%40example.test')->assertOk()->assertSee('Simpan kata laluan baharu');

        $user = User::factory()->create();
        $this->withSession(['two_factor_user_id' => $user->id])
            ->get('/two-factor-challenge')
            ->assertOk()
            ->assertSee('<html lang="ms">', false)
            ->assertSee('Pengesahan dua langkah')
            ->assertSee('id="recoverySection" class="flex flex-col gap-5" hidden', false);
    }

    public function test_password_confirm_uses_the_auth_layout(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/password/confirm')
            ->assertOk()
            ->assertSee('<html lang="ms">', false)
            ->assertSee('Sahkan kata laluan anda')
            ->assertDontSee('cdn.jsdelivr.net/npm/bootstrap', false);
    }

    public function test_language_switch_names_include_the_visible_label(): void
    {
        $this->get('/login')
            ->assertSee('BM<span class="sr-only"> – Bahasa Melayu</span>', false)
            ->assertSee('EN<span class="sr-only"> – English</span>', false);
    }

    public function test_two_factor_errors_follow_the_page_language(): void
    {
        $user = User::factory()->create(['two_factor_confirmed_at' => now()]);

        $this->withSession(['two_factor_user_id' => $user->id])
            ->post('/two-factor-challenge', [])
            ->assertSessionHasErrors(['code' => 'Sila masukkan kod pengesah atau kod pemulihan.']);
    }

    public function test_sign_in_falls_back_to_a_built_in_style_when_the_build_is_missing(): void
    {
        // A public folder with no build/manifest.json and no hot file (npm run build not uploaded).
        $public = sys_get_temp_dir().'/cp-public-'.uniqid();
        mkdir($public);
        $this->app->usePublicPath($public);

        try {
            $this->get('/login')
                ->assertOk()
                ->assertSee('body > div { max-width: 420px;', false)
                ->assertSee('<style nonce="', false)
                ->assertSee('name="email"', false);
        } finally {
            @rmdir($public);
        }
    }

    public function test_an_invalid_language_cookie_is_not_copied_to_the_member_app(): void
    {
        $this->withUnencryptedCookie(AuthPageLocale::COOKIE, 'xx')
            ->get('/login')
            ->assertSee('<html lang="ms">', false)
            ->assertDontSee("localStorage.setItem('cp-lang'", false);

        $this->withUnencryptedCookie(AuthPageLocale::COOKIE, 'en')
            ->get('/login')
            ->assertSee("localStorage.setItem('cp-lang', \"en\")", false);
    }

    public function test_too_many_sign_in_attempts_page_is_translated_and_styled(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => 'nobody@example.test', 'password' => 'wrong']);
        }

        $this->post('/login', ['email' => 'nobody@example.test', 'password' => 'wrong'])
            ->assertStatus(429)
            ->assertSee('<html lang="ms">', false)
            ->assertSee('Terlalu banyak cubaan')
            ->assertSee('Sila tunggu kira-kira')
            ->assertSee('class="auth-card"', false)
            ->assertSee('role="alert"', false)
            ->assertDontSee('Too Many Requests');
    }

    public function test_too_many_attempts_page_follows_the_saved_language(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->withUnencryptedCookie(AuthPageLocale::COOKIE, 'en')->post('/login', ['email' => 'nobody@example.test', 'password' => 'wrong']);
        }

        $this->withUnencryptedCookie(AuthPageLocale::COOKIE, 'en')
            ->post('/login', ['email' => 'nobody@example.test', 'password' => 'wrong'])
            ->assertStatus(429)
            ->assertSee('<html lang="en">', false)
            ->assertSee('Too many attempts')
            ->assertSee('Please wait about');
    }
}
