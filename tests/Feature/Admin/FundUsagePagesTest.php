<?php

namespace Tests\Feature\Admin;

use App\Models\Club;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Payment;
use App\Models\User;
use App\Models\WelfareCase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Inertia components and props for the Fund Usage pages and the admin
 * dashboard, plus cross-club / member access on every new route.
 */
class FundUsagePagesTest extends TestCase
{
    private Club $club;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->club = Club::factory()->create(['name' => 'Kelab Sukan']);
        $this->admin = $this->actingAsClubAdmin($this->club);
    }

    public function test_index_renders_kpis_tabs_and_rows(): void
    {
        $cat = ExpenseCategory::factory()->for($this->club)->create(['name' => 'Meetings']);
        Payment::factory()->forClub($this->club)->create(['status' => 'paid', 'amount' => 500.00, 'paid_date' => now()->toDateString()]);
        Expense::factory()->approved()->create(['club_id' => $this->club->id, 'expense_category_id' => $cat->id, 'amount' => 100.00, 'expense_date' => now()->toDateString()]);
        Expense::factory()->submitted()->create(['club_id' => $this->club->id, 'expense_category_id' => $cat->id, 'amount' => 40.00, 'expense_date' => now()->toDateString(), 'payee' => 'Kedai Runcit']);
        Expense::factory()->voided()->create(['club_id' => $this->club->id, 'expense_category_id' => $cat->id, 'amount' => 999.00]);
        Expense::factory()->create(['club_id' => Club::factory()->create()->id, 'payee' => 'OTHER-CLUB-PAYEE']);

        $this->get(route('admin.clubs.fund-usage.index', $this->club))
            ->assertOk()
            ->assertDontSee('OTHER-CLUB-PAYEE')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/FundUsage/Index')
                ->where('kpis.collectedYtd', 50000)
                ->where('kpis.spentYtd', 10000)
                ->where('kpis.balance', 40000)
                ->where('kpis.awaiting', 1)
                ->where('kpis.awaitingMine', 1)
                ->where('tabCounts.all', 2)
                ->where('tabCounts.void', 1)
                ->where('tabCounts.mine', 1)
                ->has('rows', 2)
                ->where('pagination.total', 2)
                ->has('rows.0', fn (Assert $row) => $row->hasAll(['id', 'ref', 'type', 'status', 'amount', 'url', 'attachmentCount'])->etc())
                ->where('nav.groups.0.title', 'Kelab Sukan')
                ->where('nav.groups.0.items.1.key', 'fund-usage')
                ->where('nav.groups.0.items.1.badge', 1)
            );

        $this->get(route('admin.clubs.fund-usage.index', [$this->club, 'tab' => 'void']))
            ->assertInertia(fn (Assert $page) => $page->has('rows', 1)->where('rows.0.status', 'void'));
        $this->get(route('admin.clubs.fund-usage.index', [$this->club, 'q' => 'Runcit']))
            ->assertInertia(fn (Assert $page) => $page->has('rows', 1)->where('rows.0.payee', 'Kedai Runcit'));
        $this->get(route('admin.clubs.fund-usage.index', [$this->club, 'status' => ['approved']]))
            ->assertInertia(fn (Assert $page) => $page->has('rows', 1)->where('rows.0.status', 'approved'));
    }

    public function test_create_renders_for_expense_and_welfare(): void
    {
        ExpenseCategory::factory()->for($this->club)->create();

        $this->get(route('admin.clubs.fund-usage.create', $this->club))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/FundUsage/Create')
                ->where('type', 'expense')
                ->where('item', null)
                ->has('categories', 1)
                ->where('urls.action', route('admin.clubs.fund-usage.store', $this->club))
                ->where('limits.maxFiles', 10)
            );

        $this->get(route('admin.clubs.fund-usage.create', [$this->club, 'type' => 'welfare']))
            ->assertInertia(fn (Assert $page) => $page->where('type', 'welfare')->has('relationships')->has('members'));
    }

    public function test_edit_renders_the_form_for_the_recorder(): void
    {
        $e = Expense::factory()->forClub($this->club)->create(['recorded_by' => $this->admin->id]);

        $this->get(route('admin.fund-usage.edit', $e))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/FundUsage/Create')
                ->where('item.id', $e->id)
                ->where('urls.action', route('admin.fund-usage.update', $e))
            );
    }

    public function test_show_renders_item_events_and_permissions(): void
    {
        $maker = User::factory()->create(['name' => 'Maker Mei']);
        $e = Expense::factory()->submitted()->forClub($this->club)->create(['recorded_by' => $maker->id, 'amount' => 186.50]);
        \App\Services\AuditService::log('fund_usage.submitted', 'x', $e, $this->club->id, ['status' => 'draft'], ['status' => 'submitted']);

        $this->get(route('admin.fund-usage.show', $e))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/FundUsage/Show')
                ->where('item.id', $e->id)
                ->where('item.amount', 18650)
                ->where('item.recordedBy', 'Maker Mei')
                ->where('item.welfare', null)
                ->where('can.approve', true)
                ->where('can.return', true)
                ->where('can.edit', false)
                ->where('needsSecondApprover', false)
                ->has('events', 1)
                ->where('events.0.action', 'submitted')
                ->where('urls.approve', route('admin.fund-usage.approve', $e))
            );
    }

    public function test_welfare_details_are_only_in_the_detail_page(): void
    {
        $beneficiary = User::factory()->create(['name' => 'Beneficiary Bala']);
        $e = Expense::factory()->submitted()->forClub($this->club)->create(['type' => 'welfare', 'payee' => null]);
        WelfareCase::create(['expense_id' => $e->id, 'beneficiary_user_id' => $beneficiary->id, 'subject_name' => 'SECRET-SUBJECT', 'relationship' => 'Father', 'recipient_name' => 'SECRET-RECIPIENT']);

        $this->get(route('admin.clubs.fund-usage.index', $this->club))->assertOk()->assertDontSee('SECRET-RECIPIENT')->assertDontSee('SECRET-SUBJECT');
        $this->get(route('admin.clubs.dashboard', $this->club))->assertOk()->assertDontSee('SECRET-RECIPIENT');
        $this->get(route('admin.fund-usage.show', $e))
            ->assertInertia(fn (Assert $page) => $page->where('item.welfare.recipientName', 'SECRET-RECIPIENT')->where('item.payee', null));

        // Members never see welfare personal data (or any fund record).
        $this->actingAsMember($this->club);
        $this->get(route('member.dashboard'))->assertOk()->assertDontSee('SECRET-RECIPIENT')->assertDontSee('SECRET-SUBJECT');
    }

    public function test_dashboard_renders_real_kpis_chart_and_queue(): void
    {
        $cat = ExpenseCategory::factory()->for($this->club)->create(['name' => 'Annual dinner']);
        Payment::factory()->forClub($this->club)->create(['status' => 'paid', 'amount' => 300.00, 'paid_date' => now()->toDateString()]);
        Expense::factory()->approved()->create(['club_id' => $this->club->id, 'expense_category_id' => $cat->id, 'amount' => 120.00, 'expense_date' => now()->toDateString()]);
        Expense::factory()->submitted()->create(['club_id' => $this->club->id, 'expense_category_id' => $cat->id, 'amount' => 50.00, 'expense_date' => now()->toDateString()]);

        $this->get(route('admin.clubs.dashboard', $this->club))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Dashboard')
                ->where('kpis.balance', 18000)
                ->where('kpis.collectedYtd', 30000)
                ->where('kpis.spentYtd', 12000)
                ->where('kpis.awaiting.count', 1)
                ->where('kpis.awaiting.sum', 5000)
                ->has('monthly', 12)
                ->where('monthly.11.collected', 30000)
                ->where('monthly.11.spent', 12000)
                ->where('categories.0.name', 'Annual dinner')
                ->where('categories.0.amount', 12000)
                ->has('queue', 1)
                ->where('queue.0.canApprove', true)
                ->has('activity')
                ->where('urls.classic', route('admin.clubs.dashboard.classic', $this->club))
            );

        $this->get(route('admin.clubs.dashboard.classic', $this->club))->assertOk()->assertViewIs('admin.dashboard.index');
    }

    /** Every new route refuses an admin of another club (403). */
    public function test_cross_club_admin_gets_403_on_every_route(): void
    {
        $e = Expense::factory()->submitted()->forClub($this->club)->create(['recorded_by' => $this->admin->id]);
        $this->actingAsClubAdmin(Club::factory()->create());

        foreach ($this->routes($e) as [$method, $url, $data]) {
            $this->call($method, $url, $data)->assertForbidden();
        }
        $this->assertSame('submitted', $e->fresh()->status);
    }

    /** Members are refused on every admin route, even in their own club. */
    public function test_member_gets_403_on_every_route(): void
    {
        $e = Expense::factory()->submitted()->forClub($this->club)->create(['recorded_by' => $this->admin->id]);
        $this->actingAsMember($this->club);

        foreach ($this->routes($e) as [$method, $url, $data]) {
            $this->call($method, $url, $data)->assertForbidden();
        }
    }

    /** @return array<int, array{0: string, 1: string, 2: array}> */
    private function routes(Expense $e): array
    {
        $cat = $e->expense_category_id;
        $body = ['intent' => 'draft', 'type' => 'expense', 'expense_category_id' => $cat, 'description' => 'x', 'payee' => 'y', 'amount' => '1.00', 'expense_date' => now()->toDateString()];

        return [
            ['GET', route('admin.clubs.dashboard', $this->club), []],
            ['GET', route('admin.clubs.dashboard.classic', $this->club), []],
            ['GET', route('admin.clubs.fund-usage.index', $this->club), []],
            ['GET', route('admin.clubs.fund-usage.create', $this->club), []],
            ['POST', route('admin.clubs.fund-usage.store', $this->club), $body],
            ['GET', route('admin.fund-usage.show', $e), []],
            ['GET', route('admin.fund-usage.edit', $e), []],
            ['PUT', route('admin.fund-usage.update', $e), $body],
            ['POST', route('admin.fund-usage.submit', $e), []],
            ['POST', route('admin.fund-usage.approve', $e), ['declaration' => true]],
            ['POST', route('admin.fund-usage.return', $e), ['reason' => 'Not acceptable']],
            ['POST', route('admin.fund-usage.void-request', $e), ['reason' => 'Not acceptable']],
            ['POST', route('admin.fund-usage.void-confirm', $e), []],
            ['DELETE', route('admin.fund-usage.destroy', $e), []],
        ];
    }
}
