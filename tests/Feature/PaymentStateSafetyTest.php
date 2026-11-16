<?php

namespace Tests\Feature;

use App\Mail\OverdueReminder;
use App\Models\AuditLog;
use App\Models\Club;
use App\Models\Payment;
use App\Models\PaymentBill;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * R2a. Payment-state safety: an FPX payment in progress is visible to the
 * member and the admins (no double payments), "Record payment…" asks for
 * method and date, one status word everywhere, and the grace period.
 */
class PaymentStateSafetyTest extends TestCase
{
    private Club $club;

    private User $member;

    private int $month = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(Carbon::create(2026, 11, 16, 10));
        $this->club = Club::factory()->create(['name' => 'Kelab Contoh']);
        $this->member = User::factory()->create(['name' => 'Siti Contoh']);
        $this->club->members()->attach($this->member, ['role' => 'member', 'job_level' => 'executive', 'joined_date' => '2026-01-01', 'is_active' => true]);
    }

    private function fee(array $attrs = []): Payment
    {
        $start = Carbon::create(2026, 9, 1)->addMonths($this->month++);

        return Payment::factory()->forClub($this->club)->forMember($this->member)->create($attrs + [
            'status' => 'pending', 'paid_date' => null, 'amount' => 10.00,
            'period_start' => $start->toDateString(), 'period_end' => $start->copy()->endOfMonth()->toDateString(),
            'due_date' => $start->copy()->addDays(14)->toDateString(),
        ]);
    }

    private function openSingleBill(Payment $fee): PaymentBill
    {
        $bill = $fee->bills()->create(['gateway' => 'toyyibpay', 'bill_code' => 'B'.uniqid(), 'reference' => 'PAY-'.$fee->id, 'amount_sen' => $fee->amountSen()]);
        $fee->forceFill(['bill_code' => $bill->bill_code, 'bill_amount' => $fee->amount, 'gateway' => 'toyyibpay', 'reference' => 'PAY-'.$fee->id])->saveQuietly();

        return $bill;
    }

    private function openGroupBill(array $fees): PaymentBill
    {
        $bill = PaymentBill::create([
            'club_id' => $this->club->id, 'user_id' => $this->member->id, 'method' => 'gateway', 'gateway' => 'toyyibpay',
            'bill_code' => 'G'.uniqid(), 'reference' => 'PAYALL-'.uniqid(),
            'amount_sen' => array_sum(array_map(fn (Payment $p) => $p->amountSen(), $fees)),
        ]);
        foreach ($fees as $p) {
            DB::table('payment_bill_items')->insert(['payment_bill_id' => $bill->id, 'payment_id' => $p->id, 'amount_sen' => $p->amountSen()]);
        }

        return $bill;
    }

    private function dom(string $html): DOMXPath
    {
        $doc = new DOMDocument;
        libxml_use_internal_errors(true);
        $doc->loadHTML($html);
        libxml_clear_errors();

        return new DOMXPath($doc);
    }

    // ── Member pages ────────────────────────────────────────────────────

    public function test_home_shows_a_payment_in_progress_instead_of_asking_to_pay_again(): void
    {
        $fee = $this->fee(['due_date' => '2026-10-01']); // 46 days late by the dates
        $this->openSingleBill($fee);
        $this->actingAs($this->member);

        $this->get(route('member.dashboard'))->assertInertia(fn (Assert $p) => $p
            ->component('Member/Home')
            ->where('outstanding.count', 0)
            ->where('outstanding.total', 0)
            ->where('outstanding.overdueCount', 0)
            ->where('outstanding.processingCount', 1)
            ->where('outstanding.processingTotal', 1000)
            ->where('upcoming.0.status', 'processing')
            ->where('upcoming.0.urls.billStatus', route('member.payments.thankyou', $fee))
            ->where('upcoming.0.urls.review', route('member.payments.review', $fee))
            ->where('clubs.0.summary.processing', 1)
            ->where('clubs.0.summary.overdue', 0));
    }

    public function test_home_keeps_other_fees_payable_and_hides_pay_all_while_one_is_in_progress(): void
    {
        $late = $this->fee(['due_date' => '2026-10-01']);
        $inProgress = $this->fee(['due_date' => '2026-10-15']);
        $due = $this->fee(['due_date' => '2026-11-15']);
        $this->openSingleBill($inProgress);
        $this->actingAs($this->member);

        $this->get(route('member.dashboard'))->assertInertia(fn (Assert $p) => $p
            ->where('outstanding.count', 2)
            ->where('outstanding.total', 2000)
            ->where('outstanding.overdueCount', 1)
            ->where('outstanding.processingCount', 1)
            ->where('upcoming.0.id', $late->id)
            ->where('upcoming.0.status', 'overdue')
            ->where('upcoming.1.id', $due->id)
            ->where('upcoming.1.status', 'due')
            ->where('upcoming.2.id', $inProgress->id)
            ->where('payAll', null));

        $this->get(route('member.payments.index', [$this->club, 'year' => 2026]))->assertInertia(fn (Assert $p) => $p
            ->where('payAll', null)
            ->where('summary.processing', 1000)
            ->where('summary.overdue', 1000)
            ->where('summary.due', 1000));
    }

    public function test_a_group_bill_in_progress_points_to_the_group_result(): void
    {
        $a = $this->fee();
        $b = $this->fee();
        $group = $this->openGroupBill([$a, $b]);
        $this->actingAs($this->member);

        $this->get(route('member.dashboard'))->assertInertia(fn (Assert $p) => $p
            ->where('outstanding.processingCount', 2)
            ->where('upcoming.0.urls.billStatus', route('member.payments.group-result', $group)));

        $this->get(route('member.payments.review', $a))->assertInertia(fn (Assert $p) => $p
            ->component('Member/ReviewPay')
            ->where('processing', true)
            ->where('hasOpenBill', true)
            ->where('openBillUrl', route('member.payments.group-result', $group)));

        $this->get(route('member.payments.review-all', $this->club))->assertInertia(fn (Assert $p) => $p
            ->where('processing', true)
            ->where('openBillUrl', route('member.payments.group-result', $group)));
    }

    public function test_review_pay_makes_checking_the_open_bill_the_main_action(): void
    {
        $fee = $this->fee(['due_date' => '2026-11-30']);
        $this->openSingleBill($fee);
        $this->actingAs($this->member);

        $this->get(route('member.payments.review', $fee))->assertInertia(fn (Assert $p) => $p
            ->where('payment.status', 'processing')
            ->where('processing', true)
            ->where('hasOpenBill', true)
            ->where('openBillUrl', route('member.payments.thankyou', $fee)));

        // Abandoned for more than three days: paying is the main action again; the warning stays.
        $this->travel(4)->days();
        $this->get(route('member.payments.review', $fee))->assertInertia(fn (Assert $p) => $p
            ->where('payment.status', 'due')
            ->where('processing', false)
            ->where('hasOpenBill', true));
    }

    // ── Reminders ───────────────────────────────────────────────────────

    public function test_reminders_wait_for_the_grace_period_and_skip_fees_in_progress(): void
    {
        Mail::fake();
        $withinGrace = $this->fee(['due_date' => '2026-11-09']); // 7 days late: last day of grace
        $pastGrace = $this->fee(['due_date' => '2026-11-08']);
        $inProgress = $this->fee(['due_date' => '2026-10-01']);
        $this->openSingleBill($inProgress);
        $grouped = $this->fee(['due_date' => '2026-10-02']);
        $this->openGroupBill([$grouped]);

        Artisan::call('payments:send-overdue-reminders');

        $this->assertSame('pending', $withinGrace->fresh()->status);
        $this->assertSame('overdue', $pastGrace->fresh()->status);
        $this->assertSame('pending', $inProgress->fresh()->status);
        $this->assertSame('pending', $grouped->fresh()->status);
        Mail::assertSent(OverdueReminder::class, 1);
        Mail::assertSent(OverdueReminder::class, fn ($m) => $m->payment->id === $pastGrace->id);

        // A club with no grace: reminded the day after the due date.
        $this->club->update(['overdue_grace_days' => 0]);
        Artisan::call('payments:send-overdue-reminders');
        $this->assertSame('overdue', $withinGrace->fresh()->status);
    }

    // ── Record payment… (admin) ─────────────────────────────────────────

    public function test_record_payment_requires_method_and_a_sensible_date(): void
    {
        $this->actingAsClubAdmin($this->club);
        $fee = $this->fee(); // period starts 1 Sep 2026

        $this->patch(route('admin.payments.mark-paid', $fee), [])->assertSessionHasErrors(['method', 'paid_date']);
        $this->patch(route('admin.payments.mark-paid', $fee), ['method' => 'cheque', 'paid_date' => '2026-11-16'])->assertSessionHasErrors('method');
        $this->patch(route('admin.payments.mark-paid', $fee), ['method' => 'cash', 'paid_date' => '2026-11-17'])->assertSessionHasErrors('paid_date');
        $this->patch(route('admin.payments.mark-paid', $fee), ['method' => 'cash', 'paid_date' => '2026-08-31'])->assertSessionHasErrors('paid_date');
        $this->assertSame('pending', $fee->fresh()->status);

        $this->patch(route('admin.payments.mark-paid', $fee), ['method' => 'cash', 'paid_date' => '2026-09-01'])->assertSessionHasNoErrors();
        $this->assertSame('paid', $fee->fresh()->status);
    }

    public function test_a_fee_paid_ahead_can_be_recorded_from_the_day_it_was_raised(): void
    {
        $this->actingAsClubAdmin($this->club);
        $ahead = Payment::factory()->forClub($this->club)->forMember($this->member)->create([
            'status' => 'pending', 'paid_date' => null, 'period_start' => '2027-01-01', 'period_end' => '2027-12-31', 'frequency' => 'yearly', 'due_date' => '2027-01-15',
        ]);

        $this->patch(route('admin.payments.mark-paid', $ahead), ['method' => 'transfer', 'paid_date' => '2026-11-15'])->assertSessionHasErrors('paid_date');
        $this->patch(route('admin.payments.mark-paid', $ahead), ['method' => 'transfer', 'paid_date' => '2026-11-16'])->assertSessionHasNoErrors();
    }

    public function test_record_payment_sets_paid_via_date_and_reference_and_is_audited(): void
    {
        $admin = $this->actingAsClubAdmin($this->club);
        $cases = ['cash' => 'cash', 'transfer' => 'transfer', 'other' => 'manual'];
        foreach ($cases as $method => $paidVia) {
            $fee = $this->fee();
            $this->patch(route('admin.payments.mark-paid', $fee), ['method' => $method, 'paid_date' => '2026-11-10', 'reference' => " REF-{$method} "])
                ->assertSessionHas('success');
            $fresh = $fee->fresh();
            $this->assertSame('paid', $fresh->status);
            $this->assertSame($paidVia, $fresh->paid_via);
            $this->assertSame('2026-11-10', $fresh->paid_date->toDateString());
            $this->assertSame("REF-{$method}", $fresh->reference);
            $this->assertNull($fresh->gateway);

            $log = AuditLog::where('action', 'payment.marked_paid')->where('auditable_id', $fee->id)->sole();
            $this->assertSame((int) $admin->id, (int) $log->user_id);
            $this->assertSame(['status' => 'paid', 'paid_date' => '2026-11-10', 'paid_via' => $paidVia, 'method' => $method, 'reference' => "REF-{$method}"], $log->new_values);
            $this->assertSame('pending', $log->old_values['status']);
        }
    }

    public function test_record_payment_asks_to_confirm_when_an_fpx_payment_is_in_progress(): void
    {
        $this->actingAsClubAdmin($this->club);
        $fee = $this->fee();
        $this->openSingleBill($fee);

        $this->patch(route('admin.payments.mark-paid', $fee), ['method' => 'cash', 'paid_date' => '2026-11-16'])
            ->assertSessionHasErrors('confirm_processing');
        $this->assertSame('pending', $fee->fresh()->status);

        $this->patch(route('admin.payments.mark-paid', $fee), ['method' => 'cash', 'paid_date' => '2026-11-16', 'confirm_processing' => '1'])
            ->assertSessionHas('success');
        $this->assertSame('paid', $fee->fresh()->status);
        $log = AuditLog::where('action', 'payment.marked_paid')->sole();
        $this->assertTrue($log->new_values['fpx_in_progress_confirmed']);
        $this->assertStringContainsString('open FPX payment did not go through', $log->description);
    }

    public function test_the_edit_form_never_marks_a_fee_paid(): void
    {
        $this->actingAsClubAdmin($this->club);
        $plain = $this->fee();
        $inProgress = $this->fee();
        $this->openSingleBill($inProgress);
        foreach ([$plain, $inProgress] as $fee) {
            $this->put(route('admin.payments.update', $fee), [
                'amount' => '10.00', 'due_date' => $fee->due_date->toDateString(), 'status' => 'paid', 'original_status' => 'pending',
            ])->assertSessionHasErrors(['status' => 'To mark this fee paid, use "Record payment…" on the payment page: it asks how and when the money was received.']);
            $this->assertSame('pending', $fee->fresh()->status);
        }
        // The form offers Paid only for a fee that is already paid (to keep it or undo it).
        $x = $this->dom($this->get(route('admin.payments.edit', $plain))->getContent());
        $this->assertSame(0, $x->query('//select[@id="f-status"]/option[@value="paid"]')->length);
        $this->assertSame('Not paid (no more reminders)', trim($x->query('//select[@id="f-status"]/option[@value="overdue"]')->item(0)->textContent));
        $paid = $this->fee(['status' => 'paid', 'paid_date' => '2026-11-01']);
        $x = $this->dom($this->get(route('admin.payments.edit', $paid))->getContent());
        $this->assertSame(1, $x->query('//select[@id="f-status"]/option[@value="paid"]')->length);
    }

    public function test_record_payment_drops_the_old_fpx_reference(): void
    {
        $this->actingAsClubAdmin($this->club);
        $fee = $this->fee();
        $this->openSingleBill($fee); // reference PAY-…, gateway toyyibpay
        $fee->bills()->update(['opened_at' => now()->subDays(5), 'created_at' => now()->subDays(5)]); // abandoned: not in progress
        $this->patch(route('admin.payments.mark-paid', $fee), ['method' => 'cash', 'paid_date' => '2026-11-16'])->assertSessionHas('success');
        $this->assertNull($fee->fresh()->reference);

        $this->actingAs($this->member)->get(route('member.payments.invoice', $fee))->assertInertia(fn (Assert $p) => $p
            ->where('receipt.method', 'manual')
            ->where('receipt.gateway', null)
            ->where('receipt.reference', null)
            ->where('receipt.transactionId', null));
    }

    public function test_own_fee_and_verifying_guards_still_apply(): void
    {
        $admin = $this->actingAsClubAdmin($this->club);
        $own = Payment::factory()->forClub($this->club)->forMember($admin)->create(['status' => 'pending', 'paid_date' => null]);
        $this->patch(route('admin.payments.mark-paid', $own), $this->recordPayment())->assertSessionHas('error');
        $this->patch(route('admin.payments.mark-paid', $own), [])->assertSessionHas('error'); // refused before any form check
        $this->assertSame('pending', $own->fresh()->status);

        $verifying = $this->fee(['status' => 'pending_verification']);
        $this->patch(route('admin.payments.mark-paid', $verifying), $this->recordPayment())
            ->assertRedirect(route('admin.clubs.verifications.index', $this->club));
        $this->assertSame('pending_verification', $verifying->fresh()->status);
    }

    public function test_a_gateway_payment_after_a_hand_recorded_one_is_flagged_for_a_refund(): void
    {
        config(['toyyibpay.webhook_secret' => self::TOYYIB_SECRET]);
        Mail::fake();
        $this->fakeToyyibConfirm();
        $admin = $this->actingAsClubAdmin($this->club);
        $cash = $this->fee();
        $transfer = $this->fee();
        foreach ([[$cash, 'cash'], [$transfer, 'transfer']] as [$fee, $method]) {
            $this->openSingleBill($fee);
            $ref = $fee->fresh()->reference;
            $code = $fee->fresh()->bill_code;
            $this->patch(route('admin.payments.mark-paid', $fee), ['method' => $method, 'paid_date' => '2026-11-16', 'confirm_processing' => '1'])->assertSessionHas('success');

            $url = route('webhook.toyyibpay').'?webhook_token='.$this->toyyibToken($ref);
            $payload = ['billcode' => $code, 'order_id' => $ref, 'status_id' => 1, 'transaction_id' => 'TXN-'.$method];
            $this->post($url, $payload)->assertOk();
            $this->post($url, $payload)->assertOk(); // flagged once

            $flag = AuditLog::where('action', 'payment.gateway_paid_after_manual')->where('auditable_id', $fee->id)->sole();
            $this->assertSame($method, $flag->new_values['paid_via']);
            $this->assertSame('TXN-'.$method, $flag->new_values['transaction_id']);
            $this->assertStringContainsString('Both payments reached the club: refund the online payment', $flag->description);
            $this->assertSame('paid', $fee->fresh()->status);
            $this->assertSame($method, $fee->fresh()->paid_via);
        }
        $this->assertSame(0, AuditLog::whereIn('action', ['payment.gateway_paid_during_verification', 'payment.gateway_paid_twice'])->count());

        // The admin sees it on the payment page and in Verifications > Recently handled.
        $show = $this->get(route('admin.payments.show', $cash))->assertOk();
        $show->assertSee('data-testid="refund-flag"', false)
            ->assertSee('Paid online after it was recorded as cash: both payments reached the club. Refund the online payment.')
            ->assertSee('TXN-cash');
        $this->get(route('admin.clubs.verifications.index', [$this->club, 'tab' => 'handled']))->assertInertia(fn (Assert $p) => $p
            ->where('handled.0.outcome', 'gateway_paid_after_manual')
            ->where('handled.0.recordedAs', 'bank transfer')
            ->where('handled.1.outcome', 'gateway_paid_after_manual')
            ->where('handled.1.recordedAs', 'cash'));
    }

    public function test_group_and_underpaid_flags_show_on_every_fee_and_in_verifications(): void
    {
        $this->actingAsClubAdmin($this->club);
        $lead = $this->fee();
        $other = $this->fee();
        $group = $this->openGroupBill([$lead, $other]);
        \App\Services\AuditService::log('payment.group_bill_refund', 'Group bill refund needed.', $lead, $this->club->id,
            ['amount_sen' => 2000, 'payment_ids' => [$lead->id, $other->id]],
            ['gateway' => 'toyyibpay', 'transaction_id' => 'TXN-G1', 'bill_code' => $group->bill_code, 'paid_amount_sen' => 2000]);
        $single = $this->fee();
        \App\Services\AuditService::log('payment.underpaid_webhook', 'Underpaid.', $single, $this->club->id,
            ['status' => 'pending'], ['paid_amount_sen' => 500, 'transaction_id' => 'TXN-U1', 'bill_code' => 'B-U1']);

        foreach ([$lead, $other] as $fee) {
            $this->get(route('admin.payments.show', $fee))->assertOk()
                ->assertSee('data-testid="refund-flag"', false)
                ->assertSee("Online payment received for a group bill ({$group->bill_code}), but nothing was marked paid: refund it, or record the fees.")
                ->assertSee('TXN-G1');
        }
        $this->get(route('admin.payments.show', $single))->assertOk()
            ->assertSee('Online payment was less than the bill (B-U1): nothing was marked paid.')
            ->assertDontSee('TXN-G1');

        $this->get(route('admin.clubs.verifications.index', [$this->club, 'tab' => 'handled']))->assertInertia(fn (Assert $p) => $p
            ->where('handled.0.outcome', 'underpaid')
            ->where('handled.1.outcome', 'group_refund')
            ->where('handled.1.reference', 'TXN-G1'));
    }

    public function test_a_reverted_slip_fee_recorded_by_transfer_gets_the_after_manual_flag(): void
    {
        config(['toyyibpay.webhook_secret' => self::TOYYIB_SECRET]);
        Mail::fake();
        $this->fakeToyyibConfirm();
        $admin = $this->actingAsClubAdmin($this->club);
        $fee = $this->fee();
        $this->openSingleBill($fee);
        $ref = $fee->fresh()->reference;
        $code = $fee->fresh()->bill_code;
        // Paid by a checked slip, then reverted by an admin.
        $fee->forceFill(['status' => 'paid', 'paid_date' => '2026-11-10', 'paid_via' => 'transfer', 'transfer_submitted_at' => now(), 'transfer_ref' => 'M-1',
            'transfer_bank_ref' => 'B-1', 'verified_by' => $admin->id, 'verified_at' => now()])->save();
        $this->put(route('admin.payments.update', $fee), ['amount' => '10.00', 'due_date' => $fee->due_date->toDateString(), 'status' => 'pending', 'original_status' => 'paid'])
            ->assertSessionHasNoErrors();
        $fresh = $fee->fresh();
        foreach (['paid_via', 'verified_by', 'transfer_submitted_at', 'transfer_bank_ref', 'transfer_ref', 'transfer_group_id'] as $col) {
            $this->assertNull($fresh->{$col}, $col);
        }

        $this->patch(route('admin.payments.mark-paid', $fee), ['method' => 'transfer', 'paid_date' => '2026-11-16', 'confirm_processing' => '1'])->assertSessionHas('success');
        $url = route('webhook.toyyibpay').'?webhook_token='.$this->toyyibToken($ref);
        $this->post($url, ['billcode' => $code, 'order_id' => $ref, 'status_id' => 1, 'transaction_id' => 'TXN-REV'])->assertOk();

        $this->assertSame(1, AuditLog::where('action', 'payment.gateway_paid_after_manual')->where('auditable_id', $fee->id)->count());
        $this->assertSame(0, AuditLog::where('action', 'payment.gateway_paid_during_verification')->count());
        $this->get(route('admin.payments.show', $fee))->assertSee('Paid online after it was recorded as bank transfer: both payments reached the club. Refund the online payment.');
    }

    // ── Admin pages: one status word, FPX in progress, the dialog ──────

    public function test_blade_payments_show_one_status_word_and_the_record_dialog(): void
    {
        $this->actingAsClubAdmin($this->club);
        $this->travelTo(Carbon::create(2026, 9, 20, 10));
        $due = $this->fee(['due_date' => '2026-09-15']);      // 5 days late: Due
        // All in September (the list opens on the current month).
        $sep = ['paid_date' => null, 'period_start' => '2026-09-01', 'period_end' => '2026-09-30'];
        $overdue = Payment::factory()->forClub($this->club)->forMember(User::factory()->create())->create($sep + ['status' => 'overdue', 'due_date' => '2026-09-01']);
        $processing = Payment::factory()->forClub($this->club)->forMember(User::factory()->create(['name' => 'Siti Contoh']))->create($sep + ['status' => 'pending', 'due_date' => '2026-09-01', 'amount' => 10.00]);
        $this->openSingleBill($processing);

        $res = $this->get(route('admin.clubs.payments.index', $this->club))->assertOk();
        $x = $this->dom($res->getContent());
        $word = fn (Payment $p) => trim($x->query("//button[@data-record-id='{$p->id}']/ancestor::tr//td[contains(@class,'d-md-table-cell')]//span[@data-status]")->item(0)?->textContent ?? '');
        $this->assertSame('Due', $word($due));
        $this->assertSame('Overdue', $word($overdue));
        $this->assertSame('FPX in progress', $word($processing));
        $this->assertStringNotContainsString('Pending', $x->query('//table')->item(0)->textContent);

        // The dialog: restates who and how much, asks method/date/reference, warns about FPX.
        $btn = $x->query("//button[@data-record-id='{$processing->id}']")->item(0);
        $this->assertSame('#recordPaymentModal', $btn->getAttribute('data-bs-target'));
        $this->assertSame('1', $btn->getAttribute('data-record-processing'));
        $this->assertSame('Siti Contoh', $btn->getAttribute('data-record-member'));
        $this->assertSame('RM 10.00', $btn->getAttribute('data-record-amount'));
        $this->assertSame('2026-09-01', $btn->getAttribute('data-record-min'));
        $this->assertSame('0', $x->query("//button[@data-record-id='{$due->id}']")->item(0)->getAttribute('data-record-processing'));
        $this->assertStringContainsString('Record payment… for Siti Contoh', preg_replace('/\s+/', ' ', $btn->textContent));
        foreach (['rp-method-cash', 'rp-method-transfer', 'rp-method-other', 'rp-date', 'rp-ref', 'rp-confirm'] as $id) {
            $this->assertSame(1, $x->query("//label[@for='{$id}']")->length, "label for {$id}");
        }
        $this->assertSame(1, $x->query('//div[@id="recordPaymentModal"]//input[@name="reference"]')->length);
        $summary = preg_replace('/\s+/', ' ', $x->query('//section[@aria-label="Summary"]')->item(0)->textContent);
        $this->assertStringContainsString('FPX in progress 1', $summary);
        $this->assertStringContainsString('Overdue 1', $summary);
        $this->assertStringContainsString('Due 1', $summary);

        // Row actions have visible words; edit and delete are under "More".
        $row = $x->query("//button[@data-record-id='{$due->id}']/ancestor::tr")->item(0);
        $text = preg_replace('/\s+/', ' ', $row->textContent);
        foreach (['View', 'Record payment', 'More', 'Edit', 'Delete'] as $w) {
            $this->assertStringContainsString($w, $text);
        }

        // Filters use the same words.
        $this->get(route('admin.clubs.payments.index', ['club' => $this->club, 'status' => 'processing']))
            ->assertViewHas('payments', fn ($p) => $p->pluck('id')->all() === [$processing->id]);
        $this->get(route('admin.clubs.payments.index', ['club' => $this->club, 'status' => 'due']))
            ->assertViewHas('payments', fn ($p) => $p->pluck('id')->all() === [$due->id]);
        $this->get(route('admin.clubs.payments.index', ['club' => $this->club, 'status' => 'pending']))
            ->assertViewHas('status', 'due');

        // The show page offers the same dialog.
        $show = $this->dom($this->get(route('admin.payments.show', $processing))->assertOk()->getContent());
        $this->assertSame(1, $show->query("//button[@data-record-id='{$processing->id}']")->length);
        $this->assertSame(1, $show->query('//div[@id="recordPaymentModal"]')->length);
        $this->assertStringContainsString('FPX in progress', $show->query('//div[contains(@class,"card-header")]')->item(0)->textContent);
    }

    public function test_the_dialog_reopens_with_the_error_after_a_refused_submit(): void
    {
        $this->actingAsClubAdmin($this->club);
        $fee = $this->fee();
        $this->from(route('admin.clubs.payments.index', $this->club))
            ->patch(route('admin.payments.mark-paid', $fee), ['payment_id' => $fee->id, 'method' => 'cash', 'paid_date' => '2030-01-01', 'reference' => 'R-1'])
            ->assertRedirect(route('admin.clubs.payments.index', $this->club));

        $x = $this->dom($this->get(route('admin.clubs.payments.index', $this->club))->getContent());
        $this->assertSame((string) $fee->id, $x->query('//div[@id="recordPaymentModal"]')->item(0)->getAttribute('data-old-id'));
        $date = $x->query('//input[@id="rp-date"]')->item(0);
        $this->assertSame('true', $date->getAttribute('aria-invalid'));
        $this->assertContains('rp-date-error', preg_split('/\s+/', $date->getAttribute('aria-describedby')));
        $this->assertSame('alert', $x->query('//*[@id="rp-date-error"]')->item(0)->getAttribute('role'));
        $this->assertSame('R-1', $x->query('//input[@id="rp-ref"]')->item(0)->getAttribute('value'));
        $this->assertSame('checked', $x->query('//input[@id="rp-method-cash"]')->item(0)->getAttribute('checked'));
    }

    public function test_dialog_errors_mark_one_method_radio_and_keep_the_confirm_error_readable(): void
    {
        $this->actingAsClubAdmin($this->club);
        $fee = $this->fee();
        $this->openSingleBill($fee);

        // Method missing and FPX not confirmed: the first choice carries the error; the confirm error sits outside the amber alert.
        $this->from(route('admin.clubs.payments.index', $this->club))
            ->patch(route('admin.payments.mark-paid', $fee), ['payment_id' => $fee->id, 'paid_date' => '2026-11-16'])
            ->assertRedirect(route('admin.clubs.payments.index', $this->club));
        $html = $this->get(route('admin.clubs.payments.index', $this->club))->getContent();
        $x = $this->dom($html);
        $this->assertSame('true', $x->query('//input[@id="rp-method-cash"]')->item(0)->getAttribute('aria-invalid'));
        $this->assertSame('', $x->query('//input[@id="rp-method-transfer"]')->item(0)->getAttribute('aria-invalid'));
        $this->assertSame(0, $x->query('//input[@name="method" and contains(@class,"is-invalid")]')->length);
        $this->from(route('admin.clubs.payments.index', $this->club))
            ->patch(route('admin.payments.mark-paid', $fee), ['payment_id' => $fee->id, 'method' => 'cash', 'paid_date' => '2026-11-16'])
            ->assertRedirect(route('admin.clubs.payments.index', $this->club));
        $x = $this->dom($this->get(route('admin.clubs.payments.index', $this->club))->getContent());
        $err = $x->query('//*[@id="rp-confirm-error"]')->item(0);
        $this->assertSame('alert', $err->getAttribute('role'));
        $this->assertSame('Tick the box to confirm.', trim($err->textContent));
        $this->assertSame(0, $x->query('//*[@id="rp-processing"]//*[@id="rp-confirm-error"]')->length, 'not inside the amber alert');
    }

    public function test_ledger_outstanding_uses_the_same_words(): void
    {
        $this->actingAsClubAdmin($this->club);
        $due = $this->fee(['due_date' => '2026-11-12']);
        $overdue = $this->fee(['due_date' => '2026-10-01']);
        $processing = $this->fee(['due_date' => '2026-10-01']);
        $this->openSingleBill($processing);
        $verifying = $this->fee(['status' => 'pending_verification']);

        $this->get(route('admin.clubs.ledger', $this->club))->assertInertia(fn (Assert $p) => $p
            ->component('Admin/Ledger/Index')
            ->where('outstanding.rows.0.id', $overdue->id)
            ->where('outstanding.rows.0.status', 'overdue')
            ->where('outstanding.rows.1.status', 'due')
            ->where('outstanding.rows.2.id', $processing->id)
            ->where('outstanding.rows.2.status', 'processing')
            ->where('outstanding.rows.3.status', 'verifying')
            ->where('outstanding.overdue', 1000)
            ->where('outstanding.due', 1000)
            ->where('outstanding.processing', 1000)
            ->where('outstanding.verifying', 1000)
            ->where('outstanding.total', 4000)
            ->where('outstanding.graceDays', 7));

        $csv = $this->get(route('admin.clubs.ledger.export', $this->club))->streamedContent();
        $this->assertStringContainsString('FPX IN PROGRESS', $csv);
        $this->assertStringContainsString('TOTAL DUE (RM)', $csv);
        $this->assertStringNotContainsString('PENDING', $csv);

        $this->get(route('admin.clubs.dashboard.classic', $this->club))->assertOk()
            ->assertViewHas('processingCount', 1)->assertViewHas('overdueCount', 1)->assertViewHas('pendingCount', 1)->assertViewHas('verifyingCount', 1);
    }

    // ── Billing: the grace period ───────────────────────────────────────

    public function test_billing_settings_edit_the_grace_period(): void
    {
        $this->actingAsClubAdmin($this->club);
        $valid = ['allow_monthly' => '1', 'allow_quarterly' => '1', 'allow_yearly' => '1', 'default_plan' => 'yearly', 'gateway_fee_payer' => 'club', 'gateway_fee' => '1.00'];

        $this->get(route('admin.clubs.billing.edit', $this->club))->assertInertia(fn (Assert $p) => $p->where('settings.overdue_grace_days', '7'));

        foreach (['-1', '61', '2.5', 'abc', ''] as $bad) {
            $this->put(route('admin.clubs.billing.update', $this->club), $valid + ['overdue_grace_days' => $bad])->assertSessionHasErrors('overdue_grace_days');
        }
        $this->assertSame(7, $this->club->fresh()->overdueGraceDays());

        $this->put(route('admin.clubs.billing.update', $this->club), $valid + ['overdue_grace_days' => '14'])->assertSessionHasNoErrors();
        $this->assertSame(14, $this->club->fresh()->overdue_grace_days);
        $log = AuditLog::where('action', 'club.billing_updated')->latest('id')->first();
        $this->assertSame(7, $log->old_values['overdue_grace_days']);
        $this->assertSame(14, $log->new_values['overdue_grace_days']);
        $this->assertStringContainsString('overdue after 14 day(s)', $log->description);

        // An older client that does not send it keeps the setting.
        $this->put(route('admin.clubs.billing.update', $this->club), $valid)->assertSessionHasNoErrors();
        $this->assertSame(14, $this->club->fresh()->overdue_grace_days);

        $this->put(route('admin.clubs.billing.update', $this->club), $valid + ['overdue_grace_days' => '0'])->assertSessionHasNoErrors();
        $this->assertSame(0, $this->club->fresh()->overdueGraceDays());
    }
}
