<?php

namespace Tests\Feature\Member;

use App\Models\AuditLog;
use App\Models\Club;
use App\Models\Payment;
use App\Models\User;
use App\Services\PaymentGateways\ToyyibPayGateway;
use App\Support\Payments\BillplzSignature;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Who pays the gateway fee: ToyyibPay flag mapping, Billplz amount, bill reuse,
 * webhook amount checks and what ReviewPay shows.
 */
class GatewayFeeTest extends TestCase
{
    private string $billplzKey = 'test-billplz-x-signature-key-2026';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Mail::fake();
        config([
            'toyyibpay.base_url' => 'https://dev.toyyibpay.com',
            'toyyibpay.webhook_secret' => 'toyyib-webhook-secret',
            'billplz.base_url' => 'https://www.billplz-sandbox.com',
            // These tests exercise "member pays" on ToyyibPay as if the sandbox had confirmed it.
            'toyyibpay.charge_to_customer_verified' => true,
        ]);
    }

    private function club(string $gateway, string $payer, int $feeSen = 100): Club
    {
        return Club::factory()->create([
            'payment_gateway' => $gateway,
            'gateway_fee_payer' => $payer,
            'gateway_fee_sen' => $feeSen,
            'toyyibpay_secret_key' => 'club-secret',
            'toyyibpay_category_code' => 'cat-123',
            'billplz_api_key' => 'bp-key',
            'billplz_collection_id' => 'col-1',
            'billplz_x_signature_key' => $this->billplzKey,
        ]);
    }

    private function pending(Club $club, ?User $member = null, float $amount = 10.00): Payment
    {
        $member ??= $this->actingAsMember($club);

        return Payment::factory()->forClub($club)->forMember($member)->pending()->create(['amount' => $amount]);
    }

    private function fakeToyyib(): void
    {
        Http::fake(['dev.toyyibpay.com/index.php/api/createBill' => Http::sequence()
            ->push([['BillCode' => 'TB1']])->push([['BillCode' => 'TB2']])]);
    }

    private function fakeBillplz(): void
    {
        Http::fake(['www.billplz-sandbox.com/api/v3/bills' => Http::sequence()
            ->push(['id' => 'BP1', 'url' => 'https://www.billplz-sandbox.com/bills/BP1'])
            ->push(['id' => 'BP2', 'url' => 'https://www.billplz-sandbox.com/bills/BP2'])]);
    }

    // ── ToyyibPay ────────────────────────────────────────────────────────

    public function test_toyyibpay_club_absorbs_sends_blank_charge_flag_and_the_fee_amount(): void
    {
        $this->fakeToyyib();
        $payment = $this->pending($this->club('toyyibpay', 'club'));

        $this->post(route('member.payments.pay', $payment))->assertRedirect('https://dev.toyyibpay.com/TB1');

        Http::assertSent(fn (HttpRequest $r) => (string) $r['billChargeToCustomer'] === '1'
            && $r['billChargeToCustomer'] === ToyyibPayGateway::BILL_CHARGE_TO_CUSTOMER['club']
            && (int) $r['billAmount'] === 1000);
        $fresh = $payment->fresh();
        $this->assertSame('club', $fresh->bill_fee_payer);
        $this->assertSame(100, $fresh->bill_fee_sen);
        $this->assertSame('10.00', $fresh->bill_amount);
    }

    public function test_toyyibpay_member_pays_uses_the_flag_and_does_not_add_the_fee_to_the_amount(): void
    {
        $this->fakeToyyib();
        $payment = $this->pending($this->club('toyyibpay', 'member'));

        $this->post(route('member.payments.pay', $payment))->assertRedirect('https://dev.toyyibpay.com/TB1');

        Http::assertSent(fn (HttpRequest $r) => $r['billChargeToCustomer'] === '0'
            && $r['billChargeToCustomer'] === ToyyibPayGateway::BILL_CHARGE_TO_CUSTOMER['member']
            && (int) $r['billAmount'] === 1000);
        $this->assertSame('member', $payment->fresh()->bill_fee_payer);
    }

    // ── Billplz ──────────────────────────────────────────────────────────

    public function test_billplz_adds_the_fee_to_the_amount_only_when_the_member_pays(): void
    {
        $this->fakeBillplz();
        $member = $this->pending($this->club('billplz', 'member', 125));
        $this->post(route('member.payments.pay', $member))->assertRedirect('https://www.billplz-sandbox.com/bills/BP1');
        Http::assertSent(fn (HttpRequest $r) => (int) $r['amount'] === 1125);
        $this->assertSame('10.00', $member->fresh()->bill_amount);
        $this->assertSame(125, $member->fresh()->bill_fee_sen);

        $club = $this->pending($this->club('billplz', 'club', 125));
        $this->post(route('member.payments.pay', $club))->assertRedirect('https://www.billplz-sandbox.com/bills/BP2');
        $amounts = Http::recorded()->map(fn ($pair) => (int) $pair[0]['amount'])->all();
        $this->assertSame([1125, 1000], $amounts);
    }

    // ── Bill reuse ───────────────────────────────────────────────────────

    public function test_changing_the_fee_setting_keeps_reusing_the_open_bill_and_review_shows_its_stored_mode(): void
    {
        $this->fakeToyyib();
        $club = $this->club('toyyibpay', 'club');
        $payment = $this->pending($club);

        $this->post(route('member.payments.pay', $payment))->assertRedirect('https://dev.toyyibpay.com/TB1');

        // The club now passes the fee on to members. The open bill (club absorbs)
        // is not orphaned: pay reuses it and review shows what it will charge.
        $club->update(['gateway_fee_payer' => 'member']);
        $this->get(route('member.payments.review', $payment))
            ->assertInertia(fn (Assert $page) => $page
                ->where('hasOpenBill', true)
                ->where('charges.payer', 'club')
                ->where('charges.feeSen', 0)
                ->where('charges.totalSen', 1000));
        $this->post(route('member.payments.pay', $payment))->assertRedirect('https://dev.toyyibpay.com/TB1');
        Http::assertSentCount(1);
        $this->assertSame('club', $payment->fresh()->bill_fee_payer);
        $this->assertSame(0, AuditLog::where('action', 'payment.bill_replaced')->count());

        // Once the amount changes a new bill is made with the current setting.
        $payment->update(['amount' => 12.00]);
        $this->get(route('member.payments.review', $payment))
            ->assertInertia(fn (Assert $page) => $page
                ->where('hasOpenBill', false)
                ->where('charges.payer', 'member')
                ->where('charges.totalSen', 1300));
        $this->post(route('member.payments.pay', $payment))->assertRedirect('https://dev.toyyibpay.com/TB2');
        $this->assertSame('member', $payment->fresh()->bill_fee_payer);
    }

    public function test_open_member_pays_bill_keeps_its_stored_fee_when_the_fee_amount_changes(): void
    {
        $this->fakeBillplz();
        $club = $this->club('billplz', 'member', 100);
        $payment = $this->pending($club);

        $this->post(route('member.payments.pay', $payment))->assertRedirect('https://www.billplz-sandbox.com/bills/BP1');
        $club->update(['gateway_fee_sen' => 150, 'gateway_fee_payer' => 'club']);

        $this->get(route('member.payments.review', $payment))
            ->assertInertia(fn (Assert $page) => $page
                ->where('charges.payer', 'member')
                ->where('charges.feeSen', 100)
                ->where('charges.totalSen', 1100));
        $this->post(route('member.payments.pay', $payment))->assertRedirect('https://www.billplz-sandbox.com/bills/BP1');
        Http::assertSentCount(1);
    }

    public function test_legacy_bill_without_fee_mode_is_reused_and_shown_as_club_absorbed(): void
    {
        Http::fake();
        $club = $this->club('billplz', 'member');
        $payment = $this->pending($club);
        $payment->update(['bill_code' => 'OLD', 'bill_amount' => 10.00, 'gateway' => 'billplz']);

        $this->get(route('member.payments.review', $payment))
            ->assertInertia(fn (Assert $page) => $page->where('charges.payer', 'club')->where('charges.totalSen', 1000));
        $this->post(route('member.payments.pay', $payment))->assertRedirect('https://www.billplz-sandbox.com/bills/OLD');
        Http::assertNothingSent();
    }

    // ── ToyyibPay "member pays" gate ─────────────────────────────────────

    public function test_toyyibpay_member_setting_is_ignored_until_verified(): void
    {
        config(['toyyibpay.charge_to_customer_verified' => false]);
        $this->fakeToyyib();
        $payment = $this->pending($this->club('toyyibpay', 'member'));

        $this->get(route('member.payments.review', $payment))
            ->assertInertia(fn (Assert $page) => $page->where('charges.payer', 'club')->where('charges.totalSen', 1000));
        $this->post(route('member.payments.pay', $payment));
        Http::assertSent(fn (HttpRequest $r) => (string) $r['billChargeToCustomer'] === '1');
        $this->assertSame('club', $payment->fresh()->bill_fee_payer);
    }

    public function test_changing_the_amount_clears_the_stored_fee_mode(): void
    {
        $payment = $this->pending($this->club('toyyibpay', 'member'));
        $payment->update(['bill_code' => 'X', 'bill_amount' => 10.00, 'bill_fee_payer' => 'member', 'bill_fee_sen' => 100, 'gateway' => 'toyyibpay']);

        $payment->update(['amount' => 12.00]);

        $fresh = $payment->fresh();
        $this->assertNull($fresh->bill_code);
        $this->assertNull($fresh->bill_fee_payer);
        $this->assertNull($fresh->bill_fee_sen);
    }

    // ── Webhook amount checks ────────────────────────────────────────────

    private function billplzPayload(Payment $p, int $paidSen): array
    {
        $body = [
            'id' => $p->bill_code, 'collection_id' => 'col-1', 'paid' => 'true', 'state' => 'paid',
            'amount' => (string) $paidSen, 'paid_amount' => (string) $paidSen,
            'transaction_id' => 'TXN-'.uniqid(), 'transaction_status' => 'completed',
            'reference_1_label' => 'PaymentRef', 'reference_1' => $p->reference,
        ];
        $body['x_signature'] = BillplzSignature::compute($body, $this->billplzKey);

        return $body;
    }

    public function test_billplz_webhook_accepts_amount_plus_fee_and_rejects_less_than_the_fee_amount(): void
    {
        $club = $this->club('billplz', 'member', 100);
        $member = User::factory()->create();
        $club->members()->attach($member, ['role' => 'member', 'job_level' => 'executive', 'joined_date' => now()->toDateString(), 'is_active' => true]);
        $attrs = ['bill_amount' => 10.00, 'bill_fee_payer' => 'member', 'bill_fee_sen' => 100, 'gateway' => 'billplz'];

        $ok = $this->pending($club, $member);
        $ok->update($attrs + ['bill_code' => 'BPOK', 'reference' => 'R1']);
        $this->post(route('webhook.billplz'), $this->billplzPayload($ok, 1100))->assertOk();
        $this->assertSame('paid', $ok->fresh()->status);
        $this->assertSame('billplz', $ok->fresh()->paid_via);
        $this->assertSame(0, $ok->fresh()->fee_absorbed_sen);

        $short = Payment::factory()->forClub($club)->forMember($member)->pending()->create([
            'amount' => 10.00, 'period_start' => now()->addMonthNoOverflow()->startOfMonth()->toDateString(),
        ]);
        $short->update($attrs + ['bill_code' => 'BPSHORT', 'reference' => 'R2']);
        $this->post(route('webhook.billplz'), $this->billplzPayload($short, 999))->assertOk();
        $this->assertSame('pending', $short->fresh()->status);
        $this->assertSame(1, AuditLog::where('action', 'payment.underpaid_webhook')->count());
    }

    public function test_toyyibpay_webhook_accepts_amount_with_or_without_the_charge(): void
    {
        $club = $this->club('toyyibpay', 'member', 100);
        $member = User::factory()->create();
        $club->members()->attach($member, ['role' => 'member', 'job_level' => 'executive', 'joined_date' => now()->toDateString(), 'is_active' => true]);
        $url = route('webhook.toyyibpay').'?webhook_token=toyyib-webhook-secret';

        $cases = [['11.00', 'paid'], ['10.00', 'paid'], ['9.99', 'pending']];
        foreach ($cases as $i => [$paid, $expected]) {
            $p = Payment::factory()->forClub($club)->forMember($member)->pending()->create([
                'amount' => 10.00,
                'period_start' => now()->addMonthsNoOverflow($i + 1)->startOfMonth()->toDateString(),
                'bill_code' => "TB-{$i}", 'bill_amount' => 10.00, 'bill_fee_payer' => 'member', 'bill_fee_sen' => 100, 'gateway' => 'toyyibpay',
            ]);
            $this->post($url, ['billcode' => "TB-{$i}", 'status_id' => 1, 'amount' => $paid, 'transaction_id' => "T{$i}"])->assertOk();
            $this->assertSame($expected, $p->fresh()->status, "paid {$paid}");
        }
    }

    // ── ReviewPay ────────────────────────────────────────────────────────

    public function test_review_shows_fee_and_total_when_the_member_pays(): void
    {
        $payment = $this->pending($this->club('toyyibpay', 'member', 100));

        $this->get(route('member.payments.review', $payment))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Member/ReviewPay')
                ->where('payment.amount', 1000)
                ->where('charges.payer', 'member')
                ->where('charges.feeSen', 100)
                ->where('charges.totalSen', 1100)
            );
    }

    public function test_review_shows_no_fee_when_the_club_absorbs_it(): void
    {
        $payment = $this->pending($this->club('billplz', 'club', 100));

        $this->get(route('member.payments.review', $payment))
            ->assertInertia(fn (Assert $page) => $page
                ->where('charges.payer', 'club')
                ->where('charges.feeSen', 0)
                ->where('charges.totalSen', 1000)
            );
    }
}
