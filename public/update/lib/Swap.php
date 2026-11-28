<?php

declare(strict_types=1);

namespace ClubPortalUpdater;

/**
 * Puts the staged release in place and, if anything goes wrong, puts the old one back exactly.
 *
 * Every folder or root file of the release is moved aside to the old folder and the staged one renamed in. Files in
 * public/ are copied one by one (public/index.php carries the swap guard, so it is never absent), with the old file
 * saved first. The whole list of moves is written to the journal before the first one is done, and each move is marked
 * when finished. Rollback does not trust those marks: it looks at the disk, so it works from any interruption point.
 *
 * Never touched: .env*, storage/, database/ except migrations, seeders and factories, public/update/ and
 * public/update-guard.php (the finish step moves those), and public/ itself.
 */
final class Swap
{
    private const NOT_UNITS = ['public', 'storage', 'database'];

    private const DATABASE_DIRS = ['migrations', 'seeders', 'factories'];

    private const PUBLIC_KEPT = ['update', 'update-guard.php'];

    /**
     * Where the old release is kept: next to the app folder, or, when that cannot be written, beside the staging
     * folder's fallback under storage/app/updater/ (the rule is Unpack::stagingDir's, not repeated here).
     */
    public static function oldDir(string $app, string $oldVersion): string
    {
        $staging = Unpack::stagingDir($app, $oldVersion);

        return str_contains(basename($staging), '.clubportal-update-')
            ? dirname($staging).'/.clubportal-old-'.$oldVersion
            : dirname($staging).'/old-'.$oldVersion;
    }

    /**
     * The moves, in the order they are done. Paths are relative; run() resolves them against the old folder.
     *
     * @param  array<int|string, string>  $oldFiles  the old release-files.json "files" (path => sha256, or a list of paths)
     * @param  array<int|string, string>  $newFiles  the same for the new release
     * @return list<array{kind: string, role: string, rel: string, dir: bool, app: string, staging: string, backup: bool, carry: bool, mkdirs: list<string>}>
     */
    public static function plan(string $app, string $staging, array $oldFiles, array $newFiles): array
    {
        $app = rtrim($app, '/\\');
        $staging = rtrim($staging, '/\\');
        $oldPaths = self::paths($oldFiles);
        $newPaths = self::paths($newFiles);

        $units = ['release-files.json' => true];
        foreach (array_merge($oldPaths, $newPaths) as $path) {
            $top = explode('/', $path)[0];
            if (! self::isExcludedTop($top)) {
                $units[$top] = true;
            }
        }
        foreach (@scandir($staging) ?: [] as $name) {
            if ($name !== '.' && $name !== '..' && ! self::isExcludedTop($name)) {
                $units[$name] = true;
            }
        }
        foreach (self::DATABASE_DIRS as $dir) {
            $units['database/'.$dir] = true;
        }
        $units['public/build'] = true;
        ksort($units, SORT_STRING);

        $moves = [];
        foreach (array_keys($units) as $rel) {
            $hasOld = self::lexists("$app/$rel");
            $hasNew = self::lexists("$staging/$rel");
            $dir = ($hasOld && is_dir("$app/$rel") && ! is_link("$app/$rel")) || ($hasNew && is_dir("$staging/$rel"));
            if ($hasOld) {
                $moves[] = self::move('rename', 'aside', $rel, $dir, $app, $staging);
            }
            if ($hasNew) {
                $moves[] = self::move('rename', 'in', $rel, $dir, $app, $staging);
            }
        }

        $staged = [];
        foreach (self::listFiles("$staging/public") as $sub) {
            $rel = 'public/'.$sub;
            if (! self::isPublicKept($rel)) {
                $staged[$rel] = true;
            }
        }
        ksort($staged, SORT_STRING);
        // The host's .htaccess handling goes last, after everything else is in place.
        if (isset($staged['public/.htaccess'])) {
            unset($staged['public/.htaccess']);
            $staged['public/.htaccess'] = true;
        }
        foreach (array_keys($staged) as $rel) {
            $move = self::move('put', 'file', $rel, false, $app, $staging);
            $move['backup'] = self::lexists("$app/$rel");
            $move['carry'] = $rel === 'public/.htaccess';
            $move['mkdirs'] = self::missingDirs($app, $rel);
            $moves[] = $move;
        }

        $removed = [];
        foreach ($oldPaths as $rel) {
            if (str_starts_with($rel, 'public/') && ! self::isPublicKept($rel) && $rel !== 'public/.htaccess'
                && ! isset($staged[$rel]) && is_file("$app/$rel") && ! is_link("$app/$rel")) {
                $removed[$rel] = true;
            }
        }
        ksort($removed, SORT_STRING);
        foreach (array_keys($removed) as $rel) {
            $moves[] = self::move('rename', 'aside', $rel, false, $app, $staging);
        }

        return $moves;
    }

