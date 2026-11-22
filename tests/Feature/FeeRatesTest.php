<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\FeeRate;
use App\Models\Payment;
use App\Models\User;
use App\Support\Payments\FeeRates;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * FeeRates: the one rule for a member's monthly fee (Generate fees and pay ahead), and the fee-rate form
 * keeping the old rate in force until a future-dated one starts.
 */
class FeeRatesTest extends TestCase
{
    private Club $club;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(Carbon::create(2026, 11, 16, 10));
        $this->club = Club::factory()->create();
        $this->member = User::factory()->create();
        $this->club->members()->attach($this->member, ['role' => 'member', 'job_level' => 'executive', 'joined_date' => '2026-01-05', 'is_active' => true]);
    }

    private function rate(float $amount, string $from, ?string $to = null, string $level = 'executive'): FeeRate
    {
        return FeeRate::factory()->create(['club_id' => $this->club->id, 'job_level' => $level, 'monthly_amount' => $amount, 'effective_from' => $from, 'effective_to' => $to]);
    }

    private function sen(string $month): ?int
    {
        return FeeRates::monthlySen($this->club, $this->member, Carbon::parse($month.'-01'));
    }

    public function test_the_open_rate_applies_from_its_start(): void
    {
        $this->rate(10, '2026-03-01');

        $this->assertNull($this->sen('2026-02'));
        $this->assertSame(1000, $this->sen('2026-03'));
        $this->assertSame(1000, $this->sen('2027-06'));
    }

    public function test_a_closed_rate_covers_its_months_and_the_latest_start_wins_in_the_month_of_a_change(): void
    {
        $this->rate(10, '2026-01-01', '2026-11-16');
        $this->rate(12, '2026-11-16');

        $this->assertSame(1000, $this->sen('2026-10'));
        $this->assertSame(1200, $this->sen('2026-11'), 'both cover November: the later start wins');
        $this->assertSame(1200, $this->sen('2026-12'));
    }

    public function test_same_start_date_the_newest_row_wins(): void
    {
        $this->rate(10, '2026-11-01', '2026-11-16');
        $this->rate(11, '2026-11-01');

        $this->assertSame(1100, $this->sen('2026-11'));
    }

    public function test_no_rate_a_zero_rate_another_level_or_no_membership_is_null(): void
    {
        $this->rate(10, '2026-01-01', null, 'manager');
        $this->assertNull($this->sen('2026-11'), 'only the member\'s own level counts');

        $this->rate(0, '2026-01-01');
        $this->assertNull($this->sen('2026-11'), 'a zero rate bills nothing');

        $this->assertNull(FeeRates::monthlySen($this->club, User::factory()->create(), Carbon::parse('2026-11-01')));
    }

    public function test_by_month_reads_each_month_with_one_rule(): void
    {
        $this->rate(10, '2026-01-01', '2026-12-31');
        $this->rate(12, '2027-01-01');

        $this->assertSame(
            ['2026-11-01' => 1000, '2026-12-01' => 1000, '2027-01-01' => 1200],
            FeeRates::monthlySenByMonth($this->club, $this->member, [Carbon::parse('2026-11-01'), Carbon::parse('2026-12-15'), Carbon::parse('2027-01-31')])
        );
    }

    public function test_saving_a_future_dated_rate_keeps_the_old_one_until_the_day_before(): void
    {
        $old = $this->rate(10, '2026-01-01');
        $this->actingAsClubAdmin($this->club);

        $this->post(route('admin.clubs.fee-rates.store', $this->club), ['rates' => [
            ['job_level' => 'executive', 'monthly_amount' => '12.00', 'effective_from' => '2027-01-01'],
        ]])->assertRedirect();

        $this->assertSame('2026-12-31', $old->fresh()->effective_to->toDateString());
        $this->assertSame(1000, $this->sen('2026-11'));
        $this->assertSame(1000, $this->sen('2026-12'), 'no gap before the new rate starts');
        $this->assertSame(1200, $this->sen('2027-01'));
        $this->get(route('admin.clubs.fee-rates.index', $this->club))->assertOk()->assertSee('From 01 Jan 2027: RM 12.00/month');
    }

    public function test_saving_a_rate_from_today_or_earlier_closes_the_old_one_today(): void
    {
        $old = $this->rate(10, '2026-01-01');
        $this->actingAsClubAdmin($this->club);

        $this->post(route('admin.clubs.fee-rates.store', $this->club), ['rates' => [
            ['job_level' => 'executive', 'monthly_amount' => '12.00', 'effective_from' => '2026-11-01'],
        ]])->assertRedirect();

        $this->assertSame('2026-11-16', $old->fresh()->effective_to->toDateString());
        $this->assertSame(1200, $this->sen('2026-11'));
        $this->assertSame(1000, $this->sen('2026-10'));
    }

    public function test_the_add_payment_hint_uses_the_rate_in_force_this_month(): void
    {
        $this->rate(10, '2026-01-01', '2026-12-31');
        $this->rate(12, '2027-01-01');
        $this->actingAsClubAdmin($this->club);

        $rates = $this->get(route('admin.clubs.payments.create', $this->club))->assertOk()->viewData('feeRates');
        $this->assertSame('10.00', $rates['executive']->monthly_amount);
    }

    // ---- Pay ahead uses the same rule ----

    private function payAhead(string $frequency, string $start)
    {
        $this->actingAs($this->member);

        return $this->post(route('member.payments.generate-future', $this->club), ['period_start' => $start, 'frequency' => $frequency]);
    }

    public function test_pay_ahead_amounts_are_unchanged_with_one_open_rate(): void
    {
        $this->rate(10, '2026-01-01');

        $this->payAhead('quarterly', '2026-12-01')->assertSessionHas('success');
        $this->payAhead('monthly', '2027-03-01')->assertSessionHas('success');

        $this->assertSame(['30.00', '10.00'], Payment::where('user_id', $this->member->id)->orderBy('period_start')->pluck('amount')->all());
    }

    public function test_pay_ahead_adds_up_each_month_when_a_future_dated_rate_starts_inside_the_period(): void
    {
        $this->rate(10, '2026-01-01', '2026-12-31');
        $this->rate(12, '2027-01-01');

        $this->payAhead('quarterly', '2026-12-01')->assertSessionHas('success');

        // December at RM 10, January and February at RM 12.
        $this->assertSame('34.00', Payment::where('user_id', $this->member->id)->sole()->amount);
    }

    public function test_pay_ahead_is_refused_when_a_month_of_the_period_has_no_rate(): void
    {
        $this->rate(12, '2027-01-01');

        $this->payAhead('quarterly', '2026-12-01')->assertSessionHas('error', 'member.no_fee_rate');
        $this->payAhead('quarterly', '2027-01-01')->assertSessionHas('success');
        $this->assertSame('36.00', Payment::where('user_id', $this->member->id)->sole()->amount);
    }

    public function test_the_pay_ahead_form_gets_each_months_rate(): void
    {
        $this->rate(10, '2026-01-01', '2026-12-31');
        $this->rate(12, '2027-01-01');
        $this->actingAs($this->member);

        $this->get(route('member.payments.index', $this->club))
            ->assertInertia(fn (Assert $page) => $page
                ->where('futureForm.monthlySen', 1000)
                ->where('futureForm.monthRates.2026-12-01', 1000)
                ->where('futureForm.monthRates.2027-01-01', 1200)
                ->where('futureForm.monthRates.2027-11-01', 1200)
                ->has('futureForm.monthRates', 12)
            );
    }
}
