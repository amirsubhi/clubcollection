<?php

namespace Tests\Feature\Admin;

use App\Mail\MemberWelcome;
use App\Models\Club;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MemberControllerTest extends TestCase
{
    // ── Index ──────────────────────────────────────────────────────────────

    public function test_index_returns_200_for_club_admin(): void
    {
        $club = Club::factory()->create();
        $this->actingAsClubAdmin($club);

        $this->get(route('admin.clubs.members.index', $club))->assertOk();
    }

    // ── Store ──────────────────────────────────────────────────────────────

    public function test_store_creates_user_and_attaches_to_club(): void
    {
        Mail::fake();
        $club = Club::factory()->create();
        $this->actingAsClubAdmin($club);

        $this->post(route('admin.clubs.members.store', $club), [
            'name'        => 'New Member',
            'email'       => 'newmember@example.com',
            'job_level'   => 'executive',
            'role'        => 'member',
            'joined_date' => '2025-01-15',
        ])->assertRedirect(route('admin.clubs.members.index', $club));

        $user = User::where('email', 'newmember@example.com')->first();
        $this->assertNotNull($user);
        $this->assertTrue($club->members()->where('users.id', $user->id)->exists());
    }

    public function test_store_validates_required_fields(): void
    {
        $club = Club::factory()->create();
        $this->actingAsClubAdmin($club);

        $this->post(route('admin.clubs.members.store', $club), [])
            ->assertSessionHasErrors(['name', 'email', 'job_level', 'role', 'joined_date']);
    }

    public function test_store_rejects_invalid_job_level(): void
    {
        $club = Club::factory()->create();
        $this->actingAsClubAdmin($club);

        $this->post(route('admin.clubs.members.store', $club), [
            'name'        => 'Test User',
            'email'       => 'testuser@example.com',
            'job_level'   => 'intern',
            'role'        => 'member',
            'joined_date' => '2025-01-15',
        ])->assertSessionHasErrors('job_level');
    }

    public function test_store_rejects_duplicate_email(): void
    {
        Mail::fake();
        $club     = Club::factory()->create();
        $this->actingAsClubAdmin($club);
        $existing = User::factory()->create(['email' => 'duplicate@example.com']);

        $this->post(route('admin.clubs.members.store', $club), [
            'name'        => 'Duplicate',
            'email'       => 'duplicate@example.com',
            'job_level'   => 'executive',
            'role'        => 'member',
            'joined_date' => '2025-01-15',
        ])->assertSessionHasErrors('email');
    }

    public function test_store_sends_welcome_email(): void
    {
        Mail::fake();
        $club = Club::factory()->create();
        $this->actingAsClubAdmin($club);

        $this->post(route('admin.clubs.members.store', $club), [
            'name'        => 'Welcome Member',
            'email'       => 'welcome@example.com',
            'job_level'   => 'manager',
            'role'        => 'member',
            'joined_date' => '2025-01-15',
        ]);

        Mail::assertSent(MemberWelcome::class, fn($mail) => $mail->hasTo('welcome@example.com'));
    }

    // ── Update ─────────────────────────────────────────────────────────────

    public function test_update_changes_pivot_role_and_job_level(): void
    {
        $club   = Club::factory()->create();
        $this->actingAsClubAdmin($club);
        $member = User::factory()->create();
        $club->members()->attach($member, [
            'role'        => 'member',
            'job_level'   => 'executive',
            'joined_date' => '2025-01-01',
            'is_active'   => true,
        ]);

        $this->patch(route('admin.members.update', $member), [
            'role'        => 'admin',
            'job_level'   => 'manager',
            'joined_date' => '2025-01-01',
            'is_active'   => true,
        ])->assertRedirect();

        $pivot = $club->members()->where('users.id', $member->id)->first()?->pivot;
        $this->assertEquals('admin', $pivot->role);
        $this->assertEquals('manager', $pivot->job_level);
    }

    // ── Destroy ────────────────────────────────────────────────────────────

    public function test_destroy_detaches_member_from_club(): void
    {
        $club   = Club::factory()->create();
        $this->actingAsClubAdmin($club);
        $member = User::factory()->create();
        $club->members()->attach($member, [
            'role'        => 'member',
            'job_level'   => 'executive',
            'joined_date' => '2025-01-01',
            'is_active'   => true,
        ]);

        $this->delete(route('admin.members.destroy', $member))
            ->assertRedirect();

        // Pivot row removed
        $this->assertFalse($club->members()->where('users.id', $member->id)->exists());
        // User record still exists
        $this->assertDatabaseHas('users', ['id' => $member->id]);
    }
}
