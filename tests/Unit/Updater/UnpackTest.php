<?php

namespace Tests\Unit\Updater;

use ClubPortalUpdater\Unpack;
use ClubPortalUpdater\UpdaterError;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../../public/update/lib/load.php';

class UnpackTest extends TestCase
{
    use Support;

    protected function tearDown(): void
    {
        $this->cleanTempDirs();
    }

    /** @return array<string, string> */
    private function release(): array
    {
        $files = ['artisan' => '#!/usr/bin/env php', 'app/Models/User.php' => '<?php // user', 'config/app.php' => '<?php return [];',
            'public/index.php' => '<?php // index', 'resources/empty.txt' => ''];
        for ($i = 0; $i < 12; $i++) {
            $files["vendor/pkg/File$i.php"] = '<?php // '.str_repeat((string) $i, 5000);
        }

        return $files;
    }

    /** @return array<string, string> relative path => contents of every file under $dir */
    private function tree(string $dir): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $out[substr($f->getPathname(), strlen($dir) + 1)] = file_get_contents($f->getPathname());
        }
        ksort($out);

        return $out;
    }

    public function test_unpacking_in_slices_equals_a_full_extract(): void
    {
        $root = $this->tempDir();
        $zip = $this->makeZip($root.'/r.zip', $this->release());
        $staging = $root.'/staging';

        $next = 0;
        $calls = 0;
        while ($next !== -1) {
            $next = Unpack::slice($zip, $staging, $next, 4);
            $calls++;
            $this->assertLessThan(20, $calls);
        }
        $this->assertGreaterThan(2, $calls);

        $full = $root.'/full';
        $z = new \ZipArchive;
        $z->open($zip);
        $z->extractTo($full);
        $this->assertSame($this->tree($full.'/clubportal-9.9.9'), $this->tree($staging));
        $this->assertSame([], Unpack::verify($staging));
    }

    public function test_starting_again_from_zero_replaces_a_stale_staging_folder(): void
    {
        $root = $this->tempDir();
        $zip = $this->makeZip($root.'/r.zip', $this->release());
        mkdir($root.'/staging');
        file_put_contents($root.'/staging/stale.txt', 'old');

        Unpack::slice($zip, $root.'/staging', 0, 1000);

        $this->assertFileDoesNotExist($root.'/staging/stale.txt');
        $this->assertSame([], Unpack::verify($root.'/staging'));
    }

    public function test_verify_flags_a_tampered_and_a_missing_file(): void
    {
        $root = $this->tempDir();
        $zip = $this->makeZip($root.'/r.zip', $this->release());
        Unpack::slice($zip, $root.'/staging', 0, 1000);

        file_put_contents($root.'/staging/app/Models/User.php', '<?php // tampered');
        unlink($root.'/staging/config/app.php');

        $this->assertSame(['app/Models/User.php', 'config/app.php'], Unpack::verify($root.'/staging'));
    }

    public function test_write_failure_leaves_site_untouched(): void
    {
        $root = $this->tempDir();
        // A pretend installed site next to the staging folder.
        mkdir($root.'/app/app', 0755, true);
        file_put_contents($root.'/app/artisan', 'live artisan');
        file_put_contents($root.'/app/app/Live.php', '<?php // live');
        $before = $this->tree($root.'/app');

        $zip = $this->makeZip($root.'/r.zip', $this->release());
        $staging = $root.'/.clubportal-update-9.9.9';
        mkdir($staging);
        // A plain file where the release needs the folder "vendor": creating vendor/pkg/... cannot succeed.
        // (Slice 0 wipes a stale staging folder, so use a later slice to meet the blocker.)
        $next = Unpack::slice($zip, $staging, 0, 3);
        $this->assertGreaterThan(0, $next);
        Unpack::removeTree($staging.'/vendor');
        file_put_contents($staging.'/vendor', 'i am a file, not a folder');

        try {
            Unpack::slice($zip, $staging, $next, 1000);
            $this->fail('A write that cannot happen must throw');
        } catch (UpdaterError $e) {
            $this->assertNotSame('', $e->getMessage());
        }

        $this->assertDirectoryDoesNotExist($staging, 'a failed unpack removes the staging folder');
        $this->assertSame($before, $this->tree($root.'/app'), 'the installed site is not touched');
    }

    public function test_an_unsafe_zip_is_refused_and_nothing_is_left_behind(): void
    {
        $root = $this->tempDir();
        $zip = $this->makeZip($root.'/r.zip', $this->release());
        $z = new \ZipArchive;
        $z->open($zip);
        $z->addFromString('clubportal-9.9.9/../evil.php', 'x');
        $z->close();

        try {
            Unpack::slice($zip, $root.'/staging', 0, 1000);
            $this->fail('An unsafe zip must be refused');
        } catch (UpdaterError) {
        }
        $this->assertDirectoryDoesNotExist($root.'/staging');
        $this->assertFileDoesNotExist($root.'/evil.php');
    }

    public function test_staging_goes_next_to_the_app_when_the_parent_is_writable(): void
    {
        $root = $this->tempDir();
        mkdir($root.'/site');

        $this->assertSame($root.'/.clubportal-update-2.3.0', Unpack::stagingDir($root.'/site', '2.3.0'));
    }

    public function test_staging_falls_back_to_storage_on_the_same_disk(): void
    {
        $root = $this->tempDir();
        mkdir($root.'/site/storage/app', 0755, true);
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('A read-only parent cannot be simulated as root.');
        }
        chmod($root, 0555);
        try {
            $this->assertSame($root.'/site/storage/app/updater/staging-2.3.0', Unpack::stagingDir($root.'/site', '2.3.0'));
        } finally {
            chmod($root, 0755);
        }
    }

    public function test_no_writable_place_fails_with_a_message_for_the_host(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('A read-only folder cannot be simulated as root.');
        }
        $root = $this->tempDir();
        mkdir($root.'/site/storage/app', 0755, true);
        chmod($root, 0555);
        chmod($root.'/site/storage/app', 0555);
        try {
            Unpack::stagingDir($root.'/site', '2.3.0');
            $this->fail('No place to stage must fail');
        } catch (UpdaterError $e) {
            $this->assertStringContainsString('open_basedir', $e->hostMessage());
        } finally {
            chmod($root, 0755);
            chmod($root.'/site/storage/app', 0755);
        }
    }

    public function test_a_version_with_path_characters_is_refused(): void
    {
        $this->expectException(UpdaterError::class);
        Unpack::stagingDir($this->tempDir(), '../../x');
    }

    public function test_stray_probe_folders_are_swept(): void
    {
        $root = $this->tempDir();
        mkdir($root.'/site');
        mkdir($root.'/.update-probe-aaa');
        mkdir($root.'/.update-probe-bbb-b');
        mkdir($root.'/keep-me');

        $this->assertSame(2, Unpack::sweepProbes($root.'/site'));
        $this->assertDirectoryDoesNotExist($root.'/.update-probe-aaa');
        $this->assertDirectoryExists($root.'/keep-me');
    }
}
