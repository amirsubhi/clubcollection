<?php

namespace Tests\Feature\Admin;

use App\Http\Controllers\Admin\PaymentController;
use App\Mail\PaymentCancelled;
use App\Models\AuditLog;
use App\Models\Club;
use App\Models\Payment;
use App\Models\PaymentBill;
use App\Models\PaymentCancellation;
use App\Models\User;
use App\Services\Payments\PaymentCancellationService;
use App\Services\Payments\SettlementService;
use App\Support\Admin\FundFigures;
use App\Support\Payments\WebhookResult;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * "Cancel payment" (Batalkan bayaran): a paid fee, or the set it was paid with, taken back to unpaid with a reason;
 * the receipt number is void, everything stays on record, the member is told, and a replay of the cancelled
 * gateway transaction never marks it paid again.
 */
class PaymentCancellationTest extends TestCase
{
    private Club $club;

    private User $member;

    private User $admin;

    private int $month = 0;

    private int $billSeq = 0;

    private int $txnSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Mail::fake();
        Storage::fake('local');
        Carbon::setTestNow('2026-03-10 10:00:00');
        config([
            'toyyibpay.base_url' => 'https://dev.toyyibpay.com',
            'toyyibpay.webhook_secret' => self::TOYYIB_SECRET,
        ]);
        $this->club = Club::factory()->create([
            'name' => 'Kelab Contoh',
            'payment_gateway' => 'toyyibpay',
            'toyyibpay_secret_key' => 'club-secret',
            'toyyibpay_category_code' => 'cat-123',
            'transfer_enabled' => true,
            'bank_name' => 'Bank Contoh',
            'bank_account_name' => 'Kelab',
            'bank_account_no' => '1234 5678 9012',
        ])->fresh();
        $this->member = $this->join(User::factory()->create(['role' => 'member', 'name' => 'Ahli Satu']));
        $this->admin = $this->join(User::factory()->create(['role' => 'admin', 'name' => 'Bendahari']), 'admin');
        Http::fake(['dev.toyyibpay.com/index.php/api/createBill' => fn () => Http::response([['BillCode' => 'BILL'.(++$this->billSeq)]])]);
        $this->fakeToyyibConfirm();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    private function join(User $user, string $role = 'member', ?Club $club = null): User
    {
        ($club ?? $this->club)->members()->attach($user, ['role' => $role, 'job_level' => 'executive', 'joined_date' => now()->toDateString(), 'is_active' => true]);

        return $user;
    }

    /** An open fee for a month of its own. */
    private function fee(array $attrs = [], ?User $member = null): Payment
    {
        $start = Carbon::create(2026, 1, 1)->addMonths($this->month++);

        return Payment::factory()->forClub($this->club)->forMember($member ?? $this->member)->pending()->create($attrs + [
            'amount' => 10.00,
            'period_start' => $start->toDateString(),
            'period_end' => $start->copy()->endOfMonth()->toDateString(),
            'due_date' => $start->toDateString(),
        ]);
    }

    private function webhook(string $billCode, string $reference, string $txn, ?string $amount = null): void
    {
        auth()->logout();
        $this->post(route('webhook.toyyibpay').'?webhook_token='.$this->toyyibToken($reference), [
            'billcode' => $billCode, 'order_id' => $reference, 'status_id' => 1, 'transaction_id' => $txn,
        ] + ($amount ? ['amount' => $amount] : []))->assertOk();
    }

    /** The member pays one fee by FPX (a new bill), and the gateway confirms it. Returns the bill. */
    private function payFpx(Payment $fee): PaymentBill
    {
        $this->actingAs($this->member)->post(route('member.payments.pay', $fee));
        $bill = PaymentBill::where('payment_id', $fee->id)->latest('id')->firstOrFail();
        $this->webhook($bill->bill_code, $bill->reference, 'TXN-'.(++$this->txnSeq));
        $this->assertSame('paid', $fee->fresh()->status);

        return $bill->fresh();
    }

    /** "Bayar semua" by FPX for these fees. Returns the group bill. */
    private function payAllFpx(array $fees): PaymentBill
    {
        $total = array_sum(array_map(fn (Payment $f) => $f->fresh()->amountSen(), $fees));
        $this->actingAs($this->member)->post(route('member.payments.pay-all', $this->club), [
            'payment_ids' => array_map(fn (Payment $f) => $f->id, $fees), 'total_sen' => $total,
        ]);
        $bill = PaymentBill::whereNull('payment_id')->where('method', 'gateway')->latest('id')->firstOrFail();
        $this->webhook($bill->bill_code, $bill->reference, 'TXN-'.(++$this->txnSeq), number_format($total / 100, 2, '.', ''));

        return $bill->fresh();
    }

    private function slip(): array
    {
        return [UploadedFile::fake()->image('slip.png', 600, 800)];
    }

    /** One fee paid by a slip the admin approves. */
    private function paySlip(Payment $fee, ?User $checker = null): void
    {
        $this->actingAs($this->member)->post(route('member.payments.transfer', $fee), [
            'files' => $this->slip(), 'transfer_date' => today()->toDateString(), 'transfer_ref' => 'M-REF',
        ])->assertSessionHasNoErrors();
        $this->actingAs($checker ?? $this->admin)->post(route('admin.verifications.approve', $fee), [
            'paid_date' => today()->toDateString(), 'reference' => 'BANK-REF',
        ])->assertSessionHasNoErrors();
        $this->assertSame('paid', $fee->fresh()->status);
    }

