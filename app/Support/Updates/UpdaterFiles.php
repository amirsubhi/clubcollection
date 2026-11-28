<?php

declare(strict_types=1);

namespace App\Support\Updates;

/** The fixed names of the updater's files (spec section 5 and 12). Everything lives under storage/app/updater/. */
final class UpdaterFiles
{
    public static function dir(): string
    {
        return storage_path('app/updater');
    }

    public static function jobPath(): string
    {
        return self::dir().'/job.json';
    }

    public static function journalPath(): string
    {
        return self::dir().'/journal.json';
    }

    /** The one flock file every step, slice and recovery takes. */
    public static function lockPath(): string
    {
        return self::dir().'/lock';
    }

    /** Written by code that runs without Laravel; consumed once by Laravel. */
    public static function outcomePath(): string
    {
        return self::dir().'/outcome.json';
    }

    /** The members' pause page. */
    public static function pausePath(): string
    {
        return self::dir().'/pause.html';
    }

    public static function historyDir(): string
    {
        return self::dir().'/history';
    }

    /** Present after a migration failed part-way: the update button stays off until a super admin clears it. */
    public static function blockedPath(): string
    {
        return self::dir().'/blocked';
    }

    /** Saved payment callbacks. */
    public static function webhooksDir(): string
    {
        return self::dir().'/webhooks';
    }

    /** The swap guard marker. */
    public static function guardPath(): string
    {
        return storage_path('framework/updating');
    }
}
