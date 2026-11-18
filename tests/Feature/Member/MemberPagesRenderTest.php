<?php

namespace Tests\Feature\Member;

use App\Models\Club;
use App\Models\Payment;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Every member-facing page renders its Inertia component with the expected
 * props, and nothing admin-only (payments.notes) reaches the browser.
 */
class MemberPagesRenderTest extends TestCase
{
    private Club $club;

    private User $member;

    private Payment $payment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->club = Club::factory()->create();
        $this->member = $this->actingAsMember($this->club);
        $this->payment = Payment::factory()
            ->forClub($this->club)
            ->forMember($this->member)
            ->pending()
            ->create(['notes' => 'INTERNAL-ADMIN-NOTE-should-not-leak', 'amount' => 60.00]);
    }

    public function test_member_dashboard_renders_home(): void
    {
        $this->get(route('member.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Member/Home')
                ->where('outstanding.total', 6000)
                ->where('outstanding.count', 1)
                ->has('upcoming', 1)
                ->where('upcoming.0.id', $this->payment->id)
                ->where('upcoming.0.urls.review', route('member.payments.review', $this->payment))
                ->has('recentPaid', 0)
                ->has('clubs', 1)
                ->where('clubs.0.id', $this->club->id)
                ->where('clubs.0.fundUrl', route('member.clubs.fund', $this->club))
                ->missing('clubs.0.fund')
                ->where('account.logoutUrl', route('logout'))
            );
    }

    public function test_member_payments_index_renders_with_year_filter(): void
    {
        $year = now()->year;

        $this->get(route('member.payments.index', [$this->club, 'year' => $year]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Member/Payments')
                ->where('club.id', $this->club->id)
                ->where('selectedYear', $year)
                ->has('years')
                ->has('payments', 1)
                ->where('payments.0.id', $this->payment->id)
                ->where('summary.due', 6000)
                ->where('futureForm.action', route('member.payments.generate-future', $this->club))
            );

        $this->get(route('member.payments.index', [$this->club, 'year' => $year - 5]))
            ->assertInertia(fn (Assert $page) => $page->where('selectedYear', $year - 5)->has('payments', 0));
    }

    public function test_member_invoice_renders(): void
    {
        $this->get(route('member.payments.invoice', $this->payment))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Member/Invoice')
                ->where('payment.id', $this->payment->id)
                ->where('payment.amount', 6000)
                ->where('payment.status', 'due')
                ->where('receipt', null)
                ->where('member.name', $this->member->name)
                ->where('payClub.name', $this->club->name)
            );
    }

    public function test_paid_invoice_renders_receipt_variant(): void
    {
        $paid = Payment::factory()->forClub($this->club)->forMember($this->member)
            ->create([
            'period_start' => now()->subMonthNoOverflow()->startOfMonth()->toDateString(),
            'period_end' => now()->subMonthNoOverflow()->endOfMonth()->toDateString(),
            'transaction_id' => 'TXN-1', 'gateway' => 'toyyibpay',
        ]);

        $this->get(route('member.payments.invoice', $paid))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Member/Invoice')
                ->where('payment.status', 'paid')
                ->where('payment.urls.review', null)
                ->where('receipt.transactionId', 'TXN-1')
                ->where('receipt.gateway', 'ToyyibPay')
            );
    }

    public function test_member_review_page_renders(): void
    {
        $this->get(route('member.payments.review', $this->payment))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Member/ReviewPay')
                ->where('payment.id', $this->payment->id)
                ->where('payment.amount', 6000)
                ->where('gateway.name', 'toyyibpay')
                ->where('gateway.label', 'ToyyibPay')
                ->has('gateway.available')
                ->where('hasOpenBill', false)
                ->where('payUrl', route('member.payments.pay', $this->payment))
            );
    }

    public function test_review_of_a_paid_fee_redirects_to_the_receipt(): void
    {
        $paid = Payment::factory()->forClub($this->club)->forMember($this->member)->create([
            'period_start' => now()->subMonthNoOverflow()->startOfMonth()->toDateString(),
            'period_end' => now()->subMonthNoOverflow()->endOfMonth()->toDateString(),
        ]);

        $this->get(route('member.payments.review', $paid))
            ->assertRedirect(route('member.payments.invoice', $paid));
    }

    public function test_member_thankyou_renders_pay_result(): void
    {
        $this->payment->update(['bill_code' => 'OPEN1', 'gateway' => 'toyyibpay']);

        $this->get(route('member.payments.thankyou', $this->payment))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Member/PayResult')
                ->where('payment.id', $this->payment->id)
                ->where('statusUrl', route('member.payments.status', $this->payment))
                ->where('reviewUrl', route('member.payments.review', $this->payment))
            );
    }

    public function test_invoice_does_not_show_admin_only_notes(): void
    {
        $response = $this->get(route('member.payments.invoice', $this->payment))->assertOk();

        $response->assertDontSee('INTERNAL-ADMIN-NOTE-should-not-leak');
        $response->assertInertia(fn (Assert $page) => $page
            ->missing('payment.notes')
            ->missing('notes')
        );
    }

    public function test_no_member_page_leaks_admin_only_notes(): void
    {
        $this->payment->update(['bill_code' => 'OPEN1', 'gateway' => 'toyyibpay']);

        foreach ([
            route('member.dashboard'),
            route('member.payments.index', $this->club),
            route('member.payments.review', $this->payment),
            route('member.payments.thankyou', $this->payment),
        ] as $url) {
            $this->get($url)->assertOk()->assertDontSee('INTERNAL-ADMIN-NOTE-should-not-leak');
        }
    }
}
