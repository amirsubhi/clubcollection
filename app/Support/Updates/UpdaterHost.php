<?php

declare(strict_types=1);

namespace App\Support\Updates;

use App\Console\Commands\BackupRun;
use App\Models\PaymentBill;
use App\Support\ReleaseInfo;
use Symfony\Component\Process\ExecutableFinder;
use Throwable;

/**
 * The host facts the pre-checks depend on, read from the real server. Every read is cheap and never changes anything
 * except the rename probe, which cleans up after itself. Tests replace this with a fake (PreChecks::$host).
 *
 * Methods may throw; PreChecks decides per check whether an unreadable fact is a pass or a fail.
 */
class UpdaterHost
{
    /** PHP functions the updater calls (opcache_reset is optional and not listed). Later tasks extend this list. */
    public const NEEDED_FUNCTIONS = ['rename', 'copy', 'unlink', 'mkdir', 'rmdir', 'file_put_contents', 'fopen', 'fwrite',
        'flock', 'hash_file', 'scandir', 'curl_init', 'curl_setopt', 'curl_exec', 'curl_errno', 'curl_error', 'curl_getinfo',
        'fread', 'fclose', 'filesize', 'file_get_contents', 'clearstatcache', 'stat', 'random_bytes', 'chmod', 'fileperms'];

    /** A job in one of these states is over and no longer blocks a new update. */
    public const TERMINAL_JOB_STATUSES = ['finished', 'undone', 'failed', 'rolled_back'];

    public function installedVersion(): string
    {
        return ReleaseInfo::version((string) config('updates.release_file'));
    }

    public function siteDomain(): string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : 'this site';
    }

    public function basePath(): string
    {
        return base_path();
    }

    /** Where the new release is staged: next to the app folder. */
    public function stagingParent(): string
    {
        return dirname(base_path());
    }

    public function phpVersion(): string
    {
        return PHP_VERSION;
    }

    public function extensionLoaded(string $name): bool
    {
        return extension_loaded($name);
    }

    /** @return list<string> the functions the updater needs that this script cannot call (function_exists, not ini parsing) */
    public function disabledFunctions(): array
    {
        return array_values(array_filter(self::NEEDED_FUNCTIONS, fn (string $f) => ! function_exists($f)));
    }

    /** True when code changes show up on their own (OPcache off, or it re-checks file dates). */
    public function opcacheValidatesTimestamps(): bool
    {
        if (! extension_loaded('Zend OPcache') || ! filter_var(ini_get('opcache.enable'), FILTER_VALIDATE_BOOLEAN)) {
            return true;
        }

        return filter_var(ini_get('opcache.validate_timestamps'), FILTER_VALIDATE_BOOLEAN);
    }

    /** True when opcache_reset() exists and this script may call it (never calls it). */
    public function opcacheResetAllowed(): bool
    {
        if (! function_exists('opcache_reset')) {
            return false;
        }
        $restrict = (string) ini_get('opcache.restrict_api');
        if ($restrict === '') {
            return true;
        }
        $script = realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));

        return $script !== false && str_starts_with($script, $restrict);
    }

    /** Free bytes on the filesystem holding $path (not the account's quota). @throws \RuntimeException when unreadable */
    public function freeBytes(string $path): float
    {
        $free = @disk_free_space($path);
        if ($free === false) {
            throw new \RuntimeException('Free disk space cannot be read.');
        }

        return $free;
    }

    /**
     * Entries under $base/$relativePaths that PHP cannot write (at most 20 are returned), as paths relative to $base.
     *
     * @param  list<string>  $relativePaths
     * @return list<string>
     */
    public function unwritablePaths(string $base, array $relativePaths): array
    {
        $bad = [];
        foreach ($relativePaths as $rel) {
            $path = $base.'/'.$rel;
            if (! file_exists($path)) {
                continue;
            }
            if (! is_writable($path)) {
                $bad[] = $rel;
            }
            if (is_dir($path) && ! is_link($path)) {
                $it = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::SELF_FIRST,
                );
                foreach ($it as $entry) {
                    if (! $entry->isLink() && ! $entry->isWritable()) {
                        $bad[] = $rel.substr($entry->getPathname(), strlen($path));
                    }
                    if (count($bad) >= 20) {
                        return $bad;
                    }
                }
            }
        }

        return $bad;
    }

    /** Really creates a folder in $parent, renames it and removes it again; false on any refusal. */
    public function canCreateAndRename(string $parent): bool
    {
        if (! is_dir($parent)) {
            return false;
        }
        $name = '.update-probe-'.bin2hex(random_bytes(6));
        $a = rtrim($parent, '/\\').'/'.$name;
        $b = $a.'-b';
        try {
            if (! @mkdir($a, 0700)) {
                return false;
            }

            return @rename($a, $b);
        } catch (Throwable) {
            return false;
        } finally {
            foreach ([$a, $b] as $left) {
                if (is_dir($left)) {
                    @rmdir($left);
                }
            }
        }
    }

    public function pendingMigrations(): int
    {
        $migrator = app('migrator');
        $files = $migrator->getMigrationFiles(array_merge($migrator->paths(), [database_path('migrations')]));
        $repository = $migrator->getRepository();
        $ran = $repository->repositoryExists() ? $repository->getRan() : [];

        return count(array_diff(array_keys($files), $ran));
    }

    /**
     * An FPX bill opened in the last 2 minutes and not yet paid or replaced. (Cache locks cannot be listed on every
     * driver, so a held pay:* lock is not looked for.)
     */
    public function paymentInProgress(): bool
    {
        return PaymentBill::query()
            ->where('method', 'gateway')
            ->whereNotNull('bill_code')
            ->whereNull('paid_at')
            ->whereNull('superseded_at')
            ->whereRaw('COALESCE(opened_at, created_at) >= ?', [now()->subMinutes(2)])
            ->exists();
    }

    /** The job file exists and its status is not a terminal one; an unreadable file counts as running. */
    public function updateRunning(): bool
    {
        return UpdateJob::isRunning();
    }

    public function databaseDriver(): string
    {
        return (string) config('database.connections.'.config('database.default').'.driver');
    }

    /**
     * True when backup:run cannot put the database in the backup on this host. SQLite is copied by backup:run itself;
     * MySQL/MariaDB needs a program it can start (mysqldump); any other driver is not backed up by it.
     */
    public function databaseBackupNeedsTick(): bool
    {
        $driver = $this->databaseDriver();
        if ($driver === 'sqlite') {
            return false;
        }
        if (! in_array($driver, ['mysql', 'mariadb'], true) || ! BackupRun::canStartProcesses()) {
            return true;
        }
        $finder = new ExecutableFinder;

        return ($finder->find('mysqldump') ?? $finder->find('mariadb-dump')) === null;
    }
}
