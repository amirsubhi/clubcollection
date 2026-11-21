<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Support\Doctor\HostProbe;
use App\Support\SchedulerHeartbeat;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * G2: `php artisan app:doctor` reports PASS / WARN / FAIL per check, never
 * prints a secret, and exits 1 when anything FAILs.
 */
class AppDoctorTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/doctor-'.Str::random(8);
        File::ensureDirectoryExists("{$this->root}/public/build");
        File::ensureDirectoryExists("{$this->root}/public/storage");
        File::ensureDirectoryExists("{$this->root}/storage/app/private");
        File::ensureDirectoryExists("{$this->root}/storage/app/backups");
        File::ensureDirectoryExists("{$this->root}/storage/framework");
        file_put_contents("{$this->root}/public/build/manifest.json", '{}');
        $this->app->usePublicPath("{$this->root}/public");
        $this->app->useStoragePath("{$this->root}/storage");

        $this->healthyHost();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    /** A host where every check passes. */
    private function healthyHost(): void
    {
        $this->app['env'] = 'production';
        config([
            'app.env' => 'production',
            'app.debug' => false,
            'app.url' => 'https://club.example.com',
            'app.timezone' => 'Asia/Kuala_Lumpur',
            'session.secure' => true,
            'cache.default' => 'database',
            'mail.default' => 'smtp',
            'filesystems.disks.local.root' => "{$this->root}/storage/app/private",
            'toyyibpay.sandbox' => false,
            'toyyibpay.webhook_secret' => self::TOYYIB_SECRET,
        ]);
        $this->probe('8.4.12', ['upload_max_filesize' => '12M', 'post_max_size' => '32M', 'memory_limit' => '256M']);
        Club::factory()->create(['payment_gateway' => 'toyyibpay']);
        SchedulerHeartbeat::beat();
        touch("{$this->root}/storage/app/backups/club-portal-20261120-021500.zip");
    }

    /** @param  array<string, string>  $ini */
    private function probe(string $version, array $ini, array $missing = []): void
    {
        $this->app->instance(HostProbe::class, new class($version, $ini, $missing) extends HostProbe
        {
            public function __construct(private string $version, private array $iniValues, private array $missing) {}

            public function phpVersion(): string
            {
                return $this->version;
            }

            public function extensionLoaded(string $name): bool
            {
                return ! in_array($name, $this->missing, true);
            }

            public function ini(string $key): string|false
            {
                return $this->iniValues[$key] ?? false;
            }
        });
    }

    /** @return array{0: int, 1: array<string, string>, 2: array<string, mixed>} exit code, status by check, decoded JSON */
    private function doctor(): array
    {
        $code = Artisan::call('app:doctor', ['--json' => true]);
        $json = json_decode(Artisan::output(), true);
        $this->assertIsArray($json, 'the --json output is JSON');

        return [$code, collect($json['checks'])->pluck('status', 'check')->all(), $json];
    }

    public function test_healthy_production_host_passes_everything(): void
    {
        [$code, $status, $json] = $this->doctor();

        $this->assertSame(0, $code);
        $this->assertTrue($json['ok']);
        $this->assertSame([], array_keys(array_filter($status, fn ($s) => $s !== 'PASS')), 'every row passes');
        foreach (['PHP version', 'PHP extensions', 'APP_KEY', 'APP_ENV', 'APP_DEBUG', 'APP_URL uses https', 'SESSION_SECURE_COOKIE',
            'APP_TIMEZONE', 'Cache store', 'upload_max_filesize', 'post_max_size', 'memory_limit', 'Private files folder',
            'storage:link', 'Front-end build', 'No Vite dev-server file', 'Database', 'ToyyibPay webhook secret',
            'ToyyibPay mode', 'Mail', 'Scheduler (cron job)', 'Backups'] as $check) {
            $this->assertArrayHasKey($check, $status);
        }
    }

    public function test_misconfigured_production_host_fails_with_a_fix_per_row(): void
    {
        config([
            'app.debug' => true,
            'app.url' => 'http://club.example.com',
            'cache.default' => 'array',
            'mail.default' => 'log',
            'toyyibpay.sandbox' => true,
            'toyyibpay.webhook_secret' => 'your_webhook_secret_here',
        ]);
        $this->probe('8.3.6', ['upload_max_filesize' => '2M', 'post_max_size' => '8M', 'memory_limit' => '64M'], ['gd', 'pdo_sqlite', 'pdo_mysql']);
        @unlink(SchedulerHeartbeat::path());
        file_put_contents("{$this->root}/public/hot", 'http://localhost:5173');

        [$code, $status, $json] = $this->doctor();

        $this->assertSame(1, $code);
        $this->assertFalse($json['ok']);
        foreach (['PHP version', 'PHP extensions', 'APP_DEBUG', 'APP_URL uses https', 'Cache store', 'upload_max_filesize',
            'post_max_size', 'memory_limit', 'No Vite dev-server file', 'ToyyibPay webhook secret', 'Scheduler (cron job)'] as $check) {
            $this->assertSame('FAIL', $status[$check], $check);
        }
        $this->assertSame('WARN', $status['ToyyibPay mode']);
        $this->assertSame('WARN', $status['Mail']);
        foreach ($json['checks'] as $row) {
            $this->assertSame($row['status'] === 'PASS', $row['fix'] === '', "{$row['check']}: a fix exactly when not passing");
        }
        $extensions = collect($json['checks'])->firstWhere('check', 'PHP extensions');
        // Only the PDO driver of the connection in use is required (the suite also runs on MariaDB).
        $driver = config('database.connections.'.config('database.default').'.driver');
        $this->assertSame('missing: gd, '.(in_array($driver, ['mysql', 'mariadb'], true) ? 'pdo_mysql' : 'pdo_sqlite'), $extensions['result']);
        $this->assertStringContainsString('SANDBOX-TEST.md', collect($json['checks'])->firstWhere('check', 'ToyyibPay mode')['fix']);
    }

    public function test_outside_production_live_site_checks_are_warnings(): void
    {
        $this->app['env'] = 'local';
        config(['app.env' => 'local', 'app.debug' => true, 'app.url' => 'http://localhost', 'session.secure' => null, 'mail.default' => 'log']);
        @unlink(SchedulerHeartbeat::path());

        [$code, $status] = $this->doctor();

        $this->assertSame(0, $code, 'only WARN rows');
        foreach (['APP_ENV', 'APP_DEBUG', 'APP_URL uses https', 'SESSION_SECURE_COOKIE', 'Scheduler (cron job)'] as $check) {
            $this->assertSame('WARN', $status[$check], $check);
        }
        $this->assertSame('PASS', $status['Mail'], 'log mail is fine outside production');

        // Checks that matter everywhere still FAIL.
        $this->probe('8.3.6', ['upload_max_filesize' => '2M', 'post_max_size' => '8M', 'memory_limit' => '-1']);
        [$code, $status] = $this->doctor();
        $this->assertSame(1, $code);
        $this->assertSame('FAIL', $status['PHP version']);
        $this->assertSame('WARN', $status['upload_max_filesize']);
        $this->assertSame('PASS', $status['memory_limit'], '-1 means no limit');

        // 0 also means no limit (post_max_size = 0 disables the check in PHP).
        $this->probe('8.4.1', ['upload_max_filesize' => '0', 'post_max_size' => '0', 'memory_limit' => '0']);
        [, $status] = $this->doctor();
        foreach (['upload_max_filesize', 'post_max_size', 'memory_limit'] as $key) {
            $this->assertSame('PASS', $status[$key], $key);
        }
    }

    public function test_stale_scheduler_heartbeat_fails(): void
    {
        $this->travel(SchedulerHeartbeat::STALE_MINUTES + 1)->minutes();
        [$code, $status, $json] = $this->doctor();

        $this->assertSame(1, $code);
        $this->assertSame('FAIL', $status['Scheduler (cron job)']);
        $this->assertStringContainsString('ago', collect($json['checks'])->firstWhere('check', 'Scheduler (cron job)')['result']);

        $this->artisan('schedule:run')->assertSuccessful();
        [, $status] = $this->doctor();
        $this->assertSame('PASS', $status['Scheduler (cron job)'], 'the scheduled heartbeat writes a fresh timestamp');
    }

    public function test_billplz_club_without_a_usable_key_is_named(): void
    {
        config(['billplz.x_signature_key' => 'your_billplz_x_signature_key_here']);
        Club::query()->delete();
        Club::factory()->create(['name' => 'Gamma Club', 'payment_gateway' => 'billplz']);

        [$code, $status, $json] = $this->doctor();
        $this->assertSame(1, $code);
        $this->assertSame('FAIL', $status['Billplz X-Signature key']);
        $this->assertStringContainsString('Gamma Club', collect($json['checks'])->firstWhere('check', 'Billplz X-Signature key')['result']);
        $this->assertArrayNotHasKey('ToyyibPay webhook secret', $status, 'no active ToyyibPay club');
        $this->assertSame('PASS', $status['ToyyibPay mode']);
    }

    public function test_database_that_cannot_connect_is_reported_as_such(): void
    {
        $default = config('database.default');
        config([
            'database.connections.missing' => ['driver' => 'sqlite', 'database' => "{$this->root}/nope/none.sqlite", 'prefix' => ''],
            'database.default' => 'missing',
        ]);
        [$code, $status, $json] = $this->doctor();
        config(['database.default' => $default]);

        $this->assertSame(1, $code);
        $this->assertSame('FAIL', $status['Database']);
        $this->assertSame('sqlite: cannot connect', collect($json['checks'])->firstWhere('check', 'Database')['result']);
        $this->assertSame('WARN', $status['Webhook secrets']);
    }

    public function test_table_output_never_prints_secrets(): void
    {
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $this->artisan('app:doctor')
            ->expectsOutputToContain('Club Portal host check')
            ->expectsOutputToContain('0 FAIL')
            ->assertSuccessful();

        Artisan::call('app:doctor');
        $out = Artisan::output();
        $this->assertStringNotContainsString(self::TOYYIB_SECRET, $out);
        $this->assertStringNotContainsString((string) config('app.key'), $out);
        $this->assertStringContainsString('set (hidden)', $out);
    }
}
