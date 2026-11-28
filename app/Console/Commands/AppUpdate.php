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
 * What differs: this process IS the old app, so after the swap the new code cannot be booted here. `migrate`,
 * `optimize` and then `app:update --finish` (the health check and the finish) therefore run in child `php artisan`
 * commands (only when PHP may start programs) that get past the swap guard with a one-time pass, and the website's PHP
 * cache cannot be reset from the command line (the CLI and the web pool have separate caches).
 */
#[Signature('app:update {--check : Only show the installed and the latest version} {--yes : Do not ask before updating} {--database-backed-up : With --yes: I have just taken a full backup of the database} {--manual-action-done : With --yes: the thing the release asks to be done first has been done} {--finish : Finish an update that stopped after the switch, once the manual commands have been run}')]
#[Description('Update Club Portal to the latest release (the same steps as the Updates page)')]
class AppUpdate extends Command
{
    /** Test seam: adjusts the updater's Context (fake download, fake Laravel boot) before it is used. */
    public static ?\Closure $tune = null;

    /** Test seam: runs a child process, fn(list<string> $argv, string $cwd, array $env): array{code: int, output: string}. */
    public static ?\Closure $child = null;

    /** Test seam: whether the web server's PHP checks files for changes (null: read opcache.validate_timestamps). */
    public static ?\Closure $opcacheTimestamps = null;

    /** Test seam: takes the update lock after the switch, fn(string $dir): ?Lock. */
    public static ?\Closure $lockAfterSwitch = null;

    /** Seconds app:update's own `--finish` child waits for the update lock (tests shorten it). */
    public static float $finishLockWait = 15.0;

    /** Exit codes of app:update's own `--finish` child that mean "nothing was tried": never a reason to put files back. */
    public const FINISH_BUSY = 3;

    public const FINISH_REFUSED = 4;

    /** The one line public/update-guard.php prints when it stops an artisan command. */
    private const GUARD_LINE = 'Club Portal is being updated; try again in a few minutes.';

    /** Test seam: whether a person must confirm a database backup (null: ask PreChecks). */
    public static ?\Closure $needsDatabaseTick = null;

    private const BUSY = 'An update is already running (in a browser or another terminal). Try again later.';

    private const MANUAL = 'Run: php artisan migrate --force && php artisan optimize && php artisan app:update --finish';

    /** The one-time pass for the child commands (storage/app/updater/cli-pass; public/update-guard.php reads both). */
    public const PASS_FILE = 'cli-pass';

    public const PASS_ENV = 'CLUBPORTAL_UPDATER_PASS';

