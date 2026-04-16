<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class TwoFactorTest extends TestCase
{
    // ── ProfileController: enable / confirm / disable ──────────────────────

    public function test_enable_two_factor_provisions_secret_and_qr(): void
    {
        $this->actingAsSuperAdmin();

        $this->post(route('profile.2fa.enable'))
            ->assertOk()
            ->assertViewHas('qrSvg')
            ->assertViewHas('secret');

        $user = User::first();
        $this->assertNotNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_confirmed_at, 'should not be confirmed until verify');
    }

    public function test_confirm_two_factor_with_valid_code_enables_2fa(): void
    {
        $user = $this->actingAsSuperAdmin();
        $this->post(route('profile.2fa.enable'));

        $secret = $user->fresh()->getTwoFactorSecret();
        $code   = (new Google2FA())->getCurrentOtp($secret);

        $this->post(route('profile.2fa.confirm'), ['code' => $code])
            ->assertRedirect(route('profile.security'));

        $fresh = $user->fresh();
        $this->assertNotNull($fresh->two_factor_confirmed_at);
        $this->assertCount(8, $fresh->getTwoFactorRecoveryCodes());
        $this->assertDatabaseHas('audit_logs', ['action' => '2fa.enabled', 'user_id' => $user->id]);
    }

    public function test_confirm_two_factor_with_invalid_code_keeps_2fa_off(): void
    {
        $user = $this->actingAsSuperAdmin();
        $this->post(route('profile.2fa.enable'));

        $this->post(route('profile.2fa.confirm'), ['code' => '000000'])
            ->assertRedirect();

        $this->assertNull($user->fresh()->two_factor_confirmed_at);
    }

    public function test_disable_two_factor_requires_correct_password(): void
    {
        // The User model casts 'password' => 'hashed', so we pass plain text
        // and let Eloquent hash on save (avoiding a double-hash via Hash::make).
        $user = User::factory()->create([
            'role'                    => 'super_admin',
            'password'                => 'SuperSecret!2026',
            'two_factor_secret'       => encrypt('JBSWY3DPEHPK3PXP'),
            'two_factor_confirmed_at' => now(),
        ]);
        $this->actingAs($user);
        // The route is behind the `two_factor` middleware which would otherwise
        // bounce a 2FA-enabled user to /two-factor-challenge; mark the session
        // verified so we exercise the controller.
        $this->withSession(['two_factor_verified' => true]);

        $this->delete(route('profile.2fa.disable'), ['password' => 'wrong'])
            ->assertSessionHasErrors('password');

        $this->assertNotNull($user->fresh()->two_factor_confirmed_at);

        $this->delete(route('profile.2fa.disable'), ['password' => 'SuperSecret!2026'])
            ->assertRedirect(route('profile.security'));

        $this->assertNull($user->fresh()->two_factor_confirmed_at);
        $this->assertDatabaseHas('audit_logs', ['action' => '2fa.disabled', 'user_id' => $user->id]);
    }

    // ── TwoFactorChallengeController: verify with TOTP ─────────────────────

    public function test_challenge_verify_with_valid_totp_logs_in_and_audits(): void
    {
        $secret = (new Google2FA())->generateSecretKey();
        $user   = User::factory()->create([
            'role'                    => 'super_admin',
            'two_factor_secret'       => encrypt($secret),
            'two_factor_confirmed_at' => now(),
        ]);

        $code = (new Google2FA())->getCurrentOtp($secret);

        // withSession sets the session BEFORE the request is dispatched, so
        // the controller sees the pinned two_factor_user_id.
        $this->withSession(['two_factor_user_id' => $user->id])
            ->post(route('two-factor.verify'), ['code' => $code])
            ->assertRedirect();

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login', 'user_id' => $user->id]);
    }

    public function test_challenge_verify_with_invalid_totp_audits_failure(): void
    {
        $secret = (new Google2FA())->generateSecretKey();
        $user   = User::factory()->create([
            'role'                    => 'admin',
            'two_factor_secret'       => encrypt($secret),
            'two_factor_confirmed_at' => now(),
        ]);

        $this->withSession(['two_factor_user_id' => $user->id])
            ->post(route('two-factor.verify'), ['code' => '000000'])
            ->assertRedirect()
            ->assertSessionHasErrors('code');

        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', ['action' => '2fa.failed', 'user_id' => $user->id]);
    }

    // ── TwoFactorChallengeController: verify with recovery code ────────────

    public function test_challenge_verify_with_recovery_code_consumes_it_and_audits(): void
    {
        $codes = ['ABCD-1234', 'EFGH-5678', 'IJKL-9012'];
        $user  = User::factory()->create([
            'role'                       => 'admin',
            'two_factor_secret'          => encrypt('JBSWY3DPEHPK3PXP'),
            'two_factor_recovery_codes'  => encrypt(json_encode($codes)),
            'two_factor_confirmed_at'    => now(),
        ]);

        $this->withSession(['two_factor_user_id' => $user->id])
            ->post(route('two-factor.verify'), ['recovery_code' => 'EFGH-5678'])
            ->assertRedirect();

        $this->assertAuthenticatedAs($user);
        $remaining = $user->fresh()->getTwoFactorRecoveryCodes();
        $this->assertCount(2, $remaining);
        $this->assertNotContains('EFGH-5678', $remaining);

        $this->assertDatabaseHas('audit_logs', ['action' => '2fa.recovery_code_used', 'user_id' => $user->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login', 'user_id' => $user->id]);
    }

    public function test_challenge_verify_with_invalid_recovery_code_audits_failure(): void
    {
        $user = User::factory()->create([
            'role'                       => 'admin',
            'two_factor_secret'          => encrypt('JBSWY3DPEHPK3PXP'),
            'two_factor_recovery_codes'  => encrypt(json_encode(['ABCD-1234'])),
            'two_factor_confirmed_at'    => now(),
        ]);

        $this->withSession(['two_factor_user_id' => $user->id])
            ->post(route('two-factor.verify'), ['recovery_code' => 'WXYZ-0000'])
            ->assertRedirect()
            ->assertSessionHasErrors('recovery_code');

        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', ['action' => '2fa.failed', 'user_id' => $user->id]);
    }

    // ── Throttle ───────────────────────────────────────────────────────────

    public function test_challenge_verify_is_throttled_after_5_failures(): void
    {
        $user = User::factory()->create([
            'role'                    => 'admin',
            'two_factor_secret'       => encrypt('JBSWY3DPEHPK3PXP'),
            'two_factor_confirmed_at' => now(),
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->withSession(['two_factor_user_id' => $user->id])
                ->post(route('two-factor.verify'), ['code' => '000000']);
        }

        // 6th attempt should be throttled (HTTP 429).
        $this->withSession(['two_factor_user_id' => $user->id])
            ->post(route('two-factor.verify'), ['code' => '000000'])
            ->assertStatus(429);
    }
}
