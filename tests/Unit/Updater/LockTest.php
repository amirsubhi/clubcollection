<?php

namespace Tests\Unit\Updater;

use ClubPortalUpdater\Lock;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../../public/update/lib/load.php';

class LockTest extends TestCase
{
    use Support;

    protected function tearDown(): void
    {
        $this->cleanTempDirs();
    }

    public function test_a_held_lock_makes_the_second_acquire_return_null(): void
    {
        $dir = $this->tempDir();
        $first = Lock::acquire($dir);
        $this->assertNotNull($first);
        $this->assertNull(Lock::acquire($dir));

        $first->release();
        $again = Lock::acquire($dir);
        $this->assertNotNull($again);
        $again->release();
    }

    public function test_the_lock_is_released_when_the_object_goes_away(): void
    {
        $dir = $this->tempDir();
        $lock = Lock::acquire($dir);
        $this->assertNotNull($lock);
        unset($lock);

        $this->assertNotNull(Lock::acquire($dir));
    }

    public function test_the_updater_folder_is_created_when_missing(): void
    {
        $dir = $this->tempDir().'/a/b';
        $this->assertNotNull(Lock::acquire($dir));
        $this->assertFileExists($dir.'/lock');
    }
}