    /** "Bayar semua" by slip, approved. Returns the slip group. */
    private function paySlipAll(array $fees): PaymentBill
    {
        $total = array_sum(array_map(fn (Payment $f) => $f->fresh()->amountSen(), $fees));
        $this->actingAs($this->member)->post(route('member.payments.transfer-all', $this->club), [
            'payment_ids' => array_map(fn (Payment $f) => $f->id, $fees), 'total_sen' => $total,
            'files' => $this->slip(), 'transfer_date' => today()->toDateString(),
        ])->assertSessionHasNoErrors();
        $group = PaymentBill::where('method', 'transfer')->latest('id')->firstOrFail();
        $this->actingAs($this->admin)->post(route('admin.verifications.approve', $fees[0]), ['paid_date' => today()->toDateString()])
            ->assertSessionHasNoErrors();

        return $group->fresh();
    }

    private function record(Payment $fee, string $method = 'cash'): void
    {
        $this->actingAs($this->admin)->patch(route('admin.payments.mark-paid', $fee), $this->recordPayment(['method' => $method]))
            ->assertSessionHasNoErrors();
    }

    private function cancel(Payment $fee, string $reason, ?User $as = null, array $extra = [])
    {
        return $this->actingAs($as ?? $this->admin)->post(route('admin.payments.cancel', $fee), $extra + [
            'reason' => $reason, 'confirm' => '1',
        ]);
    }

    private function refundFlags(): int
    {
        return AuditLog::whereIn('action', PaymentController::REFUND_FLAG_ACTIONS)->count();
    }

    // ── Who may cancel ──────────────────────────────────────────────────

    public function test_only_this_clubs_admins_and_the_super_admin_may_cancel_and_never_their_own_fee(): void
    {
        $fee = $this->fee();
        $this->record($fee);
        auth()->logout();

        $this->get(route('admin.payments.cancel-form', $fee))->assertRedirect(route('login'));
        $this->post(route('admin.payments.cancel', $fee), ['reason' => 'other', 'confirm' => '1'])->assertRedirect(route('login'));

        $this->cancel($fee, 'other', $this->member)->assertForbidden();
        $this->actingAs($this->member)->get(route('admin.payments.cancel-form', $fee))->assertForbidden();

        $other = Club::factory()->create();
        $otherAdmin = $this->join(User::factory()->create(['role' => 'admin']), 'admin', $other);
        $this->cancel($fee, 'other', $otherAdmin)->assertForbidden();
        $this->actingAs($otherAdmin)->get(route('admin.payments.cancel-form', $fee))->assertForbidden();
        $this->assertSame('paid', $fee->fresh()->status);

        // An admin's own fee: another admin must cancel it.
        $own = $this->fee([], $this->admin);
        $second = $this->join(User::factory()->create(['role' => 'admin']), 'admin');
        $this->actingAs($second)->patch(route('admin.payments.mark-paid', $own), $this->recordPayment());
        $this->assertSame('paid', $own->fresh()->status);
        $this->cancel($own, 'recorded_by_mistake')->assertRedirect(route('admin.payments.show', $own))
            ->assertSessionHas('error', 'This is your own fee, so another admin must cancel it.');
        $this->assertSame('paid', $own->fresh()->status);
        $this->actingAs($this->admin)->get(route('admin.payments.show', $own))->assertOk()->assertDontSee('data-testid="cancel-payment"', false);

        // This club's admin.
        $this->actingAs($this->admin)->get(route('admin.payments.cancel-form', $fee))->assertOk()->assertSee('Cancel payment');
        $this->actingAs($this->admin)->get(route('admin.payments.show', $fee))->assertOk()->assertSee('data-testid="cancel-payment"', false);
        $this->cancel($fee, 'recorded_by_mistake')->assertRedirect(route('admin.payments.show', $fee))->assertSessionHasNoErrors();
        $this->assertSame('pending', $fee->fresh()->status);

        // The super admin, even for a fee of their own.
        $super = $this->actingAsSuperAdmin();
        $this->join($super);
        $superFee = $this->fee([], $super);
        $this->actingAs($this->admin)->patch(route('admin.payments.mark-paid', $superFee), $this->recordPayment())->assertSessionHasNoErrors();
        $this->cancel($superFee, 'other', $super)->assertSessionHasNoErrors();
        $this->assertSame('pending', $superFee->fresh()->status);
        $this->cancel($own, 'other', $super)->assertSessionHasNoErrors();
        $this->assertSame('pending', $own->fresh()->status);
    }

    // ── Reasons ─────────────────────────────────────────────────────────

