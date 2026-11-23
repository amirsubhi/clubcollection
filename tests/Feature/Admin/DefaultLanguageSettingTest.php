<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\Club;
use App\Models\PortalSetting;
use App\Support\Portal\PortalSettings;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** Portal settings: the default language of the member side (members with no saved choice). Malay unless changed. */
class DefaultLanguageSettingTest extends TestCase
{
    private function choose(string $code)
    {
        return $this->put(route('admin.portal-settings.update'), ['default_locale' => $code]);
    }

    public function test_missing_means_malay(): void
    {
        $this->assertSame('ms', PortalSettings::defaultLocale());

        // A settings cache from before the key existed, or a stored code with no language file.
        PortalSettings::flush();
        Cache::forever(PortalSettings::CACHE_KEY, ['name' => 'Portal']);
        $this->assertSame('ms', PortalSettings::defaultLocale());

        PortalSetting::query()->create(['key' => 'default_locale', 'value' => 'fr']);
        PortalSettings::flush();
        $this->assertSame('ms', PortalSettings::defaultLocale());
    }

    public function test_the_page_lists_the_available_languages(): void
    {
        $this->actingAsSuperAdmin();
        $this->get(route('admin.portal-settings.edit'))->assertOk()
            ->assertSee('Default language')
            ->assertSee('<option value="ms" selected>Bahasa Melayu</option>', false)
            ->assertSee('<option value="en" >English</option>', false);
    }

    public function test_the_super_admin_changes_it_and_it_is_audited(): void
    {
        $this->actingAsSuperAdmin();

        $this->choose('en')->assertRedirect(route('admin.portal-settings.edit'))
            ->assertSessionHas('success', 'Saved: default language.');
        $this->assertSame('en', PortalSettings::defaultLocale());
        $this->assertSame('en', PortalSetting::where('key', 'default_locale')->value('value'));
        $this->get(route('admin.portal-settings.edit'))->assertSee('<option value="en" selected>English</option>', false);

        // Back to Malay: stored as nothing.
        $this->choose('ms')->assertSessionHas('success', 'Saved: default language.');
        $this->assertSame('ms', PortalSettings::defaultLocale());
        $this->assertSame(0, PortalSetting::where('key', 'default_locale')->count());

        $logs = AuditLog::where('action', 'portal.settings_updated')->orderBy('id')->get();
        $this->assertCount(2, $logs);
        $this->assertSame('Portal settings updated (default language: English).', $logs[0]->description);
        $this->assertSame(['default_locale' => 'en'], $logs[0]->new_values);
        $this->assertSame('Portal settings updated (default language: Bahasa Melayu).', $logs[1]->description);
        $this->assertSame(['default_locale' => 'en'], $logs[1]->old_values);

        // The same value again changes nothing.
        $this->choose('ms')->assertSessionHas('success', 'Nothing changed.');
        $this->assertCount(2, AuditLog::where('action', 'portal.settings_updated')->get());
    }

    public function test_only_an_available_language(): void
    {
        $this->actingAsSuperAdmin();
        foreach (['fr', 'bm', 'EN', 'en_US'] as $code) {
            $this->choose($code)->assertSessionHasErrors(['default_locale' => 'Choose one of the listed languages.']);
        }
        $this->assertSame(0, PortalSetting::where('key', 'default_locale')->count());
    }

    public function test_saving_without_the_field_leaves_it_alone(): void
    {
        $this->actingAsSuperAdmin();
        $this->choose('en');
        $this->put(route('admin.portal-settings.update'), ['name' => 'Portal Kelab'])->assertSessionHas('success', 'Saved: portal name.');
        $this->assertSame('en', PortalSettings::defaultLocale());
    }

    public function test_only_the_super_admin_can_change_it(): void
    {
        $club = Club::factory()->create();
        $this->actingAsClubAdmin($club);
        $this->choose('en')->assertForbidden();
        $this->actingAsMember($club);
        $this->choose('en')->assertForbidden();
        $this->assertSame('ms', PortalSettings::defaultLocale());
    }
}
