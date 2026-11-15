<?php

namespace Tests\Feature\Admin;

use App\Models\Club;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Admin dashboard "Gateway fees this year": fees the club absorbed on fees
 * paid online this year: fee_absorbed_sen, set by the verified webhook from bill_fee_sen.
 */
class GatewayFeeKpiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Mail::fake();
        config(['toyyibpay.webhook_secret' => self::TOYYIB_SECRET]);
        $this->fakeToyyibConfirm();
    }

    private function paidOnline(Club $club, User $member, int $monthsAgo, string $payer, int $feeSen): Payment
    {
        $start = now()->startOfMonth()->subMonthsNoOverflow($monthsAgo);
        $p = Payment::factory()->forClub($club)->forMember($member)->pending()->create([
            'period_start' => $start->toDateString(), 'period_end' => $start->copy()->endOfMonth()->toDateString(),
            'bill_code' => 'B'.uniqid(), 'reference' => 'REF-'.uniqid(), 'bill_amount' => 50.00, 'bill_fee_payer' => $payer, 'bill_fee_sen' => $feeSen, 'gateway' => 'toyyibpay',
        ]);
        $this->post(route('webhook.toyyibpay').'?webhook_token='.$this->toyyibToken($p->reference), [
            'billcode' => $p->bill_code, 'order_id' => $p->reference, 'status_id' => 1, 'amount' => '50.00', 'transaction_id' => 'T'.$p->id,
        ])->assertOk();

        return $p->fresh();
    }

    public function test_kpi_sums_fees_the_club_absorbed_on_online_payments_this_year(): void
    {
        $this->travelTo(now()->setDate((int) now()->year, 11, 15));
        $club = Club::factory()->create();
        $member = User::factory()->create();
        $club->members()->attach($member, ['role' => 'member', 'job_level' => 'executive', 'joined_date' => now()->toDateString(), 'is_active' => true]);

        $first = $this->paidOnline($club, $member, 0, 'club', 100);
        $this->assertSame('toyyibpay', $first->paid_via);
        $this->assertSame(100, $first->fee_absorbed_sen);
        // Editing a paid fee's amount keeps what was absorbed.
        $first->update(['amount' => 55.00]);
        $this->assertSame(100, $first->fresh()->fee_absorbed_sen);
        $this->paidOnline($club, $member, 1, 'club', 125);
        $memberPaid = $this->paidOnline($club, $member, 2, 'member', 100); // member paid the fee: not counted
        $this->assertSame(0, $memberPaid->fee_absorbed_sen);
        // Paid by hand (no webhook, so no paid_via/fee_absorbed_sen) with a bill fee recorded: not counted.
        Payment::factory()->forClub($club)->forMember($member)->create([
            'period_start' => now()->startOfMonth()->subMonthsNoOverflow(3)->toDateString(),
            'bill_code' => 'MANUAL', 'bill_fee_payer' => 'club', 'bill_fee_sen' => 100,
        ]);
        // Paid online last year: not counted.
        $old = $this->paidOnline($club, $member, 4, 'club', 100);
        $old->update(['paid_date' => now()->subYear()->toDateString()]);
        // Another club's fees: not counted.
        $other = Club::factory()->create();
        $other->members()->attach($member, ['role' => 'member', 'job_level' => 'executive', 'joined_date' => now()->toDateString(), 'is_active' => true]);
        $this->paidOnline($other, $member, 0, 'club', 100);

        $this->actingAsClubAdmin($club);
        $this->get(route('admin.clubs.dashboard', $club))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Dashboard')
                ->where('kpis.gatewayFees.absorbedYtd', 225)
                ->where('kpis.gatewayFees.payer', 'club')
                ->where('kpis.gatewayFees.perBill', 100)
                ->where('urls.billing', route('admin.clubs.billing.edit', $club))
            );
    }

    public function test_kpi_is_zero_and_reports_member_payer_when_members_pay(): void
    {
        $club = Club::factory()->create(['payment_gateway' => 'billplz', 'gateway_fee_payer' => 'member']);
        $member = User::factory()->create();
        $club->members()->attach($member, ['role' => 'member', 'job_level' => 'executive', 'joined_date' => now()->toDateString(), 'is_active' => true]);
        $this->paidOnline($club, $member, 0, 'member', 100);

        $this->actingAsClubAdmin($club);
        $this->get(route('admin.clubs.dashboard', $club))
            ->assertInertia(fn (Assert $page) => $page
                ->where('kpis.gatewayFees.absorbedYtd', 0)
                ->where('kpis.gatewayFees.payer', 'member')
            );
    }
}
