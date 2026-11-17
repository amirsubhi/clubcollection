<?php

namespace Tests\Feature\Member;

use App\Models\Club;
use App\Models\Payment;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * A fee past its due date leaves "Akan datang": the rows carry pastDue, and while the club's
 * grace period runs (DisplayStatus "due") the last grace day as lateAfter ("Lewat selepas <date>").
 */
class OverdueWordingTest extends TestCase
{
    public function test_past_due_fees_carry_the_grace_end_date(): void
    {
        $this->withoutVite();
        $this->travelTo(Carbon::create(2026, 11, 17, 10));
        $club = Club::factory()->create(['overdue_grace_days' => 7]);
        $member = $this->actingAsMember($club);
        $fee = fn (string $due) => Payment::factory()->forClub($club)->forMember($member)->pending()
            ->create(['due_date' => $due, 'period_start' => $due, 'period_end' => Carbon::parse($due)->endOfMonth()->toDateString()]);
        $late = $fee('2026-09-01');     // past the grace period: "Lewat"
        $grace = $fee('2026-11-12');    // in the grace period until 19 Nov
        $future = $fee('2026-12-01');   // not yet due

        $this->get(route('member.dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('upcoming', fn ($rows) => collect($rows)->mapWithKeys(fn ($r) => [$r['id'] => [$r['status'], $r['pastDue'], $r['lateAfter']]])->all() === [
                    $late->id => ['overdue', true, null],
                    $grace->id => ['due', true, '2026-11-19'],
                    $future->id => ['due', false, null],
                ]));

        $this->get(route('member.payments.index', $club))
            ->assertInertia(fn (Assert $page) => $page
                ->where('payments', fn ($rows) => collect($rows)->firstWhere('id', $grace->id)['lateAfter'] === '2026-11-19'));
    }

    public function test_one_bm_word_for_overdue(): void
    {
        $i18n = file_get_contents(resource_path('js/lib/i18n.ts'));
        $this->assertStringNotContainsString('Tertunggak', $i18n);
        $this->assertStringContainsString('lateAfter: { bm: "Lewat selepas {d}"', $i18n);
    }
}
