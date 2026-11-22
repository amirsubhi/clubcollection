<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\FeeRate;
use App\Models\User;
use App\Support\Payments\FeeRates;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The data migration that closes the gaps the old fee-rate form left (it ended the previous rate on the day a
 * later-starting rate was entered).
 */
class FeeRateGapMigrationTest extends TestCase
{
    private function runMigration(): void
    {
        (require database_path('migrations/2026_11_26_000000_close_fee_rate_gaps.php'))->up();
    }

    private function rate(Club $club, string $level, float $amount, string $from, ?string $to): FeeRate
    {
        return FeeRate::factory()->create(['club_id' => $club->id, 'job_level' => $level, 'monthly_amount' => $amount, 'effective_from' => $from, 'effective_to' => $to]);
    }

    public function test_a_rate_closed_on_the_day_a_later_rate_was_entered_is_extended_to_the_day_before_it(): void
    {
        $this->travelTo(Carbon::create(2026, 11, 22, 10));
        $club = Club::factory()->create();
        $other = Club::factory()->create();
        $member = User::factory()->create();
        $club->members()->attach($member, ['role' => 'member', 'job_level' => 'executive', 'joined_date' => '2026-01-05', 'is_active' => true]);

        // Executive: RM 10 closed on 20 Nov when RM 12 from 1 Jan was entered → December had no rate.
        $old = $this->rate($club, 'executive', 10, '2025-01-01', '2026-11-20');
        $new = $this->rate($club, 'executive', 12, '2027-01-01', null);
        // An older chain with a gap too (closed 3 Mar, next from 1 May), and one already contiguous.
        $a = $this->rate($club, 'manager', 15, '2025-01-01', '2025-03-03');
        $b = $this->rate($club, 'manager', 18, '2025-05-01', '2025-12-31');
        $c = $this->rate($club, 'manager', 20, '2026-01-01', null);
        // A row replaced before it started (ends before it starts): history, left alone and not a "next" rate.
        $replaced = $this->rate($club, 'manager', 25, '2027-03-01', '2027-02-28');
        // Another club's level with the same code is its own chain.
        $elsewhere = $this->rate($other, 'executive', 7, '2025-01-01', '2026-06-01');

        $this->assertNull(FeeRates::monthlySen($club, $member, Carbon::parse('2026-12-01')), 'the gap before the migration');

        $this->runMigration();

        $this->assertSame('2026-12-31', $old->fresh()->effective_to->toDateString());
        $this->assertNull($new->fresh()->effective_to);
        $this->assertSame('2025-04-30', $a->fresh()->effective_to->toDateString());
        $this->assertSame('2025-12-31', $b->fresh()->effective_to->toDateString());
        $this->assertNull($c->fresh()->effective_to);
        $this->assertSame('2027-02-28', $replaced->fresh()->effective_to->toDateString());
        $this->assertSame('2026-06-01', $elsewhere->fresh()->effective_to->toDateString(), 'no next rate: unchanged');

        $this->assertSame(1000, FeeRates::monthlySen($club, $member, Carbon::parse('2026-12-01')));
        $this->assertSame(1200, FeeRates::monthlySen($club, $member, Carbon::parse('2027-01-01')));

        // Running it again changes nothing.
        $before = FeeRate::orderBy('id')->get(['id', 'effective_to'])->toArray();
        $this->runMigration();
        $this->assertSame($before, FeeRate::orderBy('id')->get(['id', 'effective_to'])->toArray());
    }

    public function test_overlapping_or_open_rows_are_left_as_they_are(): void
    {
        $club = Club::factory()->create();
        // Ends after the next one starts (overlap) or never ends: nothing to close.
        $overlap = $this->rate($club, 'executive', 10, '2025-01-01', '2026-03-15');
        $next = $this->rate($club, 'executive', 12, '2026-03-01', null);
        $open = $this->rate($club, 'manager', 10, '2025-01-01', null);
        $later = $this->rate($club, 'manager', 12, '2026-01-01', null);

        $this->runMigration();

        $this->assertSame('2026-03-15', $overlap->fresh()->effective_to->toDateString());
        $this->assertNull($next->fresh()->effective_to);
        $this->assertNull($open->fresh()->effective_to);
        $this->assertNull($later->fresh()->effective_to);
    }
}
