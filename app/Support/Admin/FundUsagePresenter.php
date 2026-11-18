<?php

namespace App\Support\Admin;

use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\Expense;
use App\Support\Member\MemberPaymentPresenter;
use Illuminate\Support\Collection;

/**
 * Shapes Fund Usage records for the admin Inertia pages.
 *
 * Fields are whitelisted: no storage paths (only the audited download
 * route), no raw model dumps. Welfare personal data (decrypted) is only
 * included by detail(), which is used solely on admin pages behind
 * authorizeClubAdmin(). Money is integer sen.
 */
final class FundUsagePresenter
{
    public static function sen(mixed $amount): int
    {
        return MemberPaymentPresenter::sen($amount);
    }

    /**
     * Row for lists and queues. No welfare personal data.
     *
     * @return array<string, mixed>
     */
    public static function row(Expense $e, ?int $viewerId = null): array
    {
        return [
            'id' => $e->id,
            'ref' => $e->reference(),
            'type' => $e->type,
            'status' => $e->status,
            'category' => $e->category?->name ?? '—',
            'description' => $e->description,
            'payee' => $e->isWelfare() ? null : $e->payee,
            'amount' => self::sen($e->amount),
            'invoiceDate' => $e->expense_date->toDateString(),
            'paidDate' => $e->paid_date?->toDateString(),
            'recordedBy' => $e->recordedBy?->name ?? 'Unknown',
            'approvedBy' => $e->approver?->name,
            'attachmentCount' => (int) ($e->attachments_count ?? $e->attachments()->count()),
            'returned' => $e->status === 'draft' && $e->return_reason !== null,
            'voidPending' => $e->isVoidPending(),
            'recordedByMe' => $viewerId !== null && (int) $e->recorded_by === $viewerId,
            'url' => route('admin.fund-usage.show', $e),
        ];
    }

    /**
     * Full record for the detail and edit pages (admins only).
     *
     * @return array<string, mixed>
     */
    public static function detail(Expense $e, ?int $viewerId = null): array
    {
        $w = $e->welfareCase;

        return self::row($e, $viewerId) + [
            'categoryId' => $e->expense_category_id,
            'invoiceNo' => $e->invoice_no,
            'minuteRef' => $e->minute_ref,
            'submittedAt' => $e->submitted_at?->format('Y-m-d\TH:i'),
            'approvedAt' => $e->approved_at?->format('Y-m-d\TH:i'),
            'returnReason' => $e->return_reason,
            'voidReason' => $e->void_reason,
            'voidRequestedBy' => $e->voidRequester?->name,
            'voidRequestedAt' => $e->void_requested_at?->format('Y-m-d\TH:i'),
            'voidApprovedBy' => $e->voidApprover?->name,
            'attachments' => $e->attachments->map(fn (Attachment $a) => self::attachment($a))->values(),
            'welfare' => $w ? [
                'beneficiaryId' => $w->beneficiary_user_id,
                'beneficiaryName' => $w->beneficiary?->name ?? 'Unknown',
                'subjectName' => $w->subject_name,
                'relationship' => $w->relationship,
                'recipientName' => $w->recipient_name,
                'certificateSightedById' => $w->certificate_sighted_by,
                'certificateSightedBy' => $w->sightedBy?->name,
                'certificateSightedOn' => $w->certificate_sighted_at?->toDateString(),
                'retentionUntil' => $w->retention_until?->toDateString(),
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function attachment(Attachment $a): array
    {
        return [
            'id' => (string) $a->id,
            'name' => $a->original_name,
            'kind' => $a->kind,
            'sizeKb' => max(1, (int) round($a->size / 1024)),
            'mime' => $a->mime,
            'sha256' => $a->sha256,
            'frozen' => $a->isFrozen(),
            // Unaudited image for gallery tiles and the inline slip preview (images only, not
            // cached); audited inline view when a preview is opened in full; audited download.
            'thumbUrl' => str_starts_with($a->mime, 'image/') ? route('admin.attachments.thumb', $a) : null,
            'url' => route('admin.attachments.show', ['attachment' => $a, 'inline' => 1]),
            'downloadUrl' => route('admin.attachments.show', $a),
        ];
    }

    private const TIMELINE = [
        'fund_usage.created' => 'recorded',
        'fund_usage.updated' => 'edited',
        'fund_usage.submitted' => 'submitted',
        'fund_usage.returned' => 'returned',
        'fund_usage.approved' => 'approved',
        'fund_usage.void_requested' => 'void_requested',
        'fund_usage.voided' => 'voided',
        'attachment.downloaded' => 'downloaded',
    ];

    /**
     * Audit trail for the record (oldest first), from audit_logs.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function timeline(Expense $e): Collection
    {
        $attachmentIds = $e->attachments->pluck('id');

        return AuditLog::query()
            ->where(function ($q) use ($e, $attachmentIds) {
                $q->where(fn ($q) => $q->where('auditable_type', Expense::class)->where('auditable_id', $e->id))
                    ->orWhere(fn ($q) => $q->where('auditable_type', Attachment::class)
                        ->whereIn('auditable_id', $attachmentIds)
                        ->where('action', 'attachment.downloaded'));
            })
            ->whereIn('action', array_keys(self::TIMELINE))
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(200)
            ->get()
            ->map(fn (AuditLog $log) => [
                'at' => $log->created_at->format('Y-m-d\TH:i'),
                'who' => $log->user_name,
                'action' => self::TIMELINE[$log->action],
                'note' => self::note($log),
            ])
            ->values();
    }

    private static function note(AuditLog $log): ?string
    {
        $new = $log->new_values ?? [];

        return match ($log->action) {
            'fund_usage.returned', 'fund_usage.void_requested' => $new['reason'] ?? null,
            'fund_usage.approved' => 'No conflict of interest declared',
            'attachment.downloaded' => $new['name'] ?? null,
            'fund_usage.updated' => ($log->old_values['status'] ?? null) === 'submitted' ? 'Edited after submission: back to draft' : null,
            default => null,
        };
    }
}
