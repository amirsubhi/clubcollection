<?php

namespace Tests\Feature;

use App\Mail\PaymentConfirmation;
use App\Models\AuditLog;
use App\Models\Club;
use App\Models\Payment;
use App\Models\PaymentBill;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Support\Payments\BillplzSignature;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * S1 hardening of the gateway callbacks: per-bill tokens, the confirm step,
 * superseded bills, transaction dedupe, gateway match, log hygiene and the
 * pay() lock.
 */
class WebhookHardeningTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'toyyibpay.webhook_secret' => self::TOYYIB_SECRET,
            'toyyibpay.base_url' => 'https://dev.toyyibpay.com',
        ]);
        Mail::fake();
    }

    private function club(array $attrs = []): Club
    {
        return Club::factory()->create($attrs + [
            'payment_gateway' => 'toyyibpay',
            'toyyibpay_secret_key' => 'club-secret',
            'toyyibpay_category_code' => 'cat-123',
        ]);
    }

    private function member(Club $club): User
    {
        $member = User::factory()->create(['role' => 'member']);
        $club->members()->attach($member, ['role' => 'member', 'job_level' => 'executive', 'joined_date' => now()->toDateString(), 'is_active' => true]);

        return $member;
    }

    private function pending(array $attrs = [], ?Club $club = null): Payment
    {
        $club ??= $this->club();

        return Payment::factory()->forClub($club)->forMember($this->member($club))->pending()->create($attrs + [
            'bill_code' => 'BILL-'.uniqid(),
            'reference' => 'PAY-'.uniqid(),
            'gateway' => 'toyyibpay',
        ]);
    }

    private function toyyibCallback(string $billCode, string $reference, array $extra = [], ?string $token = null)
    {
        return $this->post(route('webhook.toyyibpay').'?webhook_token='.($token ?? $this->toyyibToken($reference)), $extra + [
            'billcode' => $billCode,
            'order_id' => $reference,
            'status_id' => 1,
            'transaction_id' => 'TXN-'.uniqid(),
        ]);
    }

    // ── Callback URLs and the secret ─────────────────────────────────────

    public function test_forged_host_does_not_change_the_callback_url_in_production(): void
    {
        config(['app.url' => 'https://club.example.test']);
        $this->app['env'] = 'production';
        (new AppServiceProvider($this->app))->boot();
        $this->app['env'] = 'testing';

        Http::fake(['*/index.php/api/createBill' => Http::response([['BillCode' => 'HOSTBILL']])]);
        $club = $this->club();
        $member = $this->member($club);
        $payment = Payment::factory()->forClub($club)->forMember($member)->pending()->create();
        $this->actingAs($member);

        $this->post('http://evil.example/my/payments/'.$payment->id.'/pay', [], ['X-Forwarded-Host' => 'evil.example']);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'createBill')) {
                return false;
            }

            return str_starts_with($request['billCallbackUrl'], 'https://club.example.test/webhook/toyyibpay?webhook_token=')
                && str_starts_with($request['billReturnUrl'], 'https://club.example.test/');
        });
    }

    public function test_the_callback_token_is_a_per_bill_hmac_of_the_reference(): void
    {
        Http::fake(['*/index.php/api/createBill' => Http::response([['BillCode' => 'TOKBILL']])]);
        $club = $this->club();
        $member = $this->member($club);
        $payment = Payment::factory()->forClub($club)->forMember($member)->pending()->create();
        $this->actingAs($member)->post(route('member.payments.pay', $payment));

        $reference = $payment->fresh()->reference;
        Http::assertSent(fn ($r) => str_contains($r->url(), 'createBill')
            && str_ends_with($r['billCallbackUrl'], '?webhook_token='.hash_hmac('sha256', $reference, self::TOYYIB_SECRET))
            && ! str_contains($r['billCallbackUrl'], self::TOYYIB_SECRET));
    }

    public function test_a_token_for_bill_a_fails_for_bill_b(): void
    {
        $this->fakeToyyibConfirm();
        $a = $this->pending();
        $b = $this->pending();

        $this->toyyibCallback($b->bill_code, $b->reference, [], $this->toyyibToken($a->reference))->assertForbidden();
        // Naming bill B with bill A's order_id does not help either: the reference comes from our records.
        $this->toyyibCallback($b->bill_code, $a->reference, [], $this->toyyibToken($a->reference))->assertForbidden();

        $this->assertSame('pending', $b->fresh()->status);
    }

    public function test_the_raw_secret_is_no_longer_a_valid_token(): void
    {
        $this->fakeToyyibConfirm();
        $p = $this->pending();

        $this->toyyibCallback($p->bill_code, $p->reference, [], self::TOYYIB_SECRET)->assertForbidden();
        $this->assertSame('pending', $p->fresh()->status);
    }

    public function test_a_short_secret_is_refused(): void
    {
        $short = str_repeat('x', 31);
        config(['toyyibpay.webhook_secret' => $short]);
        $this->fakeToyyibConfirm();
        $p = $this->pending();

        $this->toyyibCallback($p->bill_code, $p->reference, [], hash_hmac('sha256', $p->reference, $short))->assertForbidden();
        $this->assertSame('pending', $p->fresh()->status);
    }

    // ── Documented callback shape: status + refno (no status_id / transaction_id) ──

    public function test_documented_callback_shape_settles_the_fee(): void
    {
        $this->fakeToyyibConfirm();
        $p = $this->pending();

        $this->post(route('webhook.toyyibpay').'?webhook_token='.$this->toyyibToken($p->reference), [
            'refno' => 'TP-REF-1', 'status' => '1', 'reason' => 'Approved',
            'billcode' => $p->bill_code, 'order_id' => $p->reference, 'amount' => '50.00',
        ])->assertOk();

        $fresh = $p->fresh();
        $this->assertSame('paid', $fresh->status);
        $this->assertSame('TP-REF-1', $fresh->transaction_id);
    }

    public function test_documented_failed_callback_does_not_settle(): void
    {
        $this->fakeToyyibConfirm();
        $p = $this->pending();

        $this->post(route('webhook.toyyibpay').'?webhook_token='.$this->toyyibToken($p->reference), [
            'refno' => 'TP-REF-2', 'status' => '3', 'reason' => 'Failed',
            'billcode' => $p->bill_code, 'order_id' => $p->reference, 'amount' => '50.00',
        ]);

        $this->assertNotSame('paid', $p->fresh()->status);
    }

    public function test_bill_name_and_description_use_only_allowed_characters(): void
    {
        $this->assertSame('Club Fee Kelab Sukan Rekreasi', \App\Services\PaymentGateways\ToyyibPayGateway::safeText('Club Fee – Kelab Sukan & Rekreasi Contoh', 30));
        $this->assertLessThanOrEqual(30, mb_strlen(\App\Services\PaymentGateways\ToyyibPayGateway::safeText(str_repeat('Kelab ', 20), 30)));
        $this->assertSame('Club fee', \App\Services\PaymentGateways\ToyyibPayGateway::safeText('–––', 30));
        $this->assertSame('Club Fee Kelab Cafe', \App\Services\PaymentGateways\ToyyibPayGateway::safeText('Club Fee – Kelab Café', 30));
    }

    public function test_array_valued_fields_are_refused_not_a_server_error(): void
    {
        $p = $this->pending();

        $res = $this->post(route('webhook.toyyibpay').'?webhook_token=x', [
            'refno' => ['a'], 'billcode' => [$p->bill_code], 'order_id' => [$p->reference], 'status' => '1',
        ]);

        $this->assertLessThan(500, $res->status());
        $this->assertNotSame('paid', $p->fresh()->status);
    }

    public function test_text_status_with_numeric_status_id_still_settles(): void
    {
        $this->fakeToyyibConfirm();
        $p = $this->pending();

        $this->post(route('webhook.toyyibpay').'?webhook_token='.$this->toyyibToken($p->reference), [
            'status' => 'success', 'status_id' => '1', 'refno' => '', 'transaction_id' => 'TXN-MIX',
            'billcode' => $p->bill_code, 'order_id' => $p->reference, 'amount' => '50.00',
        ])->assertOk();

        $fresh = $p->fresh();
        $this->assertSame('paid', $fresh->status);
        $this->assertSame('TXN-MIX', $fresh->transaction_id);
    }

    // ── Confirm step (getBillTransactions) ───────────────────────────────

    public function test_confirm_step_settles_when_the_gateway_reports_the_payment(): void
    {
        $this->fakeToyyibConfirm();
        $p = $this->pending();

        $this->toyyibCallback($p->bill_code, $p->reference)->assertOk();

        $this->assertSame('paid', $p->fresh()->status);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'getBillTransactions') && $r['billCode'] === $p->bill_code);
    }

    public function test_unreachable_gateway_api_does_not_settle_and_asks_for_a_retry(): void
    {
        Http::fake(['*/index.php/api/getBillTransactions' => fn () => throw new ConnectionException('down')]);
        $p = $this->pending();

        $this->toyyibCallback($p->bill_code, $p->reference)->assertStatus(503);

        $this->assertSame('pending', $p->fresh()->status);
        Mail::assertNothingSent();
    }

    public function test_gateway_reporting_no_successful_transaction_does_not_settle_and_is_audited_once(): void
    {
        Http::fake(['*/index.php/api/getBillTransactions' => Http::response([['billpaymentStatus' => '3', 'billpaymentAmount' => '50.00']])]);
        $p = $this->pending();

        $this->toyyibCallback($p->bill_code, $p->reference)->assertStatus(409);
        $this->toyyibCallback($p->bill_code, $p->reference)->assertStatus(409);

        $this->assertSame('pending', $p->fresh()->status);
        $this->assertSame(1, AuditLog::where('action', 'payment.webhook_unconfirmed')->where('auditable_id', $p->id)->count());
    }

    public function test_confirmed_amount_below_the_fee_is_flagged_not_settled(): void
    {
        $this->fakeToyyibConfirm('20.00');
        $p = $this->pending(['amount' => 50.00]);

        // The callback claims the full amount; the gateway's own record says RM 20.
        $this->toyyibCallback($p->bill_code, $p->reference, ['amount' => '50.00'])->assertOk();

        $this->assertSame('pending', $p->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'payment.underpaid_webhook', 'auditable_id' => $p->id]);
    }

    public function test_confirm_step_can_be_turned_off(): void
    {
        config(['toyyibpay.verify_callback' => false]);
        Http::fake();
        $p = $this->pending();

        $this->toyyibCallback($p->bill_code, $p->reference)->assertOk();

        $this->assertSame('paid', $p->fresh()->status);
        Http::assertNothingSent();
    }

    // ── Superseded bills, unmatched callbacks, dedupe, gateway match ─────

    public function test_pay_records_every_bill_and_supersedes_the_old_one(): void
    {
        Http::fake(['*/index.php/api/createBill' => Http::sequence()
            ->push([['BillCode' => 'FIRST']])
            ->push([['BillCode' => 'SECOND']])]);
        $club = $this->club();
        $member = $this->member($club);
        $payment = Payment::factory()->forClub($club)->forMember($member)->pending()->create(['amount' => 50.00]);
        $this->actingAs($member)->post(route('member.payments.pay', $payment));

        // The fee changes, so the open bill is dropped and a new one is opened.
        $payment->fresh()->update(['amount' => 60.00]);
        $this->post(route('member.payments.pay', $payment));

        $bills = PaymentBill::where('payment_id', $payment->id)->orderBy('id')->get();
        $this->assertSame(['FIRST', 'SECOND'], $bills->pluck('bill_code')->all());
        $this->assertNotNull($bills[0]->superseded_at);
        $this->assertNull($bills[1]->superseded_at);
        $this->assertSame(5000, $bills[0]->amount_sen);
        $this->assertSame(6000, $bills[1]->amount_sen);
        $this->assertSame('SECOND', $payment->fresh()->bill_code);
    }

    public function test_an_old_bill_paid_after_re_pay_settles_when_the_amount_is_right(): void
    {
        $this->fakeToyyibConfirm();
        $p = $this->pending(['amount' => 50.00, 'bill_code' => 'NEWBILL', 'reference' => 'PAY-NEW', 'bill_amount' => 50.00, 'bill_fee_payer' => 'club', 'bill_fee_sen' => 100]);
        $old = $p->bills()->create(['gateway' => 'toyyibpay', 'bill_code' => 'OLDBILL', 'reference' => 'PAY-OLD', 'amount_sen' => 5000, 'fee_payer' => 'club', 'fee_sen' => 150, 'superseded_at' => now()]);
        $p->bills()->create(['gateway' => 'toyyibpay', 'bill_code' => 'NEWBILL', 'reference' => 'PAY-NEW', 'amount_sen' => 5000, 'fee_payer' => 'club', 'fee_sen' => 100]);

        $this->toyyibCallback('OLDBILL', 'PAY-OLD', ['transaction_id' => 'TXN-OLD'])->assertOk();

        $fresh = $p->fresh();
        $this->assertSame('paid', $fresh->status);
        $this->assertSame(150, $fresh->fee_absorbed_sen, 'the fee mode of the bill that was paid');
        $this->assertSame('TXN-OLD', $old->fresh()->transaction_id);
        Mail::assertSent(PaymentConfirmation::class);
    }

    public function test_an_old_bill_for_a_lower_amount_is_flagged_not_settled(): void
    {
        $this->fakeToyyibConfirm();
        $p = $this->pending(['amount' => 60.00, 'bill_code' => 'NEWBILL2', 'reference' => 'PAY-NEW2', 'bill_amount' => 60.00]);
        $p->bills()->create(['gateway' => 'toyyibpay', 'bill_code' => 'OLDBILL2', 'reference' => 'PAY-OLD2', 'amount_sen' => 5000, 'superseded_at' => now()]);

        $this->toyyibCallback('OLDBILL2', 'PAY-OLD2')->assertOk();

        $this->assertSame('pending', $p->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'payment.underpaid_webhook', 'auditable_id' => $p->id]);
    }

    public function test_an_unmatched_paid_callback_is_audited_once(): void
    {
        $this->fakeToyyibConfirm('50.00');
        $payload = ['transaction_id' => 'TXN-NOBODY'];

        $this->toyyibCallback('NOBILL', 'PAY-404-1', $payload)->assertOk();
        $this->toyyibCallback('NOBILL', 'PAY-404-1', $payload)->assertOk();

        $this->assertSame(1, AuditLog::where('action', 'payment.unmatched_webhook')->count());
        $log = AuditLog::where('action', 'payment.unmatched_webhook')->first();
        $this->assertSame('NOBILL', $log->new_values['bill_code']);
        $this->assertSame('TXN-NOBODY', $log->new_values['transaction_id']);
    }

    public function test_an_unmatched_callback_with_a_bad_token_is_refused_and_not_audited(): void
    {
        $this->toyyibCallback('NOBILL', 'PAY-404-2', [], 'forged')->assertForbidden();

        $this->assertSame(0, AuditLog::where('action', 'payment.unmatched_webhook')->count());
    }

    public function test_a_duplicate_transaction_id_does_not_resettle_a_reverted_fee(): void
    {
        $this->fakeToyyibConfirm();
        $p = $this->pending();
        $this->toyyibCallback($p->bill_code, $p->reference, ['transaction_id' => 'TXN-ONCE'])->assertOk();
        $this->assertSame('paid', $p->fresh()->status);

        // An admin reverts the fee (e.g. the payment was refunded at the gateway).
        $p->fresh()->update(['status' => 'pending', 'paid_date' => null]);

        $this->toyyibCallback($p->bill_code, $p->reference, ['transaction_id' => 'TXN-ONCE'])->assertOk();

        $this->assertSame('pending', $p->fresh()->status);
        Mail::assertSentCount(1);
    }

    public function test_a_callback_from_another_gateway_than_the_payment_is_ignored(): void
    {
        $this->fakeToyyibConfirm();
        $p = $this->pending(['gateway' => 'billplz']);

        $this->toyyibCallback($p->bill_code, $p->reference)->assertOk();

        $this->assertSame('pending', $p->fresh()->status);
    }

    // ── Hygiene ──────────────────────────────────────────────────────────

    public function test_webhook_routes_are_throttled(): void
    {
        foreach (['webhook.toyyibpay', 'webhook.billplz'] as $name) {
            $this->assertContains('throttle:webhooks', Route::getRoutes()->getByName($name)->gatherMiddleware(), $name);
        }
        $this->assertSame(300, \Illuminate\Support\Facades\RateLimiter::limiter('webhooks')(request())->maxAttempts);
    }

    public function test_callback_logs_only_allow_listed_fields(): void
    {
        $this->fakeToyyibConfirm();
        Log::spy();
        $p = $this->pending();

        $this->toyyibCallback($p->bill_code, $p->reference, ['billEmail' => 'payer@example.test', 'billTo' => 'Payer Name', 'billPhone' => '0123456789'])->assertOk();

        Log::shouldHaveReceived('info')->withArgs(function ($message, $context = []) {
            return str_contains($message, 'callback received')
                && array_keys($context) === ['gateway', 'bill_code', 'paid', 'amount_sen', 'transaction_id'];
        })->once();
        Log::shouldNotHaveReceived('info', [\Mockery::any(), \Mockery::on(fn ($c) => str_contains(json_encode($c), 'payer@example.test'))]);
    }

    public function test_billplz_ignores_a_club_signature_key_without_complete_credentials(): void
    {
        config(['billplz.x_signature_key' => 'global-billplz-signature-key']);
        $club = Club::factory()->create(['payment_gateway' => 'billplz', 'billplz_x_signature_key' => 'stray-club-key']);
        $p = Payment::factory()->forClub($club)->forMember($this->member($club))->pending()->create(['bill_code' => 'BPZ-1', 'gateway' => 'billplz']);
        $payload = ['id' => 'BPZ-1', 'paid' => 'true', 'state' => 'paid', 'paid_amount' => '5000', 'transaction_id' => 'BPZ-TXN-1'];

        $this->post(route('webhook.billplz'), $payload + ['x_signature' => BillplzSignature::compute($payload, 'stray-club-key')])->assertForbidden();
        $this->post(route('webhook.billplz'), $payload + ['x_signature' => BillplzSignature::compute($payload, 'global-billplz-signature-key')])->assertOk();

        $this->assertSame('paid', $p->fresh()->status);
    }

    public function test_pay_refuses_while_another_request_holds_the_fee_lock(): void
    {
        Http::fake();
        $club = $this->club();
        $member = $this->member($club);
        $payment = Payment::factory()->forClub($club)->forMember($member)->pending()->create();
        $lock = Cache::lock("pay:{$payment->id}", 15);
        $lock->get();

        $this->actingAs($member)->post(route('member.payments.pay', $payment))
            ->assertRedirect(route('member.payments.review', $payment))
            ->assertSessionHas('error', 'member.try_again');

        Http::assertNothingSent();
        $lock->release();
    }

    // ── Review follow-ups: paid twice, forged refund flags, unusable secret ──

    /** A fee settled via ToyyibPay with bill OLD (txn T1); NEW is a later bill for the same fee. */
    private function paidViaOldBill(): Payment
    {
        $p = $this->pending(['bill_code' => 'NEW-'.uniqid(), 'reference' => 'PAY-NEW-'.uniqid(), 'bill_amount' => 50.00]);
        $p->bills()->create(['gateway' => 'toyyibpay', 'bill_code' => 'OLD-'.$p->id, 'reference' => 'PAY-OLD-'.$p->id, 'amount_sen' => 5000, 'superseded_at' => now(), 'transaction_id' => 'T1', 'paid_at' => now()]);
        $p->bills()->create(['gateway' => 'toyyibpay', 'bill_code' => $p->bill_code, 'reference' => $p->reference, 'amount_sen' => 5000]);
        $p->forceFill(['status' => 'paid', 'paid_date' => now()->toDateString(), 'paid_via' => 'toyyibpay', 'gateway' => 'toyyibpay', 'transaction_id' => 'T1'])->save();

        return $p->fresh();
    }

    public function test_a_second_gateway_payment_on_another_bill_is_flagged_once_for_a_refund(): void
    {
        $this->fakeToyyibConfirm();
        $p = $this->paidViaOldBill();

        $this->toyyibCallback($p->bill_code, $p->reference, ['transaction_id' => 'T2'])->assertOk();
        $this->toyyibCallback($p->bill_code, $p->reference, ['transaction_id' => 'T2'])->assertOk();

        $flags = AuditLog::where('action', 'payment.gateway_paid_twice')->where('auditable_id', $p->id)->get();
        $this->assertCount(1, $flags);
        $this->assertStringContainsString('refund', $flags[0]->description);
        $this->assertSame('T1', $p->fresh()->transaction_id, 'the settled payment is untouched');
        Mail::assertNothingSent();
    }

    public function test_a_second_payment_the_gateway_does_not_confirm_is_not_flagged(): void
    {
        Http::fake(['*/index.php/api/getBillTransactions' => Http::response([])]);
        $p = $this->paidViaOldBill();

        $this->toyyibCallback($p->bill_code, $p->reference, ['transaction_id' => 'T2'])->assertStatus(409);

        $this->assertSame(0, AuditLog::where('action', 'payment.gateway_paid_twice')->count());
    }

    public function test_a_leaked_token_for_the_settling_bill_cannot_forge_refund_flags(): void
    {
        $this->fakeToyyibConfirm();
        $p = $this->paidViaOldBill();
        $old = 'OLD-'.$p->id;

        // The gateway lists one successful payment on OLD: the one that settled the fee.
        foreach (['FAKE-1', 'FAKE-2', 'FAKE-3'] as $txn) {
            $this->toyyibCallback($old, 'PAY-OLD-'.$p->id, ['transaction_id' => $txn])->assertOk();
        }
        $this->assertSame(0, AuditLog::where('action', 'payment.gateway_paid_twice')->count());

        // A real second payment on OLD (the gateway now lists two) is flagged once, not once per invented txn.
        $this->fakeToyyibConfirm(null, [], 2);
        foreach (['REAL-2', 'FAKE-4', 'FAKE-5'] as $txn) {
            $this->toyyibCallback($old, 'PAY-OLD-'.$p->id, ['transaction_id' => $txn])->assertOk();
        }
        $this->assertSame(1, AuditLog::where('action', 'payment.gateway_paid_twice')->count());
    }

    public function test_paid_by_transfer_flags_are_bounded_by_what_the_gateway_confirms(): void
    {
        $this->fakeToyyibConfirm();
        $p = $this->pending();
        // Settled by a checked slip (the member sent it).
        $p->forceFill(['status' => 'paid', 'paid_via' => 'transfer', 'paid_date' => now()->toDateString(), 'transfer_submitted_at' => now()])->save();

        foreach (['X-1', 'X-2', 'X-3'] as $txn) {
            $this->toyyibCallback($p->bill_code, $p->reference, ['transaction_id' => $txn])->assertOk();
        }

        $this->assertSame(1, AuditLog::where('action', 'payment.gateway_paid_during_verification')->where('auditable_id', $p->id)->count());
    }

    public function test_unmatched_callbacks_need_gateway_confirmation_and_are_audited_once_per_bill(): void
    {
        Http::fake(['*/index.php/api/getBillTransactions' => Http::response([])]);
        $this->toyyibCallback('NOBILL-X', 'PAY-404-9', ['transaction_id' => 'A'])->assertStatus(409);
        $this->assertSame(0, AuditLog::where('action', 'payment.unmatched_webhook')->count());
    }

    public function test_unmatched_callbacks_with_varying_transaction_ids_are_audited_once(): void
    {
        $this->fakeToyyibConfirm('50.00');
        foreach (['A', 'B', 'C'] as $txn) {
            $this->toyyibCallback('NOBILL-Y', 'PAY-404-8', ['transaction_id' => $txn])->assertOk();
        }
        $this->assertSame(1, AuditLog::where('action', 'payment.unmatched_webhook')->count());
    }

    public function test_no_bill_is_opened_while_the_webhook_secret_is_unusable(): void
    {
        Http::fake();
        foreach (['', 'your_webhook_secret_here', str_repeat('s', 31)] as $secret) {
            config(['toyyibpay.webhook_secret' => $secret]);
            app()->forgetInstance(\App\Services\PaymentGateways\PaymentGatewayManager::class);
            $club = $this->club();
            $member = $this->member($club);
            $payment = Payment::factory()->forClub($club)->forMember($member)->pending()->create();

            $this->assertFalse(app(\App\Services\PaymentGateways\ToyyibPayGateway::class)->isConfiguredForClub($club));
            $this->actingAs($member)->post(route('member.payments.pay', $payment))
                ->assertSessionHas('error', 'member.gateway_unavailable');
        }
        Http::assertNothingSent();
    }

    public function test_an_http_app_url_keeps_its_scheme_when_pinned(): void
    {
        config(['app.url' => 'http://club.example.test']);
        AppServiceProvider::pinRootUrl();

        $this->assertSame('http://club.example.test/webhook/toyyibpay', route('webhook.toyyibpay'));
    }
}