    public function test_reasons_fit_how_it_was_paid(): void
    {
        $slip = $this->fee();
        $this->paySlip($slip);
        $hand = $this->fee();
        $this->record($hand, 'transfer');
        $online = $this->fee();
        $this->payFpx($online);

        $this->assertSame(PaymentCancellationService::METHOD_SLIP, PaymentCancellationService::paidMethod($slip->fresh()));
        $this->assertSame(PaymentCancellationService::METHOD_MANUAL, PaymentCancellationService::paidMethod($hand->fresh()));
        $this->assertSame(PaymentCancellationService::METHOD_GATEWAY, PaymentCancellationService::paidMethod($online->fresh()));

        // The form offers only the reasons that fit.
        $options = fn (Payment $p) => collect((new \DOMXPath(tap(new \DOMDocument, fn ($d) => @$d->loadHTML($this->actingAs($this->admin)->get(route('admin.payments.cancel-form', $p))->getContent())))
            ->query('//select[@name="reason"]/option[@value!=""]')))->map(fn ($o) => $o->getAttribute('value'))->all();
        $this->assertSame(['slip_not_genuine', 'money_not_received', 'recorded_by_mistake', 'other'], $options($slip));
        $this->assertSame(['money_not_received', 'recorded_by_mistake', 'other'], $options($hand));
        $this->assertSame(['refunded', 'other'], $options($online));

        // And the server refuses the others.
        $this->cancel($slip, 'refunded')->assertSessionHasErrors('reason');
        $this->cancel($hand, 'slip_not_genuine')->assertSessionHasErrors('reason');
        $this->cancel($hand, 'refunded')->assertSessionHasErrors('reason');
        $this->cancel($online, 'money_not_received')->assertSessionHasErrors('reason');
        $this->cancel($online, 'slip_not_genuine')->assertSessionHasErrors('reason');
        $this->cancel($online, 'made_up')->assertSessionHasErrors('reason');
        $this->actingAs($this->admin)->post(route('admin.payments.cancel', $online), ['reason' => 'refunded'])->assertSessionHasErrors('confirm');
        $this->cancel($online, 'refunded', null, ['note' => str_repeat('x', 501)])->assertSessionHasErrors('note');
        foreach ([$slip, $hand, $online] as $p) {
            $this->assertSame('paid', $p->fresh()->status);
        }
        $this->assertSame(0, PaymentCancellation::count());
        Mail::assertNotSent(PaymentCancelled::class);
    }

    // ── One fee ─────────────────────────────────────────────────────────

    public function test_cancelling_a_slip_payment_keeps_the_record_voids_the_number_and_emails_the_member(): void
    {
        $fee = $this->fee();
        $this->paySlip($fee);
        $paid = $fee->fresh();
        $this->assertSame('RCP-2026-0001', $paid->receipt_no);
        $snapshot = $paid->issuer_snapshot;
        $this->assertNotNull($snapshot);

        $this->cancel($fee, 'slip_not_genuine', null, ['note' => "Bank says no such transfer.\nChecked twice."])
            ->assertRedirect(route('admin.payments.show', $fee))->assertSessionHas('success');

        $fresh = $fee->fresh();
        $this->assertSame('pending', $fresh->status);
        $this->assertNull($fresh->paid_date);
        $this->assertNull($fresh->receipt_no);
        $this->assertNull($fresh->issuer_snapshot);
        foreach (['paid_via', 'gateway', 'verified_by', 'verified_at', 'transfer_submitted_at', 'transfer_bank_ref', 'transfer_ref', 'transfer_group_id'] as $col) {
            $this->assertNull($fresh->{$col}, $col);
        }
        $this->assertNotNull(DB::table('receipt_allocation_payments')->where('payment_id', $fee->id)->value('voided_at'));

        $row = PaymentCancellation::sole();
        $this->assertSame($fee->id, $row->payment_id);
        $this->assertSame($this->club->id, (int) $row->club_id);
        $this->assertSame('RCP-2026-0001', $row->receipt_no);
        $this->assertSame(1000, $row->amount_sen);
        $this->assertSame('2026-03-10', $row->paid_date->toDateString());
        $this->assertSame('transfer', $row->paid_via);
        $this->assertSame('BANK-REF', $row->transfer_bank_ref);
        $this->assertSame('M-REF', $row->transfer_ref);
        $this->assertNull($row->payment_bill_id);
        $this->assertEquals($snapshot, $row->issuer_snapshot);
        $this->assertSame('slip_not_genuine', $row->reason_code);
        $this->assertSame("Bank says no such transfer.\nChecked twice.", $row->note);
        $this->assertSame($this->admin->id, (int) $row->cancelled_by);
        $this->assertNotEmpty($row->event_key);

        $log = AuditLog::where('action', 'payment.cancelled')->sole();
        $this->assertSame($fee->id, (int) $log->auditable_id);
        $this->assertStringContainsString('Receipt RCP-2026-0001 voided', $log->description);
        $this->assertSame('slip_not_genuine', $log->new_values['reason_code']);
        $this->assertSame($row->event_key, $log->new_values['event_key']);
        $this->assertSame('RCP-2026-0001', $log->new_values['receipt_voided']);

        Mail::assertSent(PaymentCancelled::class, 1);
        Mail::assertSent(PaymentCancelled::class, fn (PaymentCancelled $m) => $m->hasTo($this->member->email));

        // The admin page: history with who, why and the internal note; the slip is still there; the fee can't be deleted.
        $this->actingAs($this->admin)->get(route('admin.payments.show', $fee))->assertOk()
            ->assertSee('data-testid="cancellations"', false)
            ->assertSee('RCP-2026-0001')->assertSee('Bendahari')->assertSee('The slip is not genuine')->assertSee('Bank says no such transfer.')
            ->assertSee('slip.png')
            ->assertDontSee('data-testid="cancel-payment"', false);
        $this->actingAs($this->admin)->delete(route('admin.payments.destroy', $fee))->assertSessionHas('error');
        $this->assertNotNull($fee->fresh());

        // Paid again by FPX: a new number; the cancelled one stays on record.
        $this->payFpx($fee);
        $this->assertSame('RCP-2026-0002', $fee->fresh()->receipt_no);
        $this->assertSame('RCP-2026-0001', PaymentCancellation::sole()->receipt_no);
    }