    /**
     * Does the moves. The first call writes the whole list to the journal; a later call (after an interruption) carries on
     * with that recorded list, whatever $plan now says, because the tree is half swapped and could not be planned again.
     *
     * @param  list<array<string, mixed>>  $plan
     * @param  ?callable(int, string): void  $afterMove  test seam, called with (move index, 'before'|'performed'|'done')
     */
    public static function run(Journal $j, array $plan, string $oldDir, ?callable $afterMove = null): void
    {
        $oldDir = rtrim($oldDir, '/\\');
        $swap = $j->get('swap');
        if (! is_array($swap) || ($swap['state'] ?? '') === 'rolled_back') {
            $swap = self::record($j, $plan, $oldDir);
        } elseif ($swap['state'] === 'rolling_back') {
            throw new UpdaterError('The files are being put back; the swap cannot continue.');
        } elseif (rtrim((string) $swap['old_dir'], '/\\') !== $oldDir) {
            throw new UpdaterError('The swap was started with a different old folder.');
        }

        self::mkdirp($oldDir);
        foreach ($swap['moves'] as $i => $m) {
            if ($m['state'] === 'done') {
                continue;
            }
            self::hook($afterMove, $i, 'before');
            self::perform($m);
            self::hook($afterMove, $i, 'performed');
            $swap['moves'][$i]['state'] = 'done';
            $j->set('swap', $swap);
            $j->touch();
            self::hook($afterMove, $i, 'done');
        }
        $swap['state'] = 'done';
        $j->set('swap', $swap);
        $j->touch();
    }

    /**
     * Puts the old release back from the journal, newest move first, then clears the cached framework files.
     * Safe to repeat and safe from any interruption: each move is judged by what is on disk.
     *
     * @throws UpdaterError after trying everything, when something could not be restored
     */
    public static function rollback(Journal $j): void
    {
        $swap = $j->get('swap');
        if (! is_array($swap) || ($swap['state'] ?? '') === 'rolled_back') {
            return;
        }
        $swap['state'] = 'rolling_back';
        $j->set('swap', $swap);

        $errors = [];
        foreach (array_reverse($swap['moves'], true) as $m) {
            try {
                self::undo($m);
            } catch (UpdaterError $e) {
                $errors[] = $e->getMessage();
            }
            $j->touch();
        }
        self::clearBootstrapCache((string) $swap['app']);
        if ($errors !== []) {
            throw new UpdaterError('The old files could not all be put back: '.implode(' ', $errors));
        }

        self::cleanOldDir($swap);
        $swap['state'] = 'rolled_back';
        $j->set('swap', $swap);
        $j->touch();
    }

    /**
     * Copies files that sit in a folder about to be replaced but are not in the old release (a .user.ini, a host's
     * error_log) into the staged folder, so they are still there after the swap. Release files win on a clash. Call it
     * before run(); afterwards it finds no staged folder and does nothing. The database file is not in a replaced folder.
     *
     * @param  array<int|string, string>  $oldFiles
     * @param  list<array<string, mixed>>  $plan
     * @return list<string> the site-local paths, relative to the app, sorted
     */
    public static function siteLocalFiles(string $app, array $oldFiles, array $plan): array
    {
        $app = rtrim($app, '/\\');
        $known = array_flip(self::paths($oldFiles));
        $found = [];
        foreach ($plan as $m) {
            if ($m['role'] !== 'aside' || ! $m['dir']) {
                continue;
            }
            $rel = (string) $m['rel'];
            $source = "$app/$rel";
            $target = rtrim((string) $m['staging'], '/\\')."/$rel";
            if (! is_dir($source) || is_link($source) || ! is_dir($target) || is_link($target)) {
                continue;
            }
            foreach (self::listFiles($source) as $sub) {
                $path = "$rel/$sub";
                if (isset($known[$path]) || preg_match('#^bootstrap/cache/[^/]+\.php$#', $path) === 1) {
                    continue;
                }
                $dest = "$target/$sub";
                if (self::lexists($dest)) {
                    // Already copied by an earlier call (same bytes) is still site-local; a different release file wins.
                    if (is_file($dest) && ! is_link($dest) && hash_file('sha256', $dest) === hash_file('sha256', "$source/$sub")) {
                        $found[] = $path;
                    }

                    continue;
                }
                self::mkdirp(dirname($dest));
                self::copyFile("$source/$sub", $dest);
                $found[] = $path;
            }
        }
        sort($found, SORT_STRING);

        return $found;
    }

    // ---------------------------------------------------------------- recording

