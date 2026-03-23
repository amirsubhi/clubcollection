<?php

namespace Tests\Feature\Admin;

use App\Models\Club;
use App\Models\User;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    // ── Unauthenticated ────────────────────────────────────────────────────

    public function test_unauthenticated_redirected_to_login(): void
    {
        $this->get(route('admin.clubs.index'))
            ->assertRedirect(route('login'));
    }

    // ── Role-based access ──────────────────────────────────────────────────

    public function test_member_cannot_access_admin_area(): void
    {
        $club = Club::factory()->create();
        $this->actingAsMember($club);

        $this->get(route('admin.clubs.index'))
            ->assertForbidden();
    }

    public function test_club_admin_cannot_access_super_admin_routes(): void
    {
        $club = Club::factory()->create();
        $this->actingAsClubAdmin($club);

        // /admin/admins is a super_admin-only route
        $this->get(route('admin.admins.index'))
            ->assertForbidden();
    }

    public function test_super_admin_can_access_super_admin_routes(): void
    {
        $this->actingAsSuperAdmin();

        $this->get(route('admin.admins.index'))
            ->assertOk();
    }

    // ── Club-scoped access ─────────────────────────────────────────────────

    public function test_club_admin_locked_to_own_club(): void
    {
        $clubA = Club::factory()->create();
        $clubB = Club::factory()->create();
        $this->actingAsClubAdmin($clubA);

        // Should be forbidden to view another club's members
        $this->get(route('admin.clubs.members.index', $clubB))
            ->assertForbidden();
    }

    public function test_club_admin_can_access_own_club(): void
    {
        $club = Club::factory()->create();
        $this->actingAsClubAdmin($club);

        $this->get(route('admin.clubs.members.index', $club))
            ->assertOk();
    }

    // ── Two-factor authentication ──────────────────────────────────────────

    public function test_two_factor_redirects_when_enabled_and_not_verified(): void
    {
        // Create a super admin with 2FA fully enabled (two_factor_confirmed_at set)
        $user = User::factory()->create([
            'role'                    => 'super_admin',
            'two_factor_confirmed_at' => now(),
        ]);
        $this->actingAs($user);
        // Do NOT set the 'two_factor_verified' session flag

        $this->get(route('admin.clubs.index'))
            ->assertRedirect(route('two-factor.challenge'));
    }

    public function test_two_factor_passes_when_session_verified(): void
    {
        $user = User::factory()->create([
            'role'                    => 'super_admin',
            'two_factor_confirmed_at' => now(),
        ]);
        $this->actingAs($user);

        // Simulate that the 2FA challenge has already been passed
        $this->withSession(['two_factor_verified' => true])
            ->get(route('admin.clubs.index'))
            ->assertOk();
    }
}
