<?php

declare(strict_types=1);

namespace ClubPortalUpdater;

/** The one flock every step, slice and recovery takes (storage/app/updater/lock). Non-blocking. */
final class Lock
{
    /** @param resource $handle */
    private function __construct(private $handle) {}

    /** The lock, or null when another request holds it. */
    public static function acquire(string $updaterDir): ?self
    {
        if (! is_dir($updaterDir) && ! @mkdir($updaterDir, 0755, true) && ! is_dir($updaterDir)) {
            throw new UpdaterError('The updater folder cannot be created.');
        }
        $handle = @fopen(rtrim($updaterDir, '/\\').'/lock', 'c');
        if ($handle === false) {
            throw new UpdaterError('The updater lock file cannot be opened.');
        }
        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return null;
        }

        return new self($handle);
    }

    public function release(): void
    {
        if (is_resource($this->handle)) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
        }
    }

    public function __destruct()
    {
        $this->release();
    }
}
