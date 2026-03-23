<?php

namespace Tests\Feature\Admin;

use App\Models\Club;
use App\Models\User;
use Tests\TestCase;

class ClubControllerTest extends TestCase
{
    // ── Access control ─────────────────────────────────────────────────────

    public function test_index_requires_super_admin(): void
    {
        $club = Club::factory()->create();

        // Club admin (non-super) cannot access
        $this->actingAsClubAdmin($club);
        $this->get(route('admin.clubs.index'))->assertForbidden();
    }

    public function test_super_admin_can_access_index(): void
    {
        $this->actingAsSuperAdmin();

        $this->get(route('admin.clubs.index'))->assertOk();
    }

    // ── Index ──────────────────────────────────────────────────────────────

    public function test_index_lists_all_clubs(): void
    {
        $this->actingAsSuperAdmin();
        Club::factory()->count(3)->create();

        $clubs = Club::all();
        $response = $this->get(route('admin.clubs.index'));
        $response->assertOk();

        foreach ($clubs as $club) {
            $response->assertSee($club->name);
        }
    }

    // ── Store ──────────────────────────────────────────────────────────────

    public function test_store_creates_club_and_redirects(): void
    {
        $this->actingAsSuperAdmin();

        $this->post(route('admin.clubs.store'), [
            'name'  => 'New Test Club',
            'email' => 'newclub@example.com',
        ])->assertRedirect(route('admin.clubs.index'));

        $this->assertDatabaseHas('clubs', ['name' => 'New Test Club']);
    }

    public function test_store_validates_required_name(): void
    {
        $this->actingAsSuperAdmin();

        $this->post(route('admin.clubs.store'), ['name' => ''])
            ->assertSessionHasErrors('name');
    }

    public function test_store_validates_email_format(): void
    {
        $this->actingAsSuperAdmin();

        $this->post(route('admin.clubs.store'), [
            'name'  => 'Club With Bad Email',
            'email' => 'notanemail',
        ])->assertSessionHasErrors('email');
    }

    // ── Update ─────────────────────────────────────────────────────────────

    public function test_update_modifies_club(): void
    {
        $this->actingAsSuperAdmin();
        $club = Club::factory()->create(['name' => 'Old Name']);

        $this->patch(route('admin.clubs.update', $club), [
            'name'      => 'Updated Name',
            'is_active' => true,
        ])->assertRedirect(route('admin.clubs.index'));

        $this->assertDatabaseHas('clubs', ['id' => $club->id, 'name' => 'Updated Name']);
    }

    public function test_update_preserves_toyyibpay_key_when_blank_submitted(): void
    {
        $this->actingAsSuperAdmin();
        $club = Club::factory()->create([
            'name'                 => 'Club With Key',
            'toyyibpay_secret_key' => 'existing-secret-key',
        ]);

        $this->patch(route('admin.clubs.update', $club), [
            'name'                 => 'Club With Key',
            'toyyibpay_secret_key' => '',   // intentionally blank
            'is_active'            => true,
        ])->assertRedirect();

        // Key should not be overwritten with null/blank.
        // The field is encrypted, so assert via the Eloquent model (decrypted value).
        $this->assertEquals('existing-secret-key', $club->fresh()->toyyibpay_secret_key);
    }

    // ── Destroy ────────────────────────────────────────────────────────────

    public function test_destroy_deletes_club(): void
    {
        $this->actingAsSuperAdmin();
        $club = Club::factory()->create();

        $this->delete(route('admin.clubs.destroy', $club))
            ->assertRedirect(route('admin.clubs.index'));

        $this->assertModelMissing($club);
    }

    public function test_destroy_requires_super_admin(): void
    {
        $club = Club::factory()->create();
        $this->actingAsClubAdmin($club);

        $this->delete(route('admin.clubs.destroy', $club))->assertForbidden();
    }
}
