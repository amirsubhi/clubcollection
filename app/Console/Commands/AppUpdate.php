<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AuditService;
use App\Support\Updates\Manifest;
use App\Support\Updates\PreChecks;
use App\Support\Updates\UpdateCheck;
use App\Support\Updates\UpdateJob;
use App\Support\Updates\UpdaterFiles;
use ClubPortalUpdater\BootedApp;
use ClubPortalUpdater\Context;
use ClubPortalUpdater\Files;
use ClubPortalUpdater\Journal;
use ClubPortalUpdater\LaravelApp;
use ClubPortalUpdater\Lock;
use ClubPortalUpdater\Maintenance;
use ClubPortalUpdater\Steps;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * The command-line way to the same update engine the Updates page drives (spec section 7): the same job, journal, lock
 * and Steps::run for `prechecks` ... `swap`, so a browser and a terminal can never run an update at once and the
 * outcome, history and report are the same files.
 *
 * What differs: this process IS the old app, so after the swap the new code cannot be booted here. `migrate` and
 * `optimize` therefore run in a child `php artisan` (only when PHP may start programs), and the website's PHP cache
 * cannot be reset from the command line (the CLI and the web pool have separate caches).
 */
#[Signature('app:update {--check : Only show the installed and the latest version} {--yes : Do not ask before updating}')]
#[Description('Update Club Portal to the latest release (the same steps as the Updates page)')]
class AppUpdate extends Command
{
    /** Test seam: adjusts the updater's Context (fake download, fake Laravel boot) before it is used. */
    public static ?\Closure $tune = null;

    /** Test seam: runs a child process, fn(list<string> $argv, string $cwd): array{code: int, output: string}. */
    public static ?\Closure $child = null;

    /** Test seam: whether the web server's PHP checks files for changes (null: read opcache.validate_timestamps). */
    public static ?\Closure $opcacheTimestamps = null;

    private const BUSY = 'An update is already running (in a browser or another terminal). Try again later.';

    private const MANUAL = 'Run: php artisan migrate --force && php artisan optimize && php artisan up';

