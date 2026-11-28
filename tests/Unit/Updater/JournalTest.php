<?php

namespace Tests\Unit\Updater;

use ClubPortalUpdater\Journal;
use ClubPortalUpdater\UpdaterError;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../../public/update/lib/load.php';

class JournalTest extends TestCase
{
    use Support;

    private int $now = 1_000_000;

    protected function tearDown(): void
    {
        $this->cleanTempDirs();
    }

    private function open(string $dir): Journal
    {
        return Journal::open($dir, fn () => $this->now);
    }

    public function test_steps_round_trip_through_the_file(): void
    {
        $dir = $this->tempDir();
        $j = $this->open($dir);
        $j->begin('prechecks');
        $j->log('prechecks', 'all good');
        $this->now += 5;
        $j->finish('prechecks', ['n' => 3]);
        $j->set('backup_file', 'x.sql');

        $again = $this->open($dir);
        $steps = $again->steps();
        $this->assertSame('done', $steps['prechecks']['state']);
        $this->assertSame(1_000_000, $steps['prechecks']['started_at']);
        $this->assertSame(1_000_005, $steps['prechecks']['finished_at']);
        $this->assertSame(['n' => 3], $steps['prechecks']['detail']);
        $this->assertSame(['all good'], $steps['prechecks']['log']);
        $this->assertSame('x.sql', $again->get('backup_file'));
        $this->assertNull($again->get('nothing'));
        $this->assertSame('download', $again->firstUnfinished());
    }

    public function test_writes_are_atomic_and_leave_no_temp_file(): void
    {
        $dir = $this->tempDir();
        $j = $this->open($dir);
        $j->begin('prechecks');
        $j->finish('prechecks');
        $j->touch();

        $this->assertSame(['journal.json'], array_values(array_diff(scandir($dir), ['.', '..'])));
        $this->assertIsArray(json_decode(file_get_contents($dir.'/journal.json'), true));
    }

    public function test_idle_clock_runs_from_the_last_progress(): void
    {
        $j = $this->open($this->tempDir());
        $this->assertSame(0, $j->idleSeconds());
        $j->begin('prechecks');
        $this->now += 90;
        $this->assertSame(90, $j->idleSeconds());
        $j->touch();
        $this->assertSame(0, $j->idleSeconds());
        $this->now += 30;
        $j->finish('prechecks');
        $this->assertSame(0, $j->idleSeconds());
    }

    public function test_a_step_cannot_start_before_the_earlier_ones_are_done(): void
    {
        $j = $this->open($this->tempDir());
        $this->expectException(UpdaterError::class);
        $j->begin('download');
    }

    public function test_a_step_cannot_start_when_the_one_before_failed(): void
    {
        $j = $this->open($this->tempDir());
        $j->begin('prechecks');
        $j->fail('prechecks', 'no zip');
        $this->assertSame('failed', $j->steps()['prechecks']['state']);
        $this->assertSame('prechecks', $j->firstUnfinished());

        $this->expectException(UpdaterError::class);
        $j->begin('download');
    }

    public function test_a_failed_step_can_be_begun_again(): void
    {
        $j = $this->open($this->tempDir());
        $j->begin('prechecks');
        $j->fail('prechecks', 'x');
        $j->begin('prechecks');
        $this->assertSame('running', $j->steps()['prechecks']['state']);
    }

    public function test_a_step_running_over_120_seconds_counts_as_interrupted(): void
    {
        $j = $this->open($this->tempDir());
        $j->begin('prechecks');

        $this->now += 120;
        try {
            $j->begin('prechecks');
            $this->fail('A step running for 120 s or less must not be begun again');
        } catch (UpdaterError) {
        }

        $this->now += 1;
        $j->begin('prechecks');
        $this->assertSame(1_000_121, $j->steps()['prechecks']['started_at']);
    }

    public function test_a_done_step_cannot_be_begun_again(): void
    {
        $j = $this->open($this->tempDir());
        $j->begin('prechecks');
        $j->finish('prechecks');
        $this->expectException(UpdaterError::class);
        $j->begin('prechecks');
    }

    public function test_nothing_starts_once_recovery_has_begun(): void
    {
        $dir = $this->tempDir();
        $j = $this->open($dir);
        $this->assertFalse($j->isRecovering());
        $j->markRecovering();

        $this->assertTrue($this->open($dir)->isRecovering());
        $this->expectException(UpdaterError::class);
        $j->begin('prechecks');
    }

    public function test_swap_started_once_the_swap_step_has_begun(): void
    {
        $j = $this->open($this->tempDir());
        foreach (['prechecks', 'download', 'backup', 'unpack', 'maintenance'] as $step) {
            $j->begin($step);
            $j->finish($step);
        }
        $this->assertFalse($j->isSwapStarted());
        $j->begin('swap');
        $this->assertTrue($j->isSwapStarted());
    }

    public function test_unknown_steps_are_refused(): void
    {
        $this->expectException(UpdaterError::class);
        $this->open($this->tempDir())->begin('explode');
    }

    public function test_a_corrupt_journal_is_an_error_not_an_empty_one(): void
    {
        $dir = $this->tempDir();
        file_put_contents($dir.'/journal.json', '{not json');
        $this->expectException(UpdaterError::class);
        $this->open($dir);
    }
}
