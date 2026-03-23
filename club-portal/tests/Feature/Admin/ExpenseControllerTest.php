<?php

namespace Tests\Feature\Admin;

use App\Models\Club;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\User;
use Tests\TestCase;

class ExpenseControllerTest extends TestCase
{
    // ── Index ──────────────────────────────────────────────────────────────

    public function test_index_returns_200_for_club_admin(): void
    {
        $club = Club::factory()->create();
        $this->actingAsClubAdmin($club);

        $this->get(route('admin.clubs.expenses.index', $club))->assertOk();
    }

    // ── Store ──────────────────────────────────────────────────────────────

    public function test_store_creates_expense(): void
    {
        $club     = Club::factory()->create();
        $admin    = $this->actingAsClubAdmin($club);
        $category = ExpenseCategory::factory()->for($club)->create();

        $this->post(route('admin.clubs.expenses.store', $club), [
            'expense_category_id' => $category->id,
            'description'         => 'Office supplies purchase',
            'amount'              => 125.50,
            'expense_date'        => '2025-06-15',
        ])->assertRedirect(route('admin.clubs.expenses.index', $club));

        $this->assertDatabaseHas('expenses', [
            'club_id'     => $club->id,
            'recorded_by' => $admin->id,
            'amount'      => 125.50,
            'description' => 'Office supplies purchase',
        ]);
    }

    public function test_store_rejects_category_from_different_club(): void
    {
        $club      = Club::factory()->create();
        $otherClub = Club::factory()->create();
        $this->actingAsClubAdmin($club);
        $category  = ExpenseCategory::factory()->for($otherClub)->create();

        $this->post(route('admin.clubs.expenses.store', $club), [
            'expense_category_id' => $category->id,
            'description'         => 'Test expense',
            'amount'              => 50.00,
            'expense_date'        => '2025-06-15',
        ])->assertSessionHasErrors('expense_category_id');
    }

    public function test_store_validates_minimum_amount(): void
    {
        $club     = Club::factory()->create();
        $this->actingAsClubAdmin($club);
        $category = ExpenseCategory::factory()->for($club)->create();

        $this->post(route('admin.clubs.expenses.store', $club), [
            'expense_category_id' => $category->id,
            'description'         => 'Zero amount',
            'amount'              => 0,
            'expense_date'        => '2025-06-15',
        ])->assertSessionHasErrors('amount');
    }

    public function test_store_validates_required_description(): void
    {
        $club     = Club::factory()->create();
        $this->actingAsClubAdmin($club);
        $category = ExpenseCategory::factory()->for($club)->create();

        $this->post(route('admin.clubs.expenses.store', $club), [
            'expense_category_id' => $category->id,
            'description'         => '',
            'amount'              => 50.00,
            'expense_date'        => '2025-06-15',
        ])->assertSessionHasErrors('description');
    }

    // ── Update ─────────────────────────────────────────────────────────────

    public function test_update_changes_expense_amount(): void
    {
        $club     = Club::factory()->create();
        $admin    = $this->actingAsClubAdmin($club);
        $expense  = Expense::factory()->forClub($club)->create(['amount' => 100.00]);
        $category = ExpenseCategory::factory()->for($club)->create();

        $this->patch(route('admin.expenses.update', $expense), [
            'expense_category_id' => $category->id,
            'description'         => $expense->description,
            'amount'              => 200.00,
            'expense_date'        => $expense->expense_date,
        ])->assertRedirect();

        $this->assertDatabaseHas('expenses', ['id' => $expense->id, 'amount' => 200.00]);
    }

    // ── Destroy ────────────────────────────────────────────────────────────

    public function test_destroy_removes_expense(): void
    {
        $club    = Club::factory()->create();
        $this->actingAsClubAdmin($club);
        $expense = Expense::factory()->forClub($club)->create();

        $this->delete(route('admin.expenses.destroy', $expense))
            ->assertRedirect();

        $this->assertModelMissing($expense);
    }

    // ── Cross-club authorization ───────────────────────────────────────────

    public function test_show_returns_403_for_wrong_club_admin(): void
    {
        $clubA   = Club::factory()->create();
        $clubB   = Club::factory()->create();
        $this->actingAsClubAdmin($clubA);
        $expense = Expense::factory()->forClub($clubB)->create();

        $this->get(route('admin.expenses.show', $expense))->assertForbidden();
    }

    public function test_destroy_returns_403_for_wrong_club_admin(): void
    {
        $clubA   = Club::factory()->create();
        $clubB   = Club::factory()->create();
        $this->actingAsClubAdmin($clubA);
        $expense = Expense::factory()->forClub($clubB)->create();

        $this->delete(route('admin.expenses.destroy', $expense))->assertForbidden();
    }
}
