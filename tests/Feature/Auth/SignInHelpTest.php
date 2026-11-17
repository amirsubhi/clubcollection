<?php

namespace Tests\Feature\Auth;

use App\Models\Club;
use Tests\TestCase;

/**
 * "Perlukan bantuan? Hubungi pentadbir kelab" on the sign-in pages opens a help section (details/summary, no JS).
 * A club contact appears only when exactly one active club exists.
 */
class SignInHelpTest extends TestCase
{
    public function test_help_section_without_contacts_when_several_clubs(): void
    {
        $this->withoutVite();
        Club::factory()->create(['whatsapp_number' => '+60123456789']);
        Club::factory()->create();

        $this->get(route('login'))->assertOk()
            ->assertSee('id="auth-help"', false)
            ->assertSee('Hubungi pentadbir kelab')
            ->assertSee('Bendahari kelab anda boleh membantu')
            ->assertSee(route('password.request'), false)
            ->assertDontSee('wa.me', false)
            ->assertDontSee('mailto:', false);
    }

    public function test_single_active_club_shows_its_whatsapp_else_its_email(): void
    {
        $this->withoutVite();
        Club::factory()->inactive()->create(['whatsapp_number' => '+60111111111']);
        $club = Club::factory()->create(['name' => 'Kelab Sukan', 'whatsapp_number' => '+60123456789', 'email' => 'kelab@example.test']);

        $this->get(route('login').'?lang=en')->assertOk()
            ->assertSee('Need help? Contact your club administrator')
            ->assertSee('href="https://wa.me/60123456789"', false)
            ->assertSee('WhatsApp Kelab Sukan')
            ->assertDontSee('mailto:', false)
            ->assertDontSee('60111111111');

        $club->update(['whatsapp_number' => null]);
        $this->get(route('password.request'))->assertOk()
            ->assertSee('href="mailto:kelab@example.test"', false)
            ->assertSee('E-mel Kelab Sukan')
            ->assertDontSee('data-testid="auth-help-reset"', false);
    }
}
