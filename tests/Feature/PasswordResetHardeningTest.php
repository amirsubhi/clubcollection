<?php

namespace Tests\Feature;

use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** S1: password reset can't be used to discover accounts, is throttled, and ends other sessions. */
class PasswordResetHardeningTest extends TestCase
{
    public function test_reset_gives_the_same_response_for_known_and_unknown_emails(): void
    {
        Notification::fake();
        User::factory()->create(['email' => 'known@example.test']);

        $known = $this->from(route('password.request'))->post(route('password.email'), ['email' => 'known@example.test']);
        $unknown = $this->from(route('password.request'))->post(route('password.email'), ['email' => 'nobody@example.test']);

        foreach ([$known, $unknown] as $response) {
            $response->assertRedirect(route('password.request'))
                ->assertSessionHas('status', ForgotPasswordController::GENERIC_STATUS)
                ->assertSessionHasNoErrors();
        }
    }

    public function test_reset_routes_are_throttled_each_on_its_own_limiter(): void
    {
        $routes = Route::getRoutes();
        $this->assertContains('throttle:password-email', $routes->getByName('password.email')->gatherMiddleware());
        $this->assertContains('throttle:password-reset', $routes->getByName('password.update')->gatherMiddleware());
        $confirmPost = collect($routes->getRoutes())->first(fn ($r) => $r->uri() === 'password/confirm' && in_array('POST', $r->methods(), true));
        $this->assertContains('throttle:password-confirm', $confirmPost->gatherMiddleware());
        foreach (['password-email', 'password-reset', 'password-confirm'] as $name) {
            $limits = \Illuminate\Support\Facades\RateLimiter::limiter($name)(request());
            $this->assertSame([30, 5], array_map(fn ($l) => $l->maxAttempts, $limits), $name);
        }
    }

    public function test_using_up_reset_requests_from_one_ip_does_not_block_finishing_a_reset(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->post(route('password.email'), ['email' => "someone{$i}@example.test"]);
        }
        $this->post(route('password.email'), ['email' => 'another@example.test'])->assertStatus(429);

        $user = User::factory()->create();
        $token = \Illuminate\Support\Facades\Password::createToken($user);
        $this->post(route('password.update'), [
            'token' => $token, 'email' => $user->email,
            'password' => 'tiga perkataan mudah', 'password_confirmation' => 'tiga perkataan mudah',
        ])->assertStatus(302)->assertSessionHasNoErrors();
    }

    public function test_a_password_reset_ends_other_sessions(): void
    {
        $user = User::factory()->create(['role' => 'member']);

        // An existing session (another device) has stored the old password hash.
        $this->actingAs($user)->get(route('home'));
        $hashKey = 'password_hash_'.auth()->getDefaultDriver();
        $old = session($hashKey);
        $this->assertNotNull($old);

        $token = Password::broker()->createToken($user);
        $this->post(route('password.update'), [
            'token' => $token, 'email' => $user->email,
            'password' => 'New-Strong-Pass-2026', 'password_confirmation' => 'New-Strong-Pass-2026',
        ]);

        // That session still carries the old hash: its next request logs it out.
        $this->assertNotSame($user->getAuthPassword(), $user->fresh()->getAuthPassword());
        $this->actingAs($user->fresh())->withSession([$hashKey => $old])->get(route('home'))->assertRedirect(route('login'));
        $this->assertGuest();
    }
}
