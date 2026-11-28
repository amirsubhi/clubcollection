<?php

namespace Tests\Unit\Updater;

use ClubPortalUpdater\Unpack;

/** Temp-folder helpers shared by the updater tests. */
trait Support
{
    /** @var list<string> */
    private array $tempDirs = [];

    protected function tempDir(): string
    {
        $dir = sys_get_temp_dir().'/cp-updater-'.bin2hex(random_bytes(6));
        mkdir($dir, 0755, true);
        $this->tempDirs[] = $dir;

        return realpath($dir);
    }

    protected function cleanTempDirs(): void
    {
        foreach ($this->tempDirs as $dir) {
            Unpack::removeTree($dir);
        }
        $this->tempDirs = [];
    }

    /**
     * Builds a release zip like scripts/build-release.sh: one top folder, release-files.json listing every other file.
     *
     * @param  array<string, string>  $files  path => contents
     */
    protected function makeZip(string $path, array $files, string $top = 'clubportal-9.9.9'): string
    {
        $hashes = [];
        foreach ($files as $name => $body) {
            $hashes[$name] = hash('sha256', $body);
        }
        ksort($hashes);
        $z = new \ZipArchive;
        $this->assertTrue($z->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        foreach ($files as $name => $body) {
            $z->addFromString("$top/$name", $body);
        }
        $z->addFromString("$top/release-files.json", json_encode(['format' => 1, 'version' => '9.9.9', 'files' => $hashes]));
        $z->close();

        return $path;
    }
}
