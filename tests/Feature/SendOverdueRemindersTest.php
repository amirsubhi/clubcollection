<?php

namespace Tests\Feature;

use App\Mail\OverdueReminder;
use App\Models\Club;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SendOverdueRemindersTest extends TestCase
{
    private function makePendingOverdue(): Payment
    {
        $club    = Club::factory()->create();
        $member  = User::factory()->create();
        $club->members()->attach($member, [
            'role' => 'member', 'job_level' => 'executive',
            'joined_date' => now()->toDateString(), 'is_active' => true,
        ]);
        // Pending and past the default 7-day grace period: eligible to be flipped to overdue.
        return Payment::factory()
            ->forClub($club)
            ->forMember($member)
            ->pending()
            ->create([
                'due_date' => now()->subDays(8)->toDateString(),
                'paid_date' => null,
            ]);
    }

    public function test_command_marks_overdue_writes_audit_log_and_emails(): void
    {
        Mail::fake();
        $payment = $this->makePendingOverdue();

        Artisan::call('payments:send-overdue-reminders');

        $this->assertSame('overdue', $payment->fresh()->status);
        Mail::assertSent(OverdueReminder::class, fn($mail) => $mail->payment->id === $payment->id);

        $this->assertDatabaseHas('audit_logs', [
            'action'         => 'payment.marked_overdue',
            'auditable_type' => Payment::class,
            'auditable_id'   => $payment->id,
            'club_id'        => $payment->club_id,
        ]);
    }

    public function test_command_skips_payments_already_due_in_future(): void
    {
        Mail::fake();
        $club    = Club::factory()->create();
        $member  = User::factory()->create();
        $club->members()->attach($member, [
            'role' => 'member', 'job_level' => 'executive',
            'joined_date' => now()->toDateString(), 'is_active' => true,
        ]);
        $payment = Payment::factory()->forClub($club)->forMember($member)->pending()->create([
            'due_date' => now()->addDays(7)->toDateString(),
        ]);

        Artisan::call('payments:send-overdue-reminders');

        $this->assertSame('pending', $payment->fresh()->status);
        Mail::assertNothingSent();
        $this->assertDatabaseMissing('audit_logs', [
            'action'       => 'payment.marked_overdue',
            'auditable_id' => $payment->id,
        ]);
    }

    public function test_command_skips_fees_whose_transfer_slip_is_being_checked(): void
    {
        Mail::fake();
        $payment = $this->makePendingOverdue();
        $payment->forceFill(['status' => 'pending_verification'])->save();

        Artisan::call('payments:send-overdue-reminders');

        $this->assertSame('pending_verification', $payment->fresh()->status);
        Mail::assertNothingSent();
        $this->assertDatabaseMissing('audit_logs', ['action' => 'payment.marked_overdue', 'auditable_id' => $payment->id]);
    }

    /** Nothing flipped, nothing emailed, no audit row. */
    private function assertLeftAlone(Payment $payment): void
    {
        $this->assertSame('pending', $payment->fresh()->status);
        Mail::assertNothingSent();
        $this->assertDatabaseMissing('audit_logs', ['action' => 'payment.marked_overdue', 'auditable_id' => $payment->id]);
    }

    public function test_an_inactive_membership_is_not_reminded_until_reactivated(): void
    {
        Mail::fake();
        $payment = $this->makePendingOverdue();
        $payment->club->members()->updateExistingPivot($payment->user_id, ['is_active' => false]);

        Artisan::call('payments:send-overdue-reminders');
        $this->assertLeftAlone($payment);

        // Reactivated: the next run reminds them once.
        $payment->club->members()->updateExistingPivot($payment->user_id, ['is_active' => true]);
        Artisan::call('payments:send-overdue-reminders');
        Artisan::call('payments:send-overdue-reminders');

        $this->assertSame('overdue', $payment->fresh()->status);
        Mail::assertSent(OverdueReminder::class, 1);
    }

    public function test_a_member_removed_from_the_club_is_not_reminded(): void
    {
        Mail::fake();
        $payment = $this->makePendingOverdue();
        $payment->club->members()->detach($payment->user_id);

        Artisan::call('payments:send-overdue-reminders');
        $this->assertLeftAlone($payment);
    }

    public function test_an_inactive_club_is_not_reminded(): void
    {
        Mail::fake();
        $payment = $this->makePendingOverdue();
        $payment->club->update(['is_active' => false]);

        Artisan::call('payments:send-overdue-reminders');
        $this->assertLeftAlone($payment);
    }

    public function test_a_membership_in_another_club_does_not_count(): void
    {
        Mail::fake();
        $payment = $this->makePendingOverdue();
        $payment->club->members()->updateExistingPivot($payment->user_id, ['is_active' => false]);
        Club::factory()->create()->members()->attach($payment->user_id, [
            'role' => 'member', 'job_level' => 'executive', 'joined_date' => now()->toDateString(), 'is_active' => true,
        ]);

        Artisan::call('payments:send-overdue-reminders');
        $this->assertLeftAlone($payment);
    }

    public function test_a_deleted_account_leaves_nothing_to_remind_and_the_run_still_reaches_others(): void
    {
        Mail::fake();
        $gone = $this->makePendingOverdue();
        $kept = $this->makePendingOverdue();
        // Deleting the account takes its memberships and fees with it (cascade).
        User::whereKey($gone->user_id)->delete();

        Artisan::call('payments:send-overdue-reminders');

        $this->assertNull(Payment::find($gone->id));
        $this->assertSame('overdue', $kept->fresh()->status);
        Mail::assertSent(OverdueReminder::class, 1);
    }
}
