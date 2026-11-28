<?php

declare(strict_types=1);

namespace ClubPortalUpdater;

/**
 * Who may run the update's steps: the browser holding the job's token (cookie cp_update, the plain hex token; job.json
 * keeps only its sha256), while the job is `ready` or `running` and within 30 minutes of its last step.
 */
final class Auth
{
    /** The job expires this long after its last step (the same as App\Support\Updates\UpdateJob::EXPIRES_AFTER). */
    public const EXPIRES_AFTER = 1800;

    public const COOKIE = 'cp_update';

    public static function check(string $updaterDir, ?string $cookie, ?int $now = null): bool
    {
        if (! is_string($cookie) || preg_match('/^[0-9a-f]{64}$/', $cookie) !== 1) {
            return false;
        }
        $job = self::job($updaterDir);
        if ($job === null || ! in_array($job['status'] ?? null, ['ready', 'running'], true)) {
            return false;
        }
        $hash = $job['token_hash'] ?? null;
        if (! is_string($hash) || ! hash_equals($hash, hash('sha256', $cookie))) {
            return false;
        }
        $last = $job['last_step_at'] ?? null;

        return is_int($last) && ($now ?? time()) <= $last + self::EXPIRES_AFTER;
    }

    /** True when the cookie is this job's token, whatever the job's state (the result screens show the report to it). */
    public static function isTokenHolder(string $updaterDir, ?string $cookie): bool
    {
        if (! is_string($cookie) || preg_match('/^[0-9a-f]{64}$/', $cookie) !== 1) {
            return false;
        }
        $hash = self::job($updaterDir)['token_hash'] ?? null;

        return is_string($hash) && hash_equals($hash, hash('sha256', $cookie));
    }

    /** @return ?array<string, mixed> the job, null when it is missing or cannot be read */
    public static function job(string $updaterDir): ?array
    {
        $file = rtrim($updaterDir, '/\\').'/job.json';
        if (! is_file($file)) {
            return null;
        }
        $raw = @file_get_contents($file);
        $job = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($job) ? $job : null;
    }
}
