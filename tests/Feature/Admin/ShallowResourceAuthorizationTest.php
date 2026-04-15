<?php

namespace Tests\Feature\Admin;

use App\Models\Club;
use App\Models\Discount;
use App\Models\Expense;
use App\Models\FeeRate;
use App\Models\Payment;
use App\Models\User;
use Tests\TestCase;

/**
 * IDOR coverage for shallow nested resources.
 *
 * On routes like /admin/members/{member} the {club} URL segment is dropped,
 * so the ClubAdmin middleware has no club to verify ownership against.
 * Each shallow controller MUST call AuthorizesClubResource::authorizeClubAdmin()
 * on the resolved club. These tests assert that a club admin of Club A
 * gets 403/404 when targeting a resource that belongs to Club B.
 */
class ShallowResourceAuthorizationTest extends TestCase
{
    // ── Members ────────────────────────────────────────────────────────────

    public function test_member_edit_blocked_for_wrong_club_admin(): void
    {
        [$clubA, $clubB] = [Club::factory()->create(), Club::factory()->create()];
        $this->actingAsClubAdmin($clubA);

        $memberB = User::factory()->create();
        $clubB->members()->attach($memberB, ['role' => 'member', 'job_level' => 'executive', 'joined_date' => now()->toDateString(), 'is_active' => true]);

        $this->get(route('admin.members.edit', $memberB))
            ->assertStatus(404);
    }

    public function test_member_update_blocked_for_wrong_club_admin(): void
    {
        [$clubA, $clubB] = [Club::factory()->create(), Club::factory()->create()];
        $this->actingAsClubAdmin($clubA);

        $memberB = User::factory()->create();
        $clubB->members()->attach($memberB, ['role' => 'member', 'job_level' => 'executive', 'joined_date' => now()->toDateString(), 'is_active' => true]);

        $this->patch(route('admin.members.update', $memberB), [
            'role'        => 'admin',
            'job_level'   => 'manager',
            'joined_date' => now()->toDateString(),
            'is_active'   => true,
        ])->assertStatus(404);

        // Pivot must NOT have changed.
        $pivot = $clubB->members()->where('users.id', $memberB->id)->first()->pivot;
        $this->assertSame('member', $pivot->role);
        $this->assertSame('executive', $pivot->job_level);
    }

    public function test_member_destroy_blocked_for_wrong_club_admin(): void
    {
        [$clubA, $clubB] = [Club::factory()->create(), Club::factory()->create()];
        $this->actingAsClubAdmin($clubA);

        $memberB = User::factory()->create();
        $clubB->members()->attach($memberB, ['role' => 'member', 'job_level' => 'executive', 'joined_date' => now()->toDateString(), 'is_active' => true]);

        $this->delete(route('admin.members.destroy', $memberB))
            ->assertStatus(404);

        $this->assertTrue($clubB->members()->where('users.id', $memberB->id)->exists());
    }

    public function test_member_pivot_update_does_not_promote_global_users_role(): void
    {
        // Regression for an earlier bug where setting pivot.role = 'admin'
        // also wrote 'admin' to users.role on the global users table.
        $club  = Club::factory()->create();
        $this->actingAsClubAdmin($club);
        $member = User::factory()->create(['role' => 'member']);
        $club->members()->attach($member, [
            'role'        => 'member',
            'job_level'   => 'executive',
            'joined_date' => now()->toDateString(),
            'is_active'   => true,
        ]);

        $this->patch(route('admin.members.update', $member), [
            'role'        => 'admin',
            'job_level'   => 'manager',
            'joined_date' => now()->toDateString(),
            'is_active'   => true,
        ])->assertRedirect();

        // Pivot updated, but global role unchanged.
        $this->assertSame('admin', $club->members()->where('users.id', $member->id)->first()->pivot->role);
        $this->assertSame('member', $member->fresh()->role);
    }

    // ── Fee rates ──────────────────────────────────────────────────────────

    public function test_fee_rate_destroy_blocked_for_wrong_club_admin(): void
    {
        [$clubA, $clubB] = [Club::factory()->create(), Club::factory()->create()];
        $this->actingAsClubAdmin($clubA);
        $rateB = FeeRate::factory()->for($clubB)->create();

        $this->delete(route('admin.fee-rates.destroy', $rateB))
            ->assertForbidden();

        $this->assertDatabaseHas('fee_rates', ['id' => $rateB->id]);
    }

    // ── Expenses ───────────────────────────────────────────────────────────

    public function test_expense_show_blocked_for_wrong_club_admin(): void
    {
        [$clubA, $clubB] = [Club::factory()->create(), Club::factory()->create()];
        $this->actingAsClubAdmin($clubA);
        $expenseB = Expense::factory()->forClub($clubB)->create();

        $this->get(route('admin.expenses.show', $expenseB))
            ->assertForbidden();
    }

    public function test_expense_destroy_blocked_for_wrong_club_admin(): void
    {
        [$clubA, $clubB] = [Club::factory()->create(), Club::factory()->create()];
        $this->actingAsClubAdmin($clubA);
        $expenseB = Expense::factory()->forClub($clubB)->create();

        $this->delete(route('admin.expenses.destroy', $expenseB))
            ->assertForbidden();

        $this->assertDatabaseHas('expenses', ['id' => $expenseB->id]);
    }

    // ── Discounts ──────────────────────────────────────────────────────────

    public function test_discount_edit_blocked_for_wrong_club_admin(): void
    {
        [$clubA, $clubB] = [Club::factory()->create(), Club::factory()->create()];
        $this->actingAsClubAdmin($clubA);
        $discountB = Discount::factory()->for($clubB)->create();

        $this->get(route('admin.discounts.edit', $discountB))
            ->assertForbidden();
    }

    public function test_discount_destroy_blocked_for_wrong_club_admin(): void
    {
        [$clubA, $clubB] = [Club::factory()->create(), Club::factory()->create()];
        $this->actingAsClubAdmin($clubA);
        $discountB = Discount::factory()->for($clubB)->create();

        $this->delete(route('admin.discounts.destroy', $discountB))
            ->assertForbidden();

        $this->assertDatabaseHas('discounts', ['id' => $discountB->id]);
    }

    // ── Payments (already covered in PaymentControllerTest, sanity check) ──

    public function test_payment_destroy_blocked_for_wrong_club_admin(): void
    {
        [$clubA, $clubB] = [Club::factory()->create(), Club::factory()->create()];
        $this->actingAsClubAdmin($clubA);
        $memberB = User::factory()->create();
        $clubB->members()->attach($memberB, ['role' => 'member', 'job_level' => 'executive', 'joined_date' => now()->toDateString(), 'is_active' => true]);
        $paymentB = Payment::factory()->forClub($clubB)->forMember($memberB)->create();

        $this->delete(route('admin.payments.destroy', $paymentB))
            ->assertForbidden();

        $this->assertDatabaseHas('payments', ['id' => $paymentB->id]);
    }
}
