<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\Club;
use App\Models\PortalSetting;
use App\Support\Portal\PortalSettings;
use Illuminate\Support\Facades\Storage;
use Tests\Support\LogoFixtures;
use Tests\TestCase;

/** Settings phase 1: the portal name and logo (super admin only). */
class PortalSettingsTest extends TestCase
{
    use LogoFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('public');
    }

    public function test_only_the_super_admin_may_open_or_save_it(): void
    {
        $club = Club::factory()->create();

        $this->get(route('admin.portal-settings.edit'))->assertRedirect(route('login'));
        $this->put(route('admin.portal-settings.update'), ['name' => 'X'])->assertRedirect(route('login'));

        $this->actingAsMember($club);
        $this->get(route('admin.portal-settings.edit'))->assertForbidden();
        $this->put(route('admin.portal-settings.update'), ['name' => 'X'])->assertForbidden();

        $this->actingAsClubAdmin($club);
        $this->get(route('admin.portal-settings.edit'))->assertForbidden();
        $this->put(route('admin.portal-settings.update'), ['name' => 'X'])->assertForbidden();

        $this->assertSame(0, PortalSetting::count());

        $this->actingAsSuperAdmin();
        $this->get(route('admin.portal-settings.edit'))->assertOk()->assertSee('Portal settings');
    }

    public function test_with_an_empty_table_the_name_falls_back_to_app_name(): void
    {
        $this->assertSame(0, PortalSetting::count());
        $this->assertSame('Club Portal', PortalSettings::name());
        $this->assertNull(PortalSettings::logoUrl());

        $this->get('/login')->assertOk()->assertSee('<title>Log masuk · Club Portal</title>', false);
    }

    public function test_the_name_is_applied_app_wide_and_audited(): void
    {
        $this->actingAsSuperAdmin();

        $this->put(route('admin.portal-settings.update'), ['name' => 'Portal Kelab <Staf>'])
            ->assertRedirect(route('admin.portal-settings.edit'))
            ->assertSessionHasNoErrors();

        $this->assertSame('Portal Kelab <Staf>', config('app.name'));
        $log = AuditLog::where('action', 'portal.settings_updated')->sole();
        $this->assertNull($log->old_values['name']);
        $this->assertSame('Portal Kelab <Staf>', $log->new_values['name']);

        // Admin pages: title and sidebar, escaped.
        $this->get(route('admin.portal-settings.edit'))
            ->assertSee('Portal Kelab &lt;Staf&gt;', false)
            ->assertDontSee('Portal Kelab <Staf>', false);

        // Sign-in page (several clubs: the portal brand).
        Club::factory()->count(2)->create();
        auth()->logout();
        $this->get('/login')->assertSee('<title>Log masuk · Portal Kelab &lt;Staf&gt;</title>', false);

        // A new request reads it back from the cache / table.
        PortalSettings::flush();
        $this->assertSame('Portal Kelab <Staf>', PortalSettings::name());
    }

    public function test_clearing_the_name_goes_back_to_app_name(): void
    {
        $this->actingAsSuperAdmin();
        $this->put(route('admin.portal-settings.update'), ['name' => 'Nama Lain']);
        $this->assertSame('Nama Lain', config('app.name'));

        $this->put(route('admin.portal-settings.update'), ['name' => '']);

        $this->assertSame('Club Portal', config('app.name'));
        $this->assertSame(0, PortalSetting::where('key', 'name')->count());
    }

    public function test_the_name_is_capped_at_100_characters(): void
    {
        $this->actingAsSuperAdmin();

        $this->put(route('admin.portal-settings.update'), ['name' => str_repeat('a', 101)])->assertSessionHasErrors('name');
    }

    public function test_the_logo_is_re_encoded_shown_in_the_sidebar_and_removable(): void
    {
        $this->actingAsSuperAdmin();

        $this->put(route('admin.portal-settings.update'), ['logo' => $this->upload('logo.svg', $this->svgBytes())])
            ->assertSessionHasErrors('logo');
        $this->put(route('admin.portal-settings.update'), ['logo' => $this->upload('logo.png', $this->pngBytes(900, 300))])
            ->assertSessionHasNoErrors();

        $path = PortalSettings::get('logo');
        $this->assertMatchesRegularExpression('#^portal/[0-9a-f-]{36}\.png$#', $path);
        $this->assertSame(512, getimagesizefromstring(Storage::disk('public')->get($path))[0]);
        $this->assertSame($path, AuditLog::where('action', 'portal.settings_updated')->latest('id')->first()->new_values['logo']);

        // The admin sidebar (Blade) uses it instead of the built-in mark.
        $this->get(route('admin.portal-settings.edit'))->assertSee('class="logo logo-img"', false)->assertSee(asset('storage/'.$path), false);

        $this->put(route('admin.portal-settings.update'), ['remove_logo' => '1']);
        $this->assertNull(PortalSettings::get('logo'));
        Storage::disk('public')->assertExists($path);
    }

    public function test_the_inertia_admin_sidebar_gets_the_brand(): void
    {
        $club = Club::factory()->create();
        PortalSettings::put(['name' => 'Portal ABC']);
        $this->actingAsClubAdmin($club);

        $this->get(route('admin.clubs.dashboard', $club))
            ->assertInertia(fn ($page) => $page->where('nav.brandName', 'Portal ABC')->where('nav.brandLogoUrl', null)->where('portal.name', 'Portal ABC'));
    }

    public function test_pages_render_when_the_settings_cannot_be_read(): void
    {
        // As on an error page with the database down: reads fail and fall back to the defaults.
        $default = config('database.default');
        config([
            'database.connections.unreachable' => ['driver' => 'sqlite', 'database' => '/nonexistent/dir/db.sqlite', 'prefix' => ''],
            'database.default' => 'unreachable',
        ]);
        PortalSettings::flush();

        try {
            $this->assertSame('Club Portal', PortalSettings::name());
            $this->assertNull(PortalSettings::logoUrl());
            $this->get('/login')->assertOk()->assertSee('Club Portal');
        } finally {
            config(['database.default' => $default]);
        }
    }
}
