<?php

namespace App\Services;

use App\Mail\PaymentConfirmation;
use App\Mail\TransferRejected;
use App\Models\Club;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Manual bank / DuitNow transfer with a slip (P2).
 *
 *   pending|overdue ──submit (member)──▶ pending_verification ──approve (admin)──▶ paid
 *          ▲                                      │
 *          └────────────reject (admin, reason)────┘
 *
 * Rules:
 * - Only the fee's owner submits a slip, and only while the club accepts transfers.
 * - Slips are stored as private, encrypted `proof_of_payment` attachments and
 *   frozen on submit; a rejected slip stays as history.
 * - Only an active admin of the fee's club approves or rejects, never for their
 *   own fee (a super admin may, so a single-admin club is not stuck). A member
 *   can never mark their own fee paid.
 * - Every transition locks the row, checks the status first and is audited.
 */
class TransferVerificationService
{
    public const MAX_SLIPS = 3;

    /** All slips ever sent for one fee (rejected ones are kept), so resubmits can't grow without bound. */
    public const MAX_SLIPS_TOTAL = 30;

    public function __construct(private AttachmentService $attachments) {}

    /**
     * @param  array<int, UploadedFile>  $files
     */
    public function submit(Payment $payment, User $member, array $files, string $transferDate, ?string $transferRef): Payment
    {
        if ((int) $payment->user_id !== (int) $member->id) {
            abort(403);
        }
        if (count($files) < 1 || count($files) > self::MAX_SLIPS) {
            throw ValidationException::withMessages(['files' => 'member.slip_count']);
        }
        $date = Carbon::parse($transferDate)->startOfDay();
        if ($date->gt(today()) || $date->lt(today()->subYear())) {
            throw ValidationException::withMessages(['transfer_date' => 'member.transfer_date_invalid']);
        }
        $transferRef = ($ref = trim((string) $transferRef)) === '' ? null : $ref;

        return $this->attachments->tracked(fn () => DB::transaction(function () use ($payment, $files, $date, $transferRef) {
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $club = Club::findOrFail($payment->club_id);

            if (! $club->transferReady()) {
                throw ValidationException::withMessages(['method' => 'member.transfer_unavailable']);
            }
            if (! in_array($payment->status, ['pending', 'overdue'], true)) {
                throw ValidationException::withMessages(['status' => $payment->status === 'paid' ? 'member.already_paid' : 'member.verifying']);
            }

            if ($payment->attachments()->where('kind', 'proof_of_payment')->count() + count($files) > self::MAX_SLIPS_TOTAL) {
                throw ValidationException::withMessages(['files' => 'member.slip_limit']);
            }

            $old = $this->snapshot($payment);
            // Taken before the files are stored, so Payment::currentSlips() (created_at ≥ this) finds them.
            $submittedAt = now();
            foreach ($files as $file) {
                $this->attachments->store($payment, $payment->club_id, $file, 'proof_of_payment');
            }
            $payment->attachments()->whereNull('frozen_at')->update(['frozen_at' => now()]);

            $payment->forceFill([
                'status' => 'pending_verification',
                'transfer_date' => $date->toDateString(),
                'transfer_ref' => $transferRef,
                'transfer_submitted_at' => $submittedAt,
                'rejection_reason' => null,
                'verified_by' => null,
                'verified_at' => null,
            ])->save();

            AuditService::log(
                'payment.transfer_submitted',
                "Payment #{$payment->id}: transfer slip submitted by the member (transfer date {$date->toDateString()}); awaiting verification.",
                $payment,
                $payment->club_id,
                $old,
                $this->snapshot($payment) + ['slips' => count($files)]
            );

            return $payment;
        }));
    }

