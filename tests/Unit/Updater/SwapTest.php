<?php

namespace Tests\Unit\Updater;

use ClubPortalUpdater\Journal;
use ClubPortalUpdater\Swap;
use ClubPortalUpdater\Unpack;
use ClubPortalUpdater\UpdaterError;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../../public/update/lib/load.php';

class SwapTest extends TestCase
{
    use Support;

    private const CPANEL = "# php -- BEGIN cPanel-generated handler, do not edit\nAddHandler x .php\n# php -- END cPanel-generated handler, do not edit\n";

    private string $root;

    private string $app;

    private string $staging;

    private string $oldDir;

    private string $journalDir;

    /** @var array<string, string> */
    private array $oldFiles;

    /** @var array<string, string> */
    private array $newFiles;

    protected function setUp(): void
    {
        $this->root = $this->tempDir();
        $this->app = $this->root.'/site';
        $this->staging = $this->root.'/.clubportal-update-2.3.0';
        $this->oldDir = $this->root.'/.clubportal-old-2.2.0';
        $this->journalDir = $this->root.'/journal';

        $this->oldFiles = [
            'artisan' => "#!/usr/bin/env php\nold\n",
            'release.json' => '{"version":"2.2.0"}',
            'release-files.json' => '{"old":true}',
            'composer.json' => '{"old":1}',
            'removed-in-new.txt' => 'only old',
            'app/Models/User.php' => '<?php // old user',
            'app/Gone.php' => '<?php // gone',
            'bootstrap/app.php' => '<?php // old boot',
            'bootstrap/cache/.gitignore' => "*\n!.gitignore\n",
            'vendor/pkg/A.php' => '<?php // A old',
            'database/migrations/001_a.php' => '<?php // m1',
            'database/seeders/S.php' => '<?php // s',
            'public/index.php' => '<?php // old index',
            'public/sw.js' => '// old sw',
            'public/old-icon.png' => 'oldicon',
            'public/icons/a.png' => 'oldA',
            'public/build/app-old.js' => 'old build',
            'public/.htaccess' => "Options -Indexes\n".self::CPANEL."# old tail\n",
            'public/update/index.php' => '<?php // old updater',
            'public/update-guard.php' => '<?php // old guard',
        ];
        $this->newFiles = [
            'artisan' => "#!/usr/bin/env php\nnew\n",
            'release.json' => '{"version":"2.3.0"}',
            'composer.json' => '{"new":1}',
            'brand-new-root.txt' => 'fresh root file',
            'app/Models/User.php' => '<?php // new user',
            'app/Models/Club.php' => '<?php // club',
            'bootstrap/app.php' => '<?php // new boot',
            'bootstrap/cache/.gitignore' => "*\n!.gitignore\n",
            'vendor/pkg/A.php' => '<?php // A new',
            'vendor/pkg/B.php' => '<?php // B new',
            'database/migrations/001_a.php' => '<?php // m1',
            'database/migrations/002_b.php' => '<?php // m2',
            'public/index.php' => '<?php // new index',
            'public/sw.js' => '// new sw',
            'public/new-icon.png' => 'newicon',
            'public/icons/a.png' => 'newA',
            'public/icons/b.png' => 'newB',
            'public/fonts/f.woff' => 'font',
            'public/build/app-new.js' => 'new build',
            'public/.htaccess' => "RewriteEngine On\n",
            'public/update/index.php' => '<?php // new updater',
            'public/update-guard.php' => '<?php // new guard',
        ];

        $this->writeTree($this->app, $this->oldFiles + [
            '.env' => 'APP_KEY=secret',
            '.env.backup' => 'old env',
            'storage/app/updater/job.json' => '{}',
            'storage/logs/laravel.log' => 'log',
            'database/database.sqlite' => 'SQLITE-BYTES',
            'database/database.sqlite-wal' => 'WAL-BYTES',
            'database/.gitignore' => '*.sqlite*',
            'public/.user.ini' => 'upload_max=64M',
            'app/Http/.user.ini' => 'local ini',
            'app/Http/Kernel.php' => '<?php // not in the old release list',
            'error_log' => 'host log',
            'vendor/pkg/local.txt' => 'site local in vendor',
        ]);
        $this->writeTree($this->staging, $this->newFiles + ['release-files.json' => '{"new":true}']);
        mkdir($this->journalDir);
        unset($this->oldFiles['release-files.json']);
    }

