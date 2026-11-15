<?php

namespace Tests\Feature;

use App\Mail\PaymentConfirmation;
use App\Models\Club;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\WithoutMiddleware;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    private string $secret = 'test-webhook-secret-2026';

    protected function setUp(): void
    {
        parent::setUp();
        config(['toyyibpay.webhook_secret' => $this->secret]);
        Mail::fake();
    }

    private function makePendingPayment(array $overrides = []): Payment
    {
        $club    = Club::factory()->create();
        $member  = User::factory()->create();
        $club->members()->attach($member, [
            'role' => 'member', 'job_level' => 'executive',
            'joined_date' => now()->toDateString(), 'is_active' => true,
        ]);
        return Payment::factory()->forClub($club)->forMember($member)->pending()->create(array_merge([
            'bill_code' => 'BILLCODE-'.uniqid(),
            'reference' => 'REF-'.uniqid(),
        ], $overrides));
    }

    private function payload(Payment $p, array $overrides = []): array
    {
        return array_merge([
            'billcode'       => $p->bill_code,
            'order_id'       => $p->reference,
            'status_id'      => 1,
            'transaction_id' => 'TXN-'.uniqid(),
        ], $overrides);
    }

    public function test_webhook_rejects_missing_token(): void
    {
        $payment = $this->makePendingPayment();

        $this->post(route('webhook.toyyibpay'), $this->payload($payment))
            ->assertForbidden();

        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_webhook_rejects_invalid_token(): void
    {
        $payment = $this->makePendingPayment();

        $this->post(route('webhook.toyyibpay').'?webhook_token=wrong', $this->payload($payment))
            ->assertForbidden();

        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_webhook_fails_closed_when_secret_is_empty(): void
    {
        // Defence-in-depth: if TOYYIBPAY_WEBHOOK_SECRET is unset on a deploy,
        // every webhook call must be rejected — never silently allow.
        config(['toyyibpay.webhook_secret' => '']);
        $payment = $this->makePendingPayment();

        $this->post(route('webhook.toyyibpay').'?webhook_token=anything', $this->payload($payment))
            ->assertForbidden();

        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_webhook_marks_payment_paid_with_valid_token_and_billcode(): void
    {
        $payment = $this->makePendingPayment();

        $this->post(route('webhook.toyyibpay').'?webhook_token='.$this->secret, $this->payload($payment))
            ->assertOk();

        $fresh = $payment->fresh();
        $this->assertSame('paid', $fresh->status);
        $this->assertNotNull($fresh->paid_date);
        $this->assertNotNull($fresh->transaction_id);

        Mail::assertSent(PaymentConfirmation::class, fn($mail) => $mail->payment->id === $payment->id);
        $this->assertDatabaseHas('audit_logs', [
            'action'         => 'payment.paid_via_webhook',
            'auditable_type' => Payment::class,
            'auditable_id'   => $payment->id,
        ]);
    }

    public function test_webhook_finds_payment_by_reference_when_billcode_missing(): void
    {
        $payment = $this->makePendingPayment();

        $this->post(
            route('webhook.toyyibpay').'?webhook_token='.$this->secret,
            $this->payload($payment, ['billcode' => null])
        )->assertOk();

        $this->assertSame('paid', $payment->fresh()->status);
    }

    public function test_webhook_returns_200_when_payment_not_found(): void
    {
        // ToyyibPay retries on non-200, so we must always return 200 even
        // when the bill_code doesn't match a known payment.
        $this->post(route('webhook.toyyibpay').'?webhook_token='.$this->secret, [
            'billcode'  => 'UNKNOWN',
            'status_id' => 1,
        ])->assertOk();

        Mail::assertNothingSent();
    }

    public function test_webhook_ignores_unsuccessful_status(): void
    {
        $payment = $this->makePendingPayment();

        $this->post(
            route('webhook.toyyibpay').'?webhook_token='.$this->secret,
            $this->payload($payment, ['status_id' => 3]) // 1 = success per ToyyibPayService
        )->assertOk();

        $this->assertSame('pending', $payment->fresh()->status);
        Mail::assertNothingSent();
    }

    public function test_webhook_is_idempotent_on_already_paid_payment(): void
    {
        $payment = $this->makePendingPayment();

        // First call marks paid + sends mail.
        $this->post(route('webhook.toyyibpay').'?webhook_token='.$this->secret, $this->payload($payment))
            ->assertOk();
        $firstPaidDate = $payment->fresh()->paid_date;
        $firstTxn      = $payment->fresh()->transaction_id;
        Mail::assertSentCount(1);

        // Second delivery (retry / replay) must NOT re-process.
        $this->post(
            route('webhook.toyyibpay').'?webhook_token='.$this->secret,
            $this->payload($payment, ['transaction_id' => 'OVERWRITE-ATTEMPT'])
        )->assertOk();

        $fresh = $payment->fresh();
        $this->assertSame('paid', $fresh->status);
        // paid_date and transaction_id must NOT be overwritten by a replay.
        $this->assertEquals($firstPaidDate->toDateString(), $fresh->paid_date->toDateString());
        $this->assertSame($firstTxn, $fresh->transaction_id);
        Mail::assertSentCount(1);
    }

    public function test_webhook_with_a_lower_amount_leaves_the_fee_unpaid(): void
    {
        $payment = $this->makePendingPayment(['amount' => 50.00]);

        $this->post(route('webhook.toyyibpay').'?webhook_token='.$this->secret, $this->payload($payment, ['amount' => '10.00']))
            ->assertOk();

        $this->assertSame('pending', $payment->fresh()->status);
        Mail::assertNothingSent();
    }

    public function test_webhook_with_the_full_amount_marks_paid(): void
    {
        $payment = $this->makePendingPayment(['amount' => 50.00, 'bill_amount' => 50.00]);

        $this->post(route('webhook.toyyibpay').'?webhook_token='.$this->secret, $this->payload($payment, ['amount' => '50.00']))
            ->assertOk();

        $this->assertSame('paid', $payment->fresh()->status);
    }

    public function test_webhook_without_an_amount_refuses_a_bill_created_for_a_different_amount(): void
    {
        // Bill opened for RM 20; fee is RM 50 (bill_code kept, e.g. a legacy row).
        $payment = $this->makePendingPayment(['amount' => 50.00, 'bill_amount' => 20.00]);

        $this->post(route('webhook.toyyibpay').'?webhook_token='.$this->secret, $this->payload($payment))
            ->assertOk();

        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'payment.underpaid_webhook', 'auditable_id' => $payment->id]);
    }

    // ── P2: a gateway payment while a transfer slip is being checked ──────

    public function test_webhook_during_verification_settles_and_flags_for_refund(): void
    {
        $payment = $this->makePendingPayment();
        $payment->forceFill(['status' => 'pending_verification', 'transfer_date' => now()->toDateString(), 'transfer_submitted_at' => now()])->save();

        $this->post(route('webhook.toyyibpay').'?webhook_token='.$this->secret, $this->payload($payment))
            ->assertOk();

        // The money was really taken by the gateway: settle it, never ignore it.
        $fresh = $payment->fresh();
        $this->assertSame('paid', $fresh->status);
        $this->assertSame('toyyibpay', $fresh->paid_via);
        Mail::assertSent(PaymentConfirmation::class);
        $this->assertDatabaseHas('audit_logs', ['action' => 'payment.paid_via_webhook', 'auditable_id' => $payment->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'payment.gateway_paid_during_verification', 'auditable_id' => $payment->id]);
    }

    public function test_webhook_after_an_approved_transfer_is_flagged_once(): void
    {
        $payment = $this->makePendingPayment();
        $payment->forceFill(['status' => 'paid', 'paid_via' => 'transfer', 'paid_date' => now()->toDateString()])->save();
        $payload = $this->payload($payment, ['transaction_id' => 'TXN-DUP-1']);

        $this->post(route('webhook.toyyibpay').'?webhook_token='.$this->secret, $payload)->assertOk();
        $this->post(route('webhook.toyyibpay').'?webhook_token='.$this->secret, $payload)->assertOk();

        $this->assertSame('transfer', $payment->fresh()->paid_via);
        Mail::assertNothingSent();
        $this->assertSame(1, \App\Models\AuditLog::where('action', 'payment.gateway_paid_during_verification')->where('auditable_id', $payment->id)->count());
    }
}