    public function test_cancelling_an_online_payment_keeps_its_transaction_so_a_replay_never_marks_it_paid(): void
    {
        $fee = $this->fee();
        $bill = $this->payFpx($fee);
        $txn = $fee->fresh()->transaction_id;
        $this->assertSame('RCP-2026-0001', $fee->fresh()->receipt_no);

        $this->cancel($fee, 'refunded')->assertSessionHasNoErrors();
        $fresh = $fee->fresh();
        $this->assertSame('pending', $fresh->status);
        $this->assertSame($txn, $fresh->transaction_id, 'kept: the replay check reads it');
        $this->assertNull($fresh->gateway);
        $this->assertSame($txn, $bill->fresh()->transaction_id);
        $this->assertNotNull($bill->fresh()->paid_at);
        $row = PaymentCancellation::sole();
        $this->assertSame('toyyibpay', $row->paid_via);
        $this->assertSame('toyyibpay', $row->gateway);
        $this->assertSame($txn, $row->transaction_id);
        Mail::assertSent(PaymentCancelled::class, 1);

        // The gateway (or anyone) replays the cancelled payment: nothing changes, nothing is flagged.
        $this->webhook($bill->bill_code, $bill->reference, $txn);
        $this->assertSame('pending', $fee->fresh()->status);
        $this->assertNull($fee->fresh()->receipt_no);
        $this->assertSame(0, $this->refundFlags());

        // Paid again online: a new bill (the old one is never reused), a new number.
        $again = $this->payFpx($fee);
        $this->assertNotSame($bill->bill_code, $again->bill_code);
        $this->assertSame('RCP-2026-0002', $fee->fresh()->receipt_no);

        // A late replay of the cancelled payment is not a second payment.
        $this->webhook($bill->bill_code, $bill->reference, $txn);
        $this->assertSame('RCP-2026-0002', $fee->fresh()->receipt_no);
        $this->assertSame($again->transaction_id, $fee->fresh()->transaction_id);
        $this->assertSame(0, $this->refundFlags());

        // ...nor after it is cancelled again and recorded by hand.
        $this->cancel($fee, 'other')->assertSessionHasNoErrors();
        $this->record($fee);
        $this->webhook($bill->bill_code, $bill->reference, $txn);
        $this->webhook($again->bill_code, $again->reference, $again->transaction_id);
        $this->assertSame('cash', $fee->fresh()->paid_via);
        $this->assertSame(0, $this->refundFlags());
        $this->assertSame(2, PaymentCancellation::count());
    }

    public function test_a_cancelled_single_bill_never_pays_the_fee_again_whatever_id_it_is_reported_under(): void
    {
        $fee = $this->fee();
        $this->actingAs($this->member)->post(route('member.payments.pay', $fee));
        $bill = PaymentBill::where('payment_id', $fee->id)->sole();
        // Settled by the member's status check (the gateway gives no id: "requery-{bill}").
        $this->actingAs($this->member)->getJson(route('member.payments.status', $fee))->assertJson(['status' => 'paid']);
        $this->assertSame('requery-'.$bill->bill_code, $fee->fresh()->transaction_id);

        $this->cancel($fee, 'refunded')->assertSessionHasNoErrors();

        // The lost callback of that same payment arrives late, under the gateway's own id.
        $this->webhook($bill->bill_code, $bill->reference, 'TP-REAL-1');
        $this->assertSame('pending', $fee->fresh()->status);
        $this->assertNull($fee->fresh()->receipt_no);
        $this->assertSame(0, $this->refundFlags());

        // Another status check and the scheduled reconcile leave a settled bill alone.
        $this->travel(2)->minutes();
        $this->actingAs($this->member)->getJson(route('member.payments.status', $fee))->assertJson(['status' => 'overdue']);
        $this->travel(20)->minutes();
        $this->artisan('payments:reconcile-open-bills')->assertSuccessful();
        $this->assertSame('pending', $fee->fresh()->status);
        $this->assertSame(0, $this->refundFlags());

        // The gateway lists a second successful payment for the bill: flagged once for the treasurer, still not paid.
        $this->fakeToyyibConfirm(null, [], 2);
        $this->webhook($bill->bill_code, $bill->reference, 'TP-REAL-2');
        $this->webhook($bill->bill_code, $bill->reference, 'TP-REAL-2');
        $this->assertSame('pending', $fee->fresh()->status);
        $this->assertSame(1, AuditLog::where('action', 'payment.gateway_paid_twice')->where('auditable_id', $fee->id)->count());

        // Paid again on a new bill: a late report of the cancelled bill is not taken for a second payment.
        $this->fakeToyyibConfirm(null, [], 1);
        $this->payFpx($fee);
        $this->assertSame('RCP-2026-0002', $fee->fresh()->receipt_no);
        $this->webhook($bill->bill_code, $bill->reference, 'TP-REAL-3');
        $this->assertSame('paid', $fee->fresh()->status);
        $this->assertSame(1, $this->refundFlags(), 'only the flag above');
    }