    public function handle(): int
    {
        $this->loadUpdater();
        $status = app(UpdateCheck::class)->status(true);

        if ($this->option('finish')) {
            return $this->finishManually();
        }

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
        if (self::$needsDatabaseTick !== null ? (bool) (self::$needsDatabaseTick)() : PreChecks::needsDatabaseBackupTick()) {
            // --yes never assumes a backup was taken: it needs --database-backed-up.
            $dbTicked = $this->option('yes')
                ? (bool) $this->option('database-backed-up')
                : $this->confirm("This server can't copy your club's data by itself. Have you just taken a full backup of the database (cPanel Backup, or phpMyAdmin Export)?", false);
            if (! $dbTicked) {
                $this->line($this->option('yes')
                    ? "The update was not started: this server can't copy your club's data by itself. Take a full backup of the database (cPanel Backup, or phpMyAdmin Export), then run it again with --database-backed-up."
                    : 'The update was not started. Take a backup of the database, then run it again.');

                return self::FAILURE;
            }
        }
        $manualTicked = false;
        if (PreChecks::needsManualActionTick($m)) {
            $manualTicked = $this->option('yes')
                ? (bool) $this->option('manual-action-done')
                : $this->confirm("This update needs one thing done first: {$m->manualAction} Has that been done?", false);
            if (! $manualTicked) {
                $this->line($this->option('yes')
                    ? 'The update was not started: this update needs one thing done first ('.$m->manualAction.'). Do that, then run it again with --manual-action-done.'
                    : 'The update was not started. Do that first, then run it again.');

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

        if (! $canStartProcesses) {
            // The operator's own `php artisan` commands must get past the swap guard (it stops artisan at its first
            // line), so ONLY the guard file is lifted: Laravel's `down` file stays up and members still get the pause
            // page from maintenance.php. /update/ shows that the update is waiting for these commands.
            Maintenance::liftGuard($c->app);
            $this->line('This server does not let PHP start programs, so the update stops here. The new files are in place and the portal is still paused for members.');
            $this->line(self::MANUAL);
            $this->line('The update is not finished until you run these commands. Members see the pause page until then.');
            $this->cacheLine();

            return self::SUCCESS;
        }

        $lock = self::$lockAfterSwitch !== null ? (self::$lockAfterSwitch)($c->files->dir) : Lock::acquire($c->files->dir);
        if ($lock === null) {
            // Someone else holds the lock: nothing is put back from here, and the old files are already switched out.
            // The guard is lifted for the operator's commands, as above.
            Maintenance::liftGuard($c->app);
            $this->line('The new files are in place and the portal is paused for members, but this command could not take the update lock to carry on.');
            $this->line('Once the other update has finished, run this to complete it:');
            $this->line(self::MANUAL);

            return self::FAILURE;
        }

        // The guard stays up (web requests keep the pause page, saved callbacks and idle recovery); the child commands
        // get past its artisan check with a one-time pass that only lives while they run.
        $passFile = $c->files->dir.'/'.self::PASS_FILE;
        $finish = null;
        try {
            $pass = bin2hex(random_bytes(32));
            Files::writeAtomic($passFile, $pass."\n");
            @chmod($passFile, 0600);
            $env = [self::PASS_ENV => $pass];
            $failure = $this->migrateInChild($c, $lock, $env); // releases the lock
            if ($failure === null) {
                $this->line("✓ Updating your club's records to the new version");
                // `health` and `finish` on the new code, through the same code as the manual line.
                $finish = $this->child([PHP_BINARY, base_path('artisan'), 'app:update', '--finish'], base_path(), $env);
            }
        } finally {
            $lock->release();
            @unlink($passFile);
        }
        if ($failure !== null) {
            return $this->failed(Steps::abort('migrate', $c, $failure), $c, true);
        }

        $lines = $this->outputLines((string) $finish['output']);
        foreach ($lines as $line) {
            $this->line($line);
        }
        $c->load();
        if (($c->job['status'] ?? null) === 'finished') {
            return self::SUCCESS;
        }
        clearstatcache();
        if (in_array($finish['code'], [self::FINISH_BUSY, self::FINISH_REFUSED], true) || in_array(self::GUARD_LINE, $lines, true)) {
            // The finish was never tried (the lock was busy, it was refused, or the guard stopped it): put nothing back,
            // and leave it for the operator, as when this command cannot take the lock.
            Maintenance::liftGuard($c->app);
            $this->line('The new files are in place and the portal is paused for members, but the last step could not run from here.');
            $this->line('Once any other update has finished, run this to complete it:');
            $this->line(self::MANUAL);

            return self::FAILURE;
        }
        if (is_file($c->files->guardPath)) {
            // The new version failed its check (or the finish command could not run): put the old files back, as the
            // page does when `health` fails.
            $error = 'app:update --finish failed (exit code '.$finish['code'].').'.($lines === [] ? '' : ' '.end($lines));

            return $this->failed(Steps::abort('health', $c, $error), $c, true);
        }
        $this->line('✗ The portal could not be opened again from here. The new version is in place and members see the pause page.');
        $this->line('Run: php artisan app:update --finish');
        $this->cacheLine();

        return self::FAILURE;
    }

    /**
     * migrate, optimize:clear and optimize in a child process on the new code, with the journal kept as the page keeps
     * it. The caller holds the lock; it is released here once `migrate` is recorded, because the `--finish` child takes
     * it itself. Returns the failure text, or null when all three worked.
     *
     * @param  array<string, string>  $env
     */
    private function migrateInChild(Context $c, Lock $lock, array $env): ?string
    {
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
                $r = $this->child([PHP_BINARY, base_path('artisan'), ...$args], base_path(), $env);
                $lines = $this->outputLines($r['output']);
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
            $j->finish('migrate'); // also restarts the 10-minute clock for the finish
            $c->saveJob();

            return null;
        } catch (Throwable $e) {
            return $e->getMessage();
        } finally {
            $lock->release();
        }
    }

    /** @return list<string> the non-empty lines of a child's output */
    private function outputLines(string $output): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", $output)), static fn ($l) => $l !== ''));
    }

