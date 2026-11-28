<?php

declare(strict_types=1);

namespace ClubPortalUpdater;

/**
 * The update's steps (spec section 5, steps 1 to 10, with the Stage 2 rules), one request each, or one slice of the
 * download or the unpacking. The page asks for the step the last answer named; a step asked for out of order runs
 * nothing and names the right one.
 *
 * Every answer is array{step, status: 'done'|'more'|'failed'|'busy', next: ?string, message: string, wait?: int,
 * detail?: string, swap_started: bool}. `message` is always plain words for the screen; the technical error goes to
 * `detail` (Show details) and the journal's log (the report).
 *
 * Failures: before the switch (`swap` not begun) the job and outcome are `failed` and the pause is lifted; from the
 * switch on the old files are put back and the outcome is `rolled_back`, or `blocked` when the database changes began
 * and did not finish. Only the installed release's updater runs these steps (the new public/update/ is moved in by
 * `finish`), so the page-to-step protocol never has to match another version's. Two seams do cross versions:
 * `app:update --finish` runs the NEW release's command against THIS lib (Context, Lock, Journal,
 * Steps::isThisAttempt/completeFinishing, Maintenance::liftGuard, LaravelApp::forKernel), and this lib's
 * LaravelApp::health and `migrate` call the new app's UpdaterHost and migrations. Keep those APIs stable for at least
 * one release.
 */
final class Steps
{
    /** What the super admin sees for each step (`swap` and `opcache` are one line on the screen). */
    public const SCREEN = [
        'prechecks' => 'Checking your site is ready',
        'download' => 'Downloading Club Portal v%s',
        'backup' => "Saving a copy of your club's data",
        'unpack' => 'Checking the new files',
        'maintenance' => 'Pausing the portal for members',
        'swap' => 'Switching to the new version',
        'opcache' => 'Switching to the new version',
        'migrate' => "Updating your club's records to the new version",
        'health' => 'Checking everything works',
        'finish' => 'Opening the portal again',
    ];

    /** The only place a release zip may come from (the same rule as App\Support\Updates\Manifest::ZIP_URL_PREFIX). */
    public const ZIP_URL_PREFIX = 'https://github.com/amirsubhi/clubcollection/releases/download/';

    /** Download slices in a row that add nothing before the download counts as failed. */
    public const MAX_STALLS = 5;

    /** What a step failure means, in plain words (shown as "What happened"). */
    private const FAILED = [
        'prechecks' => "Your site wasn't ready to update.",
        'download' => 'The new version could not be downloaded.',
        'backup' => "A copy of your club's data could not be saved, so the update did not go ahead.",
        'unpack' => 'The new files did not check out, so the update did not go ahead.',
        'maintenance' => 'The portal could not be paused for members, so the update did not go ahead.',
        'swap' => 'The new version could not be put in place on this host.',
        'opcache' => 'The new version could not be put in place on this host.',
        'migrate' => "The new version could not update your club's records on this host.",
        'health' => "The new version showed an error on this host's settings.",
        'finish' => 'The portal could not be opened again.',
    ];

    private const LOST = 'The connection to your site was lost while the update was running.';

    private const INTERRUPTED = 'The update was interrupted while it was switching versions, so the old version was put back.';

    /** @return array<string, mixed> */
    public static function run(string $name, Context $c): array
    {
        if (! in_array($name, Journal::STEPS, true) && $name !== 'rollback') {
            return self::reply($name, 'failed', null, 'The page asked for something the updater does not know.');
        }
        $lock = Lock::acquire($c->files->dir);
        if ($lock === null) {
            return self::reply($name, 'busy', null, 'This update is running in another tab.');
        }
        if (function_exists('ignore_user_abort')) {
            @ignore_user_abort(true);
        }
        if (function_exists('set_time_limit')) {
            @set_time_limit(120);
        }
        $level = ob_get_level();
        ob_start(); // a booted Laravel may print; the answer is JSON only
        try {
            $c->load();

            return self::dispatch($name, $c);
        } catch (\Throwable $e) {
            return self::unexpected($c, $name, $e);
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            $lock->release();
        }
    }

    /**
     * Read-only state for a tab that only watches (no lock taken for long, nothing written).
     *
     * @return array<string, mixed>
     */
    public static function status(Context $c): array
    {
        $job = Auth::job($c->files->dir) ?? [];
        $states = [];
        $next = 'prechecks';
        $idle = 0;
        $swapStarted = false;
        try {
            $j = Journal::open($c->files->dir, $c->clock);
            if (self::isThisAttempt($j, $job)) {
                foreach ($j->steps() as $step => $s) {
                    $states[$step] = $s['state'];
                }
                $next = $j->firstUnfinished();
                $swapStarted = $j->isSwapStarted();
            }
            $idle = $j->idleSeconds();
        } catch (UpdaterError) {
        }
        $locked = false;
        $lock = Lock::acquire($c->files->dir);
        if ($lock === null) {
            $locked = true;
        } else {
            $lock->release();
        }

        return ['step' => 'status', 'status' => 'watch', 'next' => $next, 'steps' => $states, 'idle' => $idle,
            'locked' => $locked, 'swap_started' => $swapStarted, 'message' => ''];
    }

