<?php

namespace App\Listeners;

use App\Events\AccountLoginBlocked;
use App\Mail\AccountSecurityNotice;
use App\Models\User;
use App\Services\AuditService;
use App\Support\AfterResponse;
use App\Support\Auth\AccountLoginBlock;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Mail;

/**
 * Audit rows (and owner emails) for sign-in security events. Found by Laravel's listener discovery
 * (app/Listeners, one handle* method per event type): do not also register it by hand, or every
 * event is handled twice. Never logs or passes on the credentials.
 */
class AuthSecurityListener
{
    /** A wrong password (SessionGuard::attempt). */
    public function handleFailed(Failed $event): void
    {
        $user = $event->user instanceof User ? $event->user : null;

        AuditService::logAccount($user, 'auth.failed', $user
            ? "Wrong password for '{$user->name}'."
            : 'Sign-in attempt for an email with no account.');
    }

    /** The per-IP or per-email+IP sign-in limit was hit (AppServiceProvider "login"). */
    public function handleLockout(Lockout $event): void
    {
        $email = $event->request->input('email');
        $user = is_string($email) && $email !== '' ? self::findUser($email) : null;

        AuditService::logAccount($user, 'auth.lockout', $user
            ? "Too many sign-in attempts for '{$user->name}' from this IP: paused for a minute."
            : 'Too many sign-in attempts from this IP: paused for a minute.');
    }

    /** Many wrong passwords for one email from any IP: sign-in paused (AccountLoginBlock). Once per pause. */
    public function handleAccountLoginBlocked(AccountLoginBlocked $event): void
    {
        $user = $event->user;

        AuditService::logAccount($user, 'auth.account_blocked', $user
            ? "Sign-in for '{$user->name}' paused for {$event->minutes} minutes after too many wrong passwords."
            : "Sign-in for an email with no account paused for {$event->minutes} minutes after too many wrong passwords.");

        if ($user) {
            AfterResponse::run(
                fn () => Mail::to($user->email)->send(new AccountSecurityNotice($user, AccountSecurityNotice::KIND_SIGN_IN_PAUSED, $event->minutes)),
                'sign-in paused email',
            );
        }
    }

    /** A password reset by email link completed. */
    public function handlePasswordReset(PasswordReset $event): void
    {
        $user = $event->user;
        if (! $user instanceof User) {
            return;
        }

        // The reset is how a paused account gets back in at once.
        AccountLoginBlock::clear($user->email);

        AuditService::logAccount($user, 'auth.password_reset', "Password for '{$user->name}' reset with an emailed link.");

        AfterResponse::run(
            fn () => Mail::to($user->email)->send(new AccountSecurityNotice($user, AccountSecurityNotice::KIND_PASSWORD_CHANGED)),
            'password changed email',
        );
    }

    public static function findUser(string $email): ?User
    {
        return AccountLoginBlock::resolveUser($email);
    }
}
