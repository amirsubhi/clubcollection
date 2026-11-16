<?php

namespace Tests\Feature\Admin;

use App\Models\Club;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Payment;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Balances count approved Fund Usage records only: drafts, records awaiting
 * approval and void records are excluded from the ledger, dashboards and
 * statistics. Also checks the last day of a range is included (date bounds).
 */
class CountedTotalsTest extends TestCase
{
    private Club $club;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->club = Club::factory()->create();
        $cat = ExpenseCategory::factory()->for($this->club)->create(['name' => 'Supplies']);
        $today = now()->toDateString();

        Payment::factory()->forClub($this->club)->create(['status' => 'paid', 'amount' => 1000.00, 'paid_date' => $today]);

        Expense::factory()->approved()->create(['club_id' => $this->club->id, 'expense_category_id' => $cat->id, 'amount' => 100.00, 'expense_date' => $today, 'description' => 'COUNTED-APPROVED']);
        Expense::factory()->create(['club_id' => $this->club->id, 'expense_category_id' => $cat->id, 'amount' => 11.00, 'expense_date' => $today, 'description' => 'HIDDEN-DRAFT']);
        Expense::factory()->submitted()->create(['club_id' => $this->club->id, 'expense_category_id' => $cat->id, 'amount' => 22.00, 'expense_date' => $today, 'description' => 'HIDDEN-SUBMITTED']);
        Expense::factory()->voided()->create(['club_id' => $this->club->id, 'expense_category_id' => $cat->id, 'amount' => 33.00, 'expense_date' => $today, 'description' => 'HIDDEN-VOID']);
    }

    public function test_counted_scope_is_approved_only(): void
    {
        $this->assertSame(['COUNTED-APPROVED'], Expense::counted()->pluck('description')->all());
    }

    public function test_ledger_totals_exclude_unapproved(): void
    {
        $this->actingAsClubAdmin($this->club);
        $res = $this->get(route('admin.clubs.ledger', $this->club).'?from='.now()->startOfYear()->toDateString().'&to='.now()->toDateString());

        $res->assertOk()
            ->assertSee('COUNTED-APPROVED')
            ->assertDontSee('HIDDEN-DRAFT')
            ->assertDontSee('HIDDEN-SUBMITTED')
            ->assertDontSee('HIDDEN-VOID');
        // Opening is computed (0 before this year's records), all money in sen.
        $res->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Ledger/Index')
            ->where('summary.out', 10000)
            ->where('summary.closing', 90000)
            ->has('transactions', 2));
    }

    public function test_ledger_includes_the_last_day_of_the_range(): void
    {
        $this->actingAsClubAdmin($this->club);
        $res = $this->get(route('admin.clubs.ledger', $this->club).'?from='.now()->toDateString().'&to='.now()->toDateString());

        $res->assertInertia(fn (Assert $page) => $page
            ->where('summary.in', 100000)
            ->where('summary.out', 10000));
    }

    public function test_csv_export_excludes_unapproved(): void
    {
        $this->actingAsClubAdmin($this->club);
        $csv = $this->get(route('admin.clubs.ledger.export', $this->club))->streamedContent();

        $this->assertStringContainsString('COUNTED-APPROVED', $csv);
        $this->assertStringNotContainsString('HIDDEN-', $csv);
    }

    public function test_inertia_dashboard_totals_exclude_unapproved(): void
    {
        $this->actingAsClubAdmin($this->club);

        $this->get(route('admin.clubs.dashboard', $this->club))
            ->assertInertia(fn (Assert $page) => $page
                ->where('kpis.spentYtd', 10000)
                ->where('kpis.balance', 90000)
                ->where('monthly.11.spent', 10000)
                ->where('categories.0.amount', 10000));
    }

    public function test_classic_dashboard_totals_exclude_unapproved(): void
    {
        $this->actingAsClubAdmin($this->club);
        $res = $this->get(route('admin.clubs.dashboard.classic', $this->club));

        $res->assertOk();
        $this->assertEquals(100.0, $res->viewData('expenseThisMonth'));
        $this->assertEquals(100.0, $res->viewData('expenseThisYear'));
        $this->assertEquals(100.0, $res->viewData('expenseAllTime'));
        $this->assertEquals(900.0, $res->viewData('balance'));
        $this->assertEquals(1000.0, $res->viewData('incomeThisMonth'));
        $this->assertEquals(100.0, array_sum($res->viewData('monthlyExpense')));
        $this->assertEquals(100.0, (float) $res->viewData('expenseByCategory')->first()->total);
    }

    public function test_statistics_totals_exclude_unapproved(): void
    {
        $this->actingAsSuperAdmin();
        $res = $this->get(route('admin.statistics'));

        $res->assertOk();
        // FundFigures (sen): spent = approved expenses + welfare + bank charges.
        $this->assertSame(10000, $res->viewData('totals')['spent']);
        $this->assertSame(90000, $res->viewData('totals')['balance']);
        $this->assertSame(10000, array_sum(array_column($res->viewData('monthly'), 'spent')));
        $this->assertSame(10000, $res->viewData('clubStats')->firstWhere('id', $this->club->id)->spent_sen);
    }

    public function test_pdf_ledger_export_excludes_unapproved(): void
    {
        $this->actingAsClubAdmin($this->club);
        $data = null;
        \Illuminate\Support\Facades\View::composer('admin.ledger.pdf', function ($view) use (&$data) {
            $data = $view->getData();
        });

        $this->get(route('admin.clubs.ledger.export-pdf', $this->club))->assertOk();

        $this->assertEquals(100.0, $data['totalExpenses']);
        $descriptions = collect($data['transactions'])->pluck('description')->implode('|');
        $this->assertStringContainsString('COUNTED-APPROVED', $descriptions);
        $this->assertStringNotContainsString('HIDDEN-', $descriptions);
    }

    public function test_member_card_excludes_unapproved(): void
    {
        $this->actingAsMember($this->club);

        $this->get(route('member.dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('clubs.0.fund.spent', 10000));
    }

    /** Spending is placed by cash date: paid_date, else the invoice date. */
    public function test_totals_use_paid_date_when_present(): void
    {
        $cat = ExpenseCategory::factory()->for($this->club)->create();
        // Invoiced last year, paid today: counts this year.
        Expense::factory()->approved()->create(['club_id' => $this->club->id, 'expense_category_id' => $cat->id, 'amount' => 7.00,
            'expense_date' => now()->subYear()->toDateString(), 'paid_date' => now()->toDateString(), 'description' => 'PAID-THIS-YEAR']);
        // Invoiced today, paid last year (prepaid): counts last year.
        Expense::factory()->approved()->create(['club_id' => $this->club->id, 'expense_category_id' => $cat->id, 'amount' => 5.00,
            'expense_date' => now()->toDateString(), 'paid_date' => now()->subYear()->toDateString()]);
        $this->actingAsClubAdmin($this->club);

        $this->get(route('admin.clubs.dashboard', $this->club))
            ->assertInertia(fn (Assert $page) => $page->where('kpis.spentYtd', 10700)->where('monthly.11.spent', 10700));

        $res = $this->get(route('admin.clubs.ledger', $this->club).'?from='.now()->startOfYear()->toDateString().'&to='.now()->toDateString());
        $res->assertSee('PAID-THIS-YEAR')
            ->assertInertia(fn (Assert $page) => $page->where('summary.out', 10700));

        $classic = $this->get(route('admin.clubs.dashboard.classic', $this->club));
        $this->assertEquals(107.0, $classic->viewData('expenseThisYear'));

        $this->actingAsSuperAdmin();
        $this->assertSame(10700, array_sum(array_column($this->get(route('admin.statistics'))->viewData('monthly'), 'spent')));
    }
}