    /**
     * Ends a job abandoned before the pause (no guard file, nothing switched): after 10 minutes without a step it is
     * `failed` with nothing changed. The next start's `prechecks` removes what it downloaded. Returns true when it did.
     */
    public static function expireAbandoned(Context $c): bool
    {
        $lock = Lock::acquire($c->files->dir);
        if ($lock === null) {
            return false;
        }
        try {
            $c->load();
            $j = $c->journal();
            if (($c->job['status'] ?? null) !== 'running' || is_file($c->files->guardPath) || $j->isRecovering()) {
                return false;
            }
            $mine = self::isThisAttempt($j, $c->job);
            if ($mine && ($j->isSwapStarted() || isset($j->steps()['finish']))) {
                return false;
            }
            $last = $c->job['last_step_at'] ?? null; // moved with the journal's clock by every step and slice
            if (! is_int($last) || $c->now() - $last < Recovery::IDLE_AFTER) {
                return false;
            }
            if (! $mine) {
                $j = $c->freshJournal(); // the outcome must not carry an earlier attempt's log
            }
            $step = $mine ? $j->firstUnfinished() : 'prechecks';
            self::writeOutcome($c, 'failed', (string) $step, 'The update page was closed before the portal was paused, so the update stopped. Nothing was changed.');
            $c->saveJob('failed');
            Maintenance::off($c->app); // nothing is up when the guard file is not, but a stray down file must not stay

            return true;
        } catch (\Throwable) {
            return false;
        } finally {
            $lock->release();
        }
    }

    /** What the result says when a job is ended after someone put the old version back by hand. */
    public const ENDED_BY_HAND = 'The update was ended by hand after it could not be undone by itself.';

    /**
     * Ends a job someone finished undoing by hand (the "could not be undone" screen's steps): the job is still
     * `running`, but the guard file and Laravel's `down` file are gone, the switch had started (or the undo began),
     * `finish` never began, and nothing has moved for 10 minutes. The job and outcome become `failed`, so the Updates
     * page and app:update are free again. Needs no token: it only records what a person already did. Returns true when
     * it did.
     */
    public static function endHandUndone(Context $c): bool
    {
        $lock = Lock::acquire($c->files->dir);
        if ($lock === null) {
            return false;
        }
        try {
            $c->load();
            $j = $c->journal();
            clearstatcache();
            if (($c->job['status'] ?? null) !== 'running' || ! self::isThisAttempt($j, $c->job)
                || is_file($c->files->guardPath) || is_file(dirname($c->files->guardPath).'/down')
                || ! ($j->isSwapStarted() || $j->isRecovering()) || isset($j->steps()['finish'])
                || $j->idleSeconds() < Recovery::IDLE_AFTER) {
                return false;
            }
            self::writeOutcome($c, 'failed', (string) ($j->firstUnfinished() ?? 'finish'), self::ENDED_BY_HAND);
            $c->saveJob('failed');
            Maintenance::off($c->app); // a stray maintenance.php must not stay

            return true;
        } catch (\Throwable) {
            return false;
        } finally {
            $lock->release();
        }
    }

    /**
     * Completes a `finish` that was interrupted (no guard file, `finish` begun or the job already `finished`, and the
     * new updater, zip or staging folder still waiting). Needs no token: it only does what `finish` had started.
     */
    public static function completeFinishing(Context $c): bool
    {
        $lock = Lock::acquire($c->files->dir);
        if ($lock === null) {
            return false;
        }
        try {
            $c->load();
            $j = $c->journal();
            if (is_file($c->files->guardPath) || ! self::isThisAttempt($j, $c->job) || $j->isRecovering()) {
                return false;
            }
            $finish = $j->steps()['finish']['state'] ?? null;
            if ($finish === null || $finish === 'done') {
                return false;
            }
            self::completeFinish($c, $j);

            return true;
        } catch (\Throwable) {
            return false;
        } finally {
            $lock->release();
        }
    }

    /**
     * A step that ran outside Steps::run (the command line's migrate in a child process) failed: the same handling as a
     * failure inside the step. From the switch on that puts the old files back and writes `rolled_back`, or `blocked`
     * when the database changes began and did not finish. Takes the lock; the caller must not hold it.
     *
     * @return array<string, mixed>
     */
    public static function abort(string $step, Context $c, string $error): array
    {
        $lock = Lock::acquire($c->files->dir);
        if ($lock === null) {
            return self::reply($step, 'busy', null, 'This update is running in another tab.');
        }
        try {
            $c->load();

            return self::failStep($c, $c->journal(), $step, $error);
        } catch (\Throwable $e) {
            return self::reply($step, 'failed', null, 'Something went wrong on your site.') + ['detail' => $e->getMessage()];
        } finally {
            $lock->release();
        }
    }

