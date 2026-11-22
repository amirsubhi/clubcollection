<?php

namespace Tests\Feature\Admin;

use App\Models\Club;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Payment;
use App\Support\Admin\FundFigures;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Settings phase 1: the club's financial_year_start_month drives "this year" (dashboard, Fund Usage, ledger
 * default and presets, the members' "where the money went" page). Month 1 keeps the calendar year exactly.
 */
class FinancialYearTest extends TestCase
{
    private Club $club;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(Carbon::create(2026, 11, 16, 10));
        $this->club = Club::factory()->create();
        $cat = ExpenseCategory::factory()->for($this->club)->create(['name' => 'Events']);

        // Before 1 July 2026 (outside FY 2026/27, inside calendar 2026) and after it.
        foreach (['2026-03-10' => 100.00, '2026-08-10' => 200.00] as $date => $amount) {
            Payment::factory()->forClub($this->club)->create(['status' => 'paid', 'amount' => $amount, 'paid_date' => $date, 'due_date' => $date, 'period_start' => $date]);
        }
        Expense::factory()->approved()->create(['club_id' => $this->club->id, 'expense_category_id' => $cat->id, 'amount' => 30.00, 'expense_date' => '2026-02-01']);
        Expense::factory()->approved()->create(['club_id' => $this->club->id, 'expense_category_id' => $cat->id, 'amount' => 50.00, 'expense_date' => '2026-09-01']);
    }

    private function startInJuly(): void
    {
        $this->club->forceFill(['financial_year_start_month' => 7])->save();
    }

    public function test_windows_for_a_calendar_year_are_unchanged(): void
    {
        $w = FundFigures::yearToDate(null, $this->club);

        $this->assertSame('2026-01-01', $w['from']->toDateString());
        $this->assertSame('2026-11-16', $w['to']->toDateString());
        $this->assertSame('year', $w['key']);
        $this->assertSame('This year so far', $w['label']);
        $this->assertSame('1 Jan – 16 Nov 2026', $w['range']);
        $this->assertSame('This year', $w['short']);
        $this->assertSame('2026', FundFigures::yearLabel(null, $this->club));
        // The same as before the setting existed.
        $this->assertEquals(FundFigures::yearToDate(), $w);
    }

    public function test_windows_for_a_financial_year_starting_in_july(): void
    {
        $this->startInJuly();
        $w = FundFigures::yearToDate(null, $this->club->fresh());

        $this->assertSame('2026-07-01', $w['from']->toDateString());
        $this->assertSame('2026-11-16', $w['to']->toDateString());
        $this->assertSame('year', $w['key']);
        $this->assertSame('This financial year so far (FY 2026/27)', $w['label']);
        $this->assertSame('FY 2026/27', $w['short']);

        // Before July, the financial year began in July of the year before.
        $march = FundFigures::yearToDate(Carbon::create(2026, 3, 31), 7);
        $this->assertSame('2025-07-01', $march['from']->toDateString());
        $this->assertSame('FY 2025/26', FundFigures::yearLabel(Carbon::create(2026, 3, 31), 7));
        $this->assertSame('FY 2099/00', FundFigures::yearLabel(Carbon::create(2099, 12, 1), 12));
    }

    public function test_the_start_is_built_from_day_one_so_month_ends_never_overflow(): void
    {
        $this->assertSame('2025-02-01', FundFigures::financialYearStart(Carbon::create(2026, 1, 31), 2)->toDateString());
        $this->assertSame('2026-02-01', FundFigures::financialYearStart(Carbon::create(2026, 3, 31), 2)->toDateString());
        $this->assertSame('2026-12-01', FundFigures::financialYearStart(Carbon::create(2026, 12, 31), 12)->toDateString());
        // Out-of-range months fall back to January.
        $this->assertSame('2026-01-01', FundFigures::financialYearStart(Carbon::create(2026, 5, 31), 13)->toDateString());
    }

    public function test_dashboard_and_fund_usage_count_from_the_financial_year_start(): void
    {
        $this->actingAsClubAdmin($this->club);

        // Calendar year: both fees and both expenses.
        $this->get(route('admin.clubs.dashboard', $this->club))
            ->assertInertia(fn (Assert $page) => $page
                ->where('kpis.collectedYtd', 30000)
                ->where('kpis.spentYtd', 8000)
                ->where('yearLabel', '2026')
                ->where('kpis.window.from', '2026-01-01'));

        $this->startInJuly();

        $this->get(route('admin.clubs.dashboard', $this->club))
            ->assertInertia(fn (Assert $page) => $page
                ->where('kpis.collectedYtd', 20000)
                ->where('kpis.spentYtd', 5000)
                ->where('yearLabel', 'FY 2026/27')
                ->where('kpis.window.from', '2026-07-01')
                ->where('kpis.window.short', 'FY 2026/27')
                // The balance is all-time, whatever the year.
                ->where('kpis.balance', 22000));

        $this->get(route('admin.clubs.fund-usage.index', $this->club))
            ->assertInertia(fn (Assert $page) => $page
                ->where('kpis.collectedYtd', 20000)
                ->where('kpis.spentYtd', 5000)
                ->where('kpis.yearLabel', 'FY 2026/27')
                ->where('kpis.window.range', '1 Jul – 16 Nov 2026'));
    }

    public function test_the_ledger_defaults_to_the_financial_year_and_offers_it_as_a_preset(): void
    {
        $this->actingAsClubAdmin($this->club);

        $this->get(route('admin.clubs.ledger', $this->club))
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.from', '2026-01-01')
                ->where('filters.preset', 'year')
                ->where('presets.0.short', 'This year'));

        $this->startInJuly();

        $this->get(route('admin.clubs.ledger', $this->club))
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.from', '2026-07-01')
                ->where('filters.to', '2026-11-16')
                ->where('filters.preset', 'year')
                ->where('presets.0.from', '2026-07-01')
                ->where('presets.0.short', 'FY 2026/27')
                ->where('summary.in', 20000)
                // Opening balance: everything before 1 July (RM 100 in, RM 30 out).
                ->where('opening.amount', 7000));
    }

    public function test_members_see_the_financial_year_on_where_the_money_went(): void
    {
        $this->startInJuly();
        $this->actingAsMember($this->club);

        $this->get(route('member.clubs.fund', $this->club))
            ->assertInertia(fn (Assert $page) => $page
                ->where('club.fyStartMonth', 7)
                ->where('fund.window.from', '2026-07-01')
                ->where('fund.collected', 20000)
                ->where('fund.spent', 5000));

        $this->get(route('member.dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('clubs.0.fyStartMonth', 7));
    }
}
