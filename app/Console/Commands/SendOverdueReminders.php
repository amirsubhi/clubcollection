<?php

namespace App\Console\Commands;

use App\Mail\OverdueReminder;
use App\Models\Payment;
use App\Services\AuditService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

#[Signature('payments:send-overdue-reminders')]
#[Description('Send overdue payment reminder emails to members')]
class SendOverdueReminders extends Command
{
    public function handle(): void
    {
        $sent = 0;
        $marked = 0;

        // Chunk to bound memory if the table grows large.
        Payment::with(['user', 'club'])
            ->where('status', 'pending')
            ->where('due_date', '<', now()->toDateString())
            ->chunkById(200, function ($chunk) use (&$sent, &$marked) {
                foreach ($chunk as $payment) {
                    $payment->update(['status' => 'overdue']);
                    $marked++;

                    // Audit the state change so the schedule's writes are
                    // attributable in the audit log (system actor).
                    AuditService::log(
                        'payment.marked_overdue',
                        "Payment #{$payment->id} marked overdue by scheduled task.",
                        $payment,
                        $payment->club_id,
                        ['status' => 'pending'],
                        ['status' => 'overdue']
                    );

                    try {
                        Mail::to($payment->user->email)
                            ->send(new OverdueReminder($payment));
                        $sent++;
                    } catch (\Exception $e) {
                        $this->warn("Failed to send reminder for payment #{$payment->id}: {$e->getMessage()}");
                    }
                }
            });

        if ($marked === 0) {
            $this->info('No overdue payments found.');
            return;
        }
        $this->info("Processed {$marked} overdue payment(s). Emails sent: {$sent}.");
    }
}
