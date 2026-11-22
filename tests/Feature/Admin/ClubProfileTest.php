<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\Club;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Tests\Support\LogoFixtures;
use Tests\TestCase;

/** Settings phase 1: the club profile page for the club's own admins (and the super admin). */
class ClubProfileTest extends TestCase
{
    use LogoFixtures;

    private Club $club;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->club = Club::factory()->create(['name' => 'Kelab Rekreasi', 'email' => 'kelab@example.test']);
    }

    private function payload(array $over = []): array
    {
        return $over + [
            'name' => 'Kelab Rekreasi',
            'email' => 'kelab@example.test',
            'registration_no' => 'PPM-001-14-01012020',
            'address' => "Aras 3, Menara Contoh\nJalan Contoh\n50450 Kuala Lumpur",
            'financial_year_start_month' => 7,
        ];
    }

    // ── Who may open and save it ─────────────────────────────────────────

    public function test_a_guest_is_sent_to_sign_in(): void
    {
        $this->get(route('admin.clubs.profile.edit', $this->club))->assertRedirect(route('login'));
        $this->put(route('admin.clubs.profile.update', $this->club), $this->payload())->assertRedirect(route('login'));
    }

    public function test_a_member_is_refused(): void
    {
        $this->actingAsMember($this->club);

        $this->get(route('admin.clubs.profile.edit', $this->club))->assertForbidden();
        $this->put(route('admin.clubs.profile.update', $this->club), $this->payload(['name' => 'Hijacked']))->assertForbidden();
        $this->assertSame('Kelab Rekreasi', $this->club->fresh()->name);
    }

    public function test_another_clubs_admin_is_refused(): void
    {
        $this->actingAsClubAdmin(Club::factory()->create());

        $this->get(route('admin.clubs.profile.edit', $this->club))->assertForbidden();
        $this->put(route('admin.clubs.profile.update', $this->club), $this->payload(['name' => 'Hijacked']))->assertForbidden();
        $this->assertSame('Kelab Rekreasi', $this->club->fresh()->name);
    }

    public function test_names_with_control_characters_are_refused(): void
    {
        $this->actingAsClubAdmin($this->club);

        foreach (['name' => "Kelab\r\nBcc: x@example.test", 'registration_no' => "PPM\x00-1"] as $field => $value) {
            $this->put(route('admin.clubs.profile.update', $this->club), $this->payload([$field => $value]))
                ->assertSessionHasErrors($field);
        }
        $this->assertNotSame('PPM-001-14-01012020', $this->club->fresh()->registration_no);
    }

    public function test_the_clubs_admin_can_open_and_save_it(): void
    {
        $this->actingAsClubAdmin($this->club);

        $this->get(route('admin.clubs.profile.edit', $this->club))
            ->assertOk()
            ->assertSee('Club profile')
            ->assertSee('Registration no. (ROS/PPM), optional')
            ->assertSee(route('admin.clubs.profile.edit', $this->club), false);

        $this->put(route('admin.clubs.profile.update', $this->club), $this->payload())
            ->assertRedirect(route('admin.clubs.profile.edit', $this->club))
            ->assertSessionHasNoErrors();

        $club = $this->club->fresh();
        $this->assertSame('PPM-001-14-01012020', $club->registration_no);
        $this->assertSame("Aras 3, Menara Contoh\nJalan Contoh\n50450 Kuala Lumpur", $club->address);
        $this->assertSame(7, $club->financialYearStartMonth());
    }

    public function test_the_super_admin_can_open_and_save_it(): void
    {
        $this->actingAsSuperAdmin();

        $this->get(route('admin.clubs.profile.edit', $this->club))->assertOk();
        $this->put(route('admin.clubs.profile.update', $this->club), $this->payload(['name' => 'Kelab Baharu']))->assertSessionHasNoErrors();
        $this->assertSame('Kelab Baharu', $this->club->fresh()->name);
    }

    public function test_an_inactive_admin_membership_is_refused(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->club->members()->attach($admin, ['role' => 'admin', 'job_level' => 'manager', 'joined_date' => now()->toDateString(), 'is_active' => false]);

        $this->actingAs($admin)->get(route('admin.clubs.profile.edit', $this->club))->assertForbidden();
    }

    // ── What is saved ────────────────────────────────────────────────────

    public function test_every_change_is_audited_with_old_and_new_values(): void
    {
        $this->actingAsClubAdmin($this->club);

        $this->put(route('admin.clubs.profile.update', $this->club), $this->payload(['name' => 'Kelab Baharu']));

        $log = AuditLog::where('action', 'club.profile_updated')->sole();
        $this->assertSame($this->club->id, $log->club_id);
        $this->assertSame('Kelab Rekreasi', $log->old_values['name']);
        $this->assertSame('Kelab Baharu', $log->new_values['name']);
        $this->assertSame(1, (int) $log->old_values['financial_year_start_month']);
        $this->assertSame(7, (int) $log->new_values['financial_year_start_month']);
        $this->assertNull($log->old_values['registration_no']);
        // Unchanged fields are left out.
        $this->assertArrayNotHasKey('email', $log->new_values);

        // Saving the same values again writes no audit row.
        $this->put(route('admin.clubs.profile.update', $this->club), $this->payload(['name' => 'Kelab Baharu']));
        $this->assertSame(1, AuditLog::where('action', 'club.profile_updated')->count());
    }

    public function test_the_month_must_be_1_to_12_and_lengths_are_capped(): void
    {
        $this->actingAsClubAdmin($this->club);

        foreach ([0, 13, 'x', ''] as $bad) {
            $this->put(route('admin.clubs.profile.update', $this->club), $this->payload(['financial_year_start_month' => $bad]))
                ->assertSessionHasErrors('financial_year_start_month');
        }
        $this->put(route('admin.clubs.profile.update', $this->club), $this->payload(['registration_no' => str_repeat('A', 61)]))
            ->assertSessionHasErrors('registration_no');
        $this->put(route('admin.clubs.profile.update', $this->club), $this->payload(['address' => str_repeat('a', 501)]))
            ->assertSessionHasErrors('address');
        $this->put(route('admin.clubs.profile.update', $this->club), $this->payload(['name' => '']))
            ->assertSessionHasErrors('name');
        $this->put(route('admin.clubs.profile.update', $this->club), $this->payload(['email' => 'not-an-email']))
            ->assertSessionHasErrors('email');

        $this->assertSame(1, $this->club->fresh()->financialYearStartMonth());
    }

    public function test_the_longest_values_fit_their_columns(): void
    {
        $this->actingAsClubAdmin($this->club);
        // Multibyte text at the limits (MariaDB / MySQL refuse what does not fit).
        $address = str_repeat('é', 500);

        $this->put(route('admin.clubs.profile.update', $this->club), $this->payload([
            'name' => str_repeat('ñ', 255),
            'registration_no' => str_repeat('Ü', 60),
            'address' => $address,
        ]))->assertSessionHasNoErrors();

        $this->assertSame($address, $this->club->fresh()->address);
    }

    public function test_other_club_fields_cannot_be_set_through_the_profile(): void
    {
        $this->actingAsClubAdmin($this->club);

        $this->put(route('admin.clubs.profile.update', $this->club), $this->payload([
            'is_active' => 0,
            'payment_gateway' => 'billplz',
            'bank_account_no' => '999999999',
            'logo' => null,
            'issuer_snapshot' => 'x',
        ]))->assertSessionHasNoErrors();

        $club = $this->club->fresh();
        $this->assertTrue((bool) $club->is_active);
        $this->assertSame('toyyibpay', $club->activeGateway());
        $this->assertNull($club->bank_account_no);
    }

    public function test_the_page_escapes_what_admins_typed(): void
    {
        $this->club->forceFill(['registration_no' => '<script>alert(1)</script>', 'address' => '<img src=x onerror=alert(1)>'])->save();
        $this->actingAsClubAdmin($this->club);

        $this->get(route('admin.clubs.profile.edit', $this->club))
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('<img src=x onerror', false);
    }

    // ── Logo ─────────────────────────────────────────────────────────────

    public function test_a_logo_is_uploaded_re_encoded_replaced_and_removed_keeping_old_files(): void
    {
        $this->actingAsClubAdmin($this->club);

        $this->put(route('admin.clubs.profile.update', $this->club), $this->payload(['logo' => $this->upload('logo.jpg', $this->jpegBytes(1200, 600))]))
            ->assertSessionHasNoErrors();
        $first = $this->club->fresh()->logo;
        $this->assertMatchesRegularExpression('#^club-logos/[0-9a-f-]{36}\.png$#', $first);
        $info = getimagesizefromstring(Storage::disk('public')->get($first));
        $this->assertSame([IMAGETYPE_PNG, 512, 256], [$info[2], $info[0], $info[1]]);

        $log = AuditLog::where('action', 'club.profile_updated')->latest('id')->first();
        $this->assertNull($log->old_values['logo']);
        $this->assertSame($first, $log->new_values['logo']);

        $this->get(route('admin.clubs.profile.edit', $this->club))->assertSee(asset('storage/'.$first), false);

        // Replace: the old file stays (an issued receipt may still show it).
        $this->put(route('admin.clubs.profile.update', $this->club), $this->payload(['logo' => $this->upload('logo.webp', $this->webpBytes())]));
        $second = $this->club->fresh()->logo;
        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertExists([$first, $second]);

        // Remove: the club has no logo; the files stay.
        $this->put(route('admin.clubs.profile.update', $this->club), $this->payload(['remove_logo' => '1']));
        $this->assertNull($this->club->fresh()->logo);
        Storage::disk('public')->assertExists([$first, $second]);
    }

    public function test_svg_gif_and_oversize_logos_are_refused(): void
    {
        $this->actingAsClubAdmin($this->club);

        $cases = [
            $this->upload('logo.svg', $this->svgBytes()),
            $this->upload('logo.gif', $this->gifBytes()),
            // A GIF renamed .png: the detected type gives it away.
            $this->upload('logo.png', $this->gifBytes()),
            // An SVG renamed .png.
            $this->upload('logo.png', $this->svgBytes()),
            $this->upload('logo.png', $this->pngBytes().str_repeat("\0", 2100 * 1024)),
            $this->upload('logo.png', $this->pngBytes(4001, 2)),
        ];
        foreach ($cases as $file) {
            $this->put(route('admin.clubs.profile.update', $this->club), $this->payload(['logo' => $file]))
                ->assertSessionHasErrors('logo');
        }

        $this->assertNull($this->club->fresh()->logo);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_the_super_admins_club_forms_use_the_same_logo_handling(): void
    {
        $this->actingAsSuperAdmin();

        $this->post(route('admin.clubs.store'), ['name' => 'Kelab Logo', 'logo' => $this->upload('logo.png', $this->pngBytes(800, 800))])
            ->assertSessionHasNoErrors();
        $club = Club::where('name', 'Kelab Logo')->sole();
        $first = $club->logo;
        $this->assertMatchesRegularExpression('#^club-logos/[0-9a-f-]{36}\.png$#', $first);
        $this->assertSame(512, getimagesizefromstring(Storage::disk('public')->get($first))[0]);

        $this->put(route('admin.clubs.update', $club), ['name' => 'Kelab Logo', 'is_active' => 1, 'logo' => $this->upload('logo.svg', $this->svgBytes())])
            ->assertSessionHasErrors('logo');
        $this->put(route('admin.clubs.update', $club), ['name' => 'Kelab Logo', 'is_active' => 1, 'logo' => $this->upload('logo.jpg', $this->jpegBytes())])
            ->assertSessionHasNoErrors();
        $this->assertNotSame($first, $club->fresh()->logo);
        // The replaced file is kept for receipts issued with it.
        Storage::disk('public')->assertExists([$first, $club->fresh()->logo]);
    }

    public function test_the_sidebar_links_to_the_profile(): void
    {
        $this->actingAsClubAdmin($this->club);

        $this->get(route('admin.clubs.members.index', $this->club))
            ->assertOk()
            ->assertSee('Club profile')
            ->assertSee(route('admin.clubs.profile.edit', $this->club), false);
    }
}
