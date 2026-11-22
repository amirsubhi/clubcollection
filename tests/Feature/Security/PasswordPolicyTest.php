<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * New passwords: 12+ characters and not breached (Password::defaults, NIST style: no composition rules).
 * The breached check is real here (Have I Been Pwned, faked over HTTP) and passes when the API is down.
 * POST /password/reset never tells an unknown email apart from a bad link.
 */
class PasswordPolicyTest extends TestCase
{
    protected bool $fakeBreachedPasswordCheck = false;

    private const GOOD = 'kopi teh susu panas';

    protected function setUp(): void
    {
        parent::setUp();
        // Nothing in this class may reach the network unless a test fakes it.
        Http::preventStrayRequests();
    }

    private function reset(User $user, string $password, ?string $token = null, ?string $email = null)
    {
        $token ??= Password::broker()->createToken($user);

        return $this->from('/password/reset/'.$token)->post(route('password.update'), [
            'token' => $token, 'email' => $email ?? $user->email,
            'password' => $password, 'password_confirmation' => $password,
        ]);
    }

    private function fakePwned(string $breached = ''): void
    {
        // The range API returns "SUFFIX:COUNT" lines for the first five characters of the SHA-1.
        $line = $breached === '' ? '0000000000000000000000000000000000A:1' : substr(strtoupper(sha1($breached)), 5).':42';
        Http::fake(['api.pwnedpasswords.com/range/*' => Http::response($line."\r\n")]);
    }

    public function test_a_long_plain_words_password_is_accepted_without_symbols_or_capitals(): void
    {
        $this->fakePwned();
        $user = User::factory()->create(['role' => 'member']);

        $this->reset($user, self::GOOD)->assertSessionHasNoErrors()->assertRedirect('/home');

        $this->assertTrue(Hash::check(self::GOOD, $user->fresh()->password));
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://api.pwnedpasswords.com/range/'.substr(strtoupper(sha1(self::GOOD)), 0, 5)));
    }

    public function test_fewer_than_12_characters_is_refused_with_a_helpful_message(): void
    {
        $this->fakePwned();
        $user = User::factory()->create(['role' => 'member']);

        $this->reset($user, 'short pass1')->assertSessionHasErrors([
            'password' => 'Kata laluan baharu perlu sekurang-kurangnya 12 aksara. Beberapa perkataan biasa yang digabungkan paling mudah diingat.',
        ]);
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_a_breached_password_is_refused(): void
    {
        $this->fakePwned('password1234');
        $user = User::factory()->create(['role' => 'member']);

        $this->withUnencryptedCookie('cp_lang', 'en')->reset($user, 'password1234')->assertSessionHasErrors([
            'password' => 'This password has appeared in a data leak on another website, so it is not safe. Please choose a different one.',
        ]);
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_the_check_fails_open_when_the_breach_api_cannot_be_reached(): void
    {
        Http::fake(['api.pwnedpasswords.com/*' => fn () => throw new ConnectionException('offline')]);
        $user = User::factory()->create(['role' => 'member']);

        $this->reset($user, self::GOOD)->assertSessionHasNoErrors()->assertRedirect('/home');
        $this->assertTrue(Hash::check(self::GOOD, $user->fresh()->password));
    }

    public function test_the_check_fails_open_on_an_api_error_and_waits_at_most_5_seconds(): void
    {
        Http::fake(['api.pwnedpasswords.com/*' => Http::response('busy', 503)]);
        $user = User::factory()->create(['role' => 'member']);

        $this->reset($user, self::GOOD)->assertSessionHasNoErrors();
        Http::assertSent(fn ($request) => true);

        $verifier = app(\Illuminate\Contracts\Validation\UncompromisedVerifier::class);
        $this->assertInstanceOf(\Illuminate\Validation\NotPwnedVerifier::class, $verifier);
        $this->assertSame(5, (fn () => $this->timeout)->call($verifier));
    }

    public function test_the_reset_page_explains_the_rule(): void
    {
        $user = User::factory()->create(['role' => 'member']);
        $token = Password::broker()->createToken($user);

        $this->get('/password/reset/'.$token.'?email='.urlencode($user->email))->assertOk()
            ->assertSee('Gunakan sekurang-kurangnya 12 aksara.')
            ->assertSee('minlength="12"', false);
    }

    // ── Item 2: no account discovery on POST /password/reset ───────────────

    public function test_unknown_email_and_bad_token_give_the_same_answer(): void
    {
        $this->fakePwned();
        $user = User::factory()->create(['role' => 'member', 'email' => 'known@example.test']);

        $unknown = $this->reset($user, self::GOOD, 'some-token', 'nobody@example.test');
        $badToken = $this->reset($user, self::GOOD, 'wrong-token');

        $expected = trans('passwords.token', [], 'ms');
        foreach ([$unknown, $badToken] as $response) {
            $response->assertSessionHasErrors(['email' => $expected]);
        }
        $this->assertSame(session('errors')->get('email'), [$expected]);
        $this->assertNotSame(trans('passwords.user', [], 'ms'), $expected);
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_unknown_email_json_answer_is_the_bad_link_message(): void
    {
        $this->fakePwned();
        $this->postJson(route('password.update'), [
            'token' => 'x', 'email' => 'nobody@example.test', 'password' => self::GOOD, 'password_confirmation' => self::GOOD,
        ])->assertStatus(422)->assertJsonPath('errors.email.0', trans('passwords.token', [], 'ms'));
    }

    public function test_password_field_errors_still_show_normally(): void
    {
        $user = User::factory()->create(['role' => 'member']);
        $token = Password::broker()->createToken($user);

        $this->from('/password/reset/'.$token)->post(route('password.update'), [
            'token' => $token, 'email' => $user->email, 'password' => self::GOOD, 'password_confirmation' => 'something else',
        ])->assertSessionHasErrors(['password' => 'Kedua-dua kata laluan baharu tidak sama. Sila taip semula.'])
            ->assertSessionDoesntHaveErrors('email');
    }
}
