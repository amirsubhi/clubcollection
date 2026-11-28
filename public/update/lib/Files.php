<?php

declare(strict_types=1);

namespace ClubPortalUpdater;

/**
 * The fixed names of the updater's files, the same as App\Support\Updates\UpdaterFiles on the Laravel side.
 * Derived from the updater's own location, never from request input.
 */
final class Files
{
    private function __construct(
        public readonly string $app,
        public readonly string $dir,
        public readonly string $jobPath,
        public readonly string $journalPath,
        public readonly string $lockPath,
        public readonly string $outcomePath,
        public readonly string $pausePath,
        public readonly string $historyDir,
        public readonly string $blockedPath,
        public readonly string $webhooksDir,
        public readonly string $guardPath,
    ) {}

    public static function forApp(string $app): self
    {
        $app = rtrim($app, '/\\');
        $dir = $app.'/storage/app/updater';

        return new self(
            $app,
            $dir,
            $dir.'/job.json',
            $dir.'/journal.json',
            $dir.'/lock',
            $dir.'/outcome.json',
            $dir.'/pause.html',
            $dir.'/history',
            $dir.'/blocked',
            $dir.'/webhooks',
            $app.'/storage/framework/updating',
        );
    }

    /** The app this updater is installed in: public/update/lib -> three levels up. */
    public static function here(): self
    {
        return self::forApp(dirname(__DIR__, 3));
    }
}
