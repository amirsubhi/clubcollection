<?php

namespace Tests\Feature\Member;

use App\Models\Club;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PayRouteSelectsGatewayTest extends TestCase
{
    private function pendingPaymentFor(User $member, Club $club): Payment
    {
        return Payment::factory()->forClub($club)->forMember($member)->pending()->create();
    }

    public function test_pay_route_uses_toyyibpay_when_club_is_configured_for_toyyibpay(): void
    {
        config([
            'toyyibpay.base_url' => 'https://dev.toyyibpay.com',
            'toyyibpay.webhook_secret' => 'secret',
        ]);
        Http::fake([
            'dev.toyyibpay.com/index.php/api/createBill' => Http::response([
                ['BillCode' => 'TPAYBILL123'],
            ], 200),
        ]);

        $club = Club::factory()->create([
            'payment_gateway' => 'toyyibpay',
            'toyyibpay_secret_key' => 'club-secret',
            'toyyibpay_category_code' => 'cat-123',
        ]);
        $member = $this->actingAsMember($club);
        $payment = $this->pendingPaymentFor($member, $club);

        $response = $this->get(route('member.payments.pay', $payment));

        $response->assertRedirect('https://dev.toyyibpay.com/TPAYBILL123');
        $this->assertSame('toyyibpay', $payment->fresh()->gateway);
        $this->assertSame('TPAYBILL123', $payment->fresh()->bill_code);
        Http::assertSent(fn ($req) => str_contains($req->url(), 'dev.toyyibpay.com'));
    }

    public function test_pay_route_uses_billplz_when_club_is_configured_for_billplz(): void
    {
        config([
            'billplz.base_url' => 'https://www.billplz-sandbox.com',
        ]);
        Http::fake([
            'www.billplz-sandbox.com/api/v3/bills' => Http::response([
                'id' => 'bill-abc',
                'url' => 'https://www.billplz-sandbox.com/bills/bill-abc',
            ], 200),
        ]);

        $club = Club::factory()->create([
            'payment_gateway' => 'billplz',
            'billplz_api_key' => 'club-api-key',
            'billplz_collection_id' => 'club-col',
            'billplz_x_signature_key' => 'club-sig',
        ]);
        $member = $this->actingAsMember($club);
        $payment = $this->pendingPaymentFor($member, $club);

        $response = $this->get(route('member.payments.pay', $payment));

        $response->assertRedirect('https://www.billplz-sandbox.com/bills/bill-abc');
        $this->assertSame('billplz', $payment->fresh()->gateway);
        $this->assertSame('bill-abc', $payment->fresh()->bill_code);
        Http::assertSent(fn ($req) => str_contains($req->url(), 'billplz-sandbox.com/api/v3/bills'));
    }

    public function test_pay_route_blocks_when_club_is_not_configured(): void
    {
        // Force globals to placeholder values so isConfiguredForClub returns false.
        config([
            'toyyibpay.secret_key' => 'your_secret_key_here',
            'toyyibpay.category_code' => 'your_category_code_here',
            'billplz.api_key' => 'your_billplz_api_key_here',
            'billplz.collection_id' => 'your_billplz_collection_id_here',
            'billplz.x_signature_key' => 'your_billplz_x_signature_key_here',
        ]);

        $club = Club::factory()->create(['payment_gateway' => 'billplz']);
        $member = $this->actingAsMember($club);
        $payment = $this->pendingPaymentFor($member, $club);

        $this->get(route('member.payments.pay', $payment))
            ->assertRedirect();
        $this->assertNull($payment->fresh()->gateway);
        $this->assertNull($payment->fresh()->bill_code);
    }
}
