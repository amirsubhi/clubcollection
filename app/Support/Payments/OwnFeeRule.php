<?php

namespace App\Support\Payments;

use App\Models\Payment;
use App\Models\User;

/**
 * Maker-checker for settling fees (S1): an admin never settles their own fee
 * (mark paid, a status edit to paid, or a transfer-slip decision). The super
 * admin is exempt so a single-admin club is never stuck.
 */
final class OwnFeeRule
{
    /** Why $admin may not settle $payment, or null when they may. */
    public static function blocker(Payment $payment, User $admin, string $action = 'check it'): ?string
    {
        if (! $admin->isSuperAdmin() && (int) $payment->user_id === (int) $admin->id) {
            return "This is your own fee, so another admin must {$action}.";
        }

        return null;
    }
}
