<?php

namespace Tests\Feature\Member;

use App\Mail\OverdueReminder;
use App\Models\Club;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\FeeRate;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Tests\TestCase;

class MemberPayFlowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function toyyibClub(): Club
    {
        config([
            'toyyibpay.base_url' => 'https://dev.toyyibpay.com',
            'toyyibpay.webhook_secret' => self::TOYYIB_SECRET,
        ]);

        return Club::factory()->create([
            'payment_gateway' => 'toyyibpay',
            'toyyibpay_secret_key' => 'club-secret',
            'toyyibpay_category_code' => 'cat-123',
        ]);
    }

    private function pending(User $member, Club $club, array $attrs = []): Payment
    {
        return Payment::factory()->forClub($club)->forMember($member)->pending()->create($attrs);
    }

    // ── Routes & authorization ───────────────────────────────────────────

    public function test_get_on_pay_route_is_not_allowed(): void
    {
        $club = Club::factory()->create();
        $payment = $this->pending($this->actingAsMember($club), $club);

        $this->assertSame(['POST'], app('router')->getRoutes()->getByName('member.payments.pay')->methods());

        // Rendering the 405 page goes through symfony/var-dumper, which breaks on
        // PHP < 8.4 (same cause as the known LedgerTest failure), so assert on the
        // exception itself: a GET reaches no controller and opens no bill.
        $this->withoutExceptionHandling();
        try {
            $this->get(route('member.payments.pay', $payment));
            $this->fail('GET on the pay route should not be allowed.');
        } catch (MethodNotAllowedHttpException $e) {
            $this->assertSame(405, $e->getStatusCode());
        }
        $this->assertNull($payment->fresh()->bill_code);
    }

    public function test_member_cannot_access_another_members_payment(): void
    {
        $club = Club::factory()->create();
        $owner = User::factory()->create(['role' => 'member']);
        $club->members()->attach($owner, ['role' => 'member', 'job_level' => 'executive', 'joined_date' => now()->toDateString(), 'is_active' => true]);
        $payment = $this->pending($owner, $club);

        // A different member of the same club.
        $this->actingAsMember($club);

        $this->get(route('member.payments.invoice', $payment))->assertForbidden();
        $this->get(route('member.payments.review', $payment))->assertForbidden();
        $this->get(route('member.payments.status', $payment))->assertForbidden();
        $this->get(route('member.payments.thankyou', $payment))->assertForbidden();
        $this->post(route('member.payments.pay', $payment))->assertForbidden();
        $this->assertNull($payment->fresh()->bill_code);
    }

    public function test_former_or_inactive_members_and_inactive_clubs_are_refused(): void
    {
        Http::fake();
        $club = $this->toyyibClub();
        $member = $this->actingAsMember($club);
        $payment = $this->pending($member, $club);

        // Membership switched off.
        $club->members()->updateExistingPivot($member->id, ['is_active' => false]);
        $this->get(route('member.payments.review', $payment))->assertForbidden();
        $this->post(route('member.payments.pay', $payment))->assertForbidden();

        // Active member again, but the club is deactivated.
        $club->members()->updateExistingPivot($member->id, ['is_active' => true]);
        $club->update(['is_active' => false]);
        $this->get(route('member.payments.review', $payment))->assertForbidden();
        $this->post(route('member.payments.pay', $payment))->assertForbidden();

        // Removed from the club altogether.
        $club->update(['is_active' => true]);
        $club->members()->detach($member->id);
        $this->get(route('member.payments.invoice', $payment))->assertForbidden();
        $this->get(route('member.payments.status', $payment))->assertForbidden();
        $this->post(route('member.payments.pay', $payment))->assertForbidden();

        Http::assertNothingSent();
        $this->assertNull($payment->fresh()->bill_code);
    }

    public function test_member_cannot_list_payments_of_a_club_they_do_not_belong_to(): void
    {
        $this->actingAsMember(Club::factory()->create());
        $other = Club::factory()->create();

        $this->get(route('member.payments.index', $other))->assertForbidden();
        $this->post(route('member.payments.generate-future', $other), [
            'period_start' => now()->addMonth()->startOfMonth()->toDateString(),
            'frequency' => 'monthly',
        ])->assertForbidden();
    }

    // ── Pay: bill reuse ──────────────────────────────────────────────────

    public function test_pay_reuses_the_open_bill_instead_of_creating_a_second_one(): void
    {
        Http::fake([
            'dev.toyyibpay.com/index.php/api/createBill' => Http::sequence()
                ->push([['BillCode' => 'FIRSTBILL']], 200)
                ->push([['BillCode' => 'SECONDBILL']], 200),
        ]);
        $club = $this->toyyibClub();
        $payment = $this->pending($this->actingAsMember($club), $club);

        $this->post(route('member.payments.pay', $payment))
            ->assertRedirect('https://dev.toyyibpay.com/FIRSTBILL');
        $reference = $payment->fresh()->reference;

        $this->post(route('member.payments.pay', $payment))
            ->assertRedirect('https://dev.toyyibpay.com/FIRSTBILL');

        Http::assertSentCount(1);
        $this->assertSame('FIRSTBILL', $payment->fresh()->bill_code);
        $this->assertSame($reference, $payment->fresh()->reference);
    }

    /** R2a: pressing pay again on the same bill restarts its "in progress" window. */
    public function test_reusing_the_bill_restamps_when_it_was_opened(): void
    {
        Http::fake(['dev.toyyibpay.com/index.php/api/createBill' => Http::response([['BillCode' => 'FIRSTBILL']], 200)]);
        $club = $this->toyyibClub();
        $payment = $this->pending($this->actingAsMember($club), $club);

        $this->post(route('member.payments.pay', $payment))->assertRedirect('https://dev.toyyibpay.com/FIRSTBILL');
        $bill = $payment->bills()->sole();
        $this->assertNotNull($bill->opened_at);

        $this->travel(2)->days();
        $this->post(route('member.payments.pay', $payment))->assertRedirect('https://dev.toyyibpay.com/FIRSTBILL');
        $this->assertTrue($bill->fresh()->opened_at->isSameMinute(now()));
        $this->travel(2)->days();
        $this->assertSame('processing', \App\Support\Payments\DisplayStatus::of($payment->fresh()));
        $this->travel(2)->days();
        $this->get(route('member.payments.review', $payment))->assertInertia(fn (Assert $p) => $p
            ->where('processing', false)->where('hasOpenBill', true)->where('openBillUrl', route('member.payments.thankyou', $payment)));
    }

    public function test_pay_creates_a_new_bill_when_the_amount_changed_after_the_bill(): void
    {
        Http::fake([
            'dev.toyyibpay.com/index.php/api/createBill' => Http::sequence()
                ->push([['BillCode' => 'BILL-RM10']], 200)
                ->push([['BillCode' => 'BILL-RM25']], 200),
        ]);
        $club = $this->toyyibClub();
        $payment = $this->pending($this->actingAsMember($club), $club, ['amount' => 10.00]);

        $this->post(route('member.payments.pay', $payment))->assertRedirect('https://dev.toyyibpay.com/BILL-RM10');
        $this->assertSame('10.00', $payment->fresh()->bill_amount);

        // Admin edits the fee: the open RM 10 bill must not be reused.
        $payment->fresh()->update(['amount' => 25.00]);
        $this->assertNull($payment->fresh()->bill_code);

        $this->post(route('member.payments.pay', $payment))->assertRedirect('https://dev.toyyibpay.com/BILL-RM25');
        Http::assertSentCount(2);
        Http::assertSent(fn ($req) => $req['billAmount'] === 2500);
        $this->assertSame('25.00', $payment->fresh()->bill_amount);
    }

    public function test_a_bill_for_a_different_amount_is_replaced_and_audited(): void
    {
        Http::fake([
            'dev.toyyibpay.com/index.php/api/createBill' => Http::response([['BillCode' => 'FRESH']], 200),
        ]);
        $club = $this->toyyibClub();
        // Stale bill left behind (e.g. amount written without the model hook).
        $payment = $this->pending($this->actingAsMember($club), $club, ['amount' => 30.00, 'bill_code' => 'STALE', 'bill_amount' => 10.00, 'gateway' => 'toyyibpay']);

        $this->post(route('member.payments.pay', $payment))->assertRedirect('https://dev.toyyibpay.com/FRESH');

        $this->assertDatabaseHas('audit_logs', ['action' => 'payment.bill_replaced', 'auditable_id' => $payment->id]);
    }

    public function test_pay_reuses_an_open_billplz_bill(): void
    {
        config(['billplz.base_url' => 'https://www.billplz-sandbox.com']);
        Http::fake();
        $club = Club::factory()->create([
            'payment_gateway' => 'billplz',
            'billplz_api_key' => 'k',
            'billplz_collection_id' => 'c',
            'billplz_x_signature_key' => 's',
        ]);
        $payment = $this->pending($this->actingAsMember($club), $club, ['bill_code' => 'bill-abc', 'gateway' => 'billplz']);

        $this->post(route('member.payments.pay', $payment))
            ->assertRedirect('https://www.billplz-sandbox.com/bills/bill-abc');

        Http::assertNothingSent();
    }

    public function test_pay_creates_a_new_bill_when_the_open_one_belongs_to_another_gateway(): void
    {
        Http::fake([
            'dev.toyyibpay.com/index.php/api/createBill' => Http::response([['BillCode' => 'TPNEW']], 200),
        ]);
        $club = $this->toyyibClub();
        $payment = $this->pending($this->actingAsMember($club), $club, ['bill_code' => 'old-billplz', 'gateway' => 'billplz']);

        $this->post(route('member.payments.pay', $payment))
            ->assertRedirect('https://dev.toyyibpay.com/TPNEW');

        Http::assertSentCount(1);
        $this->assertSame('TPNEW', $payment->fresh()->bill_code);
        $this->assertSame('toyyibpay', $payment->fresh()->gateway);
        $this->assertDatabaseHas('audit_logs', ['action' => 'payment.bill_replaced', 'auditable_id' => $payment->id]);
    }

    public function test_pay_from_inertia_returns_an_external_location(): void
    {
        Http::fake([
            'dev.toyyibpay.com/index.php/api/createBill' => Http::response([['BillCode' => 'TPX']], 200),
        ]);
        $club = $this->toyyibClub();
        $payment = $this->pending($this->actingAsMember($club), $club);

        $this->post(route('member.payments.pay', $payment), [], ['X-Inertia' => 'true'])
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', 'https://dev.toyyibpay.com/TPX');
    }

    public function test_pay_on_a_paid_fee_creates_no_bill(): void
    {
        Http::fake();
        $club = $this->toyyibClub();
        $payment = Payment::factory()->forClub($club)->forMember($this->actingAsMember($club))->create();

        $this->post(route('member.payments.pay', $payment))
            ->assertRedirect(route('member.payments.invoice', $payment));
        Http::assertNothingSent();
    }

    public function test_review_flags_an_open_bill(): void
    {
        $club = $this->toyyibClub();
        $payment = $this->pending($this->actingAsMember($club), $club, ['bill_code' => 'OPEN1', 'gateway' => 'toyyibpay']);

        $this->get(route('member.payments.review', $payment))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Member/ReviewPay')
                ->where('hasOpenBill', true)
                ->where('gateway.available', true)
                ->missing('payment.billCode')
            );
    }

    // ── Status JSON ──────────────────────────────────────────────────────

    public function test_status_route_returns_the_stored_status_for_the_owner(): void
    {
        $club = Club::factory()->create();
        $payment = $this->pending($this->actingAsMember($club), $club);

        $this->getJson(route('member.payments.status', $payment))
            ->assertOk()
            ->assertExactJson(['status' => 'due']);

        $payment->update(['status' => 'paid', 'paid_date' => now()->toDateString()]);

        $this->getJson(route('member.payments.status', $payment))
            ->assertOk()
            ->assertExactJson(['status' => 'paid']);
    }

    // ── PayResult ────────────────────────────────────────────────────────

    public function test_pay_result_shows_paid_only_when_the_stored_status_is_paid(): void
    {
        $club = Club::factory()->create();
        $member = $this->actingAsMember($club);
        $paid = Payment::factory()->forClub($club)->forMember($member)->create(['gateway' => 'toyyibpay', 'transaction_id' => 'TP-TXN-9']);

        $this->get(route('member.payments.thankyou', $paid))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Member/PayResult')
                ->where('outcome', 'paid')
                ->where('receipt.transactionId', 'TP-TXN-9')
            );
    }

    public function test_pay_result_is_pending_even_when_the_gateway_redirect_claims_success(): void
    {
        $club = Club::factory()->create();
        $payment = $this->pending($this->actingAsMember($club), $club, ['bill_code' => 'B1', 'gateway' => 'toyyibpay']);

        // ToyyibPay success parameters on the return URL are not proof of payment.
        $this->get(route('member.payments.thankyou', [$payment, 'status_id' => '1', 'billcode' => 'X']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('outcome', 'pending')
                ->where('receipt', null)
            );

        $this->get(route('member.payments.thankyou', [$payment, 'billplz' => ['paid' => 'true']]))
            ->assertInertia(fn (Assert $page) => $page->where('outcome', 'pending'));
    }

    public function test_pay_result_shows_failed_when_the_gateway_reports_failure(): void
    {
        $club = Club::factory()->create();
        $payment = $this->pending($this->actingAsMember($club), $club, ['bill_code' => 'B1', 'gateway' => 'toyyibpay']);

        $this->get(route('member.payments.thankyou', [$payment, 'status_id' => '3']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('outcome', 'failed')
                ->where('reviewUrl', route('member.payments.review', $payment))
            );

        $this->get(route('member.payments.thankyou', [$payment, 'billplz' => ['paid' => 'false']]))
            ->assertInertia(fn (Assert $page) => $page->where('outcome', 'failed'));
    }

    public function test_thankyou_without_an_opened_bill_redirects_to_review(): void
    {
        $club = Club::factory()->create();
        $payment = $this->pending($this->actingAsMember($club), $club);

        $this->get(route('member.payments.thankyou', $payment))
            ->assertRedirect(route('member.payments.review', $payment));
    }

    public function test_pending_fees_past_the_grace_period_count_as_overdue_in_summaries(): void
    {
        $club = Club::factory()->create(); // default grace: 7 days
        $member = $this->actingAsMember($club);
        $month = fn (int $n) => ['period_start' => now()->startOfMonth()->addMonthsNoOverflow($n)->toDateString(),
            'period_end' => now()->startOfMonth()->addMonthsNoOverflow($n)->endOfMonth()->toDateString()];
        // 8 days late: overdue. 7 days late: still due (last day of grace). Not due yet: due.
        $this->pending($member, $club, ['amount' => 10.00, 'due_date' => today()->subDays(8)->toDateString()] + $month(-2));
        $this->pending($member, $club, ['amount' => 5.00, 'due_date' => today()->subDays(7)->toDateString()] + $month(-1));
        $this->pending($member, $club, ['amount' => 20.00, 'due_date' => today()->addDays(3)->toDateString()] + $month(1));

        $this->get(route('member.dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('clubs.0.summary.overdue', 1)
                ->where('clubs.0.summary.due', 2)
                ->where('outstanding.overdueCount', 1)
                ->where('outstanding.count', 3)
                ->where('upcoming.0.status', 'overdue')
                ->where('upcoming.1.status', 'due')
            );

        $year = today()->year;
        $this->get(route('member.payments.index', [$club, 'year' => $year]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.overdue', today()->subDays(8)->year === $year ? 1000 : 0)
            );

        // A club with a shorter grace period: the 7-days-late fee is overdue there.
        $club->update(['overdue_grace_days' => 3]);
        $this->get(route('member.dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('outstanding.overdueCount', 2));
    }

    public function test_logout_from_an_inertia_page_returns_an_external_location(): void
    {
        $this->actingAsMember(Club::factory()->create());

        $this->post(route('logout'), [], ['X-Inertia' => 'true'])
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', url('/'));
        $this->assertGuest();
    }

    public function test_member_pages_declare_malay_as_the_html_language(): void
    {
        $this->actingAsMember(Club::factory()->create());

        $this->get(route('member.dashboard'))->assertOk()->assertSee('<html lang="ms">', false);
    }

    public function test_paid_status_wins_over_a_failure_hint(): void
    {
        $club = Club::factory()->create();
        $paid = Payment::factory()->forClub($club)->forMember($this->actingAsMember($club))->create();

        $this->get(route('member.payments.thankyou', [$paid, 'status_id' => '3']))
            ->assertInertia(fn (Assert $page) => $page->where('outcome', 'paid'));
    }

    // ── Home: "where your money goes" ────────────────────────────────────

    public function test_home_fund_aggregates_exclude_other_clubs_and_other_years(): void
    {
        $club = Club::factory()->create(['is_active' => true]);
        $member = $this->actingAsMember($club);
        $events = ExpenseCategory::factory()->for($club)->create(['name' => 'Events']);
        Expense::factory()->approved()->create(['club_id' => $club->id, 'expense_category_id' => $events->id, 'amount' => 100.00, 'expense_date' => now()->startOfYear()->toDateString(), 'description' => 'SECRET-DESCRIPTION']);
        Expense::factory()->approved()->create(['club_id' => $club->id, 'expense_category_id' => $events->id, 'amount' => 25.50, 'expense_date' => now()->startOfYear()->toDateString()]);
        // Last year: excluded.
        Expense::factory()->approved()->create(['club_id' => $club->id, 'expense_category_id' => $events->id, 'amount' => 999.00, 'expense_date' => now()->subYear()->toDateString()]);
        // Not approved (draft / awaiting / void): never counted.
        Expense::factory()->create(['club_id' => $club->id, 'expense_category_id' => $events->id, 'amount' => 300.00, 'expense_date' => now()->startOfYear()->toDateString()]);
        Expense::factory()->submitted()->create(['club_id' => $club->id, 'expense_category_id' => $events->id, 'amount' => 301.00, 'expense_date' => now()->startOfYear()->toDateString()]);
        Expense::factory()->voided()->create(['club_id' => $club->id, 'expense_category_id' => $events->id, 'amount' => 302.00, 'expense_date' => now()->startOfYear()->toDateString()]);
        // Someone else's paid fee in the same club counts towards "collected" (aggregate only).
        Payment::factory()->forClub($club)->create(['amount' => 40.00]);

        $other = Club::factory()->create(['is_active' => true]);
        $otherCat = ExpenseCategory::factory()->for($other)->create(['name' => 'OTHER-CLUB-CATEGORY']);
        Expense::factory()->approved()->create(['club_id' => $other->id, 'expense_category_id' => $otherCat->id, 'amount' => 5000.00, 'expense_date' => now()->toDateString()]);
        Payment::factory()->forClub($other)->create(['amount' => 777.00]);

        $this->get(route('member.clubs.fund', $club))
            ->assertOk()
            ->assertDontSee('OTHER-CLUB-CATEGORY')
            ->assertDontSee('SECRET-DESCRIPTION')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Member/Fund')
                ->where('club.id', $club->id)
                ->where('fund.spent', 12550)
                ->where('fund.collected', 4000)
                ->where('fund.categories', [['name' => 'Events', 'kind' => 'expense', 'amount' => 12550]])
            );

        $this->assertNotNull($member);
    }

    public function test_home_shows_a_section_per_active_club_for_multi_club_members(): void
    {
        $a = Club::factory()->create(['name' => 'Alpha Club', 'is_active' => true]);
        $b = Club::factory()->create(['name' => 'Beta Club', 'is_active' => true]);
        $inactive = Club::factory()->create(['name' => 'Gamma Club', 'is_active' => false]);
        $member = $this->actingAsMember($a);
        foreach ([$b, $inactive] as $club) {
            $club->members()->attach($member, ['role' => 'member', 'job_level' => 'manager', 'joined_date' => now()->toDateString(), 'is_active' => true]);
        }
        $catB = ExpenseCategory::factory()->for($b)->create(['name' => 'Sports']);
        Expense::factory()->approved()->create(['club_id' => $b->id, 'expense_category_id' => $catB->id, 'amount' => 10.00, 'expense_date' => now()->toDateString()]);
        $this->pending($member, $a, ['amount' => 20.00]);
        $this->pending($member, $b, ['amount' => 30.00]);
        $this->pending($member, $inactive, ['amount' => 99.00]);

        $this->get(route('member.dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('clubs', 2)
                ->where('clubs.0.name', 'Alpha Club')
                ->where('clubs.0.fundUrl', route('member.clubs.fund', $a))
                ->where('clubs.1.name', 'Beta Club')
                ->where('clubs.1.fundUrl', route('member.clubs.fund', $b))
                ->where('clubs.1.jobLevel', 'Manager')
                ->where('outstanding.total', 5000)
                ->where('outstanding.count', 2)
                ->has('upcoming', 2)
            );
        $this->get(route('member.clubs.fund', $a))->assertInertia(fn (Assert $page) => $page->where('fund.spent', 0));
        $this->get(route('member.clubs.fund', $b))->assertInertia(fn (Assert $page) => $page->where('fund.spent', 1000));
    }

    // ── Generate future + emails ─────────────────────────────────────────

    public function test_generate_future_creates_an_invoice_and_redirects_to_it(): void
    {
        $club = Club::factory()->create();
        $member = $this->actingAsMember($club);
        FeeRate::factory()->create(['club_id' => $club->id, 'job_level' => 'executive', 'monthly_amount' => 10.00, 'effective_to' => null]);
        $start = now()->addMonths(2)->startOfMonth();

        $response = $this->post(route('member.payments.generate-future', $club), [
            'period_start' => $start->toDateString(),
            'frequency' => 'quarterly',
        ]);

        $payment = Payment::where('user_id', $member->id)->latest('id')->first();
        $response->assertRedirect(route('member.payments.review', $payment))
            ->assertSessionHas('success', 'member.future_created');
        $this->assertSame('30.00', $payment->amount);
        $this->assertSame('pending', $payment->status);
    }

    public function test_overdue_reminder_links_to_the_review_page(): void
    {
        $club = Club::factory()->create();
        $payment = Payment::factory()->forClub($club)->forMember(User::factory()->create())->overdue()->create();

        $html = (new OverdueReminder($payment))->render();

        $this->assertStringContainsString(route('member.payments.review', $payment), $html);
        $this->assertStringNotContainsString(route('member.payments.pay', $payment), $html);
    }
}
