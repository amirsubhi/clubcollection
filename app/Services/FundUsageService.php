<?php

namespace App\Services;

use App\Models\Attachment;
use App\Models\Club;
use App\Models\Expense;
use App\Models\User;
use App\Models\WelfareCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Maker-checker workflow for Fund Usage records (expenses and welfare).
 *
 *   draft ──submit──▶ submitted ──approve──▶ approved ──request void + confirm void──▶ void
 *     ▲                   │
 *     └──return / edit────┘
 *
 * Rules:
 * - Only the recorder (maker) edits, submits or deletes a draft.
 * - Submit needs ≥1 document (welfare: a document or a "certificate sighted" record)
 *   and freezes the documents.
 * - The approver (checker) must not be the recorder or the welfare beneficiary and
 *   must tick a conflict-of-interest declaration. Never auto-approved.
 * - Any edit of a submitted record sends it back to draft.
 * - Return needs a reason. Void needs a reason and a second admin to confirm.
 *
 * Every transition is audited with old/new values. Welfare personal data is
 * never written to the audit log (it is encrypted on welfare_cases only).
 */
class FundUsageService
{
    public function __construct(private AttachmentService $attachments) {}

    /**
     * @param  array<string, mixed>  $data  validated expense fields
     * @param  array<int, array{file: UploadedFile, kind: string}>  $files
     * @param  array<string, mixed>|null  $welfare
     */
    public function create(Club $club, User $by, array $data, array $files = [], ?array $welfare = null): Expense
    {
        $this->assertFileCount(0, count($files));

        // Row, welfare details and files commit together; files written before
        // a failure are deleted again (AttachmentService::tracked).
        $expense = $this->attachments->tracked(fn () => DB::transaction(function () use ($club, $by, $data, $welfare, $files) {
            $expense = new Expense($this->expenseFields($data));
            $expense->club_id = $club->id;
            $expense->recorded_by = $by->id;
            $expense->status = 'draft';
            $expense->save();

            if ($expense->isWelfare()) {
                $this->saveWelfare($expense, $welfare ?? []);
            }

            AuditService::log(
                'fund_usage.created',
                "{$this->label($expense)} {$expense->reference()} recorded as draft (RM {$expense->amount}).",
                $expense,
                $club->id,
                [],
                $this->snapshot($expense)
            );

            foreach ($files as $f) {
                $this->attachments->store($expense, $club->id, $f['file'], $f['kind']);
            }

            return $expense;
        }));

        return $expense->fresh();
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array{file: UploadedFile, kind: string}>  $newFiles
     * @param  array<int, int>  $removeIds
     * @param  array<string, mixed>|null  $welfare
     */
    public function update(Expense $expense, User $by, array $data, array $newFiles = [], array $removeIds = [], ?array $welfare = null): Expense
    {
        $this->assertRecorder($expense, $by, 'edit');

        return $this->attachments->tracked(fn () => DB::transaction(function () use ($expense, $by, $data, $newFiles, $removeIds, $welfare) {
            $expense = Expense::whereKey($expense->id)->lockForUpdate()->firstOrFail();
            if (! in_array($expense->status, ['draft', 'submitted'], true)) {
                throw ValidationException::withMessages(['status' => 'Approved or void records cannot be edited.']);
            }

            $old = $this->snapshot($expense);
            $expense->fill($this->expenseFields($data));
            $welfareChanged = $expense->isWelfare() && $this->welfareDirty($expense, $welfare ?? []);
            $removeIds = $expense->attachments()->whereIn('id', $removeIds)->pluck('id')->all();
            // Check the final count before touching anything (existing − removed + new).
            $this->assertFileCount($expense->attachments()->count() - count($removeIds), count($newFiles));
            $changed = $expense->isDirty() || $welfareChanged || $newFiles !== [] || $removeIds !== [];

            if (! $changed) {
                return $expense;
            }

            $wasSubmitted = $expense->status === 'submitted';
            if ($wasSubmitted) {
                // Any change after submission means the checker must look again.
                $this->toDraft($expense);
            }
            $expense->save();

            if ($expense->isWelfare()) {
                $this->saveWelfare($expense, $welfare ?? []);
            } else {
                $expense->welfareCase()->delete();
            }

            foreach ($expense->attachments()->whereIn('id', $removeIds)->get() as $a) {
                $this->attachments->delete($a);
            }
            foreach ($newFiles as $f) {
                $this->attachments->store($expense, $expense->club_id, $f['file'], $f['kind']);
            }

            AuditService::log(
                'fund_usage.updated',
                "{$expense->reference()} edited".($wasSubmitted ? ' after submission; back to draft.' : '.'),
                $expense,
                $expense->club_id,
                $old,
                $this->snapshot($expense->fresh()) + [
                    'welfare_details_changed' => $welfareChanged,
                    'attachments_added' => count($newFiles),
                    'attachments_removed' => count($removeIds),
                ]
            );

            return $expense->fresh();
        }));
    }

    public function submit(Expense $expense, User $by): Expense
    {
        $this->assertRecorder($expense, $by, 'submit');

        return DB::transaction(function () use ($expense) {
            $expense = Expense::whereKey($expense->id)->lockForUpdate()->firstOrFail();
            $this->assertStatus($expense, 'draft', 'Only drafts can be submitted.');

            $hasDocs = $expense->attachments()->exists();
            $sighted = $expense->isWelfare() && $expense->welfareCase?->certificateSighted();
            if (! $hasDocs && ! $sighted) {
                throw ValidationException::withMessages(['files' => $expense->isWelfare()
                    ? 'Attach at least one document or record who sighted the certificate before submitting.'
                    : 'Attach at least one document (invoice, receipt or bank slip) before submitting.']);
            }

            $old = $this->snapshot($expense);
            $expense->status = 'submitted';
            $expense->submitted_at = now();
            $expense->return_reason = null;
            $expense->save();
            $expense->attachments()->whereNull('frozen_at')->update(['frozen_at' => now()]);

            AuditService::log(
                'fund_usage.submitted',
                "{$expense->reference()} submitted for approval; documents locked.",
                $expense,
                $expense->club_id,
                $old,
                $this->snapshot($expense) + ['attachments' => $expense->attachments()->count()]
            );

            return $expense;
        });
    }

    public function approve(Expense $expense, User $by, bool $declaredNoConflict): Expense
    {
        if (! $declaredNoConflict) {
            throw ValidationException::withMessages(['declaration' => 'Tick the conflict-of-interest declaration to approve.']);
        }

        return DB::transaction(function () use ($expense, $by) {
            $expense = Expense::whereKey($expense->id)->lockForUpdate()->firstOrFail();
            $this->assertStatus($expense, 'submitted', 'Only records awaiting approval can be approved.');
            if ($reason = $this->approvalBlocker($expense, $by)) {
                abort(403, $reason);
            }

            $old = $this->snapshot($expense);
            $expense->status = 'approved';
            $expense->approved_by = $by->id;
            $expense->approved_at = now();
            $expense->save();

            AuditService::log(
                'fund_usage.approved',
                "{$expense->reference()} approved (RM {$expense->amount}). Approver declared no conflict of interest.",
                $expense,
                $expense->club_id,
                $old,
                $this->snapshot($expense) + ['conflict_of_interest_declared' => true]
            );

            return $expense;
        });
    }

    public function returnToDraft(Expense $expense, User $by, string $reason): Expense
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw ValidationException::withMessages(['reason' => 'Give a reason (at least 5 characters) so the maker knows what to fix.']);
        }

