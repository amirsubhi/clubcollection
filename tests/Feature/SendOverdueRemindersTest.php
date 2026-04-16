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
        // Pending and past due — eligible to be flipped to overdue.
        return Payment::factory()
            ->forClub($club)
            ->forMember($member)
            ->pending()
            ->create([
                'due_date' => now()->subDays(5)->toDateString(),
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
}
