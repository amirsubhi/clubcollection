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

    public const BLOCKED_REASON = 'The update stopped while it was changing the club\'s records, so one change may not have finished. The old version was put back. Restore the database from the copy saved before the update (or a cPanel backup), or have it checked, before trying again.';

    /** Job statuses of an update that has not ended yet (every other status is final). */
    private const OPEN_JOB_STATUSES = ['ready', 'running'];

    /**
     * Takes the update lock and, when the update is still abandoned under it (guard file up, idle), undoes it. Null when
     * another request holds the lock, the update moved recently, the journal cannot be read, or there is no guard file.
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

    /**
     * True when the swap guard is up and nothing has moved for IDLE_AFTER seconds. An update abandoned before the pause
     * (no guard file) is left to the updater page and the job's own expiry.
     */
    public static function isAbandoned(Files $f, int $now): bool
    {
        clearstatcache();
        if (! is_file($f->guardPath)) {
            return false;
        }
        $idle = self::idleSeconds($f, $now);

        return $idle !== null && $idle >= self::IDLE_AFTER;
    }

    /**
     * Undoes the update. The caller holds the lock. The journal is marked first, so no step can start afterwards; the
     * guard file is the last thing removed, so a crash part-way is simply tried again by the next idle request (the
     * journal's clock is moved first, so that is at most once per IDLE_AFTER). A failure is also kept in the journal's
     * `recovery_error` meta ({at, message}) for the updater page.
     *
     * An update left while `migrate` was begun but not done ends `blocked` (files put back, but the database may be
     * half changed: J5 last case); otherwise `undone`. The job is marked `undone` either way.
     *
     * @return string the outcome: `undone` or `blocked`, or the job's own final status when an earlier pass or path
     *                already ended it (then outcome.json and the history are not written again)
     *
     * @throws UpdaterError when the journal cannot be read (nothing is changed), the old files could not all be put
     *                      back, or the pause could not be lifted
     */
    public static function run(string $app): string
    {
        $f = Files::forApp($app);
        $j = Journal::open($f->dir);
        try {
            $result = self::undo($app, $f, $j);
        } catch (\Throwable $e) {
            self::noteError($j, $e->getMessage());
            throw $e;
        }
        $j->set('recovery_error', null);

        return $result;
    }

    /** Records a failed undo in the journal (`recovery_error` = {at, message}). Best effort: never throws. */
    public static function noteFailure(string $app, string $message): void
    {
        try {
            self::noteError(Journal::open(Files::forApp($app)->dir), $message);
        } catch (\Throwable) {
        }
    }

    private static function noteError(Journal $j, string $message): void
    {
        try {
            $j->set('recovery_error', ['at' => time(), 'message' => mb_substr($message, 0, 1000)]);
        } catch (\Throwable) {
        }
    }

    private static function undo(string $app, Files $f, Journal $j): string
    {
        $job = self::readJob($f) ?? [];
        $status = $job['status'] ?? null;
        $step = $j->firstUnfinished();
        $migrateState = $j->steps()['migrate']['state'] ?? null;
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

        $open = ! is_string($status) || in_array($status, self::OPEN_JOB_STATUSES, true);
        $result = is_string($status) ? $status : 'undone';
        if ($open) {
            $blocked = $migrateState !== null && $migrateState !== 'done' && ! $finished;
            $result = $blocked ? 'blocked' : 'undone';
            Outcome::write($f->dir, [
                'outcome' => $result,
                'from' => $job['from_version'] ?? null,
                'to' => $job['to_version'] ?? null,
                'step' => $blocked ? 'migrate' : $step,
                'reason' => $blocked ? self::BLOCKED_REASON : self::REASON,
                'at' => time(),
                'started_at' => is_int($job['created_at'] ?? null) ? $job['created_at'] : self::firstStart($j),
                'log' => $j->steps()[$blocked ? 'migrate' : (string) $step]['log'] ?? [],
                'steps' => self::timings($j),
            ]);
            $job['status'] = 'undone';
            $job['ended_at'] = time();
            Files::writeAtomic($f->jobPath, (string) json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
        }
        // If lifting the pause fails, the next try waits for the 10-minute clock again instead of every request.
        $j->touch();
        Maintenance::off($app);

        return $result;
    }

    /**
     * Seconds since the last progress: the journal's `last_step_at`, or the guard file's age when there is no journal
     * (or it has no time yet). Null when the journal exists but cannot be read, or nothing tells.
     */
    private static function idleSeconds(Files $f, int $now): ?int
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
        $mtime = @filemtime($f->guardPath);

        return is_int($mtime) ? max(0, $now - $mtime) : null;
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
