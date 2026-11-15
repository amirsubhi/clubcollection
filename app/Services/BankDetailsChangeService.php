<?php

namespace App\Services;

use App\Models\Club;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Maker-checker for the club's bank / DuitNow details (S1). Members transfer
 * money to whatever these say, so one admin can't change them alone:
 *
 *   request (any club admin or super admin) ──▶ pending ──approve (a different
 *   club admin, or a super admin who did not request it)──▶ live
 *                                                 └──cancel (anyone allowed, requester included)──▶ dropped
 *
 * Turning transfers off is never held back: it takes effect at once. Turning
 * them on with new details waits for the approval. Every step is audited and
 * every active admin of the club is emailed on request and on approval.
 */
class BankDetailsChangeService
{
    /**
     * Apply the transfer settings an admin saved. Returns true when a change
     * was left waiting for approval.
     *
     * @param  array{transfer_enabled: bool, bank_name: ?string, bank_account_name: ?string, bank_account_no: ?string, duitnow_id: ?string}  $wanted
     */
    public function save(Club $club, User $admin, array $wanted): bool
    {
        $detailsChanged = $this->details($wanted) !== $this->details($club->only(Club::BANK_DETAIL_FIELDS));

        if (! $detailsChanged) {
            // Same (already approved) details: switching on or off is immediate.
            if ((bool) $club->transfer_enabled !== $wanted['transfer_enabled']) {
                $club->forceFill(['transfer_enabled' => $wanted['transfer_enabled']])->save();
            }

            return false;
        }

        DB::transaction(function () use ($club, $admin, $wanted) {
            $club = Club::whereKey($club->id)->lockForUpdate()->firstOrFail();
            $replaced = $club->pending_bank_change;

            $club->forceFill([
                // Off is immediate; on waits (with the new details) for approval.
                'transfer_enabled' => $wanted['transfer_enabled'] ? (bool) $club->transfer_enabled : false,
                'pending_bank_change' => $wanted,
                'bank_change_requested_by' => $admin->id,
                'bank_change_requested_at' => now(),
            ])->save();

            AuditService::log(
                'club.bank_change_requested',
                "Bank / DuitNow details change requested for '{$club->name}' by {$admin->name}; waiting for a second admin to approve.".($replaced ? ' It replaces an earlier pending request.' : ''),
                $club,
                $club->id,
                $club->only(Club::BANK_DETAIL_FIELDS) + ['transfer_enabled' => (bool) $club->transfer_enabled],
                $wanted
            );
        });

        $club->refresh();
        $club->notifyAdmins(
            'Bank details change waiting for approval',
            [
                "{$admin->name} asked to change the bank / DuitNow details members pay into.",
                'New details: '.$this->describe($wanted).'.',
                'Members keep seeing the current details until another admin approves the change on the billing page. If you did not expect this, cancel it there.',
            ],
            route('admin.clubs.billing.edit', $club).'#bank-change',
            'Review the change'
        );

        return true;
    }

    /** Why $admin may not approve the pending change, or null when they may. */
    public function approvalBlocker(Club $club, User $admin): ?string
    {
        if (! $club->hasPendingBankChange()) {
            return 'There is no bank details change waiting.';
        }
        if ((int) $club->bank_change_requested_by === (int) $admin->id) {
            return 'You asked for this change, so another admin must approve it.';
        }
        if (! $admin->isSuperAdmin() && ! app(FundUsageService::class)->isClubAdmin($club, $admin)) {
            return 'Only an admin of this club can approve it.';
        }

        return null;
    }

