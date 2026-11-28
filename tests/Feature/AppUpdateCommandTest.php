<?php

namespace Tests\Feature;

use App\Console\Commands\AppUpdate;
use App\Console\Commands\BackupRun;
use App\Models\AuditLog;
use App\Models\User;
use ClubPortalUpdater\Context;
use ClubPortalUpdater\Lock;
use ClubPortalUpdater\Swap;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Tests\Unit\Updater\UpdateRunFixture;

require_once __DIR__.'/../../public/update/lib/load.php';

class AppUpdateCommandTest extends TestCase
{
    use UpdateRunFixture;

    private const MANUAL_LINE = 'Run: php artisan migrate --force && php artisan optimize && php artisan up';

    private string $originalBase;

    /** @var list<array{0: list<string>, 1: string}> */
    private array $childCalls = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalBase = $this->app->basePath();
        $this->makeSite();
        $this->app->setBasePath($this->site);
        config(['updates.release_file' => $this->site.'/release-files.json']);
        Cache::flush();
        $this->childCalls = [];

        AppUpdate::$tune = function (Context $c): void {
            $f = $this->context();
            $c->boot = $f->boot;
            $c->fetch = $f->fetch;
            $c->opcacheReset = $f->opcacheReset;
            $c->opcacheWait = $f->opcacheWait;
        };
        AppUpdate::$child = function (array $argv, string $cwd): array {
            $this->childCalls[] = [$argv, $cwd];
            // The guard is lifted before a child runs, or the new artisan would stop at its first line.
            $this->assertFileDoesNotExist($this->site.'/storage/framework/updating');
            $this->assertFileExists($this->site.'/storage/framework/down', 'members still see the pause page');

            return ['code' => 0, 'output' => "ok\n"];
        };
        BackupRun::$processAvailable = static fn (): bool => true;
    }

    protected function tearDown(): void
    {
        AppUpdate::$tune = null;
        AppUpdate::$child = null;
        AppUpdate::$opcacheTimestamps = null;
        BackupRun::$processAvailable = null;
        $this->app->setBasePath($this->originalBase);
        $this->cleanTempDirs();
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function manifest(string $version = '2.2.1'): array
    {
        return [
            'format' => 1, 'app' => 'clubportal', 'version' => $version, 'tag' => 'v'.$version,
            'released_at' => '2026-12-01T00:00:00Z',
            'zip' => [
                'name' => "clubportal-$version.zip",
                'url' => "https://github.com/amirsubhi/clubcollection/releases/download/v$version/clubportal-$version.zip",
                'size' => (int) filesize($this->zip), 'sha256' => hash_file('sha256', $this->zip),
            ],
            'php_requirement' => '>=8.4.1', 'min_from_version' => '2.0.0', 'security' => false,
            'last_security_version' => '2.2.0', 'summary' => null, 'manual_action' => null,
            'whats_new' => ['Receipts now show the club\'s logo'], 'notes_markdown' => 'Notes',
            'notes_url' => "https://github.com/amirsubhi/clubcollection/releases/tag/v$version",
            'signature' => null,
        ];
    }

    private function fakeGithub(string $version = '2.2.1'): void
    {
        Http::fake(['*' => Http::response($this->manifest($version))]);
    }

    private function superAdmin(): User
    {
        return User::factory()->create(['role' => 'super_admin', 'name' => 'Aminah']);
    }

    /** The command starts its own job: drop the fixture's ready job. */
    private function noJob(): void
    {
        unlink($this->dir.'/job.json');
    }

    public function test_check_prints_installed_latest_and_available(): void
    {
        $this->fakeGithub('2.2.1');

        $this->artisan('app:update', ['--check' => true])
            ->expectsOutputToContain('Installed: 2.2.0')
            ->expectsOutputToContain('Latest: 2.2.1')
            ->expectsOutputToContain('Update available: yes')
            ->assertExitCode(0);

        $this->assertSame('ready', $this->job()['status'], '--check never touches the job');
    }

    public function test_check_when_up_to_date(): void
    {
        $this->fakeGithub('2.2.0');

        $this->artisan('app:update', ['--check' => true])
            ->expectsOutputToContain('Latest: 2.2.0')
            ->expectsOutputToContain('Update available: no')
            ->assertExitCode(0);
    }

    public function test_refuses_when_a_job_is_running(): void
    {
        $this->fakeGithub();
        $this->superAdmin();
        $this->writeReadyJob(['status' => 'running']);

        $this->artisan('app:update', ['--yes' => true])
            ->expectsOutputToContain('An update is already running (in a browser or another terminal). Try again later.')
            ->assertExitCode(1);

        $this->assertSame(0, $this->laravel->boots);
        $this->assertFileDoesNotExist($this->dir.'/journal.json');
    }

    public function test_refuses_when_the_lock_is_held(): void
    {
        $this->fakeGithub();
        $this->superAdmin();
        $this->noJob();
        $lock = Lock::acquire($this->dir);
        try {
            $this->artisan('app:update', ['--yes' => true])
                ->expectsOutputToContain('An update is already running (in a browser or another terminal). Try again later.')
                ->assertExitCode(1);
        } finally {
            $lock?->release();
        }

        $this->assertFileDoesNotExist($this->dir.'/job.json');
    }

    public function test_refuses_when_a_failed_update_has_not_been_looked_at(): void
    {
        $this->fakeGithub();
        $this->superAdmin();
        $this->noJob();
        file_put_contents($this->dir.'/blocked', '1');

        $this->artisan('app:update', ['--yes' => true])
            ->expectsOutputToContain('switched off until a failed update has been looked at')
            ->assertExitCode(1);

        $this->assertFileDoesNotExist($this->dir.'/job.json');
    }

    public function test_needs_a_super_admin_to_own_the_job(): void
    {
        $this->fakeGithub();
        $this->noJob();

        $this->artisan('app:update', ['--yes' => true])
            ->expectsOutputToContain('no super admin')
            ->assertExitCode(1);
    }

    public function test_nothing_to_do_when_up_to_date(): void
    {
        $this->fakeGithub('2.2.0');

        $this->artisan('app:update', ['--yes' => true])
            ->expectsOutputToContain('up to date')
            ->assertExitCode(0);
    }

    public function test_declining_the_prompt_changes_nothing(): void
    {
        $this->fakeGithub();
        $this->superAdmin();
        $this->noJob();

        $this->artisan('app:update')
            ->expectsConfirmation('Update Club Portal from v2.2.0 to v2.2.1? Members will see a pause page while it runs.', 'no')
            ->assertExitCode(1);

        $this->assertFileDoesNotExist($this->dir.'/job.json');
        $this->assertOldSite();
    }

    public function test_without_processes_it_stops_after_the_switch_with_the_manual_line(): void
    {
        BackupRun::$processAvailable = static fn (): bool => false;
        $this->fakeGithub();
        $this->superAdmin();
        $this->noJob();

        $this->artisan('app:update', ['--yes' => true])
            ->expectsOutputToContain('Checking your site is ready')
            ->expectsOutputToContain('Downloading Club Portal v2.2.1')
            ->expectsOutputToContain("Saving a copy of your club's data")
            ->expectsOutputToContain('Checking the new files')
            ->expectsOutputToContain('Pausing the portal for members')
            ->expectsOutputToContain('Switching to the new version')
            ->expectsOutputToContain('first super admin')
            ->expectsOutputToContain(self::MANUAL_LINE)
            ->expectsOutputToContain("website's PHP cache is not cleared from the command line")
            ->assertExitCode(0);

        $this->assertSame('<?php // new A', $this->read('app/A.php'));
        $this->assertSame([], $this->childCalls, 'no child process is started');
        $this->assertFileDoesNotExist($this->site.'/storage/framework/updating', 'the guard is lifted so artisan works');
        $this->assertFileExists($this->site.'/storage/framework/down', 'members still see the pause page');
        $this->assertFileExists($this->site.'/storage/framework/maintenance.php');
        $this->assertFileDoesNotExist($this->dir.'/outcome.json');
        $this->assertSame('running', $this->job()['status']);
        $done = array_keys(array_filter($this->journal()->steps(), fn ($s) => $s['state'] === 'done'));
        $this->assertSame(['prechecks', 'download', 'backup', 'unpack', 'maintenance', 'swap'], $done);
        $this->assertContains('backup:run --database-only', $this->laravel->calls);
        $this->assertNotContains('migrate --force', $this->laravel->calls, 'migrate is not run in this process');
        $this->assertSame('<?php // old updater', $this->read('public/update/index.php'), 'the new updater moves in at the finish');
    }

    public function test_the_job_belongs_to_the_first_super_admin_and_is_audited(): void
    {
        BackupRun::$processAvailable = static fn (): bool => false;
        $this->fakeGithub();
        User::factory()->create(['role' => 'admin']);
        $first = $this->superAdmin();
        $this->superAdmin();
        $this->noJob();

        $this->artisan('app:update', ['--yes' => true])->assertExitCode(0);

        $this->assertSame($first->id, $this->job()['user_id']);
        $this->assertSame('Aminah', $this->job()['user_name']);
        $row = AuditLog::where('action', 'portal.update_started')->first();
        $this->assertNotNull($row);
        $this->assertSame($first->id, $row->user_id);
    }

    public function test_with_processes_it_migrates_in_a_child_and_opens_the_portal(): void
    {
        $this->fakeGithub();
        $this->superAdmin();
        $this->noJob();

        $this->artisan('app:update', ['--yes' => true])
            ->expectsOutputToContain("Updating your club's records to the new version")
            ->expectsOutputToContain('Opening the portal again')
            ->expectsOutputToContain("website's PHP cache is not cleared from the command line")
            ->doesntExpectOutputToContain(self::MANUAL_LINE)
            ->assertExitCode(0);

        $this->assertSame(
            ['migrate --force', 'optimize:clear', 'optimize'],
            array_map(fn ($c) => implode(' ', array_slice($c[0], 2)), $this->childCalls),
        );
        $this->assertSame(PHP_BINARY, $this->childCalls[0][0][0]);
        $this->assertSame($this->site.'/artisan', $this->childCalls[0][0][1]);
        $this->assertSame($this->site, $this->childCalls[0][1]);
        $this->assertNotContains('migrate --force', $this->laravel->calls);

        $this->assertNotPaused();
        $this->assertSame('finished', $this->job()['status']);
        $this->assertSame('finished', $this->outcome()['outcome']);
        $this->assertSame(['Receipts now show the club\'s logo'], $this->outcome()['whats_new']);
        $this->assertCount(1, glob($this->dir.'/history/*.json'));
        $this->assertSame('<?php // new guard', $this->read('public/update-guard.php'));
        $this->assertSame('<?php // new updater', $this->read('public/update/index.php'));
        $this->assertSame('<?php // new lib', $this->read('public/update/lib/load.php'));
        $this->assertSame('done', $this->journal()->steps()['finish']['state']);
        $this->assertFileExists(Swap::oldDir($this->site, '2.2.0').'/public/update/index.php');
    }

    public function test_a_failed_migration_in_the_child_puts_the_old_version_back_and_blocks(): void
    {
        AppUpdate::$child = function (array $argv, string $cwd): array {
            $this->childCalls[] = [$argv, $cwd];

            return $argv[2] === 'migrate'
                ? ['code' => 1, 'output' => "SQLSTATE[42S21]: Column already exists\n"]
                : ['code' => 0, 'output' => ''];
        };
        $this->fakeGithub();
        $this->superAdmin();
        $this->noJob();

        $this->artisan('app:update', ['--yes' => true])
            ->expectsOutputToContain('Restore the database')
            ->expectsOutputToContain($this->dir.'/history/')
            ->assertExitCode(1);

        $this->assertCount(1, $this->childCalls, 'nothing runs after the failed migration');
        $this->assertOldSite();
        $this->assertNotPaused();
        $this->assertSame('blocked', $this->outcome()['outcome']);
        $this->assertStringContainsString('Column already exists', $this->outcome()['report']);
        $this->assertSame('rolled_back', $this->job()['status']);
    }

    public function test_a_step_failure_prints_the_plain_message_and_the_report_path(): void
    {
        $this->laravel->failedChecks = ['PHP version: needs 8.4.1'];
        $this->fakeGithub();
        $this->superAdmin();
        $this->noJob();

        $this->artisan('app:update', ['--yes' => true])
            ->expectsOutputToContain("Your site wasn't ready to update.")
            ->expectsOutputToContain('PHP version: needs 8.4.1')
            ->expectsOutputToContain($this->dir.'/history/')
            ->assertExitCode(1);

        $this->assertSame('failed', $this->outcome()['outcome']);
        $this->assertOldSite();
        $this->assertSame('failed', $this->job()['status']);
    }

    public function test_the_php_cache_line_says_to_restart_php_when_timestamps_are_off(): void
    {
        AppUpdate::$opcacheTimestamps = static fn (): bool => false;
        BackupRun::$processAvailable = static fn (): bool => false;
        $this->fakeGithub();
        $this->superAdmin();
        $this->noJob();

        $this->artisan('app:update', ['--yes' => true])
            ->expectsOutputToContain('restart PHP')
            ->assertExitCode(0);
    }
}
