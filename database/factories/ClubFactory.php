<?php

namespace Database\Factories;

use App\Models\Club;
use App\Models\ClubJobLevel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Club>
 */
class ClubFactory extends Factory
{
    protected $model = Club::class;

    /** Job levels every factory club gets (code => name), so tests can put members and fee rates on them. */
    public const JOB_LEVELS = ['executive' => 'Executive', 'manager' => 'Manager'];

    public function definition(): array
    {
        return [
            'name'      => fake()->company(),
            'email'     => fake()->unique()->companyEmail(),
            'logo'      => null,
            'is_active' => true,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(fn (Club $club) => self::putJobLevels($club, self::JOB_LEVELS));
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    /**
     * Replace the default levels with $levels (code => name, all active).
     *
     * @param  array<string, string>  $levels
     */
    public function withJobLevels(array $levels): static
    {
        return $this->afterCreating(function (Club $club) use ($levels) {
            ClubJobLevel::where('club_id', $club->id)->delete();
            self::putJobLevels($club, $levels);
        });
    }

    /** @param  array<string, string>  $levels */
    private static function putJobLevels(Club $club, array $levels): void
    {
        $order = 0;
        foreach ($levels as $code => $name) {
            $club->jobLevels()->create(['code' => $code, 'name' => $name, 'sort_order' => ++$order, 'is_active' => true]);
        }
        $club->unsetRelation('jobLevels');
    }
}
