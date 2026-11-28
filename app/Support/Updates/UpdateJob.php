<?php

declare(strict_types=1);

namespace App\Support\Updates;

use App\Models\User;
use App\Support\Portal\PortalSettings;
use RuntimeException;

/** storage/app/updater/job.json: what the standalone updater is asked to do (spec section 4). */
final class UpdateJob
{
    /** The job expires this long after its last step. */
    public const EXPIRES_AFTER = 1800;

    /**
     * Writes the job (atomically) and returns the plain token; only its sha256 is stored.
     *
     * @throws RuntimeException when the file cannot be written
     */
    public static function create(Manifest $m, User $u, bool $dbBackupTicked, bool $manualTicked): string
    {
        $token = bin2hex(random_bytes(32));
        $now = time();
        $job = [
            'manifest' => $m->toArray(),
            'token_hash' => hash('sha256', $token),
            'user_id' => $u->id,
            'user_name' => $u->name,
            'created_at' => $now,
            'last_step_at' => $now,
            'expires_at' => $now + self::EXPIRES_AFTER,
            'status' => 'ready',
            'db_backup_ticked' => $dbBackupTicked,
            'manual_ticked' => $manualTicked,
            'from_version' => (new UpdaterHost)->installedVersion(),
            'to_version' => $m->version,
            'timezone' => (string) config('app.timezone'),
            'locale' => PortalSettings::defaultLocale(),
            'portal_name' => PortalSettings::name(),
        ];

        $dir = UpdaterFiles::dir();
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException('The update folder cannot be created.');
        }
        $temp = $dir.'/job.json.'.bin2hex(random_bytes(6)).'.tmp';
        if (@file_put_contents($temp, json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) === false
            || ! @rename($temp, UpdaterFiles::jobPath())) {
            @unlink($temp);
            throw new RuntimeException('The update job cannot be written.');
        }

        return $token;
    }

    /** The job, null when there is none. A file that cannot be read counts as a running job. */
    public static function current(): ?array
    {
        $file = UpdaterFiles::jobPath();
        if (! is_file($file)) {
            return null;
        }
        $job = json_decode((string) @file_get_contents($file), true);

        return is_array($job) ? $job : ['status' => 'running', 'unreadable' => true];
    }

    public static function isRunning(): bool
    {
        $job = self::current();

        return $job !== null && ! in_array($job['status'] ?? null, UpdaterHost::TERMINAL_JOB_STATUSES, true);
    }
}