    public function handle(): int
    {
        $this->loadUpdater();
        $status = app(UpdateCheck::class)->status(true);

        if ($this->option('check')) {
            $this->line('Installed: '.$status->installed);
            $this->line('Latest: '.($status->latest?->version ?? 'unknown'));
            $this->line('Update available: '.($status->available ? 'yes' : 'no'));
            if ($status->error !== null) {
                $this->line($status->error);

                return self::FAILURE;
            }

            return self::SUCCESS;
        }

        if ($status->error !== null) {
            $this->line($status->error);

            return self::FAILURE;
        }
        $m = $status->latest;
        if (! $status->available || $m === null) {
            $this->line("Club Portal is up to date (v{$status->installed}). There is nothing to update.");

            return self::SUCCESS;
        }

        // A quick look first, so a person is not asked questions for an update that cannot start.
        $lock = $this->takeLock();
        if ($lock === null) {
            return self::FAILURE;
        }
        $lock->release();

        $admin = User::where('role', 'super_admin')->orderBy('id')->first();
        if ($admin === null) {
            $this->line('There is no super admin account to record this update under, so it was not started.');

            return self::FAILURE;
        }

        $this->line("Club Portal v{$status->installed} -> v{$m->version}");
        foreach ($m->whatsNew as $line) {
            $this->line('  - '.$line);
        }
        $dbTicked = false;
        if (PreChecks::needsDatabaseBackupTick()) {
            $dbTicked = $this->confirm("This server can't copy your club's data by itself. Have you just taken a full backup of the database (cPanel Backup, or phpMyAdmin Export)?", false);
            if (! $dbTicked) {
                $this->line('The update was not started. Take a backup of the database, then run it again.');

                return self::FAILURE;
            }
        }
        $manualTicked = false;
        if (PreChecks::needsManualActionTick($m)) {
            $manualTicked = $this->confirm("This update needs one thing done first: {$m->manualAction} Has that been done?", false);
            if (! $manualTicked) {
                $this->line('The update was not started. Do that first, then run it again.');

                return self::FAILURE;
            }
        }
        if (! $this->option('yes') && ! $this->confirm("Update Club Portal from v{$status->installed} to v{$m->version}? Members will see a pause page while it runs.", false)) {
            $this->line('The update was not started. Nothing was changed.');

            return self::FAILURE;
        }

        if (! $this->createJob($m, $admin, $dbTicked, $manualTicked, $status->installed)) {
            return self::FAILURE;
        }

        try {
            return $this->runUpdate();
        } catch (Throwable $e) {
            $this->line('Something went wrong on your site: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    /** The lock, or null with the plain refusal printed (lock held, a job running, or the button switched off). */
    private function takeLock(): ?Lock
    {
        $lock = Lock::acquire(UpdaterFiles::dir());
        if ($lock === null || UpdateJob::isRunning()) {
            $lock?->release();
            $this->line(self::BUSY);

            return null;
        }
        if (is_file(UpdaterFiles::blockedPath())) {
            $lock->release();
            $this->line('The update is switched off until a failed update has been looked at (a super admin switches it back on on the Updates page).');

            return null;
        }

        return $lock;
    }

    private function createJob(Manifest $m, User $admin, bool $dbTicked, bool $manualTicked, string $installed): bool
    {
        $lock = $this->takeLock();
        if ($lock === null) {
            return false;
        }
        try {
            // No token is kept and no cookie is set: nobody drives this job from a browser.
            UpdateJob::create($m, $admin, $dbTicked, $manualTicked);
        } catch (Throwable) {
            $this->line("The update could not be started: the portal's update folder cannot be written to.");

            return false;
        } finally {
            $lock->release();
        }
        Auth::setUser($admin);
        AuditService::log('portal.update_started', "Update to v{$m->version} started from the command line.", null, null, [],
            ['from' => $installed, 'to' => $m->version]);
        $this->line("The update is recorded under {$admin->name}, the first super admin, in the audit log.");

        return true;
    }

    private function runUpdate(): int
    {
        // Decided now, while this process still runs on the old files: nothing from app/ is loaded after the swap.
        $canStartProcesses = BackupRun::canStartProcesses();

        $laravel = LaravelApp::forKernel(app(Kernel::class)); // we are inside the booted (old) app
        $c = new Context(base_path());
        $c->boot = static fn (string $app): BootedApp => $laravel;
        $c->opcacheReset = static fn (): bool => false; // the website's cache is another process's
        $c->opcacheWait = static fn (): ?int => null;
        if (self::$tune !== null) {
            (self::$tune)($c);
        }
        $c->load();

        // prechecks ... swap, exactly as the page runs them. The lock is taken by Steps::run itself, one step at a time.
        $next = 'prechecks';
        for ($i = 0; $i < 100000 && $next !== 'opcache'; $i++) {
            $r = Steps::run($next, $c);
            if (! in_array($r['status'], ['done', 'more'], true)) {
                return $this->failed($r, $c);
            }
            if ($r['status'] === 'done') {
                $this->line('✓ '.Steps::screenName((string) $r['step'], $c->to()));
            }
            $next = $r['next'];
            if ($next === null) {
                break;
            }
        }
        if ($next !== 'opcache') {
            $this->line('✗ The update did not get as far as switching versions.');

            return self::FAILURE;
        }

        // The swap guard makes `artisan` print one line and stop at its first line (Task 9), so a child `php artisan
        // migrate` would be refused by it. Lift ONLY the guard file here: Laravel's own `down` file stays up, so members
        // still get the pause page from maintenance.php while the new code migrates. The manual line below needs it
        // lifted for the same reason.
        Maintenance::liftGuard($c->app);

        if (! $canStartProcesses) {
            $this->line('This server does not let PHP start programs, so the update stops here. The new files are in place and the portal is still paused for members.');
            $this->line(self::MANUAL);
            $this->cacheLine();

            return self::SUCCESS;
        }

        $failure = $this->migrateInChild($c);
        if ($failure !== null) {
            // Putting the old files back needs the guard again, like any other switch of files.
            Files::writeAtomic($c->files->guardPath, time()."\n");

            return $this->failed(Steps::abort('migrate', $c, $failure), $c, true);
        }
        $this->line("✓ Updating your club's records to the new version");

        // `finish`: pause off, outcome `finished` with what's new, then the new update-guard.php and public/update/ move in.
        if (! Steps::completeFinishing($c)) {
            $this->line('✗ The portal could not be opened again from here. The new version is in place: run php artisan up, or open the Updates page.');
            $this->cacheLine();

            return self::FAILURE;
        }
        $this->line('✓ '.Steps::screenName('finish', $c->to()));
        $this->line('Club Portal is now v'.ltrim($c->to(), 'vV').'.');
        $this->cacheLine();

        return self::SUCCESS;
    }

    /**
     * migrate, optimize:clear and optimize in a child process on the new code, with the journal kept as the page keeps
     * it. Returns the failure text, or null when all three worked (then `health` and `finish` are begun for the finish).
     */
    private function migrateInChild(Context $c): ?string
    {
        $lock = Lock::acquire($c->files->dir);
        if ($lock === null) {
            return self::BUSY;
        }
        try {
            $j = Journal::open($c->files->dir, $c->clock);
            $j->begin('opcache');
            $j->log('opcache', "Command line: the website's PHP cache is not cleared from here.");
            $j->finish('opcache');
            $j->begin('migrate');
            $j->set('migrate_started', $c->now());
            $j->set('migrated', false);
            $c->load();
            $c->saveJob();

            foreach ([['migrate', '--force'], ['optimize:clear'], ['optimize']] as $args) {
                $r = $this->child([PHP_BINARY, base_path('artisan'), ...$args], base_path());
                $lines = array_values(array_filter(array_map('trim', explode("\n", $r['output'])), static fn ($l) => $l !== ''));
                foreach (array_slice($lines, -10) as $line) {
                    $j->log('migrate', $line);
                }
                if ($r['code'] !== 0) {
                    return implode(' ', $args).' failed (exit code '.$r['code'].').'.($lines === [] ? '' : ' '.end($lines));
                }
                if ($args[0] === 'migrate') {
                    $j->set('migrated', true);
                }
            }
            $j->finish('migrate');
            $j->begin('health');
            $j->log('health', 'Not checked from the command line: the website cannot be asked for its pages from here.');
            $j->finish('health');
            $j->begin('finish');
            $c->saveJob();

            return null;
        } catch (Throwable $e) {
            return $e->getMessage();
        } finally {
            $lock->release();
        }
    }

    /** @param list<string> $argv @return array{code: int, output: string} */
    private function child(array $argv, string $cwd): array
    {
        if (self::$child !== null) {
            return (self::$child)($argv, $cwd);
        }
        $process = @proc_open($argv, [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $cwd);
        if (! is_resource($process)) {
            return ['code' => 1, 'output' => 'The command could not be started.'];
        }
        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        return ['code' => proc_close($process), 'output' => $output];
    }

    /**
     * A step answered `failed` or `busy`: its plain words and where the report is.
     *
     * @param  array<string, mixed>  $r
     */
    private function failed(array $r, Context $c, bool $afterSwitch = false): int
    {
        if (($r['status'] ?? '') === 'busy') {
            $this->line(self::BUSY);

            return self::FAILURE;
        }
        $this->line('✗ '.Steps::screenName((string) ($r['step'] ?? 'prechecks'), $c->to()));
        $this->line((string) ($r['message'] ?? 'Something went wrong on your site.'));
        if (is_string($r['detail'] ?? null) && $r['detail'] !== '') {
            $this->line('  '.$r['detail']);
        }
        $history = glob($c->files->historyDir.'/*.json') ?: [];
        sort($history);
        $report = $history !== [] ? end($history) : $c->files->journalPath;
        $this->line('The full report is in '.$report.($history !== [] ? ' (the "report" field; the Updates page shows it too).' : '.'));
        if ($afterSwitch) {
            $this->cacheLine();
        }

        return self::FAILURE;
    }

    private function cacheLine(): void
    {
        $checks = self::$opcacheTimestamps !== null
            ? (bool) (self::$opcacheTimestamps)()
            : (($v = ini_get('opcache.validate_timestamps')) === false || filter_var($v, FILTER_VALIDATE_BOOLEAN));
        $this->line($checks
            ? "The website's PHP cache is not cleared from the command line; it refreshes within a few seconds."
            : "The website's PHP cache is not cleared from the command line, and this server does not look for changed files: restart PHP (or use the Updates page) so the website runs the new version.");
    }

    /** The updater library is plain PHP outside Composer's autoloader. */
    private function loadUpdater(): void
    {
        if (! class_exists(Steps::class, false)) {
            require_once base_path('public/update/lib/load.php');
        }
    }
}
