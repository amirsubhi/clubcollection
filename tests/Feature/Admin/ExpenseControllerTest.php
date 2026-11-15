<?php

namespace Tests\Feature\Admin;

use App\Models\Club;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use Tests\TestCase;

/**
 * Expenses moved to Fund Usage. The old GET URLs redirect there; the old
 * write routes are gone. Expense categories still live on Blade.
 */
class ExpenseControllerTest extends TestCase
{
    public function test_old_index_and_create_redirect_to_fund_usage(): void
    {
        $club = Club::factory()->create();
        $this->actingAsClubAdmin($club);

        $this->get(route('admin.clubs.expenses.index', $club))
            ->assertRedirect(route('admin.clubs.fund-usage.index', $club));
        $this->get(route('admin.clubs.expenses.create', $club))
            ->assertRedirect(route('admin.clubs.fund-usage.create', $club));
    }

    public function test_old_show_and_edit_redirect_to_fund_usage(): void
    {
        $club = Club::factory()->create();
        $this->actingAsClubAdmin($club);
        $expense = Expense::factory()->forClub($club)->create();

        $this->get(route('admin.expenses.show', $expense))
            ->assertRedirect(route('admin.fund-usage.show', $expense));
        $this->get(route('admin.expenses.edit', $expense))
            ->assertRedirect(route('admin.fund-usage.edit', $expense));
    }

    public function test_old_show_returns_403_for_wrong_club_admin(): void
    {
        $clubA = Club::factory()->create();
        $clubB = Club::factory()->create();
        $this->actingAsClubAdmin($clubA);
        $expense = Expense::factory()->forClub($clubB)->create();

        $this->get(route('admin.expenses.show', $expense))->assertForbidden();
        $this->get(route('admin.clubs.expenses.index', $clubB))->assertForbidden();
    }

    public function test_old_write_routes_are_gone(): void
    {
        $club = Club::factory()->create();
        $this->actingAsClubAdmin($club);
        $expense = Expense::factory()->forClub($club)->create();

        $this->assertFalse(\Illuminate\Support\Facades\Route::has('admin.clubs.expenses.store'));
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('admin.expenses.update'));
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('admin.expenses.destroy'));

        $this->withoutExceptionHandling();
        $this->expectException(\Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException::class);
        $this->delete("/admin/expenses/{$expense->id}");
    }

    public function test_expense_category_delete_redirects_to_category_index(): void
    {
        $club = Club::factory()->create();
        $this->actingAsClubAdmin($club);
        $category = ExpenseCategory::factory()->for($club)->create();

        $this->delete(route('admin.expense-categories.destroy', $category))
            ->assertRedirect(route('admin.clubs.expense-categories.index', $club));
        $this->assertModelMissing($category);
    }

    public function test_expense_category_with_records_is_not_deleted(): void
    {
        $club = Club::factory()->create();
        $this->actingAsClubAdmin($club);
        $expense = Expense::factory()->forClub($club)->create();

        $this->delete(route('admin.expense-categories.destroy', $expense->category))
            ->assertSessionHas('error');
        $this->assertModelExists($expense->category);
    }
}