    protected function tearDown(): void
    {
        $this->cleanTempDirs();
    }

    /** @param array<string, string> $files */
    private function writeTree(string $dir, array $files): void
    {
        foreach ($files as $rel => $body) {
            $path = "$dir/$rel";
            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0755, true);
            }
            file_put_contents($path, $body);
        }
        if (is_file("$dir/artisan")) {
            chmod("$dir/artisan", 0755);
        }
    }

    /** @return array<string, string> relative path => "mode:contents" of every file, empty folders as "dir" */
    private function tree(string $dir): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $f) {
            $rel = substr($f->getPathname(), strlen($dir) + 1);
            $out[$rel] = $f->isDir() ? 'dir' : sprintf('%o', $f->getPerms() & 0777).':'.file_get_contents($f->getPathname());
        }
        ksort($out);

        return $out;
    }

    private function journal(): Journal
    {
        return Journal::open($this->journalDir);
    }

    private function plan(): array
    {
        return Swap::plan($this->app, $this->staging, $this->oldFiles, $this->newFiles);
    }

    private function read(string $rel): string
    {
        return file_get_contents($this->app.'/'.$rel);
    }

    public function test_after_run_the_app_is_the_new_release_plus_kept_paths(): void
    {
        Swap::run($this->journal(), $this->plan(), $this->oldDir);

        foreach ($this->newFiles as $rel => $body) {
            if ($rel === 'public/.htaccess') {
                continue;
            }
            if (str_starts_with($rel, 'public/update')) {
                $this->assertStringContainsString('old', $this->read($rel), "$rel stays for the finish step");

                continue;
            }
            $this->assertSame($body, $this->read($rel), $rel);
        }
        $this->assertSame('{"new":true}', $this->read('release-files.json'));
        $this->assertSame(self::CPANEL."RewriteEngine On\n", $this->read('public/.htaccess'));
        $this->assertSame(0755, fileperms($this->app.'/artisan') & 0777);
        $this->assertFileDoesNotExist($this->app.'/removed-in-new.txt');
        $this->assertFileDoesNotExist($this->app.'/app/Gone.php');
        $this->assertFileDoesNotExist($this->app.'/public/old-icon.png');
        $this->assertFileDoesNotExist($this->app.'/public/build/app-old.js');
        $this->assertSame('<?php // old updater', $this->read('public/update/index.php'));
        $this->assertSame('<?php // old guard', $this->read('public/update-guard.php'));
    }

    public function test_files_only_in_the_old_release_end_up_in_the_old_folder(): void
    {
        Swap::run($this->journal(), $this->plan(), $this->oldDir);

        $this->assertSame('only old', file_get_contents($this->oldDir.'/removed-in-new.txt'));
        $this->assertSame('<?php // gone', file_get_contents($this->oldDir.'/app/Gone.php'));
        $this->assertSame('oldicon', file_get_contents($this->oldDir.'/public/old-icon.png'));
        $this->assertSame('old build', file_get_contents($this->oldDir.'/public/build/app-old.js'));
        $this->assertSame('oldA', file_get_contents($this->oldDir.'/public/icons/a.png'));
        $this->assertStringContainsString('# old tail', file_get_contents($this->oldDir.'/public/.htaccess'));
        $this->assertFileDoesNotExist($this->oldDir.'/public/update/index.php');
    }

    public function test_env_storage_and_the_database_are_untouched(): void
    {
        $keep = array_filter($this->tree($this->app), fn (string $k) => preg_match('#^(\.env|storage|database/database|database/\.gitignore)#', $k) === 1, ARRAY_FILTER_USE_KEY);
        $plan = $this->plan();
        foreach ($plan as $m) {
            $this->assertDoesNotMatchRegularExpression('#^(\.env|storage|database$|database/database|public$|public/update)#', $m['rel']);
        }

        Swap::run($this->journal(), $plan, $this->oldDir);

        $after = array_filter($this->tree($this->app), fn (string $k) => preg_match('#^(\.env|storage|database/database|database/\.gitignore)#', $k) === 1, ARRAY_FILTER_USE_KEY);
        $this->assertSame($keep, $after);
        $this->assertFileExists($this->app.'/database/migrations/002_b.php');
        $this->assertFileExists($this->app.'/public/fonts/f.woff');
    }

    public function test_site_local_files_and_sqlite_survive(): void
    {
        $plan = $this->plan();
        $listed = Swap::siteLocalFiles($this->app, $this->oldFiles, $plan);
        Swap::run($this->journal(), $plan, $this->oldDir);

        $this->assertContains('app/Http/.user.ini', $listed);
        $this->assertContains('app/Http/Kernel.php', $listed);
        $this->assertContains('vendor/pkg/local.txt', $listed);
        $this->assertSame($listed, array_values(array_unique($listed)));
        foreach ($listed as $path) {
            $this->assertStringNotContainsString('sqlite', $path);
        }
        $this->assertSame('local ini', $this->read('app/Http/.user.ini'));
        $this->assertSame('site local in vendor', $this->read('vendor/pkg/local.txt'));
        $this->assertSame('upload_max=64M', $this->read('public/.user.ini'));
        $this->assertSame('host log', $this->read('error_log'));
        $this->assertSame('SQLITE-BYTES', $this->read('database/database.sqlite'));
        $this->assertSame('WAL-BYTES', $this->read('database/database.sqlite-wal'));
        $this->assertSame('<?php // new user', $this->read('app/Models/User.php'), 'release files win');
    }

    public function test_a_site_local_file_never_replaces_a_release_file_and_a_second_call_lists_the_same(): void
    {
        file_put_contents($this->app.'/app/Models/Club.php', 'LOCAL CLUB');
        $plan = $this->plan();

        $first = Swap::siteLocalFiles($this->app, $this->oldFiles, $plan);
        $second = Swap::siteLocalFiles($this->app, $this->oldFiles, $plan);

        $this->assertNotContains('app/Models/Club.php', $first, 'a clash is won by the release file and is not copied');
        $this->assertSame($first, $second);
        $this->assertSame('<?php // club', file_get_contents($this->staging.'/app/Models/Club.php'));
    }

    public function test_site_local_files_in_a_removed_bootstrap_cache_are_not_carried(): void
    {
        file_put_contents($this->app.'/bootstrap/cache/config.php', '<?php // cached');

        $listed = Swap::siteLocalFiles($this->app, $this->oldFiles, $this->plan());

        $this->assertNotContains('bootstrap/cache/config.php', $listed);
        $this->assertFileDoesNotExist($this->staging.'/bootstrap/cache/config.php');
    }

    public function test_every_move_is_recorded_in_the_journal_and_the_journal_is_touched(): void
    {
        $now = 1000;
        $j = Journal::open($this->journalDir, function () use (&$now) {
            return $now;
        });
        $plan = $this->plan();
        $seenPending = null;
        $idleAfterMove = [];

        Swap::run($j, $plan, $this->oldDir, function (int $i, string $phase) use ($j, &$now, &$seenPending, &$idleAfterMove, $plan) {
            if ($phase === 'before') {
                $now += 500; // the idle clock would be 500 s old here unless the previous move touched it
                $idleAfterMove[$i] = $j->idleSeconds();
                if ($i === 2) {
                    $swap = $j->get('swap');
                    $seenPending = count($swap['moves']) === count($plan) && $swap['moves'][2]['state'] === 'pending';
                }
            }
        });

        $this->assertTrue($seenPending, 'the whole list is in the journal before move 2 is done');
        $this->assertSame(500, $idleAfterMove[1], 'move 0 and 1 touched the clock, then 500 s passed');
        $swap = Journal::open($this->journalDir)->get('swap');
        $this->assertSame('done', $swap['state']);
        $this->assertSame(array_fill(0, count($plan), 'done'), array_column($swap['moves'], 'state'));
        $this->assertSame(0, $j->idleSeconds());
    }

    public function test_rollback_after_a_complete_swap_restores_the_old_tree_byte_for_byte(): void
    {
        $before = $this->tree($this->app);
        Swap::run($this->journal(), $this->plan(), $this->oldDir);
        $this->assertNotSame($before, $this->tree($this->app));

        Swap::rollback($this->journal());

        $this->assertSame($before, $this->tree($this->app));
        $this->assertDirectoryDoesNotExist($this->oldDir);
        $this->assertSame('<?php // new user', file_get_contents($this->staging.'/app/Models/User.php'), 'the staged release is back in staging');
    }

    public function test_kill_after_every_move_then_run_again_completes_the_swap(): void
    {
        $count = count($this->plan());
        $this->assertGreaterThan(20, $count);
        $reference = $this->completedTree();

        foreach (['before', 'performed', 'done'] as $phase) {
            for ($n = 0; $n < $count; $n++) {
                $this->setUp();
                $this->killAt($n, $phase);

                Swap::run($this->journal(), $this->plan(), $this->oldDir); // $plan is ignored: the journal's list wins

                $this->assertSame($reference, $this->tree($this->app), "re-run after kill at move $n ($phase)");
                $this->cleanTempDirs();
            }
        }
    }

    public function test_kill_after_every_move_then_rollback_restores_the_old_tree_byte_for_byte(): void
    {
        $count = count($this->plan());

        foreach (['before', 'performed', 'done'] as $phase) {
            for ($n = 0; $n < $count; $n++) {
                $this->setUp();
                $before = $this->tree($this->app);
                $this->killAt($n, $phase);

                Swap::rollback($this->journal());
                $this->assertSame($before, $this->tree($this->app), "rollback after kill at move $n ($phase)");

                Swap::rollback($this->journal()); // idempotent
                $this->assertSame($before, $this->tree($this->app), "second rollback after kill at move $n ($phase)");
                $this->cleanTempDirs();
            }
        }
    }

    public function test_rollback_that_is_itself_interrupted_can_be_repeated(): void
    {
        $before = $this->tree($this->app);
        Swap::run($this->journal(), $this->plan(), $this->oldDir);
        // A rollback killed half way: undo the last moves by hand, leave the journal at 'rolling_back'.
        $j = $this->journal();
        $swap = $j->get('swap');
        $swap['state'] = 'rolling_back';
        $j->set('swap', $swap);
        rename($this->app.'/vendor', $this->staging.'/vendor');
        rename($this->oldDir.'/vendor', $this->app.'/vendor');

        Swap::rollback($this->journal());

        $this->assertSame($before, $this->tree($this->app));
    }

    public function test_run_refuses_to_continue_once_a_rollback_started(): void
    {
        $this->killAt(3, 'done');
        $j = $this->journal();
        $swap = $j->get('swap');
        $swap['state'] = 'rolling_back';
        $j->set('swap', $swap);

        $this->expectException(UpdaterError::class);
        Swap::run($this->journal(), $this->plan(), $this->oldDir);
    }

    public function test_rollback_restores_htaccess_and_removes_cached_framework_files(): void
    {
        $htaccess = $this->read('public/.htaccess');
        Swap::run($this->journal(), $this->plan(), $this->oldDir);
        $this->assertNotSame($htaccess, $this->read('public/.htaccess'));
        file_put_contents($this->app.'/bootstrap/cache/config.php', '<?php // new cached config');
        file_put_contents($this->app.'/bootstrap/cache/packages.php', '<?php // new packages');

        Swap::rollback($this->journal());

        $this->assertSame($htaccess, $this->read('public/.htaccess'));
        $this->assertFileDoesNotExist($this->app.'/bootstrap/cache/config.php');
        $this->assertFileDoesNotExist($this->app.'/bootstrap/cache/packages.php');
        $this->assertFileExists($this->app.'/bootstrap/cache/.gitignore');
    }

    public function test_rollback_without_a_recorded_swap_does_nothing(): void
    {
        $before = $this->tree($this->app);

        Swap::rollback($this->journal());

        $this->assertSame($before, $this->tree($this->app));
    }

    public function test_rollback_reports_a_lost_folder_but_restores_the_rest(): void
    {
        Swap::run($this->journal(), $this->plan(), $this->oldDir);
        Unpack::removeTree($this->oldDir.'/vendor'); // someone deleted a saved folder
        Unpack::removeTree($this->app.'/vendor');

        try {
            Swap::rollback($this->journal());
            $this->fail('expected an UpdaterError');
        } catch (UpdaterError $e) {
            $this->assertStringContainsString('vendor', $e->getMessage());
        }
        $this->assertSame('<?php // old user', $this->read('app/Models/User.php'));
        $this->assertDirectoryExists($this->oldDir, 'the old folder is kept when not everything was restored');
    }

    public function test_run_refuses_a_non_empty_old_folder_it_did_not_create(): void
    {
        mkdir($this->oldDir);
        file_put_contents($this->oldDir.'/stray', 'x');

        $this->expectException(UpdaterError::class);
        Swap::run($this->journal(), $this->plan(), $this->oldDir);
    }

    public function test_plan_refuses_unsafe_release_paths(): void
    {
        $this->expectException(UpdaterError::class);
        Swap::plan($this->app, $this->staging, ['../evil' => 'x'], []);
    }

    public function test_plan_and_site_local_refuse_an_empty_old_list(): void
    {
        foreach ([fn () => Swap::plan($this->app, $this->staging, [], $this->newFiles),
            fn () => Swap::siteLocalFiles($this->app, [], $this->plan())] as $call) {
            try {
                $call();
                $this->fail('expected an UpdaterError');
            } catch (UpdaterError $e) {
                $this->assertStringContainsString('cannot be updated from the admin', $e->getMessage());
            }
        }
        $this->assertFileDoesNotExist($this->staging.'/app/Gone.php');
    }

    public function test_plan_refuses_empty_and_dot_segments(): void
    {
        foreach (['/abs/x', 'a//b', './a', 'public//update/index.php', 'public/./update/index.php', 'a/../b', 'a\\b'] as $bad) {
            foreach ([[[$bad => 'x'], ['ok' => 'x']], [['ok' => 'x'], [$bad => 'x']]] as [$old, $new]) {
                try {
                    Swap::plan($this->app, $this->staging, $old, $new);
                    $this->fail("accepted $bad");
                } catch (UpdaterError) {
                    $this->addToAssertionCount(1);
                }
            }
        }
    }

    public function test_run_after_rolled_back_records_a_fresh_plan_and_completes(): void
    {
        $reference = $this->completedTree();
        $before = $this->tree($this->app);
        Swap::run($this->journal(), $this->plan(), $this->oldDir);
        Swap::rollback($this->journal());
        $this->assertSame($before, $this->tree($this->app));
        $this->assertSame('rolled_back', $this->journal()->get('swap')['state']);

        Swap::run($this->journal(), $this->plan(), $this->oldDir);

        $this->assertSame($reference, $this->tree($this->app));
        $this->assertSame('done', $this->journal()->get('swap')['state']);
    }

    public function test_old_folder_is_next_to_the_app(): void
    {
        $this->assertSame($this->root.'/.clubportal-old-2.2.0', Swap::oldDir($this->app, '2.2.0'));
    }

    public function test_old_folder_falls_back_under_storage_when_the_parent_is_not_writable(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root can write everywhere');
        }
        chmod($this->root, 0555);
        try {
            $old = Swap::oldDir($this->app, '2.2.0');
            $staging = Unpack::stagingDir($this->app, '2.3.0');
        } finally {
            chmod($this->root, 0755);
        }

        $this->assertSame($this->app.'/storage/app/updater/old-2.2.0', $old);
        $this->assertSame(dirname($staging), dirname($old));
    }

    // ---------------------------------------------------------------- helpers for the kill tests

    /** Runs the swap in a scratch copy of the fixture and returns the finished tree, for comparison. */
    private function completedTree(): array
    {
        Swap::run($this->journal(), $this->plan(), $this->oldDir);
        $tree = $this->tree($this->app);
        $this->cleanTempDirs();
        $this->setUp();

        return $tree;
    }

    private function killAt(int $move, string $phase): void
    {
        try {
            Swap::run($this->journal(), $this->plan(), $this->oldDir, function (int $i, string $p) use ($move, $phase) {
                if ($i === $move && $p === $phase) {
                    throw new \RuntimeException('killed');
                }
            });
            $this->fail('the kill hook did not fire');
        } catch (\RuntimeException $e) {
            $this->assertSame('killed', $e->getMessage());
        }
    }
}
