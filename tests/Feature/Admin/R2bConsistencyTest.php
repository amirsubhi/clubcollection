<?php

namespace Tests\Feature\Admin;

use App\Http\Controllers\Admin\DashboardController;
use App\Models\Club;
use App\Models\ExpenseCategory;
use App\Models\User;
use App\Support\Initials;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** R2b: the classic Blade pages match the React ones, plus the small fixes (deltas, Fund Usage wording). */
class R2bConsistencyTest extends TestCase
{
    private Club $club;

    protected function setUp(): void
    {
        parent::setUp();
        $this->club = Club::factory()->create(['name' => 'Kelab Sukan']);
    }

    public function test_initials_skip_bin_binti_and_match_the_react_rule(): void
    {
        $this->assertSame('SA', Initials::of('Siti Aminah binti Hassan'));
        $this->assertSame('AR', Initials::of('Ahmad bin Razak'));
        $this->assertSame('LS', Initials::of('Lim Siew Ling'));
        $this->assertSame('MA', Initials::of('Muthu a/l Arumugam'));
        $this->assertSame('Z', Initials::of('Zainab'));
        $this->assertSame('ÉL', Initials::of('élodie lee'));
    }

    public function test_blade_and_react_show_the_same_initials_icons_and_theme_control(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'name' => 'Siti Aminah binti Hassan']);
        $this->club->members()->attach($admin, ['role' => 'admin', 'job_level' => 'manager', 'joined_date' => now()->toDateString(), 'is_active' => true]);
        $this->actingAs($admin);

        $html = $this->get(route('admin.clubs.members.index', $this->club))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<span class="avatar" aria-hidden="true">SA<\/span>/', $html);
        // Lucide glyphs, as on the React pages: monitor for "System", not Bootstrap's half circle.
        $this->assertStringContainsString('data-theme-icon="system"', $html);
        $this->assertStringNotContainsString('bi-circle-half', $html);
        $this->assertStringContainsString('<rect width="20" height="14" x="2" y="3" rx="2"/>', $html);
        $this->assertStringNotContainsString('class="bi bi-grid-1x2"', $html);
        // The nav count takes its colours from the tokens, not an inline white-on-primary override.
        $this->assertDoesNotMatchRegularExpression('/\.admin-nav \.count \{[^}]*color: #fff/', $html);

        $this->get(route('admin.clubs.billing.edit', $this->club))
            ->assertInertia(fn (Assert $p) => $p->where('nav.user.initials', 'SA'));
    }

    public function test_success_buttons_are_primary_and_the_count_badge_uses_token_colours(): void
    {
        $css = file_get_contents(resource_path('css/bootstrap-theme.css'));
        $this->assertMatchesRegularExpression('/\.btn-primary,\s*\.btn-success \{[^}]*--bs-btn-bg: rgb\(var\(--primary\)\)/', $css);
        $this->assertStringContainsString('.admin-nav .count { background: rgb(var(--primary)); color: rgb(var(--primary-foreground)); }', $css);
    }

    public function test_classic_dashboard_has_labelled_period_selects_and_bars_not_donuts(): void
    {
        $this->actingAsClubAdmin($this->club);
        $html = $this->get(route('admin.clubs.dashboard.classic', $this->club))->assertOk()->getContent();

        $this->assertStringContainsString('<label for="dash-month"', $html);
        $this->assertStringContainsString('<label for="dash-year"', $html);
        $this->assertStringContainsString('id="dash-month"', $html);
        $this->assertStringNotContainsString('doughnut', $html);
        $this->assertStringNotContainsString('#198754', $html);
        $this->assertStringNotContainsString('rgba(25,135,84', $html);
        $this->assertStringContainsString('data-testid="status-bars"', $html);
        $this->assertStringContainsString("tok('--chart-1-stroke')", $html);
        $this->assertStringNotContainsString('btn-success', $html);
    }

    public function test_ledger_pdf_uses_the_lavender_colours(): void
    {
        $pdf = file_get_contents(resource_path('views/admin/ledger/pdf.blade.php'));
        $this->assertStringNotContainsString('#0d6efd', $pdf);
        $this->assertStringContainsString('#5B4FCF', $pdf);
    }

    public function test_percent_vs_last_year_is_hidden_when_last_year_was_tiny(): void
    {
        $pct = new \ReflectionMethod(DashboardController::class, 'pct');
        $ctl = app(DashboardController::class);

        $this->assertNull($pct->invoke($ctl, 50_000, 2_000), 'RM 20 last year: no "+2,400%"');
        $this->assertNull($pct->invoke($ctl, 50_000, 0));
        $this->assertSame(25.0, $pct->invoke($ctl, 62_500, DashboardController::MIN_DELTA_BASE_SEN));
    }

    public function test_fund_usage_errors_are_plain_and_not_laravel_defaults(): void
    {
        $this->actingAsClubAdmin($this->club);
        ExpenseCategory::create(['club_id' => $this->club->id, 'name' => 'Refreshments']);

        $res = $this->from(route('admin.clubs.fund-usage.create', $this->club))
            ->post(route('admin.clubs.fund-usage.store', $this->club), ['intent' => 'submit', 'type' => 'expense']);

        $res->assertSessionHasErrors([
            'description' => 'Enter a description.',
            'amount' => 'Enter the amount.',
            'expense_date' => 'Enter the invoice date.',
            'payee' => 'Enter who was paid.',
            'expense_category_id' => 'Choose a category.',
        ]);
        foreach (session('errors')->all() as $message) {
            $this->assertStringNotContainsString('field is required', $message);
        }
    }
}
