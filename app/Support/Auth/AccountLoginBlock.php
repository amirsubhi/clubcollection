<?php

namespace App\Support\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Per-account sign-in protection, on top of the per-IP limits (AppServiceProvider "login").
 *
 * Wrong passwords are counted per email, whatever the IP, so a slow guess from many addresses is still
 * stopped. After config('security.account_login.max_failures') failures within decay_minutes, sign-in
 * for that email pauses for block_minutes (a fixed pause from that moment, its own cache key). It is
 * never permanent, a password reset is never blocked and ends the pause, and an email with no account
 * is treated exactly the same (so the pause does not reveal which emails exist).
 *
 * Keys are the account id when the email has an account, else a hash of the normalised email (accountKey).
 */
final class AccountLoginBlock
{
    /**
     * The account an email signs in to: the same look-up as the login (the users provider's `email = ?`,
     * which MariaDB / MySQL *_ci collations match case- and accent-insensitively), then a lowercased match
     * so SQLite (case-sensitive `=`) behaves the same for case variants.
     */
    public static function resolveUser(string $email): ?User
    {
        $email = trim(mb_strcut($email, 0, 255));
        if ($email === '') {
            return null;
        }

        return User::query()->where('email', $email)->first()
            ?? User::query()->whereRaw('LOWER(email) = ?', [mb_strtolower($email)])->first();
    }

    /**
     * Counter / pause key. An existing account is keyed on its id, so spelling variants of its email
     * ("Jose@x", "josé@x", trailing spaces) that the database matches to the same row share one counter
     * and can't be rotated to dodge the pause. Only an email with no account falls back to a hash of
     * the normalised text (the typed value is attacker-controlled and unbounded).
     */
    public static function accountKey(string $email): string
    {
        $user = self::resolveUser($email);

        return $user ? 'uid:'.$user->id : 'email:'.sha1(mb_strtolower(trim($email)));
    }

    private static function counterKey(string $email): string
    {
        return 'login-account-failures:'.self::accountKey($email);
    }

    private static function blockKey(string $email): string
    {
        return 'login-account-block:'.self::accountKey($email);
    }

    /** Seconds until sign-in is allowed again, or 0 when the account is not paused. */
    public static function secondsLeft(string $email): int
    {
        $until = Cache::get(self::blockKey($email));

        return is_int($until) ? max(0, $until - now()->getTimestamp()) : 0;
    }

    public static function isBlocked(string $email): bool
    {
        return self::secondsLeft($email) > 0;
    }

    /**
     * Count one wrong password. Returns true only for the failure that starts a pause (once per pause),
     * so the caller can audit it and email the owner once.
     */
    public static function recordFailure(string $email): bool
    {
        if (self::isBlocked($email)) {
            return false;
        }

        $config = config('security.account_login');
        $counter = self::counterKey($email);
        RateLimiter::hit($counter, max(1, (int) $config['decay_minutes']) * 60);

        if (RateLimiter::attempts($counter) < max(1, (int) $config['max_failures'])) {
            return false;
        }

        $seconds = max(1, (int) $config['block_minutes']) * 60;
        $started = Cache::add(self::blockKey($email), now()->getTimestamp() + $seconds, $seconds);
        RateLimiter::clear($counter);

        return $started;
    }

    /** A successful sign-in or a password reset: forget the failures and end any pause. */
    public static function clear(string $email): void
    {
        RateLimiter::clear(self::counterKey($email));
        Cache::forget(self::blockKey($email));
    }
}
