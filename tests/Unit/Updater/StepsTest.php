<?php

namespace Tests\Unit\Updater;

use ClubPortalUpdater\Journal;
use ClubPortalUpdater\Lock;
use ClubPortalUpdater\Maintenance;
use ClubPortalUpdater\Steps;
use ClubPortalUpdater\Swap;
use ClubPortalUpdater\Unpack;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../../public/update/lib/load.php';

class StepsTest extends TestCase
{
    use UpdateRunFixture;

    protected function setUp(): void
    {
        $this->makeSite();
    }

    protected function tearDown(): void
    {
        $this->cleanTempDirs();
    }

    public function test_a_whole_update_runs_and_finishes(): void
    {
        $c = $this->context();

        $last = $this->runSteps($c);

        $this->assertSame(['step' => 'finish', 'status' => 'done', 'next' => null], array_intersect_key($last, array_flip(['step', 'status', 'next'])));
        $this->assertSame('<?php // new A', $this->read('app/A.php'));
        $this->assertSame('<?php // new B', $this->read('app/B.php'));
        $this->assertSame('<?php // new guard', $this->read('public/update-guard.php'));
        $this->assertSame('<?php // new updater', $this->read('public/update/index.php'));
        $this->assertSame('<?php // new lib', $this->read('public/update/lib/load.php'));
        $old = Swap::oldDir($this->site, '2.2.0');
        $this->assertSame('<?php // old updater', (string) file_get_contents($old.'/public/update/index.php'));
        $this->assertSame('<?php // old guard', (string) file_get_contents($old.'/public/update-guard.php'));
        $this->assertNotPaused();
        $this->assertDirectoryDoesNotExist(Unpack::stagingDir($this->site, '2.2.1'));
        $this->assertFileDoesNotExist($this->dir.'/clubportal-2.2.1.zip');
        $this->assertDirectoryDoesNotExist($this->site.'/public/.update-incoming');

        $this->assertSame('finished', $this->job()['status']);
        $outcome = $this->outcome();
        $this->assertSame('finished', $outcome['outcome']);
        $this->assertSame(['Receipts now show the club\'s logo'], $outcome['whats_new']);
        $this->assertCount(1, glob($this->dir.'/history/*.json'));
        $this->assertSame('done', $this->journal()->steps()['finish']['state']);

        $this->assertStringContainsString('sedang dikemas kini', (string) file_get_contents($this->dir.'/pause.html'));
        $this->assertContains('backup:run --database-only', $this->laravel->calls);
        $this->assertContains('migrate --force', $this->laravel->calls);
        $this->assertContains('optimize', $this->laravel->calls);
        $this->assertContains('health 2.2.1', $this->laravel->calls);
        $this->assertContains('laravel_maintenance', array_column($c->cookies, 'name'), 'the bypass cookie is given at the pause');
    }

    public function test_steps_run_only_in_order(): void
    {
        $c = $this->context();

        $r = Steps::run('swap', $c);
        $this->assertSame('more', $r['status']);
        $this->assertSame('prechecks', $r['next']);
        $this->assertFileDoesNotExist($this->dir.'/journal.json', 'nothing was written for the refused step');

        $this->assertSame('done', Steps::run('prechecks', $c)['status']);
        $r = Steps::run('backup', $c);
        $this->assertSame(['more', 'download'], [$r['status'], $r['next']]);
        $this->assertArrayNotHasKey('backup', $this->journal()->steps());
        $this->assertSame('running', $this->job()['status'], 'the job is running after prechecks');
    }

    public function test_an_unknown_step_changes_nothing(): void
    {
        $this->assertSame('failed', Steps::run('../../etc', $this->context())['status']);
        $this->assertFileDoesNotExist($this->dir.'/journal.json');
        $this->assertSame('ready', $this->job()['status']);
    }

    public function test_no_step_runs_while_recovering(): void
    {
        $c = $this->context();
        Steps::run('prechecks', $c);
        Journal::open($this->dir)->markRecovering();

        $r = Steps::run('download', $c);

        $this->assertSame('failed', $r['status']);
        $this->assertArrayNotHasKey('download', $this->journal()->steps());
        $this->assertFileDoesNotExist($this->dir.'/outcome.json', 'recovery writes the outcome, not the refused step');
        $this->assertPlain($r['message']);
    }

