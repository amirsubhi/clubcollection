<?php

namespace Database\Factories;

use App\Models\Club;
use App\Models\ExpenseCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExpenseCategory>
 */
class ExpenseCategoryFactory extends Factory
{
    protected $model = ExpenseCategory::class;

    public function definition(): array
    {
        return [
            'club_id' => Club::factory(),
            'name'    => fake()->randomElement([
                'Event', 'Food & Beverage', 'Supplies', 'Decoration',
                'Transportation', 'Printing & Stationery', 'Maintenance', 'Miscellaneous',
            ]),
        ];
    }
}
