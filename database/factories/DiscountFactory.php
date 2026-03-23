<?php

namespace Database\Factories;

use App\Models\Club;
use App\Models\Discount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Discount>
 */
class DiscountFactory extends Factory
{
    protected $model = Discount::class;

    public function definition(): array
    {
        return [
            'club_id'    => Club::factory(),
            'name'       => fake()->words(2, true),
            'type'       => 'percentage',
            'value'      => 10.00,
            'valid_from' => now()->toDateString(),
            'valid_to'   => null,
            'is_active'  => true,
        ];
    }

    public function fixed(float $amount = 5.00): static
    {
        return $this->state(['type' => 'fixed', 'value' => $amount]);
    }
}
