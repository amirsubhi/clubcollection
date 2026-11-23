<?php

namespace Tests\Feature;

use App\Support\Doctor\HostProbe;
use App\Support\Requirements;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * One requirements list (bootstrap/requirements.php) behind app:doctor, /install (InstallationTest) and the
 * guard in public/index.php (PreflightTest): this proves each of them reads it, and that it matches Composer.
 */
class RequirementsTest extends TestCase
{
    private function list(): array
    {
        return require base_path('bootstrap/requirements.php');
    }

    /** "8.4.1" → "8.4.0": the newest version that is too old. */
    private function justBelow(string $version): string
    {
        $parts = array_map('intval', explode('.', $version));
        $this->assertGreaterThan(0, $parts[2], 'the requirement names a patch release');
        $parts[2]--;

        return implode('.', $parts);
    }

    private function probe(string $version, array $missing = []): void
    {
        $this->app->instance(HostProbe::class, new class($version, $missing) extends HostProbe
        {
            public function __construct(private string $version, private array $missing) {}

            public function phpVersion(): string
            {
                return $this->version;
            }

            public function extensionLoaded(string $name): bool
            {
                return ! in_array($name, $this->missing, true);
            }
        });
    }

    /** @return array<string, string> status by check */
    private function doctor(): array
    {
        Artisan::call('app:doctor', ['--json' => true]);

        return collect(json_decode(Artisan::output(), true)['checks'])->pluck('status', 'check')->all();
    }

    public function test_the_list_is_well_formed(): void
    {
        $list = $this->list();

        $this->assertSame($list, Requirements::all());
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $list['php']);
        $this->assertSame([], array_diff($list['boot_extensions'], $list['extensions']), 'the guard only checks listed extensions');
        $this->assertSame([], array_diff($list['boot_writable'], $list['writable']), 'the guard only checks listed folders');
        $this->assertSame('pdo_mysql', Requirements::pdoExtension('mariadb'));
    }

    public function test_the_php_version_matches_composers_platform_check(): void
    {
        $check = base_path('vendor/composer/platform_check.php');
        if (! is_file($check)) {
            $this->markTestSkipped('vendor/composer/platform_check.php is missing (composer install --no-dev -o writes it)');
        }
        $this->assertMatchesRegularExpression('/PHP_VERSION_ID >= (\d+)/', (string) file_get_contents($check));
        preg_match('/PHP_VERSION_ID >= (\d+)/', (string) file_get_contents($check), $m);
        [$major, $minor, $patch] = array_map('intval', explode('.', Requirements::php()));

        $this->assertSame((int) $m[1], $major * 10000 + $minor * 100 + $patch,
            'bootstrap/requirements.php "php" must equal the version vendor/composer/platform_check.php refuses below');
    }

    public function test_app_doctor_reads_the_list(): void
    {
        $php = $this->list()['php'];

        $this->probe($php);
        $status = $this->doctor();
        $this->assertSame('PASS', $status['PHP version']);
        $this->assertSame('PASS', $status['PHP extensions']);

        $this->probe($this->justBelow($php));
        $this->assertSame('FAIL', $this->doctor()['PHP version']);

        foreach ($this->list()['extensions'] as $ext) {
            $this->probe($php, [$ext]);
            Artisan::call('app:doctor', ['--json' => true]);
            $row = collect(json_decode(Artisan::output(), true)['checks'])->firstWhere('check', 'PHP extensions');
            $this->assertSame('FAIL', $row['status'], $ext);
            $this->assertSame("missing: {$ext}", $row['result']);
        }
    }

    public function test_the_guard_reads_the_list(): void
    {
        require_once base_path('bootstrap/preflight.php');
        $php = $this->list()['php'];
        $yes = fn () => true;

        $this->assertNull(clubportal_preflight(base_path(), $php, $yes, $yes));
        $this->assertSame(503, clubportal_preflight(base_path(), $this->justBelow($php), $yes, $yes)['status']);
    }

    public function test_the_release_build_takes_the_php_requirement_from_the_list(): void
    {
        $script = (string) file_get_contents(base_path('scripts/build-release.sh'));

        $this->assertStringContainsString('bootstrap/requirements.php', $script);
        $this->assertStringNotContainsString('$c["require"]["php"]', $script);
    }
}
