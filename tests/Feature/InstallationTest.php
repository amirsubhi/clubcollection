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
    }

    protected function tearDown(): void
    {
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

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'app_name'                    => 'Test Portal',
            'app_url'                     => 'https://example.test',
            'admin_name'                  => 'Test Admin',
            'admin_email'                 => 'admin@example.test',
            'admin_password'              => 'StrongPass!2026',
            'admin_password_confirmation' => 'StrongPass!2026',
        ], $overrides);
    }
}
