<?php

namespace App\Console\Commands;

use App\Mail\OverdueReminder;
use App\Models\Payment;
use App\Services\AuditService;
use App\Support\Payments\DisplayStatus;
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

        // Chunk to bound memory if the table grows large. Candidates are past
        // their due date; each club's grace period and any FPX bill in
        // progress are checked per fee (DisplayStatus): only a fee shown as
        // overdue is flipped and reminded. A fee whose slip is being checked
        // is not 'pending', so it never gets here.
        // Only fees of an active club whose member is still an active member of it: a deactivated or removed
        // membership (or club) is left 'pending' and nobody is emailed. Reactivated, the member's fees are
        // reminded on the next run ('overdue' doubles as "already reminded").
        Payment::with(['user', 'club'])
            ->where('status', 'pending')
            ->where('due_date', '<', now()->toDateString())
            ->whereHas('club', fn ($q) => $q->where('is_active', true))
            ->whereHas('user')
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('club_user')
                ->whereColumn('club_user.club_id', 'payments.club_id')
                ->whereColumn('club_user.user_id', 'payments.user_id')
                ->where('club_user.is_active', true))
            ->chunkById(200, function ($chunk) use (&$sent, &$marked) {
                DisplayStatus::prime($chunk);
                foreach ($chunk as $payment) {
                    if (DisplayStatus::of($payment) !== DisplayStatus::OVERDUE) {
                        continue; // still within the grace period, or an FPX payment is in progress
                    }
                    // Re-check the status in the update itself: a webhook or an
                    // admin may have settled the fee since the chunk was read,
                    // and a paid fee must never be turned back into overdue (S1).
                    $updated = Payment::whereKey($payment->id)
                        ->where('status', 'pending')
                        ->update(['status' => 'overdue']);
                    if ($updated === 0) {
                        continue;
                    }
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