    public function test_a_cancelled_transaction_stays_used_even_once_the_fee_no_longer_carries_it(): void
    {
        // An older fee whose bill is only on the fee row (no payment_bills entry).
        $fee = $this->fee(['bill_code' => 'LEG-1', 'reference' => 'LREF-1', 'gateway' => 'toyyibpay']);
        $this->webhook('LEG-1', 'LREF-1', 'TXN-LEG');
        $this->assertSame('paid', $fee->fresh()->status);
        $this->assertSame(0, PaymentBill::count());

        $this->cancel($fee, 'refunded')->assertSessionHasNoErrors();
        // Recorded by hand: "Record payment…" clears payments.transaction_id.
        $this->record($fee);
        $this->assertNull($fee->fresh()->transaction_id);

        // Replayed (refused: the transaction is on record as cancelled, and the fee's reference changed).
        auth()->logout();
        $this->post(route('webhook.toyyibpay').'?webhook_token='.$this->toyyibToken('LREF-1'), [
            'billcode' => 'LEG-1', 'order_id' => 'LREF-1', 'status_id' => 1, 'transaction_id' => 'TXN-LEG',
        ]);
        $this->assertSame('cash', $fee->fresh()->paid_via);
        $this->assertSame(0, $this->refundFlags(), 'the refunded payment is not a second payment');

        $used = fn (string $txn) => app(SettlementService::class)->transactionAlreadyUsed(new WebhookResult(
            billCode: 'LEG-1', reference: 'LREF-1', paid: true, transactionId: $txn, gateway: 'toyyibpay', rawPayload: []));
        $this->assertTrue($used('TXN-LEG'), 'still used after the fee stopped carrying it');
        $this->assertFalse($used('TXN-OTHER'));
    }

    public function test_cancelling_a_payment_recorded_by_hand_then_paying_again_by_slip(): void
    {
        $fee = $this->fee();
        $this->record($fee, 'other');
        $this->assertSame('RCP-2026-0001', $fee->fresh()->receipt_no);

        $this->cancel($fee, 'money_not_received')->assertSessionHasNoErrors();
        $row = PaymentCancellation::sole();
        $this->assertSame('manual', $row->paid_via);
        $this->assertSame('money_not_received', $row->reason_code);
        $this->assertNull($row->note);
        $this->assertSame('pending', $fee->fresh()->status);
        Mail::assertSent(PaymentCancelled::class, 1);

        $this->paySlip($fee);
        $this->assertSame('RCP-2026-0002', $fee->fresh()->receipt_no);
        $this->assertSame(PaymentCancellationService::METHOD_SLIP, PaymentCancellationService::paidMethod($fee->fresh()));
    }

    // ── Sets ────────────────────────────────────────────────────────────

    public function test_cancelling_one_fee_of_an_online_set_cancels_the_whole_set_in_one_event(): void
    {
        $fees = [$this->fee(), $this->fee(['amount' => 20.00]), $this->fee(['amount' => 30.00])];
        $bill = $this->payAllFpx($fees);
        $this->assertSame('RCP-2026-0001', $bill->receipt_no);
        $this->actingAs($this->member)->get(route('member.payments.group-result', $bill))
            ->assertInertia(fn (Assert $page) => $page->where('outcome', 'paid'));

        // The form lists the set and its total.
        $this->actingAs($this->admin)->get(route('admin.payments.cancel-form', $fees[1]))->assertOk()
            ->assertSee('data-testid="cancel-set"', false)->assertSee('these 3 fees')->assertSee('RM 60.00');

        $this->cancel($fees[1], 'refunded', null, ['note' => 'Refunded in ToyyibPay'])->assertSessionHasNoErrors();

        foreach ($fees as $fee) {
            $this->assertSame('pending', $fee->fresh()->status);
            $this->assertNull($fee->fresh()->receipt_no);
            $this->assertSame($bill->transaction_id, $fee->fresh()->transaction_id);
        }
        $rows = PaymentCancellation::orderBy('id')->get();
        $this->assertCount(3, $rows);
        $this->assertCount(1, $rows->pluck('event_key')->unique());
        $this->assertSame([$bill->id], $rows->pluck('payment_bill_id')->unique()->values()->all());
        $this->assertSame(['RCP-2026-0001'], $rows->pluck('receipt_no')->unique()->values()->all());
        $this->assertSame(3, AuditLog::where('action', 'payment.cancelled')->count());
        Mail::assertSent(PaymentCancelled::class, 1);
        Mail::assertSent(PaymentCancelled::class, fn (PaymentCancelled $m) => $m->fees->count() === 3 && $m->group?->id === $bill->id);
        $this->assertNotNull($bill->fresh()->paid_at, 'the bill keeps its payment: a replay is refused');
        $this->actingAs($this->member)->getJson(route('member.payments.group-status', $bill))->assertJson(['status' => 'pending']);
        $this->assertSame(['pending'], collect($fees)->map(fn ($f) => $f->fresh()->status)->unique()->values()->all());

        // The member's group page says the receipt was cancelled, without the admin's note.
        $this->actingAs($this->member)->get(route('member.payments.group-result', $bill))->assertOk()
            ->assertDontSee('Refunded in ToyyibPay')
            ->assertInertia(fn (Assert $page) => $page->component('Member/PayResult')
                ->where('outcome', 'cancelled')->where('receipt', null)
                ->where('cancellation.receiptNo', 'RCP-2026-0001')
                ->where('cancellation.cancelledOn', '2026-03-10')
                ->where('cancellation.reason', 'refunded')
                ->missing('cancellation.note'));

        // Replayed: nothing paid.
        $this->webhook($bill->bill_code, $bill->reference, $bill->transaction_id, '60.00');
        foreach ($fees as $fee) {
            $this->assertSame('pending', $fee->fresh()->status);
        }

        // Paid again as a new set: a new bill and number; the old page still says cancelled.
        $again = $this->payAllFpx($fees);
        $this->assertNotSame($bill->id, $again->id);
        $this->assertSame('RCP-2026-0002', $again->receipt_no);
        foreach ($fees as $fee) {
            $this->assertSame('RCP-2026-0002', $fee->fresh()->receipt_no);
        }
        $this->actingAs($this->member)->get(route('member.payments.group-result', $bill))
            ->assertInertia(fn (Assert $page) => $page->where('outcome', 'cancelled')->where('cancellation.receiptNo', 'RCP-2026-0001'));
        $this->actingAs($this->member)->get(route('member.payments.group-result', $again))
            ->assertInertia(fn (Assert $page) => $page->where('outcome', 'paid')->where('receipt.receiptNo', 'RCP-2026-0002'));
        $this->assertSame(0, $this->refundFlags());
    }

