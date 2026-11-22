<?php

namespace Tests\Feature\Auth;

use App\Models\Club;
use App\Models\User;
use Tests\TestCase;

/** The front page (/) is the sign-in page, with a "how to join" section and a show/hide password button. */
class FrontPageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_front_page_shows_the_sign_in_form_and_how_to_join(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('<html lang="ms">', false)
            ->assertSee('action="'.route('login').'"', false)
            ->assertSee('data-testid="auth-join"', false)
            ->assertSee('Ahli baru? Cara menyertai')
            ->assertSee('Untuk menyertai, atau jika anda tidak dapat log masuk, hubungi pentadbir kelab anda.')
            ->assertDontSee('wa.me', false);

        $this->get('/?lang=en')->assertOk()
            ->assertSee('<html lang="en">', false)
            ->assertSee('New member? How to join')
            ->assertSee('To join, or if you cannot sign in, contact your club admin.');
    }

    public function test_join_section_links_the_only_active_club(): void
    {
        Club::factory()->create(['name' => 'Kelab Sukan', 'whatsapp_number' => '+60123456789']);

        $this->get('/')->assertOk()
            ->assertSee('data-testid="auth-join-whatsapp"', false)
            ->assertSee('href="https://wa.me/60123456789"', false);
    }

    public function test_signed_in_user_is_sent_on_from_the_front_page(): void
    {
        $this->actingAs(User::factory()->create())->get('/')->assertRedirect('/home');
    }

    public function test_failed_sign_in_from_the_front_page_returns_there_with_the_error(): void
    {
        $this->from('/')->post('/login', ['email' => 'nobody@example.test', 'password' => 'wrong'])
            ->assertRedirect('/')
            ->assertSessionHasErrors('email');
    }

    public function test_password_toggle_is_hidden_until_the_nonced_script_shows_it(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<button type="button" id="password-toggle"[^>]*\shidden[\s>]/', $html);
        $this->assertStringContainsString('aria-pressed="false"', $html);
        $this->assertStringContainsString('data-show="Tunjuk" data-hide="Sorok"', $html);
        // The CSP allows only nonced inline scripts: the toggle script must carry the page's nonce.
        $this->assertMatchesRegularExpression('/<script nonce="[^"]+">\s*\(function \(\) \{\s*var input = document.getElementById\(\'password\'\)/', $html);
    }
}
