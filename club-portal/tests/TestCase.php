<?php

namespace Tests;

use App\Models\Club;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Ensure the app appears installed so requests are not redirected to /install
        if (! file_exists(storage_path('app'))) {
            mkdir(storage_path('app'), 0755, true);
        }
        touch(storage_path('app/.installed'));
    }

    /**
     * Create a super_admin user, log them in, and return the user.
     * 2FA is NOT enabled (two_factor_confirmed_at = null) so the
     * RequiresTwoFactor middleware passes through automatically.
     */
    protected function actingAsSuperAdmin(): User
    {
        $user = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($user);
        return $user;
    }

    /**
     * Create a user with role=admin, attach them as club admin via the
     * club_user pivot, log them in, and return the user.
     */
    protected function actingAsClubAdmin(Club $club): User
    {
        $user = User::factory()->create(['role' => 'admin']);
        $club->members()->attach($user, [
            'role'        => 'admin',
            'job_level'   => 'manager',
            'joined_date' => now()->toDateString(),
            'is_active'   => true,
        ]);
        $this->actingAs($user);
        return $user;
    }

    /**
     * Create a plain member, attach them to $club, log them in, and return the user.
     */
    protected function actingAsMember(Club $club): User
    {
        $user = User::factory()->create(['role' => 'member']);
        $club->members()->attach($user, [
            'role'        => 'member',
            'job_level'   => 'executive',
            'joined_date' => now()->toDateString(),
            'is_active'   => true,
        ]);
        $this->actingAs($user);
        return $user;
    }
}
