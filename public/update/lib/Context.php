<?php

declare(strict_types=1);

namespace ClubPortalUpdater;

/**
 * What one step request works with: the app folder (from the updater's own location, never from the request), the job,
 * the journal, and the seams tests replace: booting Laravel, fetching the zip, the PHP cache and the clock.
 *
 * The journal is opened by Steps::run once it holds the update lock (one Journal instance per request: each instance
 * saves its whole copy, so two would undo each other's writes).
 */
final class Context
{
    public readonly Files $files;

    /** @var array<string, mixed> */
    public array $job = [];

    public ?Journal $journal = null;

    /** @var \Closure(string): BootedApp boots the Laravel app installed in the given folder, in this process */
    public \Closure $boot;

    /** @var \Closure(string, string, int): array{done: bool, bytes: int} url, file, seconds: one download slice */
    public \Closure $fetch;

    /** @var \Closure(): bool resets the PHP cache; false when that is not allowed here */
    public \Closure $opcacheReset;

    /** @var \Closure(): ?int seconds to wait for PHP to notice changed files itself, null when it caches nothing */
    public \Closure $opcacheWait;

    /** @var \Closure(): int */
    public \Closure $clock;

    /** @var array<string, mixed> what the browser sent with the step (the health step's page results) */
    public array $input = [];

    /** True when the page is served over https (the bypass cookie is then Secure). */
    public bool $https = false;

    /** @var list<array{name: string, value: string, expires: int}> cookies index.php sets on the response */
    public array $cookies = [];

    /** Seconds one download or unpack request may spend before it answers `more`. */
    public int $sliceSeconds = 15;

    /** Zip entries extracted per Unpack::slice call. */
    public int $unpackBatch = 200;

    public function __construct(public readonly string $app)
    {
        $this->files = Files::forApp($app);
        $this->boot = static fn (string $app): BootedApp => LaravelApp::boot($app);
        $this->fetch = static fn (string $url, string $dest, int $seconds): array => Download::slice($url, $dest, $seconds);
        $this->opcacheReset = static function (): bool {
            if (! function_exists('opcache_reset')) {
                return false;
            }

            return @opcache_reset() === true;
        };
        $this->opcacheWait = static function (): ?int {
            if (! extension_loaded('Zend OPcache') || ! filter_var(ini_get('opcache.enable'), FILTER_VALIDATE_BOOLEAN)) {
                return null;
            }

            return max(0, (int) ini_get('opcache.revalidate_freq'));
        };
        $this->clock = static fn (): int => time();
    }

    public function now(): int
    {
        return (int) ($this->clock)();
    }

    /** Re-reads the job and opens the journal (call while holding the lock). */
    public function load(): void
    {
        $this->job = Auth::job($this->files->dir) ?? [];
        $this->journal = Journal::open($this->files->dir, $this->clock);
    }

    public function journal(): Journal
    {
        if ($this->journal === null) {
            $this->load();
        }

        return $this->journal;
    }

    /** Throws away the journal of an earlier attempt and starts an empty one (the old one is kept as journal-previous.json). */
    public function freshJournal(): Journal
    {
        $path = $this->files->journalPath;
        if (is_file($path) && ! @rename($path, $this->files->dir.'/journal-previous.json')) {
            @unlink($path);
        }
        clearstatcache(true, $path);
        if (is_file($path)) {
            throw new UpdaterError('The record of an earlier update cannot be cleared.');
        }
        $this->journal = Journal::open($this->files->dir, $this->clock);

        return $this->journal;
    }

    /** Saves the job with a new status (null keeps it), and the 30-minute clock restarted. */
    public function saveJob(?string $status = null): void
    {
        if ($this->job === []) {
            return;
        }
        $now = $this->now();
        if ($status !== null) {
            $this->job['status'] = $status;
            if ($status !== 'running') {
                $this->job['ended_at'] = $now;
            }
        }
        $this->job['last_step_at'] = $now;
        $this->job['expires_at'] = $now + Auth::EXPIRES_AFTER;
        Files::writeAtomic($this->files->jobPath, (string) json_encode($this->job, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    public function from(): string
    {
        return is_string($this->job['from_version'] ?? null) ? $this->job['from_version'] : '?';
    }

    public function to(): string
    {
        return is_string($this->job['to_version'] ?? null) ? $this->job['to_version'] : '?';
    }
}
