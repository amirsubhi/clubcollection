<?php

namespace App\Console\Commands;

use App\Services\AuditService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use ZipArchive;

/**
 * G2: a dated zip in storage/app/backups (outside public/) with the database
 * and the uploaded files (storage/app/private, plus the club logos in
 * storage/app/public). Scheduled daily; keeps the newest --keep zips.
 *
 * - SQLite: a consistent copy made with VACUUM INTO (safe while the site runs).
 * - MySQL / MariaDB: mysqldump when the host has it, with the credentials in a
 *   temporary option file (never on the command line). Without it, the zip
 *   holds the files only and the message says to use cPanel Backup.
 * - Never includes .env or APP_KEY: the uploaded files are encrypted with
 *   APP_KEY, so keep the key separately, off the server (docs/INSTALL.md).
 */
#[Signature('backup:run {--keep=14 : How many backup zips to keep (older ones are deleted)}')]
#[Description('Back up the database and uploaded files to storage/app/backups')]
class BackupRun extends Command
{
    /** Test seam: whether PHP may start mysqldump (null: ask the runtime). */
    public static ?\Closure $processAvailable = null;

    /** Own temporary files older than this are removed by the next run. */
    private const STALE_TEMP_SECONDS = 86400;

    /** Backup zip names for this app: <prefix>-YYYYmmdd-HHMMSS[-n].zip */
    public static function namePattern(string $prefix): string
    {
        return '/^'.preg_quote($prefix, '/').'-(\d{8}-\d{6})(?:-(\d+))?\.zip$/';
    }

    public static function prefix(): string
    {
        return Str::slug((string) config('app.name')) ?: 'clubportal';
    }

    public static function directory(): string
    {
        return storage_path('app/backups');
    }

