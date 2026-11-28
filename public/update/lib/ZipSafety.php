<?php

declare(strict_types=1);

namespace ClubPortalUpdater;

/** Refuses a zip that could write outside its own folder or carry anything the release did not list. */
final class ZipSafety
{
    /** @throws UpdaterError */
    public static function check(\ZipArchive $z, string $topFolder): void
    {
        $topFolder = trim($topFolder, '/');
        if ($topFolder === '' || str_contains($topFolder, '/') || $topFolder === '..' || $topFolder === '.') {
            throw new UpdaterError('The update file has no single top folder.');
        }

        $listed = self::listedFiles($z, $topFolder);
        $seen = [];
        for ($i = 0; $i < $z->numFiles; $i++) {
            $name = $z->getNameIndex($i);
            if ($name === false) {
                throw new UpdaterError("The update file is damaged (entry $i).");
            }
            if (str_contains($name, '\\')) {
                throw new UpdaterError("The update file has a backslash in a path: $name");
            }
            if (preg_match('~[\x00-\x1f]~', $name)) {
                throw new UpdaterError('The update file has a control character in a path.');
            }
            if (str_starts_with($name, '/') || preg_match('~^[A-Za-z]:~', $name)) {
                throw new UpdaterError("The update file has an absolute path: $name");
            }
            if (in_array('..', explode('/', $name), true)) {
                throw new UpdaterError("The update file has a '..' in a path: $name");
            }
            if (self::isSymlink($z, $i)) {
                throw new UpdaterError("The update file has a symbolic link: $name");
            }
            if ($name !== $topFolder.'/' && ! str_starts_with($name, $topFolder.'/')) {
                throw new UpdaterError("The update file has a path outside its folder: $name");
            }
            if (isset($seen[$name])) {
                throw new UpdaterError("The update file lists a path twice: $name");
            }
            $seen[$name] = true;

            if (str_ends_with($name, '/')) {
                continue;
            }
            $relative = substr($name, strlen($topFolder) + 1);
            if ($relative !== 'release-files.json' && ! isset($listed[$relative])) {
                throw new UpdaterError("The update file has a file that release-files.json does not list: $relative");
            }
        }
    }

    /** @return array<string, string> path => sha256 from the zip's own release-files.json */
    private static function listedFiles(\ZipArchive $z, string $topFolder): array
    {
        $raw = $z->getFromName($topFolder.'/release-files.json');
        $json = $raw === false ? null : json_decode($raw, true);
        if (! is_array($json) || ! isset($json['files']) || ! is_array($json['files'])) {
            throw new UpdaterError('The update file has no readable release-files.json.');
        }

        return $json['files'];
    }

    private static function isSymlink(\ZipArchive $z, int $index): bool
    {
        $opsys = 0;
        $attributes = 0;
        if (! $z->getExternalAttributesIndex($index, $opsys, $attributes)) {
            return false;
        }

        // Unix mode lives in the high 16 bits; 0120000 is the symlink file type.
        return $opsys === \ZipArchive::OPSYS_UNIX && ((($attributes >> 16) & 0170000) === 0120000);
    }
}
