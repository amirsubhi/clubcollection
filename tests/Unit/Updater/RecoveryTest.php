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
        $this->runUpdateUntil('health', 660);
        $this->assertSame('<?php // new A', $this->read('app/A.php'));

        $this->assertSame('undone', Recovery::runIfIdle($this->site));

        $this->assertOldTree();
        $this->assertMaintenanceGone();
        $this->assertSame('undone', $this->job()['status']);
        $o = $this->outcome();
        $this->assertSame('undone', $o['outcome']);
        $this->assertSame('health', $o['step']);
        $this->assertSame(Recovery::REASON, $o['reason']);
        $this->assertSame(['2.2.0', '2.2.1'], [$o['from'], $o['to']]);
        $this->assertCount(1, glob($this->dir.'/history/*-2.2.0-2.2.1.json'));
        $this->assertTrue(Journal::open($this->dir)->isRecovering());
        $this->assertSame('rolled_back', Journal::open($this->dir)->get('swap')['state']);
    }

    public function test_an_update_left_part_way_through_the_database_change_ends_blocked(): void
    {
        $this->runUpdateUntil('migrate', 660);

        $this->assertSame('blocked', Recovery::runIfIdle($this->site));

        $this->assertOldTree();
        $this->assertMaintenanceGone();
        $o = $this->outcome();
        $this->assertSame('blocked', $o['outcome']);
        $this->assertSame('migrate', $o['step']);
        $this->assertSame(Recovery::BLOCKED_REASON, $o['reason']);
        $this->assertStringContainsString('Restore the database', $o['reason']);
        $this->assertSame('blocked', json_decode((string) file_get_contents(glob($this->dir.'/history/*.json')[0]), true)['outcome']);
        $this->assertSame('undone', $this->job()['status']);
    }

    public function test_the_outcome_carries_the_timezone_and_what_the_backup_holds(): void
    {
        $this->writeJob(['timezone' => 'Asia/Kuala_Lumpur']);
        $this->runUpdateUntil('migrate', 660);
        Journal::open($this->dir)->set('backup', ['database' => false, 'at' => 1764299100, 'file' => 'x.zip']);

        $this->assertSame('blocked', Recovery::runIfIdle($this->site));

        $o = $this->outcome();
        $this->assertFalse($o['database_in_backup']);
        $history = json_decode((string) file_get_contents(glob($this->dir.'/history/*.json')[0]), true);
        $this->assertSame('Asia/Kuala_Lumpur', $history['timezone']);
        $this->assertSame(1764299100, $history['backup_at']);
        $this->assertFalse($history['database_in_backup']);
        $this->assertFileExists($this->dir.'/blocked', 'the button is off before Laravel ever sees the outcome');
    }

    public function test_a_job_without_a_timezone_records_utc(): void
    {
        $this->runUpdateUntil('health', 660);

        Recovery::runIfIdle($this->site);

        $history = json_decode((string) file_get_contents(glob($this->dir.'/history/*.json')[0]), true);
        $this->assertSame('UTC', $history['timezone']);
        $this->assertNull($history['database_in_backup']);
        $this->assertFileDoesNotExist($this->dir.'/blocked');
    }

    public function test_a_failed_undo_is_kept_in_the_journal_and_cleared_by_a_later_success(): void
    {
        $this->runUpdateUntil('health', 660);
        rename($this->oldDir.'/bootstrap', $this->root.'/elsewhere');
        try {
            Recovery::runIfIdle($this->site);
            $this->fail('expected the failure');
        } catch (UpdaterError) {
        }
        $error = Journal::open($this->dir)->get('recovery_error');
        $this->assertIsInt($error['at']);
        $this->assertStringContainsString('could not all be put back', $error['message']);

        // Someone puts the folder back; the next idle request finishes the undo and clears the marker.
        rename($this->root.'/elsewhere', $this->oldDir.'/bootstrap');
        $this->assertSame('undone', Recovery::runIfIdle($this->site, time() + 700));
        $this->assertNull(Journal::open($this->dir)->get('recovery_error'));
        $this->assertOldTree();
    }

    public function test_when_the_pause_cannot_be_lifted_the_undo_waits_and_is_not_written_twice(): void
    {
        $this->runUpdateUntil('health', 660);
        $framework = $this->site.'/storage/framework';
        chmod($framework, 0555);
        try {
            Recovery::runIfIdle($this->site);
            $this->fail('expected the failure');
        } catch (UpdaterError) {
        } finally {
            chmod($framework, 0755);
        }
        $this->assertFileExists($framework.'/updating');
        $this->assertSame('undone', $this->job()['status']);
        $outcome = (string) file_get_contents($this->dir.'/outcome.json');
        $this->assertNotNull(Journal::open($this->dir)->get('recovery_error'));

        // The clock moved: the next request within 10 minutes does nothing.
        chmod($framework, 0555);
        try {
            $this->assertNull(Recovery::runIfIdle($this->site));
        } finally {
            chmod($framework, 0755);
        }

        // Ten minutes later it lifts the pause without writing the outcome or the history again.
        $history = glob($this->dir.'/history/*.json');
        $historyBody = (string) file_get_contents($history[0]);
        $this->assertSame('undone', Recovery::runIfIdle($this->site, time() + 700));
        $this->assertMaintenanceGone();
        $this->assertSame($outcome, file_get_contents($this->dir.'/outcome.json'));
        $this->assertSame($history, glob($this->dir.'/history/*.json'));
        $this->assertSame($historyBody, file_get_contents($history[0]));
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

    public function test_without_the_guard_file_an_idle_job_is_left_to_the_updater_page(): void
    {
        $this->runUpdateUntil('download', 660);

        $this->assertNull(Recovery::runIfIdle($this->site));

        $this->assertSame('running', $this->job()['status']);
        $this->assertFileDoesNotExist($this->dir.'/outcome.json');
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
