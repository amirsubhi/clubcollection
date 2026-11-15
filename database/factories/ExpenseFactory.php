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
            'payee'               => fake()->company(),
            'type'                => 'expense',
        ];
    }

    /** An approved (counted) record; the approver is a separate user. */
    public function approved(): static
    {
        return $this->state(fn () => [
            'status'       => 'approved',
            'submitted_at' => now()->subDay(),
            'approved_by'  => User::factory(),
            'approved_at'  => now(),
        ]);
    }

    public function submitted(): static
    {
        return $this->state(fn () => ['status' => 'submitted', 'submitted_at' => now()]);
    }

    public function voided(): static
    {
        return $this->approved()->state(fn () => [
            'status'            => 'void',
            'voided_by'         => User::factory(),
            'void_requested_at' => now(),
            'void_reason'       => 'Duplicate entry',
            'void_approved_by'  => User::factory(),
            'voided_at'         => now(),
        ]);
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
