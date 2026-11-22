<?php

namespace App\Services;

use App\Mail\PaymentConfirmation;
use App\Mail\TransferRejected;
use App\Models\Club;
use App\Models\Payment;
use App\Models\PaymentBill;
use App\Models\User;
use App\Support\Payments\OpenFeeSet;
use App\Support\Payments\OwnFeeRule;
use App\Support\Payments\ReceiptNumbers;
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
 * - "Bayar semua" (R1b): one slip and one reference (YURAN-G{oldest fee id})
 *   for every open fee of the member in the club. The slip is kept on the
 *   group (a payment_bills row, method 'transfer'); all its fees move to
 *   pending_verification together, and approve / reject always apply to the
 *   whole set, never to part of it.
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

            if ($this->slipsEverSent([$payment->id]) + count($files) > self::MAX_SLIPS_TOTAL) {
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
                // A slip for this fee alone: no group.
                'transfer_group_id' => null,
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

    /**
     * One slip for a set of fees ("Bayar semua"). $paymentIds and $totalSen
     * are what the member confirmed: they must still be exactly the member's
     * open fees in the club, or nothing is stored (member.set_changed).
     *
     * @param  array<int, int>  $paymentIds
     * @param  array<int, UploadedFile>  $files
     */
    public function submitGroup(Club $club, User $member, array $paymentIds, int $totalSen, array $files, string $transferDate, ?string $transferRef): PaymentBill
    {
        if (count($files) < 1 || count($files) > self::MAX_SLIPS) {
            throw ValidationException::withMessages(['files' => 'member.slip_count']);
        }
        $date = Carbon::parse($transferDate)->startOfDay();
        if ($date->gt(today()) || $date->lt(today()->subYear())) {
            throw ValidationException::withMessages(['transfer_date' => 'member.transfer_date_invalid']);
        }
        $transferRef = ($ref = trim((string) $transferRef)) === '' ? null : $ref;

        return $this->attachments->tracked(fn () => DB::transaction(function () use ($club, $member, $paymentIds, $totalSen, $files, $date, $transferRef) {
            $club = Club::whereKey($club->id)->firstOrFail();
            if (! $club->transferReady()) {
                throw ValidationException::withMessages(['method' => 'member.transfer_unavailable']);
            }

            $fees = OpenFeeSet::query($club->id, $member->id)->lockForUpdate()->get();
            if (! OpenFeeSet::matches($fees, $paymentIds, $totalSen)) {
                throw ValidationException::withMessages(['status' => 'member.set_changed']);
            }

            if ($this->slipsEverSent($fees->pluck('id')->all()) + count($files) > self::MAX_SLIPS_TOTAL) {
                throw ValidationException::withMessages(['files' => 'member.slip_limit']);
            }

            $submittedAt = now();
            $group = PaymentBill::create([
                'club_id' => $club->id,
                'user_id' => $member->id,
                'method' => 'transfer',
                'reference' => PaymentBill::groupTransferReference($fees->first()->id),
                'amount_sen' => $totalSen,
            ]);
            $group->payments()->attach($fees->mapWithKeys(fn (Payment $p) => [$p->id => ['amount_sen' => $p->amountSen()]])->all());

            foreach ($files as $file) {
                $this->attachments->store($group, $club->id, $file, 'proof_of_payment');
            }
            $group->attachments()->whereNull('frozen_at')->update(['frozen_at' => now()]);

            foreach ($fees as $payment) {
                $old = $this->snapshot($payment);
                $payment->forceFill([
                    'status' => 'pending_verification',
                    'transfer_date' => $date->toDateString(),
                    'transfer_ref' => $transferRef,
                    'transfer_submitted_at' => $submittedAt,
                    'transfer_group_id' => $group->id,
                    'rejection_reason' => null,
                    'verified_by' => null,
                    'verified_at' => null,
                ])->save();

                AuditService::log(
                    'payment.transfer_submitted',
                    "Payment #{$payment->id}: transfer slip submitted by the member for {$fees->count()} fees at once ({$group->reference}, RM ".number_format($totalSen / 100, 2).", transfer date {$date->toDateString()}); awaiting verification.",
                    $payment,
                    $payment->club_id,
                    $old,
                    $this->snapshot($payment) + ['slips' => count($files), 'group' => $group->reference, 'payment_ids' => $fees->pluck('id')->all()]
                );
            }

            return $group;
        }));
    }

    public function approve(Payment $payment, User $admin, string $paidDate, ?string $bankRef): Payment
    {
        if ($group = $payment->openTransferGroup()) {
            return $this->approveGroup($group, $admin, $paidDate, $bankRef);
        }
        $this->assertCanVerify($payment, $admin);
        $bankRef = ($ref = trim((string) $bankRef)) === '' ? null : $ref;

        $payment = DB::transaction(function () use ($payment, $admin, $paidDate, $bankRef) {
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $this->assertVerifying($payment);
            if ($payment->transfer_date && Carbon::parse($paidDate)->lt($payment->transfer_date)) {
                throw ValidationException::withMessages(['paid_date' => 'The date received can\'t be before the member\'s transfer date ('.$payment->transfer_date->format('j M Y').').']);
            }

            $slips = $this->slipRefs($payment); // before any change
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
                "Payment #{$payment->id}: transfer slip approved; marked paid (RM {$payment->amount}, paid {$payment->paid_date->toDateString()}). Receipt ".($payment->receipt_no ?? 'n/a').'.',
                $payment,
                $payment->club_id,
                $old,
                $this->snapshot($payment) + ['slips' => $slips]
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
        if ($group = $payment->openTransferGroup()) {
            return $this->rejectGroup($group, $admin, $reason);
        }
        $this->assertCanVerify($payment, $admin);
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw ValidationException::withMessages(['reason' => 'Give a reason (at least 5 characters) so the member knows what to fix.']);
        }

        $payment = DB::transaction(function () use ($payment, $admin, $reason) {
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $this->assertVerifying($payment);

            $slips = $this->slipRefs($payment); // before any change
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
                $this->snapshot($payment) + ['reason' => $reason, 'slips' => $slips]
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

    /** Approve every fee of a group slip at once; returns the oldest fee. */
    private function approveGroup(PaymentBill $group, User $admin, string $paidDate, ?string $bankRef): Payment
    {
        $bankRef = ($ref = trim((string) $bankRef)) === '' ? null : $ref;

        $fees = DB::transaction(function () use ($group, $admin, $paidDate, $bankRef) {
            [$group, $fees] = $this->lockGroup($group, $admin);
            // Before any change: the slips hang off the group bill through the fees' transfer_group_id.
            $slips = $fees->isEmpty() ? [] : $this->slipRefs($fees->first());
            $date = Carbon::parse($paidDate);
            foreach ($fees as $payment) {
                if ($payment->transfer_date && $date->lt($payment->transfer_date)) {
                    throw ValidationException::withMessages(['paid_date' => 'The date received can\'t be before the member\'s transfer date ('.$payment->transfer_date->format('j M Y').').']);
                }
            }

            $total = 'RM '.number_format($group->amount_sen / 100, 2);
            $skipped = count($group->itemAmounts()) - $fees->count();
            if ($skipped > 0) {
                // Some fees were paid online while the slip was checked: their share is flagged for a refund.
                $total .= ', '.$skipped.' fee(s) already paid online, approved RM '.number_format($fees->sum(fn (Payment $p) => $p->amountSen()) / 100, 2);
            }
            // One slip, one receipt: one number for the fees approved, taken after the group and fee locks
            // (CLAUDE.MD "Lock order"; later locks are only rows reached through these fees), kept on the group
            // and on every fee.
            $receipt = ReceiptNumbers::allocate(Club::findOrFail($group->club_id), $group->id, $date);
            foreach ($fees as $payment) {
                // The shared number, set before the save so the Payment hook doesn't issue one of its own.
                $payment->receipt_no = $receipt['receipt_no'];
                $payment->receiptAllocationId = $receipt['id'];
                $old = $this->snapshot($payment);
                $payment->forceFill([
                    'status' => 'paid',
                    'paid_date' => $date->toDateString(),
                    'paid_via' => 'transfer',
                    'gateway' => null,
                    'transfer_bank_ref' => $bankRef,
                    'verified_by' => $admin->id,
                    'verified_at' => now(),
                    'fee_absorbed_sen' => 0,
                    'rejection_reason' => null,
                ])->save();

                AuditService::log(
                    'payment.transfer_approved',
                    "Payment #{$payment->id}: transfer slip {$group->reference} ({$fees->count()} fees, {$total}) approved; marked paid (RM {$payment->amount}, paid {$payment->paid_date->toDateString()}). Receipt {$receipt['receipt_no']} (shared by the set).",
                    $payment,
                    $payment->club_id,
                    $old,
                    $this->snapshot($payment) + ['group' => $group->reference, 'slips' => $slips]
                );
            }
            $group->forceFill(['paid_at' => now(), 'receipt_no' => $receipt['receipt_no']])->save();

            return $fees;
        });

        $fees->load(['user', 'club']);
        $lead = $fees->first();
        try {
            Mail::to($lead->user->email)->send(new PaymentConfirmation($lead, $fees, $group->fresh() ?? $group));
        } catch (\Throwable $e) {
            Log::error('Payment confirmation email failed', ['payment_id' => $lead->id, 'error' => $e->getMessage()]);
        }

        return $lead;
    }

    /** Reject a group slip: every fee goes back to pending with the reason. Returns the oldest fee. */
    private function rejectGroup(PaymentBill $group, User $admin, string $reason): Payment
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw ValidationException::withMessages(['reason' => 'Give a reason (at least 5 characters) so the member knows what to fix.']);
        }

        $fees = DB::transaction(function () use ($group, $admin, $reason) {
            // A set where a fee changed meanwhile (e.g. paid online) can still be
            // rejected: the fees still being checked go back to pending.
            [$group, $fees] = $this->lockGroup($group, $admin, requireIntact: false);
            // Before any change: rejecting clears transfer_group_id, which is how a fee finds the group's slips.
            $slips = $fees->isEmpty() ? [] : $this->slipRefs($fees->first());
            foreach ($fees as $payment) {
                $old = $this->snapshot($payment);
                $payment->forceFill([
                    'status' => 'pending',
                    'rejection_reason' => $reason,
                    'verified_by' => $admin->id,
                    'verified_at' => now(),
                    // The set is closed; a later slip starts afresh.
                    'transfer_group_id' => null,
                ])->save();

                AuditService::log(
                    'payment.transfer_rejected',
                    "Payment #{$payment->id}: transfer slip {$group->reference} ({$fees->count()} fees) rejected: {$reason}",
                    $payment,
                    $payment->club_id,
                    $old,
                    $this->snapshot($payment) + ['reason' => $reason, 'group' => $group->reference, 'slips' => $slips]
                );
            }
            $group->forceFill(['superseded_at' => now()])->save();

            return $fees;
        });

        $fees->load(['user', 'club']);
        $lead = $fees->first();
        if ($lead) {
            try {
                Mail::to($lead->user->email)->send(new TransferRejected($lead, $fees, $group));
            } catch (\Throwable $e) {
                Log::error('Transfer rejected email failed', ['payment_id' => $lead->id, 'error' => $e->getMessage()]);
            }
        }

        return $lead ?? Payment::findOrFail(array_key_first($group->itemAmounts()));
    }

    /**
     * Lock a group and its fees and check $admin may decide it. With
     * $requireIntact, every fee must still be waiting at its billed amount
     * (approve is all-or-nothing); otherwise only the waiting fees are returned.
     *
     * @return array{0: PaymentBill, 1: \Illuminate\Support\Collection<int, Payment>}
     */
    private function lockGroup(PaymentBill $group, User $admin, bool $requireIntact = true): array
    {
        $group = PaymentBill::whereKey($group->id)->lockForUpdate()->firstOrFail();
        if (! $group->isOpen()) {
            throw ValidationException::withMessages(['status' => 'This slip was already handled by another admin.']);
        }
        $amounts = $group->itemAmounts();
        $fees = Payment::whereIn('id', array_keys($amounts))->orderBy('due_date')->orderBy('period_start')->orderBy('id')->lockForUpdate()->get();
        foreach ($fees as $payment) {
            $this->assertCanVerify($payment, $admin);
        }
        // Only fees whose current slip is this group's: a fee that left the set and
        // was later sent on its own slip is never decided here.
        $inGroup = fn (Payment $p) => (int) $p->transfer_group_id === (int) $group->id;
        $waiting = $fees->filter(fn (Payment $p) => $p->status === 'pending_verification' && $inGroup($p))->values();
        if ($requireIntact) {
            // While a slip is checked the admin pages freeze the fee, so the only
            // way a fee leaves the set is a gateway payment that arrived meanwhile
            // (the webhook settles it and flags the member's refund of its share).
            // Approve then covers the fees still waiting; anything else blocks it.
            $paidOnline = fn (Payment $p) => $p->status === 'paid' && ! in_array($p->paid_via, [null, 'transfer', 'cash', 'manual'], true);
            $intact = $fees->count() === count($amounts) && $waiting->isNotEmpty()
                && $fees->every(fn (Payment $p) => $paidOnline($p)
                    || ($p->status === 'pending_verification' && $inGroup($p) && $p->amountSen() === ($amounts[$p->id] ?? -1)));
            if (! $intact) {
                throw ValidationException::withMessages(['status' => 'A fee in this set changed after the slip was sent. Reject the set so the member can send a new slip.']);
            }
        } elseif ($waiting->isEmpty()) {
            throw ValidationException::withMessages(['status' => 'This slip was already handled by another admin.']);
        }

        return [$group, $waiting];
    }

    /**
     * Slips ever sent for these fees, on their own or in any group slip that
     * covers one of them (rejected ones included), for MAX_SLIPS_TOTAL.
     *
     * @param  array<int, int>  $paymentIds
     */
    private function slipsEverSent(array $paymentIds): int
    {
        $groupIds = \Illuminate\Support\Facades\DB::table('payment_bill_items')
            ->join('payment_bills', 'payment_bills.id', '=', 'payment_bill_items.payment_bill_id')
            ->where('payment_bills.method', 'transfer')
            ->whereIn('payment_bill_items.payment_id', $paymentIds)
            ->distinct()->pluck('payment_bills.id');

        return \App\Models\Attachment::query()->where('kind', 'proof_of_payment')
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('attachable_type', Payment::class)->whereIn('attachable_id', $paymentIds))
                ->orWhere(fn ($q) => $q->where('attachable_type', PaymentBill::class)->whereIn('attachable_id', $groupIds)))
            ->count();
    }

    /**
     * The other fees of a group slip, for the own-fee rule: an admin may not
     * decide a set that holds any fee of their own.
     *
     * @return \Illuminate\Support\Collection<int, Payment>
     */
    private function groupFees(Payment $payment)
    {
        $group = $payment->openTransferGroup();

        return $group ? Payment::whereIn('id', array_keys($group->itemAmounts()))->get() : collect([$payment]);
    }

    /** Why $admin may not approve or reject $payment's slip, or null when they may. Status is not checked. */
    public function blocker(Payment $payment, User $admin): ?string
    {
        foreach ($this->groupFees($payment) as $fee) {
            if ($own = OwnFeeRule::blocker($fee, $admin)) {
                return $own;
            }
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

    /**
     * The slip files the decision was made on (id and SHA-256), so the audit row ties the
     * approval or rejection to the exact files even though the queue previews them unaudited.
     *
     * @return list<array{id: int, sha256: ?string}>
     */
    private function slipRefs(Payment $payment): array
    {
        return $payment->currentSlips()->get(['id', 'sha256'])
            ->map(fn ($a) => ['id' => (int) $a->id, 'sha256' => $a->sha256])->values()->all();
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
