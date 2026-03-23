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
            'discount.created'      => 'Discount Created',
            'discount.updated'      => 'Discount Updated',
            'discount.deleted'      => 'Discount Deleted',
            'fee_rate.updated'      => 'Fee Rates Updated',
            'fee_rate.deleted'      => 'Fee Rate Deleted',
            'club.created'          => 'Club Created',
            'club.updated'          => 'Club Updated',
            'club.deleted'          => 'Club Deleted',
            '2fa.enabled'           => '2FA Enabled',
            '2fa.disabled'          => '2FA Disabled',
            default                 => ucwords(str_replace(['.', '_'], ' ', $this->action)),
        };
    }

    public function getActionBadgeClassAttribute(): string
    {
        return match (true) {
            str_ends_with($this->action, '.deleted') || str_ends_with($this->action, '.removed')
                => 'danger',
            str_ends_with($this->action, '.created') || str_ends_with($this->action, '.added') || str_ends_with($this->action, '.imported')
                => 'success',
            str_starts_with($this->action, 'auth.')
                => 'secondary',
            str_ends_with($this->action, '.marked_paid')
                => 'primary',
            default
                => 'warning',
        };
    }
}
