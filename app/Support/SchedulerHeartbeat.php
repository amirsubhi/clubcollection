<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Proof that the cron job runs `schedule:run`: a one-minute scheduled task
 * writes the time to storage/framework/schedule-heartbeat (gitignored), and `app:doctor`
 * fails when it is missing or over 10 minutes old. A file (not the cache)
 * so it also survives `cache:clear` and an `array` cache store.
 */
final class SchedulerHeartbeat
{
    public const STALE_MINUTES = 10;

    public static function path(): string
    {
        return storage_path('framework/schedule-heartbeat');
    }

    public static function beat(): void
    {
        @file_put_contents(self::path(), (string) now()->getTimestamp(), LOCK_EX);
    }

    public static function last(): ?CarbonImmutable
    {
        $raw = @file_get_contents(self::path());

        return is_string($raw) && ctype_digit(trim($raw)) ? CarbonImmutable::createFromTimestamp((int) trim($raw)) : null;
    }
}
