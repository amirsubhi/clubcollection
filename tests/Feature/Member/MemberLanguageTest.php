<?php

namespace Tests\Feature\Member;

use App\Http\Middleware\AuthPageLocale;
use App\Models\Club;
use App\Models\Payment;
use App\Models\User;
use App\Support\Portal\PortalSettings;
use App\Support\UserLocale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * A member's language (UserLocale). A signed-in member, and every email to a member: the language saved on the
 * account, else the portal default. A guest: the browser's choice (?lang=, else the cp_lang cookie), else the portal
 * default. An account's language is written only by its signed-in owner's POST from a language switch
 * (POST /my/language); nothing a browser merely carries (cookie, ?lang=) is ever saved.
 */
class MemberLanguageTest extends TestCase
{
    private function request(array $query = [], ?string $cookie = null): Request
    {
        $request = Request::create('/login', 'GET', $query);
        if ($cookie !== null) {
            $request->cookies->set(AuthPageLocale::COOKIE, $cookie);
        }

        return $request;
    }

    private function member(?string $locale = null): User
    {
        $user = User::factory()->create(['role' => 'member']);
        $user->forceFill(['locale' => $locale])->save();

        return $user->fresh();
    }

    // ── The precedence rule ─────────────────────────────────────────────

    public function test_a_member_gets_the_saved_language_else_the_default_and_a_guest_the_browser_choice(): void
    {
        // A member: saved wins over the cookie and ?lang=.
        $this->assertSame('en', UserLocale::resolve($this->member('en'), $this->request(['lang' => 'bm'], 'bm')));
        $this->assertSame('ms', UserLocale::resolve($this->member('ms'), $this->request([], 'en')));
        // A member with nothing saved: the portal default, whatever the browser carries (it may be someone else's).
        $this->assertSame('ms', UserLocale::resolve($this->member(), $this->request(['lang' => 'en'], 'en')));
        $this->assertSame('ms', UserLocale::resolve($this->member(), $this->request([], 'en')));
        // A guest: ?lang= before the cookie ("bm" = Malay), then the cookie, then the default.
        $this->assertSame('en', UserLocale::resolve(null, $this->request(['lang' => 'en'], 'bm')));
        $this->assertSame('ms', UserLocale::resolve(null, $this->request(['lang' => 'bm'], 'en')));
        $this->assertSame('en', UserLocale::resolve(null, $this->request([], 'en')));
        $this->assertSame('ms', UserLocale::resolve(null, $this->request(['lang' => 'xx'], 'xx')));
        PortalSettings::put(['default_locale' => 'en']);
        $this->assertSame('en', UserLocale::resolve($this->member(), $this->request([], 'bm')));
        $this->assertSame('ms', UserLocale::resolve(null, $this->request([], 'bm')));
        // A saved code that is no longer available falls through to the default.
        $this->assertSame('en', UserLocale::resolve($this->member('fr'), $this->request()));
    }

    public function test_emails_use_the_saved_language_else_the_portal_default_never_the_browser(): void
    {
        $this->assertSame('en', $this->member('en')->preferredLocale());
        $this->assertSame('ms', $this->member()->preferredLocale());

        // A request in progress does not change it (an admin's request, a cookie of someone else).
        $this->withUnencryptedCookie(AuthPageLocale::COOKIE, 'en')->get('/login');
        $this->assertSame('ms', $this->member()->preferredLocale());

        PortalSettings::put(['default_locale' => 'en']);
        $this->assertSame('en', $this->member()->preferredLocale());
        $this->assertSame('ms', $this->member('ms')->preferredLocale());
    }

    public function test_admin_emails_are_english_whatever_is_saved_or_the_default(): void
    {
        $club = Club::factory()->create();
        PortalSettings::put(['default_locale' => 'ms']);
        $admin = $this->actingAsClubAdmin($club);
        $this->assertSame('en', $admin->preferredLocale());
        $admin->forceFill(['locale' => 'ms'])->save();
        $this->assertSame('en', $admin->fresh()->preferredLocale());
        $this->assertSame('en', $this->actingAsSuperAdmin()->preferredLocale());
    }

    public function test_a_language_is_never_saved_for_an_admin(): void
    {
        $club = Club::factory()->create();
        $admin = $this->actingAsClubAdmin($club);
        UserLocale::save($admin, 'ms');
        $this->postJson(route('member.language.update'), ['lang' => 'ms'])->assertNoContent();
        $this->withUnencryptedCookie(AuthPageLocale::COOKIE, 'bm')->get('/home');
        $this->assertNull($admin->fresh()->locale);
    }

    // ── What the pages get ──────────────────────────────────────────────

