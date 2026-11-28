<?php

namespace Tests\Unit\Updater;

use ClubPortalUpdater\Maintenance;
use ClubPortalUpdater\UpdaterError;
use Illuminate\Foundation\Http\MaintenanceModeBypassCookie;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../../public/update/lib/load.php';

class MaintenanceTest extends TestCase
{
    use Support;

    private string $app;

    private string $stub;

    protected function setUp(): void
    {
        $this->app = $this->tempDir();
        $real = dirname(__DIR__, 3).'/'.Maintenance::STUB;
        $this->stub = (string) file_get_contents($real);
        mkdir(dirname($this->app.'/'.Maintenance::STUB), 0755, true);
        copy($real, $this->app.'/'.Maintenance::STUB);
        mkdir($this->app.'/storage/framework', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->cleanTempDirs();
    }

    public function test_on_writes_laravels_down_file_the_stub_and_the_guard(): void
    {
        Maintenance::on($this->app, 'abcdefghijklmnop1234', '<html>paused</html>');

        $down = json_decode((string) file_get_contents($this->app.'/storage/framework/down'), true);
        $this->assertSame([
            'except' => ['webhook/*'],
            'redirect' => null,
            'retry' => 120,
            'refresh' => null,
            'secret' => 'abcdefghijklmnop1234',
            'status' => 503,
            'template' => '<html>paused</html>',
        ], $down);
        // Byte for byte what `php artisan down` copies (the stub has no placeholders).
        $this->assertSame($this->stub, file_get_contents($this->app.'/storage/framework/maintenance.php'));
        $this->assertFileExists($this->app.'/storage/framework/updating');
        $this->assertSame([], glob($this->app.'/storage/framework/*.tmp*'));
    }

    public function test_on_reads_the_stub_first_and_writes_nothing_without_it(): void
    {
        unlink($this->app.'/'.Maintenance::STUB);

        try {
            Maintenance::on($this->app, 'abcdefghijklmnop1234', '<html></html>');
            $this->fail('expected a refusal');
        } catch (UpdaterError) {
        }
        $this->assertFileDoesNotExist($this->app.'/storage/framework/updating');
        $this->assertFileDoesNotExist($this->app.'/storage/framework/down');
        $this->assertFileDoesNotExist($this->app.'/storage/framework/maintenance.php');
    }

    public function test_on_refuses_an_empty_or_odd_secret(): void
    {
        foreach (['', 'short', 'has/slash/inside/x', str_repeat('a', 200)] as $secret) {
            try {
                Maintenance::on($this->app, $secret, '<html></html>');
                $this->fail("secret '$secret' accepted");
            } catch (UpdaterError) {
            }
        }
        $this->assertFileDoesNotExist($this->app.'/storage/framework/updating');
    }

    public function test_lift_guard_removes_only_the_guard(): void
    {
        Maintenance::on($this->app, Maintenance::newSecret(), '<html></html>');

        Maintenance::liftGuard($this->app);

        $this->assertFileDoesNotExist($this->app.'/storage/framework/updating');
        $this->assertFileExists($this->app.'/storage/framework/down');
        $this->assertFileExists($this->app.'/storage/framework/maintenance.php');
    }

    public function test_off_removes_all_three_and_is_safe_to_repeat(): void
    {
        Maintenance::on($this->app, Maintenance::newSecret(), '<html></html>');

        Maintenance::off($this->app);
        Maintenance::off($this->app);

        foreach (['updating', 'down', 'maintenance.php'] as $name) {
            $this->assertFileDoesNotExist($this->app.'/storage/framework/'.$name);
        }
    }

    public function test_the_bypass_cookie_is_accepted_by_laravel(): void
    {
        $secret = Maintenance::newSecret();
        $cookie = Maintenance::bypassCookie($secret);

        $this->assertSame('laravel_maintenance', $cookie['name']);
        $this->assertEqualsWithDelta(time() + 12 * 3600, $cookie['expires'], 5);
        $this->assertTrue(MaintenanceModeBypassCookie::isValid($cookie['value'], $secret));
        $this->assertFalse(MaintenanceModeBypassCookie::isValid($cookie['value'], Maintenance::newSecret()));
    }

    public function test_new_secrets_are_random_and_accepted_by_on(): void
    {
        $a = Maintenance::newSecret();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $a);
        $this->assertNotSame($a, Maintenance::newSecret());
    }
}
