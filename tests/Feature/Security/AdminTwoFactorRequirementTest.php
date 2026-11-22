<?php

namespace Tests\Feature\Security;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\AuditLog;
use App\Models\Club;
use App\Models\User;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/** REQUIRE_ADMIN_2FA: admins must turn 2FA on before the admin pages open; members stay optional. Turning 2FA off needs a code. */
class AdminTwoFactorRequirementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['security.require_admin_two_factor' => true]);
    }

    public function test_it_is_on_by_default(): void
    {
        // Read the file itself: TestCase turns the setting off for the rest of the suite.
        $config = require config_path('security.php');
        $this->assertTrue($config['require_admin_two_factor']);
    }

    public function test_a_super_admin_without_2fa_is_sent_to_the_security_page(): void
    {
        $this->actingAsSuperAdmin();

        $this->get('/home')->assertRedirect(route('profile.security'));
        $this->get(route('admin.clubs.index'))->assertRedirect(route('profile.security'));
        $this->get(route('profile.security'))->assertOk()->assertSee('Turn on 2FA to continue')->assertSee('data-testid="2fa-required"', false);
    }

    public function test_a_club_admin_without_2fa_is_sent_to_the_security_page_also_from_inertia_pages(): void
    {
        $club = Club::factory()->create();
        $this->actingAsClubAdmin($club);
        $version = (string) app(HandleInertiaRequests::class)->version(request());

        $this->get(route('admin.clubs.dashboard', $club))->assertRedirect(route('profile.security'));
        $this->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $version, 'X-Requested-With' => 'XMLHttpRequest'])
            ->get(route('admin.clubs.dashboard', $club))
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', route('profile.security'));
    }

    public function test_members_are_not_forced(): void
    {
        $club = Club::factory()->create();
        $this->actingAsMember($club);

        $this->get(route('member.dashboard'))->assertOk();
    }

    public function test_the_setting_off_lets_admins_in_without_2fa(): void
    {
        config(['security.require_admin_two_factor' => false]);
        $this->actingAsSuperAdmin();

        $this->get(route('admin.clubs.index'))->assertOk();
        $this->get(route('profile.security'))->assertOk()->assertDontSee('data-testid="2fa-required"', false);
    }

    public function test_setting_up_2fa_is_allowed_and_then_the_admin_pages_open(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($admin);

        $this->post(route('profile.2fa.enable'), ['current_password' => 'password'])->assertOk()->assertSee('<svg', false);
        $code = (new Google2FA())->getCurrentOtp($admin->fresh()->getTwoFactorSecret());
        $this->post(route('profile.2fa.confirm'), ['code' => $code, 'current_password' => 'password'])
            ->assertRedirect(route('profile.security'));

        // The one-time recovery codes are shown (not redirected away), then the admin pages open.
        $this->get(route('profile.security'))->assertOk()->assertSee('data-testid="recovery-codes"', false);
        $this->get(route('admin.clubs.index'))->assertOk();
    }

    public function test_an_admin_with_2fa_is_not_affected(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $this->actingAs($admin)->withSession(['two_factor_verified' => true]);

        $this->get(route('admin.clubs.index'))->assertOk();
    }

    public function test_logout_is_allowed(): void
    {
        $this->actingAsSuperAdmin();

        $this->post(route('logout'))->assertRedirect('/');
        $this->assertGuest();
    }

    public function test_a_new_admin_first_changes_the_temporary_password_then_sets_up_2fa(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $admin->forceFill(['must_change_password' => true])->save();
        $this->actingAs($admin);

        // No loop between the two rules: the password page first ...
        $this->get('/home')->assertRedirect(route('password.change'));
        $this->get(route('profile.security'))->assertRedirect(route('password.change'));
        $this->get(route('password.change'))->assertOk();

        $this->post(route('password.change.update'), [
            'current_password' => 'password', 'password' => 'kopi teh susu panas', 'password_confirmation' => 'kopi teh susu panas',
        ])->assertRedirect('/home');

        // ... then 2FA.
        $this->get('/home')->assertRedirect(route('profile.security'));
        $this->get(route('profile.security'))->assertOk();
    }

    // ── Item 10: turning 2FA off needs a code as well as the password ─────

    private function adminWith2fa(): User
    {
        $admin = User::factory()->create(['role' => 'admin', 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $admin->forceFill(['two_factor_recovery_codes' => encrypt(json_encode(['ABCD-EFGH', 'JKLM-NPQR']))])->save();
        $this->actingAs($admin)->withSession(['two_factor_verified' => true]);

        return $admin;
    }

    public function test_the_password_alone_does_not_turn_2fa_off(): void
    {
        $admin = $this->adminWith2fa();

        $this->delete(route('profile.2fa.disable'), ['password' => 'password'])->assertSessionHasErrors('code');
        $this->delete(route('profile.2fa.disable'), ['password' => 'password', 'code' => '000000'])->assertSessionHasErrors('code');
        $this->delete(route('profile.2fa.disable'), ['password' => 'password', 'code' => 'WXYZ-2345'])->assertSessionHasErrors('code');

        $this->assertTrue($admin->fresh()->hasEnabledTwoFactor());
        // The two wrong codes are audited (the missing one is a form error).
        $this->assertSame(2, AuditLog::where('action', '2fa.disable_failed')->count());
    }

    public function test_five_wrong_codes_lock_turning_2fa_off_even_for_the_right_code(): void
    {
        $admin = $this->adminWith2fa();
        for ($i = 0; $i < 5; $i++) {
            $this->delete(route('profile.2fa.disable'), ['password' => 'password', 'code' => '00000'.$i])->assertSessionHasErrors('code');
            \Illuminate\Support\Facades\RateLimiter::clear('2fa-setup|'.$admin->id); // the per-minute form limit is not what is tested here
        }
        $code = (new Google2FA())->getCurrentOtp('JBSWY3DPEHPK3PXP');
        $this->delete(route('profile.2fa.disable'), ['password' => 'password', 'code' => $code])->assertSessionHasErrors('code');
        $this->assertTrue($admin->fresh()->hasEnabledTwoFactor());

        $this->travel(16)->minutes();
        $code = (new Google2FA())->getCurrentOtp('JBSWY3DPEHPK3PXP');
        $this->delete(route('profile.2fa.disable'), ['password' => 'password', 'code' => $code])->assertSessionHasNoErrors();
        $this->assertFalse($admin->fresh()->hasEnabledTwoFactor());
    }

    public function test_one_code_or_recovery_code_cannot_be_used_twice_by_racing_requests(): void
    {
        $admin = $this->adminWith2fa();
        // Two copies loaded before either request saves: what two parallel requests would each hold.
        $first = User::find($admin->id);
        $second = User::find($admin->id);
        $code = (new Google2FA())->getCurrentOtp('JBSWY3DPEHPK3PXP');
        $this->assertTrue($first->verifyTwoFactorCode($code));
        $this->assertFalse($second->verifyTwoFactorCode($code));

        $first = User::find($admin->id);
        $second = User::find($admin->id);
        $this->assertTrue($first->verifyTwoFactorCodeOrRecoveryCode('ABCD-EFGH'));
        $this->assertFalse($second->verifyTwoFactorCodeOrRecoveryCode('ABCD-EFGH'));
        $this->assertSame(['JKLM-NPQR'], $admin->fresh()->getTwoFactorRecoveryCodes());
    }

    public function test_a_current_code_turns_2fa_off(): void
    {
        $admin = $this->adminWith2fa();
        $code = (new Google2FA())->getCurrentOtp('JBSWY3DPEHPK3PXP');

        $this->delete(route('profile.2fa.disable'), ['password' => 'password', 'code' => $code])
            ->assertSessionHasNoErrors()->assertRedirect(route('profile.security'));
        $this->assertFalse($admin->fresh()->hasEnabledTwoFactor());
    }

    public function test_a_recovery_code_turns_2fa_off(): void
    {
        $admin = $this->adminWith2fa();

        $this->delete(route('profile.2fa.disable'), ['password' => 'password', 'code' => 'jklm npqr'])
            ->assertSessionHasNoErrors()->assertRedirect(route('profile.security'));
        $this->assertFalse($admin->fresh()->hasEnabledTwoFactor());
        $this->assertDatabaseHas('audit_logs', ['action' => '2fa.disabled', 'user_id' => $admin->id]);
    }

    public function test_the_disable_form_asks_for_the_code(): void
    {
        $this->adminWith2fa();

        $this->get(route('profile.security'))->assertOk()->assertSee('name="code"', false);
    }
}
