<?php

namespace Tests\Unit\Updater;

use ClubPortalUpdater\UpdaterError;
use ClubPortalUpdater\ZipSafety;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../../public/update/lib/load.php';

class ZipSafetyTest extends TestCase
{
    use Support;

    private const TOP = 'clubportal-9.9.9';

    protected function tearDown(): void
    {
        $this->cleanTempDirs();
    }

    private function open(string $path): \ZipArchive
    {
        $z = new \ZipArchive;
        $this->assertTrue($z->open($path));

        return $z;
    }

    public function test_a_clean_release_zip_passes(): void
    {
        $zip = $this->makeZip($this->tempDir().'/a.zip', ['app/A.php' => '<?php', 'artisan' => 'x', 'public/index.php' => 'y']);
        $z = $this->open($zip);
        ZipSafety::check($z, self::TOP);
    }

    /** @return iterable<string, array{string}> */
    public static function badPaths(): iterable
    {
        yield 'parent segment' => [self::TOP.'/../evil.php'];
        yield 'nested parent segment' => [self::TOP.'/app/../../evil.php'];
        yield 'absolute path' => ['/etc/cron.d/evil'];
        yield 'windows drive' => ['C:/evil.php'];
        yield 'backslash' => [self::TOP.'/app\\evil.php'];
        yield 'outside the top folder' => ['other-folder/evil.php'];
        yield 'a file named like the top folder' => [self::TOP];
    }

    #[DataProvider('badPaths')]
    public function test_a_bad_path_is_refused(string $entry): void
    {
        $zip = $this->makeZip($this->tempDir().'/a.zip', ['app/A.php' => '<?php']);
        $z = $this->open($zip);
        $z->addFromString($entry, 'evil');
        $z->close();

        $this->expectException(UpdaterError::class);
        ZipSafety::check($this->open($zip), self::TOP);
    }

    public function test_a_symlink_entry_is_refused(): void
    {
        $zip = $this->makeZip($this->tempDir().'/a.zip', ['app/A.php' => '<?php']);
        $z = $this->open($zip);
        $name = self::TOP.'/app/link';
        $z->addFromString($name, '../../.env');
        $z->setExternalAttributesName($name, \ZipArchive::OPSYS_UNIX, 0120777 << 16);
        $z->close();

        $this->expectException(UpdaterError::class);
        $this->expectExceptionMessage('symbolic link');
        ZipSafety::check($this->open($zip), self::TOP);
    }

    public function test_a_file_missing_from_release_files_json_is_refused(): void
    {
        $zip = $this->makeZip($this->tempDir().'/a.zip', ['app/A.php' => '<?php']);
        $z = $this->open($zip);
        $z->addFromString(self::TOP.'/app/unlisted.php', '<?php evil();');
        $z->close();

        $this->expectException(UpdaterError::class);
        $this->expectExceptionMessage('app/unlisted.php');
        ZipSafety::check($this->open($zip), self::TOP);
    }

    public function test_a_zip_without_release_files_json_is_refused(): void
    {
        $zip = $this->tempDir().'/a.zip';
        $z = new \ZipArchive;
        $z->open($zip, \ZipArchive::CREATE);
        $z->addFromString(self::TOP.'/app/A.php', 'x');
        $z->close();

        $this->expectException(UpdaterError::class);
        ZipSafety::check($this->open($zip), self::TOP);
    }

    public function test_a_wrong_top_folder_is_refused(): void
    {
        $zip = $this->makeZip($this->tempDir().'/a.zip', ['app/A.php' => '<?php']);
        $this->expectException(UpdaterError::class);
        ZipSafety::check($this->open($zip), 'something-else');
    }
}