    /** The screen line of a step. */
    public static function screenName(string $step, string $to): string
    {
        return sprintf(self::SCREEN[$step] ?? $step, ltrim($to, 'v'));
    }

    /** The job's identity in the journal (meta `job`), so a journal from an earlier attempt is told apart. */
    public static function jobId(array $job): string
    {
        return (is_int($job['created_at'] ?? null) ? (string) $job['created_at'] : '?').'-'.(is_string($job['token_hash'] ?? null) ? substr($job['token_hash'], 0, 16) : '?');
    }

    public static function isThisAttempt(Journal $j, array $job): bool
    {
        return $job !== [] && $j->get('job') === self::jobId($job);
    }

    // ---------------------------------------------------------------- dispatch

    /** @return array<string, mixed> */
    private static function dispatch(string $name, Context $c): array
    {
        $j = $c->journal();
        if (! in_array($c->job['status'] ?? null, ['ready', 'running'], true)) {
            return self::reply($name, 'failed', null, 'This update has already ended.');
        }
        if (! self::isThisAttempt($j, $c->job)) {
            if ($name !== 'prechecks') {
                return self::reply($name, 'more', 'prechecks', 'Starting from the beginning.');
            }
            // The journal of an earlier attempt (or one left `recovering`): this run starts on a clean one.
            $j = $c->freshJournal();
            $j->set('job', self::jobId($c->job));
        }
        if ($j->isRecovering()) {
            return self::reply($name, 'failed', null, 'The update stopped for too long and was undone.', $j);
        }
        $swap = $j->get('swap');
        if (is_array($swap) && ($swap['state'] ?? '') === 'rolling_back') {
            // Putting the old files back started and stopped part-way: only finishing that is allowed now.
            return self::putBack($c, $j, 'swap', self::INTERRUPTED, 'Putting the old files back had started and was interrupted.');
        }
        if ($name === 'rollback') {
            return self::rollbackRequested($c, $j);
        }
        $expected = $j->firstUnfinished();
        if ($expected === null) {
            return self::reply('finish', 'done', null, 'Done.', $j);
        }
        if ($name !== $expected) {
            return self::reply($name, 'more', $expected, 'Carrying on from where the update got to.', $j);
        }

        try {
            $result = match ($name) {
                'prechecks' => self::prechecks($c, $j),
                'download' => self::download($c, $j),
                'backup' => self::backup($c, $j),
                'unpack' => self::unpack($c, $j),
                'maintenance' => self::maintenance($c, $j),
                'swap' => self::swap($c, $j),
                'opcache' => self::opcache($c, $j),
                'migrate' => self::migrate($c, $j),
                'health' => self::health($c, $j),
                'finish' => self::finish($c, $j),
            };
        } catch (\Throwable $e) {
            return self::failStep($c, $j, $name, $e->getMessage());
        }
        self::resendBypass($c, $j);

        return $result;
    }

    // ---------------------------------------------------------------- the steps

    /** @return array<string, mixed> */
    private static function prechecks(Context $c, Journal $j): array
    {
        self::beginOnce($j, 'prechecks');
        $c->saveJob('running');
        if (is_file($c->files->guardPath)) {
            throw new UpdaterError('The portal is still paused by an earlier update (storage/framework/updating exists).');
        }
        if (is_file($c->files->blockedPath)) {
            throw new UpdaterError('A failed update has not been looked at yet (storage/app/updater/blocked exists).');
        }
        $installed = self::releaseFiles($c->app);
        if ($installed === []) {
            throw new UpdaterError('The installed release-files.json is missing, unreadable or empty.');
        }
        self::sweepEarlierAttempts($c);

        $failed = ($c->boot)($c->app)->preChecks($c->job);
        if ($failed !== []) {
            foreach ($failed as $line) {
                $j->log('prechecks', 'Check failed: '.$line);
            }

            return self::failStep($c, $j, 'prechecks', 'Pre-checks failed: '.implode(' | ', $failed), $failed);
        }
        $j->finish('prechecks');
        $c->saveJob();

        return self::reply('prechecks', 'done', 'download', 'Your site is ready.', $j);
    }

