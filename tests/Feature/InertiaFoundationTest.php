<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InertiaFoundationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_ui_kit_renders_the_inertia_page_with_shared_props(): void
    {
        $this->get('/dev/ui-kit')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dev/UiKit')
                ->where('auth.user', null)
                ->has('flash')
                ->where('locale', 'ms')
                ->where('club', null)
            );
    }

    public function test_shared_auth_user_exposes_only_id_name_and_role(): void
    {
        $user = User::factory()->create(['role' => 'member']);

        $this->actingAs($user)
            ->get('/dev/ui-kit')
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.user', [
                    'id' => $user->id,
                    'name' => $user->name,
                    'role' => 'member',
                ])
            );
    }

    public function test_locale_is_malay_for_members_and_app_locale_for_admins(): void
    {
        config(['app.locale' => 'en']);

        $this->actingAs(User::factory()->create(['role' => 'member']))
            ->get('/dev/ui-kit')
            ->assertInertia(fn (Assert $page) => $page->where('locale', 'ms'));

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get('/dev/ui-kit')
            ->assertInertia(fn (Assert $page) => $page->where('locale', 'en'));
    }

    public function test_shared_club_is_the_route_bound_club_only(): void
    {
        Route::middleware('web')->get('/_test/clubs/{club}', fn (Club $club) => Inertia::render('Dev/UiKit'));
        $club = Club::factory()->create();

        $this->get("/_test/clubs/{$club->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('club', ['id' => $club->id, 'name' => $club->name])
            );
    }

    public function test_inertia_page_csp_uses_nonce_and_allows_only_style_attributes_inline(): void
    {
        $csp = $this->get('/dev/ui-kit')->headers->get('Content-Security-Policy');

        $directives = [];
        foreach (array_filter(array_map('trim', explode(';', (string) $csp))) as $d) {
            [$name, $value] = array_pad(explode(' ', $d, 2), 2, '');
            $directives[$name] = $value;
        }

        $this->assertMatchesRegularExpression("/'nonce-[A-Za-z0-9+\\/=]+'/", $directives['script-src']);
        $this->assertStringNotContainsString("'unsafe-inline'", $directives['script-src']);
        $this->assertStringNotContainsString("'unsafe-inline'", $directives['style-src']);
        $this->assertSame("'unsafe-inline'", $directives['style-src-attr']);
        $this->assertStringContainsString('https://fonts.googleapis.com', $directives['style-src']);
        $this->assertStringContainsString('https://fonts.gstatic.com', $directives['font-src']);
    }

    public function test_existing_blade_login_page_still_renders(): void
    {
        $this->withoutVite();

        $this->get('/login')
            ->assertOk()
            ->assertHeader('Content-Security-Policy')
            ->assertDontSee('data-page=', false);
    }
}