    public function test_busy_when_another_request_holds_the_lock(): void
    {
        $lock = Lock::acquire($this->dir);
        try {
            $r = Steps::run('prechecks', $this->context());
        } finally {
            $lock?->release();
        }

        $this->assertSame('busy', $r['status']);
        $this->assertSame('This update is running in another tab.', $r['message']);
        $this->assertFileDoesNotExist($this->dir.'/journal.json');
        $this->assertSame('ready', $this->job()['status']);
    }

    public function test_a_migration_failure_puts_the_files_back_and_ends_blocked(): void
    {
        $this->laravel->codes['migrate'] = 1;
        $c = $this->context();

        $r = $this->runSteps($c);

        $this->assertSame(['migrate', 'failed'], [$r['step'], $r['status']]);
        $this->assertOldSite();
        $this->assertNotPaused();
        $outcome = $this->outcome();
        $this->assertSame('blocked', $outcome['outcome']);
        $this->assertSame('migrate', $outcome['step']);
        $this->assertStringContainsString('Restore the database from the copy of your club\'s data saved at the start of the update', $outcome['reason']);
        $this->assertStringContainsString('kelab-20261128-213000.zip', $outcome['reason']);
        $this->assertStringContainsString('Column already exists', $outcome['report']);
        $this->assertSame('rolled_back', $this->job()['status']);
        $this->assertSame($outcome['reason'], $r['message']);
        $this->assertPlain($r['message']);
    }

    public function test_a_failure_after_the_migration_ran_is_rolled_back_not_blocked(): void
    {
        $this->laravel->codes['optimize'] = 1;

        $r = $this->runSteps($this->context());

        $this->assertSame('migrate', $r['step']);
        $this->assertOldSite();
        $this->assertSame('rolled_back', $this->outcome()['outcome']);
    }

    public function test_a_health_failure_puts_the_files_back(): void
    {
        $c = $this->context();
        $c->input = ['up' => 200, 'login' => 500, 'login_marker' => false];

        $r = $this->runSteps($c);

        $this->assertSame(['health', 'failed'], [$r['step'], $r['status']]);
        $this->assertOldSite();
        $this->assertNotPaused();
        $outcome = $this->outcome();
        $this->assertSame('rolled_back', $outcome['outcome']);
        $this->assertSame("The new version showed an error on this host's settings.", $outcome['reason']);
        $this->assertStringContainsString('GET /login answered HTTP 500', $outcome['report']);
        $this->assertSame('rolled_back', $this->job()['status']);
        $this->assertNotContains('health 2.2.1', $this->laravel->calls, 'the page results failed first');
        $this->assertPlain($r['message']);
    }

    public function test_the_in_process_boot_of_the_new_version_is_part_of_the_health_check(): void
    {
        $this->laravel->healthProblem = 'The new version cannot reach the database: refused';

        $r = $this->runSteps($this->context());

        $this->assertSame('health', $r['step']);
        $this->assertOldSite();
        $this->assertStringContainsString('cannot reach the database', $this->outcome()['report']);
    }

    public function test_a_failure_before_the_switch_ends_failed_with_nothing_changed(): void
    {
        $this->laravel->codes['backup:run'] = 1;

        $r = $this->runSteps($this->context());

        $this->assertSame(['backup', 'failed'], [$r['step'], $r['status']]);
        $this->assertSame('failed', $this->outcome()['outcome']);
        $this->assertSame('failed', $this->job()['status']);
        $this->assertOldSite();
        $this->assertNotPaused();
        $this->assertPlain($r['message']);
    }

    public function test_a_lost_connection_after_the_pause_but_before_the_switch_lifts_the_pause(): void
    {
        $c = $this->context();
        $this->runSteps($c, 'swap');
        $this->assertFileExists($this->site.'/storage/framework/updating');

        $r = Steps::run('rollback', $c);

        $this->assertSame('failed', $r['status']);
        $this->assertSame('failed', $this->outcome()['outcome']);
        $this->assertSame('failed', $this->job()['status']);
        $this->assertNotPaused();
        $this->assertOldSite();
    }

