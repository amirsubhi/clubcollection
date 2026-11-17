<?php

namespace Tests\Feature\Admin;

use App\Models\Club;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * R1e polish: BM category names for members, date format hints, SRI on the
 * CDN tags, the club name shown once fewer, the theme button label and the
 * member-page club fallback for admins who are no longer active.
 */
class R1ePolishTest extends TestCase
{
    private Club $club;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(Carbon::create(2026, 11, 16, 10));
        $this->club = Club::factory()->create(['name' => 'Kelab Contoh']);
    }

    // ── Spending categories in BM ────────────────────────────────────────

    public function test_admin_can_set_and_clear_a_categorys_bm_name(): void
    {
        $this->actingAsClubAdmin($this->club);

        $this->post(route('admin.clubs.expense-categories.store', $this->club), ['name' => 'Event', 'name_ms' => '  Acara '])
            ->assertSessionHasNoErrors();
        $cat = ExpenseCategory::where('club_id', $this->club->id)->firstOrFail();
        $this->assertSame('Acara', $cat->name_ms);

        $this->put(route('admin.expense-categories.update', $cat), ['name' => 'Event', 'name_ms' => ''])->assertSessionHasNoErrors();
        $this->assertNull($cat->fresh()->name_ms);

        $this->put(route('admin.expense-categories.update', $cat), ['name' => 'Event', 'name_ms' => str_repeat('x', 101)])
            ->assertSessionHasErrors('name_ms');

        $this->get(route('admin.clubs.expense-categories.index', $this->club))
            ->assertOk()
            ->assertSee('for="f-name-ms"', false)
            ->assertSee('id="f-name-ms"', false)
            ->assertSee('aria-label="Bahasa Melayu name of category Event"', false);
    }

    public function test_member_spending_card_gets_the_bm_name_with_the_english_name_as_fallback(): void
    {
        $withMs = ExpenseCategory::factory()->for($this->club)->create(['name' => 'Food & Beverage', 'name_ms' => 'Makanan & minuman']);
        $plain = ExpenseCategory::factory()->for($this->club)->create(['name' => 'Supplies', 'name_ms' => null]);
        Payment::factory()->forClub($this->club)->create(['status' => 'paid', 'amount' => 500, 'paid_date' => '2026-03-01', 'period_start' => '2026-03-01']);
        Expense::factory()->approved()->create(['club_id' => $this->club->id, 'expense_category_id' => $withMs->id, 'amount' => 60, 'expense_date' => '2026-04-01']);
        Expense::factory()->approved()->create(['club_id' => $this->club->id, 'expense_category_id' => $plain->id, 'amount' => 40, 'expense_date' => '2026-04-02']);

        $this->actingAsMember($this->club);
        $this->get(route('member.clubs.fund', $this->club))->assertInertia(fn (Assert $page) => $page
            ->where('fund.categories.0.name', 'Food & Beverage')
            ->where('fund.categories.0.nameMs', 'Makanan & minuman')
            ->where('fund.categories.1.name', 'Supplies')
            ->missing('fund.categories.1.nameMs'));
    }

    public function test_default_categories_all_have_bm_names(): void
    {
        foreach (ExpenseCategory::DEFAULTS as $en => $ms) {
            $this->assertNotSame('', trim($ms), "{$en} has a BM name");
        }
    }

    // ── Classic admin pages ──────────────────────────────────────────────

    public function test_classic_date_fields_write_out_the_picked_date(): void
    {
        $this->actingAsClubAdmin($this->club);

        // No fixed "dd/mm/yyyy" label (the browser's control may show another order); the layout script
        // writes the picked date out under every date field instead.
        $this->get(route('admin.clubs.payments.create', $this->club))
            ->assertOk()
            ->assertSee('type="date"', false)
            ->assertDontSee('dd/mm/yyyy')
            ->assertSee("document.querySelectorAll('input[type=\"date\"]')", false)
            ->assertSee("toLocaleDateString('en-GB'", false);
    }

    public function test_expense_categories_page_explains_the_bank_charges_category(): void
    {
        $this->actingAsClubAdmin($this->club);

        $this->get(route('admin.clubs.expense-categories.index', $this->club))
            ->assertOk()
            ->assertSee("only charges the portal can't see. Absorbed gateway fees are added automatically.", false);
    }

    public function test_cdn_fallback_assets_carry_subresource_integrity(): void
    {
        // No public/build (npm run build not uploaded): the classic pages fall back to the pinned jsDelivr copies.
        $public = sys_get_temp_dir().'/cp-public-'.uniqid();
        mkdir($public);
        $this->app->usePublicPath($public);

        try {
            $this->actingAsClubAdmin($this->club);
            $response = $this->get(route('admin.clubs.members.index', $this->club))->assertOk();
            $html = $response->getContent();

            preg_match_all('#<(?:link|script)[^>]+cdn\.jsdelivr\.net[^>]*>#', $html, $tags);
            $this->assertCount(3, $tags[0]);
            foreach ($tags[0] as $tag) {
                $this->assertMatchesRegularExpression('#integrity="sha384-[A-Za-z0-9+/=]{64}"#', $tag, $tag);
                $this->assertStringContainsString('crossorigin="anonymous"', $tag);
            }
            $this->assertStringContainsString('https://cdn.jsdelivr.net', (string) $response->headers->get('Content-Security-Policy'));
        } finally {
            @rmdir($public);
        }
    }

    public function test_classic_pages_load_bootstrap_and_fonts_from_the_build_with_no_third_party_hosts(): void
    {
        // A build that bundles Bootstrap (resources/js/classic.ts) and the self-hosted fonts.
        $this->withVite();
        $public = sys_get_temp_dir().'/cp-public-'.uniqid();
        mkdir($public.'/build', 0777, true);
        $entry = fn (string $src, string $file, bool $isEntry = true) => [$src => ['file' => $file, 'src' => $src] + ($isEntry ? ['isEntry' => true] : [])];
        file_put_contents($public.'/build/manifest.json', json_encode(
            $entry('resources/css/classic.css', 'assets/classic.css')
            + $entry('resources/css/bootstrap-theme.css', 'assets/bootstrap-theme.css')
            + $entry('resources/js/classic.ts', 'assets/classic.js')
            + $entry('resources/js/classic-charts.ts', 'assets/classic-charts.js')
            + $entry(\App\Support\FrontendAssets::BODY_FONT, 'assets/jakarta.woff2', false)
        ));
        $this->app->usePublicPath($public);

        try {
            $this->actingAsClubAdmin($this->club);
            $response = $this->get(route('admin.clubs.members.index', $this->club))->assertOk();
            $html = $response->getContent();
            $csp = (string) $response->headers->get('Content-Security-Policy');

            $this->assertStringNotContainsString('cdn.jsdelivr.net', $html);
            $this->assertStringNotContainsString('fonts.googleapis.com', $html);
            $this->assertStringContainsString('build/assets/classic.css', $html);
            $this->assertStringContainsString('build/assets/classic.js', $html);
            $this->assertMatchesRegularExpression('#<link rel="preload" href="[^"]*build/assets/jakarta\.woff2" as="font" type="font/woff2" crossorigin>#', $html);
            $this->assertStringNotContainsString('cdn.jsdelivr.net', $csp);
            $this->assertStringNotContainsString('fonts.g', $csp);

            $this->actingAsSuperAdmin();
            $statistics = $this->get(route('admin.statistics'))->assertOk()->getContent();
            $this->assertStringContainsString('build/assets/classic-charts.js', $statistics);
            $this->assertStringNotContainsString('cdn.jsdelivr.net', $statistics);
            $this->assertStringContainsString("window.cpWhenReady('chart'", $statistics);
        } finally {
            @unlink($public.'/build/manifest.json');
            @rmdir($public.'/build');
            @rmdir($public);
        }
    }

    public function test_club_name_is_not_repeated_under_the_brand_and_theme_button_names_its_state(): void
    {
        $this->actingAsClubAdmin($this->club);

        $html = $this->get(route('admin.clubs.members.index', $this->club))->assertOk()->getContent();
        $this->assertStringNotContainsString('<span class="sub">', $html);
        $this->assertStringContainsString('aria-label="Theme (System)"', $html);

        $this->get(route('admin.clubs.dashboard', $this->club))
            ->assertInertia(fn (Assert $page) => $page->where('nav.brandSubtitle', null));
    }

    public function test_member_page_fallback_skips_clubs_the_admin_no_longer_manages(): void
    {
        $member = User::factory()->create(['role' => 'member']);
        $this->club->members()->attach($member, ['role' => 'member', 'job_level' => 'executive', 'joined_date' => now()->toDateString(), 'is_active' => true]);

        $admin = $this->actingAsClubAdmin($this->club);
        $this->get(route('admin.members.edit', $member))->assertOk()->assertSee('Kelab Contoh');

        // No longer an active admin of the club: the fallback does not pick it.
        $this->club->members()->updateExistingPivot($admin->id, ['is_active' => false]);
        $this->get(route('admin.members.edit', $member))->assertNotFound();

        // Active again, but the club is switched off.
        $this->club->members()->updateExistingPivot($admin->id, ['is_active' => true]);
        $this->club->update(['is_active' => false]);
        $this->get(route('admin.members.edit', $member))->assertNotFound();
    }

    public function test_removing_a_member_keeps_their_paid_fees_in_the_club_history(): void
    {
        $member = User::factory()->create(['role' => 'member']);
        $this->club->members()->attach($member, ['role' => 'member', 'job_level' => 'executive', 'joined_date' => now()->toDateString(), 'is_active' => true]);
        $fee = Payment::factory()->forClub($this->club)->forMember($member)->create(['status' => 'paid', 'amount' => 50, 'paid_date' => '2026-05-01', 'period_start' => '2026-05-01']);
        $before = \App\Support\Admin\FundFigures::for($this->club)->balance();

        $this->actingAsClubAdmin($this->club);
        $this->delete(route('admin.members.destroy', $member))->assertRedirect();

        // Detached from the club, but the fee (and so the fund figures and AGM ledger) stay.
        $this->assertFalse($this->club->members()->where('users.id', $member->id)->exists());
        $this->assertNotNull($fee->fresh());
        $this->assertSame($before, \App\Support\Admin\FundFigures::for($this->club)->balance());
        $this->assertSame(5000, $before);
    }
}
