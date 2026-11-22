<?php

namespace Tests\Feature\Security;

use App\Http\Controllers\Auth\LoginController;
use App\Mail\AccountSecurityNotice;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Sign-in limits: named buckets (login / two-factor / install), per email + IP and a per-IP ceiling that
 * an office network can live with, a per-account pause that survives IP changes, audit rows, owner emails,
 * and a reset request whose work happens after the answer.
 */
class LoginProtectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Many sign-ins here: phpunit.xml already shortens the failed-sign-in floor (AUTH_TIMEBOX_MICROSECONDS).
    }

    private function attempt(string $email, string $password, string $ip = '10.0.0.1')
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->from('/login')
            ->post('/login', ['email' => $email, 'password' => $password]);
    }

    /** Forget the signed-in user between requests (the guard and the test session both remember it). */
    private function signOut(): void
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->assertGuest();
    }

    private function pausedMessage(int $minutes = 15): string
    {
        return __(LoginController::PAUSED_MESSAGE, ['minutes' => $minutes], 'ms');
    }

    // ── Named limiters ────────────────────────────────────────────────────

    public function test_login_two_factor_and_installer_each_have_their_own_named_limiter(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes());
        $post = fn (string $uri) => $routes->first(fn ($r) => $r->uri() === $uri && in_array('POST', $r->methods(), true));

        $this->assertContains('throttle:login', $post('login')->gatherMiddleware());
        $this->assertContains('throttle:two-factor', $post('two-factor-challenge')->gatherMiddleware());
        $this->assertContains('throttle:install', $post('install')->gatherMiddleware());
    }

    public function test_using_up_the_login_limit_does_not_touch_the_two_factor_challenge(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->attempt('someone@example.test', 'wrong');
        }
        $this->attempt('someone@example.test', 'wrong')->assertStatus(429);

        // Same IP, the 2FA challenge still answers (no pending challenge: back to the sign-in page).
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
            ->post(route('two-factor.verify'), ['code' => '123456'])->assertRedirect(route('login'));
    }

    public function test_the_two_factor_limit_is_per_pending_account_so_admins_on_one_ip_are_not_blocked_by_each_other(): void
    {
        $a = User::factory()->create(['role' => 'admin']);
        $b = User::factory()->create(['role' => 'admin']);

        for ($i = 0; $i < 5; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])->withSession(['two_factor_user_id' => $a->id])
                ->post(route('two-factor.verify'), ['code' => '000000']);
        }
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])->withSession(['two_factor_user_id' => $a->id])
            ->post(route('two-factor.verify'), ['code' => '000000'])->assertStatus(429);

        // A colleague's challenge from the same office IP still answers.
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])->withSession(['two_factor_user_id' => $b->id])
            ->post(route('two-factor.verify'), ['code' => '000000'])->assertStatus(302);
    }

    public function test_the_limit_is_per_email_so_colleagues_on_one_office_ip_can_still_sign_in(): void
    {
        $colleague = User::factory()->create(['role' => 'member', 'email' => 'colleague@example.test']);

        for ($i = 0; $i < 5; $i++) {
            $this->attempt('typo@example.test', 'wrong');
        }
        $this->attempt('typo@example.test', 'wrong')->assertStatus(429)->assertSee('Terlalu banyak cubaan');

        // Another member behind the same office IP.
        $this->attempt('colleague@example.test', 'password')->assertRedirect('/home');
        $this->assertAuthenticatedAs($colleague);
    }

    public function test_one_ip_is_capped_at_30_attempts_a_minute(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->attempt("person{$i}@example.test", 'wrong')->assertStatus(302);
        }

        $this->attempt('person-new@example.test', 'wrong')->assertStatus(429);
        // A different IP is not affected.
        $this->attempt('person-new@example.test', 'wrong', '10.0.0.2')->assertStatus(302);
    }

    public function test_a_hit_limit_is_audited_as_a_lockout(): void
    {
        $user = User::factory()->create(['role' => 'member', 'email' => 'locked@example.test']);
        for ($i = 0; $i < 5; $i++) {
            $this->attempt('locked@example.test', 'wrong');
        }
        $this->attempt('locked@example.test', 'wrong')->assertStatus(429);
        // More blocked posts in the same wait add no rows.
        for ($i = 0; $i < 3; $i++) {
            $this->attempt('locked@example.test', 'wrong')->assertStatus(429);
        }

        $rows = AuditLog::where('action', 'auth.lockout')->get();
        $this->assertCount(1, $rows);
        $this->assertSame($user->id, $rows->first()->user_id);
        $this->assertSame('10.0.0.1', $rows->first()->ip_address);
    }

    public function test_the_pause_message_is_in_bahasa_melayu_by_default(): void
    {
        $this->assertStringStartsWith('Log masuk ke akaun ini dihentikan seketika selama 15 minit', $this->pausedMessage());
    }

    public function test_an_array_email_does_not_break_the_limiter(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])->from('/login')
            ->post('/login', ['email' => ['a@example.test'], 'password' => 'x'])
            ->assertRedirect('/login')->assertSessionHasErrors('email');
    }

    // ── Per-account pause (any IP) ────────────────────────────────────────

    public function test_ten_wrong_passwords_from_different_ips_pause_the_account_and_email_the_owner_once(): void
    {
        Mail::fake();
        $user = User::factory()->create(['role' => 'member', 'email' => 'ahli@example.test']);

        for ($i = 1; $i <= 9; $i++) {
            $this->attempt('ahli@example.test', "wrong-{$i}", "10.1.0.{$i}")
                ->assertSessionHasErrors(['email' => trans('auth.failed', [], 'ms')]);
        }
        // The 10th failure starts the pause and says so.
        $this->attempt('ahli@example.test', 'wrong-10', '10.1.0.10')->assertSessionHasErrors(['email' => $this->pausedMessage()]);

        // Even the right password waits, from yet another IP, and nothing more is counted or emailed.
        $this->attempt('AHLI@Example.test', 'password', '10.1.0.11')->assertSessionHasErrors(['email' => $this->pausedMessage()]);
        $this->assertGuest();

        Mail::assertSent(AccountSecurityNotice::class, 1);
        Mail::assertSent(AccountSecurityNotice::class, fn ($m) => $m->hasTo('ahli@example.test') && $m->kind === AccountSecurityNotice::KIND_SIGN_IN_PAUSED);
        $this->assertSame(1, AuditLog::where('action', 'auth.account_blocked')->where('user_id', $user->id)->count());
        $this->assertSame(10, AuditLog::where('action', 'auth.failed')->where('user_id', $user->id)->count());
    }

    public function test_the_pause_email_is_sent_at_most_once_an_hour(): void
    {
        Mail::fake();
        User::factory()->create(['role' => 'member', 'email' => 'ahli@example.test']);
        $pause = function (int $round) {
            for ($i = 1; $i <= 10; $i++) {
                $this->attempt('ahli@example.test', 'wrong', "10.9.{$round}.{$i}");
            }
        };

        $pause(1);
        $this->travel(16)->minutes();
        $pause(2);
        Mail::assertSent(AccountSecurityNotice::class, 1);

        $this->travel(61)->minutes();
        $pause(3);
        Mail::assertSent(AccountSecurityNotice::class, 2);
    }

    public function test_failed_sign_ins_have_a_half_second_floor_so_unknown_emails_are_not_faster(): void
    {
        // phpunit.xml shortens it so the suite stays fast; the shipped default is half a second.
        $this->assertStringContainsString("env('AUTH_TIMEBOX_MICROSECONDS', 500000)", file_get_contents(config_path('auth.php')));
        $this->assertSame(1000, config('auth.timebox_duration'));
    }

    public function test_the_pause_ends_after_15_minutes_and_is_never_permanent(): void
    {
        $user = User::factory()->create(['role' => 'member', 'email' => 'ahli@example.test']);
        for ($i = 1; $i <= 10; $i++) {
            $this->attempt('ahli@example.test', 'wrong', "10.2.0.{$i}");
        }
        $this->attempt('ahli@example.test', 'password', '10.2.0.50')->assertSessionHasErrors(['email' => $this->pausedMessage()]);

        $this->travel(16)->minutes();

        $this->attempt('ahli@example.test', 'password', '10.2.0.51')->assertRedirect('/home');
        $this->assertAuthenticatedAs($user);
    }

    public function test_case_and_space_variants_of_one_email_count_against_the_same_account(): void
    {
        // Rotating spellings must not dodge the pause. (Accent variants such as "josé" also match one row on
        // MariaDB / MySQL *_ci collations; SQLite only folds case, so that is covered by the MariaDB CI run.)
        $user = User::factory()->create(['role' => 'member', 'email' => 'jose@example.test']);
        $variants = ['jose@example.test', 'JOSE@example.test', 'Jose@Example.Test', ' jose@example.test ', 'jOsE@EXAMPLE.test'];

        for ($i = 0; $i < 9; $i++) {
            $this->attempt($variants[$i % 5], 'wrong', "10.6.0.{$i}")->assertSessionHasErrors(['email' => trans('auth.failed', [], 'ms')]);
        }
        $this->attempt('JoSe@example.TEST', 'wrong', '10.6.0.9')->assertSessionHasErrors(['email' => $this->pausedMessage()]);

        foreach ($variants as $j => $variant) {
            $this->attempt($variant, 'password', "10.6.1.{$j}")->assertSessionHasErrors(['email' => $this->pausedMessage()]);
        }
        $this->assertGuest();
        $this->assertSame(1, AuditLog::where('action', 'auth.account_blocked')->where('user_id', $user->id)->count());
    }

    public function test_the_per_minute_limit_is_per_account_across_email_variants(): void
    {
        User::factory()->create(['role' => 'member', 'email' => 'jose@example.test']);
        foreach (['jose@example.test', 'JOSE@example.test', 'Jose@example.test', 'jose@EXAMPLE.test', 'josE@example.test'] as $variant) {
            $this->attempt($variant, 'wrong');
        }

        $this->attempt('JoSe@example.test', 'wrong')->assertStatus(429);
    }

    public function test_an_email_without_an_account_gets_exactly_the_same_pause(): void
    {
        Mail::fake();
        for ($i = 1; $i <= 10; $i++) {
            $response = $this->attempt('ghost@example.test', 'wrong', "10.3.0.{$i}");
        }
        $response->assertSessionHasErrors(['email' => $this->pausedMessage()]);

        Mail::assertNothingSent();
        $row = AuditLog::where('action', 'auth.account_blocked')->sole();
        $this->assertNull($row->user_id);
        $this->assertStringNotContainsString('ghost@example.test', $row->description);
    }

    public function test_a_password_reset_still_works_during_a_pause_and_ends_it(): void
    {
        Notification::fake();
        $user = User::factory()->create(['role' => 'member', 'email' => 'ahli@example.test']);
        for ($i = 1; $i <= 10; $i++) {
            $this->attempt('ahli@example.test', 'wrong', "10.4.0.{$i}");
        }

        // Asking for a link is not blocked.
        $this->from(route('password.request'))->post(route('password.email'), ['email' => 'ahli@example.test'])
            ->assertSessionHasNoErrors();
        Notification::assertSentTo($user, ResetPassword::class);

        $token = Password::broker()->createToken($user);
        $this->post(route('password.update'), [
            'token' => $token, 'email' => 'ahli@example.test',
            'password' => 'kopi teh susu panas', 'password_confirmation' => 'kopi teh susu panas',
        ])->assertRedirect('/home');
        $this->signOut();

        $this->attempt('ahli@example.test', 'kopi teh susu panas', '10.4.0.99')->assertRedirect('/home');
        $this->assertAuthenticatedAs($user->fresh());
    }

    public function test_a_successful_sign_in_forgets_earlier_failures(): void
    {
        $user = User::factory()->create(['role' => 'member', 'email' => 'ahli@example.test']);
        for ($i = 1; $i <= 9; $i++) {
            $this->attempt('ahli@example.test', 'wrong', "10.5.0.{$i}");
        }
        $this->attempt('ahli@example.test', 'password', '10.5.0.20')->assertRedirect('/home');
        $this->signOut();

        // A fresh count: one more failure is not the 10th.
        $this->attempt('ahli@example.test', 'wrong', '10.5.0.21')->assertSessionHasErrors(['email' => trans('auth.failed', [], 'ms')]);
    }

    // ── Audit rows ────────────────────────────────────────────────────────

    public function test_a_wrong_password_is_audited_once_without_the_password(): void
    {
        $user = User::factory()->create(['role' => 'member', 'email' => 'ahli@example.test']);

        $this->attempt('ahli@example.test', 'My-Secret-Guess-123');

        $row = AuditLog::where('action', 'auth.failed')->sole();
        $this->assertSame($user->id, $row->user_id);
        $this->assertSame('10.0.0.1', $row->ip_address);
        $this->assertStringNotContainsString('My-Secret-Guess-123', json_encode($row->toArray()));
    }

    public function test_a_wrong_password_for_an_unknown_email_is_audited_without_the_email(): void
    {
        $this->attempt('maybe-a-password@example.test', 'wrong');

        $row = AuditLog::where('action', 'auth.failed')->sole();
        $this->assertNull($row->user_id);
        $this->assertSame('Guest', $row->user_name);
        $this->assertStringNotContainsString('maybe-a-password', json_encode($row->toArray()));
    }

    public function test_a_completed_reset_is_audited_and_the_owner_is_emailed_once(): void
    {
        Mail::fake();
        $user = User::factory()->create(['role' => 'member']);

        $token = Password::broker()->createToken($user);
        $this->post(route('password.update'), [
            'token' => $token, 'email' => $user->email,
            'password' => 'kopi teh susu panas', 'password_confirmation' => 'kopi teh susu panas',
        ])->assertRedirect('/home');
        // Another request through the same application must not send it again.
        $this->get('/login');

        $this->assertSame(1, AuditLog::where('action', 'auth.password_reset')->where('user_id', $user->id)->count());
        Mail::assertSent(AccountSecurityNotice::class, 1);
        Mail::assertSent(AccountSecurityNotice::class, fn ($m) => $m->hasTo($user->email) && $m->kind === AccountSecurityNotice::KIND_PASSWORD_CHANGED);
    }

    public function test_the_security_emails_render_in_both_languages(): void
    {
        $user = User::factory()->make(['name' => 'Aminah']);

        $paused = (new AccountSecurityNotice($user, AccountSecurityNotice::KIND_SIGN_IN_PAUSED, 15))->render();
        $this->assertStringContainsString('dihentikan seketika selama 15 minit', $paused);
        $this->assertStringContainsString('If it was not you,', $paused);
        $this->assertStringContainsString(route('password.request'), $paused);

        $changed = (new AccountSecurityNotice($user, AccountSecurityNotice::KIND_PASSWORD_CHANGED))->render();
        $this->assertStringContainsString('Kata laluan anda telah ditukar', $changed);
        $this->assertStringContainsString('contact your club admin', $changed);
    }

    public function test_a_failing_mail_server_does_not_break_the_reset(): void
    {
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP down'));
        $user = User::factory()->create(['role' => 'member']);

        $token = Password::broker()->createToken($user);
        $this->post(route('password.update'), [
            'token' => $token, 'email' => $user->email,
            'password' => 'kopi teh susu panas', 'password_confirmation' => 'kopi teh susu panas',
        ])->assertRedirect('/home');
        $this->assertAuthenticatedAs($user->fresh());
    }

    // ── Reset request: the work happens after the answer ─────────────────

    public function test_the_reset_link_is_sent_after_the_response_and_only_once(): void
    {
        Notification::fake();
        $user = User::factory()->create(['role' => 'member', 'email' => 'known@example.test']);

        $sentBeforeResponse = null;
        Event::listen(RequestHandled::class, function () use (&$sentBeforeResponse) {
            $sentBeforeResponse ??= count(Notification::sentNotifications());
        });

        $this->from(route('password.request'))->post(route('password.email'), ['email' => 'known@example.test'])
            ->assertRedirect(route('password.request'))
            ->assertSessionHas('status', \App\Http\Controllers\Auth\ForgotPasswordController::GENERIC_STATUS);
        $this->assertSame(0, $sentBeforeResponse);
        Notification::assertSentToTimes($user, ResetPassword::class, 1);

        // A second request in the same application does not send it again.
        $this->get('/login');
        Notification::assertSentToTimes($user, ResetPassword::class, 1);
    }
}
