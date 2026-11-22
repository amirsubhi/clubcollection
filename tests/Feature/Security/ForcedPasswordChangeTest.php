<?php

namespace Tests\Feature\Security;

use App\Http\Middleware\HandleInertiaRequests;
use App\Mail\AccountSecurityNotice;
use App\Mail\MemberWelcome;
use App\Models\AuditLog;
use App\Models\Club;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/** An emailed temporary password must be replaced at first sign-in (users.must_change_password). */
class ForcedPasswordChangeTest extends TestCase
{
    private const NEW = 'kopi teh susu panas';

    private function memberWithTemporaryPassword(Club $club): User
    {
        // actingAsMember signs the user in too; tests that need a guest sign out themselves.
        $user = $this->actingAsMember($club);
        $user->forceFill(['must_change_password' => true])->save();

        return $user;
    }

    // ── The flag is set wherever an admin emails a temporary password ────

    public function test_a_member_added_by_an_admin_must_change_the_emailed_password(): void
    {
        Mail::fake();
        $club = Club::factory()->create();
        $this->actingAsClubAdmin($club);

        $this->post(route('admin.clubs.members.store', $club), [
            'name' => 'Ahli Baru', 'email' => 'baru@example.test', 'job_level' => 'executive', 'joined_date' => now()->toDateString(),
        ])->assertRedirect();

        Mail::assertSent(MemberWelcome::class);
        $this->assertTrue(User::where('email', 'baru@example.test')->sole()->must_change_password);
        $this->assertDatabaseHas('users', ['email' => 'baru@example.test', 'must_change_password' => true]);
    }

    public function test_members_from_a_csv_import_must_change_the_emailed_password(): void
    {
        Mail::fake();
        $club = Club::factory()->create();
        $this->actingAsClubAdmin($club);
        $csv = "name,email,job_level,role,joined_date\nAhli Import,import@example.test,executive,member,2025-01-01\n";

        $this->post(route('admin.members.import.process', $club), [
            'file' => UploadedFile::fake()->createWithContent('members.csv', $csv),
        ]);

        $this->assertDatabaseHas('users', ['email' => 'import@example.test', 'must_change_password' => true]);
    }

    public function test_an_admin_created_by_the_super_admin_must_change_the_emailed_password(): void
    {
        Mail::fake();
        $this->actingAsSuperAdmin();

        $this->post(route('admin.admins.store'), ['name' => 'Admin Baru', 'email' => 'admin-baru@example.test', 'role' => 'admin'])->assertRedirect();

        $this->assertDatabaseHas('users', ['email' => 'admin-baru@example.test', 'must_change_password' => true]);
    }

    public function test_existing_and_factory_users_are_not_affected(): void
    {
        $this->assertFalse(User::factory()->create()->fresh()->must_change_password);

        $club = Club::factory()->create();
        $this->actingAsMember($club);
        $this->get(route('member.dashboard'))->assertOk();
    }

    // ── Redirect until done ───────────────────────────────────────────────

