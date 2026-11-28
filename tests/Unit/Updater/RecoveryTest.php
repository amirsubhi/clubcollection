<?php

namespace Tests\Unit\Updater;

use ClubPortalUpdater\Journal;
use ClubPortalUpdater\Lock;
use ClubPortalUpdater\Recovery;
use ClubPortalUpdater\UpdaterError;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../../public/update/lib/load.php';

class RecoveryTest extends TestCase
{
    use RecoveryFixture;

    protected function setUp(): void
    {
        $this->makeApp();
        $this->writeJob();
    }

    protected function tearDown(): void
    {
        $this->cleanTempDirs();
    }

    private function outcome(): array
    {
        return json_decode((string) file_get_contents($this->dir.'/outcome.json'), true);
    }

    public function test_after_the_switch_the_old_files_are_put_back(): void
    {
        $this->runUpdateUntil('migrate', 660);
        $this->assertSame('<?php // new A', $this->read('app/A.php'));

        $this->assertSame('undone', Recovery::runIfIdle($this->site));

        $this->assertOldTree();
        $this->assertMaintenanceGone();
        $this->assertSame('undone', $this->job()['status']);
        $o = $this->outcome();
        $this->assertSame('undone', $o['outcome']);
        $this->assertSame('migrate', $o['step']);
        $this->assertSame(['2.2.0', '2.2.1'], [$o['from'], $o['to']]);
        $this->assertCount(1, glob($this->dir.'/history/*-2.2.0-2.2.1.json'));
        $this->assertTrue(Journal::open($this->dir)->isRecovering());
        $this->assertSame('rolled_back', Journal::open($this->dir)->get('swap')['state']);
    }

    public function test_before_the_switch_the_pause_is_lifted_and_the_job_undone(): void
    {
        $this->runUpdateUntil('swap', 660);
        $this->assertFileExists($this->site.'/storage/framework/down');

        $this->assertSame('undone', Recovery::runIfIdle($this->site));

        $this->assertOldTree();
        $this->assertMaintenanceGone();
        $this->assertSame('undone', $this->job()['status']);
        $this->assertSame('undone', $this->outcome()['outcome']);
    }

    public function test_steps_refuse_to_run_after_recovery(): void
    {
        $this->runUpdateUntil('swap', 660);
        Recovery::runIfIdle($this->site);

        $this->expectException(UpdaterError::class);
        Journal::open($this->dir)->begin('swap');
    }

    public function test_nothing_happens_while_another_request_holds_the_lock(): void
    {
        $this->runUpdateUntil('migrate', 660);
        $lock = Lock::acquire($this->dir);

        $this->assertNull(Recovery::runIfIdle($this->site));

        $lock->release();
        $this->assertSame('<?php // new A', $this->read('app/A.php'));
        $this->assertFileExists($this->site.'/storage/framework/updating');
        $this->assertFileDoesNotExist($this->dir.'/outcome.json');
        $this->assertSame('running', $this->job()['status']);
        $this->assertFalse(Journal::open($this->dir)->isRecovering());
    }

    public function test_a_journal_that_moved_recently_is_left_alone(): void
    {
        $this->runUpdateUntil('migrate', 300);

        $this->assertNull(Recovery::runIfIdle($this->site));

        $this->assertSame('<?php // new A', $this->read('app/A.php'));
        $this->assertFileExists($this->site.'/storage/framework/updating');
    }

    public function test_without_a_journal_an_old_guard_file_is_still_lifted(): void
    {
        touch($this->site.'/storage/framework/updating', time() - 700);

        $this->assertSame('undone', Recovery::runIfIdle($this->site));

        $this->assertMaintenanceGone();
        $this->assertSame('undone', $this->job()['status']);
    }

    public function test_without_a_journal_a_new_guard_file_waits(): void
    {
        touch($this->site.'/storage/framework/updating', time() - 100);

        $this->assertNull(Recovery::runIfIdle($this->site));
        $this->assertFileExists($this->site.'/storage/framework/updating');
    }

    public function test_an_unreadable_journal_changes_nothing(): void
    {
        $this->runUpdateUntil('migrate', 660);
        file_put_contents($this->dir.'/journal.json', '{not json');

        $this->assertNull(Recovery::runIfIdle($this->site));

        $this->assertSame('<?php // new A', $this->read('app/A.php'));
        $this->assertFileExists($this->site.'/storage/framework/updating');
        $this->assertSame('running', $this->job()['status']);
    }

    public function test_when_the_files_cannot_all_be_put_back_the_portal_stays_paused(): void
    {
        $this->runUpdateUntil('migrate', 660);
        // The saved old bootstrap folder vanished: that move cannot be undone.
        $this->assertDirectoryExists($this->oldDir.'/bootstrap');
        rename($this->oldDir.'/bootstrap', $this->root.'/elsewhere');

        try {
            Recovery::runIfIdle($this->site);
            $this->fail('expected the rollback failure to be reported');
        } catch (UpdaterError) {
        }

        $this->assertFileExists($this->site.'/storage/framework/updating');
        $this->assertFileExists($this->site.'/storage/framework/down');
        $this->assertFileDoesNotExist($this->dir.'/outcome.json');
        $this->assertSame('running', $this->job()['status']);
    }

    public function test_an_unreadable_job_file_is_marked_undone(): void
    {
        $this->runUpdateUntil('swap', 660);
        file_put_contents($this->dir.'/job.json', 'garbage');

        Recovery::runIfIdle($this->site);

        $this->assertSame('undone', $this->job()['status']);
    }

    public function test_a_finished_update_is_never_rolled_back(): void
    {
        $this->runUpdateUntil('finish', 660);
        $this->writeJob(['status' => 'finished']);

        Recovery::runIfIdle($this->site);

        $this->assertSame('<?php // new A', $this->read('app/A.php'));
        $this->assertMaintenanceGone();
        $this->assertSame('finished', $this->job()['status']);
        $this->assertFileDoesNotExist($this->dir.'/outcome.json');
    }
}