    public function test_cancelling_a_slip_set_cancels_every_fee_and_keeps_the_slip_traceable(): void
    {
        $fees = [$this->fee(), $this->fee(['amount' => 20.00])];
        $group = $this->paySlipAll($fees);
        $this->assertSame('RCP-2026-0001', $group->receipt_no);

        $this->cancel($fees[0], 'slip_not_genuine')->assertSessionHasNoErrors();

        foreach ($fees as $fee) {
            $f = $fee->fresh();
            $this->assertSame('pending', $f->status);
            $this->assertNull($f->transfer_group_id, 'cleared: the next slip or record starts afresh');
            $this->assertTrue($f->inAnyTransferGroup(), 'the set is still on record');
        }
        $rows = PaymentCancellation::get();
        $this->assertCount(2, $rows);
        $this->assertCount(1, $rows->pluck('event_key')->unique());
        $this->assertSame([$group->id], $rows->pluck('payment_bill_id')->unique()->values()->all());
        $this->assertSame(2, DB::table('payment_bill_items')->where('payment_bill_id', $group->id)->count());
        Mail::assertSent(PaymentCancelled::class, 1);

        // Still evidence: the slip shows on the fee's page, and the fee can't be deleted.
        $this->actingAs($this->admin)->get(route('admin.payments.show', $fees[1]))->assertOk()->assertSee('slip.png');
        $this->actingAs($this->admin)->delete(route('admin.payments.destroy', $fees[1]))->assertSessionHas('error');

        $this->actingAs($this->member)->get(route('member.payments.group-result', $group))
            ->assertInertia(fn (Assert $page) => $page->where('outcome', 'cancelled')
                ->where('cancellation.receiptNo', 'RCP-2026-0001')->where('cancellation.reason', 'slip_not_genuine'));

        // Sent again as a new set and approved: a new shared number.
        $again = $this->paySlipAll($fees);
        $this->assertNotSame($group->id, $again->id);
        $this->assertSame('RCP-2026-0002', $again->receipt_no);
        foreach ($fees as $fee) {
            $this->assertSame('RCP-2026-0002', $fee->fresh()->receipt_no);
            $this->assertSame($again->id, $fee->fresh()->transfer_group_id);
        }
        $this->actingAs($this->member)->get(route('member.payments.group-result', $again))
            ->assertInertia(fn (Assert $page) => $page->where('outcome', 'paid')->where('receipt.receiptNo', 'RCP-2026-0002'));
        $this->actingAs($this->member)->get(route('member.payments.group-result', $group))
            ->assertInertia(fn (Assert $page) => $page->where('outcome', 'cancelled'));
    }

    public function test_a_fee_of_a_set_already_receipted_on_its_own_is_left_alone(): void
    {
        $fees = [$this->fee(), $this->fee(['amount' => 20.00]), $this->fee(['amount' => 30.00])];
        $bill = $this->payAllFpx($fees);
        // One fee was taken back earlier (before Cancel payment existed) and recorded on its own.
        $fees[2]->fresh()->update(['status' => 'pending', 'paid_date' => null]);
        $this->record($fees[2]->fresh());
        $this->assertSame('RCP-2026-0002', $fees[2]->fresh()->receipt_no);

        $this->actingAs($this->admin)->get(route('admin.payments.cancel-form', $fees[0]))->assertSee('these 2 fees')->assertSee('RM 30.00');
        $this->cancel($fees[0], 'other')->assertSessionHasNoErrors();

        $this->assertSame('pending', $fees[0]->fresh()->status);
        $this->assertSame('pending', $fees[1]->fresh()->status);
        $this->assertSame('paid', $fees[2]->fresh()->status);
        $this->assertSame('RCP-2026-0002', $fees[2]->fresh()->receipt_no);
        $this->assertSame([$fees[0]->id, $fees[1]->id], PaymentCancellation::orderBy('payment_id')->pluck('payment_id')->all());

        // Its own receipt is one fee: cancelling it touches nothing else.
        $this->cancel($fees[2], 'recorded_by_mistake')->assertSessionHasNoErrors();
        $this->assertNull(PaymentCancellation::where('payment_id', $fees[2]->id)->sole()->payment_bill_id);
        $this->assertSame(3, PaymentCancellation::count());
        Mail::assertSent(PaymentCancelled::class, 2);
    }

    // ── Twice, and the edit form ────────────────────────────────────────

