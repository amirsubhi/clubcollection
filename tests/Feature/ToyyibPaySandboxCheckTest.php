<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Club;
use App\Models\Payment;
use App\Models\PaymentBill;
use App\Models\User;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** toyyibpay:sandbox-check and the sandbox callback audit entry (docs/SANDBOX-TEST.md). */
class ToyyibPaySandboxCheckTest extends TestCase
{
    private const SECRET_KEY = 'sk-sandbox-very-secret-0123456789';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'toyyibpay.sandbox' => true,
            'toyyibpay.base_url' => 'https://dev.toyyibpay.com',
            'toyyibpay.secret_key' => self::SECRET_KEY,
            'toyyibpay.category_code' => 'sandboxcat',
            'toyyibpay.webhook_secret' => self::TOYYIB_SECRET,
        ]);
        Mail::fake();
    }

    private function run_(array $args): array
    {
        $code = Artisan::call('toyyibpay:sandbox-check', $args);

        return [$code, Artisan::output()];
    }

    public function test_refuses_when_sandbox_is_off(): void
    {
        Http::fake();
        config(['toyyibpay.sandbox' => false, 'toyyibpay.base_url' => 'https://toyyibpay.com']);

        [$code, $out] = $this->run_(['--create' => true]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Refused', $out);
        Http::assertNothingSent();
    }

    public function test_refuses_when_the_address_is_not_the_sandbox(): void
    {
        Http::fake();
        config(['toyyibpay.base_url' => 'https://toyyibpay.com']);

        [$code, $out] = $this->run_(['--bill' => 'abc']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('not the sandbox', $out);
        Http::assertNothingSent();
    }

    public function test_create_sends_the_bill_masks_secrets_and_writes_no_payment(): void
    {
        Http::fake(['*/index.php/api/createBill' => Http::response([['BillCode' => 'sbx123']])]);

        [$code, $out] = $this->run_(['--create' => true, '--amount' => '2.50']);

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('sbx123', $out);
        $this->assertStringContainsString('https://dev.toyyibpay.com/sbx123', $out);
        $this->assertStringContainsString('[hidden, '.strlen(self::SECRET_KEY).' characters]', $out);
        $this->assertStringContainsString('webhook_token=[hidden]', $out);
        $this->assertStringContainsString('"BillCode": "sbx123"', $out);
        $this->assertStringNotContainsString(self::SECRET_KEY, $out);
        $this->assertDoesNotMatchRegularExpression('/webhook_token=[0-9a-f]{64}/', $out);
        $this->assertStringNotContainsString('billName has characters other than', $out); // cleaned before sending

        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'createBill')
            && $r['userSecretKey'] === self::SECRET_KEY
            && preg_match('/^[A-Za-z0-9 _]{1,30}$/', (string) $r['billName']) === 1
            && (int) $r['billAmount'] === 250
            && (string) $r['billChargeToCustomer'] === '1'
            && str_starts_with((string) $r['billExternalReferenceNo'], 'SANDBOX-'));
        $this->assertSame(0, Payment::count());
        $this->assertSame(0, PaymentBill::count());
    }

    public function test_create_with_member_payer_sends_the_member_flag(): void
    {
        Http::fake(['*/index.php/api/createBill' => Http::response([['BillCode' => 'sbx124']])]);

        [$code] = $this->run_(['--create' => true, '--payer' => 'member']);

        $this->assertSame(0, $code);
        Http::assertSent(fn (HttpRequest $r) => (string) $r['billChargeToCustomer'] === '0' && (int) $r['billAmount'] === 100);
    }

    public function test_create_reports_a_failed_bill(): void
    {
        Http::fake(['*/index.php/api/createBill' => Http::response('[KEY-DID-NOT-EXIST]', 200)]);

        [$code, $out] = $this->run_(['--create' => true]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('createBill FAILED', $out);
        $this->assertStringContainsString('[KEY-DID-NOT-EXIST]', $out);
    }

    public function test_create_for_a_demo_fee_saves_the_bill_on_the_fee(): void
    {
        Http::fake(['*/index.php/api/createBill' => Http::response([['BillCode' => 'sbx200']])]);
        $club = Club::factory()->create(['payment_gateway' => 'toyyibpay']);
        $member = User::factory()->create(['email' => 'demo.member@example.com']);
        $payment = Payment::factory()->forClub($club)->forMember($member)->pending()->create(['amount' => 12.00]);

        [$code, $out] = $this->run_(['--create' => true, '--payment' => $payment->id]);

        $this->assertSame(0, $code, $out);
        $this->assertSame('sbx200', $payment->fresh()->bill_code);
        $this->assertDatabaseHas('payment_bills', ['payment_id' => $payment->id, 'bill_code' => 'sbx200', 'amount_sen' => 1200]);
        $this->assertStringNotContainsString('demo.member@example.com', $out);
        Http::assertSent(fn (HttpRequest $r) => (int) $r['billAmount'] === 1200
            && str_starts_with((string) $r['billExternalReferenceNo'], 'PAY-'.$payment->id.'-'));
    }

    public function test_create_refuses_a_paid_fee(): void
    {
        Http::fake();
        $club = Club::factory()->create(['payment_gateway' => 'toyyibpay']);
        $payment = Payment::factory()->forClub($club)->forMember(User::factory()->create())->create(['status' => 'paid']);

        [$code, $out] = $this->run_(['--create' => true, '--payment' => $payment->id]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('pick an unpaid demo fee', $out);
        Http::assertNothingSent();
    }

    public function test_bill_check_shows_raw_answer_and_parsed_amount(): void
    {
        Http::fake(['*/index.php/api/getBillTransactions' => Http::response([[
            'billName' => 'Club Fee',
            'billTo' => 'Sandbox Tester',
            'billEmail' => 'payer@example.com',
            'billPhone' => '0123456789',
            'billpaymentStatus' => '1',
            'billpaymentAmount' => '1.00',
        ]])]);

        [$code, $out] = $this->run_(['--bill' => 'sbx123']);

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('"billpaymentAmount": "1.00"', $out);
        $this->assertStringContainsString('100 sen (RM 1.00)', $out);
        $this->assertMatchesRegularExpression('/Paid\?\s*\|\s*YES/', $out);
        $this->assertStringNotContainsString('payer@example.com', $out);
        $this->assertStringNotContainsString('0123456789', $out);
        $this->assertStringNotContainsString('Sandbox Tester', $out);
        Http::assertSent(fn (HttpRequest $r) => $r['billCode'] === 'sbx123' && $r['billpaymentStatus'] === '1');
    }

    public function test_bill_check_flags_an_amount_below_our_fee(): void
    {
        // A sen value where ringgit is expected would read 100x too high; a
        // ringgit value read as sen... here the gateway reports less than the fee.
        Http::fake(['*/index.php/api/getBillTransactions' => Http::response([['billpaymentStatus' => '1', 'billpaymentAmount' => '0.50']])]);
        $club = Club::factory()->create();
        $payment = Payment::factory()->forClub($club)->forMember(User::factory()->create())->pending()->create(['amount' => 10.00]);
        $payment->bills()->create(['gateway' => 'toyyibpay', 'bill_code' => 'sbx300', 'reference' => 'PAY-x', 'amount_sen' => 1000, 'fee_payer' => 'club', 'fee_sen' => 100, 'opened_at' => now()]);

        [$code, $out] = $this->run_(['--bill' => 'sbx300']);

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('NO: amount below the fee', $out);
    }

    public function test_bill_check_reports_an_unreadable_answer(): void
    {
        Http::fake(['*/index.php/api/getBillTransactions' => Http::response('error', 500)]);

        [$code, $out] = $this->run_(['--bill' => 'sbx123']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('could NOT read an answer', $out);
    }

    public function test_bill_check_all_lists_every_status(): void
    {
        Http::fake(['*/index.php/api/getBillTransactions' => fn (HttpRequest $r) => isset($r['billpaymentStatus'])
            ? Http::response([])
            : Http::response([['billpaymentStatus' => '3', 'billpaymentAmount' => '1.00']])]);

        [$code, $out] = $this->run_(['--bill' => 'sbx123', '--all' => true]);

        $this->assertSame(0, $code, $out);
        $this->assertMatchesRegularExpression('/Paid\?\s*\|\s*NO/', $out);
        $this->assertStringContainsString('"billpaymentStatus": "3"', $out);
        Http::assertSentCount(2);
    }

    // ── sandbox callback audit entry ────────────────────────────────────────

    private function pendingFee(): Payment
    {
        $club = Club::factory()->create();

        return Payment::factory()->forClub($club)->forMember(User::factory()->create())->pending()->create([
            'bill_code' => 'sbxcb1', 'reference' => 'PAY-1-123', 'bill_amount' => 10.00, 'amount' => 10.00, 'gateway' => 'toyyibpay',
        ]);
    }

    /** A callback in the field names ToyyibPay's docs list for the callback URL. */
    private function documentedCallback(Payment $p): array
    {
        return ['refno' => 'TP123', 'status' => '1', 'reason' => 'Approved', 'billcode' => $p->bill_code,
            'order_id' => $p->reference, 'amount' => '10.00', 'transaction_time' => '2026-11-18 10:00:00',
            'billEmail' => 'payer@example.com'];
    }

    public function test_sandbox_callback_records_field_names_and_allow_listed_values(): void
    {
        config(['toyyibpay.log_callbacks' => true]);
        $this->fakeToyyibConfirm();
        $p = $this->pendingFee();

        $this->post(route('webhook.toyyibpay').'?webhook_token='.$this->toyyibToken($p->reference), $this->documentedCallback($p))->assertOk();

        $log = AuditLog::where('action', 'payment.sandbox_callback')->sole();
        $this->assertSame($p->id, $log->auditable_id);
        $this->assertSame($p->club_id, $log->club_id);
        $this->assertSame(['refno', 'status', 'reason', 'billcode', 'order_id', 'amount', 'transaction_time', 'billEmail'], $log->new_values['fields_received']);
        $this->assertSame('1', $log->new_values['values']['status']);
        $this->assertSame('TP123', $log->new_values['values']['refno']);
        $this->assertArrayNotHasKey('billEmail', $log->new_values['values']);
        $this->assertTrue($log->new_values['read_as']['paid']); // documented shape: status=1 is read as paid
        $this->assertSame(1000, $log->new_values['read_as']['amount_sen']);
        $this->assertStringNotContainsString('NOT PAID', $log->description);
        $this->assertStringNotContainsString('payer@example.com', json_encode($log->toArray()));
        $this->assertStringNotContainsString($this->toyyibToken($p->reference), json_encode($log->toArray()));
    }

    public function test_sandbox_callback_entry_alongside_a_settled_fee(): void
    {
        config(['toyyibpay.log_callbacks' => true]);
        $this->fakeToyyibConfirm();
        $p = $this->pendingFee();

        $this->post(route('webhook.toyyibpay').'?webhook_token='.$this->toyyibToken($p->reference), [
            'billcode' => $p->bill_code, 'order_id' => $p->reference, 'status_id' => '1', 'transaction_id' => 'TXN-9', 'amount' => '10.00',
        ])->assertOk();

        $this->assertSame('paid', $p->fresh()->status);
        $log = AuditLog::where('action', 'payment.sandbox_callback')->sole();
        $this->assertTrue($log->new_values['read_as']['paid']);
        $this->assertStringContainsString('PAID', $log->description);
    }

    public function test_no_sandbox_entry_in_live_mode_or_for_a_refused_callback(): void
    {
        config(['toyyibpay.log_callbacks' => true, 'toyyibpay.sandbox' => false]);
        $this->fakeToyyibConfirm();
        $p = $this->pendingFee();

        $this->post(route('webhook.toyyibpay').'?webhook_token='.$this->toyyibToken($p->reference), $this->documentedCallback($p))->assertOk();
        $this->assertSame(0, AuditLog::where('action', 'payment.sandbox_callback')->count());

        config(['toyyibpay.sandbox' => true]);
        $this->post(route('webhook.toyyibpay').'?webhook_token=wrong', $this->documentedCallback($p))->assertForbidden();
        $this->assertSame(0, AuditLog::where('action', 'payment.sandbox_callback')->count());
    }

    public function test_sandbox_entries_are_capped_per_bill(): void
    {
        config(['toyyibpay.log_callbacks' => true]);
        $this->fakeToyyibConfirm();
        $p = $this->pendingFee();

        for ($i = 0; $i < 22; $i++) {
            $this->post(route('webhook.toyyibpay').'?webhook_token='.$this->toyyibToken($p->reference), $this->documentedCallback($p))->assertOk();
        }

        $this->assertSame(20, AuditLog::where('action', 'payment.sandbox_callback')->count());
    }
}