    public function approve(Payment $payment, User $admin, string $paidDate, ?string $bankRef): Payment
    {
        $this->assertCanVerify($payment, $admin);
        $bankRef = ($ref = trim((string) $bankRef)) === '' ? null : $ref;

        $payment = DB::transaction(function () use ($payment, $admin, $paidDate, $bankRef) {
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $this->assertVerifying($payment);
            if ($payment->transfer_date && Carbon::parse($paidDate)->lt($payment->transfer_date)) {
                throw ValidationException::withMessages(['paid_date' => 'The date received can\'t be before the member\'s transfer date ('.$payment->transfer_date->format('j M Y').').']);
            }

            $old = $this->snapshot($payment);
            $payment->forceFill([
                'status' => 'paid',
                'paid_date' => Carbon::parse($paidDate)->toDateString(),
                'paid_via' => 'transfer',
                'gateway' => null,
                // `reference` is left alone: the webhook falls back to it to find a gateway bill.
                'transfer_bank_ref' => $bankRef,
                'verified_by' => $admin->id,
                'verified_at' => now(),
                'fee_absorbed_sen' => 0,
                'rejection_reason' => null,
            ])->save();

            AuditService::log(
                'payment.transfer_approved',
                "Payment #{$payment->id}: transfer slip approved; marked paid (RM {$payment->amount}, paid {$payment->paid_date->toDateString()}).",
                $payment,
                $payment->club_id,
                $old,
                $this->snapshot($payment)
            );

            return $payment;
        });

        $payment->load(['user', 'club']);
        try {
            Mail::to($payment->user->email)->send(new PaymentConfirmation($payment));
        } catch (\Throwable $e) {
            Log::error('Payment confirmation email failed', ['payment_id' => $payment->id, 'error' => $e->getMessage()]);
        }

        return $payment;
    }

    public function reject(Payment $payment, User $admin, string $reason): Payment
    {
        $this->assertCanVerify($payment, $admin);
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw ValidationException::withMessages(['reason' => 'Give a reason (at least 5 characters) so the member knows what to fix.']);
        }

        $payment = DB::transaction(function () use ($payment, $admin, $reason) {
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $this->assertVerifying($payment);

            $old = $this->snapshot($payment);
            // Slips stay frozen as history; the member may upload new ones.
            $payment->forceFill([
                'status' => 'pending',
                'rejection_reason' => $reason,
                'verified_by' => $admin->id,
                'verified_at' => now(),
            ])->save();

            AuditService::log(
                'payment.transfer_rejected',
                "Payment #{$payment->id}: transfer slip rejected: {$reason}",
                $payment,
                $payment->club_id,
                $old,
                $this->snapshot($payment) + ['reason' => $reason]
            );

            return $payment;
        });

        $payment->load(['user', 'club']);
        try {
            Mail::to($payment->user->email)->send(new TransferRejected($payment));
        } catch (\Throwable $e) {
            Log::error('Transfer rejected email failed', ['payment_id' => $payment->id, 'error' => $e->getMessage()]);
        }

        return $payment;
    }

    /** Why $admin may not approve or reject $payment's slip, or null when they may. Status is not checked. */
    public function blocker(Payment $payment, User $admin): ?string
    {
        if (! $admin->isSuperAdmin() && (int) $payment->user_id === (int) $admin->id) {
            return 'This is your own fee, so another admin must check it.';
        }
        $club = $payment->club ?? Club::find($payment->club_id);
        if (! $club || ! app(FundUsageService::class)->isClubAdmin($club, $admin)) {
            return 'Only an admin of this club can check it.';
        }

        return null;
    }

    private function assertCanVerify(Payment $payment, User $admin): void
    {
        if ($reason = $this->blocker($payment, $admin)) {
            abort(403, $reason);
        }
    }

    private function assertVerifying(Payment $payment): void
    {
        if ($payment->status !== 'pending_verification') {
            throw ValidationException::withMessages(['status' => 'This slip was already handled by another admin.']);
        }
    }

    /** Fields for the audit log. */
    private function snapshot(Payment $payment): array
    {
        return [
            'status' => $payment->status,
            'amount' => (string) $payment->amount,
            'paid_date' => $payment->paid_date?->toDateString(),
            'paid_via' => $payment->paid_via,
            'reference' => $payment->reference,
            'transfer_date' => $payment->transfer_date?->toDateString(),
            'transfer_ref' => $payment->transfer_ref,
            'transfer_bank_ref' => $payment->transfer_bank_ref,
            'verified_by' => $payment->verified_by,
            'rejection_reason' => $payment->rejection_reason,
        ];
    }
}