    public function test_a_lost_connection_after_the_switch_puts_the_old_version_back(): void
    {
        $c = $this->context();
        $this->runSteps($c, 'migrate');
        $this->assertSame('<?php // new A', $this->read('app/A.php'));

        $r = Steps::run('rollback', $c);

        $this->assertSame('failed', $r['status']);
        $this->assertSame('rolled_back', $this->outcome()['outcome']);
        $this->assertOldSite();
        $this->assertNotPaused();
    }

    public function test_failed_prechecks_end_the_update_with_their_lines(): void
    {
        $this->laravel->failedChecks = ['No member is paying right now: A member is paying a fee right now.'];

        $r = $this->runSteps($this->context());

        $this->assertSame(['prechecks', 'failed'], [$r['step'], $r['status']]);
        $outcome = $this->outcome();
        $this->assertSame('failed', $outcome['outcome']);
        $this->assertStringContainsString('A member is paying a fee right now.', $outcome['report']);
        $history = json_decode((string) file_get_contents(glob($this->dir.'/history/*.json')[0]), true);
        $this->assertSame('Asia/Kuala_Lumpur', $history['timezone']);
        $this->assertSame($this->laravel->failedChecks, $history['checks_failed'], 'the screen lists them from the history');
    }

    public function test_prechecks_refuse_a_site_without_its_release_file_list(): void
    {
        file_put_contents($this->site.'/release-files.json', '{"files": {}}');

        $r = Steps::run('prechecks', $this->context());

        $this->assertSame('failed', $r['status']);
        $this->assertSame([], $this->laravel->calls, 'Laravel is not even booted');
        $this->assertStringContainsString('release-files.json', $r['detail']);
    }

    public function test_a_new_start_clears_what_earlier_attempts_left(): void
    {
        $j = Journal::open($this->dir);
        $j->set('job', 'an-earlier-job');
        $j->set('swap', ['state' => 'rolled_back', 'moves' => []]);
        $j->markRecovering();
        $parent = dirname($this->site);
        foreach (['.clubportal-old-2.1.0', '.clubportal-update-2.2.1', '.update-probe-abc'] as $name) {
            mkdir($parent.'/'.$name.'/x', 0755, true);
            if ($name !== '.update-probe-abc') {
                file_put_contents($parent.'/'.$name.'/x/file', 'left');
            } else {
                rmdir($parent.'/'.$name.'/x');
            }
        }
        mkdir($this->dir.'/staging-2.2.0', 0755, true);
        file_put_contents($this->dir.'/clubportal-2.2.0.zip', 'old zip');
        mkdir($this->dir.'/webhooks', 0755, true);
        file_put_contents($this->dir.'/webhooks/1-a.json', '{}');

        $r = Steps::run('prechecks', $this->context());

        $this->assertSame('done', $r['status']);
        foreach (['.clubportal-old-2.1.0', '.clubportal-update-2.2.1', '.update-probe-abc'] as $name) {
            $this->assertDirectoryDoesNotExist($parent.'/'.$name);
        }
        $this->assertDirectoryDoesNotExist($this->dir.'/staging-2.2.0');
        $this->assertFileDoesNotExist($this->dir.'/clubportal-2.2.0.zip');
        $this->assertFileExists($this->dir.'/webhooks/1-a.json', 'saved payment callbacks are never touched');
        $fresh = $this->journal();
        $this->assertFalse($fresh->isRecovering());
        $this->assertNull($fresh->get('swap'));
        $this->assertSame(['prechecks'], array_keys($fresh->steps()));
        $this->assertFileExists($this->dir.'/journal-previous.json');
    }

    public function test_prechecks_refuse_while_an_earlier_update_still_pauses_the_portal(): void
    {
        file_put_contents($this->site.'/storage/framework/updating', '1');

        $r = Steps::run('prechecks', $this->context());

        $this->assertSame('failed', $r['status']);
        $this->assertFileExists($this->site.'/storage/framework/updating', 'the earlier update\'s pause is not lifted by this one');
    }