    /** @return array<string, mixed> */
    private static function download(Context $c, Journal $j): array
    {
        self::beginOnce($j, 'download');
        $zip = $c->job['manifest']['zip'] ?? null;
        $url = is_array($zip) && is_string($zip['url'] ?? null) ? $zip['url'] : '';
        if (! str_starts_with($url, self::ZIP_URL_PREFIX)) {
            throw new UpdaterError('The download address is not on the Club Portal releases page.');
        }
        $size = is_int($zip['size'] ?? null) ? $zip['size'] : -1;
        $sha = is_string($zip['sha256'] ?? null) ? $zip['sha256'] : '';
        $dest = self::zipPath($c);

        clearstatcache(true, $dest);
        $before = is_file($dest) ? (int) filesize($dest) : 0;
        $r = ($c->fetch)($url, $dest, $c->sliceSeconds);
        if (! $r['done']) {
            $stalls = $r['bytes'] > $before ? 0 : (int) $j->get('download_stalls', 0) + 1;
            $j->set('download_stalls', $stalls);
            if ($stalls >= self::MAX_STALLS) {
                throw new UpdaterError('The download made no progress in '.self::MAX_STALLS.' attempts in a row.');
            }
            self::progress($c, $j);

            return self::reply('download', 'more', 'download', self::megabytes($r['bytes']).' of '.self::megabytes(max(0, $size)).' downloaded.', $j);
        }
        if (! Download::verify($dest, $size, $sha)) {
            @unlink($dest);
            throw new UpdaterError('The downloaded file does not match the size and SHA-256 checksum in the release manifest.');
        }
        $j->finish('download', ['bytes' => $size]);
        $c->saveJob();

        return self::reply('download', 'done', 'backup', 'Downloaded.', $j);
    }

    /** @return array<string, mixed> */
    private static function backup(Context $c, Journal $j): array
    {
        self::beginOnce($j, 'backup');
        $app = ($c->boot)($c->app);
        if (($c->job['db_backup_ticked'] ?? false) === true) {
            $detail = ['database' => false, 'file' => null, 'at' => $c->now()];
            $j->log('backup', 'Database not in backup: the person starting the update confirmed a cPanel backup.');
        } else {
            $r = $app->call('backup:run', ['--database-only' => true]);
            foreach (array_slice(self::lines($r['output']), -10) as $line) {
                $j->log('backup', $line);
            }
            if ($r['code'] !== 0) {
                throw new UpdaterError('backup:run --database-only failed (exit code '.$r['code'].').');
            }
            if (preg_match('~Backup written: storage/app/backups/(\S+) \(.*database: ([^,]+),~', $r['output'], $m) !== 1) {
                throw new UpdaterError('backup:run did not say which backup it wrote.');
            }
            if ($m[2] === 'not included') {
                throw new UpdaterError('backup:run wrote a backup without the database.');
            }
            $detail = ['database' => true, 'file' => $m[1], 'at' => $c->now()];
        }
        $locale = is_string($c->job['locale'] ?? null) ? $c->job['locale'] : 'ms';
        $portal = is_string($c->job['portal_name'] ?? null) ? $c->job['portal_name'] : '';
        Files::writeAtomic($c->files->pausePath, $app->pausePage($locale, $portal));

        $j->set('backup', $detail);
        $j->finish('backup', $detail);
        $c->saveJob();

        return self::reply('backup', 'done', 'unpack', 'A copy of your data is saved.', $j);
    }

    /** @return array<string, mixed> */
    private static function unpack(Context $c, Journal $j): array
    {
        if (self::beginOnce($j, 'unpack')) {
            $j->set('unpack_next', 0);
        }
        $staging = Unpack::stagingDir($c->app, $c->to());
        $j->set('staging', $staging);
        $zip = self::zipPath($c);
        $next = (int) $j->get('unpack_next', 0);
        $deadline = microtime(true) + $c->sliceSeconds;
        do {
            $next = Unpack::slice($zip, $staging, $next, $c->unpackBatch, $c->app);
            $j->set('unpack_next', $next);
        } while ($next !== -1 && microtime(true) < $deadline);
        if ($next !== -1) {
            self::progress($c, $j);

            return self::reply('unpack', 'more', 'unpack', 'Checking the new files…', $j);
        }
        $bad = Unpack::verify($staging);
        if ($bad !== []) {
            throw new UpdaterError(count($bad).' new file(s) do not match release-files.json: '.implode(', ', array_slice($bad, 0, 10)));
        }
        $j->finish('unpack');
        $c->saveJob();

        return self::reply('unpack', 'done', 'maintenance', 'The new files are ready.', $j);
    }

    /** @return array<string, mixed> */
    private static function maintenance(Context $c, Journal $j): array
    {
        self::beginOnce($j, 'maintenance');
        $secret = self::downSecret($c) ?? Maintenance::newSecret(); // a repeat keeps the browser's bypass valid
        $pause = is_file($c->files->pausePath) ? @file_get_contents($c->files->pausePath) : false;
        if (! is_string($pause) || trim($pause) === '') {
            throw new UpdaterError('The pause page (pause.html) is missing.');
        }
        Maintenance::on($c->app, $secret, $pause);
        $j->finish('maintenance');
        $c->saveJob();

        return self::reply('maintenance', 'done', 'swap', 'Members now see the pause page.', $j);
    }

