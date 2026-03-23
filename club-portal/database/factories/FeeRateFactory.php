<?php

namespace Database\Factories;

use App\Models\Club;
use App\Models\FeeRate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FeeRate>
 */
class FeeRateFactory extends Factory
{
    protected $model = FeeRate::class;

    public function definition(): array
    {
        return [
            'club_id'        => Club::factory(),
            'job_level'      => fake()->randomElement(['gm', 'agm', 'manager', 'executive', 'non_exec']),
            'monthly_amount' => fake()->randomFloat(2, 5, 100),
            'effective_from' => now()->startOfYear()->toDateString(),
            'effective_to'   => null,
        ];
    }

    public function forLevel(string $level, float $amount = 10.00): static
    {
        return $this->state(['job_level' => $level, 'monthly_amount' => $amount]);
    }
}
