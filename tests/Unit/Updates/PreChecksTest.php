<?php

namespace Tests\Unit\Updates;

use App\Support\Updates\Manifest;
use App\Support\Updates\PreCheck;
use App\Support\Updates\PreChecks;
use App\Support\Updates\UpdaterHost;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** A host where everything is fine; each test breaks one fact. */
class FakeHost extends UpdaterHost
{
    public string $installed = '2.1.0';

    public string $php = '8.4.3';

    /** @var list<string> */
    public array $extensions = ['zip', 'curl'];

    /** @var list<string> */
    public array $blocked = [];

    public bool $validates = true;

    public bool $resetOk = true;

    public float $free = 5e9;

    /** @var list<string> */
    public array $unwritable = [];

    public bool $renameOk = true;

    public int $pending = 0;

    public bool $paying = false;

    public bool $running = false;

    public bool $needsTick = false;

    public string $driver = 'mysql';

    /** @var list<string> names of facts that throw */
    public array $throws = [];

    /** @var list<string> */
    public array $called = [];

    private function fact(string $name): void
    {
        $this->called[] = $name;
        if (in_array($name, $this->throws, true)) {
            throw new \RuntimeException("$name unreadable");
        }
    }

    public function installedVersion(): string
    {
        return $this->installed;
    }

    public function siteDomain(): string
    {
        return 'example.org';
    }

    public function basePath(): string
    {
        return '/srv/app';
    }

    public function stagingParent(): string
    {
        return '/srv';
    }

    public function phpVersion(): string
    {
        $this->fact('php');

        return $this->php;
    }

    public function extensionLoaded(string $name): bool
    {
        $this->fact('extensions');

        return in_array($name, $this->extensions, true);
    }

    public function disabledFunctions(): array
    {
        $this->fact('functions');

        return $this->blocked;
    }

    public function opcacheValidatesTimestamps(): bool
    {
        $this->fact('opcache');

        return $this->validates;
    }

    public function opcacheResetAllowed(): bool
    {
        return $this->resetOk;
    }

    public function freeBytes(string $path): float
    {
        $this->fact('disk');

        return $this->free;
    }

    public function unwritablePaths(string $base, array $relativePaths): array
    {
        $this->fact('writable');

        return $this->unwritable;
    }

    public function canCreateAndRename(string $parent): bool
    {
        $this->fact('rename');

        return $this->renameOk;
    }

    public function pendingMigrations(): int
    {
        $this->fact('migrations');

        return $this->pending;
    }

    public function paymentInProgress(): bool
    {
        $this->fact('no_payment');

        return $this->paying;
    }

    public function updateRunning(): bool
    {
        $this->fact('no_update_running');

        return $this->running;
    }

    public function databaseDriver(): string
    {
        return $this->driver;
    }

    public function databaseBackupNeedsTick(): bool
    {
        $this->fact('backup_database');

        return $this->needsTick;
    }
}

class PreChecksTest extends TestCase
{
    private FakeHost $host;

    protected function setUp(): void
    {
        $this->host = new FakeHost;
        PreChecks::$host = $this->host;
    }

    protected function tearDown(): void
    {
        PreChecks::$host = null;
    }

    private function manifest(array $over = []): Manifest
    {
        $d = [
            'format' => 1, 'app' => 'clubportal', 'version' => '2.2.0', 'tag' => 'v2.2.0',
            'released_at' => '2026-12-01T00:00:00Z',
            'zip' => [
                'name' => 'clubportal-2.2.0.zip',
                'url' => 'https://github.com/amirsubhi/clubcollection/releases/download/v2.2.0/clubportal-2.2.0.zip',
                'size' => 10 * 1048576, 'sha256' => str_repeat('ab', 32),
            ],
            'php_requirement' => '>=8.4.1', 'min_from_version' => '2.0.0', 'security' => false,
            'whats_new' => ['Something'], 'notes_markdown' => 'Notes', 'notes_url' => 'https://github.com/amirsubhi/clubcollection/releases/tag/v2.2.0',
            'signature' => null,
        ];

        return Manifest::fromJson(json_encode(array_merge($d, $over)));
    }

    private function check(string $key, bool $full = true, ?Manifest $m = null): PreCheck
    {
        foreach (PreChecks::run($m ?? $this->manifest(), $full) as $c) {
            if ($c->key === $key) {
                return $c;
            }
        }
        $this->fail("No check $key");
    }

