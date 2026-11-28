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

    /** Writes a whole file through a temp file in the same folder and a rename: a reader sees the old or the new one. */
    public static function writeAtomic(string $path, string $content): void
    {
        $tmp = $path.'.'.bin2hex(random_bytes(4)).'.tmp';
        if (@file_put_contents($tmp, $content) !== strlen($content) || ! @rename($tmp, $path)) {
            @unlink($tmp);
            throw new UpdaterError('Cannot write '.basename($path).' (disk full or folder not writable).');
        }
    }

    /** The app this updater is installed in: public/update/lib -> three levels up. */
    public static function here(): self
    {
        return self::forApp(dirname(__DIR__, 3));
    }
}