    /** @return array<string, mixed> */
    private static function swap(Context $c, Journal $j): array
    {
        $swap = $j->get('swap');
        self::beginOnce($j, 'swap');
        $staging = is_string($j->get('staging')) ? $j->get('staging') : Unpack::stagingDir($c->app, $c->to());
        $oldDir = Swap::oldDir($c->app, $c->from());
        $plan = [];
        if (! is_array($swap)) {
            // Planned once, on the untouched tree; a repeat after an interruption carries on with the journal's list.
            $oldFiles = self::releaseFiles($c->app);
            $plan = Swap::plan($c->app, $staging, $oldFiles, self::releaseFiles($staging));
            if ($plan === []) {
                throw new UpdaterError('The plan for switching the files is empty.');
            }
            $j->set('site_local', Swap::siteLocalFiles($c->app, $oldFiles, $plan));
        }
        Swap::run($j, $plan, $oldDir);
        $j->finish('swap');
        $c->saveJob();

        return self::reply('swap', 'done', 'opcache', 'The new files are in place.', $j);
    }

    /** @return array<string, mixed> */
    private static function opcache(Context $c, Journal $j): array
    {
        self::beginOnce($j, 'opcache');
        $until = $j->get('opcache_wait_until');
        if (! is_int($until)) {
            if (($c->opcacheReset)()) {
                $j->log('opcache', 'opcache_reset() done.');

                return self::finishOpcache($c, $j);
            }
            $wait = ($c->opcacheWait)();
            if ($wait === null) {
                $j->log('opcache', 'OPcache is off: nothing to clear.');

                return self::finishOpcache($c, $j);
            }
            $until = $c->now() + $wait + 2;
            $j->set('opcache_wait_until', $until);
            $j->log('opcache', 'opcache_reset() not allowed; waiting revalidate_freq + 2 = '.($wait + 2).' s.');
        }
        if ($c->now() < $until) {
            self::progress($c, $j);

            return self::reply('opcache', 'more', 'opcache', 'Waiting for your host to notice the new files…', $j) + ['wait' => $until - $c->now()];
        }

        return self::finishOpcache($c, $j);
    }

    /** @return array<string, mixed> */
    private static function finishOpcache(Context $c, Journal $j): array
    {
        $j->finish('opcache');
        $c->saveJob();

        return self::reply('opcache', 'done', 'migrate', 'Switched.', $j);
    }

    /** @return array<string, mixed> */
    private static function migrate(Context $c, Journal $j): array
    {
        self::beginOnce($j, 'migrate');
        $app = ($c->boot)($c->app); // the new code; a failure here changed nothing in the database
        $j->set('migrate_started', $c->now());
        $j->set('migrated', false);
        try {
            $r = $app->call('migrate', ['--force' => true]);
        } catch (\Throwable $e) {
            $r = ['code' => 1, 'output' => $e->getMessage()];
        }
        foreach (array_slice(self::lines($r['output']), -20) as $line) {
            $j->log('migrate', $line);
        }
        if ($r['code'] !== 0) {
            $j->fail('migrate', 'migrate --force failed (exit code '.$r['code'].').');

            return self::putBack($c, $j, 'migrate', self::FAILED['migrate'], 'migrate --force failed (exit code '.$r['code'].').');
        }
        $j->set('migrated', true);
        foreach (['optimize:clear', 'optimize'] as $command) {
            $r = $app->call($command);
            foreach (array_slice(self::lines($r['output']), -5) as $line) {
                $j->log('migrate', $line);
            }
            if ($r['code'] !== 0) {
                throw new UpdaterError("$command failed (exit code {$r['code']}).");
            }
        }
        $j->finish('migrate');
        $c->saveJob();

        return self::reply('migrate', 'done', 'health', "Your club's records are up to date.", $j);
    }

    /** @return array<string, mixed> */
    private static function health(Context $c, Journal $j): array
    {
        self::beginOnce($j, 'health');
        $up = is_int($c->input['up'] ?? null) ? $c->input['up'] : 0;
        $login = is_int($c->input['login'] ?? null) ? $c->input['login'] : 0;
        $marker = ($c->input['login_marker'] ?? false) === true;
        $problems = [];
        if ($up !== 200) {
            $problems[] = "GET /up answered HTTP $up.";
        }
        if ($login !== 200 || ! $marker) {
            $problems[] = "GET /login answered HTTP $login".($marker ? '.' : ' without a portal page.');
        }
        if ($problems === []) {
            $problem = ($c->boot)($c->app)->health($c->to());
            if ($problem !== null) {
                $problems[] = $problem;
            }
        }
        if ($problems !== []) {
            foreach ($problems as $line) {
                $j->log('health', $line);
            }
            $j->fail('health', implode(' ', $problems));

            return self::putBack($c, $j, 'health', self::FAILED['health'], implode(' ', $problems));
        }
        $j->finish('health');
        $c->saveJob();

        return self::reply('health', 'done', 'finish', 'Everything works.', $j);
    }

    /** @return array<string, mixed> */
    private static function finish(Context $c, Journal $j): array
    {
        self::beginOnce($j, 'finish');
        self::completeFinish($c, $j);

        return self::reply('finish', 'done', null, 'Done.', $j);
    }

