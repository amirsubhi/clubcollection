<?php

namespace Tests\Feature\Member;

use App\Models\AuditLog;
use App\Models\Club;
use App\Models\FeeRate;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Pay ahead (generateFuture): 1 / 3 / 12 months as the club allows, one fee
 * record per period, capped at 12 months ahead, no overlaps.
 */
class PayAheadTest extends TestCase
{
    private Club $club;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->club = Club::factory()->create([
            'payment_gateway' => 'toyyibpay',
            'toyyibpay_secret_key' => 'club-secret',
            'toyyibpay_category_code' => 'cat-123',
        ]);
        $this->member = $this->actingAsMember($this->club);
        FeeRate::factory()->create(['club_id' => $this->club->id, 'job_level' => 'executive', 'monthly_amount' => 10.00, 'effective_to' => null]);
    }

    private function monthStart(int $ahead): string
    {
        return now()->startOfMonth()->addMonthsNoOverflow($ahead)->toDateString();
    }

    private function create(string $frequency, string $start)
    {
        return $this->post(route('member.payments.generate-future', $this->club), ['period_start' => $start, 'frequency' => $frequency]);
    }

    public function test_quarterly_creates_one_payment_for_three_months(): void
    {
        $response = $this->create('quarterly', $this->monthStart(2));

        $payment = Payment::where('user_id', $this->member->id)->sole();
        $response->assertRedirect(route('member.payments.review', $payment))->assertSessionHas('success', 'member.future_created');
        $this->assertSame('30.00', $payment->amount);
        $this->assertSame('quarterly', $payment->frequency);
        $this->assertSame($this->monthStart(2), $payment->period_start->toDateString());
        $this->assertSame(now()->startOfMonth()->addMonthsNoOverflow(5)->subDay()->toDateString(), $payment->period_end->toDateString());
        $this->assertSame(1, AuditLog::where('action', 'payment.pay_ahead_created')->count());
    }

    public function test_yearly_creates_one_payment_for_twelve_months(): void
    {
        $this->create('yearly', $this->monthStart(1))->assertSessionHas('success', 'member.future_created');

        $payment = Payment::where('user_id', $this->member->id)->sole();
        $this->assertSame('120.00', $payment->amount);
        $this->assertSame('yearly', $payment->frequency);
        $this->assertSame(now()->startOfMonth()->addMonthsNoOverflow(13)->subDay()->toDateString(), $payment->period_end->toDateString());
    }

    public function test_yearly_payment_goes_through_review_pay_webhook_and_result(): void
    {
        Mail::fake();
        config(['toyyibpay.base_url' => 'https://dev.toyyibpay.com', 'toyyibpay.webhook_secret' => self::TOYYIB_SECRET]);
        $this->fakeToyyibConfirm(null, ['dev.toyyibpay.com/index.php/api/createBill' => Http::response([['BillCode' => 'YEARBILL']])]);
        config(['toyyibpay.charge_to_customer_verified' => true]);
        $this->club->update(['gateway_fee_payer' => 'member']);

        $this->create('yearly', $this->monthStart(1));
        $payment = Payment::where('user_id', $this->member->id)->sole();

        $this->get(route('member.payments.review', $payment))
            ->assertInertia(fn (Assert $page) => $page
                ->where('payment.frequency', 'yearly')
                ->where('payment.amount', 12000)
                ->where('charges.totalSen', 12100));

        $this->post(route('member.payments.pay', $payment))->assertRedirect('https://dev.toyyibpay.com/YEARBILL');
        Http::assertSent(fn ($r) => (int) $r['billAmount'] === 12000 && $r['billChargeToCustomer'] === '0');

        $reference = $payment->fresh()->reference;
        $this->post(route('webhook.toyyibpay').'?webhook_token='.$this->toyyibToken($reference), [
            'billcode' => 'YEARBILL', 'order_id' => $reference, 'status_id' => 1, 'amount' => '121.00', 'transaction_id' => 'TXN-Y',
        ])->assertOk();
        $this->assertSame('paid', $payment->fresh()->status);

        $this->get(route('member.payments.thankyou', $payment))
            ->assertInertia(fn (Assert $page) => $page->component('Member/PayResult')->where('outcome', 'paid'));
    }

    public function test_plan_not_allowed_by_the_club_is_rejected(): void
    {
        $this->club->update(['allow_monthly' => false]);

        $this->create('monthly', $this->monthStart(1))->assertSessionHas('error', 'member.plan_not_allowed');
        $this->assertSame(0, Payment::count());
    }

    public function test_more_than_twelve_months_ahead_is_rejected(): void
    {
        $this->create('monthly', $this->monthStart(13))->assertSessionHas('error', 'member.too_far_ahead');
        $this->assertSame(0, Payment::count());

        // A period may end at most at the end of the month 12 months ahead.
        $this->create('yearly', $this->monthStart(2))->assertSessionHas('error', 'member.too_far_ahead');
        $this->create('quarterly', $this->monthStart(11))->assertSessionHas('error', 'member.too_far_ahead');
        $this->assertSame(0, Payment::count());

        $this->create('monthly', $this->monthStart(12))->assertSessionHas('success', 'member.future_created');
        Payment::query()->delete();
        $this->create('quarterly', $this->monthStart(10))->assertSessionHas('success', 'member.future_created');
        $this->assertSame(now()->startOfMonth()->addMonthsNoOverflow(12)->endOfMonth()->toDateString(),
            Payment::where('frequency', 'quarterly')->sole()->period_end->toDateString());
    }

    public function test_concurrent_submit_gets_a_try_again_message(): void
    {
        $lock = \Illuminate\Support\Facades\Cache::lock("pay-ahead:{$this->club->id}:{$this->member->id}", 10);
        $this->assertTrue((bool) $lock->get());

        $this->create('monthly', $this->monthStart(1))->assertSessionHas('error', 'member.try_again');
        $this->assertSame(0, Payment::count());
        $lock->release();
    }

    public function test_current_month_or_past_is_rejected(): void
    {
        $this->create('monthly', now()->subDay()->toDateString())->assertSessionHasErrors('period_start');
        $this->assertSame(0, Payment::count());
    }

    public function test_duplicate_and_overlapping_periods_are_rejected(): void
    {
        $this->create('quarterly', $this->monthStart(1))->assertSessionHas('success');

        // Same start.
        $this->create('monthly', $this->monthStart(1))->assertSessionHas('error', 'member.period_overlaps');
        // Starts inside the quarter.
        $this->create('monthly', $this->monthStart(3))->assertSessionHas('error', 'member.period_overlaps');
        // A later quarter that runs into it.
        $this->create('quarterly', $this->monthStart(2))->assertSessionHas('error', 'member.period_overlaps');
        $this->assertSame(1, Payment::count());

        // Right after the quarter is fine.
        $this->create('monthly', $this->monthStart(4))->assertSessionHas('success');
        $this->assertSame(2, Payment::count());
    }

    public function test_unique_index_blocks_a_second_fee_with_the_same_period_start(): void
    {
        Payment::factory()->forClub($this->club)->forMember($this->member)->pending()->create(['period_start' => $this->monthStart(1)]);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        Payment::factory()->forClub($this->club)->forMember($this->member)->pending()->create(['period_start' => $this->monthStart(1)]);
    }

    public function test_inactive_membership_cannot_pay_ahead(): void
    {
        $this->club->members()->updateExistingPivot($this->member->id, ['is_active' => false]);

        $this->create('monthly', $this->monthStart(1))->assertForbidden();
    }

    public function test_payments_page_offers_only_allowed_plans_with_fee_mode(): void
    {
        config(['toyyibpay.charge_to_customer_verified' => true]);
        $this->club->update(['allow_monthly' => false, 'default_plan' => 'quarterly', 'gateway_fee_payer' => 'member', 'gateway_fee_sen' => 100]);
        Payment::factory()->forClub($this->club)->forMember($this->member)->pending()->create([
            'period_start' => $this->monthStart(0), 'period_end' => now()->endOfMonth()->toDateString(),
        ]);

        $this->get(route('member.payments.index', $this->club))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Member/Payments')
                ->where('futureForm.plans', ['quarterly', 'yearly'])
                ->where('futureForm.defaultPlan', 'quarterly')
                ->where('futureForm.monthlySen', 1000)
                ->where('futureForm.feePayer', 'member')
                ->where('futureForm.feeSen', 100)
                ->where('futureForm.defaultStart', $this->monthStart(1))
                ->where('futureForm.freeMonths.0', $this->monthStart(1))
                ->has('futureForm.freeMonths', 12)
                ->where('futureForm.maxEnd', now()->startOfMonth()->addMonthsNoOverflow(12)->endOfMonth()->toDateString())
            );
    }

    public function test_month_list_excludes_covered_months_and_defaults_to_the_first_free_one(): void
    {
        $this->create('quarterly', $this->monthStart(1))->assertSessionHas('success');
        $this->create('monthly', $this->monthStart(5))->assertSessionHas('success');

        $this->get(route('member.payments.index', $this->club))
            ->assertInertia(fn (Assert $page) => $page
                ->where('futureForm.defaultStart', $this->monthStart(4))
                ->where('futureForm.freeMonths', [$this->monthStart(4), ...array_map(fn ($i) => $this->monthStart($i), range(6, 12))])
            );
    }

    public function test_paid_up_to_the_cap_offers_no_months(): void
    {
        $this->create('yearly', $this->monthStart(1))->assertSessionHas('success');

        $this->get(route('member.payments.index', $this->club))
            ->assertInertia(fn (Assert $page) => $page
                ->where('futureForm.freeMonths', [])
                ->where('futureForm.defaultStart', null)
                ->where('futureForm.paidUntil', now()->startOfMonth()->addMonthsNoOverflow(13)->subDay()->toDateString())
            );
    }
}