    public function test_everything_fine_passes_with_stable_keys(): void
    {
        $checks = PreChecks::run($this->manifest(), true);

        $this->assertSame(['version_from', 'version_newer', 'php', 'extensions', 'functions', 'opcache', 'writable', 'rename',
            'disk', 'no_update_running', 'no_payment', 'migrations', 'backup_database', 'manual_action'],
            array_map(fn ($c) => $c->key, $checks));
        $this->assertTrue(PreChecks::allPass($checks));
        $this->assertFalse(PreChecks::needsDatabaseBackupTick());
    }

    public function test_installed_below_min_from_version_points_to_the_release_doc(): void
    {
        $this->host->installed = '1.9.0';
        $c = $this->check('version_from');
        $this->assertFalse($c->ok);
        $this->assertStringContainsString('docs/RELEASE.md', $c->message);
        $this->assertTrue($c->blocking);
    }

    public function test_unreadable_installed_version_says_so_plainly(): void
    {
        foreach (['dev', ''] as $v) {
            $this->host->installed = $v;
            $c = $this->check('version_from');
            $this->assertFalse($c->ok);
            $this->assertStringStartsWith("We can't tell which version your portal is on", $c->message);
            $this->assertStringNotContainsString('too old', $c->message);
            $this->assertStringContainsString('docs/RELEASE.md', $c->fix);
        }
        $this->host->throws = [];
        $this->host = new class extends FakeHost
        {
            public function installedVersion(): string
            {
                throw new \RuntimeException('x');
            }
        };
        PreChecks::$host = $this->host;
        $this->assertStringStartsWith("We can't tell", $this->check('version_from')->message);
    }

    public function test_not_newer_fails(): void
    {
        $this->host->installed = '2.2.0';
        $c = $this->check('version_newer');
        $this->assertFalse($c->ok);
        $this->assertSame('The newest version is not newer than the one you have, so there is nothing to update.', $c->message);
        $this->assertNotNull($c->fix);
    }

    public function test_php_too_old(): void
    {
        $this->host->php = '8.3.9';
        $c = $this->check('php');
        $this->assertFalse($c->ok);
        $this->assertSame('Your host runs an older PHP than this version needs (it needs PHP 8.4.1 or newer).', $c->message);
        $this->assertSame('Please switch the PHP version for the account that runs example.org to PHP 8.4.1 or newer (cPanel, MultiPHP Manager). It is needed by the Club Portal web updater.', $c->hostMessage);
    }

    public function test_php_exactly_the_requirement_passes(): void
    {
        $this->host->php = '8.4.1';
        $this->assertTrue($this->check('php')->ok);
    }

    public function test_missing_zip_extension(): void
    {
        $this->host->extensions = ['curl'];
        $c = $this->check('extensions');
        $this->assertFalse($c->ok);
        $this->assertSame('Your host has turned off a feature the updater needs (PHP zip).', $c->message);
        $this->assertSame('Please enable the PHP zip extension (ZipArchive) for the account that runs example.org. It is needed by the Club Portal web updater.', $c->hostMessage);
        $this->assertSame('Send the message below to your host, or ask whoever installed the portal to update it by hand (instructions: docs/RELEASE.md).', $c->fix);
    }

    public function test_both_extensions_missing_are_named_together(): void
    {
        $this->host->extensions = [];
        $c = $this->check('extensions');
        $this->assertSame('Your host has turned off a feature the updater needs (PHP zip, PHP curl).', $c->message);
        $this->assertStringContainsString('PHP zip extension (ZipArchive) and the PHP curl extension', $c->hostMessage);
    }

    public function test_blocked_functions(): void
    {
        $this->host->blocked = ['rename', 'copy'];
        $c = $this->check('functions');
        $this->assertFalse($c->ok);
        $this->assertSame('Your host has blocked some PHP functions the updater needs for moving and replacing files.', $c->message);
        $this->assertStringContainsString('rename, copy', $c->hostMessage);
        $this->assertStringContainsString('example.org', $c->hostMessage);
    }

    public function test_opcache_stale_and_cannot_reset(): void
    {
        $this->host->validates = false;
        $this->host->resetOk = false;
        $c = $this->check('opcache');
        $this->assertFalse($c->ok);
        $this->assertSame("Your host keeps an old copy of the portal's code in memory and doesn't let the portal refresh it, so the portal can't update itself here.", $c->message);
        $this->assertSame('Send the message below to your host, or ask whoever installed the portal to update it by hand (instructions: docs/RELEASE.md).', $c->fix);
        $this->assertStringContainsString('opcache.validate_timestamps', $c->hostMessage);
        $this->assertStringContainsString('opcache_reset', $c->hostMessage);
    }

