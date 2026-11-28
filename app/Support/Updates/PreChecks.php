<?php

declare(strict_types=1);

namespace App\Support\Updates;

use Throwable;

/**
 * The checks run before the Update button is offered (spec section 3). Each is a pass or a fail, never "unknown":
 * a host fact that cannot be read counts as a pass with no line where nothing can go wrong because of it (free space)
 * and as a fail with a fix everywhere else.
 */
final class PreChecks
{
    /** Replaced in tests. */
    public static ?UpdaterHost $host = null;

    /** Folders and files the update replaces; each must be writable by this PHP user. */
    private const REPLACED = ['app', 'bootstrap', 'config', 'database', 'lang', 'public', 'resources', 'routes', 'vendor',
        'artisan', 'composer.json', 'composer.lock', 'release.json'];

    /** Plain names of the checks, used for passing rows too ("Show checks"). */
    public const LABELS = [
        'version_from' => 'Your portal is recent enough to update from here',
        'version_newer' => 'A newer version is available',
        'php' => "Your host's PHP version is fine",
        'extensions' => 'Your host has the features the updater needs',
        'functions' => 'Your host allows the file changes the updater needs',
        'opcache' => 'Your host shows new files straight away',
        'writable' => "The portal's files can be replaced",
        'rename' => 'The website can prepare the new version',
        'disk' => 'There is enough free space',
        'no_update_running' => 'No other update is running',
        'no_payment' => 'No member is paying right now',
        'migrations' => "Your club's records are up to date",
        'backup_database' => "Your club's data can be copied automatically",
        'manual_action' => 'Nothing needs doing by hand first',
    ];

    private const BY_HAND = 'ask whoever installed the portal to update it by hand (instructions: docs/RELEASE.md)';

    private const HOST_FIX = 'Send the message below to your host, or '.self::BY_HAND.'.';

    /** The unpacked release is estimated at this many times the zip, and 3 times that is needed free. */
    private const UNPACK_FACTOR = 3;

    private const FREE_FACTOR = 3;

    /** @return list<PreCheck> */
    public static function run(Manifest $m, bool $full = false): array
    {
        $h = self::host();
        $d = self::safe(fn () => $h->siteDomain()) ?? 'this site';
        $checks = [];

        $installed = self::safe(fn () => $h->installedVersion()) ?? 'dev';
        if (trim($installed) === '' || ! Version::isRelease($installed)) {
            $checks[] = self::row('version_from', false,
                "We can't tell which version your portal is on, so it can't update itself here: ".self::BY_HAND.'.',
                'Update it by hand once with the steps in docs/RELEASE.md; after that, updates can be done from this page.');
        } else {
            $checks[] = Version::compare($installed, $m->minFromVersion) >= 0
                ? self::pass('version_from')
                : self::row('version_from', false,
                    "Your portal is on version $installed, which is too old to update from this page: ".self::BY_HAND.'.',
                    'Update it by hand once with the steps in docs/RELEASE.md; after that, updates can be done from this page.');
        }

        $checks[] = Version::compare($m->version, $installed) > 0
            ? self::pass('version_newer')
            : self::row('version_newer', false,
                'The newest version is not newer than the one you have, so there is nothing to update.',
                'Nothing to do. Use Check now to look for a newer version.');

        $checks[] = self::php($m, $h, $d);
        $checks[] = self::extensions($h, $d);
        $checks[] = self::functions($h, $d);
        $checks[] = self::opcache($h, $d);

        if ($full) {
            $checks[] = self::writable($h, $d);
            $checks[] = self::rename($h, $d);
        }

        $checks[] = self::disk($m, $h, $d);
        $checks[] = self::flag('no_update_running', fn () => $h->updateRunning(),
            'An update is already running.',
            'Wait for it to finish, then reload this page.');
        $checks[] = self::flag('no_payment', fn () => $h->paymentInProgress(),
            'A member is paying a fee right now.',
            'Try again in a couple of minutes, when the payment is finished.');
        $checks[] = self::flag('migrations', fn () => $h->pendingMigrations() > 0,
            "A previous update to your club's records hasn't finished.",
            'Ask whoever installed the portal to finish it (the technical details are in the report).');

        $checks[] = self::backupDatabase($h, $d);
        $checks[] = self::manualAction($m, $d);

        return $checks;
    }

    /**
     * True when every blocking check passes. This NEVER enables the button by itself: the two "a person must act" rows
     * (backup_database, manual_action) are not blocking here because they are cleared by a tick; use canStart().
     *
     * @param  list<PreCheck>  $checks
     */
    public static function allPass(array $checks): bool
    {
        foreach ($checks as $c) {
            if (! $c->ok && $c->blocking) {
                return false;
            }
        }

        return true;
    }

    /**
     * The button gate: every blocking check passes AND each tick that is needed has been given.
     *
     * @param  list<PreCheck>  $checks  from run()
     */
    public static function canStart(array $checks, bool $dbBackupTicked, bool $manualActionTicked): bool
    {
        if (! self::allPass($checks)) {
            return false;
        }
        foreach ($checks as $c) {
            if ($c->key === 'backup_database' && ! $c->ok && ! $dbBackupTicked) {
                return false;
            }
            if ($c->key === 'manual_action' && ! $c->ok && ! $manualActionTicked) {
                return false;
            }
        }

        return true;
    }

