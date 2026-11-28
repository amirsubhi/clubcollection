<?php

namespace Tests\Unit\Updater;

use App\Support\Updates\UpdaterFiles;
use App\Support\Updates\UpdaterHost;
use ClubPortalUpdater\Files;
use Tests\TestCase;

require_once __DIR__.'/../../../public/update/lib/load.php';

class FilesTest extends TestCase
{
    public function test_paths_match_the_laravel_side(): void
    {
        $f = Files::forApp(base_path());

        $this->assertSame(UpdaterFiles::dir(), $f->dir);
        $this->assertSame(UpdaterFiles::jobPath(), $f->jobPath);
        $this->assertSame(UpdaterFiles::journalPath(), $f->journalPath);
        $this->assertSame(UpdaterFiles::lockPath(), $f->lockPath);
        $this->assertSame(UpdaterFiles::outcomePath(), $f->outcomePath);
        $this->assertSame(UpdaterFiles::pausePath(), $f->pausePath);
        $this->assertSame(UpdaterFiles::historyDir(), $f->historyDir);
        $this->assertSame(UpdaterFiles::blockedPath(), $f->blockedPath);
        $this->assertSame(UpdaterFiles::webhooksDir(), $f->webhooksDir);
        $this->assertSame(UpdaterFiles::guardPath(), $f->guardPath);
    }

    public function test_here_is_the_app_the_updater_lives_in(): void
    {
        $this->assertSame(realpath(base_path()), realpath(Files::here()->app));
    }

    public function test_every_curl_function_the_library_calls_is_in_the_needed_list(): void
    {
        $source = '';
        foreach (glob(base_path('public/update/lib/*.php')) as $file) {
            $source .= file_get_contents($file);
        }
        preg_match_all('/\b(curl_[a-z_]+)\(/', $source, $m);
        foreach (array_unique($m[1]) as $function) {
            $this->assertContains($function, UpdaterHost::NEEDED_FUNCTIONS, "$function is called by the updater");
        }
    }
}