    /** @return array<string, mixed> */
    private static function record(Journal $j, array $plan, string $oldDir): array
    {
        if (is_dir($oldDir) && (@scandir($oldDir) ?: []) !== ['.', '..']) {
            throw new UpdaterError('The folder for the old version already exists and is not empty: '.basename($oldDir));
        }
        $moves = [];
        foreach (array_values($plan) as $i => $m) {
            $rel = (string) $m['rel'];
            $app = rtrim((string) $m['app'], '/\\');
            $staging = rtrim((string) $m['staging'], '/\\');
            $row = ['i' => $i, 'kind' => $m['kind'], 'role' => $m['role'], 'rel' => $rel, 'state' => 'pending'];
            if ($m['kind'] === 'put') {
                $row += ['from' => "$staging/$rel", 'to' => "$app/$rel", 'backup' => $m['backup'] ? "$oldDir/$rel" : null,
                    'carry' => $m['carry'], 'mkdirs' => array_map(static fn (string $d): string => "$app/$d", $m['mkdirs'])];
            } elseif ($m['role'] === 'aside') {
                $row += ['from' => "$app/$rel", 'to' => "$oldDir/$rel"];
            } else {
                $row += ['from' => "$staging/$rel", 'to' => "$app/$rel"];
            }
            $moves[] = $row;
        }
        $swap = ['state' => 'running', 'app' => rtrim((string) ($plan[0]['app'] ?? ''), '/\\'), 'old_dir' => $oldDir, 'moves' => $moves];
        $j->set('swap', $swap);

        return $swap;
    }

    private static function hook(?callable $hook, int $index, string $phase): void
    {
        if ($hook !== null) {
            $hook($index, $phase);
        }
    }

    // ---------------------------------------------------------------- doing and undoing one move

    /** @param array<string, mixed> $m */
    private static function perform(array $m): void
    {
        $from = (string) $m['from'];
        $to = (string) $m['to'];
        if ($m['kind'] === 'rename') {
            if (! self::lexists($from)) {
                if (self::lexists($to)) {
                    return; // done before, only the mark was missing
                }
                throw new UpdaterError('A file needed for the update is missing: '.$m['rel']);
            }
            if (self::lexists($to)) {
                throw new UpdaterError('Cannot move '.$m['rel'].' because the target already exists.');
            }
            self::mkdirp(dirname($to));
            if (! @rename($from, $to)) {
                throw new UpdaterError('Cannot move '.$m['rel'].' (is the folder writable?).');
            }

            return;
        }

        // put: save the old file once, then replace it through a temp file and rename
        $backup = $m['backup'];
        if (is_string($backup) && ! self::lexists($backup) && is_file($to)) {
            self::mkdirp(dirname($backup));
            self::copyFile($to, $backup);
        }
        foreach ($m['mkdirs'] as $dir) {
            self::mkdirp((string) $dir);
        }
        self::mkdirp(dirname($to));
        if ($m['carry'] === true && is_string($backup) && is_file($backup)) {
            $old = @file_get_contents($backup);
            $new = @file_get_contents($from);
            if ($old === false || $new === false) {
                throw new UpdaterError('Cannot read '.$m['rel'].'.');
            }
            self::writeFile($to, Htaccess::carryCpanelBlock($old, $new), $from);

            return;
        }
        self::copyFile($from, $to);
    }

    /** @param array<string, mixed> $m */
    private static function undo(array $m): void
    {
        $from = (string) $m['from'];
        $to = (string) $m['to'];
        if ($m['kind'] === 'rename') {
            if (self::lexists($from)) {
                return; // never done, or already put back
            }
            if (! self::lexists($to)) {
                throw new UpdaterError('Neither '.$m['rel'].' nor its saved copy could be found.');
            }
            self::mkdirp(dirname($from));
            if (! @rename($to, $from)) {
                throw new UpdaterError('Cannot put '.$m['rel'].' back.');
            }

            return;
        }

        $backup = $m['backup'];
        if (is_string($backup)) {
            if (self::lexists($backup)) { // no saved copy means the old file was never touched
                self::copyFile($backup, $to);
            }

            return;
        }
        if (self::lexists($to)) {
            @unlink($to);
        }
        foreach (array_reverse($m['mkdirs']) as $dir) {
            if (is_dir((string) $dir)) {
                @rmdir((string) $dir); // only removes it when empty
            }
        }
    }

    private static function clearBootstrapCache(string $app): void
    {
        $dir = $app.'/bootstrap/cache';
        foreach (is_dir($dir) ? (@scandir($dir) ?: []) : [] as $name) {
            if (str_ends_with($name, '.php') && is_file("$dir/$name")) {
                @unlink("$dir/$name");
            }
        }
    }