    /**
     * @param  list<string>  $argv
     * @param  array<string, string>  $env  added to this process's environment
     * @return array{code: int, output: string}
     */
    private function child(array $argv, string $cwd, array $env = []): array
    {
        if (self::$child !== null) {
            return (self::$child)($argv, $cwd, $env);
        }
        $process = @proc_open($argv, [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $cwd, $env === [] ? null : $env + getenv());
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

    /**
     * `--finish`: after the manual commands (migrate, optimize) the operator ran, or as the last child command of
     * app:update itself, completes what the page's last steps would have: the health check on the new code, the
     * remaining steps recorded as done, then Steps::completeFinishing writes the outcome, ends the job, lifts the pause
     * and moves the new update-guard.php and public/update/ in.
     *
     * This runs the NEW release's code against the installed (old) release's public/update/lib (moved in only by the
     * finish): keep the lib API it calls (Context, Lock, Journal, Steps::isThisAttempt/completeFinishing,
     * Maintenance::liftGuard, LaravelApp::forKernel) stable for at least one release.
     */
    private function finishManually(): int
    {
        $c = new Context(base_path());
        $laravel = LaravelApp::forKernel(app(Kernel::class)); // this process runs the new code
        $c->boot = static fn (string $app): BootedApp => $laravel;
        if (self::$tune !== null) {
            (self::$tune)($c);
        }
        // app:update's own child (holding the one-time pass). Its parent has just released the lock, and a web request
        // may hold it for a moment, so the child waits a little; its exit codes tell "busy" and "refused" apart from a
        // real failure, so the parent never puts the old files back for those.
        $child = $this->hasPass($c);
        $lock = Lock::acquire($c->files->dir);
        $deadline = microtime(true) + self::$finishLockWait;
        while ($lock === null && $child && microtime(true) < $deadline) {
            usleep(200000);
            $lock = Lock::acquire($c->files->dir);
        }
        if ($lock === null) {
            $this->line(self::BUSY);

            return $child ? self::FINISH_BUSY : self::FAILURE;
        }
        try {
            $c->load();
            $j = $c->journal();
            $steps = $j->steps();
            if (! in_array($c->job['status'] ?? null, ['running'], true) || ! Steps::isThisAttempt($j, $c->job)
                || ($steps['swap']['state'] ?? null) !== 'done' || ($steps['finish']['state'] ?? null) === 'done') {
                $this->line('There is no update waiting to be finished: it has not reached the point of switching versions, or it is already done.');

                return $child ? self::FINISH_REFUSED : self::FAILURE;
            }
            // With the guard up only app:update's own child may finish; it lifts the guard.
            if ($j->isRecovering() || (is_file($c->files->guardPath) && ! $child)) {
                $this->line('The update is still switching versions or is being undone. Try again in a few minutes.');

                return $child ? self::FINISH_REFUSED : self::FAILURE;
            }
            $pending = $this->pendingMigrations();
            if ($pending !== []) {
                $this->line(count($pending).' update(s) to your club\'s records have not been run yet. Run: php artisan migrate --force');

                return self::FAILURE;
            }
            $problem = ($c->boot)($c->app)->health($c->to());
            if ($problem !== null) {
                $this->line('The new version is not working yet, so the update was not finished: '.$problem);
                if (! $child) { // app:update's own child: the command that started it puts the old version back
                    $this->line('The new files are in place and members see the pause page. Fix that, then run: php artisan app:update --finish');
                }

                return self::FAILURE;
            }
            foreach (['opcache', 'migrate', 'health'] as $step) {
                $note = $step === 'health' ? 'checked from the command line: the new version is running and reaches the database' : 'finished from the command line';
                $state = $steps[$step]['state'] ?? null;
                if ($state === 'done') {
                    continue;
                }
                if ($state !== 'running') {
                    $j->begin($step);
                }
                $j->log($step, ucfirst($note).'.');
                $j->finish($step, $note);
            }
            if (! isset($j->steps()['finish'])) {
                $j->begin('finish');
            }
            if ($child) {
                // Laravel's `down` file stays until the finish lifts it, so members keep the pause page meanwhile.
                Maintenance::liftGuard($c->app);
            }
        } catch (Throwable $e) {
            $this->line('The update could not be finished: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            $lock->release();
        }
        if (! Steps::completeFinishing($c)) {
            $this->line('The portal could not be opened again from here. Open the Updates page as a super admin.');

            return self::FAILURE;
        }
        $this->line('✓ '.Steps::screenName('finish', $c->to()));
        $this->line('Club Portal is now v'.ltrim($c->to(), 'vV').'.');
        $this->cacheLine();

        return self::SUCCESS;
    }

    /** True when this process is app:update's own child: its pass variable matches the one-time pass file. */
    private function hasPass(Context $c): bool
    {
        $given = getenv(self::PASS_ENV);
        $file = $c->files->dir.'/'.self::PASS_FILE;
        $secret = is_file($file) ? trim((string) @file_get_contents($file)) : '';

        return is_string($given) && strlen($secret) >= 32 && hash_equals($secret, $given);
    }

    /** @return list<string> migration names that have not run, from the same files `migrate` reads */
    private function pendingMigrations(): array
    {
        $migrator = app('migrator');
        $repository = $migrator->getRepository();
        if (! $repository->repositoryExists()) {
            return ['all'];
        }
        $files = $migrator->getMigrationFiles(array_merge($migrator->paths(), [database_path('migrations')]));

        return array_values(array_diff(array_keys($files), $repository->getRan()));
    }

    /** The updater library is plain PHP outside Composer's autoloader. */
    private function loadUpdater(): void
    {
        if (! class_exists(Steps::class, false)) {
            require_once base_path('public/update/lib/load.php');
        }
    }
}