    /**
     * @param  string  $version  the request time the approver saw, so a change
     *                           replaced in the meantime is never approved blind
     */
    public function approve(Club $club, User $admin, string $version): void
    {
        $applied = DB::transaction(function () use ($club, $admin, $version) {
            $club = Club::whereKey($club->id)->lockForUpdate()->firstOrFail();
            if ($reason = $this->approvalBlocker($club, $admin)) {
                throw ValidationException::withMessages(['bank_change' => $reason]);
            }
            if ($version !== $this->version($club)) {
                throw ValidationException::withMessages(['bank_change' => 'The pending change was replaced or cancelled after you opened this page. Check the new request and try again.']);
            }

            $wanted = $club->pending_bank_change;
            $old = $club->only(Club::BANK_DETAIL_FIELDS) + ['transfer_enabled' => (bool) $club->transfer_enabled];
            $requestedBy = $club->bank_change_requested_by;

            $club->forceFill([
                'bank_name' => $wanted['bank_name'] ?? null,
                'bank_account_name' => $wanted['bank_account_name'] ?? null,
                'bank_account_no' => $wanted['bank_account_no'] ?? null,
                'duitnow_id' => $wanted['duitnow_id'] ?? null,
                'transfer_enabled' => (bool) ($wanted['transfer_enabled'] ?? false),
                'pending_bank_change' => null,
                'bank_change_requested_by' => null,
                'bank_change_requested_at' => null,
            ])->save();
            // Never offer a transfer without complete details.
            if ($club->transfer_enabled && ! $club->transferDetailsComplete()) {
                $club->forceFill(['transfer_enabled' => false])->save();
            }

            AuditService::log(
                'club.bank_change_approved',
                "Bank / DuitNow details for '{$club->name}' approved by {$admin->name}; members now see them.",
                $club,
                $club->id,
                $old,
                $club->only(Club::BANK_DETAIL_FIELDS) + ['transfer_enabled' => (bool) $club->transfer_enabled, 'requested_by' => $requestedBy]
            );

            return $club;
        });

        $applied->notifyAdmins(
            'Bank details changed',
            [
                "{$admin->name} approved the new bank / DuitNow details. Members now see: ".$this->describe($applied->only(Club::BANK_DETAIL_FIELDS) + ['transfer_enabled' => (bool) $applied->transfer_enabled]).'.',
                'If you did not expect this, tell the super admin and check the audit log straight away.',
            ],
            route('admin.clubs.audit-logs', $applied),
            'Open the audit log'
        );
    }

    public function cancel(Club $club, User $admin): void
    {
        DB::transaction(function () use ($club, $admin) {
            $club = Club::whereKey($club->id)->lockForUpdate()->firstOrFail();
            if (! $club->hasPendingBankChange()) {
                throw ValidationException::withMessages(['bank_change' => 'There is no bank details change waiting.']);
            }
            $pending = $club->pending_bank_change;
            $club->forceFill(['pending_bank_change' => null, 'bank_change_requested_by' => null, 'bank_change_requested_at' => null])->save();

            AuditService::log(
                'club.bank_change_cancelled',
                "Pending bank / DuitNow details change for '{$club->name}' cancelled by {$admin->name}.",
                $club,
                $club->id,
                $pending,
                []
            );
        });
    }

    /** Identifies one pending request (the approval form carries it back). */
    public function version(Club $club): string
    {
        return sha1(json_encode([$club->bank_change_requested_at?->format('Y-m-d H:i:s'), (int) $club->bank_change_requested_by, $club->pending_bank_change]));
    }

    private function details(array $values): array
    {
        $out = [];
        foreach (Club::BANK_DETAIL_FIELDS as $field) {
            $out[$field] = $values[$field] ?? null;
        }

        return $out;
    }

    private function describe(array $v): string
    {
        $parts = array_filter([
            $v['bank_name'] ?? null,
            ($v['bank_account_name'] ?? null) ? 'holder '.$v['bank_account_name'] : null,
            ($v['bank_account_no'] ?? null) ? 'account '.$v['bank_account_no'] : null,
            ($v['duitnow_id'] ?? null) ? 'DuitNow '.$v['duitnow_id'] : null,
        ]);

        return ($parts ? implode(', ', $parts) : 'no details').'; transfers '.(! empty($v['transfer_enabled']) ? 'on' : 'off');
    }
}