        return DB::transaction(function () use ($expense, $by, $reason) {
            $expense = Expense::whereKey($expense->id)->lockForUpdate()->firstOrFail();
            $this->assertStatus($expense, 'submitted', 'Only records awaiting approval can be returned.');
            if ((int) $expense->recorded_by === (int) $by->id) {
                abort(403, 'You recorded this, so you cannot return it. Edit it instead.');
            }
            if ($this->isBeneficiary($expense, $by)) {
                abort(403, 'You are the beneficiary of this welfare case, so you cannot return it.');
            }
            if (! $this->isClubAdmin($expense->club, $by)) {
                abort(403, 'Only an admin of this club can return it.');
            }

            $old = $this->snapshot($expense);
            $this->toDraft($expense);
            $expense->return_reason = $reason;
            $expense->save();

            AuditService::log(
                'fund_usage.returned',
                "{$expense->reference()} returned to draft: {$reason}",
                $expense,
                $expense->club_id,
                $old,
                $this->snapshot($expense) + ['reason' => $reason]
            );

            return $expense;
        });
    }

    public function requestVoid(Expense $expense, User $by, string $reason): Expense
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw ValidationException::withMessages(['reason' => 'Give a reason for voiding (at least 5 characters).']);
        }

        return DB::transaction(function () use ($expense, $by, $reason) {
            $expense = Expense::whereKey($expense->id)->lockForUpdate()->firstOrFail();
            $this->assertStatus($expense, 'approved', 'Only approved records can be voided.');
            if ($expense->voided_by !== null) {
                throw ValidationException::withMessages(['status' => 'A void has already been requested.']);
            }

            $old = $this->snapshot($expense);
            $expense->voided_by = $by->id;
            $expense->void_requested_at = now();
            $expense->void_reason = $reason;
            $expense->save();

            // Notification to all admins is audit-only for now (see docs/option-a-report.md).
            AuditService::log(
                'fund_usage.void_requested',
                "Void of {$expense->reference()} requested: {$reason}. Needs a second admin to confirm.",
                $expense,
                $expense->club_id,
                $old,
                $this->snapshot($expense) + ['reason' => $reason]
            );

            return $expense;
        });
    }

    public function confirmVoid(Expense $expense, User $by): Expense
    {
        return DB::transaction(function () use ($expense, $by) {
            $expense = Expense::whereKey($expense->id)->lockForUpdate()->firstOrFail();
            $this->assertStatus($expense, 'approved', 'Only approved records can be voided.');
            if ($expense->voided_by === null) {
                throw ValidationException::withMessages(['status' => 'No void has been requested.']);
            }
            if ((int) $expense->voided_by === (int) $by->id) {
                abort(403, 'A different admin must confirm the void.');
            }

            $old = $this->snapshot($expense);
            $expense->status = 'void';
            $expense->void_approved_by = $by->id;
            $expense->voided_at = now();
            $expense->save();

            AuditService::log(
                'fund_usage.voided',
                "{$expense->reference()} voided (RM {$expense->amount}); excluded from all totals.",
                $expense,
                $expense->club_id,
                $old,
                $this->snapshot($expense)
            );

            return $expense;
        });
    }

    /** Drafts may be hard-deleted by their recorder; nothing else may. */
    public function deleteDraft(Expense $expense, User $by): void
    {
        $this->assertRecorder($expense, $by, 'delete');

        DB::transaction(function () use ($expense) {
            $expense = Expense::whereKey($expense->id)->lockForUpdate()->firstOrFail();
            $this->assertStatus($expense, 'draft', 'Only drafts can be deleted. Approved records can only be voided.');

            $old = $this->snapshot($expense);
            $ref = $expense->reference();
            foreach ($expense->attachments as $a) {
                $this->attachments->delete($a);
            }
            $expense->welfareCase()->delete();
            $expense->delete();

            AuditService::log('fund_usage.deleted', "Draft {$ref} deleted.", null, $old['club_id'], $old);
        });
    }

    /**
     * Why $user may not approve $expense right now, or null when they may.
     * Status is not checked here.
     */
    public function approvalBlocker(Expense $expense, User $user): ?string
    {
        if ((int) $expense->recorded_by === (int) $user->id) {
            return 'You recorded this, so a different admin must approve it.';
        }
        if ($this->isBeneficiary($expense, $user)) {
            return 'You are the beneficiary of this welfare case, so you cannot approve it.';
        }
        if (! $this->isClubAdmin($expense->club, $user)) {
            return 'Only an admin of this club can approve it.';
        }

        return null;
    }

    /** Club admins (excluding the maker and beneficiary) who could approve. Super admins are not counted. */
    public function eligibleApproverCount(Expense $expense): int
    {
        $exclude = array_filter([$expense->recorded_by, $expense->welfareCase?->beneficiary_user_id]);

        if (! $expense->club->is_active) {
            return 0;
        }

        return $expense->club->members()
            ->wherePivot('role', 'admin')
            ->wherePivot('is_active', true)
            ->whereNotIn('users.id', $exclude)
            ->count();
    }

    /** Active admin of an active club (super admins always). Same rule as AuthorizesClubResource. */
    public function isClubAdmin(Club $club, User $user): bool
    {
        return $user->isSuperAdmin() || ($club->is_active && $club->members()
            ->where('users.id', $user->id)
            ->wherePivot('role', 'admin')
            ->wherePivot('is_active', true)
            ->exists());
    }

    public function isBeneficiary(Expense $expense, User $user): bool
    {
        return $expense->isWelfare() && $expense->welfareCase
            && (int) $expense->welfareCase->beneficiary_user_id === (int) $user->id;
    }

    private function assertFileCount(int $kept, int $adding): void
    {
        if ($kept + $adding > Attachment::MAX_FILES) {
            throw ValidationException::withMessages(['files' => 'A record can have at most '.Attachment::MAX_FILES.' documents.']);
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /** @param  array<string, mixed>  $data */
    private function expenseFields(array $data): array
    {
        $fields = array_intersect_key($data, array_flip([
            'type', 'expense_category_id', 'description', 'payee', 'invoice_no',
            'amount', 'expense_date', 'paid_date', 'minute_ref',
        ]));
        if (($fields['type'] ?? null) === 'welfare') {
            // The recipient is personal data held encrypted on welfare_cases.
            $fields['payee'] = null;
            $fields['invoice_no'] = null;
        }

        return $fields;
    }

    private function toDraft(Expense $expense): void
    {
        $expense->status = 'draft';
        $expense->submitted_at = null;
        // Unlock documents so the maker can fix them; the audit log keeps the hashes.
        $expense->attachments()->update(['frozen_at' => null]);
    }

    /** @param  array<string, mixed>  $w */
    private function saveWelfare(Expense $expense, array $w): void
    {
        $case = $expense->welfareCase ?? new WelfareCase(['expense_id' => $expense->id]);
        $case->fill([
            'beneficiary_user_id' => $w['beneficiary_user_id'] ?? null,
            'subject_name' => $w['subject_name'] ?? '',
            'relationship' => $w['relationship'] ?? '',
            'recipient_name' => $w['recipient_name'] ?? '',
            'certificate_sighted_by' => $w['certificate_sighted_by'] ?? null,
            'certificate_sighted_at' => $w['certificate_sighted_at'] ?? null,
            'retention_until' => Carbon::parse($expense->expense_date)->addYears(WelfareCase::RETENTION_YEARS)->toDateString(),
        ]);
        $case->expense_id = $expense->id;
        $case->save();
        $expense->setRelation('welfareCase', $case);
    }

    /** @param  array<string, mixed>  $w */
    private function welfareDirty(Expense $expense, array $w): bool
    {
        $case = $expense->welfareCase;
        if (! $case) {
            return true;
        }
        $sightedAt = $case->certificate_sighted_at?->toDateString();

        return (int) ($w['beneficiary_user_id'] ?? 0) !== (int) $case->beneficiary_user_id
            || (string) ($w['subject_name'] ?? '') !== (string) $case->subject_name
            || (string) ($w['relationship'] ?? '') !== (string) $case->relationship
            || (string) ($w['recipient_name'] ?? '') !== (string) $case->recipient_name
            || (int) ($w['certificate_sighted_by'] ?? 0) !== (int) $case->certificate_sighted_by
            || ($w['certificate_sighted_at'] ?? null) !== $sightedAt;
    }

    private function assertRecorder(Expense $expense, User $by, string $verb): void
    {
        if ((int) $expense->recorded_by !== (int) $by->id) {
            abort(403, "Only the admin who recorded this can {$verb} it.");
        }
    }

    private function assertStatus(Expense $expense, string $status, string $message): void
    {
        if ($expense->status !== $status) {
            throw ValidationException::withMessages(['status' => $message]);
        }
    }

    private function label(Expense $expense): string
    {
        return $expense->isWelfare() ? 'Welfare case' : 'Expense';
    }

    /** Non-personal fields for the audit log. */
    private function snapshot(Expense $expense): array
    {
        return [
            'club_id' => $expense->club_id,
            'type' => $expense->type,
            'status' => $expense->status,
            'expense_category_id' => $expense->expense_category_id,
            'description' => $expense->description,
            'payee' => $expense->payee,
            'invoice_no' => $expense->invoice_no,
            'amount' => (string) $expense->amount,
            'expense_date' => $expense->expense_date?->toDateString(),
            'paid_date' => $expense->paid_date?->toDateString(),
            'minute_ref' => $expense->minute_ref,
            'approved_by' => $expense->approved_by,
            'voided_by' => $expense->voided_by,
            'void_approved_by' => $expense->void_approved_by,
        ];
    }
}
