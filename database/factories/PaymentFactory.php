<?php

namespace Database\Factories;

use App\Models\Club;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 *
 * Base state: a **paid** monthly payment dated today.
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        return [
            'club_id'      => Club::factory(),
            'user_id'      => User::factory(),
            'recorded_by'  => User::factory(),
            'amount'       => 50.00,
            'frequency'    => 'monthly',
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end'   => now()->endOfMonth()->toDateString(),
            'due_date'     => now()->toDateString(),
            'paid_date'    => now()->toDateString(),
            'status'       => 'paid',
            'reference'    => null,
            'discount_id'  => null,
            'notes'        => null,
        ];
    }

    /** Pending payment with a future due date. */
    public function pending(): static
    {
        return $this->state([
            'status'    => 'pending',
            'paid_date' => null,
            'due_date'  => now()->addDays(7)->toDateString(),
        ]);
    }

    /** Overdue payment with a past due date. */
    public function overdue(): static
    {
        return $this->state([
            'status'    => 'overdue',
            'paid_date' => null,
            'due_date'  => now()->subDays(10)->toDateString(),
        ]);
    }

    /** Pin the payment to a specific club without creating a new one. */
    public function forClub(Club $club): static
    {
        return $this->state(['club_id' => $club->id]);
    }

    /** Pin the member (user_id) without creating a new user. */
    public function forMember(User $user): static
    {
        return $this->state(['user_id' => $user->id]);
    }
}
