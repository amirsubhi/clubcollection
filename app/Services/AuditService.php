<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class AuditService
{
    /** > 0 while asSystem() runs: entries are written as the system, whoever is signed in. */
    private static int $systemDepth = 0;

    /**
     * Run $callback with every audit entry written as "System" (no user),
     * e.g. a payment settled by a requery the member's status check started:
     * the member did not mark anything paid.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function asSystem(callable $callback): mixed
    {
        self::$systemDepth++;
        try {
            return $callback();
        } finally {
            self::$systemDepth--;
        }
    }

    public static function log(
        string  $action,
        string  $description,
        ?Model  $model   = null,
        ?int    $clubId  = null,
        array   $old     = [],
        array   $new     = []
    ): void {
        $user = self::$systemDepth > 0 ? null : auth()->user();

        AuditLog::create([
            'user_id'        => $user?->id,
            'user_name'      => $user?->name ?? 'System',
            'user_role'      => $user?->role ?? 'system',
            'action'         => $action,
            'auditable_type' => $model ? get_class($model) : null,
            'auditable_id'   => $model?->id,
            'club_id'        => $clubId,
            'description'    => $description,
            'old_values'     => $old ?: null,
            'new_values'     => $new ?: null,
            'ip_address'     => request()->ip(),
            'user_agent'     => mb_strcut(mb_scrub((string) request()->userAgent()), 0, 255),
        ]);
    }

    /**
     * Sign-in events (failed password, lockout, account pause, password reset): written as the account
     * concerned, like the auth.login rows, since nobody is signed in yet. $account null = an email with no
     * account; the typed email itself is never stored (it may be a mistyped password).
     */
    public static function logAccount(?User $account, string $action, string $description): void
    {
        AuditLog::create([
            'user_id'     => $account?->id,
            'user_name'   => $account?->name ?? 'Guest',
            'user_role'   => $account?->role ?? 'guest',
            'action'      => $action,
            'description' => mb_strcut($description, 0, 1000),
            'ip_address'  => request()->ip(),
            'user_agent'  => mb_strcut(mb_scrub((string) request()->userAgent()), 0, 255),
        ]);
    }
}
