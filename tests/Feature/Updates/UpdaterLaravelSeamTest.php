<?php

namespace Tests\Feature\Updates;

use App\Models\User;
use App\Support\Updates\Manifest;
use App\Support\Updates\PreChecks;
use App\Support\Updates\UpdaterHost;
use ClubPortalUpdater\LaravelApp;
use ClubPortalUpdater\Page;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

require_once __DIR__.'/../../../public/update/lib/load.php';

/** A host where every check passes unless a test says otherwise. */
class SeamHost extends UpdaterHost
{
    public bool $paying = false;

    public bool $needsTick = false;

    public function phpVersion(): string
    {
        return '8.4.3';
    }

    public function extensionLoaded(string $name): bool
    {
        return true;
    }

    public function disabledFunctions(): array
    {
        return [];
    }

    public function opcacheValidatesTimestamps(): bool
    {
        return true;
    }

    public function opcacheResetAllowed(): bool
    {
        return true;
    }

    public function freeBytes(string $path): float
    {
        return 5e9;
    }

    public function unwritablePaths(string $base, array $relativePaths): array
    {
        return [];
    }

    public function canCreateAndRename(string $parent): bool
    {
        return true;
    }

    public function pendingMigrations(): int
    {
        return 0;
    }

    public function paymentInProgress(): bool
    {
        return $this->paying;
    }

    public function updateRunning(): bool
    {
        return true; // this update's own job: the step must ignore the row
    }

    public function databaseDriver(): string
    {
        return 'mysql';
    }

    public function databaseBackupNeedsTick(): bool
    {
        return $this->needsTick;
    }
}

/**
 * The parts of the updater that run inside a booted Laravel (ClubPortalUpdater\LaravelApp) and the portal pages the
 * browser's health check reads, against the real app.
 */
class UpdaterLaravelSeamTest extends TestCase
{
    use RefreshDatabase;

    private string $releaseFile;

    private SeamHost $host;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->releaseFile = (string) tempnam(sys_get_temp_dir(), 'rel');
        file_put_contents($this->releaseFile, json_encode(['version' => '2.2.0']));
        config(['updates.release_file' => $this->releaseFile]);
        $this->host = new SeamHost;
        PreChecks::$host = $this->host;
    }

    protected function tearDown(): void
    {
        PreChecks::$host = null;
        @unlink($this->releaseFile);
        parent::tearDown();
    }

    private function app(): LaravelApp
    {
        return LaravelApp::forKernel($this->app->make(Kernel::class));
    }

    /** A job as App\Support\Updates\UpdateJob writes it: the manifest from Manifest::toArray(). */
    private function job(array $over = []): array
    {
        $manifest = Manifest::fromJson((string) json_encode([
            'format' => 1, 'app' => 'clubportal', 'version' => '2.2.1', 'tag' => 'v2.2.1',
            'released_at' => '2026-12-03T00:00:00Z',
            'zip' => [
                'name' => 'clubportal-2.2.1.zip',
                'url' => 'https://github.com/amirsubhi/clubcollection/releases/download/v2.2.1/clubportal-2.2.1.zip',
                'size' => 12345, 'sha256' => str_repeat('ab', 32),
            ],
            'php_requirement' => '>=8.4.1', 'min_from_version' => '2.0.0', 'security' => false,
            'last_security_version' => null, 'summary' => null, 'manual_action' => null,
            'whats_new' => ['Receipts now show the club logo'], 'notes_markdown' => 'Notes',
            'notes_url' => 'https://github.com/amirsubhi/clubcollection/releases/tag/v2.2.1', 'signature' => null,
        ]));

        return $over + ['manifest' => $manifest->toArray(), 'db_backup_ticked' => false, 'manual_ticked' => false];
    }

    public function test_prechecks_pass_with_this_updates_own_job_running(): void
    {
        $this->assertSame([], $this->app()->preChecks($this->job()));
    }

    public function test_a_failing_check_comes_back_as_one_plain_line(): void
    {
        $this->host->paying = true;

        $this->assertSame(['No member is paying right now: A member is paying a fee right now.'], $this->app()->preChecks($this->job()));
    }

    public function test_the_database_backup_row_is_cleared_by_the_tick(): void
    {
        $this->host->needsTick = true;

        $this->assertCount(1, $this->app()->preChecks($this->job()));
        $this->assertSame([], $this->app()->preChecks($this->job(['db_backup_ticked' => true])));
    }

    public function test_an_artisan_command_runs_with_its_output(): void
    {
        $r = $this->app()->call('list');

        $this->assertSame(0, $r['code']);
        $this->assertStringContainsString('backup:run', $r['output']);
    }

    public function test_the_health_boot_checks_the_version_and_the_database(): void
    {
        $this->assertNull($this->app()->health('2.2.0'));
        $this->assertNull($this->app()->health('v2.2.0'));
        $this->assertStringContainsString('reports itself as 2.2.0, not 2.2.1', (string) $this->app()->health('2.2.1'));
    }

    public function test_the_sign_in_page_carries_the_health_marker(): void
    {
        $html = $this->get('/login')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('~'.Page::LOGIN_MARKER.'~', (string) $html);
    }

    public function test_a_signed_in_super_admin_is_sent_to_a_page_with_the_marker(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);

        $response = $this->actingAs($admin)->followingRedirects()->get('/login');

        $response->assertOk();
        $this->assertMatchesRegularExpression('~'.Page::LOGIN_MARKER.'~', (string) $response->getContent());
    }
}
