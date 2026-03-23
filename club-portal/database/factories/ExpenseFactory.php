<?php

namespace Database\Factories;

use App\Models\Club;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Expense>
 */
class ExpenseFactory extends Factory
{
    protected $model = Expense::class;

    public function definition(): array
    {
        return [
            'club_id'             => Club::factory(),
            'expense_category_id' => ExpenseCategory::factory(),
            'recorded_by'         => User::factory(),
            'description'         => fake()->sentence(4),
            'amount'              => fake()->randomFloat(2, 10, 500),
            'expense_date'        => fake()->dateTimeBetween('first day of January', 'now')->format('Y-m-d'),
            'receipt'             => null,
        ];
    }

    /** Force the expense into a specific club (reuses its category). */
    public function forClub(Club $club): static
    {
        return $this->state(fn() => [
            'club_id'             => $club->id,
            'expense_category_id' => ExpenseCategory::factory()->for($club),
        ]);
    }
}