    public function test_the_download_gives_up_when_it_makes_no_progress(): void
    {
        $c = $this->context();
        $c->fetch = static fn (string $url, string $dest, int $s): array => ['done' => false, 'bytes' => 0];
        Steps::run('prechecks', $c);

        $answers = [];
        for ($i = 0; $i < Steps::MAX_STALLS; $i++) {
            $answers[] = Steps::run('download', $c)['status'];
        }

        $this->assertSame(['more', 'more', 'more', 'more', 'failed'], $answers);
        $this->assertSame('failed', $this->outcome()['outcome']);
    }

    public function test_a_damaged_download_is_refused(): void
    {
        $c = $this->context();
        $c->fetch = static function (string $url, string $dest, int $s): array {
            file_put_contents($dest, 'not the zip');

            return ['done' => true, 'bytes' => 11];
        };

        $r = $this->runSteps($c);

        $this->assertSame(['download', 'failed'], [$r['step'], $r['status']]);
        $this->assertFileDoesNotExist($this->dir.'/clubportal-2.2.1.zip');
    }

    public function test_without_a_cache_reset_the_page_waits_before_the_next_step(): void
    {
        $c = $this->context();
        $c->opcacheReset = static fn (): bool => false;
        $c->opcacheWait = static fn (): ?int => 1;
        $this->runSteps($c, 'opcache');

        $r = Steps::run('opcache', $c);

        $this->assertSame(['more', 'opcache', 3], [$r['status'], $r['next'], $r['wait']]);
        $j = $this->journal();
        $this->assertSame('running', $j->steps()['opcache']['state']);
    }

    public function test_an_interrupted_finish_is_completed_by_the_next_visit(): void
    {
        $c = $this->context();
        $this->runSteps($c, 'finish');
        // The finish request died right after lifting the pause.
        Journal::open($this->dir)->begin('finish');
        Maintenance::off($this->site);

        $this->assertTrue(Steps::completeFinishing($this->context()));

        $this->assertSame('<?php // new updater', $this->read('public/update/index.php'));
        $this->assertSame('finished', $this->outcome()['outcome']);
        $this->assertSame('finished', $this->job()['status']);
        $this->assertDirectoryDoesNotExist(Unpack::stagingDir($this->site, '2.2.1'));
        $this->assertFalse(Steps::completeFinishing($this->context()), 'nothing left to do');
    }

    public function test_finish_lifts_the_pause_before_replacing_the_updater(): void
    {
        $c = $this->context();
        $this->runSteps($c, 'finish');
        // The new updater cannot be moved in (its staged folder is gone): the pause must still be lifted.
        Unpack::removeTree(Unpack::stagingDir($this->site, '2.2.1').'/public/update');

        $r = Steps::run('finish', $c);

        $this->assertSame('done', $r['status']);
        $this->assertNotPaused();
        $this->assertSame('<?php // old updater', $this->read('public/update/index.php'), 'the running updater stays when there is no new one');
        $this->assertSame('finished', $this->outcome()['outcome']);
    }

    public function test_a_job_abandoned_before_the_pause_expires_after_ten_minutes(): void
    {
        $c = $this->context();
        $this->runSteps($c, 'backup');
        $this->assertFalse(Steps::expireAbandoned($this->context()), 'not idle yet');
        $job = $this->job();
        $job['last_step_at'] = time() - 700;
        file_put_contents($this->dir.'/job.json', json_encode($job));

        $this->assertTrue(Steps::expireAbandoned($this->context()));

        $this->assertSame('failed', $this->job()['status']);
        $outcome = $this->outcome();
        $this->assertSame('failed', $outcome['outcome']);
        $this->assertStringContainsString('Nothing was changed', $outcome['reason']);
    }

    public function test_a_job_past_the_pause_is_not_expired_by_the_page(): void
    {
        $c = $this->context();
        $this->runSteps($c, 'swap');
        Maintenance::off($this->site); // even with no guard file, a paused update is recovery's job
        Steps::run('swap', $c);
        $job = $this->job();
        $job['last_step_at'] = time() - 700;
        file_put_contents($this->dir.'/job.json', json_encode($job));

        $this->assertFalse(Steps::expireAbandoned($this->context()));
        $this->assertSame('running', $this->job()['status']);
    }
}