    /**
     * The finish order (the controller's ruling): pause off (guard file last), outcome and job `finished`, the new
     * update-guard.php and then public/update/ moved in (old copies to the old folder), zip and staging deleted. Each
     * part is safe to repeat, so an interrupted finish is completed by the next visit to /update/.
     */
    private static function completeFinish(Context $c, Journal $j): void
    {
        Maintenance::off($c->app);
        if (($c->job['status'] ?? null) !== 'finished') {
            $whatsNew = $c->job['manifest']['whats_new'] ?? [];
            self::writeOutcome($c, 'finished', 'finish', null, ['whats_new' => is_array($whatsNew) ? $whatsNew : []]);
            $c->saveJob('finished');
        }
        $staging = is_string($j->get('staging')) ? $j->get('staging') : Unpack::stagingDir($c->app, $c->to());
        $oldDir = Swap::oldDir($c->app, $c->from());
        self::installGuard($c->app, $staging, $oldDir);
        self::installUpdater($c->app, $staging, $oldDir);
        Unpack::removeTree($staging);
        @unlink(self::zipPath($c));
        $state = $j->steps()['finish']['state'] ?? null;
        if ($state === 'failed') {
            $j->begin('finish'); // a failed finish that is now complete must not be completed again on every visit
            $state = 'running';
        }
        if ($state === 'running') {
            $j->finish('finish');
        } else {
            $j->touch();
        }
    }

    /** public/index.php requires update-guard.php on every request, so it is replaced in one rename, never absent. */
    private static function installGuard(string $app, string $staging, string $oldDir): void
    {
        $new = $staging.'/public/update-guard.php';
        $current = $app.'/public/update-guard.php';
        if (! is_file($new)) {
            return;
        }
        $content = @file_get_contents($new);
        if (! is_string($content) || $content === '') {
            throw new UpdaterError('The new update-guard.php cannot be read.');
        }
        if (is_file($current) && @file_get_contents($current) === $content) {
            return;
        }
        $saved = $oldDir.'/public/update-guard.php';
        if (is_file($current) && ! is_file($saved)) {
            self::mkdirp(dirname($saved));
            if (! @copy($current, $saved)) {
                throw new UpdaterError('The old update-guard.php cannot be saved to the old folder.');
            }
        }
        Files::writeAtomic($current, $content);
    }

    /**
     * Moves the new public/update/ in: staged copy to public/.update-incoming, the running one to the old folder, the
     * incoming one into place. Judged by what is on disk, so a repeat after any interruption carries on.
     */
    private static function installUpdater(string $app, string $staging, string $oldDir): void
    {
        $new = $staging.'/public/update';
        $incoming = $app.'/public/.update-incoming';
        $current = $app.'/public/update';
        $saved = $oldDir.'/public/update';
        if (is_dir($new) && ! is_link($new) && ! file_exists($incoming)) {
            if (! @rename($new, $incoming)) {
                throw new UpdaterError('The new public/update folder cannot be moved next to the old one.');
            }
        }
        if (! is_dir($incoming) || is_link($incoming)) {
            return;
        }
        if (is_dir($current)) {
            self::mkdirp(dirname($saved));
            $target = file_exists($saved) ? $saved.'-'.bin2hex(random_bytes(3)) : $saved;
            if (! @rename($current, $target)) {
                throw new UpdaterError('The old public/update folder cannot be moved to the old folder.');
            }
            $saved = $target;
        }
        if (! @rename($incoming, $current)) {
            if (! is_dir($current) && is_dir($saved)) {
                @rename($saved, $current);
            }
            throw new UpdaterError('The new public/update folder cannot be put in place.');
        }
    }

    // ---------------------------------------------------------------- failures

    /** The page gave up reaching the server and asks for the files to be put back (from the switch on). */
    private static function rollbackRequested(Context $c, Journal $j): array
    {
        if (isset($j->steps()['finish'])) {
            return self::reply('rollback', 'more', $j->firstUnfinished() ?? 'finish', 'Carrying on from where the update got to.', $j);
        }
        $step = $j->firstUnfinished() ?? 'finish';
        if (! $j->isSwapStarted()) {
            return self::failStep($c, $j, $step, 'The page lost the connection before the switch.', [], self::LOST.' Nothing was changed.');
        }

        return self::putBack($c, $j, $step, self::LOST.' The old version was put back.', 'The page lost the connection and asked for the old version back.');
    }

