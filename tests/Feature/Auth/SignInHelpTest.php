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
            ->assertSee('Tanya bendahari kelab anda secara terus.')
            ->assertDontSee('Bendahari kelab anda boleh membantu')
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
            ->assertSee('send them a WhatsApp message.')
            ->assertDontSee('mailto:', false)
            ->assertDontSee('60111111111');

        $club->update(['whatsapp_number' => null]);
        $this->get(route('password.request'))->assertOk()
            ->assertSee('href="mailto:kelab@example.test"', false)
            ->assertSee('E-mel Kelab Sukan')
            ->assertSee('hantar e-mel kepada mereka.')
            ->assertDontSee('hantar mesej WhatsApp')
            ->assertDontSee('data-testid="auth-help-reset"', false);

        // A number that is not a usable WhatsApp number also falls back to the email.
        $club->update(['whatsapp_number' => 'abc']);
        $this->get(route('login'))->assertOk()
            ->assertSee('href="mailto:kelab@example.test"', false)
            ->assertDontSee('wa.me', false);
    }

    public function test_single_active_club_without_any_contact_says_ask_directly(): void
    {
        $this->withoutVite();
        Club::factory()->create(['whatsapp_number' => null, 'email' => null]);

        $this->get(route('login'))->assertOk()
            ->assertSee('Tanya bendahari kelab anda secara terus.')
            ->assertDontSee('Bendahari kelab anda boleh membantu')
            ->assertDontSee('wa.me', false)
            ->assertDontSee('mailto:', false);
    }
}
