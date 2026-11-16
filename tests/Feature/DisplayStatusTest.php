<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\Payment;
use App\Models\PaymentBill;
use App\Models\User;
use App\Support\Payments\DisplayStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * R2a: one status word per fee, from one place. paid | verifying |
 * processing (an open FPX bill, single or "Bayar semua") | due (within the
 * club's grace period) | overdue.
 */
class DisplayStatusTest extends TestCase
{
    private Club $club;

    private User $member;

    private int $month = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::create(2026, 11, 16, 10));
        $this->club = Club::factory()->create();
        $this->member = User::factory()->create();
        $this->club->members()->attach($this->member, ['role' => 'member', 'job_level' => 'executive', 'joined_date' => '2026-01-01', 'is_active' => true]);
    }

    /** A fee in its own month, so periods never overlap. */
    private function fee(array $attrs = []): Payment
    {
        $start = Carbon::create(2026, 1, 1)->addMonths($this->month++);

        return Payment::factory()->forClub($this->club)->forMember($this->member)->create($attrs + [
            'status' => 'pending', 'paid_date' => null, 'amount' => 10.00,
            'period_start' => $start->toDateString(), 'period_end' => $start->copy()->endOfMonth()->toDateString(),
            'due_date' => '2026-11-30',
        ]);
    }

    private function singleBill(Payment $fee, array $attrs = []): PaymentBill
    {
        $bill = $fee->bills()->create($attrs + ['gateway' => 'toyyibpay', 'bill_code' => 'B'.uniqid(), 'reference' => 'PAY-'.$fee->id, 'amount_sen' => $fee->amountSen()]);
        $fee->forceFill(['bill_code' => $bill->bill_code, 'gateway' => 'toyyibpay'])->saveQuietly();

        return $bill;
    }

    private function groupBill(array $fees, array $attrs = []): PaymentBill
    {
        $bill = PaymentBill::create($attrs + [
            'club_id' => $this->club->id, 'user_id' => $this->member->id, 'method' => 'gateway', 'gateway' => 'toyyibpay',
            'bill_code' => 'G'.uniqid(), 'reference' => 'PAYALL-'.uniqid(),
            'amount_sen' => array_sum(array_map(fn (Payment $p) => $p->amountSen(), $fees)),
        ]);
        foreach ($fees as $p) {
            DB::table('payment_bill_items')->insert(['payment_bill_id' => $bill->id, 'payment_id' => $p->id, 'amount_sen' => $p->amountSen()]);
        }

        return $bill;
    }

    private function shown(Payment $p): string
    {
        return DisplayStatus::of($p->fresh());
    }

    public function test_paid_and_verifying_come_from_the_stored_status(): void
    {
        $this->assertSame('paid', $this->shown($this->fee(['status' => 'paid', 'paid_date' => '2026-11-01'])));
        $this->assertSame('verifying', $this->shown($this->fee(['status' => 'pending_verification', 'due_date' => '2026-01-01'])));
    }

    public function test_due_until_the_end_of_the_grace_period_then_overdue(): void
    {
        // Default grace: 7 days. Today is 16 Nov.
        $this->assertSame(7, $this->club->fresh()->overdueGraceDays());
        $this->assertSame('due', $this->shown($this->fee(['due_date' => '2026-11-30'])));
        $this->assertSame('due', $this->shown($this->fee(['due_date' => '2026-11-16'])));
        $this->assertSame('due', $this->shown($this->fee(['due_date' => '2026-11-09'])), 'last day of grace');
        $this->assertSame('overdue', $this->shown($this->fee(['due_date' => '2026-11-08'])), 'first day past grace');
        // The nightly job's stored 'overdue' does not override the dates.
        $this->assertSame('due', $this->shown($this->fee(['status' => 'overdue', 'due_date' => '2026-11-12'])));
        $this->assertSame('overdue', $this->shown($this->fee(['status' => 'overdue', 'due_date' => '2026-10-01'])));
    }

    public function test_the_grace_period_is_per_club(): void
    {
        $fee = $this->fee(['due_date' => '2026-11-15']);
        $this->club->update(['overdue_grace_days' => 0]);
        $this->assertSame('overdue', $this->shown($fee), 'grace 0: overdue the day after the due date');
        $this->assertSame('due', $this->shown($this->fee(['due_date' => '2026-11-16'])), 'grace 0: due on the due date');

        $this->club->update(['overdue_grace_days' => 30]);
        $this->assertSame('due', $this->shown($this->fee(['due_date' => '2026-10-17'])));
        $this->assertSame('overdue', $this->shown($this->fee(['due_date' => '2026-10-16'])));
    }

    public function test_an_open_single_bill_makes_the_fee_processing(): void
    {
        $fee = $this->fee(['due_date' => '2026-10-01']); // overdue by the dates
        $bill = $this->singleBill($fee);
        $this->assertSame('processing', $this->shown($fee));
        $this->assertSame(route('member.payments.thankyou', $fee), DisplayStatus::memberBillUrl($fee->fresh()));

        $bill->update(['superseded_at' => now()]);
        $this->assertSame('overdue', $this->shown($fee), 'a superseded bill is not in progress');

        $paid = $this->fee();
        $this->singleBill($paid, ['paid_at' => now()]);
        $this->assertSame('due', $this->shown($paid), 'a settled bill is not in progress');
    }

    public function test_an_open_bill_counts_as_in_progress_for_three_days_only(): void
    {
        $fee = $this->fee();
        $this->singleBill($fee);
        $this->travel(DisplayStatus::PROCESSING_DAYS)->days();
        $this->travel(-1)->minutes();
        $this->assertSame('processing', $this->shown($fee));
        $this->travel(2)->minutes();
        $this->assertSame('due', $this->shown($fee), 'abandoned: back to due, so reminders go out again');
    }

    public function test_an_open_group_bill_makes_each_of_its_fees_processing(): void
    {
        $a = $this->fee(['due_date' => '2026-10-01']);
        $b = $this->fee(['due_date' => '2026-11-30']);
        $outside = $this->fee();
        $group = $this->groupBill([$a, $b]);

        $this->assertSame('processing', $this->shown($a));
        $this->assertSame('processing', $this->shown($b));
        $this->assertSame('due', $this->shown($outside));
        $this->assertSame(route('member.payments.group-result', $group), DisplayStatus::memberBillUrl($a->fresh()));

        $group->update(['superseded_at' => now()]);
        $this->assertSame('overdue', $this->shown($a));
        $this->assertSame('due', $this->shown($b));
    }

    public function test_a_transfer_group_or_a_bill_without_code_is_not_processing(): void
    {
        $a = $this->fee();
        $this->groupBill([$a], ['method' => 'transfer', 'gateway' => null, 'bill_code' => null]);
        $this->assertSame('due', $this->shown($a));
    }

    public function test_many_scope_and_counts_agree(): void
    {
        $paid = $this->fee(['status' => 'paid', 'paid_date' => '2026-11-01']);
        $verifying = $this->fee(['status' => 'pending_verification']);
        $due = $this->fee(['due_date' => '2026-11-10']);
        $overdue = $this->fee(['due_date' => '2026-11-01']);
        $single = $this->fee(['due_date' => '2026-11-01']);
        $this->singleBill($single);
        $grouped = $this->fee();
        $this->groupBill([$grouped]);

        $expected = [
            $paid->id => 'paid', $verifying->id => 'verifying', $due->id => 'due',
            $overdue->id => 'overdue', $single->id => 'processing', $grouped->id => 'processing',
        ];
        $this->assertSame($expected, DisplayStatus::many(Payment::orderBy('id')->get()));

        foreach (DisplayStatus::ALL as $status) {
            $ids = DisplayStatus::scope($this->club->payments(), $status, $this->club)->orderBy('id')->pluck('payments.id')->all();
            $this->assertSame(array_keys(array_filter($expected, fn ($s) => $s === $status)), $ids, "scope {$status}");
        }

        $this->assertSame(['paid' => 1, 'verifying' => 1, 'processing' => 2, 'due' => 1, 'overdue' => 1], DisplayStatus::counts($this->club->payments()->getQuery()));
    }

    public function test_labels(): void
    {
        $this->assertSame('Perlu dibayar', DisplayStatus::label('due', 'bm'));
        $this->assertSame('Lewat', DisplayStatus::label('overdue', 'bm'));
        $this->assertSame('Dalam proses', DisplayStatus::label('processing', 'bm'));
        $this->assertSame('Menunggu pengesahan', DisplayStatus::label('verifying', 'bm'));
        $this->assertSame('FPX in progress', DisplayStatus::adminLabel('processing'));
        $this->assertSame('Overdue', DisplayStatus::adminLabel('overdue'));
    }
}
