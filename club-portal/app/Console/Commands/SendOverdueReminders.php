<?php

namespace App\Console\Commands;

use App\Mail\OverdueReminder;
use App\Models\Payment;
use Carbon\Carbon;
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
        // Find payments that are pending and due date has passed
        $overdue = Payment::with(['user', 'club'])
            ->where('status', 'pending')
            ->where('due_date', '<', now()->toDateString())
            ->get();

        if ($overdue->isEmpty()) {
            $this->info('No overdue payments found.');
            return;
        }

        // Mark them as overdue and send reminder emails
        $sent = 0;
        foreach ($overdue as $payment) {
            // Update status to overdue
            $payment->update(['status' => 'overdue']);

            // Send reminder email
            try {
                Mail::to($payment->user->email)
                    ->send(new OverdueReminder($payment));
                $sent++;
            } catch (\Exception $e) {
                $this->warn("Failed to send reminder for payment #{$payment->id}: {$e->getMessage()}");
            }
        }

        $this->info("Processed {$overdue->count()} overdue payment(s). Emails sent: {$sent}.");
    }
}
