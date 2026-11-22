<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Support\Portal\PortalSettings;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\LogoFixtures;
use Tests\TestCase;

/** Settings phase 1: the club's name, logo, registration no. and address where members and guests see them. */
class ClubIdentityTest extends TestCase
{
    use LogoFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('public');
    }

    private function logo(string $name): string
    {
        return (string) Storage::disk('public')->putFileAs('club-logos', $this->upload('a.png', $this->pngBytes()), $name);
    }

    // ── Member home ──────────────────────────────────────────────────────

    public function test_member_home_carries_each_clubs_logo(): void
    {
        $club = Club::factory()->create(['name' => 'Kelab Satu', 'logo' => $this->logo('satu.png')]);
        $other = Club::factory()->create(['name' => 'Kelab Dua']);
        $member = $this->actingAsMember($club);
        $other->members()->attach($member, ['role' => 'member', 'job_level' => 'executive', 'joined_date' => now()->toDateString(), 'is_active' => true]);

        $this->get(route('member.dashboard'))
            ->assertInertia(fn (Assert $page) => $page->component('Member/Home')
                ->where('clubs.0.name', 'Kelab Dua')
                ->where('clubs.0.logoUrl', null)
                ->where('clubs.1.name', 'Kelab Satu')
                ->where('clubs.1.logoUrl', asset('storage/club-logos/satu.png'))
                ->where('clubs.1.fyStartMonth', 1));
    }

    public function test_a_missing_logo_file_gives_no_url(): void
    {
        $club = Club::factory()->create(['logo' => 'club-logos/gone.png']);
        $this->actingAsMember($club);

        $this->get(route('member.dashboard'))->assertInertia(fn (Assert $page) => $page->where('clubs.0.logoUrl', null));
    }

    // ── Sign-in pages ────────────────────────────────────────────────────

    public function test_with_one_active_club_the_sign_in_page_shows_its_name_and_logo(): void
    {
        Club::factory()->create(['name' => 'Kelab <Rekreasi>', 'logo' => $this->logo('kelab.png')]);
        Club::factory()->create(['is_active' => false, 'name' => 'Kelab Tutup']);

        $this->get('/login')
            ->assertOk()
            ->assertSee('data-brand-kind="club"', false)
            ->assertSee('src="'.asset('storage/club-logos/kelab.png').'"', false)
            ->assertSee('alt="Kelab &lt;Rekreasi&gt;"', false)
            ->assertSee('Kelab &lt;Rekreasi&gt;', false)
            ->assertDontSee('Kelab <Rekreasi>', false)
            // The portal name stays under it.
            ->assertSee('data-testid="auth-brand-portal"', false)
            ->assertDontSee('Kelab Tutup');
    }

    public function test_with_one_club_without_a_logo_its_name_is_shown_with_the_portal_mark(): void
    {
        Club::factory()->create(['name' => 'Kelab Tanpa Logo']);

        $this->get('/login')
            ->assertSee('data-brand-kind="club"', false)
            ->assertSee('Kelab Tanpa Logo')
            ->assertDontSee('data-testid="auth-logo"', false);
    }

    public function test_with_several_clubs_only_the_portal_is_shown(): void
    {
        Club::factory()->create(['name' => 'Kelab Satu', 'logo' => $this->logo('satu.png')]);
        Club::factory()->create(['name' => 'Kelab Dua']);

        $this->get('/login')
            ->assertSee('data-brand-kind="portal"', false)
            ->assertDontSee('Kelab Satu')
            ->assertDontSee('club-logos/satu.png', false)
            ->assertSee(config('app.name'));
    }

    public function test_with_no_club_and_no_settings_the_sign_in_page_still_renders(): void
    {
        $this->get('/login')->assertOk()->assertSee('data-brand-kind="portal"', false)->assertSee('Club Portal');
        $this->get('/password/reset')->assertOk();
    }

    public function test_the_portal_logo_is_shown_when_no_single_club_is(): void
    {
        Club::factory()->count(2)->create();
        Storage::disk('public')->put('portal/p.png', $this->pngBytes());
        PortalSettings::put(['logo' => 'portal/p.png']);

        $this->get('/login')->assertSee('src="'.asset('storage/portal/p.png').'"', false);
    }

    // ── Ledger PDF header ───────────────────────────────────────────────

    public function test_the_ledger_pdf_header_shows_the_registration_no_and_address_escaped(): void
    {
        $club = Club::factory()->create([
            'name' => 'Kelab PDF',
            'registration_no' => 'PPM-<b>9</b>',
            'address' => "Jalan <script>x</script>\nKuala Lumpur",
        ]);
        $html = view('admin.ledger.pdf', [
            'club' => $club,
            'filters' => ['from' => now()->startOfYear(), 'to' => now(), 'openingBalance' => 0, 'openingSource' => 'computed'],
            'generatedAt' => now()->format('d M Y H:i'),
            'generatedBy' => 'Admin',
            'transactions' => collect(), 'totalIncome' => 0, 'totalExpenses' => 0, 'closingBalance' => 0,
            'monthlySummary' => collect(), 'pendingPayments' => collect(), 'overduePayments' => collect(),
            'verifyingPayments' => collect(), 'processingPayments' => collect(), 'outstandingTotal' => 0,
        ])->render();

        $this->assertStringContainsString('Registration no.: PPM-&lt;b&gt;9&lt;/b&gt;', $html);
        $this->assertStringContainsString('Jalan &lt;script&gt;x&lt;/script&gt;<br />', $html);
        $this->assertStringNotContainsString('<script>x', $html);
    }

    public function test_the_ledger_pdf_downloads_with_a_profile_set(): void
    {
        $club = Club::factory()->create(['registration_no' => 'PPM-1', 'address' => "Line 1\nLine 2", 'logo' => $this->logo('pdf.png')]);
        $this->actingAsClubAdmin($club);

        $this->get(route('admin.clubs.ledger.export-pdf', $club))->assertOk()->assertHeader('content-type', 'application/pdf');
    }
}