    /** True when the database cannot go into the automatic backup here, so someone must confirm a manual one. */
    public static function needsDatabaseBackupTick(): bool
    {
        return self::safe(fn () => self::host()->databaseBackupNeedsTick()) ?? true;
    }

    /** True when the release asks for something to be done by hand first, which someone must confirm. */
    public static function needsManualActionTick(Manifest $m): bool
    {
        return $m->manualAction !== null && trim($m->manualAction) !== '';
    }

    private static function host(): UpdaterHost
    {
        return self::$host ??= new UpdaterHost;
    }

    private static function row(string $key, bool $ok, string $message, ?string $fix, ?string $hostMessage = null, bool $blocking = true): PreCheck
    {
        return new PreCheck($key, self::LABELS[$key], $ok, $message, $fix, $hostMessage, $blocking);
    }

    private static function pass(string $key): PreCheck
    {
        return self::row($key, true, '', null);
    }

    private static function safe(callable $fact): mixed
    {
        try {
            return $fact();
        } catch (Throwable) {
            return null;
        }
    }

    private static function hostFail(string $key, string $message, string $hostMessage, ?string $fix = null): PreCheck
    {
        return self::row($key, false, $message, $fix ?? self::HOST_FIX, $hostMessage);
    }

    /** A check where "true" means a problem. An unreadable fact fails with a generic line, never a pass. */
    private static function flag(string $key, callable $problem, string $message, string $fix): PreCheck
    {
        try {
            $bad = (bool) $problem();
        } catch (Throwable) {
            return self::row($key, false, "We couldn't finish checking your site just now.",
                'Reload this page in a minute. If it keeps happening, ask whoever installed the portal to look at it.');
        }

        return $bad ? self::row($key, false, $message, $fix) : self::pass($key);
    }

    private static function php(Manifest $m, UpdaterHost $h, string $d): PreCheck
    {
        if (preg_match('/\A\s*(>=|<=|==|=|>|<)?\s*(\d+(?:\.\d+){0,2})\s*\z/', $m->phpRequirement, $p) !== 1) {
            return self::pass('php'); // a requirement we cannot read cannot be held against the host
        }
        $op = ($p[1] ?? '') === '' ? '>=' : ($p[1] === '=' ? '==' : $p[1]);
        try {
            $ok = version_compare($h->phpVersion(), $p[2], $op);
        } catch (Throwable) {
            $ok = false;
        }
        if ($ok) {
            return self::pass('php');
        }
        $needs = $op === '>=' ? "{$p[2]} or newer" : trim($m->phpRequirement);

        return self::hostFail('php',
            "Your host runs an older PHP than this version needs (it needs PHP $needs).",
            "Please switch the PHP version for the account that runs $d to PHP $needs (cPanel, MultiPHP Manager). It is needed by the Club Portal web updater.");
    }

    private static function extensions(UpdaterHost $h, string $d): PreCheck
    {
        $missing = [];
        foreach (['zip' => 'PHP zip extension (ZipArchive)', 'curl' => 'PHP curl extension'] as $ext => $label) {
            if (! (self::safe(fn () => $h->extensionLoaded($ext)) ?? false)) {
                $missing[$ext] = $label;
            }
        }
        if ($missing === []) {
            return self::pass('extensions');
        }
        $plain = implode(', ', array_map(fn ($e) => "PHP $e", array_keys($missing)));
        $list = implode(' and the ', $missing);
        $needed = count($missing) > 1 ? 'They are' : 'It is';

        return self::hostFail('extensions',
            "Your host has turned off a feature the updater needs ($plain).",
            "Please enable the $list for the account that runs $d. $needed needed by the Club Portal web updater.");
    }

    private static function functions(UpdaterHost $h, string $d): PreCheck
    {
        $all = implode(', ', UpdaterHost::NEEDED_FUNCTIONS);
        try {
            $blocked = $h->disabledFunctions();
        } catch (Throwable) {
            return self::hostFail('functions',
                "We couldn't check whether your host allows the file changes the updater needs.",
                "Please confirm that none of these PHP functions are listed in disable_functions for the account that runs $d: $all. They are needed by the Club Portal web updater.");
        }
        if ($blocked === []) {
            return self::pass('functions');
        }
        $names = implode(', ', $blocked);
        $listed = (string) ini_get('disable_functions');
        $where = $listed !== '' ? " (the server's disable_functions currently lists: $listed)" : '';

        return self::hostFail('functions',
            'Your host has blocked some PHP functions the updater needs for moving and replacing files.',
            "Please allow these PHP functions for the account that runs $d: $names. They are blocked by disable_functions$where. They are needed by the Club Portal web updater.");
    }

