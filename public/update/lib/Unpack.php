<?php

declare(strict_types=1);

namespace ClubPortalUpdater;

/** Extracts the verified zip into a staging folder in slices, then checks every file against release-files.json. */
final class Unpack
{
    private const CHUNK = 65536;

    /**
     * Where the new release is staged: next to the app folder, or (when that parent cannot be written, e.g. open_basedir)
     * under storage/app/updater/ if that is on the same filesystem as the app, so a later rename() stays a rename.
     */
    public static function stagingDir(string $app, string $version): string
    {
        if (preg_match('/^[0-9A-Za-z][0-9A-Za-z.+_-]*$/', $version) !== 1) {
            throw new UpdaterError('The new version number is not valid.');
        }
        $app = rtrim($app, '/\\');
        $parent = dirname($app);
        if (@is_dir($parent) && @is_writable($parent)) {
            return $parent.'/.clubportal-update-'.$version;
        }

        $fallback = $app.'/storage/app/updater';
        $appDev = @stat($app)['dev'] ?? null;
        $fallbackDev = (@is_dir($fallback) || self::mkdirQuietly($fallback)) ? (@stat($fallback)['dev'] ?? null) : null;
        if ($appDev !== null && $appDev === $fallbackDev) {
            return $fallback.'/staging-'.$version;
        }

        throw new UpdaterError(
            "The folder next to the portal's files is not writable, and there is no other place on the same disk to prepare the update.",
            'Please allow PHP to create and rename folders in the parent folder of the Club Portal files (the folder that holds '
            .basename($app).'), or lift the open_basedir restriction for the account, so the Club Portal web updater can prepare a new version.',
        );
    }

    /** Removes empty .update-probe-* folders the pre-check's rename test left behind. Returns how many. */
    public static function sweepProbes(string $app): int
    {
        $parent = dirname(rtrim($app, '/\\'));
        $names = @scandir($parent);
        $removed = 0;
        foreach ($names === false ? [] : $names as $name) {
            $path = $parent.'/'.$name;
            if (str_starts_with($name, '.update-probe-') && is_dir($path) && ! is_link($path) && @rmdir($path)) {
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * Extracts entries $from .. $from+$count-1 into $staging (the zip's top folder is dropped).
     *
     * @return int the next index to pass, or -1 when the last entry is done
     *
     * @throws UpdaterError on a refused zip or a failed write; the staging folder is removed first
     */
    public static function slice(string $zip, string $staging, int $from, int $count): int
    {
        $z = new \ZipArchive;
        try {
            if ($z->open($zip) !== true) {
                throw new UpdaterError('The downloaded update file cannot be opened.');
            }
            $first = $z->numFiles > 0 ? $z->getNameIndex(0) : false;
            $top = is_string($first) ? explode('/', $first)[0] : '';
            ZipSafety::check($z, $top);

            if ($from === 0) {
                self::removeTree($staging);
            }
            self::makeDir($staging);

            $end = min($from + max(1, $count), $z->numFiles);
            for ($i = $from; $i < $end; $i++) {
                self::extractEntry($z, $i, $top, $staging);
            }

            return $end >= $z->numFiles ? -1 : $end;
        } catch (\Throwable $e) {
            self::removeTree($staging);
            throw $e instanceof UpdaterError ? $e : new UpdaterError('Unpacking the update failed: '.$e->getMessage(), '', $e);
        } finally {
            $z->close();
        }
    }

    /**
     * Compares every file listed in the staged release-files.json with what is on disk.
     *
     * @return list<string> paths whose hash differs or that are missing
     */
    public static function verify(string $staging): array
    {
        $raw = @file_get_contents(rtrim($staging, '/\\').'/release-files.json');
        $json = $raw === false ? null : json_decode($raw, true);
        if (! is_array($json) || ! isset($json['files']) || ! is_array($json['files'])) {
            throw new UpdaterError('The unpacked update has no readable release-files.json.');
        }
        $bad = [];
        foreach ($json['files'] as $path => $sha256) {
            $path = (string) $path;
            $file = rtrim($staging, '/\\').'/'.$path;
            $hash = (! in_array('..', explode('/', $path), true) && is_file($file) && ! is_link($file)) ? hash_file('sha256', $file) : false;
            if ($hash === false || ! hash_equals(strtolower((string) $sha256), $hash)) {
                $bad[] = $path;
            }
        }
        sort($bad, SORT_STRING);

        return $bad;
    }

    /** Deletes a folder and everything in it; symbolic links are removed, never followed. */
    public static function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (! is_dir($path)) {
            return;
        }
        foreach (@scandir($path) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                self::removeTree($path.'/'.$name);
            }
        }
        @rmdir($path);
    }

    private static function extractEntry(\ZipArchive $z, int $index, string $top, string $staging): void
    {
        $name = (string) $z->getNameIndex($index);
        $relative = substr($name, strlen($top) + 1);
        if ($relative === '' || $relative === false) {
            return;
        }
        $target = rtrim($staging, '/\\').'/'.rtrim($relative, '/');
        if (str_ends_with($name, '/')) {
            self::makeDir($target);

            return;
        }
        self::makeDir(dirname($target));
        $in = $z->getStream($name);
        if ($in === false) {
            throw new UpdaterError("The update file cannot be read at $relative.");
        }
        $out = @fopen($target, 'wb');
        if ($out === false) {
            fclose($in);
            throw new UpdaterError("The new file $relative cannot be written (disk full or folder not writable).");
        }
        while (! feof($in)) {
            $chunk = fread($in, self::CHUNK);
            if ($chunk === false) {
                fclose($in);
                fclose($out);
                throw new UpdaterError("The update file cannot be read at $relative.");
            }
            if ($chunk !== '' && fwrite($out, $chunk) !== strlen($chunk)) {
                fclose($in);
                fclose($out);
                throw new UpdaterError("The new file $relative cannot be written (disk full?).");
            }
        }
        fclose($in);
        if (! fclose($out)) {
            throw new UpdaterError("The new file $relative cannot be written (disk full?).");
        }
    }

    private static function makeDir(string $dir): void
    {
        if (! is_dir($dir) && ! self::mkdirQuietly($dir) && ! is_dir($dir)) {
            throw new UpdaterError('A folder for the new files cannot be created: '.basename($dir));
        }
    }

    /** mkdir that reports failure by its result only (no PHP warning for the log). */
    private static function mkdirQuietly(string $dir): bool
    {
        set_error_handler(static fn (): bool => true);
        try {
            return mkdir($dir, 0755, true);
        } finally {
            restore_error_handler();
        }
    }
}