    public function test_opcache_passes_when_either_way_out_exists(): void
    {
        $this->host->validates = false;
        $this->host->resetOk = true;
        $this->assertTrue($this->check('opcache')->ok);
        $this->host->validates = true;
        $this->host->resetOk = false;
        $this->assertTrue($this->check('opcache')->ok);
    }

    public function test_unwritable_files(): void
    {
        $this->host->unwritable = ['vendor/x/y.php'];
        $c = $this->check('writable');
        $this->assertFalse($c->ok);
        $this->assertStringContainsString('vendor/x/y.php', $c->hostMessage);
        $this->assertStringContainsString('example.org', $c->hostMessage);
    }

    public function test_cannot_rename_next_to_the_app(): void
    {
        $this->host->renameOk = false;
        $c = $this->check('rename');
        $this->assertFalse($c->ok);
        $this->assertStringContainsString('create and rename folders in /srv', $c->hostMessage);
    }

    public function test_cheap_run_skips_writable_and_rename(): void
    {
        $this->host->unwritable = ['app'];
        $this->host->renameOk = false;
        $keys = array_map(fn ($c) => $c->key, PreChecks::run($this->manifest(), false));
        $this->assertNotContains('writable', $keys);
        $this->assertNotContains('rename', $keys);
        $this->assertNotContains('writable', $this->host->called);
        $this->assertNotContains('rename', $this->host->called);
        $this->assertTrue(PreChecks::allPass(PreChecks::run($this->manifest())));
    }

    public function test_disk_fails_below_three_times_the_unpacked_size(): void
    {
        // 10 MB zip: unpacked about 30 MB, 3x = 90 MB needed.
        $this->host->free = 89 * 1048576;
        $c = $this->check('disk');
        $this->assertFalse($c->ok);
        $this->assertSame("There isn't enough free space on your hosting account for this update (about 90 MB are needed).", $c->message);
        $this->assertStringContainsString('at least 90 MB', $c->hostMessage);
    }

    public function test_disk_passes_with_room_and_warns_without_blocking_when_tight(): void
    {
        $this->host->free = 91 * 1048576; // tight
        $c = $this->check('disk');
        $this->assertTrue($c->ok);
        $this->assertFalse($c->blocking);
        $this->assertStringContainsString("can't see your hosting account's own storage limit", $c->message);
        $this->assertTrue(PreChecks::allPass(PreChecks::run($this->manifest(), true)));

        $this->host->free = 5e9;
        $this->assertSame('', $this->check('disk')->message);
    }

    public function test_update_running(): void
    {
        $this->host->running = true;
        $c = $this->check('no_update_running');
        $this->assertFalse($c->ok);
        $this->assertSame('An update is already running.', $c->message);
        $this->assertSame('Wait for it to finish, then reload this page.', $c->fix);
    }

    public function test_payment_in_progress(): void
    {
        $this->host->paying = true;
        $c = $this->check('no_payment');
        $this->assertFalse($c->ok);
        $this->assertSame('A member is paying a fee right now.', $c->message);
    }

    public function test_pending_migrations(): void
    {
        $this->host->pending = 2;
        $c = $this->check('migrations');
        $this->assertFalse($c->ok);
        $this->assertSame("A previous update to your club's records hasn't finished.", $c->message);
        $this->assertSame('Ask whoever installed the portal to finish it (the technical details are in the report).', $c->fix);
    }

    public function test_database_backup_row_is_non_blocking_and_asks_for_a_tick(): void
    {
        $this->host->needsTick = true;
        $c = $this->check('backup_database');
        $this->assertFalse($c->ok);
        $this->assertFalse($c->blocking);
        $this->assertTrue(PreChecks::needsDatabaseBackupTick());
        $this->assertTrue(PreChecks::allPass(PreChecks::run($this->manifest(), true)));
        $this->assertStringContainsString('example.org', $c->hostMessage);
        $this->assertStringContainsString('mysqldump', $c->hostMessage);
        $this->assertStringContainsString('host or whoever installed the portal', $c->message);
        $this->assertStringNotContainsString('confirm', $c->fix);
    }

