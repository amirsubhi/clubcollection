<?php

namespace Tests\Feature\Member;

use App\Http\Middleware\AuthPageLocale;
use App\Models\Club;
use App\Models\User;
use App\Support\Portal\PortalSettings;
use App\Support\UserLocale;
use Illuminate\Http\Request;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * A member's language: one rule (UserLocale: saved on the account → the browser's choice → the portal default),
 * saved by the member app's switch (POST /my/language), and a choice made before saving existed adopted once.
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

    public function test_saved_language_then_browser_choice_then_portal_default(): void
    {
        // Saved on the account wins over the cookie and ?lang=.
        $this->assertSame('en', UserLocale::resolve($this->member('en'), $this->request(['lang' => 'bm'], 'bm')));
        $this->assertSame('ms', UserLocale::resolve($this->member('ms'), $this->request([], 'en')));
        // Nothing saved: ?lang= before the cookie ("bm" = Malay), then the cookie.
        $this->assertSame('en', UserLocale::resolve($this->member(), $this->request(['lang' => 'en'], 'bm')));
        $this->assertSame('ms', UserLocale::resolve($this->member(), $this->request(['lang' => 'bm'], 'en')));
        $this->assertSame('en', UserLocale::resolve($this->member(), $this->request([], 'en')));
        $this->assertSame('en', UserLocale::resolve(null, $this->request([], 'en')));
        // Nothing at all: the portal default, Malay unless changed.
        $this->assertSame('ms', UserLocale::resolve($this->member(), $this->request()));
        $this->assertSame('ms', UserLocale::resolve(null, $this->request(['lang' => 'xx'], 'xx')));
        PortalSettings::put(['default_locale' => 'en']);
        $this->assertSame('en', UserLocale::resolve($this->member(), $this->request()));
        $this->assertSame('ms', UserLocale::resolve($this->member(), $this->request([], 'bm')));
        // A saved code that is no longer available falls through.
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

    public function test_the_switch_on_a_signed_in_sign_in_page_saves_the_choice(): void
    {
        $member = $this->actingAsMember(Club::factory()->create());

        // The saved language wins over the browser's cookie...
        $member->forceFill(['locale' => 'en'])->save();
        $this->withUnencryptedCookie(AuthPageLocale::COOKIE, 'bm')
            ->get(route('password.change'))->assertSee('<html lang="en">', false);

        // ...and the page's switch changes it.
        $this->get(route('password.change', ['lang' => 'bm']))->assertSee('<html lang="ms">', false);
        $this->assertSame('ms', $member->fresh()->locale);
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

    public function test_saving_is_rate_limited(): void
    {
        $this->actingAsMember(Club::factory()->create());
        for ($i = 0; $i < 20; $i++) {
            $this->postJson(route('member.language.update'), ['lang' => $i % 2 ? 'ms' : 'en'])->assertNoContent();
        }
        $this->postJson(route('member.language.update'), ['lang' => 'en'])->assertTooManyRequests();
    }

    // ── Adopting an earlier choice ──────────────────────────────────────

    public function test_a_choice_made_before_saving_existed_is_adopted_once(): void
    {
        $this->withoutVite();
        $member = $this->actingAsMember(Club::factory()->create());
        $this->assertNull($member->locale);

        $this->withUnencryptedCookie(AuthPageLocale::COOKIE, 'en')->get(route('member.dashboard'))->assertOk();
        $this->assertSame('en', $member->fresh()->locale);

        // Once: afterwards the saved language stands, whatever the cookie says.
        $this->postJson(route('member.language.update'), ['lang' => 'ms'])->assertNoContent();
        $this->withUnencryptedCookie(AuthPageLocale::COOKIE, 'en')->get(route('member.dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('locale', 'ms'));
        $this->assertSame('ms', $member->fresh()->locale);
    }

    public function test_nothing_is_adopted_without_a_valid_cookie(): void
    {
        $this->withoutVite();
        $member = $this->actingAsMember(Club::factory()->create());

        $this->get(route('member.dashboard'))->assertOk();
        $this->withUnencryptedCookie(AuthPageLocale::COOKIE, 'xx')->get(route('member.dashboard'))->assertOk();
        $this->assertNull($member->fresh()->locale);

        // A guest's cookie saves nothing.
        auth()->logout();
        $this->withUnencryptedCookie(AuthPageLocale::COOKIE, 'en')->get('/login')->assertOk();
        $this->assertSame(0, User::query()->whereNotNull('locale')->count());
    }

    public function test_the_malay_cookie_is_adopted_too(): void
    {
        $this->withoutVite();
        PortalSettings::put(['default_locale' => 'en']);
        $member = $this->actingAsMember(Club::factory()->create());

        $this->withUnencryptedCookie(AuthPageLocale::COOKIE, 'bm')->get(route('member.dashboard'))->assertOk();
        $this->assertSame('ms', $member->fresh()->locale);
    }
}