    public function test_the_member_app_gets_the_saved_language_over_the_cookie(): void
    {
        $this->withoutVite();
        $club = Club::factory()->create();
        $member = $this->actingAsMember($club);
        $member->forceFill(['locale' => 'en'])->save();

        $this->withUnencryptedCookie(AuthPageLocale::COOKIE, 'bm')
            ->get(route('member.dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('locale', 'en')
                ->where('langSave', ['url' => route('member.language.update'), 'saved' => true]));
    }

    public function test_a_member_with_nothing_saved_gets_the_portal_default_and_admins_stay_english(): void
    {
        $this->withoutVite();
        PortalSettings::put(['default_locale' => 'en']);
        $club = Club::factory()->create();
        $this->actingAsMember($club);
        $this->get(route('member.dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('locale', 'en')
            // Nothing saved: the app must not write the default down as a choice.
            ->where('langSave', ['url' => route('member.language.update'), 'saved' => false]));

        PortalSettings::put(['default_locale' => null]);
        $this->get(route('member.dashboard'))->assertInertia(fn (Assert $page) => $page->where('locale', 'ms'));

        // Admin pages keep the app locale and never save a language.
        $admin = $this->actingAsClubAdmin($club);
        $admin->forceFill(['locale' => 'ms'])->save();
        $this->get(route('member.dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('locale', config('app.locale'))
            ->where('langSave', null));
    }

    public function test_sign_in_pages_follow_the_rule(): void
    {
        $this->get('/login')->assertSee('<html lang="ms">', false);
        PortalSettings::put(['default_locale' => 'en']);
        $this->get('/login')->assertSee('<html lang="en">', false);
        $this->withUnencryptedCookie(AuthPageLocale::COOKIE, 'bm')->get('/login')->assertSee('<html lang="ms">', false);
    }

    // ── A shared browser: B signs in where A chose English ──────────────

    /**
     * The reviewer's probe, inverted: A chose English in this browser (cp_lang=en). B signs in on it with nothing
     * saved: every page B sees and every email B gets is in the portal default, and nothing is saved on B.
     */
    public function test_another_persons_browser_choice_is_never_used_for_or_saved_on_a_member(): void
    {
        $this->withoutVite();
        $club = Club::factory()->create();
        $b = $this->actingAsMember($club);
        $this->withUnencryptedCookie(AuthPageLocale::COOKIE, 'en');

        $this->get(route('member.dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('locale', 'ms')
            ->where('langSave', ['url' => route('member.language.update'), 'saved' => false]));
        $this->get(route('password.change'))->assertOk()->assertSee('<html lang="ms">', false)
            // B's own saved language is not set, so nothing is copied to the member app's storage either.
            ->assertDontSee("localStorage.setItem('cp-lang'", false);
        $this->get(route('privacy'))->assertOk()->assertSee('<html lang="ms">', false);
        $this->get('/home')->assertRedirect();
        $this->assertNull($b->fresh()->locale);

        // An email sent during B's request on that browser: the portal default too (the password-changed notice).
        $this->from(route('password.change'))->post(route('password.change.update'), [
            'current_password' => 'password', 'password' => 'a-new-long-passphrase-26', 'password_confirmation' => 'a-new-long-passphrase-26',
        ])->assertRedirect();
        $mail = $this->sentTo($b);
        $this->assertStringStartsWith('Kata laluan anda telah ditukar', $mail->getSubject());
        $this->assertStringContainsString('<html lang="ms">', $mail->getHtmlBody());
        $this->assertNull($b->fresh()->locale);
    }

    public function test_a_lang_link_never_saves_and_does_not_change_a_signed_in_members_page(): void
    {
        $member = $this->actingAsMember(Club::factory()->create());

        // The reviewer's probe, inverted: GET /privacy?lang=en while signed in saves nothing.
        $this->get(route('privacy', ['lang' => 'en']))->assertOk()->assertSee('<html lang="ms">', false)
            ->assertCookieMissing(AuthPageLocale::COOKIE);
        $this->get(route('password.change', ['lang' => 'en']))->assertOk()->assertSee('<html lang="ms">', false);
        $this->assertNull($member->fresh()->locale);

        // With a saved language, ?lang= does not override or change it either.
        $member->forceFill(['locale' => 'en'])->save();
        $this->get(route('password.change', ['lang' => 'bm']))->assertSee('<html lang="en">', false);
        $this->assertSame('en', $member->fresh()->locale);
    }

    /** On the sign-in-style pages a signed-in member's switch is a POST form to the save route, not ?lang= links. */
    public function test_the_switch_on_a_signed_in_sign_in_page_is_a_form_that_saves(): void
    {
        $member = $this->actingAsMember(Club::factory()->create());

        $this->get(route('password.change'))->assertOk()
            ->assertSee('action="'.route('member.language.update').'"', false)
            ->assertSee('name="_token"', false)
            ->assertSee('name="lang" value="en"', false)
            ->assertDontSee('?lang=en', false);

        $this->from(route('password.change'))->post(route('member.language.update'), ['lang' => 'en'])
            ->assertRedirect(route('password.change'));
        $this->assertSame('en', $member->fresh()->locale);
        $this->get(route('password.change'))->assertSee('<html lang="en">', false);

        // Guests (and admins) keep the ?lang= links.
        auth()->logout();
        $this->get('/login')->assertSee('?lang=en', false)->assertDontSee('data-testid="auth-lang-form"', false);
    }

    public function test_a_member_who_must_change_the_temporary_password_can_still_switch_language(): void
    {
        $member = $this->actingAsMember(Club::factory()->create());
        $member->forceFill(['must_change_password' => true])->save();

        $this->get(route('member.dashboard'))->assertRedirect(route('password.change'));
        $this->from(route('password.change'))->post(route('member.language.update'), ['lang' => 'en'])
            ->assertRedirect(route('password.change'));
        $this->assertSame('en', $member->fresh()->locale);
        $this->get(route('password.change'))->assertOk()->assertSee('<html lang="en">', false);
    }

    // ── Saving (POST /my/language) ──────────────────────────────────────

    public function test_the_switch_saves_the_language_on_the_account(): void
    {
        $member = $this->actingAsMember(Club::factory()->create());

        $this->postJson(route('member.language.update'), ['lang' => 'en'])->assertNoContent();
        $this->assertSame('en', $member->fresh()->locale);

        $this->postJson(route('member.language.update'), ['lang' => 'ms'])->assertNoContent();
        $this->assertSame('ms', $member->fresh()->locale);
        // Only the language is written.
        $this->assertSame($member->name, $member->fresh()->name);
    }

    public function test_only_an_available_language_is_saved(): void
    {
        $member = $this->actingAsMember(Club::factory()->create());
        $member->forceFill(['locale' => 'en'])->save();

        foreach (['fr', 'bm', '', 'EN', '../ms', ['en']] as $value) {
            $this->postJson(route('member.language.update'), ['lang' => $value])->assertUnprocessable()->assertJsonValidationErrors('lang');
        }
        $this->postJson(route('member.language.update'), [])->assertUnprocessable();
        $this->assertSame('en', $member->fresh()->locale);
    }

    public function test_saving_needs_a_signed_in_member(): void
    {
        $this->postJson(route('member.language.update'), ['lang' => 'en'])->assertUnauthorized();
        $this->post(route('member.language.update'), ['lang' => 'en'])->assertRedirect(route('login'));
        $this->assertSame(0, User::query()->whereNotNull('locale')->count());
    }

    public function test_saving_is_rate_limited_on_its_own_limiter(): void
    {
        $this->assertContains('throttle:language', Route::getRoutes()->getByName('member.language.update')->gatherMiddleware());

        $this->actingAsMember(Club::factory()->create());
        for ($i = 0; $i < 20; $i++) {
            $this->postJson(route('member.language.update'), ['lang' => $i % 2 ? 'ms' : 'en'])->assertNoContent();
        }
        $this->postJson(route('member.language.update'), ['lang' => 'en'])->assertTooManyRequests();
    }

    /**
     * The reviewer's probe, inverted: a plain throttle:N,1 is keyed on the user alone, so it would share pay's
     * counter. Language switches must never use up a member's payment attempts.
     */
    public function test_language_switches_never_block_a_payment(): void
    {
        $club = Club::factory()->create();
        $member = $this->actingAsMember($club);
        $fee = Payment::factory()->forClub($club)->forMember($member)->pending()->create();

        for ($i = 0; $i < 20; $i++) {
            $this->postJson(route('member.language.update'), ['lang' => $i % 2 ? 'ms' : 'en'])->assertNoContent();
        }
        for ($i = 0; $i < 10; $i++) {
            $this->assertNotSame(429, $this->post(route('member.payments.pay', $fee))->getStatusCode(), "pay #{$i}");
        }
        // Pay's own limit still holds.
        $this->post(route('member.payments.pay', $fee))->assertStatus(429);
    }

    private function sentTo(User $user): Email
    {
        $mail = collect(app('mail.manager')->mailer('array')->getSymfonyTransport()->messages())
            ->map(fn ($m) => $m->getOriginalMessage())
            ->first(fn (Email $m) => $m->getTo()[0]->getAddress() === $user->email);
        $this->assertNotNull($mail, "nothing sent to {$user->email}");

        return $mail;
    }
}
