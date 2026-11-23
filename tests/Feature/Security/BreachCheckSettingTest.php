<?php

namespace Tests\Feature\Security;

use App\Models\AuditLog;
use App\Models\Club;
use App\Models\PortalSetting;
use App\Models\User;
use App\Support\Portal\PortalSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * The breached-password check is a Portal setting (super admin): on by default, read on every validation, and
 * only "off" is stored. The check itself is real here (Have I Been Pwned, faked over HTTP).
 */
class BreachCheckSettingTest extends TestCase
{
    protected bool $fakeBreachedPasswordCheck = false;

    private const BREACHED = 'password1234';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
        // The range API returns "SUFFIX:COUNT" lines for the first five characters of the SHA-1.
        Http::fake(['api.pwnedpasswords.com/range/*' => Http::response(substr(strtoupper(sha1(self::BREACHED)), 5).":42\r\n")]);
    }

    private function reset(User $user, string $password)
    {
        $token = Password::broker()->createToken($user);

        return $this->from('/password/reset/'.$token)->post(route('password.update'), [
            'token' => $token, 'email' => $user->email,
            'password' => $password, 'password_confirmation' => $password,
        ]);
    }

    private function change(string $password)
    {
        return $this->from(route('password.change'))->post(route('password.change.update'), [
            'current_password' => 'password', 'password' => $password, 'password_confirmation' => $password,
        ]);
    }

    private function switchTo(bool $on)
    {
        return $this->put(route('admin.portal-settings.update'), ['breach_check' => $on ? '1' : '0']);
    }

    public function test_on_by_default_a_breached_password_is_refused_on_reset_and_change(): void
    {
        $this->assertTrue(PortalSettings::breachCheckOn());
        $user = User::factory()->create(['role' => 'member']);

        $this->reset($user, self::BREACHED)->assertSessionHasErrors('password');
        $this->assertTrue(Hash::check('password', $user->fresh()->password));

        $this->actingAs($user);
        $this->change(self::BREACHED)->assertSessionHasErrors('password');
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
        Http::assertSentCount(2);
    }

    public function test_switched_off_a_breached_password_is_accepted_and_nothing_is_sent(): void
    {
        $this->actingAsSuperAdmin();
        $this->switchTo(false)->assertRedirect(route('admin.portal-settings.edit'));
        $this->assertSame(PortalSettings::BREACH_CHECK_OFF, PortalSetting::where('key', 'breach_check')->value('value'));
        $this->assertFalse(PortalSettings::breachCheckOn());

        $user = User::factory()->create(['role' => 'member']);
        $this->actingAs($user);
        $this->change(self::BREACHED)->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check(self::BREACHED, $user->fresh()->password));

        $other = User::factory()->create(['role' => 'member']);
        $this->reset($other, self::BREACHED)->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check(self::BREACHED, $other->fresh()->password));
        Http::assertNothingSent();
    }

    public function test_switching_it_back_on_takes_effect_at_once_and_stores_nothing(): void
    {
        $super = $this->actingAsSuperAdmin();
        $this->switchTo(false);
        $this->switchTo(true)->assertRedirect(route('admin.portal-settings.edit'));
        $this->assertSame(0, PortalSetting::where('key', 'breach_check')->count());

        $this->actingAs($super);
        $this->change(self::BREACHED)->assertSessionHasErrors('password');
    }

    public function test_the_change_is_audited_both_ways(): void
    {
        $this->actingAsSuperAdmin();
        $this->switchTo(false);
        $this->switchTo(true);

        $logs = AuditLog::where('action', 'portal.settings_updated')->orderBy('id')->get();
        $this->assertCount(2, $logs);
        $this->assertSame('Portal settings updated (breached-password check turned off).', $logs[0]->description);
        $this->assertSame('Portal settings updated (breached-password check turned on).', $logs[1]->description);
        $this->assertSame(PortalSettings::BREACH_CHECK_OFF, $logs[0]->new_values['breach_check']);
        $this->assertSame(PortalSettings::BREACH_CHECK_OFF, $logs[1]->old_values['breach_check']);
    }

    public function test_saving_without_the_field_leaves_it_alone(): void
    {
        $this->actingAsSuperAdmin();
        $this->switchTo(false);

        // Another form (or an old page) posting only the name never turns the check back on or off.
        $this->put(route('admin.portal-settings.update'), ['name' => 'Portal Kelab'])->assertRedirect()
            ->assertSessionHas('success', 'Saved: portal name.');
        $this->assertFalse(PortalSettings::breachCheckOn());
    }

    public function test_only_the_super_admin_can_change_it(): void
    {
        $club = Club::factory()->create();

        $this->actingAsClubAdmin($club);
        $this->switchTo(false)->assertForbidden();

        $this->actingAsMember($club);
        $this->switchTo(false)->assertForbidden();

        $this->assertTrue(PortalSettings::breachCheckOn());
        $this->assertSame(0, PortalSetting::count());
    }

    public function test_the_form_shows_the_switch_with_its_current_state(): void
    {
        $this->actingAsSuperAdmin();
        $this->get(route('admin.portal-settings.edit'))->assertOk()
            ->assertSee('Refuse new passwords that appear in known data breaches')
            ->assertSee('id="f-breach-check"', false);
        $box = '/<input[^>]*id="f-breach-check"[^>]*>/';
        preg_match($box, $this->get(route('admin.portal-settings.edit'))->getContent(), $on);
        $this->assertStringContainsString('checked', $on[0]);

        $this->switchTo(false);
        preg_match($box, $this->get(route('admin.portal-settings.edit'))->getContent(), $off);
        $this->assertStringNotContainsString('checked', $off[0]);
    }

    public function test_the_flash_says_what_changed_and_the_off_warning_is_in_the_page(): void
    {
        $this->actingAsSuperAdmin();
        $this->switchTo(false)->assertSessionHas('success', 'Saved. Breached-password check turned off.');
        $this->get(route('admin.portal-settings.edit'))->assertOk()
            ->assertSee('data-testid="breach-check-off"', false)
            ->assertSee('How it works');
    }

    public function test_a_field_error_shows_once_under_its_field_with_a_plain_message(): void
    {
        $this->actingAsSuperAdmin();
        $html = $this->followingRedirects()->from(route('admin.portal-settings.edit'))
            ->put(route('admin.portal-settings.update'), ['name' => "Kelab\x07Staf"])
            ->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'The portal name can&#039;t contain tabs, line breaks or other hidden characters.'), $html);
        $this->assertStringNotContainsString('alert alert-danger', $html);
    }

    public function test_a_settings_cache_from_before_this_setting_reads_as_on(): void
    {
        // v2.0.0 cached only name / logo / brand; an upgraded site may still hold that array.
        PortalSettings::flush();
        Cache::forever(PortalSettings::CACHE_KEY, ['name' => 'Portal Lama', 'logo' => null, 'brand' => null]);

        $this->assertSame('Portal Lama', PortalSettings::name());
        $this->assertTrue(PortalSettings::breachCheckOn());
    }
}
