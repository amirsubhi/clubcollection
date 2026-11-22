<?php

namespace Tests\Feature\Member;

use App\Mail\PaymentConfirmation;
use App\Mail\TransferRejected;
use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\Club;
use App\Models\Payment;
use App\Models\PaymentBill;
use App\Models\User;
use App\Services\PaymentGateways\ToyyibPayGateway;
use App\Support\Payments\BillplzSignature;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * R1b "Bayar semua": every open fee of a member in a club paid with one
 * gateway bill or one transfer slip, settled all at once or not at all.
 */
class PayAllTest extends TestCase
{
    private string $billplzKey = 'test-billplz-x-signature-key-2026';

    private Club $club;

    private User $member;

    /** @var array<int, Payment> oldest first: RM 10 (overdue), RM 20, RM 30 */
    private array $fees;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Mail::fake();
        Storage::fake('local');
        config([
            'toyyibpay.base_url' => 'https://dev.toyyibpay.com',
            'toyyibpay.webhook_secret' => self::TOYYIB_SECRET,
            'billplz.base_url' => 'https://www.billplz-sandbox.com',
        ]);

        $this->club = $this->makeClub();
        $this->member = $this->join(User::factory()->create(['role' => 'member', 'name' => 'Ahli Contoh']), $this->club);
        $this->fees = [
            $this->fee($this->club, $this->member, '2026-08-01', 10.00, 'overdue'),
            $this->fee($this->club, $this->member, '2026-09-01', 20.00),
            $this->fee($this->club, $this->member, '2026-10-01', 30.00),
        ];
    }

    private function makeClub(array $attrs = []): Club
    {
        return Club::factory()->create($attrs + [
            'name' => 'Kelab Contoh',
            'payment_gateway' => 'toyyibpay',
            'gateway_fee_payer' => 'club',
            'gateway_fee_sen' => 100,
            'toyyibpay_secret_key' => 'club-secret',
            'toyyibpay_category_code' => 'cat-123',
            'billplz_api_key' => 'bp-key',
            'billplz_collection_id' => 'col-1',
            'billplz_x_signature_key' => $this->billplzKey,
            'transfer_enabled' => true,
            'bank_name' => 'Bank Contoh',
            'bank_account_name' => 'Kelab Contoh',
            'bank_account_no' => '1234 5678 9012',
        ]);
    }

    private function join(User $user, Club $club, string $role = 'member'): User
    {
        $club->members()->attach($user, ['role' => $role, 'job_level' => 'executive', 'joined_date' => now()->toDateString(), 'is_active' => true]);

        return $user;
    }

    private function fee(Club $club, User $member, string $start, float $amount, string $status = 'pending'): Payment
    {
        $s = \Carbon\Carbon::parse($start);

        return Payment::factory()->forClub($club)->forMember($member)->create([
            'amount' => $amount,
            'frequency' => 'monthly',
            'period_start' => $s->toDateString(),
            'period_end' => $s->copy()->endOfMonth()->toDateString(),
            'due_date' => $s->toDateString(),
            'status' => $status,
            'paid_date' => null,
        ]);
    }

    private function set(?array $fees = null): array
    {
        $fees ??= $this->fees;

        return [
            'payment_ids' => array_map(fn (Payment $p) => $p->id, $fees),
            'total_sen' => array_sum(array_map(fn (Payment $p) => $p->fresh()->amountSen(), $fees)),
        ];
    }

    private function fakeToyyib(string ...$codes): void
    {
        $seq = Http::sequence();
        foreach ($codes ?: ['GRP1'] as $code) {
            $seq->push([['BillCode' => $code]]);
        }
        Http::fake(['dev.toyyibpay.com/index.php/api/createBill' => $seq]);
    }

    /** Open a group bill as the member and return it. */
    private function payAll(string $code = 'GRP1', bool $fake = true): PaymentBill
    {
        if ($fake) {
            $this->fakeToyyib($code);
        }
        $this->actingAs($this->member)->post(route('member.payments.pay-all', $this->club), $this->set())
            ->assertRedirect("https://dev.toyyibpay.com/{$code}");

        return PaymentBill::where('bill_code', $code)->firstOrFail();
    }

    private function webhook(PaymentBill $bill, array $extra = [], ?string $token = null)
    {
        auth()->logout();

        return $this->post(route('webhook.toyyibpay').'?webhook_token='.($token ?? $this->toyyibToken($bill->reference)), $extra + [
            'billcode' => $bill->bill_code,
            'order_id' => $bill->reference,
            'status_id' => 1,
            'transaction_id' => 'TXN-'.uniqid(),
        ]);
    }

    private function statuses(): array
    {
        return array_map(fn (Payment $p) => $p->fresh()->status, $this->fees);
    }

    // ── Gateway: one bill for the set ────────────────────────────────────

    public function test_one_bill_for_the_sum_of_open_fees_with_the_club_absorbing_one_fee(): void
    {
        $bill = $this->payAll();

        $this->assertTrue($bill->isGroup());
        $this->assertSame(6000, $bill->amount_sen);
        $this->assertSame('club', $bill->fee_payer);
        $this->assertSame([$this->fees[0]->id => 1000, $this->fees[1]->id => 2000, $this->fees[2]->id => 3000], $bill->itemAmounts());
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'createBill')
            && (int) $r['billAmount'] === 6000
            && $r['billChargeToCustomer'] == ToyyibPayGateway::BILL_CHARGE_TO_CUSTOMER['club']
            && $r['billExternalReferenceNo'] === $bill->reference
            && str_contains($r['billCallbackUrl'], 'webhook_token='.$this->toyyibToken($bill->reference))
            && str_contains($r['billReturnUrl'], '/my/pay-all/'.$bill->id));
        Http::assertSentCount(1);
        // Single-fee bills are untouched.
        $this->assertNull($this->fees[0]->fresh()->bill_code);
    }

    public function test_member_pays_one_gateway_fee_on_top_when_the_club_passes_it_on(): void
    {
        config(['toyyibpay.charge_to_customer_verified' => true]);
        $this->club->update(['gateway_fee_payer' => 'member']);

        $this->actingAs($this->member)->get(route('member.payments.review-all', $this->club))
            ->assertInertia(fn (Assert $page) => $page->component('Member/ReviewPay')
                ->where('payment', null)
                ->where('group.sumSen', 6000)
                ->where('charges.feeSen', 100)
                ->where('charges.totalSen', 6100)
                ->has('group.fees', 3)
                ->where('group.fees.0.id', $this->fees[0]->id)
                ->where('transfer.reference', 'YURAN-G'.$this->fees[0]->id));

        $bill = $this->payAll();
        $this->assertSame('member', $bill->fee_payer);
        Http::assertSent(fn (HttpRequest $r) => (int) $r['billAmount'] === 6000 && $r['billChargeToCustomer'] == ToyyibPayGateway::BILL_CHARGE_TO_CUSTOMER['member']);
    }

    public function test_member_fee_falls_back_to_the_club_until_toyyibpay_charge_is_verified(): void
    {
        config(['toyyibpay.charge_to_customer_verified' => false]);
        $this->club->update(['gateway_fee_payer' => 'member']);

        $bill = $this->payAll();
        $this->assertSame('club', $bill->fee_payer);
    }

    public function test_billplz_adds_the_gateway_fee_once_for_the_whole_set(): void
    {
        $this->club->update(['payment_gateway' => 'billplz', 'gateway_fee_payer' => 'member']);
        Http::fake(['www.billplz-sandbox.com/api/v3/bills' => Http::response(['id' => 'BPG1', 'url' => 'https://www.billplz-sandbox.com/bills/BPG1'])]);

        $this->actingAs($this->member)->post(route('member.payments.pay-all', $this->club), $this->set())
            ->assertRedirect('https://www.billplz-sandbox.com/bills/BPG1');

        Http::assertSent(fn (HttpRequest $r) => (int) $r['amount'] === 6100);
        $this->assertSame(6000, PaymentBill::where('bill_code', 'BPG1')->value('amount_sen'));
    }

    public function test_an_open_group_bill_for_the_same_set_is_reused(): void
    {
        $bill = $this->payAll('GRP1');

        $this->actingAs($this->member)->post(route('member.payments.pay-all', $this->club), $this->set())
            ->assertRedirect('https://dev.toyyibpay.com/GRP1');

        Http::assertSentCount(1);
        $this->assertSame(1, PaymentBill::whereNull('payment_id')->count());
        $this->assertTrue($bill->fresh()->isOpen());
    }

    /** R2a: pressing pay again on the same group bill restarts its "in progress" window. */
    public function test_reusing_the_group_bill_restamps_when_it_was_opened(): void
    {
        $bill = $this->payAll('GRP1');
        $this->assertNotNull($bill->opened_at);
        $this->travel(2)->days();
        $this->actingAs($this->member)->post(route('member.payments.pay-all', $this->club), $this->set())
            ->assertRedirect('https://dev.toyyibpay.com/GRP1');
        $this->assertTrue($bill->fresh()->opened_at->isSameMinute(now()));

        // 4 days after creation, 2 after the reuse: still in progress.
        $this->travel(2)->days();
        $this->assertSame('processing', \App\Support\Payments\DisplayStatus::of($this->fees[0]->fresh()));
        $this->travel(2)->days();
        $this->assertNotSame('processing', \App\Support\Payments\DisplayStatus::of($this->fees[0]->fresh()));
        // No longer in progress: the review keeps the warning and says to ask the treasurer.
        $this->actingAs($this->member)->get(route('member.payments.review-all', $this->club))
            ->assertInertia(fn (Assert $page) => $page->where('processing', false)->where('hasOpenBill', true));
    }

    /** R2a: a set with a fee being paid through another bill is not billed again. */
    public function test_pay_all_is_refused_while_a_fee_is_in_progress_through_another_bill(): void
    {
        $fee = $this->fees[0];
        $fee->bills()->create(['gateway' => 'toyyibpay', 'bill_code' => 'SINGLE1', 'reference' => 'PAY-'.$fee->id.'-1', 'amount_sen' => $fee->amountSen(), 'opened_at' => now()]);
        $fee->forceFill(['bill_code' => 'SINGLE1', 'gateway' => 'toyyibpay'])->saveQuietly();
        $this->fakeToyyib('GRP1');

        $this->actingAs($this->member)->get(route('member.payments.review-all', $this->club))
            ->assertInertia(fn (Assert $page) => $page->where('processing', true)->where('payAgainBlocked', true));
        $this->actingAs($this->member)->post(route('member.payments.pay-all', $this->club), $this->set())
            ->assertRedirect(route('member.payments.review-all', $this->club))
            ->assertSessionHas('error', 'member.check_status_first');
        Http::assertNothingSent();
        $this->assertSame(0, PaymentBill::whereNull('payment_id')->count());
    }

    public function test_paid_and_verifying_fees_are_left_out_of_the_set(): void
    {
        $this->fees[1]->update(['status' => 'paid', 'paid_date' => now()->toDateString()]);
        $extra = $this->fee($this->club, $this->member, '2026-11-01', 40.00, 'pending_verification');

        $this->actingAs($this->member)->get(route('member.payments.review-all', $this->club))
            ->assertInertia(fn (Assert $page) => $page->where('group.sumSen', 4000)
                ->where('group.paymentIds', [$this->fees[0]->id, $this->fees[2]->id]));

        // Sending a paid or verifying fee in the set is refused.
        $this->actingAs($this->member)->post(route('member.payments.pay-all', $this->club), [
            'payment_ids' => [$this->fees[0]->id, $this->fees[1]->id, $this->fees[2]->id, $extra->id],
            'total_sen' => 10000,
        ])->assertRedirect(route('member.payments.review-all', $this->club))->assertSessionHas('error', 'member.set_changed');
        $this->assertSame(0, PaymentBill::count());
    }

    public function test_a_set_that_changed_since_the_review_is_refused(): void
    {
        $set = $this->set();
        $this->fees[1]->update(['amount' => 25.00]);
        Http::fake();

        $this->actingAs($this->member)->post(route('member.payments.pay-all', $this->club), $set)
            ->assertRedirect(route('member.payments.review-all', $this->club))
            ->assertSessionHas('error', 'member.set_changed');

        // A new fee added by the treasurer also changes the set.
        $this->fee($this->club, $this->member, '2026-12-01', 5.00);
        $this->actingAs($this->member)->post(route('member.payments.pay-all', $this->club), $this->set())
            ->assertSessionHas('error', 'member.set_changed');
        Http::assertNothingSent();
    }

    public function test_with_one_open_fee_the_review_falls_back_to_the_single_flow(): void
    {
        $this->fees[1]->update(['status' => 'paid', 'paid_date' => now()->toDateString()]);
        $this->fees[2]->update(['status' => 'paid', 'paid_date' => now()->toDateString()]);

        $this->actingAs($this->member)->get(route('member.payments.review-all', $this->club))
            ->assertRedirect(route('member.payments.review', $this->fees[0]));
    }

    public function test_pay_all_refuses_while_another_request_holds_the_member_lock(): void
    {
        Http::fake();
        $lock = Cache::lock("pay-all:{$this->club->id}:{$this->member->id}", 15);
        $lock->get();

        $this->actingAs($this->member)->post(route('member.payments.pay-all', $this->club), $this->set())
            ->assertRedirect(route('member.payments.review-all', $this->club))
            ->assertSessionHas('error', 'member.try_again');

        Http::assertNothingSent();
        $this->assertSame(0, PaymentBill::count());
        $lock->release();
    }

    // ── Access ───────────────────────────────────────────────────────────

    public function test_other_members_and_clubs_are_refused(): void
    {
        $bill = $this->payAll();
        $other = $this->join(User::factory()->create(['role' => 'member']), $this->club);
        $otherClub = $this->makeClub(['name' => 'Kelab Lain']);

        $this->actingAs($other)->get(route('member.payments.group-result', $bill))->assertForbidden();
        $this->actingAs($other)->get(route('member.payments.group-status', $bill))->assertForbidden();
        // Another member's fees are never in "my" set.
        $this->actingAs($other)->post(route('member.payments.pay-all', $this->club), $this->set())
            ->assertSessionHas('error', 'member.set_changed');
        // Not a member of that club at all.
        $this->actingAs($this->member)->get(route('member.payments.review-all', $otherClub))->assertForbidden();
        $this->actingAs($this->member)->post(route('member.payments.pay-all', $otherClub), $this->set())->assertForbidden();
        $this->actingAs($this->member)->post(route('member.payments.transfer-all', $otherClub), $this->set() + [
            'files' => [UploadedFile::fake()->image('slip.png')],
            'transfer_date' => now()->toDateString(),
        ])->assertForbidden();
        // A single-fee bill is not a group page.
        $single = $this->fees[0]->bills()->create(['gateway' => 'toyyibpay', 'bill_code' => 'ONE', 'reference' => 'PAY-ONE', 'amount_sen' => 1000]);
        $this->actingAs($this->member)->get(route('member.payments.group-result', $single))->assertForbidden();
    }

    // ── Webhook settlement ───────────────────────────────────────────────

    public function test_a_confirmed_callback_settles_every_fee_in_one_go(): void
    {
        $bill = $this->payAll();
        $this->fakeToyyibConfirm();

        $this->webhook($bill, ['transaction_id' => 'TXN-G1', 'amount' => '60.00'])->assertOk();

        $this->assertSame(['paid', 'paid', 'paid'], $this->statuses());
        foreach ($this->fees as $i => $fee) {
            $fee = $fee->fresh();
            $this->assertSame('TXN-G1', $fee->transaction_id);
            $this->assertSame('toyyibpay', $fee->paid_via);
            // One bill, one gateway fee: absorbed once, on the oldest fee.
            $this->assertSame($i === 0 ? 100 : 0, $fee->fee_absorbed_sen);
        }
        $bill->refresh();
        $this->assertNotNull($bill->paid_at);
        $this->assertSame('TXN-G1', $bill->transaction_id);
        $this->assertSame(3, AuditLog::where('action', 'payment.paid_via_webhook')->count());
        Mail::assertSent(PaymentConfirmation::class, 1);
        Mail::assertSent(PaymentConfirmation::class, fn ($m) => $m->fees?->count() === 3);

        // A replay of the same transaction changes nothing.
        $this->webhook($bill, ['transaction_id' => 'TXN-G1', 'amount' => '60.00'])->assertOk();
        Mail::assertSent(PaymentConfirmation::class, 1);

        // The result page shows one receipt with a line per fee.
        // Opened from the gateway's return (with its query string): "Kongsi" still shares the clean receipt URL.
        $this->actingAs($this->member)->get(route('member.payments.group-result', $bill).'?status_id=1&billcode=X')
            ->assertInertia(fn (Assert $page) => $page->component('Member/PayResult')
                ->where('outcome', 'paid')
                ->where('receiptUrl', route('member.payments.group-result', $bill))
                ->where('receipt.amount', 6000)
                ->has('receipt.lines', 3)
                // One bill, one receipt number, shared by its fees (settings phase 2).
                ->where('receipt.receiptNo', $bill->receipt_no)
                ->where('receipt.lines.0.receiptNo', $bill->receipt_no)
                ->where('receipt.lines.0.invoiceNo', 'INV-'.str_pad((string) $this->fees[0]->id, 6, '0', STR_PAD_LEFT)));
        $this->assertMatchesRegularExpression('/\ARCP-\d{4}-\d{4}\z/', (string) $bill->receipt_no);
        $this->getJson(route('member.payments.group-status', $bill))->assertJson(['status' => 'paid']);
    }

    public function test_an_underpaid_callback_settles_nothing(): void
    {
        $bill = $this->payAll();
        $this->fakeToyyibConfirm();

        $this->webhook($bill, ['amount' => '30.00'])->assertOk();

        $this->assertSame(['overdue', 'pending', 'pending'], $this->statuses());
        $this->assertNull($bill->fresh()->paid_at);
        $this->assertSame(1, AuditLog::where('action', 'payment.underpaid_webhook')->count());
        Mail::assertNothingSent();
    }

    public function test_a_gateway_confirming_less_than_the_bill_settles_nothing(): void
    {
        $bill = $this->payAll();
        $this->fakeToyyibConfirm('59.99');

        $this->webhook($bill, ['amount' => '60.00'])->assertOk();

        $this->assertSame(['overdue', 'pending', 'pending'], $this->statuses());
    }

    public function test_the_group_token_is_an_hmac_of_the_group_reference(): void
    {
        $bill = $this->payAll();
        $this->fakeToyyibConfirm();

        // A token for another reference (e.g. a fee's own bill) fails for this bill.
        $this->webhook($bill, [], $this->toyyibToken('PAY-'.$this->fees[0]->id.'-1'))->assertForbidden();
        $this->webhook($bill, [], 'not-a-token')->assertForbidden();
        $this->assertSame(['overdue', 'pending', 'pending'], $this->statuses());

        $this->webhook($bill)->assertOk();
        $this->assertSame(['paid', 'paid', 'paid'], $this->statuses());
    }

    public function test_billplz_group_callback_is_checked_with_the_club_signature_key(): void
    {
        $this->club->update(['payment_gateway' => 'billplz']);
        Http::fake(['www.billplz-sandbox.com/api/v3/bills' => Http::response(['id' => 'BPG2', 'url' => 'https://www.billplz-sandbox.com/bills/BPG2'])]);
        $this->actingAs($this->member)->post(route('member.payments.pay-all', $this->club), $this->set());
        $bill = PaymentBill::where('bill_code', 'BPG2')->firstOrFail();
        auth()->logout();

        $payload = ['id' => 'BPG2', 'paid' => 'true', 'paid_amount' => '6000', 'reference_1' => $bill->reference, 'transaction_id' => 'BPTXN1', 'state' => 'paid'];
        $this->post(route('webhook.billplz'), $payload + ['x_signature' => 'forged'])->assertForbidden();
        $this->post(route('webhook.billplz'), $payload + ['x_signature' => BillplzSignature::compute($payload, $this->billplzKey)])->assertOk();

        $this->assertSame(['paid', 'paid', 'paid'], $this->statuses());
    }

    public function test_a_fee_edited_mid_flow_supersedes_the_bill_and_a_late_payment_is_flagged_not_applied(): void
    {
        $this->fakeToyyib('GRP1', 'GRP2');
        $bill = $this->payAll('GRP1', fake: false);
        $this->fees[1]->update(['amount' => 25.00]); // the treasurer edits one fee

        $this->assertTrue($bill->fresh()->isSuperseded());

        $this->fakeToyyibConfirm();
        $this->webhook($bill, ['transaction_id' => 'TXN-LATE', 'amount' => '60.00'])->assertOk();
        $this->webhook($bill, ['transaction_id' => 'TXN-LATE', 'amount' => '60.00'])->assertOk(); // retry

        // Never partly applied: every fee stays owed.
        $this->assertSame(['overdue', 'pending', 'pending'], $this->statuses());
        $flags = AuditLog::where('action', 'payment.group_bill_refund')->get();
        $this->assertCount(1, $flags);
        $this->assertSame('GRP1', $flags[0]->new_values['bill_code']);
        Mail::assertNothingSent();

        // The member gets a fresh bill for the new set.
        $this->actingAs($this->member)->post(route('member.payments.pay-all', $this->club), $this->set())
            ->assertRedirect('https://dev.toyyibpay.com/GRP2');
    }

    public function test_a_fee_paid_elsewhere_supersedes_the_bill_and_a_late_payment_is_flagged(): void
    {
        $bill = $this->payAll();
        $this->fees[0]->update(['status' => 'paid', 'paid_date' => now()->toDateString(), 'paid_via' => 'cash']);
        $this->assertTrue($bill->fresh()->isSuperseded());

        $this->fakeToyyibConfirm();
        $this->webhook($bill, ['amount' => '60.00'])->assertOk();

        $this->assertSame(['paid', 'pending', 'pending'], $this->statuses());
        $this->assertSame(1, AuditLog::where('action', 'payment.group_bill_refund')->count());
    }

    public function test_a_superseded_bill_whose_fees_are_all_still_owed_settles(): void
    {
        $bill = $this->payAll();
        // Replaced by a newer group bill (e.g. the club switched gateway), nothing else changed.
        $bill->forceFill(['superseded_at' => now()])->save();

        $this->fakeToyyibConfirm();
        $this->webhook($bill, ['amount' => '60.00'])->assertOk();

        $this->assertSame(['paid', 'paid', 'paid'], $this->statuses());
    }

    public function test_a_settled_group_bill_paid_again_is_flagged_once_for_a_refund(): void
    {
        $bill = $this->payAll();
        $this->fakeToyyibConfirm(null, [], 2);
        $this->webhook($bill, ['transaction_id' => 'TXN-1', 'amount' => '60.00'])->assertOk();
        $this->webhook($bill, ['transaction_id' => 'TXN-2', 'amount' => '60.00'])->assertOk();
        $this->webhook($bill, ['transaction_id' => 'TXN-2', 'amount' => '60.00'])->assertOk();
        // A third transaction the gateway does not report is not flagged.
        $this->webhook($bill, ['transaction_id' => 'TXN-3', 'amount' => '60.00'])->assertOk();

        $this->assertSame(1, AuditLog::where('action', 'payment.gateway_paid_twice')->count());
        $this->assertSame('TXN-1', $this->fees[0]->fresh()->transaction_id);
    }

    public function test_a_fee_slip_sent_on_its_own_supersedes_the_group_bill(): void
    {
        $bill = $this->payAll();

        $this->actingAs($this->member)->post(route('member.payments.transfer', $this->fees[2]), [
            'files' => [UploadedFile::fake()->image('slip.png')],
            'transfer_date' => now()->toDateString(),
        ])->assertSessionHasNoErrors();

        $this->assertTrue($bill->fresh()->isSuperseded());
    }

    // ── Transfer: one slip for the set ───────────────────────────────────

    private function sendGroupSlip(): PaymentBill
    {
        $this->actingAs($this->member)->post(route('member.payments.transfer-all', $this->club), $this->set() + [
            'files' => [UploadedFile::fake()->image('slip.png', 600, 800)],
            'transfer_date' => now()->subDay()->toDateString(),
            'transfer_ref' => 'MBB 991',
        ])->assertSessionHasNoErrors();
        auth()->logout();

        return PaymentBill::where('method', 'transfer')->latest('id')->firstOrFail();
    }

    public function test_one_slip_moves_every_fee_to_verification_together(): void
    {
        $group = $this->sendGroupSlip();

        $this->assertSame('YURAN-G'.$this->fees[0]->id, $group->reference);
        $this->assertSame(6000, $group->amount_sen);
        $this->assertSame(['pending_verification', 'pending_verification', 'pending_verification'], $this->statuses());
        $this->assertSame(1, Attachment::where('attachable_type', PaymentBill::class)->where('attachable_id', $group->id)->whereNotNull('frozen_at')->count());
        foreach ($this->fees as $fee) {
            $this->assertSame('YURAN-G'.$this->fees[0]->id, $fee->fresh()->transferReference());
            $this->assertCount(1, $fee->fresh()->currentSlips()->get());
        }

        $this->actingAs($this->member)->get(route('member.payments.group-result', $group))
            ->assertInertia(fn (Assert $page) => $page->where('outcome', 'verifying')->has('verification.slips', 1)->has('group.fees', 3));
        // The member can open their own group slip.
        $slip = Attachment::where('attachable_type', PaymentBill::class)->firstOrFail();
        $this->actingAs($this->member)->get(route('member.payments.slip', $slip))->assertOk();
        $other = $this->join(User::factory()->create(['role' => 'member']), $this->club);
        $this->actingAs($other)->get(route('member.payments.slip', $slip))->assertForbidden();
    }

    public function test_admin_dashboard_lists_a_group_slip_as_one_row_with_its_total(): void
    {
        $this->sendGroupSlip();
        $admin = $this->join(User::factory()->create(['role' => 'admin']), $this->club, 'admin');

        $this->actingAs($admin)->get(route('admin.clubs.dashboard', $this->club))
            ->assertInertia(fn (Assert $page) => $page->component('Admin/Dashboard')
                ->where('slips.count', 1)
                ->has('slips.items', 1)
                ->where('slips.items.0.id', $this->fees[0]->id)
                ->where('slips.items.0.feeCount', 3)
                ->where('slips.items.0.amount', 6000)
                // The sidebar badge counts slips too: one for the three-fee set.
                ->where('nav.groups.0.items.2.key', 'verifications')
                ->where('nav.groups.0.items.2.badge', 1));
    }

    public function test_admin_approves_the_whole_set_at_once(): void
    {
        $group = $this->sendGroupSlip();
        $admin = $this->join(User::factory()->create(['role' => 'admin']), $this->club, 'admin');

        $this->actingAs($admin)->get(route('admin.clubs.verifications.index', $this->club))
            ->assertInertia(fn (Assert $page) => $page->component('Admin/Verifications/Index')
                ->has('pending', 1)
                ->where('pending.0.group.totalSen', 6000)
                ->has('pending.0.group.fees', 3)
                ->where('pending.0.expectedRef', 'YURAN-G'.$this->fees[0]->id)
                ->has('pending.0.slips', 1));

        // Paid date before the transfer date is refused for the set.
        $this->actingAs($admin)->post(route('admin.verifications.approve', $this->fees[1]), ['paid_date' => now()->subDays(3)->toDateString()])
            ->assertSessionHasErrors('paid_date');
        $this->assertSame(['pending_verification', 'pending_verification', 'pending_verification'], $this->statuses());

        // Approving through any fee of the set approves all of it.
        $this->actingAs($admin)->post(route('admin.verifications.approve', $this->fees[1]), ['paid_date' => now()->toDateString(), 'reference' => 'BANK-1'])
            ->assertSessionHasNoErrors();

        $this->assertSame(['paid', 'paid', 'paid'], $this->statuses());
        foreach ($this->fees as $fee) {
            $this->assertSame('transfer', $fee->fresh()->paid_via);
            $this->assertSame($admin->id, $fee->fresh()->verified_by);
        }
        $this->assertNotNull($group->fresh()->paid_at);
        $this->assertSame(3, AuditLog::where('action', 'payment.transfer_approved')->count());
        $this->assertAuditNamesGroupSlips('payment.transfer_approved', $group);
        Mail::assertSent(PaymentConfirmation::class, 1);

        $this->actingAs($this->member)->get(route('member.payments.group-result', $group))
            ->assertInertia(fn (Assert $page) => $page->where('outcome', 'paid')->where('receipt.method', 'transfer')->has('receipt.lines', 3));
    }

    /** Every fee's audit row names the group slip files (they live on the PaymentBill). */
    private function assertAuditNamesGroupSlips(string $action, PaymentBill $group): void
    {
        $expected = $group->attachments()->where('kind', 'proof_of_payment')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertNotEmpty($expected);
        $rows = AuditLog::where('action', $action)->get();
        $this->assertCount(3, $rows);
        foreach ($rows as $row) {
            $this->assertSame($expected, array_column($row->new_values['slips'], 'id'));
            $this->assertNotEmpty($row->new_values['slips'][0]['sha256']);
        }
    }

    public function test_admin_rejects_the_whole_set_at_once(): void
    {
        $group = $this->sendGroupSlip();
        $admin = $this->join(User::factory()->create(['role' => 'admin']), $this->club, 'admin');

        $this->actingAs($admin)->post(route('admin.verifications.reject', $this->fees[2]), ['reason' => 'Amaun pada slip tidak sama'])
            ->assertSessionHasNoErrors();

        $this->assertSame(['pending', 'pending', 'pending'], $this->statuses());
        foreach ($this->fees as $fee) {
            $this->assertSame('Amaun pada slip tidak sama', $fee->fresh()->rejection_reason);
        }
        $this->assertTrue($group->fresh()->isSuperseded());
        // Rejecting clears transfer_group_id: the slips must still be named (read before the change).
        $this->assertAuditNamesGroupSlips('payment.transfer_rejected', $group);
        Mail::assertSent(TransferRejected::class, 1);

        // The member sees the reason and can pay the set again.
        $this->actingAs($this->member)->get(route('member.payments.review-all', $this->club))
            ->assertInertia(fn (Assert $page) => $page->where('rejection.reason', 'Amaun pada slip tidak sama')->has('group.fees', 3));
        $this->actingAs($this->member)->get(route('member.payments.group-result', $group))
            ->assertRedirect(route('member.payments.review-all', $this->club));
        // A rejected set's reference no longer applies to the fees.
        $this->assertSame('YURAN-'.$this->fees[0]->id, $this->fees[0]->fresh()->transferReference());
    }

    public function test_an_admin_may_not_decide_a_set_that_holds_their_own_fee(): void
    {
        // The member is also an admin of the club.
        $this->club->members()->updateExistingPivot($this->member->id, ['role' => 'admin']);
        $this->member->update(['role' => 'admin']);
        $this->sendGroupSlip();

        $this->actingAs($this->member)->post(route('admin.verifications.approve', $this->fees[0]), ['paid_date' => now()->toDateString()])
            ->assertForbidden();
        $this->actingAs($this->member)->post(route('admin.verifications.reject', $this->fees[2]), ['reason' => 'Saya sendiri'])
            ->assertForbidden();
        $this->assertSame(['pending_verification', 'pending_verification', 'pending_verification'], $this->statuses());

        $other = $this->join(User::factory()->create(['role' => 'admin']), $this->club, 'admin');
        $this->actingAs($other)->post(route('admin.verifications.approve', $this->fees[0]), ['paid_date' => now()->toDateString()])
            ->assertSessionHasNoErrors();
        $this->assertSame(['paid', 'paid', 'paid'], $this->statuses());
    }

    public function test_an_admin_of_another_club_cannot_decide_the_set(): void
    {
        $this->sendGroupSlip();
        $otherClub = $this->makeClub(['name' => 'Kelab Lain']);
        $outsider = $this->join(User::factory()->create(['role' => 'admin']), $otherClub, 'admin');

        $this->actingAs($outsider)->post(route('admin.verifications.approve', $this->fees[0]), ['paid_date' => now()->toDateString()])
            ->assertForbidden();
        $this->assertSame(['pending_verification', 'pending_verification', 'pending_verification'], $this->statuses());
    }

    public function test_a_fee_paid_online_while_the_group_slip_is_checked_is_flagged_with_its_share_and_the_rest_stays_approvable(): void
    {
        $group = $this->sendGroupSlip();
        // The member also pays fee B on its own at the gateway.
        $this->fees[1]->forceFill(['bill_code' => null])->save();
        $single = $this->fees[1]->bills()->create(['gateway' => 'toyyibpay', 'bill_code' => 'ONEB', 'reference' => 'PAY-B-1', 'amount_sen' => 2000, 'fee_payer' => 'club', 'fee_sen' => 100]);
        $this->fakeToyyibConfirm();
        $this->post(route('webhook.toyyibpay').'?webhook_token='.$this->toyyibToken('PAY-B-1'), [
            'billcode' => 'ONEB', 'order_id' => 'PAY-B-1', 'status_id' => 1, 'transaction_id' => 'TXN-B', 'amount' => '20.00',
        ])->assertOk();

        $this->assertSame(['pending_verification', 'paid', 'pending_verification'], $this->statuses());
        $flag = AuditLog::where('action', 'payment.gateway_paid_during_verification')->sole();
        $this->assertStringContainsString('refund RM 20.00', $flag->description);
        $this->assertStringContainsString($group->reference, $flag->description);
        $this->assertSame(2000, $flag->new_values['refund_sen']);

        $admin = $this->join(User::factory()->create(['role' => 'admin']), $this->club, 'admin');
        $this->actingAs($admin)->get(route('admin.clubs.verifications.index', $this->club))
            ->assertInertia(fn (Assert $page) => $page->has('pending', 1)
                ->where('pending.0.group.approveSen', 4000)
                ->where('pending.0.group.paidOnlineSen', 2000));

        $this->actingAs($admin)->post(route('admin.verifications.approve', $this->fees[0]), ['paid_date' => now()->toDateString()])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', fn ($m) => str_contains($m, '2 fees are now paid'));
        $this->assertSame(['paid', 'paid', 'paid'], $this->statuses());
        $this->assertSame('toyyibpay', $this->fees[1]->fresh()->paid_via);
        $this->assertSame('transfer', $this->fees[2]->fresh()->paid_via);
        $this->assertNotNull($group->fresh()->paid_at);
        Mail::assertSent(PaymentConfirmation::class, fn ($m) => $m->group?->is($group) && $m->fees->count() === 2);

        // The slip receipt covers only what the slip settled; fee B is noted as paid online.
        $this->actingAs($this->member)->get(route('member.payments.group-result', $group))
            ->assertInertia(fn (Assert $page) => $page->where('outcome', 'paid')
                ->where('receipt.amount', 4000)
                ->has('receipt.lines', 2)
                ->where('receipt.lines.0.id', $this->fees[0]->id)
                ->where('receipt.lines.1.id', $this->fees[2]->id)
                ->has('receipt.paidOnline', 1)
                ->where('receipt.paidOnline.0.id', $this->fees[1]->id));
    }

    public function test_a_fee_reverted_and_sent_on_its_own_slip_is_never_decided_with_the_group(): void
    {
        $group = $this->sendGroupSlip();
        $admin = $this->join(User::factory()->create(['role' => 'admin']), $this->club, 'admin');
        // Fee A paid online mid-check, then the admin cancels that payment and the member sends a slip for A alone.
        $this->fees[0]->forceFill(['status' => 'paid', 'paid_via' => 'toyyibpay', 'paid_date' => now()->toDateString()])->save();
        $a = $this->fees[0]->fresh();
        $this->actingAs($admin)->post(route('admin.payments.cancel', $a), ['reason' => 'refunded', 'confirm' => '1'])->assertSessionHasNoErrors();
        $this->assertSame(1, \App\Models\PaymentCancellation::count(), 'only A: the slip set is not paid');
        $this->travel(1)->minutes();
        $this->actingAs($this->member)->post(route('member.payments.transfer', $a), [
            'files' => [UploadedFile::fake()->image('a.png')],
            'transfer_date' => now()->toDateString(),
        ])->assertSessionHasNoErrors();
        $this->assertNull($this->fees[0]->fresh()->transfer_group_id);

        // Two cards: A's own slip, and the group.
        $this->actingAs($admin)->get(route('admin.clubs.verifications.index', $this->club))
            ->assertInertia(fn (Assert $page) => $page->has('pending', 2));

        // The group can't be approved past A, and rejecting it leaves A's own slip alone.
        $this->actingAs($admin)->post(route('admin.verifications.approve', $this->fees[1]), ['paid_date' => now()->toDateString()])
            ->assertSessionHasErrors('status');
        $this->assertSame(['pending_verification', 'pending_verification', 'pending_verification'], $this->statuses());
        $this->actingAs($admin)->post(route('admin.verifications.reject', $this->fees[1]), ['reason' => 'Set lama, hantar semula'])
            ->assertSessionHasNoErrors();
        $this->assertSame(['pending_verification', 'pending', 'pending'], $this->statuses());
        $this->assertNull($this->fees[0]->fresh()->rejection_reason);
        $this->assertTrue($group->fresh()->isSuperseded());

        // A's own slip is still decided on its own.
        $this->actingAs($admin)->post(route('admin.verifications.approve', $this->fees[0]), ['paid_date' => now()->toDateString()])
            ->assertSessionHasNoErrors();
        $this->assertSame(['paid', 'pending', 'pending'], $this->statuses());
    }

    public function test_a_group_bill_paid_late_while_the_same_fees_are_under_a_group_slip_is_flagged_not_applied(): void
    {
        $bill = $this->payAll();
        $slip = $this->sendGroupSlip();
        $this->assertTrue($bill->fresh()->isSuperseded());

        $this->fakeToyyibConfirm();
        $this->webhook($bill, ['transaction_id' => 'TXN-LATE-G', 'amount' => '60.00'])->assertOk();

        $this->assertSame(['pending_verification', 'pending_verification', 'pending_verification'], $this->statuses());
        $this->assertNull($bill->fresh()->paid_at);
        $this->assertTrue($slip->fresh()->isOpen());
        $this->assertSame(1, AuditLog::where('action', 'payment.group_bill_refund')->count());
        Mail::assertNothingSent();
    }

    public function test_a_set_with_one_fee_left_can_still_be_rejected_and_the_email_keeps_the_group(): void
    {
        $group = $this->sendGroupSlip();
        $this->fees[0]->forceFill(['status' => 'paid', 'paid_via' => 'toyyibpay', 'paid_date' => now()->toDateString()])->save();
        $this->fees[1]->forceFill(['status' => 'paid', 'paid_via' => 'toyyibpay', 'paid_date' => now()->toDateString()])->save();
        $admin = $this->join(User::factory()->create(['role' => 'admin']), $this->club, 'admin');

        $this->actingAs($admin)->post(route('admin.verifications.reject', $this->fees[2]), ['reason' => 'Slip tidak jelas langsung'])
            ->assertSessionHasNoErrors();

        $this->assertSame(['paid', 'paid', 'pending'], $this->statuses());
        Mail::assertSent(TransferRejected::class, function (TransferRejected $m) use ($group) {
            $html = $m->render();

            return $m->group?->is($group) && $m->fees?->count() === 1
                && str_contains($html, $group->reference) && str_contains($html, 'Jumlah / Total');
        });
    }

    public function test_a_set_where_a_fee_changed_otherwise_cannot_be_approved(): void
    {
        $this->sendGroupSlip();
        // Not a gateway payment (e.g. changed directly in the database): approve is refused.
        $this->fees[0]->forceFill(['status' => 'pending'])->save();
        $admin = $this->join(User::factory()->create(['role' => 'admin']), $this->club, 'admin');

        $this->actingAs($admin)->post(route('admin.verifications.approve', $this->fees[1]), ['paid_date' => now()->toDateString()])
            ->assertSessionHasErrors('status');
        $this->assertSame(['pending', 'pending_verification', 'pending_verification'], $this->statuses());
    }

    public function test_a_fee_in_a_group_slip_cannot_be_deleted(): void
    {
        $this->sendGroupSlip();
        $admin = $this->join(User::factory()->create(['role' => 'admin']), $this->club, 'admin');
        $this->actingAs($admin)->post(route('admin.verifications.approve', $this->fees[1]), ['paid_date' => now()->toDateString()])
            ->assertSessionHasNoErrors();

        $this->actingAs($admin)->delete(route('admin.payments.destroy', $this->fees[1]))->assertSessionHas('error');
        $this->assertNotNull(Payment::find($this->fees[1]->id));

        // The model refuses too, whatever the caller: the set stays as evidence.
        $this->expectException(\LogicException::class);
        $this->fees[1]->fresh()->delete();
    }

    public function test_a_fee_in_a_gateway_group_bill_can_still_be_deleted_and_supersedes_it(): void
    {
        $bill = $this->payAll();
        $admin = $this->join(User::factory()->create(['role' => 'admin']), $this->club, 'admin');

        $this->actingAs($admin)->delete(route('admin.payments.destroy', $this->fees[2]))->assertSessionHas('success');

        $this->assertNull(Payment::find($this->fees[2]->id));
        $this->assertTrue($bill->fresh()->isSuperseded());
    }

    public function test_a_settled_group_no_longer_reads_paid_once_an_admin_reverts_one_fee(): void
    {
        $bill = $this->payAll();
        $this->fakeToyyibConfirm();
        $this->webhook($bill, ['transaction_id' => 'TXN-G2', 'amount' => '60.00'])->assertOk();
        $this->assertSame(['paid', 'paid', 'paid'], $this->statuses());

        // An admin later reverts one fee to pending.
        $this->fees[1]->fresh()->forceFill(['status' => 'pending', 'paid_date' => null])->save();

        $this->actingAs($this->member)->get(route('member.payments.group-result', $bill))
            ->assertRedirect(route('member.payments.index', $this->club));
        $this->getJson(route('member.payments.group-status', $bill))->assertJson(['status' => 'pending']);
    }

    public function test_after_a_revert_a_single_slip_no_longer_uses_the_old_group(): void
    {
        $group = $this->sendGroupSlip();
        $admin = $this->join(User::factory()->create(['role' => 'admin']), $this->club, 'admin');
        $this->actingAs($admin)->post(route('admin.verifications.approve', $this->fees[0]), ['paid_date' => now()->toDateString()])
            ->assertSessionHasNoErrors();
        $f = $this->fees[0]->fresh();
        $this->assertSame($group->id, $f->transferGroup()?->id);

        // "Cancel payment" takes back the whole slip set (one receipt), and clears the group link on each fee.
        $this->actingAs($admin)->post(route('admin.payments.cancel', $f), ['reason' => 'money_not_received', 'confirm' => '1', 'note' => 'x'])
            ->assertSessionHasNoErrors();
        $this->assertNull($f->fresh()->transfer_group_id);
        $this->assertSame(['pending', 'pending', 'pending'], $this->statuses());

        $this->travel(5)->minutes();
        $this->actingAs($this->member)->post(route('member.payments.transfer', $f), [
            'files' => [UploadedFile::fake()->image('s2.png', 600, 800)],
            'transfer_date' => now()->toDateString(),
        ])->assertSessionHasNoErrors();

        $f = $f->fresh();
        $this->assertSame('pending_verification', $f->status);
        $this->assertNull($f->transferGroup());
        $this->assertSame('YURAN-'.$f->id, $f->transferReference());
        $this->assertSame(1, $f->currentSlips()->count());

        $this->actingAs($admin)->get(route('admin.clubs.verifications.index', $this->club))
            ->assertInertia(fn (Assert $page) => $page->has('pending', 1)
                ->where('pending.0.group', null)
                ->where('pending.0.expectedRef', 'YURAN-'.$f->id)
                ->has('pending.0.slips', 1));
    }

    public function test_single_review_warns_and_links_when_a_group_bill_holds_the_fee(): void
    {
        $bill = $this->payAll();

        $this->actingAs($this->member)->get(route('member.payments.review', $this->fees[1]))
            ->assertInertia(fn (Assert $page) => $page->where('hasOpenBill', true)
                ->where('openBillUrl', route('member.payments.group-result', $bill)));

        $this->fees[1]->update(['amount' => 21.00]); // supersedes the group bill
        $this->actingAs($this->member)->get(route('member.payments.review', $this->fees[1]))
            ->assertInertia(fn (Assert $page) => $page->where('hasOpenBill', false)->where('openBillUrl', null));
    }

    public function test_a_group_slip_respects_the_slip_limit_across_its_history(): void
    {
        // Fee B already has slips up to the limit (rejected ones are kept).
        $svc = app(\App\Services\AttachmentService::class);
        $this->actingAs($this->member);
        for ($i = 0; $i < \App\Services\TransferVerificationService::MAX_SLIPS_TOTAL; $i++) {
            $svc->store($this->fees[1], $this->club->id, UploadedFile::fake()->image("old{$i}.png"), 'proof_of_payment');
        }

        $this->post(route('member.payments.transfer-all', $this->club), $this->set() + [
            'files' => [UploadedFile::fake()->image('slip.png')],
            'transfer_date' => now()->toDateString(),
        ])->assertSessionHasErrors(['files' => 'member.slip_limit']);
        $this->assertSame(['overdue', 'pending', 'pending'], $this->statuses());
        $this->assertSame(0, PaymentBill::count());
    }

    public function test_a_stale_set_cannot_be_sent_by_transfer(): void
    {
        $set = $this->set();
        $this->fees[2]->update(['amount' => 35.00]);

        $this->actingAs($this->member)->post(route('member.payments.transfer-all', $this->club), $set + [
            'files' => [UploadedFile::fake()->image('slip.png')],
            'transfer_date' => now()->toDateString(),
        ])->assertSessionHasErrors('status');

        $this->assertSame(['overdue', 'pending', 'pending'], $this->statuses());
        $this->assertSame(0, Attachment::count());
    }

    // ── Pages ────────────────────────────────────────────────────────────

    public function test_home_offers_pay_all_from_two_open_fees(): void
    {
        $this->actingAs($this->member)->get(route('member.dashboard'))
            ->assertInertia(fn (Assert $page) => $page->component('Member/Home')
                ->where('payAll.totalSen', 6000)
                ->where('payAll.count', 3)
                ->where('payAll.url', route('member.payments.review-all', $this->club))
                ->where('upcoming.0.id', $this->fees[0]->id));

        $this->fees[1]->update(['status' => 'paid', 'paid_date' => now()->toDateString()]);
        $this->fees[2]->update(['status' => 'paid', 'paid_date' => now()->toDateString()]);
        $this->actingAs($this->member)->get(route('member.dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('payAll', null));
    }

    public function test_payments_page_offers_pay_all(): void
    {
        $this->actingAs($this->member)->get(route('member.payments.index', ['club' => $this->club, 'year' => 2026]))
            ->assertInertia(fn (Assert $page) => $page->component('Member/Payments')->where('payAll.count', 3));
    }
}
