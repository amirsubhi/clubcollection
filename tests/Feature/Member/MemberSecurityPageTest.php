<?php

namespace Tests\Feature\Member;

use App\Models\Club;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Members get Security & 2FA inside the member layout (Inertia, BM first); admins keep the Blade page.
 * Turning 2FA on still needs the password at both steps.
 */
class MemberSecurityPageTest extends TestCase
{
    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->member = $this->actingAsMember(Club::factory()->create(['name' => 'Kelab Ujian']));
    }

    public function test_member_gets_the_member_security_page_in_bm(): void
    {
        $this->get(route('profile.security'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Member/Security')
                ->where('locale', 'ms')
                ->where('clubName', 'Kelab Ujian')
                ->where('enabled', false)
                ->where('setup', null)
                ->where('recoveryCodes', null)
                ->where('urls.home', route('member.dashboard'))
                ->where('urls.enable', route('profile.2fa.enable'))
            );
    }

    public function test_admin_still_gets_the_blade_security_page(): void
    {
        $this->actingAsSuperAdmin();

        $res = $this->get(route('profile.security'))
            ->assertOk()
            ->assertViewIs('admin.profile.security');
        $this->assertNoStore($res);
    }

    public function test_member_security_page_is_not_cached_and_its_history_is_encrypted(): void
    {
        $res = $this->get(route('profile.security'))->assertOk();
        $this->assertNoStore($res);
        $this->assertTrue($res->viewData('page')['encryptHistory']);

        // Other member pages are unchanged.
        $this->assertFalse($this->get(route('member.dashboard'))->viewData('page')['encryptHistory']);
    }

    private function assertNoStore($res): void
    {
        $cache = (string) $res->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cache);
        $this->assertStringContainsString('private', $cache);
    }

    public function test_member_errors_are_member_codes(): void
    {
        $this->post(route('profile.2fa.enable'), ['current_password' => 'wrong'])
            ->assertSessionHasErrors(['current_password' => 'member.2fa_password_invalid']);
        $this->post(route('profile.2fa.enable'), ['current_password' => 'password']);

        $this->post(route('profile.2fa.confirm'), ['code' => '12', 'current_password' => 'password'])
            ->assertSessionHasErrors(['code' => 'member.2fa_code_invalid']);
        $this->post(route('profile.2fa.confirm'), ['code' => '000000', 'current_password' => 'password'])
            ->assertSessionHasErrors(['code' => 'member.2fa_code_invalid']);
    }

    public function test_enable_and_confirm_are_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post(route('profile.2fa.enable'), ['current_password' => 'wrong'])->assertSessionHasErrors('current_password');
        }
        $this->post(route('profile.2fa.enable'), ['current_password' => 'wrong'])->assertStatus(429);
        // One bucket for the whole setup: confirm is refused too.
        $this->post(route('profile.2fa.confirm'), ['code' => '000000', 'current_password' => 'wrong'])->assertStatus(429);
    }

    public function test_confirm_keeps_this_session_signed_in_but_a_new_login_needs_the_code(): void
    {
        $this->post(route('profile.2fa.enable'), ['current_password' => 'password']);
        $code = (new Google2FA())->getCurrentOtp($this->member->fresh()->getTwoFactorSecret());
        $this->post(route('profile.2fa.confirm'), ['code' => $code, 'current_password' => 'password']);

        // Same session: the page with the codes, not the challenge.
        $this->get(route('profile.security'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Member/Security')->has('recoveryCodes', 8));

        $this->post(route('logout'))->assertSessionHas('inertia.clear_history', true);
        $this->assertGuest();

        $this->post(route('login'), ['email' => $this->member->email, 'password' => 'password'])
            ->assertRedirect(route('two-factor.challenge'));
        $this->assertGuest();
    }

    public function test_member_enable_and_confirm_need_the_password(): void
    {
        $this->post(route('profile.2fa.enable'), ['current_password' => 'wrong'])->assertSessionHasErrors('current_password');
        $this->assertNull($this->member->fresh()->two_factor_secret);

        $this->post(route('profile.2fa.enable'), ['current_password' => 'password'])->assertRedirect(route('profile.security'));
        $secret = $this->member->fresh()->getTwoFactorSecret();
        $this->assertNotNull($secret);

        // The page now shows the QR code and the key to type in.
        $this->get(route('profile.security'))->assertInertia(fn (Assert $page) => $page
            ->component('Member/Security')
            ->where('setup.secret', $secret)
            ->has('setup.qrSvg'));

        $code = (new Google2FA())->getCurrentOtp($secret);
        $this->post(route('profile.2fa.confirm'), ['code' => $code])->assertSessionHasErrors('current_password');
        $this->post(route('profile.2fa.confirm'), ['code' => $code, 'current_password' => 'wrong'])->assertSessionHasErrors('current_password');
        $this->assertNull($this->member->fresh()->two_factor_confirmed_at);

        $this->post(route('profile.2fa.confirm'), ['code' => $code, 'current_password' => 'password'])
            ->assertRedirect(route('profile.security'))
            ->assertSessionHas('success', 'member.2fa_enabled');
        $this->assertNotNull($this->member->fresh()->two_factor_confirmed_at);

        // Recovery codes are shown once, then gone; the setup step is over.
        $this->get(route('profile.security'))->assertInertia(fn (Assert $page) => $page
            ->where('enabled', true)
            ->where('setup', null)
            ->has('recoveryCodes', 8));
        $this->get(route('profile.security'))->assertInertia(fn (Assert $page) => $page->where('recoveryCodes', null));
    }

    public function test_member_can_turn_2fa_off_with_the_password_and_a_recovery_code(): void
    {
        $this->post(route('profile.2fa.enable'), ['current_password' => 'password']);
        $code = (new Google2FA())->getCurrentOtp($this->member->fresh()->getTwoFactorSecret());
        $this->post(route('profile.2fa.confirm'), ['code' => $code, 'current_password' => 'password']);
        $recovery = $this->member->fresh()->getTwoFactorRecoveryCodes()[0];

        $this->delete(route('profile.2fa.disable'), ['password' => 'wrong', 'code' => $recovery])->assertSessionHasErrors('password');
        $this->assertNotNull($this->member->fresh()->two_factor_confirmed_at);

        // The password alone is not enough (AdminTwoFactorRequirementTest), and the code just used to turn it on
        // can't be replayed. (Enable, confirm and disable share one 5-a-minute limit.)
        $this->delete(route('profile.2fa.disable'), ['password' => 'password', 'code' => $code])->assertSessionHasErrors(['code' => 'member.2fa_disable_code_invalid']);
        $this->assertNotNull($this->member->fresh()->two_factor_confirmed_at);

        $this->delete(route('profile.2fa.disable'), ['password' => 'password', 'code' => strtolower($recovery)])
            ->assertRedirect(route('profile.security'))
            ->assertSessionHas('success', 'member.2fa_disabled')
            ->assertSessionHas('inertia.clear_history', true);
        $this->assertNull($this->member->fresh()->two_factor_secret);
    }
}