    public function test_cancelling_an_online_payment_made_while_a_slip_was_checked_puts_the_fee_back_in_that_slip(): void
    {
        $a = $this->fee();
        $b = $this->fee();
        // An FPX bill for A is opened first, then the member sends one slip for A and B.
        $this->actingAs($this->member)->post(route('member.payments.pay', $a));
        $bill = PaymentBill::where('payment_id', $a->id)->latest('id')->firstOrFail();
        $total = $a->fresh()->amountSen() + $b->fresh()->amountSen();
        $this->actingAs($this->member)->post(route('member.payments.transfer-all', $this->club), [
            'payment_ids' => [$a->id, $b->id], 'total_sen' => $total,
            'files' => $this->slip(), 'transfer_date' => today()->toDateString(),
        ])->assertSessionHasNoErrors();
        $group = PaymentBill::where('method', 'transfer')->latest('id')->firstOrFail();
        // The FPX payment for A still arrives while the slip is checked.
        $this->webhook($bill->bill_code, $bill->reference, 'TXN-'.(++$this->txnSeq));
        $this->assertSame('paid', $a->fresh()->status);

        $this->cancel($a->fresh(), 'refunded')->assertSessionHasNoErrors();

        $this->assertSame('pending_verification', $a->fresh()->status, 'back in the slip that is still being checked');
        $this->assertSame($group->id, (int) $a->fresh()->transfer_group_id);
        $this->actingAs($this->admin)->post(route('admin.verifications.approve', $b), ['paid_date' => today()->toDateString()])
            ->assertSessionHasNoErrors();
        $this->assertSame('paid', $a->fresh()->status);
        $this->assertSame('paid', $b->fresh()->status);
        $this->assertSame($a->fresh()->receipt_no, $b->fresh()->receipt_no, 'one slip, one receipt');
    }

    public function test_cancelling_an_online_payment_made_while_the_fees_own_slip_was_checked_keeps_that_slip(): void
    {
        $fee = $this->fee();
        // An FPX bill is opened, then the member sends a slip for the same fee; the FPX payment still arrives.
        $this->actingAs($this->member)->post(route('member.payments.pay', $fee));
        $bill = PaymentBill::where('payment_id', $fee->id)->latest('id')->firstOrFail();
        $this->actingAs($this->member)->post(route('member.payments.transfer', $fee), [
            'files' => $this->slip(), 'transfer_date' => today()->toDateString(), 'transfer_ref' => 'M-REF',
        ])->assertSessionHasNoErrors();
        $this->webhook($bill->bill_code, $bill->reference, 'TXN-'.(++$this->txnSeq));
        $this->assertSame('paid', $fee->fresh()->status);

        $this->cancel($fee->fresh(), 'refunded')->assertSessionHasNoErrors();

        $fresh = $fee->fresh();
        $this->assertSame('pending_verification', $fresh->status, 'its own slip is still waiting');
        $this->assertNotNull($fresh->transfer_submitted_at);
        $this->assertSame('M-REF', $fresh->transfer_ref);
        $this->assertStringContainsString('awaiting the check of its transfer slip', \App\Models\AuditLog::where('action', 'payment.cancelled')->sole()->description);
        Mail::assertSent(\App\Mail\PaymentCancelled::class, fn ($m) => $m->slipPending === true
            && str_contains($m->render(), 'masih disemak') && ! str_contains($m->render(), 'belum dibayar'));
        $this->actingAs($this->member)->get(route('member.payments.invoice', $fee))
            ->assertInertia(fn ($page) => $page->where('payment.status', 'verifying')->has('cancellation'));

        // The slip is then decided as usual.
        $this->actingAs($this->admin)->post(route('admin.verifications.approve', $fee), ['paid_date' => today()->toDateString()])
            ->assertSessionHasNoErrors();
        $this->assertSame('paid', $fee->fresh()->status);
    }

    public function test_the_old_online_return_page_of_a_cancelled_payment_leads_to_the_fee_page(): void
    {
        $fee = $this->fee();
        $this->payFpx($fee);
        $this->cancel($fee->fresh(), 'refunded')->assertSessionHasNoErrors();

        $this->actingAs($this->member)->get(route('member.payments.thankyou', $fee))
            ->assertRedirect(route('member.payments.invoice', $fee));
    }

