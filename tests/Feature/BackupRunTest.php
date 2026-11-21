<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Club;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

/**
 * G2: `php artisan backup:run` zips a consistent SQLite copy and the
 * uploaded files into storage/app/backups, never .env or APP_KEY, keeps the
 * newest --keep zips and writes a system.backup audit entry.
 */
class BackupRunTest extends TestCase
{
    private string $root;

    private string $defaultConnection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/backup-'.Str::random(8);
        File::ensureDirectoryExists("{$this->root}/storage/app/private/clubs/1/attachments/2026");
        File::ensureDirectoryExists("{$this->root}/storage/app/public/logos");
        file_put_contents("{$this->root}/storage/app/private/clubs/1/attachments/2026/slip.bin", 'encrypted-slip');
        file_put_contents("{$this->root}/storage/app/private/.gitignore", "*\n");
        file_put_contents("{$this->root}/storage/app/public/logos/club.png", 'logo');
        $this->app->useStoragePath("{$this->root}/storage");
        config([
            'app.name' => 'Club Portal',
            'filesystems.disks.local.root' => "{$this->root}/storage/app/private",
            'filesystems.disks.public.root' => "{$this->root}/storage/app/public",
        ]);

        // A real SQLite file outside the test transaction (VACUUM INTO can't run inside one).
        $this->defaultConnection = config('database.default');
        touch("{$this->root}/db.sqlite");
        config([
            'database.connections.backup_scratch' => ['driver' => 'sqlite', 'database' => "{$this->root}/db.sqlite", 'prefix' => '', 'foreign_key_constraints' => true],
            'database.default' => 'backup_scratch',
        ]);
        Artisan::call('migrate', ['--database' => 'backup_scratch', '--force' => true]);
        Club::factory()->create(['name' => 'Backed Up Club']);
    }

    protected function tearDown(): void
    {
        // RefreshDatabase rolls back "the default connection": point it back first.
        config(['database.default' => $this->defaultConnection]);
        DB::purge('backup_scratch');
        $root = $this->root;
        parent::tearDown();
        File::deleteDirectory($root);
    }

    private function backups(): array
    {
        return array_values(array_map('basename', glob("{$this->root}/storage/app/backups/*.zip") ?: []));
    }

    public function test_backup_zips_database_and_private_files_without_env(): void
    {
        $this->artisan('backup:run')->expectsOutputToContain('Backup written')->assertSuccessful();

        $names = $this->backups();
        $this->assertCount(1, $names);
        $this->assertMatchesRegularExpression('/^club-portal-\d{8}-\d{6}\.zip$/', $names[0]);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open("{$this->root}/storage/app/backups/{$names[0]}"));
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entries[] = $zip->getNameIndex($i);
        }
        sort($entries);
        $this->assertSame([
            'README.txt',
            'database/database.sqlite',
            'storage/app/private/clubs/1/attachments/2026/slip.bin',
            'storage/app/public/logos/club.png',
        ], $entries);
        $this->assertSame('encrypted-slip', $zip->getFromName('storage/app/private/clubs/1/attachments/2026/slip.bin'));
        foreach ($entries as $entry) {
            $this->assertStringNotContainsString('.env', $entry);
            $this->assertStringNotContainsString((string) config('app.key'), (string) $zip->getFromName($entry));
        }
        $this->assertStringContainsString('NOT included: .env and APP_KEY', $zip->getFromName('README.txt'));

        // The database copy is a working SQLite file with the data.
        file_put_contents("{$this->root}/restored.sqlite", $zip->getFromName('database/database.sqlite'));
        $zip->close();
        $pdo = new \PDO("sqlite:{$this->root}/restored.sqlite");
        $this->assertSame('Backed Up Club', $pdo->query('select name from clubs')->fetchColumn());
        $pdo = null;

        $audit = AuditLog::where('action', 'system.backup')->sole();
        $this->assertSame('System', $audit->user_name);
        $this->assertSame($names[0], $audit->new_values['file']);
        $this->assertTrue($audit->new_values['database']);

        // No temporary files left behind; the folder is locked against web access.
        $this->assertSame(['.htaccess', $names[0]], array_values(array_diff(scandir("{$this->root}/storage/app/backups"), ['.', '..'])));
    }

    public function test_keeps_only_the_newest_backups(): void
    {
        $dir = "{$this->root}/storage/app/backups";
        File::ensureDirectoryExists($dir);
        foreach (range(1, 5) as $day) {
            touch(sprintf('%s/club-portal-202611%02d-021500.zip', $dir, $day));
        }
        touch("{$dir}/keep-me.txt");

        $this->artisan('backup:run', ['--keep' => 3])->expectsOutputToContain('Deleted older backups')->assertSuccessful();

        $left = $this->backups();
        sort($left);
        $this->assertCount(3, $left);
        $this->assertSame(['club-portal-20261104-021500.zip', 'club-portal-20261105-021500.zip'], array_slice($left, 0, 2));
        $this->assertMatchesRegularExpression('/^club-portal-'.now()->format('Ymd').'-\d{6}\.zip$/', $left[2]);
        $this->assertFileExists("{$dir}/keep-me.txt", 'only backup zips are pruned');
    }

    public function test_backup_is_scheduled_daily(): void
    {
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events());
        $backup = $events->first(fn ($e) => str_contains((string) $e->command, 'backup:run'));
        $this->assertNotNull($backup);
        $this->assertSame('15 2 * * *', $backup->expression);
        $this->assertSame('Asia/Kuala_Lumpur', $backup->timezone);
        $this->assertNotNull($events->first(fn ($e) => $e->description === 'scheduler-heartbeat' && $e->expression === '* * * * *'));
    }

    public function test_failed_database_copy_keeps_older_backups_and_fails(): void
    {
        $dir = "{$this->root}/storage/app/backups";
        File::ensureDirectoryExists($dir);
        foreach (range(1, 3) as $day) {
            touch(sprintf('%s/club-portal-202611%02d-021500.zip', $dir, $day));
        }
        // VACUUM INTO refuses to run inside an open transaction.
        DB::connection('backup_scratch')->beginTransaction();
        try {
            $this->artisan('backup:run', ['--keep' => 1])
                ->expectsOutputToContain('could NOT be copied')
                ->expectsOutputToContain('Older backups were kept')
                ->assertFailed();
        } finally {
            DB::connection('backup_scratch')->rollBack();
        }

        $this->assertCount(4, $this->backups(), 'nothing pruned; the files-only zip is added');
    }

    public function test_prune_only_touches_this_apps_backups_and_stale_temp_files(): void
    {
        $dir = "{$this->root}/storage/app/backups";
        File::ensureDirectoryExists($dir);
        foreach (range(1, 3) as $day) {
            touch(sprintf('%s/club-portal-202611%02d-021500.zip', $dir, $day));
        }
        touch("{$dir}/manual-copy-20260101-000000.zip");
        touch("{$dir}/club-portal-before-upgrade.zip");
        touch("{$dir}/.db-oldleftover", time() - 2 * 86400);
        touch("{$dir}/.partial-club-portal-20261101-021500.zip", time() - 2 * 86400);
        touch("{$dir}/.db-fresh"); // another run may be writing it right now

        $this->artisan('backup:run', ['--keep' => 1])->assertSuccessful();

        $left = array_values(array_diff(scandir($dir), ['.', '..']));
        $this->assertContains('manual-copy-20260101-000000.zip', $left);
        $this->assertContains('club-portal-before-upgrade.zip', $left);
        $this->assertContains('.db-fresh', $left);
        $this->assertNotContains('.db-oldleftover', $left);
        $this->assertNotContains('.partial-club-portal-20261101-021500.zip', $left);
        $this->assertCount(1, preg_grep('/^club-portal-\d{8}-\d{6}\.zip$/', $left));
    }

    public function test_mysql_without_proc_open_backs_up_files_only_with_a_clear_message(): void
    {
        \App\Console\Commands\BackupRun::$processAvailable = fn () => false;
        try {
            $this->assertFalse(\App\Console\Commands\BackupRun::canStartProcesses());
            [$file, $entry, $note, $ok] = app(\App\Console\Commands\BackupRun::class)
                ->dumpDatabase(['driver' => 'mysql', 'database' => 'club', 'username' => 'u', 'password' => 'p'], "{$this->root}/x");
        } finally {
            \App\Console\Commands\BackupRun::$processAvailable = null;
        }

        $this->assertNull($file);
        $this->assertNull($entry);
        $this->assertTrue($ok, 'a known host limit, not a failure');
        $this->assertStringContainsString('cPanel', $note);
        $this->assertFileDoesNotExist("{$this->root}/x.cnf", 'no option file with the password written');
    }
}
