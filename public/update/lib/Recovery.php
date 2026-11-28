<?php

declare(strict_types=1);

namespace ClubPortalUpdater;

/**
 * Undoes an abandoned update without a token (spec §5 recovery, J4): the page was closed or the phone slept and no step
 * ran for 10 minutes. It only ever restores the installed release, so it is safe for any web request to trigger.
 * Runs from web requests only (the swap guard, the updater page), never from artisan: the CLI cannot reset the
 * website's PHP cache.
 */
final class Recovery
{
    /** No step or slice for this long means the update was abandoned (the 10-minute clock, `last_step_at`). */
    public const IDLE_AFTER = 600;

    public const REASON = 'The update page was closed, or the screen went off, and no step ran for 10 minutes, so the update was undone.';

    /** Job statuses of an update that has not ended yet (every other status is final). */
    private const OPEN_JOB_STATUSES = ['ready', 'running'];

    /**
     * Takes the update lock and, when the update is still abandoned under it, undoes it. Null when another request holds
     * the lock, the update moved recently, the journal cannot be read, or there is nothing to undo.
     *
     * @throws UpdaterError when the old files could not all be put back (the portal stays paused; a later idle request
     *                      tries again)
     */
    public static function runIfIdle(string $app, ?int $now = null): ?string
    {
        $f = Files::forApp($app);
        $lock = Lock::acquire($f->dir);
        if ($lock === null) {
            return null;
        }
        try {
            if (! self::isAbandoned($f, $now ?? time())) {
                return null;
            }

            return self::run($app);
        } finally {
            $lock->release();
        }
    }

    /** True when the guard is up (or a job is still open) and nothing has moved for IDLE_AFTER seconds. */
    public static function isAbandoned(Files $f, int $now): bool
    {
        clearstatcache();
        $job = self::readJob($f);
        $open = $job === null ? false : in_array($job['status'] ?? null, self::OPEN_JOB_STATUSES, true);
        if (! is_file($f->guardPath) && ! $open) {
            return false;
        }
        $idle = self::idleSeconds($f, $now, $job);

        return $idle !== null && $idle >= self::IDLE_AFTER;
    }

    /**
     * Undoes the update. The caller holds the lock. The journal is marked first, so no step can start afterwards; the
     * guard file is the last thing removed, so a crash part-way is simply tried again by the next idle request.
     *
     * @return string the outcome: `undone`, or the job's own final status when an earlier path already ended it
     *
     * @throws UpdaterError when the journal cannot be read (nothing is changed) or the old files could not all be put back
     */
    public static function run(string $app): string
    {
        $f = Files::forApp($app);
        $j = Journal::open($f->dir);
        $job = self::readJob($f) ?? [];
        $status = $job['status'] ?? null;
        $step = $j->firstUnfinished();
        $j->markRecovering();

        $finished = $status === 'finished' || (($j->steps()['finish']['state'] ?? null) === 'done');
        if ($j->isSwapStarted() && ! $finished) {
            try {
                Swap::rollback($j);
            } catch (UpdaterError $e) {
                $j->log('swap', 'Undoing the abandoned update: '.$e->getMessage());
                throw $e;
            }
            if (function_exists('opcache_reset')) {
                @opcache_reset();
            }
        }

        $result = is_string($status) && ! in_array($status, self::OPEN_JOB_STATUSES, true) ? $status : 'undone';
        if ($result === 'undone') {
            Outcome::write($f->dir, [
                'outcome' => 'undone',
                'from' => $job['from_version'] ?? null,
                'to' => $job['to_version'] ?? null,
                'step' => $step,
                'reason' => self::REASON,
                'at' => time(),
                'started_at' => is_int($job['created_at'] ?? null) ? $job['created_at'] : self::firstStart($j),
                'log' => $step !== null ? ($j->steps()[$step]['log'] ?? []) : [],
                'steps' => self::timings($j),
            ]);
            $job['status'] = 'undone';
            $job['ended_at'] = time();
            Files::writeAtomic($f->jobPath, (string) json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
        }
        Maintenance::off($app);

        return $result;
    }

    /**
     * Seconds since the last progress: the journal's `last_step_at`; without one, the guard file's age; without that, the
     * job's `last_step_at`. Null when the journal exists but cannot be read, or nothing tells.
     *
     * @param  ?array<string, mixed>  $job
     */
    private static function idleSeconds(Files $f, int $now, ?array $job): ?int
    {
        if (is_file($f->journalPath)) {
            $raw = @file_get_contents($f->journalPath);
            $journal = is_string($raw) ? json_decode($raw, true) : null;
            if (! is_array($journal)) {
                return null;
            }
            if (is_int($journal['last_step_at'] ?? null)) {
                return max(0, $now - $journal['last_step_at']);
            }
        }
        $mtime = is_file($f->guardPath) ? @filemtime($f->guardPath) : false;
        if (is_int($mtime)) {
            return max(0, $now - $mtime);
        }
        if (is_int($job['last_step_at'] ?? null)) {
            return max(0, $now - $job['last_step_at']);
        }

        return null;
    }

    /** The job, null when there is none; a file that cannot be read gives an empty job (status unknown). */
    private static function readJob(Files $f): ?array
    {
        if (! is_file($f->jobPath)) {
            return null;
        }
        $raw = @file_get_contents($f->jobPath);
        $job = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($job) ? $job : [];
    }

    private static function firstStart(Journal $j): ?int
    {
        $starts = array_filter(array_column($j->steps(), 'started_at'), 'is_int');

        return $starts === [] ? null : min($starts);
    }

    /** @return array<string, array{state: string, started_at: mixed, finished_at: mixed}> */
    private static function timings(Journal $j): array
    {
        $out = [];
        foreach ($j->steps() as $name => $s) {
            $out[$name] = ['state' => $s['state'], 'started_at' => $s['started_at'], 'finished_at' => $s['finished_at']];
        }

        return $out;
    }
}
