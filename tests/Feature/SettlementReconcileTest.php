<?php

namespace Tests\Feature;

use App\Console\Commands\ReconcileOpenBills;
use App\Mail\PaymentConfirmation;
use App\Models\AuditLog;
use App\Models\Club;
use App\Models\Payment;
use App\Models\PaymentBill;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * G1 money safety: the reconcile of open bills, the member status-check
 * requery, webhooks failing closed without any amount, the reference
 * fallback, and the pay throttles. All gateway calls are faked.
 */
class SettlementReconcileTest extends TestCase
{
    private Club $club;

    private User $member;

    /** bill code => list of successful transactions getBillTransactions reports. */
    private array $paid = [];

    private bool $gatewayDown = false;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'toyyibpay.webhook_secret' => self::TOYYIB_SECRET,
            'toyyibpay.base_url' => 'https://dev.toyyibpay.com',
        ]);
        Mail::fake();
        Cache::flush();
        $this->club = Club::factory()->create([
            'payment_gateway' => 'toyyibpay', 'toyyibpay_secret_key' => 'club-secret', 'toyyibpay_category_code' => 'cat-123',
        ]);
        $this->member = User::factory()->create(['role' => 'member']);
        $this->club->members()->attach($this->member, ['role' => 'member', 'job_level' => 'executive', 'joined_date' => now()->toDateString(), 'is_active' => true]);

        Http::fake([
            '*/index.php/api/getBillTransactions' => function (HttpRequest $request) {
                if ($this->gatewayDown) {
                    return Http::response('down', 500);
                }

                return Http::response($this->paid[(string) $request['billCode']] ?? []);
            },
        ]);
    }

    private function fee(string $start = '2026-01-01', float $amount = 50.00, ?User $member = null): Payment
    {
        return Payment::factory()->forClub($this->club)->forMember($member ?? $this->member)->pending()->create([
            'amount' => $amount, 'period_start' => $start, 'period_end' => date('Y-m-t', strtotime($start)), 'due_date' => $start,
        ]);
    }

    private function singleBill(Payment $fee, string $openedAgo = '-30 minutes', array $attrs = []): PaymentBill
    {
        $code = 'B'.uniqid();
        $ref = 'PAY-'.$fee->id.'-'.uniqid();
        $bill = $fee->bills()->create($attrs + ['gateway' => 'toyyibpay', 'bill_code' => $code, 'reference' => $ref, 'amount_sen' => $fee->amountSen(), 'fee_payer' => 'club', 'fee_sen' => 100, 'opened_at' => now()->modify($openedAgo)]);
        $fee->forceFill(['bill_code' => $code, 'bill_amount' => $fee->amount, 'gateway' => 'toyyibpay', 'reference' => $ref])->saveQuietly();

        return $bill;
    }

    private function groupBill(array $fees, string $openedAgo = '-30 minutes'): PaymentBill
    {
        $bill = PaymentBill::create([
            'club_id' => $this->club->id, 'user_id' => $this->member->id, 'method' => 'gateway', 'gateway' => 'toyyibpay',
            'bill_code' => 'G'.uniqid(), 'reference' => 'PAYALL-'.uniqid(), 'fee_payer' => 'club', 'fee_sen' => 100,
            'amount_sen' => array_sum(array_map(fn (Payment $p) => $p->amountSen(), $fees)), 'opened_at' => now()->modify($openedAgo),
        ]);
        foreach ($fees as $p) {
            DB::table('payment_bill_items')->insert(['payment_bill_id' => $bill->id, 'payment_id' => $p->id, 'amount_sen' => $p->amountSen()]);
        }

        return $bill;
    }

    private function gatewayPaid(PaymentBill|string $bill, int $sen, ?string $invoice = null): void
    {
        $code = $bill instanceof PaymentBill ? $bill->bill_code : $bill;
        $this->paid[$code][] = ['billpaymentStatus' => '1', 'billpaymentAmount' => number_format($sen / 100, 2, '.', '')]
            + ($invoice ? ['billpaymentInvoiceNo' => $invoice] : []);
    }

    private function toyyibCallback(string $billCode, string $reference, array $extra = [])
    {
        return $this->post(route('webhook.toyyibpay').'?webhook_token='.$this->toyyibToken($reference), $extra + [
            'billcode' => $billCode, 'order_id' => $reference, 'status' => '1', 'refno' => 'TP-'.uniqid(),
        ]);
    }

    private function reconcile(): void
    {
        $this->artisan('payments:reconcile-open-bills')->assertSuccessful();
    }

    private function audits(string $action): int
    {
        return AuditLog::where('action', $action)->count();
    }

    // ── Reconcile ────────────────────────────────────────────────────────

    public function test_reconcile_is_scheduled_every_15_minutes_without_overlapping(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'payments:reconcile-open-bills'));
        $this->assertNotNull($event);
        $this->assertSame('*/15 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_reconcile_settles_a_paid_open_single_bill_like_the_webhook(): void
    {
        $fee = $this->fee();
        $bill = $this->singleBill($fee);
        $this->gatewayPaid($bill, 5000, 'TP-INV-1');

        $this->reconcile();

        $fee->refresh();
        $this->assertSame('paid', $fee->status);
        $this->assertSame('toyyibpay', $fee->paid_via);
        $this->assertSame('TP-INV-1', $fee->transaction_id);
        $this->assertSame(100, (int) $fee->fee_absorbed_sen);
        $this->assertNotNull($bill->fresh()->paid_at);
        $this->assertSame(1, $this->audits('payment.paid_via_webhook'));
        $log = AuditLog::where('action', 'payment.reconciled')->sole();
        $this->assertSame('reconcile', $log->new_values['source']);
        $this->assertSame($this->club->id, $log->club_id);
        Mail::assertSent(PaymentConfirmation::class, 1);
    }

    public function test_reconcile_settles_a_paid_open_group_bill(): void
    {
        $a = $this->fee('2026-01-01');
        $b = $this->fee('2026-02-01');
        $bill = $this->groupBill([$a, $b]);
        $this->gatewayPaid($bill, 10000);

        $this->reconcile();

        $this->assertSame(['paid', 'paid'], [$a->fresh()->status, $b->fresh()->status]);
        $this->assertNotNull($bill->fresh()->paid_at);
        $this->assertSame('requery-'.$bill->bill_code, $bill->fresh()->transaction_id);
        $this->assertSame(['source' => 'reconcile'], array_intersect_key(AuditLog::where('action', 'payment.reconciled')->sole()->new_values, ['source' => 1]));
        Mail::assertSent(PaymentConfirmation::class, 1);
    }

    public function test_reconcile_skips_unpaid_superseded_too_new_and_too_old_bills(): void
    {
        $unpaid = $this->singleBill($this->fee('2026-01-01'));
        $superseded = $this->singleBill($this->fee('2026-02-01'), '-30 minutes', ['superseded_at' => now()]);
        $tooNew = $this->singleBill($this->fee('2026-03-01'), '-5 minutes');
        $tooOld = $this->singleBill($this->fee('2026-04-01'), '-4 days');
        foreach ([$superseded, $tooNew, $tooOld] as $bill) {
            $this->gatewayPaid($bill, 5000);
        }

        $this->reconcile();

        $this->assertSame(0, Payment::where('status', 'paid')->count());
        Http::assertSentCount(1); // only the unpaid, eligible bill was asked about
        Http::assertSent(fn ($r) => $r['billCode'] === $unpaid->bill_code);
        $this->assertSame(0, $this->audits('payment.reconciled'));
    }

    public function test_reconcile_keeps_the_amount_checks(): void
    {
        $fee = $this->fee();
        $bill = $this->singleBill($fee);
        $this->gatewayPaid($bill, 4000);

        $this->reconcile();

        $this->assertSame('pending', $fee->fresh()->status);
        $this->assertSame(1, $this->audits('payment.underpaid_webhook'));
    }

    public function test_reconcile_survives_gateway_errors_and_is_bounded(): void
    {
        $bills = collect(range(1, 3))->map(fn ($i) => $this->singleBill($this->fee("2026-0{$i}-01")));
        $this->gatewayDown = true;

        $this->artisan('payments:reconcile-open-bills', ['--limit' => 2])
            ->expectsOutputToContain('2 gateway unreachable')
            ->assertSuccessful();
        Http::assertSentCount(2);
        // The next run resumes where this one stopped.
        $this->gatewayDown = false;
        $this->gatewayPaid($bills[2], 5000);
        $this->artisan('payments:reconcile-open-bills', ['--limit' => 2])->assertSuccessful();
        $this->assertSame('paid', $bills[2]->payment->fresh()->status);
        $this->assertSame(0, (int) Cache::get(ReconcileOpenBills::CURSOR_KEY));
    }

    public function test_reconcile_says_it_skips_billplz(): void
    {
        $fee = $this->fee();
        $this->singleBill($fee, '-30 minutes', ['gateway' => 'billplz']);

        $this->artisan('payments:reconcile-open-bills')
            ->expectsOutputToContain('Skipped 1 open billplz bill(s)')
            ->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_reconcile_then_late_webhook_settles_once_without_a_paid_twice_flag(): void
    {
        // Without an invoice number from the gateway: the callback's refno differs from the reconcile's id.
        $fee = $this->fee();
        $bill = $this->singleBill($fee);
        $this->gatewayPaid($bill, 5000);
        $this->reconcile();

        $this->toyyibCallback($bill->bill_code, $bill->reference, ['amount' => '50.00'])->assertOk();

        $this->assertSame('paid', $fee->fresh()->status);
        $this->assertSame(1, $this->audits('payment.paid_via_webhook'));
        $this->assertSame(0, $this->audits('payment.gateway_paid_twice'));
        Mail::assertSent(PaymentConfirmation::class, 1);

        // Same for a group bill.
        $a = $this->fee('2026-05-01');
        $b = $this->fee('2026-06-01');
        $group = $this->groupBill([$a, $b]);
        $this->gatewayPaid($group, 10000);
        $this->reconcile();
        $this->toyyibCallback($group->bill_code, $group->reference, ['amount' => '100.00'])->assertOk();
        $this->assertSame(0, $this->audits('payment.gateway_paid_twice'));
        $this->assertSame(3, $this->audits('payment.paid_via_webhook'));
        Mail::assertSent(PaymentConfirmation::class, 2);
    }

    public function test_reconcile_with_the_gateway_invoice_number_dedupes_the_late_callback(): void
    {
        $fee = $this->fee();
        $bill = $this->singleBill($fee);
        $this->gatewayPaid($bill, 5000, 'TP-SAME');
        $this->reconcile();

        $this->toyyibCallback($bill->bill_code, $bill->reference, ['amount' => '50.00', 'refno' => 'TP-SAME'])->assertOk();

        $this->assertSame(1, $this->audits('payment.paid_via_webhook'));
        $this->assertSame(0, $this->audits('payment.gateway_paid_twice'));
    }

    public function test_a_second_real_payment_after_reconcile_is_still_flagged(): void
    {
        $fee = $this->fee();
        $bill = $this->singleBill($fee);
        $this->gatewayPaid($bill, 5000, 'TP-1');
        $this->reconcile();
        $this->gatewayPaid($bill, 5000, 'TP-2'); // the member paid the same bill again

        $this->toyyibCallback($bill->bill_code, $bill->reference, ['amount' => '50.00', 'refno' => 'TP-2'])->assertOk();

        $this->assertSame(1, $this->audits('payment.gateway_paid_twice'));
    }

    public function test_webhook_then_reconcile_is_a_no_op(): void
    {
        $fee = $this->fee();
        $bill = $this->singleBill($fee);
        $this->gatewayPaid($bill, 5000);
        $this->toyyibCallback($bill->bill_code, $bill->reference, ['amount' => '50.00'])->assertOk();
        $this->assertSame('paid', $fee->fresh()->status);
        $sent = count(Http::recorded());

        $this->reconcile();

        $this->assertCount($sent, Http::recorded()); // the bill is no longer open: not even asked
        $this->assertSame(1, $this->audits('payment.paid_via_webhook'));
        $this->assertSame(0, $this->audits('payment.reconciled'));
        Mail::assertSent(PaymentConfirmation::class, 1);
    }

    public function test_reconcile_covers_a_legacy_fee_without_a_payment_bills_row(): void
    {
        $fee = $this->fee();
        $fee->forceFill(['bill_code' => 'LEGACY1', 'bill_amount' => 50.00, 'gateway' => 'toyyibpay', 'reference' => 'PAY-L1', 'updated_at' => now()->subHour()])->saveQuietly();
        $this->gatewayPaid('LEGACY1', 5000);

        $this->reconcile();

        $this->assertSame('paid', $fee->fresh()->status);
    }

    // ── Member status check ──────────────────────────────────────────────

    public function test_status_check_requeries_an_open_bill_and_is_throttled(): void
    {
        $fee = $this->fee();
        $bill = $this->singleBill($fee, '-1 minute');
        $this->actingAs($this->member);

        $this->getJson(route('member.payments.status', $fee))->assertOk()->assertJson(['status' => 'processing']);
        Http::assertSentCount(1);

        // Paid now, but asked again within 30 s: no second call, the stored status.
        $this->gatewayPaid($bill, 5000);
        $this->getJson(route('member.payments.status', $fee))->assertOk()->assertJsonMissing(['status' => 'paid']);
        Http::assertSentCount(1);

        $this->travel(31)->seconds();
        $this->getJson(route('member.payments.status', $fee))->assertOk()->assertJson(['status' => 'paid']);
        Http::assertSentCount(2);
        $log = AuditLog::where('action', 'payment.reconciled')->sole();
        $this->assertSame('status_check', $log->new_values['source']);
        $this->assertNull($log->user_id); // written as the system, not the member
    }

    public function test_status_check_for_someone_elses_fee_is_refused_before_any_requery(): void
    {
        $other = User::factory()->create(['role' => 'member']);
        $this->club->members()->attach($other, ['role' => 'member', 'job_level' => 'executive', 'joined_date' => now()->toDateString(), 'is_active' => true]);
        $fee = $this->fee('2026-01-01', 50.00, $other);
        $bill = $this->singleBill($fee);
        $this->gatewayPaid($bill, 5000);

        $this->actingAs($this->member)->getJson(route('member.payments.status', $fee))->assertForbidden();

        Http::assertNothingSent();
        $this->assertSame('pending', $fee->fresh()->status);
    }

    public function test_group_status_check_requeries_and_settles(): void
    {
        $a = $this->fee('2026-01-01');
        $b = $this->fee('2026-02-01');
        $bill = $this->groupBill([$a, $b], '-1 minute');
        $this->gatewayPaid($bill, 10000);

        $this->actingAs($this->member)->getJson(route('member.payments.group-status', $bill))->assertOk()->assertJson(['status' => 'paid']);
        $this->assertSame('paid', $a->fresh()->status);

        // Someone else's group bill: refused, nothing asked.
        $other = User::factory()->create(['role' => 'member']);
        $this->club->members()->attach($other, ['role' => 'member', 'job_level' => 'executive', 'joined_date' => now()->toDateString(), 'is_active' => true]);
        $sent = count(Http::recorded());
        $this->actingAs($other)->getJson(route('member.payments.group-status', $bill))->assertForbidden();
        $this->assertCount($sent, Http::recorded());
    }

    public function test_a_single_fee_status_check_asks_about_its_open_group_bill(): void
    {
        $a = $this->fee('2026-01-01');
        $b = $this->fee('2026-02-01');
        $bill = $this->groupBill([$a, $b], '-1 minute');
        $this->gatewayPaid($bill, 10000);

        $this->actingAs($this->member)->getJson(route('member.payments.status', $b))->assertOk()->assertJson(['status' => 'paid']);
        $this->assertSame('paid', $a->fresh()->status);
    }

    // ── Fail closed and the reference fallback ───────────────────────────

    public function test_webhook_fails_closed_when_no_amount_is_known(): void
    {
        config(['toyyibpay.verify_callback' => false]);
        $fee = $this->fee();
        $fee->forceFill(['bill_code' => 'NOAMT', 'gateway' => 'toyyibpay', 'reference' => 'PAY-NOAMT', 'bill_amount' => null])->saveQuietly();

        $this->toyyibCallback('NOAMT', 'PAY-NOAMT')->assertStatus(409);
        $this->toyyibCallback('NOAMT', 'PAY-NOAMT')->assertStatus(409);

        $this->assertSame('pending', $fee->fresh()->status);
        $this->assertSame(1, $this->audits('payment.webhook_amount_unknown'));
        $this->assertSame($this->club->id, AuditLog::where('action', 'payment.webhook_amount_unknown')->sole()->club_id);
    }

    public function test_an_unrecorded_bill_code_naming_our_reference_is_flagged_never_settled(): void
    {
        // Someone pays a bill of their own and sends its code with our fee's
        // reference and token: the gateway confirms that bill as paid in full.
        $fee = $this->fee();
        $bill = $this->singleBill($fee);
        $this->gatewayPaid('NOTOURS', 5000);

        $this->toyyibCallback('NOTOURS', $bill->reference, ['amount' => '50.00'])->assertOk();
        $this->toyyibCallback('NOTOURS', $bill->reference, ['amount' => '50.00'])->assertOk();

        $this->assertSame('pending', $fee->fresh()->status);
        $this->assertNull($bill->fresh()->paid_at);
        $this->assertSame(0, $this->audits('payment.paid_via_webhook'));
        $log = AuditLog::where('action', 'payment.unmatched_webhook')->sole();
        $this->assertSame($this->club->id, $log->club_id);
        $this->assertSame($fee->id, (int) $log->auditable_id);
        $this->assertStringContainsString("the bill isn't ours", $log->description);

        // Shown to the club's admins on the fee, as a warning.
        $this->actingAsClubAdmin($this->club);
        $this->get(route('admin.payments.show', $fee))
            ->assertOk()->assertSee('A payment for bill NOTOURS referencing this fee was reported', false);
    }

    public function test_the_unrecorded_bill_flag_still_needs_that_references_token(): void
    {
        $fee = $this->fee();
        $bill = $this->singleBill($fee);
        $this->gatewayPaid('NOTOURS2', 5000);

        $this->post(route('webhook.toyyibpay').'?webhook_token='.$this->toyyibToken('PAY-SOMETHING-ELSE'), [
            'billcode' => 'NOTOURS2', 'order_id' => $bill->reference, 'status' => '1', 'refno' => 'TP-X', 'amount' => '50.00',
        ])->assertForbidden();

        $this->assertSame('pending', $fee->fresh()->status);
        $this->assertSame(0, $this->audits('payment.unmatched_webhook'));
    }

    public function test_reconcile_flags_extra_payments_the_gateway_lists_for_the_bill(): void
    {
        $fee = $this->fee();
        $bill = $this->singleBill($fee);
        $this->gatewayPaid($bill, 5000, 'TP-A');
        $this->gatewayPaid($bill, 5000, 'TP-B'); // paid twice, both callbacks lost

        $this->reconcile();
        $this->reconcile();

        $this->assertSame('paid', $fee->fresh()->status);
        $this->assertSame(1, $this->audits('payment.gateway_paid_twice'));
        // The second payment's own callback, arriving late, adds no second flag.
        $this->toyyibCallback($bill->bill_code, $bill->reference, ['amount' => '50.00', 'refno' => 'TP-B'])->assertOk();
        $this->assertSame(1, $this->audits('payment.gateway_paid_twice'));
    }

    public function test_an_underpaid_bill_is_flagged_once_across_reconcile_runs(): void
    {
        $fee = $this->fee();
        $bill = $this->singleBill($fee);
        $this->gatewayPaid($bill, 4000);

        $this->reconcile();
        $this->reconcile();

        $this->assertSame(1, $this->audits('payment.underpaid_webhook'));
    }

    public function test_status_check_audit_is_written_as_system_with_a_real_session_login(): void
    {
        $fee = $this->fee();
        $bill = $this->singleBill($fee, '-1 minute');
        $this->gatewayPaid($bill, 5000);

        // A real session login (no actingAs): the guard reads the user from the session.
        $this->withSession([Auth::guard('web')->getName() => $this->member->id])
            ->getJson(route('member.payments.status', $fee))->assertOk()->assertJson(['status' => 'paid']);

        foreach (AuditLog::whereIn('action', ['payment.paid_via_webhook', 'payment.reconciled'])->get() as $log) {
            $this->assertNull($log->user_id, $log->action);
            $this->assertSame('System', $log->user_name);
        }
        $this->assertSame(2, AuditLog::whereIn('action', ['payment.paid_via_webhook', 'payment.reconciled'])->count());
    }

    // ── Throttles ────────────────────────────────────────────────────────

    public function test_pay_and_pay_all_are_throttled(): void
    {
        foreach (['member.payments.pay', 'member.payments.pay-all'] as $name) {
            $this->assertContains('throttle:10,1', Route::getRoutes()->getByName($name)->gatherMiddleware(), $name);
        }

        $fee = $this->fee();
        $this->actingAs($this->member);
        Http::fake(['*/index.php/api/createBill' => Http::response([['BillCode' => 'THR1']])]);
        for ($i = 0; $i < 10; $i++) {
            $this->post(route('member.payments.pay', $fee))->assertStatus(302);
        }
        $this->post(route('member.payments.pay', $fee))->assertStatus(429);
    }
}