    public function handle(): int
    {
        $dir = self::directory();
        if (! is_dir($dir) && ! @mkdir($dir, 0750, true) && ! is_dir($dir)) {
            $this->error("Cannot create {$dir}. Run chmod -R 775 storage in the app folder.");

            return self::FAILURE;
        }
        // storage/ is outside the web root; this is a second lock in case a host serves it anyway.
        if (! is_file("{$dir}/.htaccess")) {
            @file_put_contents("{$dir}/.htaccess", "Require all denied\n");
        }

        $prefix = self::prefix();
        $this->removeStaleTemp($dir);
        $stamp = now()->format('Ymd-His');
        $name = "{$prefix}-{$stamp}.zip";
        for ($n = 2; file_exists("{$dir}/{$name}"); $n++) {
            $name = "{$prefix}-{$stamp}-{$n}.zip";
        }

        // Registered for clean-up before anything can throw (a mysqldump timeout included).
        $tmp = "{$dir}/.db-".Str::random(12);
        $temp = [$tmp, "{$tmp}.cnf"];
        $ok = true;
        try {
            [$dbFile, $dbEntry, $dbNote, $dbOk] = $this->dumpDatabase(DB::connection()->getConfig(), $tmp);
            $ok = $dbOk;

            $partial = "{$dir}/.partial-{$name}";
            $temp[] = $partial;
            $zip = new ZipArchive;
            if ($zip->open($partial, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                $this->error("Cannot write {$partial}.");

                return self::FAILURE;
            }
            if ($dbFile !== null) {
                $zip->addFile($dbFile, $dbEntry);
            }
            $files = 0;
            foreach ([
                'storage/app/private' => (string) config('filesystems.disks.local.root'),
                'storage/app/public' => (string) config('filesystems.disks.public.root'),
            ] as $entryRoot => $root) {
                $files += $this->addFolder($zip, $root, $entryRoot);
            }
            $zip->addFromString('README.txt', $this->readme($dbEntry, $dbNote));
            if (! $zip->close()) {
                $this->error('Writing the zip failed (disk full?).');

                return self::FAILURE;
            }
            rename($partial, "{$dir}/{$name}");
        } catch (\Throwable $e) {
            $this->error('Backup failed: '.$e->getMessage());
            report($e);

            return self::FAILURE;
        } finally {
            foreach ($temp as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }
        }

        $size = filesize("{$dir}/{$name}") ?: 0;
        // A run whose database copy failed deletes nothing: otherwise 14 failed
        // nights would leave only backups without a database.
        // Likewise when this zip has no database at all (e.g. mysqldump unavailable):
        // keep the older zips that do hold one.
        $pruned = ($ok && $dbFile !== null) ? $this->prune($dir, $prefix, max(1, (int) $this->option('keep'))) : [];

        AuditService::asSystem(fn () => AuditService::log(
            'system.backup',
            "Backup {$name} created (".($dbEntry ?? 'no database copy').", {$files} file(s), ".number_format($size / 1048576, 1).' MB).',
            null, null, [],
            ['file' => $name, 'bytes' => $size, 'files' => $files, 'database' => $dbEntry !== null, 'pruned' => count($pruned)]
        ));

        $this->info("Backup written: storage/app/backups/{$name} (".number_format($size / 1048576, 1)." MB, database: ".($dbEntry ?? 'not included').", {$files} file(s)).");
        if ($dbNote !== null) {
            $ok ? $this->warn($dbNote) : $this->error($dbNote);
        }
        if (! $ok) {
            $this->error('Older backups were kept (none deleted) because this one has no database copy. Fix the error above, then run php artisan backup:run again.');
        }
        if ($pruned !== []) {
            $this->line('Deleted older backups: '.implode(', ', $pruned));
        }
        $this->line('Download backups off the server regularly. .env and APP_KEY are NOT in the zip: keep APP_KEY separately (e.g. in a password manager).');

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Copy the database to $tmp (the caller deletes it).
     *
     * @param  array<string, mixed>  $config  the default connection's config
     * @return array{0: ?string, 1: ?string, 2: ?string, 3: bool} temp file, zip entry name, note, ok
     */
    public function dumpDatabase(array $config, string $tmp): array
    {
        $driver = (string) ($config['driver'] ?? '');

        if ($driver === 'sqlite') {
            try {
                DB::connection()->statement('VACUUM INTO ?', [$tmp]);
            } catch (\Throwable $e) {
                return [null, null, 'The SQLite database could NOT be copied ('.$e->getMessage().'). Only files are in this backup.', false];
            }

            return [$tmp, 'database/database.sqlite', null, true];
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $finder = new ExecutableFinder;
            $bin = self::canStartProcesses() ? ($finder->find('mysqldump') ?? $finder->find('mariadb-dump')) : null;
            if ($bin === null) {
                return [null, null, 'mysqldump is not available on this server (or PHP may not start programs), so the database is NOT in this backup. Back it up with cPanel → Backup (or phpMyAdmin → Export).', true];
            }

            return $this->mysqldump($bin, $config, $tmp);
        }

        return [null, null, "The {$driver} database is not backed up by this command: use your host's database backup. Only files are in this backup.", true];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{0: ?string, 1: ?string, 2: ?string, 3: bool}
     */
    private function mysqldump(string $bin, array $config, string $tmp): array
    {
        $quote = fn ($v) => '"'.addcslashes((string) $v, "\\\"").'"';
        $options = "[client]\nuser=".$quote($config['username'] ?? '')."\npassword=".$quote($config['password'] ?? '')."\n";
        if (! empty($config['unix_socket'])) {
            $options .= 'socket='.$quote($config['unix_socket'])."\n";
        } else {
            $options .= 'host='.$quote($config['host'] ?? 'localhost')."\nport=".(int) ($config['port'] ?? 3306)."\n";
        }
        $optionFile = "{$tmp}.cnf";
        touch($optionFile);
        chmod($optionFile, 0600);
        file_put_contents($optionFile, $options);

        $out = fopen($tmp, 'wb');
        $stderr = '';
        try {
            $process = new Process([$bin, "--defaults-extra-file={$optionFile}", '--single-transaction', '--quick',
                '--routines', '--no-tablespaces', '--default-character-set=utf8mb4', (string) ($config['database'] ?? '')]);
            $process->setTimeout(900);
            $process->run(function ($type, $buffer) use ($out, &$stderr) {
                if ($type === Process::OUT) {
                    fwrite($out, $buffer);
                } else {
                    $stderr .= $buffer;
                }
            });
        } finally {
            fclose($out);
            @unlink($optionFile);
        }

        if (! $process->isSuccessful()) {
            @unlink($tmp);

            return [null, null, 'mysqldump failed ('.Str::limit(trim($stderr), 300).'). The database is NOT in this backup: use cPanel → Backup.', false];
        }

        return [$tmp, 'database/database.sql', null, true];
    }

    /** proc_open exists and is not in disable_functions (shared hosts often turn it off). */
    public static function canStartProcesses(): bool
    {
        if (self::$processAvailable !== null) {
            return (bool) (self::$processAvailable)();
        }
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return function_exists('proc_open') && ! in_array('proc_open', $disabled, true);
    }

    /** Leftovers of a run that was killed (.db-* dumps, .partial-* zips) older than a day. */
    private function removeStaleTemp(string $dir): void
    {
        foreach (glob("{$dir}/.{db,partial}-*", GLOB_BRACE) ?: [] as $path) {
            if (is_file($path) && filemtime($path) < time() - self::STALE_TEMP_SECONDS) {
                @unlink($path);
            }
        }
    }

    private function addFolder(ZipArchive $zip, string $root, string $entryRoot): int
    {
        $real = $root !== '' ? realpath($root) : false;
        if ($real === false || ! is_dir($real)) {
            return 0;
        }
        $count = 0;
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($real, \FilesystemIterator::SKIP_DOTS));
        foreach ($items as $file) {
            /** @var \SplFileInfo $file */
            if (! $file->isFile() || $file->isLink() || $file->getFilename() === '.gitignore') {
                continue;
            }
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($real) + 1));
            $zip->addFile($file->getPathname(), "{$entryRoot}/{$relative}");
            $count++;
        }

        return $count;
    }

    /** @return list<string> the deleted backup names */
    private function prune(string $dir, string $prefix, int $keep): array
    {
        // Only this app's own backup names: a zip copied in by hand is never deleted.
        $pattern = self::namePattern($prefix);
        $backups = collect(scandir($dir) ?: [])
            ->filter(fn ($f) => preg_match($pattern, $f))
            ->sortByDesc(fn ($f) => preg_match($pattern, $f, $m) ? $m[1].sprintf('-%04d', (int) ($m[2] ?? 1)) : '')
            ->values();

        $deleted = [];
        foreach ($backups->slice($keep) as $old) {
            if (@unlink("{$dir}/{$old}")) {
                $deleted[] = $old;
            }
        }

        return $deleted;
    }

    private function readme(?string $dbEntry, ?string $dbNote): string
    {
        return implode("\n", [
            'Club Portal backup, made '.now()->toDateTimeString().' ('.config('app.timezone').').',
            '',
            'Contents:',
            '- '.($dbEntry ?? 'no database copy').($dbNote ? " ({$dbNote})" : ''),
            '- storage/app/private: uploaded documents and slips (encrypted with APP_KEY)',
            '- storage/app/public: club logos',
            '',
            'NOT included: .env and APP_KEY. Without the same APP_KEY the documents, slips, welfare details and',
            'club gateway keys cannot be read. Restore steps: docs/INSTALL.md, section "Backups".',
            '',
        ]);
    }
}
