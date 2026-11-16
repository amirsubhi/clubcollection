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
 * FundFigures is the one definition of the fund figures. The dashboard,
 * Fund Usage KPIs, the member "where your money goes" card and the ledger
 * must all show the same numbers on the same records.
 */
class FundFiguresTest extends TestCase
{
    private Club $club;

    private ExpenseCategory $cat;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(Carbon::create(2026, 11, 16, 10));
        $this->club = Club::factory()->create();
        $this->cat = ExpenseCategory::factory()->for($this->club)->create(['name' => 'Events']);

        // Last year: RM500 in, RM100 out.
        $this->paid(500.00, '2025-12-20');
        $this->spend(100.00, '2025-12-21');
        // This year so far: RM300 in; RM80 expense + RM50 welfare out.
        $this->paid(300.00, '2026-03-02');
        $this->spend(80.00, '2026-04-10');
        $this->spend(50.00, '2026-05-05', 'welfare');
        // Never counted: awaiting approval, void, a pending fee.
        Expense::factory()->submitted()->create(['club_id' => $this->club->id, 'expense_category_id' => $this->cat->id, 'amount' => 999.00, 'expense_date' => '2026-06-01']);
        Expense::factory()->voided()->create(['club_id' => $this->club->id, 'expense_category_id' => $this->cat->id, 'amount' => 777.00, 'expense_date' => '2026-06-01']);
        Payment::factory()->forClub($this->club)->pending()->create(['amount' => 444.00]);
        // Dated after today: not in today's balance or this year so far.
        $this->spend(40.00, '2026-12-01');
    }

    private function paid(float $amount, string $date, array $extra = []): void
    {
        Payment::factory()->forClub($this->club)->create(['status' => 'paid', 'amount' => $amount, 'paid_date' => $date, 'due_date' => $date, 'period_start' => $date] + $extra);
    }

    /** A fee paid online whose gateway charge the club absorbed (as the verified webhook records it). */
    private function paidOnline(string $date, int $absorbedSen): void
    {
        $this->paid(50.00, $date, ['paid_via' => 'toyyibpay', 'gateway' => 'toyyibpay', 'fee_absorbed_sen' => $absorbedSen]);
    }

    private function spend(float $amount, string $date, string $type = 'expense'): void
    {
        Expense::factory()->approved()->create(['club_id' => $this->club->id, 'expense_category_id' => $this->cat->id, 'amount' => $amount, 'expense_date' => $date, 'type' => $type]);
    }

    public function test_definitions(): void
    {
        $f = new FundFigures($this->club);
        $ytd = FundFigures::yearToDate();

        $this->assertSame('2026-01-01', $ytd['from']->toDateString());
        $this->assertSame('2026-11-16', $ytd['to']->toDateString());
        $this->assertSame('1 Jan – 16 Nov 2026', $ytd['range']);
        $this->assertSame('1 Dec 2025 – 16 Nov 2026', FundFigures::lastMonths(12)['range']);

        $w = $f->forWindow($ytd);
        $this->assertSame(30000, $w['collected']);
        $this->assertSame(13000, $w['spent'], 'spent = expenses + welfare, approved only');
        $this->assertSame(8000, $w['expenses']);
        $this->assertSame(5000, $w['welfare']);
        $this->assertSame(0, $w['charges']);
        $this->assertSame(1, $w['welfareCount']);
        $this->assertSame('This year so far', $w['window']['label']);

        // 500 + 300 − 100 − 80 − 50; the December record is not counted yet.
        $this->assertSame(57000, $f->balance());
        $this->assertSame(53000, $f->balanceAt(Carbon::create(2026, 12, 31)));
        $this->assertSame(40000, $f->openingBalance(Carbon::create(2026, 1, 1)));

        // The last month of the chart runs to today, like the window.
        $monthly = $f->monthly(12);
        $this->assertSame(2026, $monthly[11]['year']);
        $this->assertSame(50000 + 30000, array_sum(array_column($monthly, 'collected')));
        $this->assertSame(10000 + 13000, array_sum(array_column($monthly, 'spent')));
    }

    public function test_ledger_opening_balance_is_computed_at_the_from_date(): void
    {
        $this->actingAsClubAdmin($this->club);

        $this->get(route('admin.clubs.ledger', $this->club))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Ledger/Index')
                ->where('filters.from', '2026-01-01')
                ->where('filters.to', '2026-11-16')
                ->where('filters.preset', 'year')
                ->where('filters.openingBalance', '')
                ->where('opening.source', 'computed')
                ->where('opening.amount', 40000)
                ->where('opening.asOf', '2025-12-31')
                ->where('summary.in', 30000)
                ->where('summary.out', 13000)
                ->where('summary.outExpenses', 8000)
                ->where('summary.outWelfare', 5000)
                ->where('summary.closing', 57000)
                ->has('presets', 2)
                ->where('presets.1.key', '12m')
                ->has('monthly', 11)
                ->has('transactions', 3)
                ->where('transactions.2.kind', 'welfare')
                ->where('transactions.2.direction', 'out')
                ->where('transactions.2.balance', 57000)
                ->where('outstanding.total', 44400)
                ->where('nav.groups.0.items', fn ($items) => collect($items)->firstWhere('key', 'ledger')['external'] === false
                    && collect($items)->firstWhere('key', 'billing')['external'] === false)
            );

        // The 12-month preset starts on 1 Dec 2025: opening is the balance at 30 Nov 2025 (0).
        $this->get(route('admin.clubs.ledger', $this->club).'?from=2025-12-01&to=2026-11-16')
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.preset', '12m')
                ->where('opening.amount', 0)
                ->where('summary.closing', 57000));

        // A custom From mid-year carries forward everything before it.
        $this->get(route('admin.clubs.ledger', $this->club).'?from=2026-04-01&to=2026-11-16')
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.preset', 'custom')
                ->where('opening.amount', 70000)
                ->where('summary.closing', 57000));
    }

    public function test_manual_opening_balance_overrides_and_is_flagged_in_page_and_exports(): void
    {
        $this->actingAsClubAdmin($this->club);
        $q = '?from=2026-01-01&to=2026-11-16&opening_balance=1000';

        $this->get(route('admin.clubs.ledger', $this->club).$q)
            ->assertInertia(fn (Assert $page) => $page
                ->where('opening.source', 'manual')
                ->where('opening.amount', 100000)
                ->where('opening.computed', 40000)
                ->where('filters.openingBalance', '1000.00')
                ->where('summary.closing', 100000 + 30000 - 13000)
                ->where('urls.csv', fn ($u) => str_contains($u, 'opening_balance=1000.00')));

        $csv = $this->get(route('admin.clubs.ledger.export', $this->club).$q)->streamedContent();
        $this->assertStringContainsString('Opening Balance (RM),1,000.00', str_replace('"', '', $csv));
        $this->assertStringContainsString('diubah secara manual', $csv);

        $computed = $this->get(route('admin.clubs.ledger.export', $this->club).'?from=2026-01-01&to=2026-11-16')->streamedContent();
        $this->assertStringContainsString('Opening Balance (RM),400.00', str_replace('"', '', $computed));
        $this->assertStringContainsString('Closing Balance (RM),570.00', str_replace('"', '', $computed));
    }

    /** Dashboard, Fund Usage, member card and ledger agree on the same records. */
    public function test_every_screen_shows_the_same_figures(): void
    {
        $this->actingAsClubAdmin($this->club);

        $dash = $this->get(route('admin.clubs.dashboard', $this->club))->assertOk();
        $dash->assertInertia(fn (Assert $page) => $page
            ->where('kpis.balance', 57000)
            ->where('kpis.collectedYtd', 30000)
            ->where('kpis.spentYtd', 13000)
            ->where('kpis.expensesYtd', 8000)
            ->where('kpis.welfareYtd', 5000)
            ->where('kpis.window.range', '1 Jan – 16 Nov 2026')
            ->where('kpis.balanceAsOf', '2026-11-16')
            ->where('monthlyWindow.label', 'Last 12 months')
            ->where('monthlyWindow.range', '1 Dec 2025 – 16 Nov 2026'));

        $this->get(route('admin.clubs.fund-usage.index', $this->club))
            ->assertInertia(fn (Assert $page) => $page
                ->where('kpis.balance', 57000)
                ->where('kpis.collectedYtd', 30000)
                ->where('kpis.spentYtd', 13000)
                ->where('kpis.expensesYtd', 8000)
                ->where('kpis.welfareYtd', 5000)
                ->where('kpis.window.range', '1 Jan – 16 Nov 2026'));

        $this->get(route('admin.clubs.ledger', $this->club))
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.closing', 57000)
                ->where('summary.in', 30000)
                ->where('summary.out', 13000));

        $this->actingAsMember($this->club);
        $this->get(route('member.dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('clubs.0.fund.collected', 30000)
                ->where('clubs.0.fund.spent', 13000)
                ->where('clubs.0.fund.expenses', 8000)
                ->where('clubs.0.fund.welfare', 5000)
                ->where('clubs.0.fund.charges', 0)
                ->missing('clubs.0.fund.welfareCount')
                ->where('clubs.0.fund.fromReserves', 0)
                ->where('clubs.0.fund.window.from', '2026-01-01')
                ->where('clubs.0.fund.window.to', '2026-11-16'));
    }

    public function test_a_manual_opening_balance_is_dropped_when_from_moves(): void
    {
        $this->actingAsClubAdmin($this->club);
        $url = route('admin.clubs.ledger', $this->club);

        // Typed for 1 Jan and still on 1 Jan: used.
        $this->get($url.'?from=2026-01-01&to=2026-11-16&opening_balance=1000&opening_for=2026-01-01')
            ->assertInertia(fn (Assert $page) => $page->where('opening.source', 'manual')->where('opening.amount', 100000)
                ->where('urls.pdf', fn ($u) => str_contains($u, 'opening_for=2026-01-01')));
        // From moved to 1 Apr: the 1 Jan figure no longer applies, the records are used.
        $this->get($url.'?from=2026-04-01&to=2026-11-16&opening_balance=1000&opening_for=2026-01-01')
            ->assertInertia(fn (Assert $page) => $page->where('opening.source', 'computed')->where('opening.amount', 70000)
                ->where('filters.openingBalance', '')
                ->where('urls.csv', fn ($u) => ! str_contains($u, 'opening_balance')));
        // Exports follow the same rule.
        $csv = $this->get(route('admin.clubs.ledger.export', $this->club).'?from=2026-04-01&to=2026-11-16&opening_balance=1000&opening_for=2026-01-01')->streamedContent();
        $this->assertStringContainsString('Opening Balance (RM),700.00', str_replace('"', '', $csv));
        $this->assertStringNotContainsString('diubah secara manual', $csv);
    }

    /** Absorbed gateway fees are money out: a "bank / gateway charges" line in spent and the balance. */
    public function test_absorbed_gateway_fees_are_bank_charges_everywhere(): void
    {
        $this->paidOnline('2025-12-10', 100);  // last year
        $this->paidOnline('2026-03-10', 100);
        $this->paidOnline('2026-03-20', 150);
        $this->paidOnline('2026-04-02', 100);
        $this->paidOnline('2026-04-05', 0);    // member paid the fee: no charge
        $this->paid(50.00, '2026-04-06', ['fee_absorbed_sen' => null]); // settled by hand: no gateway charge

        $f = new FundFigures($this->club);
        $w = $f->forWindow(FundFigures::yearToDate());
        $this->assertSame(30000 + 5 * 5000, $w['collected']);
        $this->assertSame(350, $w['charges']);
        $this->assertSame(8000 + 5000 + 350, $w['spent']);
        // (500 + 50 + 550) collected − (100 + 130) approved − (1.00 + 3.50) charges
        $balance = 110000 - 23000 - 450;
        $this->assertSame($balance, $f->balance());
        $this->assertSame(55000 - 10000 - 100, $f->openingBalance(Carbon::create(2026, 1, 1)));
        $this->assertSame(250, $f->monthly(12)[3]['spent'], 'March: the two absorbed charges');
        $this->assertContains(['name' => FundFigures::CHARGES_LABEL, 'nameMs' => FundFigures::CHARGES_LABEL_MS, 'amount' => 350], $f->byCategoryBetween(Carbon::create(2026, 1, 1), Carbon::create(2026, 11, 16)));

        $this->actingAsClubAdmin($this->club);
        $this->get(route('admin.clubs.dashboard', $this->club))
            ->assertInertia(fn (Assert $page) => $page
                ->where('kpis.balance', $balance)
                ->where('kpis.spentYtd', 13350)
                ->where('kpis.chargesYtd', 350)
                ->where('kpis.gatewayFees.absorbedYtd', 350));
        $this->get(route('admin.clubs.fund-usage.index', $this->club))
            ->assertInertia(fn (Assert $page) => $page->where('kpis.balance', $balance)->where('kpis.spentYtd', 13350)->where('kpis.chargesYtd', 350));

        // Ledger: one "out" row per month, the same closing balance.
        $this->get(route('admin.clubs.ledger', $this->club))
            ->assertInertia(fn (Assert $page) => $page
                ->where('opening.amount', 44900)
                ->where('summary.out', 13350)
                ->where('summary.outCharges', 350)
                ->where('summary.outExpenses', 8000)
                ->where('summary.outCount', 2)
                ->where('summary.closing', $balance)
                ->where('transactions', fn ($t) => collect($t)->where('kind', 'charges')->map(fn ($r) => [$r['date'], $r['amount'], $r['direction']])->values()->all()
                    === [['2026-03-20', 250, 'out'], ['2026-04-02', 100, 'out']])
                ->where('monthly.2.out', 250));

        // CSV and PDF match the page.
        $csv = str_replace('"', '', $this->get(route('admin.clubs.ledger.export', $this->club))->streamedContent());
        $this->assertStringContainsString('Bank / gateway charges (Caj bank / gateway) — Mar 2026', $csv);
        $this->assertStringContainsString('of which Bank / gateway charges (RM),3.50', $csv);
        $this->assertStringContainsString('Closing Balance (RM),'.number_format($balance / 100, 2), $csv);
        $pdf = null;
        \Illuminate\Support\Facades\View::composer('admin.ledger.pdf', function ($view) use (&$pdf) {
            $pdf = $view->getData();
        });
        $this->get(route('admin.clubs.ledger.export-pdf', $this->club))->assertOk();
        $this->assertEquals($balance / 100, $pdf['closingBalance']);
        $this->assertSame(2, collect($pdf['transactions'])->where('kind', 'charges')->count());

        $this->actingAsMember($this->club);
        $this->get(route('member.dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('clubs.0.fund.spent', 13350)
                ->where('clubs.0.fund.charges', 350)
                ->where('clubs.0.fund.categories', fn ($c) => collect($c)->contains(fn ($r) => $r['name'] === FundFigures::CHARGES_LABEL && $r['amount'] === 350)));
    }

    public function test_member_card_notes_spending_from_reserves(): void
    {
        $this->spend(400.00, '2026-07-01');
        $this->actingAsMember($this->club);

        $this->get(route('member.dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('clubs.0.fund.spent', 53000)
                ->where('clubs.0.fund.collected', 30000)
                ->where('clubs.0.fund.fromReserves', 23000));
    }

    public function test_dashboard_kpis_carry_their_window(): void
    {
        $this->actingAsClubAdmin($this->club);

        $this->get(route('admin.clubs.dashboard', $this->club))
            ->assertInertia(fn (Assert $page) => $page
                ->has('kpis.window', fn (Assert $w) => $w->hasAll(['key', 'label', 'range', 'from', 'to']))
                // The submitted record was made by someone else, so it waits for this admin.
                ->where('kpis.awaiting.count', 1)
                ->where('kpis.awaiting.mine', 1)
                ->has('monthly', 12));
    }
}