    /**
     * A step failed: before the switch the update ends `failed` (pause lifted if it was put up); from the switch on the
     * old files are put back.
     *
     * @param  list<string>  $prechecksFailed
     * @return array<string, mixed>
     */
    private static function failStep(Context $c, Journal $j, string $step, string $error, array $prechecksFailed = [], ?string $reason = null): array
    {
        if (! in_array($step, Journal::STEPS, true)) {
            $step = 'prechecks';
        }
        $switched = $j->isSwapStarted(); // before fail(), which records the step
        try {
            if (($j->steps()[$step]['state'] ?? null) !== 'failed') {
                $j->fail($step, mb_substr($error, 0, 1000));
            }
            $j->log($step, 'Failed: '.mb_substr($error, 0, 1000));
        } catch (\Throwable) {
        }
        if ($step === 'finish') {
            // Never put back after the pause was lifted: the step is repeated (by the page, or the next visit).
            return self::reply($step, 'failed', null, self::FAILED['finish'], $j) + ['detail' => $error];
        }
        if ($switched) {
            return self::putBack($c, $j, $step, $reason ?? self::FAILED[$step], $error);
        }
        $reason ??= self::FAILED[$step];
        $maintenance = isset($j->steps()['maintenance']);
        // prechecks_failed goes into the report; checks_failed keeps the same plain lines in the history for the screen.
        self::writeOutcome($c, 'failed', $step, $reason, ['prechecks_failed' => $prechecksFailed, 'checks_failed' => $prechecksFailed]);
        $c->saveJob('failed');
        $j->touch();
        if ($maintenance) {
            Maintenance::off($c->app);
        }

        return self::reply($step, 'failed', null, $reason, $j) + ['detail' => $error];
    }

    /**
     * From the switch on: the old files back, the PHP cache cleared, outcome `rolled_back` (or `blocked` when the database
     * changes began and did not finish), the job `rolled_back`, the pause lifted. When the files cannot all be put back
     * the portal stays paused, the job stays `running` and `recovery_error` is kept for the "could not be undone" screen.
     *
     * @return array<string, mixed>
     */
    private static function putBack(Context $c, Journal $j, string $step, string $reason, string $error): array
    {
        // A Laravel booted earlier in this request turns every warning into an exception; the put-back must not stop
        // part-way because of one, so PHP's own handling applies until it is done.
        set_error_handler(null);
        try {
            return self::putBackFiles($c, $j, $step, $reason, $error);
        } finally {
            restore_error_handler();
        }
    }

    /** @return array<string, mixed> */
    private static function putBackFiles(Context $c, Journal $j, string $step, string $reason, string $error): array
    {
        $blocked = is_int($j->get('migrate_started')) && $j->get('migrated') !== true;
        try {
            Swap::rollback($j);
        } catch (\Throwable $e) {
            try {
                $j->log('swap', 'Putting the old version back failed: '.$e->getMessage());
            } catch (\Throwable) {
            }
            $j->set('recovery_error', ['at' => $c->now(), 'message' => mb_substr($e->getMessage(), 0, 1000)]);
            $c->saveJob();

            return self::reply($step, 'failed', null, 'The update could not be undone by itself.', $j) + ['detail' => $e->getMessage()];
        }
        ($c->opcacheReset)();
        if ($blocked) {
            $backup = $j->get('backup');
            $copy = is_array($backup) && ($backup['database'] ?? false) === true
                ? 'the copy of your club\'s data saved at the start of the update (file '.$backup['file'].' in storage/app/backups)'
                : 'the cPanel backup of the database taken before the update';
            $reason = "One change to your club's records didn't finish, so the old version was put back. "
                ."Restore the database from $copy, or have it checked, before trying again.";
        }
        self::writeOutcome($c, $blocked ? 'blocked' : 'rolled_back', $blocked ? 'migrate' : $step, $reason, ['error' => $error]);
        $c->saveJob('rolled_back');
        $j->set('recovery_error', null);
        $j->touch();
        Maintenance::off($c->app);

        return self::reply($step, 'failed', null, $reason, $j) + ['detail' => $error];
    }

    /** @return array<string, mixed> */
    private static function unexpected(Context $c, string $name, \Throwable $e): array
    {
        try {
            $j = $c->journal();
            if (in_array($name, Journal::STEPS, true) && in_array($c->job['status'] ?? null, ['ready', 'running'], true) && self::isThisAttempt($j, $c->job)) {
                return self::failStep($c, $j, $name, $e->getMessage());
            }
        } catch (\Throwable) {
        }

        return self::reply($name, 'failed', null, 'Something went wrong on your site.') + ['detail' => $e->getMessage()];
    }

    /** @param array<string, mixed> $extra */
    private static function writeOutcome(Context $c, string $outcome, string $step, ?string $reason, array $extra = []): void
    {
        $j = $c->journal();
        $log = [];
        foreach ($j->steps() as $name => $s) {
            if ($name === $step || $s['state'] === 'failed') {
                $log = array_merge($log, $s['log']);
            }
        }
        if (isset($extra['error']) && is_string($extra['error'])) {
            $log[] = 'Error: '.$extra['error'];
        }
        unset($extra['error']);
        $timings = [];
        foreach ($j->steps() as $name => $s) {
            $timings[$name] = ['state' => $s['state'], 'started_at' => $s['started_at'], 'finished_at' => $s['finished_at']];
        }
        $backup = $j->get('backup');
        Outcome::write($c->files->dir, $extra + [
            'outcome' => $outcome,
            'from' => $c->from(),
            'to' => $c->to(),
            'step' => $step,
            'reason' => $reason,
            'at' => $c->now(),
            'started_at' => is_int($c->job['created_at'] ?? null) ? $c->job['created_at'] : null,
            'log' => $log,
            'steps' => $timings,
            'timezone' => is_string($c->job['timezone'] ?? null) ? $c->job['timezone'] : 'UTC',
            'site_local' => $j->get('site_local', []),
            'database_in_backup' => is_array($backup) ? ($backup['database'] ?? null) : null,
            'backup_at' => is_array($backup) ? ($backup['at'] ?? null) : null,
        ]);
    }