    public function test_every_page_sends_the_user_to_the_change_password_page(): void
    {
        $club = Club::factory()->create();
        $user = $this->memberWithTemporaryPassword($club);
        $this->actingAs($user);

        $this->get('/home')->assertRedirect(route('password.change'));
        $this->get(route('member.dashboard'))->assertRedirect(route('password.change'));
        $this->get(route('profile.security'))->assertRedirect(route('password.change'));

        $this->get(route('password.change'))->assertOk()
            ->assertSee('Pilih kata laluan anda sendiri')
            ->assertSee('Kata laluan sementara (daripada e-mel anda)')
            ->assertSee('Gunakan sekurang-kurangnya 12 aksara.')
            ->assertSee('action="'.route('logout').'"', false)
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_an_inertia_visit_gets_a_full_page_location_not_an_xhr_redirect(): void
    {
        $club = Club::factory()->create();
        $user = $this->memberWithTemporaryPassword($club);
        $this->actingAs($user);
        $version = (string) app(HandleInertiaRequests::class)->version(request());

        $this->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $version, 'X-Requested-With' => 'XMLHttpRequest'])
            ->get(route('member.dashboard'))
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', route('password.change'));
    }

    public function test_logout_is_allowed(): void
    {
        $club = Club::factory()->create();
        $this->actingAs($this->memberWithTemporaryPassword($club));

        $this->post(route('logout'))->assertRedirect('/');
        $this->assertGuest();
    }

    // ── Changing it ───────────────────────────────────────────────────────

    public function test_changing_the_password_clears_the_flag_and_keeps_this_session_signed_in(): void
    {
        Mail::fake();
        $club = Club::factory()->create();
        $user = $this->memberWithTemporaryPassword($club);
        $this->actingAs($user);
        $this->get(route('password.change'))->assertOk();

        $this->post(route('password.change.update'), [
            'current_password' => 'password', 'password' => self::NEW, 'password_confirmation' => self::NEW,
        ])->assertSessionHasNoErrors()->assertRedirect('/home');

        $fresh = $user->fresh();
        $this->assertFalse($fresh->must_change_password);
        $this->assertTrue(Hash::check(self::NEW, $fresh->password));
        // The same session goes on (auth.session stored the new hash), straight to the member home.
        $this->get('/home')->assertRedirect(route('member.dashboard'));
        $this->assertAuthenticatedAs($fresh);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.password_changed', 'user_id' => $user->id]);
        // Replacing the emailed temporary password is expected: no "your password was changed" email.
        Mail::assertNotSent(AccountSecurityNotice::class);
    }

    public function test_another_session_is_signed_out_after_the_change(): void
    {
        $club = Club::factory()->create();
        $user = $this->memberWithTemporaryPassword($club);
        $this->actingAs($user)->get(route('password.change'));
        $hashKey = 'password_hash_'.auth()->getDefaultDriver();
        $old = session($hashKey);

        $this->post(route('password.change.update'), [
            'current_password' => 'password', 'password' => self::NEW, 'password_confirmation' => self::NEW,
        ])->assertRedirect('/home');

        $this->actingAs($user->fresh())->withSession([$hashKey => $old])->get('/home')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_wrong_current_password_short_or_same_password_are_refused_gently(): void
    {
        $club = Club::factory()->create();
        $user = $this->memberWithTemporaryPassword($club);
        $this->actingAs($user);

        $this->from(route('password.change'))->post(route('password.change.update'), [
            'current_password' => 'wrong', 'password' => self::NEW, 'password_confirmation' => self::NEW,
        ])->assertSessionHasErrors(['current_password' => 'Itu bukan kata laluan semasa anda. Sila cuba lagi.']);

        $this->from(route('password.change'))->post(route('password.change.update'), [
            'current_password' => 'password', 'password' => 'pendek', 'password_confirmation' => 'pendek',
        ])->assertSessionHasErrors('password');

        $this->assertTrue($user->fresh()->must_change_password);

        // A long current password typed again as the new one.
        $user = $this->memberWithTemporaryPassword($club);
        $user->forceFill(['password' => Hash::make('the same old long one')])->save();
        $this->flushSession();
        $this->actingAs($user->fresh());
        $this->from(route('password.change'))->post(route('password.change.update'), [
            'current_password' => 'the same old long one', 'password' => 'the same old long one', 'password_confirmation' => 'the same old long one',
        ])->assertSessionHasErrors(['password' => 'Kata laluan baharu mesti berbeza daripada kata laluan semasa.']);

        $this->assertTrue($user->fresh()->must_change_password);
    }

    public function test_a_voluntary_change_emails_the_owner(): void
    {
        Mail::fake();
        $club = Club::factory()->create();
        $user = $this->actingAsMember($club);

        $this->get(route('password.change'))->assertOk()->assertSee('Tukar kata laluan');
        $this->post(route('password.change.update'), [
            'current_password' => 'password', 'password' => self::NEW, 'password_confirmation' => self::NEW,
        ])->assertRedirect('/home');

        Mail::assertSent(AccountSecurityNotice::class, fn ($m) => $m->hasTo($user->email) && $m->kind === AccountSecurityNotice::KIND_PASSWORD_CHANGED);
    }

    public function test_a_password_reset_also_clears_the_flag(): void
    {
        $club = Club::factory()->create();
        $user = $this->memberWithTemporaryPassword($club);

        $token = Password::broker()->createToken($user);
        $this->post(route('password.update'), [
            'token' => $token, 'email' => $user->email, 'password' => self::NEW, 'password_confirmation' => self::NEW,
        ])->assertRedirect('/home');

        $this->assertFalse($user->fresh()->must_change_password);
        $this->get(route('member.dashboard'))->assertOk();
        $this->assertSame(1, AuditLog::where('action', 'auth.password_reset')->count());
    }
}