    private static function opcache(UpdaterHost $h, string $d): PreCheck
    {
        try {
            $stale = ! $h->opcacheValidatesTimestamps() && ! $h->opcacheResetAllowed();
        } catch (Throwable) {
            $stale = true;
        }
        if (! $stale) {
            return self::pass('opcache');
        }

        return self::hostFail('opcache',
            "Your host keeps an old copy of the portal's code in memory and doesn't let the portal refresh it, so the portal can't update itself here.",
            "Please either set opcache.validate_timestamps=1, or allow opcache_reset() for the account that runs $d (opcache_reset must not be in disable_functions, and opcache.restrict_api must be empty or include the portal's folder). Without one of these, PHP keeps serving the old code after an update. It is needed by the Club Portal web updater.");
    }

    private static function writable(UpdaterHost $h, string $d): PreCheck
    {
        try {
            $bad = $h->unwritablePaths($h->basePath(), self::REPLACED);
        } catch (Throwable) {
            return self::hostFail('writable',
                "We couldn't check whether the portal's files can be replaced by the website.",
                "Please make sure the portal's files and folders are writable by the PHP user of the account that runs $d (owner of the files = the account user). The check could not read them. It is needed by the Club Portal web updater.");
        }
        if ($bad === []) {
            return self::pass('writable');
        }
        $shown = implode(', ', array_slice($bad, 0, 5)).(count($bad) > 5 ? ', and more' : '');

        return self::hostFail('writable',
            "The portal's files can't be replaced by the website: some are locked against changes.",
            "Please make the portal's files writable by the PHP user of the account that runs $d (owner of the files = the account user). Not writable: $shown. It is needed by the Club Portal web updater.");
    }

    private static function rename(UpdaterHost $h, string $d): PreCheck
    {
        $parent = self::safe(fn () => $h->stagingParent()) ?? '(the folder above the portal)';
        if (self::safe(fn () => $h->canCreateAndRename($parent)) === true) {
            return self::pass('rename');
        }
        $basedir = (string) ini_get('open_basedir');
        $why = $basedir !== '' ? " PHP's open_basedir is currently set to: $basedir." : '';

        return self::hostFail('rename',
            "The website isn't allowed to prepare the new version next to the portal's folder.",
            "Please allow PHP to create and rename folders in $parent for the account that runs $d.$why If open_basedir is set it must include that folder. It is needed by the Club Portal web updater.");
    }

    private static function disk(Manifest $m, UpdaterHost $h, string $d): PreCheck
    {
        $needed = (float) ($m->zipSize * self::UNPACK_FACTOR * self::FREE_FACTOR);
        $mb = (int) ceil($needed / 1048576);
        try {
            $free = $h->freeBytes($h->basePath());
        } catch (Throwable) {
            return self::pass('disk'); // cannot be read: the download itself fails cleanly if the space is really short
        }
        if ($free < $needed) {
            return self::hostFail('disk',
                "There isn't enough free space on your hosting account for this update (about $mb MB are needed).",
                "Please free up or add disk space (at least $mb MB free, and enough file quota) for the account that runs $d. Disk Usage in cPanel shows the account's use. It is needed by the Club Portal web updater.",
                'Ask your host or whoever installed the portal to free up space on the hosting account, or to give it more.');
        }
        if ($free < $needed * 2) {
            // Only the server's disk is visible, not the account's own limit: say so when it is close.
            return self::row('disk', true,
                "We can't see your hosting account's own storage limit, and free space looks tight.",
                "Ask your host or whoever installed the portal to check that the account has at least $mb MB free.",
                "Please check Disk Usage in cPanel for the account that runs $d: at least $mb MB should be free before the Club Portal web updater runs.",
                false);
        }

        return self::pass('disk');
    }

    private static function backupDatabase(UpdaterHost $h, string $d): PreCheck
    {
        if (! self::needsDatabaseBackupTick()) {
            return self::row('backup_database', true, '', null, null, false);
        }
        $driver = self::safe(fn () => $h->databaseDriver()) ?? '';
        $why = in_array($driver, ['mysql', 'mariadb'], true)
            ? 'The portal could not run mysqldump on this server.'
            : 'The portal cannot back up this kind of database by itself.';

        return self::row('backup_database', false,
            "This host can't copy your club's data by itself, so the host or whoever installed the portal must take a copy before the update.",
            'Ask them to take a copy of the database before updating.',
            "Please take a full backup of the database for the account that runs $d (cPanel, Backup, or phpMyAdmin, Export) before the Club Portal web updater runs. $why",
            false);
    }

    /**
     * The release author's text is shown as written, in this row's fix and in the host message, never in the message
     * line (so the screen's own wording stays plain).
     */
    private static function manualAction(Manifest $m, string $d): PreCheck
    {
        if (! self::needsManualActionTick($m)) {
            return self::row('manual_action', true, '', null, null, false);
        }

        return self::row('manual_action', false,
            'This update needs one thing done first by your host or whoever installed the portal.',
            'They need to: '.$m->manualAction,
            "For the account that runs $d: before the Club Portal is updated to version {$m->version}, please do the following: {$m->manualAction}",
            false);
    }
}
