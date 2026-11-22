<?php

namespace Tests\Feature;

use App\Mail\PaymentConfirmation;
use App\Models\AuditLog;
use App\Models\Club;
use App\Models\Payment;
use App\Models\PaymentBill;
use App\Models\User;
use App\Support\Member\MemberPaymentPresenter;
use App\Support\Payments\ReceiptNumbers;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Settings phase 2: receipt numbers per club, {PREFIX}-{FY start year}-{NNNN}, a sequence per club and financial
 * year, issued when a fee becomes paid, shared by the fees of one group receipt, never reused.
 */
class ReceiptNumberTest extends TestCase
{
    private Club $club;

    private User $member;

    private int $month = 0;

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
            'payment_gateway' => 'toyyibpay',
            'toyyibpay_secret_key' => 'club-secret',
            'toyyibpay_category_code' => 'cat-123',
            'transfer_enabled' => true,
            'bank_name' => 'Bank Contoh',
            'bank_account_name' => 'Kelab',
            'bank_account_no' => '1234 5678 9012',
        ])->fresh();
        $this->member = $this->join(User::factory()->create(['role' => 'member']));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function join(User $user, string $role = 'member', ?Club $club = null): User
    {
        ($club ?? $this->club)->members()->attach($user, ['role' => $role, 'job_level' => 'executive', 'joined_date' => now()->toDateString(), 'is_active' => true]);

        return $user;
    }

    /** An open fee for a month of its own (one fee per member and period). */
    private function fee(array $attrs = [], ?Club $club = null): Payment
    {
        $start = Carbon::create(2026, 1, 1)->addMonths($this->month++);

        return Payment::factory()->forClub($club ?? $this->club)->forMember($this->member)->pending()->create($attrs + [
            'amount' => 10.00,
            'period_start' => $start->toDateString(),
            'period_end' => $start->copy()->endOfMonth()->toDateString(),
            'due_date' => $start->toDateString(),
        ]);
    }

    private function pay(Payment $fee): Payment
    {
        $fee->fresh()->update(['status' => 'paid', 'paid_date' => today()->toDateString()]);

        return $fee->fresh();
    }

    private function links(int $paymentId)
    {
        return DB::table('receipt_allocation_payments')->where('payment_id', $paymentId)->orderBy('id')->get();
    }

    // ── Format and sequence ─────────────────────────────────────────────

    public function test_an_unpaid_fee_has_no_number_and_paid_fees_are_numbered_in_order(): void
    {
        $a = $this->fee();
        $this->assertNull($a->receipt_no);

        $this->assertSame('RCP-2026-0001', $this->pay($a)->receipt_no);
        $this->assertSame('RCP-2026-0002', $this->pay($this->fee())->receipt_no);

        $alloc = DB::table('receipt_allocations')->where('receipt_no', 'RCP-2026-0001')->sole();
        $this->assertSame($this->club->id, (int) $alloc->club_id);
        $this->assertSame(2026, (int) $alloc->fy_start_year);
        $this->assertSame(1, (int) $alloc->seq);
        $this->assertNull($alloc->payment_bill_id);
        $this->assertCount(1, $this->links($a->id));
        $this->assertSame(2, (int) DB::table('receipt_counters')->where('club_id', $this->club->id)->value('last_no'));
    }

    public function test_every_path_that_marks_a_fee_paid_numbers_it(): void
    {
        // An admin recording it.
        $recorded = $this->fee();
        $this->actingAsClubAdmin($this->club);
        $this->patch(route('admin.payments.mark-paid', $recorded), $this->recordPayment())->assertSessionHasNoErrors();
        $this->assertSame('RCP-2026-0001', $recorded->fresh()->receipt_no);

        // A gateway callback.
        $this->fakeToyyibConfirm();
        $online = $this->fee(['bill_code' => 'BILL-1', 'reference' => 'REF-1']);
        auth()->logout();
        $this->post(route('webhook.toyyibpay').'?webhook_token='.$this->toyyibToken('REF-1'), [
            'billcode' => 'BILL-1', 'order_id' => 'REF-1', 'status_id' => 1, 'transaction_id' => 'TXN-1',
        ])->assertOk();
        $this->assertSame('RCP-2026-0002', $online->fresh()->receipt_no);

        // A transfer slip approved.
        $slip = $this->fee();
        $admin = $this->join(User::factory()->create(['role' => 'admin']), 'admin');
        $this->actingAs($this->member)->post(route('member.payments.transfer', $slip), [
            'files' => [UploadedFile::fake()->image('slip.png', 600, 800)],
            'transfer_date' => today()->toDateString(),
        ])->assertSessionHasNoErrors();
        $this->assertNull($slip->fresh()->receipt_no, 'a slip being checked is not paid yet');
        $this->actingAs($admin)->post(route('admin.verifications.approve', $slip), ['paid_date' => today()->toDateString()])->assertSessionHasNoErrors();
        $this->assertSame('RCP-2026-0003', $slip->fresh()->receipt_no);

        // A fee created as paid: numbered, and logged against its new id.
        $created = Payment::factory()->forClub($this->club)->forMember($this->member)->create(['period_start' => '2027-01-01']);
        $this->assertSame('RCP-2026-0004', $created->fresh()->receipt_no);
        $this->assertCount(1, $this->links($created->id));
    }

    public function test_later_saves_of_a_paid_fee_keep_its_number(): void
    {
        $fee = $this->pay($this->fee());
        $fee->update(['notes' => 'checked']);
        $fee->fresh()->update(['paid_date' => today()->subDay()->toDateString()]);

        $this->assertSame('RCP-2026-0001', $fee->fresh()->receipt_no);
        $this->assertSame(1, DB::table('receipt_allocations')->count());
    }

    public function test_each_club_has_its_own_sequence(): void
    {
        $other = Club::factory()->create();
        $this->join($this->member, club: $other);

        $this->assertSame('RCP-2026-0001', $this->pay($this->fee())->receipt_no);
        $this->assertSame('RCP-2026-0001', $this->pay($this->fee(club: $other))->receipt_no);
        $this->assertSame('RCP-2026-0002', $this->pay($this->fee())->receipt_no);
    }

    public function test_the_sequence_starts_again_each_financial_year(): void
    {
        $this->club->forceFill(['financial_year_start_month' => 7])->save();

        Carbon::setTestNow('2026-06-30 23:59:00');
        $this->assertSame('RCP-2025-0001', $this->pay($this->fee())->receipt_no);
        $this->assertSame('RCP-2025-0002', $this->pay($this->fee())->receipt_no);

        Carbon::setTestNow('2026-07-01 00:00:00');
        $this->assertSame('RCP-2026-0001', $this->pay($this->fee())->receipt_no);

        // A calendar-year club rolls over on 1 January.
        $cal = Club::factory()->create(['financial_year_start_month' => 1]);
        $this->join($this->member, club: $cal);
        Carbon::setTestNow('2026-12-31 12:00:00');
        $this->assertSame('RCP-2026-0001', $this->pay($this->fee(club: $cal))->receipt_no);
        Carbon::setTestNow('2027-01-01 08:00:00');
        $this->assertSame('RCP-2027-0001', $this->pay($this->fee(club: $cal))->receipt_no);
    }

    public function test_the_year_is_the_one_the_number_is_issued_in_not_a_backdated_paid_date(): void
    {
        $this->club->forceFill(['financial_year_start_month' => 7])->save();
        Carbon::setTestNow('2026-07-02 10:00:00');
        $fee = $this->fee();

        $fee->update(['status' => 'paid', 'paid_date' => '2026-06-28']);

        $this->assertSame('RCP-2026-0001', $fee->fresh()->receipt_no);
    }

    public function test_numbers_past_9999_keep_counting(): void
    {
        DB::table('receipt_counters')->insert(['club_id' => $this->club->id, 'fy_start_year' => 2026, 'last_no' => 9999]);

        $this->assertSame('RCP-2026-10000', $this->pay($this->fee())->receipt_no);
    }

    // ── Group receipts: one document, one number ────────────────────────

    public function test_a_group_bill_shares_one_number_across_its_fees(): void
    {
        $fees = [$this->fee(), $this->fee(['amount' => 20.00]), $this->fee(['amount' => 30.00])];
        Http::fake(['dev.toyyibpay.com/index.php/api/createBill' => Http::response([['BillCode' => 'GRP1']])]);
        $this->actingAs($this->member)->post(route('member.payments.pay-all', $this->club), [
            'payment_ids' => array_map(fn ($f) => $f->id, $fees),
            'total_sen' => 6000,
        ])->assertRedirect('https://dev.toyyibpay.com/GRP1');
        $bill = PaymentBill::where('bill_code', 'GRP1')->sole();
        $this->fakeToyyibConfirm();
        auth()->logout();

        $this->post(route('webhook.toyyibpay').'?webhook_token='.$this->toyyibToken($bill->reference), [
            'billcode' => 'GRP1', 'order_id' => $bill->reference, 'status_id' => 1, 'transaction_id' => 'TXN-G', 'amount' => '60.00',
        ])->assertOk();

        $bill->refresh();
        $this->assertSame('RCP-2026-0001', $bill->receipt_no);
        foreach ($fees as $fee) {
            $this->assertSame('RCP-2026-0001', $fee->fresh()->receipt_no);
        }
        $alloc = DB::table('receipt_allocations')->sole();
        $this->assertSame($bill->id, (int) $alloc->payment_bill_id);
        $this->assertSame(3, DB::table('receipt_allocation_payments')->where('receipt_allocation_id', $alloc->id)->count());

        // The email carries the one number; the next receipt takes the next one.
        Mail::assertSent(PaymentConfirmation::class, function (PaymentConfirmation $m) {
            $html = $m->render();

            return str_contains($html, 'RCP-2026-0001') && str_contains($html, 'INV-');
        });
        $this->assertSame('RCP-2026-0002', $this->pay($this->fee())->receipt_no);

        // The member's group receipt.
        $this->actingAs($this->member)->get(route('member.payments.group-result', $bill))
            ->assertInertia(fn (Assert $page) => $page->where('receipt.receiptNo', 'RCP-2026-0001')
                ->where('receipt.lines.1.receiptNo', 'RCP-2026-0001'));
    }

    public function test_a_group_transfer_slip_shares_one_number_across_its_fees(): void
    {
        $fees = [$this->fee(), $this->fee(['amount' => 20.00])];
        $admin = $this->join(User::factory()->create(['role' => 'admin']), 'admin');
        $this->actingAs($this->member)->post(route('member.payments.transfer-all', $this->club), [
            'payment_ids' => [$fees[0]->id, $fees[1]->id],
            'total_sen' => 3000,
            'files' => [UploadedFile::fake()->image('slip.png', 600, 800)],
            'transfer_date' => today()->toDateString(),
        ])->assertSessionHasNoErrors();
        $group = PaymentBill::where('method', 'transfer')->sole();

        $this->actingAs($admin)->post(route('admin.verifications.approve', $fees[0]), ['paid_date' => today()->toDateString()])
            ->assertSessionHasNoErrors();

        $this->assertSame('RCP-2026-0001', $group->fresh()->receipt_no);
        $this->assertSame('RCP-2026-0001', $fees[0]->fresh()->receipt_no);
        $this->assertSame('RCP-2026-0001', $fees[1]->fresh()->receipt_no);
        $this->assertSame(1, DB::table('receipt_allocations')->count());
        Mail::assertSent(PaymentConfirmation::class, fn (PaymentConfirmation $m) => $m->group?->receipt_no === 'RCP-2026-0001');

        $this->actingAs($this->member)->get(route('member.payments.group-result', $group))
            ->assertInertia(fn (Assert $page) => $page->where('receipt.receiptNo', 'RCP-2026-0001'));

        // Each fee's own receipt page says it is part of that receipt (count and total) and links to it, so the
        // one number never reads as a RM 20 receipt and a RM 30 receipt at once.
        $this->actingAs($this->member)->get(route('member.payments.invoice', $fees[1]))
            ->assertInertia(fn (Assert $page) => $page->where('receipt.receiptNo', 'RCP-2026-0001')
                ->where('receipt.partOf.receiptNo', 'RCP-2026-0001')
                ->where('receipt.partOf.count', 2)
                ->where('receipt.partOf.amount', 3000)
                ->where('receipt.partOf.url', route('member.payments.group-result', $group)));

        // A fee paid on its own has no such note.
        $single = $this->pay($this->fee());
        $this->actingAs($this->member)->get(route('member.payments.invoice', $single))
            ->assertInertia(fn (Assert $page) => $page->where('receipt.partOf', null));
    }

    // ── Never reused ────────────────────────────────────────────────────

    public function test_a_fee_taken_back_from_paid_keeps_its_number_as_void_and_gets_a_new_one_when_paid_again(): void
    {
        $fee = $this->pay($this->fee());
        $this->assertSame('RCP-2026-0001', $fee->receipt_no);
        $this->actingAsClubAdmin($this->club);

        $this->put(route('admin.payments.update', $fee), [
            'due_date' => $fee->due_date->toDateString(), 'amount' => '10.00',
            'status' => 'pending', 'original_status' => 'paid',
        ])->assertSessionHasNoErrors();

        $this->assertNull($fee->fresh()->receipt_no, 'no longer a receipt');
        $this->assertNotNull($this->links($fee->id)->sole()->voided_at, 'the number is kept, as void');
        $this->assertTrue(DB::table('receipt_allocations')->where('receipt_no', 'RCP-2026-0001')->exists());

        $this->patch(route('admin.payments.mark-paid', $fee), $this->recordPayment())->assertSessionHasNoErrors();

        $this->assertSame('RCP-2026-0002', $fee->fresh()->receipt_no);
        $links = $this->links($fee->id);
        $this->assertCount(2, $links);
        $this->assertNull($links[1]->voided_at);

        // The admin sees both.
        $this->get(route('admin.payments.show', $fee))->assertOk()
            ->assertSee('RCP-2026-0002')
            ->assertSee('data-testid="voided-receipt-no"', false)
            ->assertSee('RCP-2026-0001');
    }

    public function test_an_online_group_receipt_drops_a_fee_that_was_taken_back_and_receipted_again(): void
    {
        $fees = [$this->fee(), $this->fee(['amount' => 20.00])];
        Http::fake(['dev.toyyibpay.com/index.php/api/createBill' => Http::response([['BillCode' => 'GRP2']])]);
        $this->actingAs($this->member)->post(route('member.payments.pay-all', $this->club), [
            'payment_ids' => [$fees[0]->id, $fees[1]->id], 'total_sen' => 3000,
        ]);
        $bill = PaymentBill::where('bill_code', 'GRP2')->sole();
        $this->fakeToyyibConfirm();
        auth()->logout();
        $this->post(route('webhook.toyyibpay').'?webhook_token='.$this->toyyibToken($bill->reference), [
            'billcode' => 'GRP2', 'order_id' => $bill->reference, 'status_id' => 1, 'transaction_id' => 'TXN-G2', 'amount' => '30.00',
        ])->assertOk();

        $fees[1]->fresh()->update(['status' => 'pending', 'paid_date' => null]);
        $this->pay($fees[1]);
        $this->assertSame('RCP-2026-0002', $fees[1]->fresh()->receipt_no);

        $receipt = \App\Support\Member\MemberPaymentPresenter::groupReceipt($bill->fresh(), collect([$fees[0]->fresh(), $fees[1]->fresh()]));
        $this->assertSame('RCP-2026-0001', $receipt['receiptNo']);
        $this->assertSame([$fees[0]->id], array_column($receipt['lines'], 'id'), 'the re-receipted fee is not on the old receipt');
        $this->assertSame(1000, $receipt['amount']);
    }

    public function test_deleting_a_paid_fee_voids_its_number_which_is_never_reused(): void
    {
        $fee = $this->pay($this->fee());
        $this->assertSame('RCP-2026-0001', $fee->fresh()->receipt_no);

        $fee->fresh()->delete();

        $this->assertNotNull(DB::table('receipt_allocation_payments')->where('payment_id', $fee->id)->value('voided_at'));
        $this->assertSame('RCP-2026-0002', $this->pay($this->fee())->fresh()->receipt_no);
    }

    public function test_a_group_fee_taken_back_and_paid_alone_gets_its_own_new_number(): void
    {
        $fees = [$this->fee(), $this->fee(['amount' => 20.00])];
        $admin = $this->join(User::factory()->create(['role' => 'admin']), 'admin');
        $this->actingAs($this->member)->post(route('member.payments.transfer-all', $this->club), [
            'payment_ids' => [$fees[0]->id, $fees[1]->id], 'total_sen' => 3000,
            'files' => [UploadedFile::fake()->image('slip.png', 600, 800)],
            'transfer_date' => today()->toDateString(),
        ])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('admin.verifications.approve', $fees[0]), ['paid_date' => today()->toDateString()])->assertSessionHasNoErrors();

        // One fee of the set taken back, then recorded again.
        $fees[1]->fresh()->update(['status' => 'pending', 'paid_date' => null]);
        $this->assertSame('RCP-2026-0001', $fees[0]->fresh()->receipt_no, 'the rest of the set keeps the number');
        $this->pay($fees[1]);

        $this->assertSame('RCP-2026-0002', $fees[1]->fresh()->receipt_no);
    }

    public function test_the_unique_index_refuses_a_number_twice_and_nothing_is_paid(): void
    {
        // As if the counter were out of step with the log: the next number already exists.
        DB::table('receipt_allocations')->insert([
            'club_id' => $this->club->id, 'fy_start_year' => 2026, 'seq' => 1, 'receipt_no' => 'RCP-2026-0001', 'created_at' => now(),
        ]);
        $fee = $this->fee();

        try {
            DB::transaction(fn () => $fee->update(['status' => 'paid', 'paid_date' => today()->toDateString()]));
            $this->fail('A duplicate receipt number was issued.');
        } catch (QueryException) {
            // The whole settlement rolls back.
        }

        $this->assertSame('pending', $fee->fresh()->status);
        $this->assertNull($fee->fresh()->receipt_no);
        $this->assertSame(1, DB::table('receipt_allocations')->count());
    }

    public function test_a_counter_row_created_by_another_settlement_is_advanced_not_replaced(): void
    {
        // Another settlement created this year's row first (the concurrent-insert case): the upsert advances it.
        DB::table('receipt_counters')->insert(['club_id' => $this->club->id, 'fy_start_year' => 2026, 'last_no' => 41]);

        $this->assertSame('RCP-2026-0042', $this->pay($this->fee())->receipt_no);
        $this->assertSame(42, (int) DB::table('receipt_counters')->where('club_id', $this->club->id)->value('last_no'));
        $this->assertSame(1, DB::table('receipt_counters')->count());
    }

    public function test_several_fees_paid_in_one_transaction_get_distinct_numbers(): void
    {
        $fees = [$this->fee(), $this->fee(), $this->fee(), $this->fee()];

        DB::transaction(function () use ($fees) {
            foreach ($fees as $fee) {
                $fee->update(['status' => 'paid', 'paid_date' => today()->toDateString()]);
            }
        });

        $numbers = array_map(fn ($f) => $f->fresh()->receipt_no, $fees);
        $this->assertSame(['RCP-2026-0001', 'RCP-2026-0002', 'RCP-2026-0003', 'RCP-2026-0004'], $numbers);
    }

    public function test_a_rolled_back_settlement_takes_no_number(): void
    {
        $fee = $this->fee();
        try {
            DB::transaction(function () use ($fee) {
                $fee->update(['status' => 'paid', 'paid_date' => today()->toDateString()]);
                throw new \RuntimeException('gateway check failed');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame(0, DB::table('receipt_allocations')->count());
        $this->assertSame('RCP-2026-0001', $this->pay($this->fee())->receipt_no);
    }

    public function test_it_cannot_be_mass_assigned(): void
    {
        $this->assertNotContains('receipt_no', (new Payment)->getFillable());
        $this->assertNotContains('receipt_prefix', (new Club)->getFillable());
    }

    // ── The prefix (club profile) ───────────────────────────────────────

    private function profile(array $over = []): array
    {
        return $over + ['name' => $this->club->name, 'financial_year_start_month' => 1];
    }

    public function test_a_new_prefix_applies_only_to_later_receipts_and_the_sequence_carries_on(): void
    {
        $first = $this->pay($this->fee());
        $this->actingAsClubAdmin($this->club);

        $this->put(route('admin.clubs.profile.update', $this->club), $this->profile(['receipt_prefix' => 'kbs1']))
            ->assertSessionHasNoErrors();
        $this->assertSame('KBS1', $this->club->fresh()->receipt_prefix);

        $this->assertSame('RCP-2026-0001', $first->fresh()->receipt_no);
        $this->assertSame('KBS1-2026-0002', $this->pay($this->fee())->receipt_no);

        $log = AuditLog::where('action', 'club.profile_updated')->latest('id')->first();
        $this->assertSame('RCP', $log->old_values['receipt_prefix']);
        $this->assertSame('KBS1', $log->new_values['receipt_prefix']);

        // The profile page previews the next number.
        $this->get(route('admin.clubs.profile.edit', $this->club))->assertOk()->assertSee('KBS1-2026-0003');
    }

    public function test_the_prefix_must_be_2_to_6_letters_or_digits(): void
    {
        $this->actingAsClubAdmin($this->club);

        foreach (['R', 'TOOLONG', 'RC-P', 'RC P', 'RČP', '<b>', "RC\x00P"] as $bad) {
            $this->put(route('admin.clubs.profile.update', $this->club), $this->profile(['receipt_prefix' => $bad]))
                ->assertSessionHasErrors('receipt_prefix');
        }
        $this->assertSame('RCP', $this->club->fresh()->receipt_prefix);

        // Emptied: back to RCP. Left out of the request: unchanged.
        $this->club->forceFill(['receipt_prefix' => 'ABC'])->save();
        $this->put(route('admin.clubs.profile.update', $this->club), $this->profile(['receipt_prefix' => '']))->assertSessionHasNoErrors();
        $this->assertSame('RCP', $this->club->fresh()->receipt_prefix);
        $this->club->fresh()->forceFill(['receipt_prefix' => 'ABC'])->save();
        $this->put(route('admin.clubs.profile.update', $this->club), $this->profile())->assertSessionHasNoErrors();
        $this->assertSame('ABC', $this->club->fresh()->receipt_prefix);
    }

    public function test_only_the_clubs_admins_and_the_super_admin_may_change_the_prefix(): void
    {
        $this->put(route('admin.clubs.profile.update', $this->club), $this->profile(['receipt_prefix' => 'HACK']))->assertRedirect(route('login'));

        $this->actingAsMember($this->club);
        $this->put(route('admin.clubs.profile.update', $this->club), $this->profile(['receipt_prefix' => 'HACK']))->assertForbidden();

        $this->actingAsClubAdmin(Club::factory()->create());
        $this->put(route('admin.clubs.profile.update', $this->club), $this->profile(['receipt_prefix' => 'HACK']))->assertForbidden();
        $this->assertSame('RCP', $this->club->fresh()->receipt_prefix);

        $this->actingAsSuperAdmin();
        $this->put(route('admin.clubs.profile.update', $this->club), $this->profile(['receipt_prefix' => 'SUPR']))->assertSessionHasNoErrors();
        $this->assertSame('SUPR', $this->club->fresh()->receipt_prefix);
    }

    // ── Where it shows ──────────────────────────────────────────────────

    public function test_the_member_receipt_the_email_the_admin_page_and_the_ledger_show_it(): void
    {
        // With a reference too, so the ledger's reference cell shows both.
        $fee = $this->pay($this->fee(['reference' => 'PAY-REF-1']));

        $this->actingAs($this->member)->get(route('member.payments.invoice', $fee))
            ->assertInertia(fn (Assert $page) => $page->component('Member/Invoice')->where('receipt.receiptNo', 'RCP-2026-0001'));

        $html = (new PaymentConfirmation($fee->load(['user', 'club'])))->render();
        $this->assertStringContainsString('<td data-testid="receipt-no">RCP-2026-0001</td>', $html);

        $this->actingAsClubAdmin($this->club);
        $this->get(route('admin.payments.show', $fee))->assertOk()->assertSee('RCP-2026-0001');
        $this->get(route('admin.clubs.payments.index', $this->club).'?month='.$fee->period_start->format('Y-m'))->assertOk()
            ->assertSee('<div class="small fw-normal text-muted font-monospace" data-testid="receipt-no">RCP-2026-0001</div>', false);

        $csv = $this->get(route('admin.clubs.ledger.export', $this->club).'?from=2026-01-01&to=2026-12-31')->assertOk()->streamedContent();
        $this->assertStringContainsString('Receipt No.', $csv);
        $this->assertStringContainsString('RCP-2026-0001', $csv);

        $this->get(route('admin.clubs.ledger', $this->club).'?from=2026-01-01&to=2026-12-31')
            ->assertInertia(fn (Assert $page) => $page->where('transactions.0.receiptNo', 'RCP-2026-0001')
                ->where('transactions.0.reference', 'PAY-REF-1'));

        // The PDF: the receipt number and the reference share one cell (the HTML is captured as dompdf renders it).
        $html = null;
        View::composer('admin.ledger.pdf', function ($view) use (&$html) {
            static $inside = false;
            if (! $inside) {
                $inside = true;
                $html = view('admin.ledger.pdf', $view->getData())->render();
                $inside = false;
            }
        });
        $this->get(route('admin.clubs.ledger.export-pdf', $this->club).'?from=2026-01-01&to=2026-12-31')->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('RCP-2026-0001<br>', (string) $html);
        $this->assertStringContainsString('PAY-REF-1', (string) $html);
    }

    public function test_fees_paid_before_receipt_numbers_keep_the_old_form(): void
    {
        $fee = $this->pay($this->fee());
        DB::table('payments')->where('id', $fee->id)->update(['receipt_no' => null]);
        $legacy = 'RCP-'.str_pad((string) $fee->id, 6, '0', STR_PAD_LEFT);

        $this->actingAs($this->member)->get(route('member.payments.invoice', $fee))
            ->assertInertia(fn (Assert $page) => $page->where('receipt.receiptNo', $legacy));
        $this->assertStringContainsString($legacy, (new PaymentConfirmation($fee->fresh()->load(['user', 'club'])))->render());

        // An older group: RCP-G{bill id}.
        $bill = PaymentBill::create(['club_id' => $this->club->id, 'user_id' => $this->member->id, 'method' => 'gateway', 'gateway' => 'toyyibpay', 'reference' => 'G-OLD', 'amount_sen' => 1000]);
        $this->assertSame('RCP-G'.str_pad((string) $bill->id, 6, '0', STR_PAD_LEFT), MemberPaymentPresenter::groupReceiptNo($bill));
    }

    public function test_the_allocator_formats_and_previews(): void
    {
        $this->assertSame('RCP-2026-0001', ReceiptNumbers::preview($this->club));
        $this->assertSame('AB-2025-0042', ReceiptNumbers::format('AB', 2025, 42));
        $this->club->receipt_prefix = 'bad prefix';
        $this->assertSame('RCP', ReceiptNumbers::prefix($this->club), 'a stored value that is not valid falls back to RCP');
    }
}