    public function test_other_database_kinds_need_a_tick_without_mentioning_mysqldump(): void
    {
        $this->host->needsTick = true;
        $this->host->driver = 'pgsql';
        $c = $this->check('backup_database');
        $this->assertFalse($c->ok);
        $this->assertStringNotContainsString('mysqldump', $c->hostMessage);
    }

    public function test_button_gate_requires_both_ticks(): void
    {
        $m = $this->manifest(['manual_action' => 'Add MAIL_X=1 to .env']);
        $this->host->needsTick = true;
        $checks = PreChecks::run($m, true);

        $this->assertTrue(PreChecks::allPass($checks), 'allPass alone never gates the button');
        $this->assertTrue(PreChecks::needsManualActionTick($m));
        $this->assertFalse(PreChecks::needsManualActionTick($this->manifest()));
        $this->assertFalse(PreChecks::canStart($checks, false, false));
        $this->assertFalse(PreChecks::canStart($checks, true, false));
        $this->assertFalse(PreChecks::canStart($checks, false, true));
        $this->assertTrue(PreChecks::canStart($checks, true, true));

        // only the manual action
        $this->host->needsTick = false;
        $checks = PreChecks::run($m, true);
        $this->assertFalse(PreChecks::canStart($checks, true, false));
        $this->assertTrue(PreChecks::canStart($checks, false, true));

        // only the backup
        $this->host->needsTick = true;
        $checks = PreChecks::run($this->manifest(), true);
        $this->assertFalse(PreChecks::canStart($checks, false, true));
        $this->assertTrue(PreChecks::canStart($checks, true, false));

        // nothing needed
        $this->host->needsTick = false;
        $this->assertTrue(PreChecks::canStart(PreChecks::run($this->manifest(), true), false, false));

        // a blocking failure is never cleared by ticks
        $this->host->php = '7.4.0';
        $this->assertFalse(PreChecks::canStart(PreChecks::run($this->manifest(), true), true, true));
    }

    public function test_every_key_has_a_plain_label(): void
    {
        $this->host->needsTick = true;
        foreach ([true, false] as $broken) {
            if ($broken) {
                $this->host->php = '7.0.0';
                $this->host->running = true;
            }
            foreach (PreChecks::run($this->manifest(['manual_action' => 'x']), true) as $c) {
                $this->assertNotSame('', $c->label, $c->key);
                foreach (['opcache', 'migrat', 'swap', 'journal', 'maintenance mode', 'unpack'] as $word) {
                    $this->assertStringNotContainsStringIgnoringCase($word, $c->label, $c->key);
                }
            }
        }
        $this->assertCount(14, PreChecks::LABELS);
    }

    public function test_unreadable_function_and_writable_checks_make_no_specific_claims(): void
    {
        $this->host->throws = ['functions', 'writable'];
        $f = $this->check('functions');
        $this->assertFalse($f->ok);
        $this->assertStringContainsString("couldn't check", $f->message);
        $this->assertStringContainsString('curl_init', $f->hostMessage);
        $w = $this->check('writable');
        $this->assertStringContainsString("couldn't check", $w->message);
        $this->assertStringNotContainsString('Not writable', $w->hostMessage);
    }

    public function test_fixes_do_not_send_the_reader_into_cpanel(): void
    {
        $this->host->free = 1;
        $this->host->needsTick = true;
        $this->host->throws = [];
        foreach (PreChecks::run($this->manifest(['manual_action' => 'x']), true) as $c) {
            if (! $c->ok) {
                $this->assertStringNotContainsStringIgnoringCase('cpanel', $c->message.$c->fix, $c->key);
            }
        }
        $this->host->free = 95 * 1048576;
        $tight = $this->check('disk');
        $this->assertStringNotContainsStringIgnoringCase('cpanel', $tight->message.$tight->fix);
        $this->assertStringContainsString('cPanel', $tight->hostMessage);
    }

    public function test_manual_action_row_names_who_must_act(): void
    {
        $m = $this->manifest(['manual_action' => 'Add MAIL_X=1 to .env']);
        $c = $this->check('manual_action', true, $m);
        $this->assertFalse($c->ok);
        $this->assertStringContainsString('your host or whoever installed the portal', $c->message);
        $this->assertStringContainsString('Add MAIL_X=1 to .env', $c->fix);
        $this->assertStringContainsString('example.org', $c->hostMessage);
        $this->assertTrue($this->check('manual_action')->ok);
    }

    /** @return iterable<string, array{string}> */
    public static function hostFixable(): iterable
    {
        foreach (['php', 'extensions', 'functions', 'opcache', 'writable', 'rename', 'disk'] as $k) {
            yield $k => [$k];
        }
    }