    /** Removes the saved copies once they are proven restored, then any empty folders; anything else stays. */
    private static function cleanOldDir(array $swap): void
    {
        foreach ($swap['moves'] as $m) {
            $backup = $m['backup'] ?? null;
            if ($m['kind'] === 'put' && is_string($backup) && is_file($backup) && is_file((string) $m['to'])
                && hash_file('sha256', $backup) === hash_file('sha256', (string) $m['to'])) {
                @unlink($backup);
            }
        }
        self::pruneEmpty((string) $swap['old_dir']);
    }

    private static function pruneEmpty(string $dir): void
    {
        if (! is_dir($dir) || is_link($dir)) {
            return;
        }
        foreach (@scandir($dir) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                self::pruneEmpty("$dir/$name");
            }
        }
        @rmdir($dir);
    }

    // ---------------------------------------------------------------- helpers

    /** @return array{kind: string, role: string, rel: string, dir: bool, app: string, staging: string, backup: bool, carry: bool, mkdirs: list<string>} */
    private static function move(string $kind, string $role, string $rel, bool $dir, string $app, string $staging): array
    {
        return ['kind' => $kind, 'role' => $role, 'rel' => $rel, 'dir' => $dir, 'app' => $app, 'staging' => $staging,
            'backup' => false, 'carry' => false, 'mkdirs' => []];
    }

    private static function isExcludedTop(string $top): bool
    {
        return in_array($top, self::NOT_UNITS, true) || str_starts_with($top, '.env');
    }

    private static function isPublicKept(string $rel): bool
    {
        $second = explode('/', $rel)[1] ?? '';

        return in_array($second, self::PUBLIC_KEPT, true) || $second === 'build';
    }

    /**
     * @param  array<int|string, string>  $files
     * @return list<string>
     */
    private static function paths(array $files): array
    {
        $paths = array_is_list($files) ? $files : array_keys($files);
        $out = [];
        foreach ($paths as $path) {
            $path = (string) $path;
            if ($path === '' || $path[0] === '/' || str_contains($path, '\\') || in_array('..', explode('/', $path), true)) {
                throw new UpdaterError('The release lists an unsafe path.');
            }
            $out[] = $path;
        }

        return $out;
    }

    /** Folders between public/ and the file that do not exist yet (shallowest first). @return list<string> */
    private static function missingDirs(string $app, string $rel): array
    {
        $parts = explode('/', dirname($rel));
        $missing = [];
        $path = '';
        foreach ($parts as $part) {
            $path .= ($path === '' ? '' : '/').$part;
            if ($path !== 'public' && ! is_dir("$app/$path")) {
                $missing[] = $path;
            }
        }

        return $missing;
    }

    /** @return list<string> files under $dir (paths relative to it), symbolic links skipped, sorted */
    private static function listFiles(string $dir, string $prefix = ''): array
    {
        $out = [];
        foreach (is_dir($dir) && ! is_link($dir) ? (@scandir($dir) ?: []) : [] as $name) {
            if ($name === '.' || $name === '..' || is_link("$dir/$name")) {
                continue;
            }
            if (is_dir("$dir/$name")) {
                array_push($out, ...self::listFiles("$dir/$name", $prefix.$name.'/'));
            } elseif (is_file("$dir/$name")) {
                $out[] = $prefix.$name;
            }
        }
        sort($out, SORT_STRING);

        return $out;
    }

    private static function lexists(string $path): bool
    {
        return file_exists($path) || is_link($path);
    }

    private static function mkdirp(string $dir): void
    {
        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new UpdaterError('A folder cannot be created: '.basename($dir));
        }
    }

    /** Copy through a temp file in the target folder and a rename, keeping the file mode: never half a file. */
    private static function copyFile(string $source, string $target): void
    {
        $tmp = dirname($target).'/.'.basename($target).'.tmp-'.bin2hex(random_bytes(3));
        if (! @copy($source, $tmp)) {
            @unlink($tmp);
            throw new UpdaterError('Cannot copy '.basename($source).' (disk full or folder not writable).');
        }
        self::finishTemp($tmp, $target, $source);
    }

    private static function writeFile(string $target, string $content, string $modeFrom): void
    {
        $tmp = dirname($target).'/.'.basename($target).'.tmp-'.bin2hex(random_bytes(3));
        if (@file_put_contents($tmp, $content) !== strlen($content)) {
            @unlink($tmp);
            throw new UpdaterError('Cannot write '.basename($target).' (disk full or folder not writable).');
        }
        self::finishTemp($tmp, $target, $modeFrom);
    }

    private static function finishTemp(string $tmp, string $target, string $modeFrom): void
    {
        $perm = @fileperms($modeFrom);
        if ($perm !== false) {
            @chmod($tmp, $perm & 0777);
        }
        if (! @rename($tmp, $target)) {
            @unlink($tmp);
            throw new UpdaterError('Cannot replace '.basename($target).'.');
        }
    }
}
