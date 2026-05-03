<?php

namespace Tests\Feature;

use App\Mail\PaymentConfirmation;
use App\Models\Club;
use App\Models\Payment;
use App\Models\User;
use App\Support\Payments\BillplzSignature;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class BillplzWebhookTest extends TestCase
{
    private string $key = 'test-billplz-x-signature-key-2026';

    protected function setUp(): void
    {
        parent::setUp();
        config(['billplz.x_signature_key' => $this->key]);
        Mail::fake();
    }

    private function makePendingPayment(array $overrides = []): Payment
    {
        $club = Club::factory()->create(['payment_gateway' => 'billplz']);
        $member = User::factory()->create();
        $club->members()->attach($member, [
            'role' => 'member', 'job_level' => 'executive',
            'joined_date' => now()->toDateString(), 'is_active' => true,
        ]);

        return Payment::factory()->forClub($club)->forMember($member)->pending()->create(array_merge([
            'bill_code' => 'BPLZ-'.uniqid(),
            'reference' => 'REF-'.uniqid(),
            'gateway' => 'billplz',
        ], $overrides));
    }

    private function payload(Payment $p, array $overrides = []): array
    {
        // Avoid empty-string fields — Laravel's ConvertEmptyStringsToNull
        // middleware would turn them into nulls before signature checks run,
        // breaking the round-trip.
        $body = array_merge([
            'id' => $p->bill_code,
            'collection_id' => 'col-1',
            'paid' => 'true',
            'state' => 'paid',
            'amount' => (string) (int) round($p->amount * 100),
            'paid_amount' => (string) (int) round($p->amount * 100),
            'due_at' => now()->toDateString(),
            'email' => $p->user->email,
            'name' => $p->user->name,
            'url' => 'https://www.billplz-sandbox.com/bills/'.$p->bill_code,
            'paid_at' => now()->toIso8601String(),
            'transaction_id' => 'TXN-'.uniqid(),
            'transaction_status' => 'completed',
            'reference_1_label' => 'PaymentRef',
            'reference_1' => $p->reference,
        ], $overrides);

        $body['x_signature'] = BillplzSignature::compute($body, $this->key);

        return $body;
    }

    public function test_webhook_rejects_missing_signature(): void
    {
        $payment = $this->makePendingPayment();
        $payload = $this->payload($payment);
        unset($payload['x_signature']);

        $this->post(route('webhook.billplz'), $payload)->assertForbidden();
        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_webhook_rejects_invalid_signature(): void
    {
        $payment = $this->makePendingPayment();
        $payload = $this->payload($payment);
        $payload['x_signature'] = str_repeat('0', 64);

        $this->post(route('webhook.billplz'), $payload)->assertForbidden();
        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_webhook_fails_closed_when_signature_key_is_empty(): void
    {
        config(['billplz.x_signature_key' => '']);
        $payment = $this->makePendingPayment();

        // Even a "valid" signature against the empty key must be rejected.
        $this->post(route('webhook.billplz'), $this->payload($payment))->assertForbidden();
        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_webhook_marks_payment_paid_with_valid_signature(): void
    {
        $payment = $this->makePendingPayment();

        $this->post(route('webhook.billplz'), $this->payload($payment))->assertOk();

        $fresh = $payment->fresh();
        $this->assertSame('paid', $fresh->status);
        $this->assertSame('billplz', $fresh->gateway);
        $this->assertNotNull($fresh->paid_date);
        $this->assertNotNull($fresh->transaction_id);

        Mail::assertSent(PaymentConfirmation::class, fn ($mail) => $mail->payment->id === $payment->id);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'payment.paid_via_webhook',
            'auditable_type' => Payment::class,
            'auditable_id' => $payment->id,
        ]);
    }

    public function test_webhook_returns_200_when_payment_not_found(): void
    {
        $payload = [
            'id' => 'NEVER-SEEN',
            'paid' => 'true',
            'state' => 'paid',
            'reference_1' => 'NO-REF',
        ];
        $payload['x_signature'] = BillplzSignature::compute($payload, $this->key);

        $this->post(route('webhook.billplz'), $payload)->assertOk();
        Mail::assertNothingSent();
    }

    public function test_webhook_ignores_unsuccessful_state(): void
    {
        $payment = $this->makePendingPayment();
        $payload = $this->payload($payment, ['paid' => 'false', 'state' => 'due']);
        // Re-sign because we mutated values. BillplzSignature::compute strips
        // x_signature internally, so pass the full array directly.
        $payload['x_signature'] = BillplzSignature::compute($payload, $this->key);

        $this->post(route('webhook.billplz'), $payload)->assertOk();
        $this->assertSame('pending', $payment->fresh()->status);
        Mail::assertNothingSent();
    }

    public function test_webhook_uses_per_club_signature_key(): void
    {
        $perClubKey = 'per-club-x-signature-key-different';

        $club = Club::factory()->create([
            'payment_gateway'        => 'billplz',
            'billplz_x_signature_key' => $perClubKey,
        ]);
        $member = User::factory()->create();
        $club->members()->attach($member, [
            'role' => 'member', 'job_level' => 'executive',
            'joined_date' => now()->toDateString(), 'is_active' => true,
        ]);
        $payment = Payment::factory()->forClub($club)->forMember($member)->pending()->create([
            'bill_code' => 'BPLZ-PERCLUB-'.uniqid(),
            'gateway'   => 'billplz',
        ]);

        // Build a payload signed with the per-club key (not the global one set in setUp).
        $body = [
            'id'          => $payment->bill_code,
            'collection_id' => 'col-club',
            'paid'        => 'true',
            'state'       => 'paid',
            'amount'      => (string) (int) round($payment->amount * 100),
            'paid_amount' => (string) (int) round($payment->amount * 100),
            'due_at'      => now()->toDateString(),
            'email'       => $payment->user->email,
            'name'        => $payment->user->name,
            'url'         => 'https://www.billplz-sandbox.com/bills/'.$payment->bill_code,
            'paid_at'     => now()->toIso8601String(),
            'transaction_id' => 'TXN-PERCLUB-'.uniqid(),
            'transaction_status' => 'completed',
            'reference_1_label' => 'PaymentRef',
            'reference_1' => 'REF-PERCLUB',
        ];
        $body['x_signature'] = \App\Support\Payments\BillplzSignature::compute($body, $perClubKey);

        $this->post(route('webhook.billplz'), $body)->assertOk();

        $this->assertSame('paid', $payment->fresh()->status);
        Mail::assertSent(PaymentConfirmation::class, fn ($mail) => $mail->payment->id === $payment->id);
    }

    public function test_webhook_is_idempotent_on_already_paid_payment(): void
    {
        $payment = $this->makePendingPayment();

        $this->post(route('webhook.billplz'), $this->payload($payment))->assertOk();
        $firstPaidDate = $payment->fresh()->paid_date;
        $firstTxn = $payment->fresh()->transaction_id;
        Mail::assertSentCount(1);

        $this->post(route('webhook.billplz'), $this->payload($payment, ['transaction_id' => 'OVERWRITE-ATTEMPT']))
            ->assertOk();

        $fresh = $payment->fresh();
        $this->assertSame('paid', $fresh->status);
        $this->assertEquals($firstPaidDate->toDateString(), $fresh->paid_date->toDateString());
        $this->assertSame($firstTxn, $fresh->transaction_id);
        Mail::assertSentCount(1);
    }
}