    #[DataProvider('hostFixable')]
    public function test_host_messages(string $key): void
    {
        $this->host->php = '7.4.0';
        $this->host->extensions = [];
        $this->host->blocked = ['rename'];
        $this->host->validates = false;
        $this->host->resetOk = false;
        $this->host->unwritable = ['app'];
        $this->host->renameOk = false;
        $this->host->free = 1;

        $c = $this->check($key);
        $this->assertFalse($c->ok);
        $this->assertNotNull($c->hostMessage);
        $this->assertStringContainsString('example.org', $c->hostMessage);
        $this->assertStringContainsString('Club Portal web updater', $c->hostMessage);
        $this->assertNotNull($c->fix);
    }

    public function test_no_jargon_on_screen(): void
    {
        $this->host->installed = '1.0.0';
        $this->host->php = '7.4.0';
        $this->host->extensions = [];
        $this->host->blocked = ['rename'];
        $this->host->validates = false;
        $this->host->resetOk = false;
        $this->host->unwritable = ['app'];
        $this->host->renameOk = false;
        $this->host->free = 1;
        $this->host->pending = 1;
        $this->host->paying = true;
        $this->host->running = true;
        $this->host->needsTick = true;

        $failed = 0;
        // The release author's own text is shown as written in the manual_action fix and host message only.
        $author = 'Run the migration and clear the opcache';
        foreach (PreChecks::run($this->manifest(['manual_action' => $author]), true) as $c) {
            if ($c->key === 'manual_action') {
                $this->assertStringContainsString($author, $c->fix);
                $this->assertStringContainsString($author, $c->hostMessage);
                $this->assertStringNotContainsString($author, $c->message);
                $this->assertStringNotContainsStringIgnoringCase('opcache', $c->message);
            }
            if ($c->ok) {
                continue;
            }
            $failed++;
            $this->assertNotNull($c->fix, "{$c->key} has a fix");
            foreach ($c->key === 'manual_action' ? [$c->message] : [$c->message, $c->fix] as $line) {
                foreach (['opcache', 'migrat', 'swap', 'journal', 'maintenance mode', 'unpack'] as $word) {
                    $this->assertStringNotContainsStringIgnoringCase($word, $line, "{$c->key}: $line");
                }
            }
        }
        $this->assertSame(13, $failed);
        foreach (PreChecks::run($this->manifest(), true) as $c) {
            $this->assertStringNotContainsStringIgnoringCase('cpanel', $c->message.$c->fix, $c->key);
        }
    }

    public function test_an_unreadable_fact_is_never_unknown(): void
    {
        foreach (['php', 'extensions', 'functions', 'opcache', 'writable', 'rename', 'disk', 'no_update_running', 'no_payment', 'migrations', 'backup_database'] as $fact) {
            $this->host = new FakeHost;
            $this->host->throws = [$fact];
            PreChecks::$host = $this->host;
            $checks = PreChecks::run($this->manifest(), true);
            $this->assertCount(14, $checks, $fact);
            foreach ($checks as $c) {
                $this->assertIsBool($c->ok);
                if (! $c->ok) {
                    $this->assertNotNull($c->fix, "$fact: {$c->key} fails with a fix");
                    $this->assertNotSame('', $c->message);
                }
            }
        }
        $this->host = new FakeHost;
        $this->host->throws = ['disk'];
        PreChecks::$host = $this->host;
        $this->assertTrue($this->check('disk')->ok, 'free space that cannot be read is a pass with no line');
        $this->assertSame('', $this->check('disk')->message);
    }

    public function test_real_host_probe_creates_renames_and_cleans_up(): void
    {
        $dir = sys_get_temp_dir().'/pc-'.bin2hex(random_bytes(4));
        mkdir($dir);
        $real = new UpdaterHost;

        $this->assertTrue($real->canCreateAndRename($dir));
        $this->assertSame([], scandir($dir, SCANDIR_SORT_ASCENDING) ? array_values(array_diff(scandir($dir), ['.', '..'])) : ['?']);
        $this->assertFalse($real->canCreateAndRename($dir.'/missing'));

        mkdir("$dir/app");
        file_put_contents("$dir/app/a.php", 'x');
        $this->assertSame([], $real->unwritablePaths($dir, ['app', 'nothing-here']));

        unlink("$dir/app/a.php");
        rmdir("$dir/app");
        rmdir($dir);
    }
}
