<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\Payment;
use App\Models\User;
use App\Support\Payments\BillplzSignature;
use App\Support\Payments\WebhookSecret;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The placeholder secrets shipped in .env.example are public strings.
 * If an operator never replaces them, webhooks must fail closed instead of
 * accepting forged "paid" callbacks signed with the placeholder.
 */
class WebhookPlaceholderSecretTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function pendingPayment(string $gateway): Payment
    {
        $club = Club::factory()->create(['payment_gateway' => $gateway]);
        $member = User::factory()->create();
        $club->members()->attach($member, [
            'role' => 'member', 'job_level' => 'executive',
            'joined_date' => now()->toDateString(), 'is_active' => true,
        ]);

        return Payment::factory()->forClub($club)->forMember($member)->pending()->create([
            'bill_code' => strtoupper($gateway).'-'.uniqid(),
            'reference' => 'REF-'.uniqid(),
            'gateway' => $gateway,
        ]);
    }

    public function test_toyyibpay_rejects_placeholder_webhook_secret(): void
    {
        $placeholder = 'your_webhook_secret_here';
        config(['toyyibpay.webhook_secret' => $placeholder]);
        $payment = $this->pendingPayment('toyyibpay');

        $this->post(route('webhook.toyyibpay').'?webhook_token='.$placeholder, [
            'billcode' => $payment->bill_code,
            'order_id' => $payment->reference,
            'status_id' => 1,
            'transaction_id' => 'TXN-FORGED',
        ])->assertForbidden();

        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_billplz_rejects_placeholder_signature_key(): void
    {
        $placeholder = 'your_billplz_x_signature_key_here';
        config(['billplz.x_signature_key' => $placeholder]);
        $payment = $this->pendingPayment('billplz');

        $payload = [
            'id' => $payment->bill_code,
            'paid' => 'true',
            'state' => 'paid',
            'reference_1' => $payment->reference,
        ];
        $payload['x_signature'] = BillplzSignature::compute($payload, $placeholder);

        $this->post(route('webhook.billplz'), $payload)->assertForbidden();

        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_webhook_secret_usability_rules(): void
    {
        $this->assertFalse(WebhookSecret::isUsable(null));
        $this->assertFalse(WebhookSecret::isUsable(''));
        $this->assertFalse(WebhookSecret::isUsable('   '));
        $this->assertFalse(WebhookSecret::isUsable('your_webhook_secret_here'));
        $this->assertFalse(WebhookSecret::isUsable('your_billplz_x_signature_key_here'));
        // Our own secret must be at least 32 characters (S1).
        $this->assertFalse(WebhookSecret::isUsable('a-real-random-secret-value'));
        $this->assertFalse(WebhookSecret::isUsable(str_repeat('k', 31)));
        $this->assertTrue(WebhookSecret::isUsable(str_repeat('k', 32)));
        $this->assertTrue(WebhookSecret::isUsable('a-real-random-secret-value-0123456789'));
        // A gateway-issued key (Billplz) has its own format: only blanks and placeholders are refused.
        $this->assertTrue(WebhookSecret::isUsable('S-short-billplz-key', 1));
        $this->assertFalse(WebhookSecret::isUsable('your_billplz_x_signature_key_here', 1));
    }
}
