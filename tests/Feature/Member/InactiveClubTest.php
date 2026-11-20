<?php

namespace Tests\Feature\Member;

use App\Models\Club;
use App\Models\FeeRate;
use App\Models\Payment;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * G2: a deactivated club, or a deactivated membership, drops off the
 * member's pages: not on the home page, no payments list, no pay-ahead.
 */
class InactiveClubTest extends TestCase
{
    private Club $active;

    private Club $other;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->active = Club::factory()->create(['name' => 'Alpha Club']);
        $this->other = Club::factory()->create(['name' => 'Beta Club']);
        $this->member = $this->actingAsMember($this->active);
        $this->other->members()->attach($this->member, ['role' => 'member', 'job_level' => 'executive', 'joined_date' => now()->toDateString(), 'is_active' => true]);
        foreach ([$this->active, $this->other] as $club) {
            FeeRate::factory()->create(['club_id' => $club->id, 'job_level' => 'executive', 'monthly_amount' => 10.00, 'effective_to' => null]);
            Payment::factory()->forClub($club)->forMember($this->member)->create([
                'status' => 'pending', 'due_date' => now()->toDateString(),
                'period_start' => now()->startOfMonth()->toDateString(), 'period_end' => now()->endOfMonth()->toDateString(),
            ]);
        }
    }

    private function deactivate(string $how): void
    {
        $how === 'club'
            ? $this->other->update(['is_active' => false])
            : $this->other->members()->updateExistingPivot($this->member->id, ['is_active' => false]);
    }

    private function payAhead(Club $club)
    {
        return $this->post(route('member.payments.generate-future', $club), [
            'period_start' => now()->startOfMonth()->addMonthsNoOverflow(2)->toDateString(),
            'frequency' => 'monthly',
        ]);
    }

    public function test_both_clubs_show_while_active(): void
    {
        $this->get(route('member.dashboard'))->assertInertia(fn (Assert $page) => $page
            ->has('clubs', 2)
            ->where('outstanding.count', 2));
        $this->get(route('member.payments.index', $this->other))->assertOk();
        $this->get(route('member.clubs.fund', $this->other))->assertOk();
    }

    public function test_inactive_club_or_membership_is_hidden_and_refused(): void
    {
        foreach (['club', 'membership'] as $how) {
            $this->setUpFresh($how);

            $this->get(route('member.dashboard'))->assertInertia(fn (Assert $page) => $page
                ->has('clubs', 1)
                ->where('clubs.0.name', 'Alpha Club')
                ->where('outstanding.count', 1)
                ->where('upcoming.0.clubName', 'Alpha Club'));

            $this->get(route('member.payments.index', $this->other))->assertForbidden();
            $this->get(route('member.clubs.fund', $this->other))->assertForbidden();
            $this->get(route('member.clubs.fund', $this->active))->assertOk();
            $this->get(route('member.payments.index', $this->active))->assertOk();

            $before = Payment::count();
            $this->payAhead($this->other)->assertForbidden();
            $this->assertSame($before, Payment::count(), "pay-ahead refused for an inactive {$how}");
            $this->payAhead($this->active)->assertRedirect();
            $this->assertSame($before + 1, Payment::count());
        }
    }

    private function setUpFresh(string $how): void
    {
        $this->other->update(['is_active' => true]);
        $this->other->members()->updateExistingPivot($this->member->id, ['is_active' => true]);
        Payment::where('user_id', $this->member->id)->whereDate('period_start', '>', now()->endOfMonth())->delete();
        $this->deactivate($how);
    }

    public function test_admin_home_lists_only_active_memberships(): void
    {
        $admin = $this->actingAsClubAdmin($this->active);
        $this->other->members()->attach($admin, ['role' => 'admin', 'job_level' => 'manager', 'joined_date' => now()->toDateString(), 'is_active' => false]);

        $this->get(route('home'))->assertOk()->assertViewHas('clubs', fn ($clubs) => $clubs->pluck('name')->all() === ['Alpha Club']);
    }
}
