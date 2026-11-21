<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;

class InstallationTest extends BaseTestCase
{
    use RefreshDatabase;

    private ?string $envBackup = null;

    // Don't extend Tests\TestCase — that base creates the .installed marker
    // in setUp(), which would force every install test to delete it first.
    public function createApplication()
    {
        $app = require __DIR__.'/../../bootstrap/app.php';
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        @unlink(storage_path('app/.installed'));
        @unlink(storage_path('app/.installed.tmp'));

        // Back up .env — the install wizard writes APP_NAME / APP_URL to it.
        $envPath = base_path('.env');
        if (file_exists($envPath)) {
            $this->envBackup = file_get_contents($envPath);
        }

        // Stub Artisan::call('migrate', ...) so we don't re-run migrations
        // against the in-memory test DB (which RefreshDatabase already set up).
        Artisan::shouldReceive('call')
            ->with('migrate', \Mockery::any())
            ->andReturn(0)
            ->byDefault();
        Artisan::shouldReceive('output')->andReturn('')->byDefault();

        config(['app.install_token' => self::TOKEN]);

        // The app needs PHP 8.4 (InstallController::MIN_PHP); let the install
        // flow tests run on an older local PHP too. Reset in tearDown().
        \App\Http\Controllers\InstallController::$minimumPhp = '8.0.0';
    }

    private const TOKEN = 'test-install-token-0123456789';

    protected function tearDown(): void
    {
        \App\Http\Controllers\InstallController::$minimumPhp = \App\Http\Controllers\InstallController::MIN_PHP;
        @unlink(storage_path('app/.installed'));
        @unlink(storage_path('app/.installed.tmp'));

        // Restore .env so subsequent tests see the original config.
        if ($this->envBackup !== null) {
            file_put_contents(base_path('.env'), $this->envBackup);
        }
        parent::tearDown();
    }

    public function test_install_page_loads_when_not_installed(): void
    {
        $this->get('/install')->assertOk()->assertSee('System Requirements');
    }

    public function test_install_redirects_when_already_installed(): void
    {
        touch(storage_path('app/.installed'));
        $this->get('/install')->assertRedirect();
    }

    public function test_install_rejects_short_password(): void
    {
        $this->post('/install', $this->validPayload([
            'admin_password'              => 'short',
            'admin_password_confirmation' => 'short',
        ]))->assertSessionHasErrors('admin_password');

        $this->assertDatabaseMissing('users', ['email' => 'admin@example.test']);
        $this->assertFileDoesNotExist(storage_path('app/.installed'));
    }

    public function test_install_rejects_password_without_complexity(): void
    {
        // 12 chars but only lower-case letters — fails Password::min(12)
        // ->mixedCase()->numbers()->symbols()
        $this->post('/install', $this->validPayload([
            'admin_password'              => 'aaaaaaaaaaaa',
            'admin_password_confirmation' => 'aaaaaaaaaaaa',
        ]))->assertSessionHasErrors('admin_password');

        $this->assertFileDoesNotExist(storage_path('app/.installed'));
    }