    public function test_a_payment_cannot_be_cancelled_twice(): void
    {
        $fee = $this->fee();
        $this->record($fee);
        $stale = $fee->fresh();

        $this->cancel($fee, 'other')->assertSessionHasNoErrors();
        $this->cancel($fee, 'other')->assertSessionHasErrors('status');
        $this->actingAs($this->admin)->get(route('admin.payments.cancel-form', $fee))->assertRedirect(route('admin.payments.show', $fee));

        // Two admins at once: the second, from a stale copy, is refused inside the lock.
        try {
            app(PaymentCancellationService::class)->cancel($stale, $this->admin, 'other', null);
            $this->fail('A second cancel must be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }
        $this->assertSame(1, PaymentCancellation::count());
        $this->assertSame(1, AuditLog::where('action', 'payment.cancelled')->count());
        Mail::assertSent(PaymentCancelled::class, 1);
    }

    public function test_the_edit_form_no_longer_takes_a_fee_back_from_paid(): void
    {
        $fee = $this->fee();
        $this->record($fee);
        $edit = ['amount' => '10.00', 'due_date' => $fee->due_date->toDateString(), 'original_status' => 'paid'];

        foreach (['pending', 'overdue'] as $status) {
            $this->actingAs($this->admin)->put(route('admin.payments.update', $fee), ['status' => $status] + $edit)
                ->assertSessionHasErrors(['status' => PaymentController::USE_CANCEL_MESSAGE]);
            $this->assertSame('paid', $fee->fresh()->status);
            $this->assertSame('RCP-2026-0001', $fee->fresh()->receipt_no);
        }
        // Other edits of a paid fee still work.
        $this->actingAs($this->admin)->put(route('admin.payments.update', $fee), ['status' => 'paid', 'notes' => 'Checked'] + $edit)->assertSessionHasNoErrors();
        $this->assertSame('Checked', $fee->fresh()->notes);

        $html = $this->actingAs($this->admin)->get(route('admin.payments.edit', $fee))->getContent();
        $x = new \DOMXPath(tap(new \DOMDocument, fn ($d) => @$d->loadHTML($html)));
        $this->assertSame(['paid'], collect($x->query('//select[@id="f-status"]/option'))->map(fn ($o) => $o->getAttribute('value'))->all());
        $this->assertStringContainsString('data-testid="use-cancel-payment"', $html);
        $this->assertStringContainsString(route('admin.payments.cancel-form', $fee), $html);
        $this->assertSame(0, PaymentCancellation::count());
    }

    // ── What the member sees ────────────────────────────────────────────

    public function test_the_member_sees_the_cancelled_receipt_on_the_unpaid_fee_and_a_note_once_paid_again(): void
    {
        $fee = $this->fee();
        $this->record($fee);
        $this->cancel($fee, 'recorded_by_mistake', null, ['note' => 'Wrong member clicked'])->assertSessionHasNoErrors();

        $this->actingAs($this->member)->get(route('member.payments.invoice', $fee))->assertOk()
            ->assertDontSee('Wrong member clicked')->assertDontSee('Bendahari')
            ->assertInertia(fn (Assert $page) => $page->component('Member/Invoice')
                ->where('receipt', null)
                ->where('payment.status', 'overdue')
                ->where('cancellation', ['receiptNo' => 'RCP-2026-0001', 'cancelledOn' => '2026-03-10', 'reason' => 'recorded_by_mistake'])
                ->where('payment.urls.review', route('member.payments.review', $fee)));

        $this->paySlip($fee);
        $this->actingAs($this->member)->get(route('member.payments.invoice', $fee))
            ->assertInertia(fn (Assert $page) => $page->where('receipt.receiptNo', 'RCP-2026-0002')
                ->where('cancellation.receiptNo', 'RCP-2026-0001'));

        // A fee never cancelled has none.
        $plain = $this->fee();
        $this->actingAs($this->member)->get(route('member.payments.invoice', $plain))
            ->assertInertia(fn (Assert $page) => $page->where('cancellation', null));
    }

    public function test_the_email_is_in_bm_and_en_worded_by_reason_and_never_carries_the_note(): void
    {
        $expect = [
            'slip_not_genuine' => ['Kami tidak dapat mengesahkan bayaran ini', 'We could not confirm this payment'],
            'money_not_received' => ['Kami tidak dapat mengesahkan bayaran ini', 'We could not confirm this payment'],
            'recorded_by_mistake' => ['Bayaran ini telah direkodkan secara tersilap', 'This payment was recorded by mistake'],
            'refunded' => ['Bayaran ini telah dikembalikan kepada anda', 'This payment was refunded to you'],
            'other' => ['Hubungi bendahari kelab untuk maklumat lanjut', 'Contact the club treasurer for details'],
        ];
        $fee = $this->fee();
        $this->record($fee);
        foreach ($expect as $reason => [$bm, $en]) {
            $row = new PaymentCancellation;
            $row->forceFill(['club_id' => $this->club->id, 'payment_id' => $fee->id, 'event_key' => (string) Str::uuid(),
                'receipt_no' => 'RCP-2026-0001', 'amount_sen' => 1000, 'reason_code' => $reason, 'note' => 'SECRET-NOTE', 'created_at' => now()]);
            $html = (new PaymentCancelled($fee->fresh()->load(['user', 'club']), collect([$fee->fresh()]), $row))->render();
            $this->assertStringContainsString($bm, $html, $reason);
            $this->assertStringContainsString($en, $html, $reason);
            $this->assertStringContainsString('Resit <strong>RCP-2026-0001</strong> telah dibatalkan pada 10 Mar 2026', $html);
            $this->assertStringContainsString('belum dibayar', $html);
            $this->assertStringContainsString('unpaid', $html);
            $this->assertStringNotContainsString('SECRET-NOTE', $html);
        }
        $mail = new PaymentCancelled($fee->fresh()->load(['user', 'club']), collect([$fee->fresh()]), $row);
        $this->assertSame('Bayaran dibatalkan / Payment cancelled – Kelab Contoh', $mail->envelope()->subject);
    }

    public function test_a_mail_failure_does_not_undo_the_cancel(): void
    {
        $fee = $this->fee();
        $this->record($fee);
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP down'));

        $this->cancel($fee, 'other')->assertSessionHasNoErrors();
        $this->assertSame('pending', $fee->fresh()->status);
        $this->assertSame(1, PaymentCancellation::count());
    }

    // ── Figures ─────────────────────────────────────────────────────────

    public function test_a_cancelled_fee_drops_out_of_the_income_it_was_counted_in(): void
    {
        $kept = $this->fee(['amount' => 40.00]);
        $this->record($kept);
        $fee = $this->fee(['amount' => 25.00]);
        $this->record($fee);
        $f = FundFigures::for($this->club);
        $ytd = FundFigures::yearToDate(null, $this->club);
        $this->assertSame(6500, $f->forWindow($ytd)['collected']);

        $this->cancel($fee, 'money_not_received')->assertSessionHasNoErrors();

        $this->assertSame(4000, $f->forWindow($ytd)['collected']);
        $this->actingAs($this->admin)->get(route('admin.clubs.ledger', $this->club).'?from=2026-01-01&to=2026-03-10')
            ->assertInertia(fn (Assert $page) => $page->where('summary.in', 4000)->where('summary.inCount', 1));
        $this->actingAs($this->admin)->get(route('admin.clubs.payments.index', $this->club))->assertOk();
        $this->actingAs($this->admin)->get(route('admin.payments.show', $fee))->assertOk();
    }
}
