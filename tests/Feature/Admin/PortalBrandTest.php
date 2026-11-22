<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\Club;
use App\Models\PortalSetting;
use App\Support\Portal\BrandPalette;
use App\Support\Portal\PortalSettings;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Settings phase 2: the portal brand colour (a preset key, super admin only) reaches every layout. */
class PortalBrandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('public');
    }

    public function test_only_the_super_admin_may_change_the_colour(): void
    {
        $club = Club::factory()->create();

        $this->put(route('admin.portal-settings.update'), ['brand' => 'teal'])->assertRedirect(route('login'));
        $this->actingAsMember($club);
        $this->put(route('admin.portal-settings.update'), ['brand' => 'teal'])->assertForbidden();
        $this->actingAsClubAdmin($club);
        $this->put(route('admin.portal-settings.update'), ['brand' => 'teal'])->assertForbidden();

        $this->assertSame(0, PortalSetting::count());
        $this->assertSame(BrandPalette::DEFAULT, BrandPalette::current());
    }

    public function test_only_a_listed_preset_is_accepted(): void
    {
        $this->actingAsSuperAdmin();

        foreach (['#ff0000', 'red', 'teal;}body{display:none}', '</style><script>alert(1)</script>', ''] as $bad) {
            $this->put(route('admin.portal-settings.update'), ['brand' => $bad])->assertSessionHasErrors('brand');
        }
        $this->put(route('admin.portal-settings.update'), ['brand' => ['teal']])->assertSessionHasErrors('brand');

        $this->assertSame(0, PortalSetting::where('key', 'brand')->count());
    }

    public function test_the_default_adds_no_style_anywhere(): void
    {
        $club = Club::factory()->create();

        $this->get('/login')->assertOk()->assertDontSee('data-brand=', false)
            ->assertSee('<meta name="theme-color" content="#5B4FCF" media="(prefers-color-scheme: light)">', false);
        $this->get('/dev/ui-kit')->assertOk()->assertDontSee('data-brand=', false);
        $this->get('/no-such-page')->assertNotFound()->assertDontSee('data-brand=', false);
        $this->actingAsClubAdmin($club);
        $this->get(route('admin.clubs.members.index', $club))->assertOk()->assertDontSee('data-brand=', false);
    }

    public function test_a_chosen_preset_reaches_every_layout_with_the_nonce_and_is_audited(): void
    {
        $club = Club::factory()->create();
        $this->actingAsSuperAdmin();

        $this->put(route('admin.portal-settings.update'), ['brand' => 'teal'])
            ->assertRedirect(route('admin.portal-settings.edit'))
            ->assertSessionHasNoErrors();

        $this->assertSame('teal', PortalSettings::get('brand'));
        $log = AuditLog::where('action', 'portal.settings_updated')->sole();
        $this->assertNull($log->old_values['brand']);
        $this->assertSame('teal', $log->new_values['brand']);
        $this->assertStringContainsString('brand', $log->description);

        $css = (string) BrandPalette::css('teal');
        $check = function ($response) use ($css) {
            $html = $response->getContent();
            $this->assertMatchesRegularExpression('/<style nonce="([^"]+)" data-brand="teal">/', $html);
            preg_match('/<style nonce="([^"]+)" data-brand="teal">/', $html, $m);
            // The block carries this response's CSP nonce; the policy is not relaxed.
            $csp = (string) $response->headers->get('Content-Security-Policy');
            $this->assertStringContainsString("'nonce-{$m[1]}'", $csp);
            $this->assertStringNotContainsString("style-src 'self' 'unsafe-inline'", $csp);
            $this->assertStringContainsString($css, $html);
            $this->assertStringContainsString('<meta name="theme-color" content="#266D6D" media="(prefers-color-scheme: light)">', $html);
        };

        // Classic admin (Bootstrap) page.
        $check($this->get(route('admin.portal-settings.edit'))->assertOk());
        $this->actingAsClubAdmin($club);
        $check($this->get(route('admin.clubs.members.index', $club))->assertOk());
        // Inertia root view.
        $check($this->get('/dev/ui-kit')->assertOk());
        // Sign-in pages and the branded error pages (layouts.auth).
        auth()->logout();
        $check($this->get('/login')->assertOk());
        $check($this->get('/no-such-page')->assertNotFound());
    }

    public function test_choosing_lavender_again_stores_nothing(): void
    {
        $this->actingAsSuperAdmin();
        $this->put(route('admin.portal-settings.update'), ['brand' => 'maroon']);
        $this->assertSame('maroon', BrandPalette::current());

        $this->put(route('admin.portal-settings.update'), ['brand' => 'lavender'])->assertSessionHasNoErrors();

        $this->assertSame(0, PortalSetting::where('key', 'brand')->count());
        $this->assertSame(BrandPalette::DEFAULT, BrandPalette::current());
        $this->get(route('admin.portal-settings.edit'))->assertDontSee('data-brand=', false);
    }

    public function test_saving_without_the_field_keeps_the_colour(): void
    {
        $this->actingAsSuperAdmin();
        PortalSettings::put(['brand' => 'blue']);

        $this->put(route('admin.portal-settings.update'), ['name' => 'Portal Baru'])->assertSessionHasNoErrors();

        $this->assertSame('blue', BrandPalette::current());
    }

    public function test_a_tampered_stored_value_falls_back_to_the_default(): void
    {
        PortalSetting::create(['key' => 'brand', 'value' => '</style><script>alert(1)</script>']);
        PortalSettings::flush();

        $this->assertSame(BrandPalette::DEFAULT, BrandPalette::current());
        $this->get('/login')->assertOk()->assertDontSee('data-brand=', false)->assertDontSee('alert(1)', false);
    }

    public function test_the_settings_page_lists_every_preset_with_swatches(): void
    {
        PortalSettings::put(['brand' => 'slate']);
        $this->actingAsSuperAdmin();

        $response = $this->get(route('admin.portal-settings.edit'))->assertOk()
            ->assertSee('Brand colour')
            ->assertSee('data-testid="app-icon-note"', false)
            ->assertSee('may keep the old icon and name');
        foreach (BrandPalette::keys() as $key) {
            $response->assertSee('id="f-brand-'.$key.'"', false)
                ->assertSee('data-swatch="'.$key.'-light"', false)
                ->assertSee('data-swatch="'.$key.'-dark"', false)
                ->assertSee('background:'.BrandPalette::hex($key, 'light'), false);
        }
        $this->assertMatchesRegularExpression('/value="slate" id="f-brand-slate" checked/', $response->getContent());
    }
}