    // ---------------------------------------------------------------- helpers

    /** Begins the step unless it is already running (a slice, or a repeat after an interruption). True when it began. */
    private static function beginOnce(Journal $j, string $step): bool
    {
        if (($j->steps()[$step]['state'] ?? null) === 'running') {
            return false;
        }
        $j->begin($step);

        return true;
    }

    /** A slice made progress: the 10-minute and 30-minute clocks restart. */
    private static function progress(Context $c, Journal $j): void
    {
        $j->touch();
        $c->saveJob();
    }

    /** While the pause is up, every answer gives the browser the bypass cookie again (a lost answer must not strand it). */
    private static function resendBypass(Context $c, Journal $j): void
    {
        $secret = self::downSecret($c);
        if ($secret !== null && is_file($c->files->guardPath)) {
            $c->cookies[] = Maintenance::bypassCookie($secret);
        }
    }

    private static function downSecret(Context $c): ?string
    {
        $file = $c->app.'/storage/framework/down';
        $raw = is_file($file) ? @file_get_contents($file) : false;
        $down = is_string($raw) ? json_decode($raw, true) : null;
        $secret = is_array($down) ? ($down['secret'] ?? null) : null;

        return is_string($secret) && preg_match('/^[A-Za-z0-9]{16,128}$/', $secret) === 1 ? $secret : null;
    }

    /** Removes what earlier attempts left: staging and old folders, probes, zips. Never storage/app/updater/webhooks. */
    private static function sweepEarlierAttempts(Context $c): void
    {
        Unpack::sweepProbes($c->app);
        $parent = dirname($c->app);
        foreach (@scandir($parent) ?: [] as $name) {
            if (! str_starts_with($name, '.clubportal-update-') && ! str_starts_with($name, '.clubportal-old-')) {
                continue;
            }
            $path = $parent.'/'.$name;
            if (! is_dir($path) || is_link($path)) {
                continue;
            }
            if (Unpack::isOwnedBy($path, $c->app)) {
                self::removeDir($path);
            } else {
                // Another portal in the same parent folder (or one from before the marker): its files may be the only
                // way back for that site, so they are never deleted here.
                @error_log('Club Portal updater: left '.$name.' alone (not marked as this portal\'s).');
            }
        }
        foreach (@scandir($c->files->dir) ?: [] as $name) {
            $path = $c->files->dir.'/'.$name;
            if (preg_match('/^(staging|old)-[0-9A-Za-z.+_-]+$/', $name) === 1) {
                self::removeDir($path);
            } elseif (preg_match('/^clubportal-[0-9A-Za-z.+_-]+\.zip$/', $name) === 1 && is_file($path) && ! is_link($path)) {
                @unlink($path);
            }
        }
        if (is_dir($c->app.'/public/.update-incoming')) {
            self::removeDir($c->app.'/public/.update-incoming');
        }
    }

    private static function removeDir(string $path): void
    {
        if (is_dir($path) && ! is_link($path)) {
            Unpack::removeTree($path);
        }
    }

    /** @return array<int|string, string> a release-files.json "files" list, [] when it is missing or unreadable */
    private static function releaseFiles(string $dir): array
    {
        $raw = @file_get_contents(rtrim($dir, '/\\').'/release-files.json');
        $json = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($json) && is_array($json['files'] ?? null) ? $json['files'] : [];
    }

    private static function zipPath(Context $c): string
    {
        $to = $c->to();
        if (preg_match('/^[0-9A-Za-z][0-9A-Za-z.+_-]*$/', $to) !== 1) {
            throw new UpdaterError('The new version number is not valid.');
        }

        return $c->files->dir.'/clubportal-'.$to.'.zip';
    }

    private static function mkdirp(string $dir): void
    {
        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new UpdaterError('A folder cannot be created: '.basename($dir));
        }
    }

    /** @return list<string> */
    private static function lines(string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/', $text) ?: []), static fn (string $l): bool => $l !== ''));
    }

    private static function megabytes(int $bytes): string
    {
        return number_format($bytes / 1048576, 1).' MB';
    }

    /** @return array<string, mixed> */
    private static function reply(string $step, string $status, ?string $next, string $message, ?Journal $j = null): array
    {
        return ['step' => $step, 'status' => $status, 'next' => $next, 'message' => $message,
            'swap_started' => $j !== null && $j->isSwapStarted()];
    }
}
