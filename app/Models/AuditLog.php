<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    protected $fillable = [
        'user_id',
        'user_name',
        'user_role',
        'action',
        'auditable_type',
        'auditable_id',
        'club_id',
        'description',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function club()
    {
        return $this->belongsTo(Club::class);
    }

    public function auditable()
    {
        return $this->morphTo();
    }

    public function getActionLabelAttribute(): string
    {
        return match ($this->action) {
            'auth.login'            => 'Logged In',
            'auth.logout'           => 'Logged Out',
            'member.added'          => 'Member Added',
            'member.updated'        => 'Member Updated',
            'member.removed'        => 'Member Removed',
            'member.imported'       => 'Members Imported (CSV)',
            'payment.created'       => 'Payment Created',
            'payment.updated'       => 'Payment Updated',
            'payment.marked_paid'   => 'Payment Marked Paid',
            'payment.deleted'       => 'Payment Deleted',
            'expense.created'       => 'Expense Created',
            'expense.updated'       => 'Expense Updated',
            'expense.deleted'       => 'Expense Deleted',
            'fund_usage.created'        => 'Fund Usage Recorded',
            'fund_usage.updated'        => 'Fund Usage Edited',
            'fund_usage.submitted'      => 'Fund Usage Submitted',
            'fund_usage.approved'       => 'Fund Usage Approved',
            'fund_usage.returned'       => 'Fund Usage Returned',
            'fund_usage.void_requested' => 'Fund Usage Void Requested',
            'fund_usage.voided'         => 'Fund Usage Voided',
            'fund_usage.deleted'        => 'Fund Usage Draft Deleted',
            'attachment.added'          => 'Document Attached',
            'attachment.removed'        => 'Document Removed',
            'attachment.viewed'         => 'Document Viewed',
            'attachment.downloaded'     => 'Document Downloaded',
            'discount.created'      => 'Discount Created',
            'discount.updated'      => 'Discount Updated',
            'discount.deleted'      => 'Discount Deleted',
            'fee_rate.updated'      => 'Fee Rates Updated',
            'fee_rate.deleted'      => 'Fee Rate Deleted',
            'job_level.created'     => 'Job Level Added',
            'job_level.updated'     => 'Job Level Updated',
            'job_level.deactivated' => 'Job Level Deactivated',
            'job_level.reactivated' => 'Job Level Reactivated',
            'job_level.deleted'     => 'Job Level Deleted',
            'club.created'          => 'Club Created',
            'club.updated'          => 'Club Updated',
            'club.deleted'          => 'Club Deleted',
            '2fa.enabled'           => '2FA Enabled',
            '2fa.disabled'          => '2FA Disabled',
            default                 => ucwords(str_replace(['.', '_'], ' ', $this->action)),
        };
    }

    /**
     * Chip colour by meaning: danger for removals, voids and rejections; success for approvals and money received;
     * warning for things someone should look at (a returned record, a webhook that did not line up); neutral
     * (secondary) for routine activity such as creating, editing, signing in or viewing a document.
     */
    public function getActionBadgeClassAttribute(): string
    {
        $a = (string) $this->action;
        $verb = str_contains($a, '.') ? substr($a, strpos($a, '.') + 1) : $a;

        return match (true) {
            in_array($verb, ['deleted', 'removed', 'voided', 'void_requested', 'transfer_rejected', 'rejected'], true)
                => 'danger',
            in_array($verb, ['approved', 'marked_paid', 'paid_via_webhook', 'reconciled', 'transfer_approved', 'bank_change_approved'], true)
                => 'success',
            in_array($verb, ['returned', 'underpaid_webhook', 'unmatched_webhook', 'webhook_unconfirmed', 'webhook_amount_unknown', 'gateway_paid_twice',
                'gateway_paid_after_manual', 'gateway_paid_during_verification', 'marked_overdue'], true)
                => 'warning',
            default
                => 'secondary',
        };
    }
}