    public function test_install_creates_super_admin_and_writes_marker_on_success(): void
    {
        $this->post('/install', $this->validPayload())
            ->assertRedirect(route('login'));

        $admin = User::where('email', 'admin@example.test')->first();
        $this->assertNotNull($admin);
        $this->assertSame('super_admin', $admin->role);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('StrongPass!2026', $admin->password));
        $this->assertFileExists(storage_path('app/.installed'));
    }

    public function test_install_refuses_when_marker_already_exists(): void
    {
        touch(storage_path('app/.installed'));

        $this->post('/install', $this->validPayload())
            ->assertRedirect();

        $this->assertDatabaseMissing('users', ['email' => 'admin@example.test']);
    }

    // ── S1: install token and production .env ─────────────────────────────

    public function test_installer_is_disabled_without_a_token(): void
    {
        config(['app.install_token' => null]);
        @unlink(storage_path('app/.install-token'));

        $this->get('/install')->assertForbidden()->assertSee('The installer is disabled')->assertDontSee('name="admin_password"', false);
        $this->post('/install', $this->validPayload())->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'admin@example.test']);
        $this->assertFileDoesNotExist(storage_path('app/.installed'));
    }

    public function test_a_too_short_token_keeps_the_installer_disabled(): void
    {
        config(['app.install_token' => 'short']);

        $this->post('/install', $this->validPayload(['install_token' => 'short']))->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => 'admin@example.test']);
    }

    public function test_a_wrong_token_is_refused(): void
    {
        $this->post('/install', $this->validPayload(['install_token' => 'not-the-right-token-at-all']))
            ->assertSessionHasErrors('install_token');

        $this->assertDatabaseMissing('users', ['email' => 'admin@example.test']);
        $this->assertFileDoesNotExist(storage_path('app/.installed'));
    }

    public function test_a_token_file_enables_the_installer_and_is_removed_after_install(): void
    {
        config(['app.install_token' => null]);
        file_put_contents(storage_path('app/.install-token'), "file-token-0123456789abcdef\n");

        try {
            $this->post('/install', $this->validPayload(['install_token' => 'file-token-0123456789abcdef']))
                ->assertRedirect(route('login'));
            $this->assertFileDoesNotExist(storage_path('app/.install-token'));
        } finally {
            @unlink(storage_path('app/.install-token'));
        }
    }

    public function test_install_rejects_a_newline_or_quote_in_the_app_name(): void
    {
        foreach (["Club\nAPP_DEBUG=true", 'Club "Portal"', "Club\rX", 'Club ${APP_KEY}', 'Club\\'] as $bad) {
            $this->post('/install', $this->validPayload(['app_name' => $bad]))->assertSessionHasErrors('app_name');
        }
        $this->assertDatabaseMissing('users', ['email' => 'admin@example.test']);
    }

    public function test_install_writes_production_settings_and_clears_the_token(): void
    {
        file_put_contents(base_path('.env'), ($this->envBackup ?? '')."\nINSTALL_TOKEN=".self::TOKEN."\n");

        $this->post('/install', $this->validPayload())->assertRedirect(route('login'));

        $env = file_get_contents(base_path('.env'));
        $this->assertMatchesRegularExpression('/^APP_ENV=production$/m', $env);
        $this->assertMatchesRegularExpression('/^APP_DEBUG=false$/m', $env);
        $this->assertMatchesRegularExpression('/^APP_TIMEZONE=Asia\/Kuala_Lumpur$/m', $env);
        $this->assertMatchesRegularExpression('/^SESSION_SECURE_COOKIE=true$/m', $env);
        $this->assertMatchesRegularExpression('/^LOG_LEVEL=warning$/m', $env);
        $this->assertMatchesRegularExpression('/^APP_URL=https:\/\/example\.test$/m', $env);
        $this->assertMatchesRegularExpression('/^INSTALL_TOKEN=$/m', $env);
        $this->assertFileExists(storage_path('app/.installed'));
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'app_name'                    => 'Test Portal',
            'app_url'                     => 'https://example.test',
            'admin_name'                  => 'Test Admin',
            'admin_email'                 => 'admin@example.test',
            'admin_password'              => 'StrongPass!2026',
            'admin_password_confirmation' => 'StrongPass!2026',
            'install_token'               => self::TOKEN,
        ], $overrides);
    }

    public function test_installer_requires_php_8_4(): void
    {
        $this->assertSame('8.4.0', \App\Http\Controllers\InstallController::MIN_PHP);

        \App\Http\Controllers\InstallController::$minimumPhp = \App\Http\Controllers\InstallController::MIN_PHP;
        $this->get('/install')->assertSee('PHP &gt;= 8.4', false);
    }

    public function test_installer_checks_the_pdo_driver_of_the_configured_database(): void
    {
        // Only the driver name is read; the real connection is put back before tearDown uses it.
        $default = config('database.default');
        $driver = config("database.connections.{$default}.driver");
        try {
            config(["database.connections.{$default}.driver" => 'mariadb']);
            $this->get('/install')->assertSee('PDO MySQL / MariaDB Extension')->assertDontSee('PDO SQLite Extension');

            config(["database.connections.{$default}.driver" => 'sqlite']);
            $this->get('/install')->assertSee('PDO SQLite Extension')->assertDontSee('PDO MySQL / MariaDB Extension');
        } finally {
            config(["database.connections.{$default}.driver" => $driver]);
        }
    }
}
