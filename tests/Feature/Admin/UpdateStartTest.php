<?php

namespace Tests\Feature\Admin;

use App\Http\Controllers\Admin\UpdatesController;
use App\Models\AuditLog;
use App\Models\Club;
use App\Models\User;
use App\Support\Portal\PortalSettings;
use App\Support\Updates\PreChecks;
use App\Support\Updates\UpdateJob;
use App\Support\Updates\UpdaterFiles;
use App\Support\Updates\UpdaterHost;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/** A host where every check passes; a test can take the zip extension away or ask for a tick. */
class UpdateStartHost extends UpdaterHost
{
    public bool $zip = true;

    public bool $needsTick = false;

    public float $free = 5e9;

    public function phpVersion(): string
    {
        return '8.4.3';
    }

    public function extensionLoaded(string $name): bool
    {
        return $name === 'zip' ? $this->zip : true;
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
        return $this->free;
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
        return false;
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

/** Starting an update: the re-confirmation, the job file, the cookie and the screens around the button (J1, J3, J5, J8). */
class UpdateStartTest extends TestCase
{
    private string $releaseFile;

    private string $storage;

    private UpdateStartHost $host;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::flush();
        $this->storage = sys_get_temp_dir().'/updstart-'.bin2hex(random_bytes(5));
        File::ensureDirectoryExists($this->storage.'/framework');
        File::ensureDirectoryExists($this->storage.'/app');
        touch($this->storage.'/app/.installed');
        $this->app->useStoragePath($this->storage);
        $this->releaseFile = tempnam(sys_get_temp_dir(), 'rel');
        file_put_contents($this->releaseFile, json_encode(['version' => '2.2.0']));
        config(['updates.release_file' => $this->releaseFile, 'app.timezone' => 'Asia/Kuala_Lumpur']);
        $this->host = new UpdateStartHost;
        PreChecks::$host = $this->host;
        $this->release();
    }

    protected function tearDown(): void
    {
        PreChecks::$host = null;
        @unlink($this->releaseFile);
        File::deleteDirectory($this->storage);
        parent::tearDown();
    }

    private function release(array $over = []): void
    {
        Http::swap(new Factory);
        Http::fake(['*' => Http::response(array_merge([
            'format' => 1, 'app' => 'clubportal', 'version' => '2.2.3', 'tag' => 'v2.2.3',
            'released_at' => '2026-12-03T00:00:00Z',
            'zip' => [
                'name' => 'clubportal-2.2.3.zip',
                'url' => 'https://github.com/amirsubhi/clubcollection/releases/download/v2.2.3/clubportal-2.2.3.zip',
                'size' => 12345, 'sha256' => str_repeat('ab', 32),
            ],
            'php_requirement' => '>=8.4.1', 'min_from_version' => '2.0.0', 'security' => false,
            'last_security_version' => null, 'summary' => null, 'manual_action' => null,
            'whats_new' => ['Receipts now show the club logo'],
            'notes_markdown' => 'Some notes',
            'notes_url' => 'https://github.com/amirsubhi/clubcollection/releases/tag/v2.2.3',
            'signature' => null,
        ], $over))]);
    }

    private function twoFactorAdmin(): array
    {
        $secret = (new Google2FA)->generateSecretKey();
        $user = User::factory()->create([
            'role' => 'super_admin',
            'two_factor_secret' => encrypt($secret),
            'two_factor_confirmed_at' => now(),
        ]);
        $this->actingAs($user);
        $this->withSession(['two_factor_verified' => true]);

        return [$user, $secret];
    }

    private function writeJob(array $job): void
    {
        File::ensureDirectoryExists(UpdaterFiles::dir());
        file_put_contents(UpdaterFiles::jobPath(), json_encode($job));
    }

    private function assertNoJob(): void
    {
        $this->assertFileDoesNotExist(UpdaterFiles::jobPath());
    }

    public function test_only_a_super_admin_may_start(): void
    {
        $club = Club::factory()->create();
        $this->actingAsClubAdmin($club);
        $this->post(route('admin.updates.start'), ['password' => 'password'])->assertForbidden();
        $this->assertNoJob();
    }

    public function test_a_wrong_password_is_refused_and_audited(): void
    {
        $this->actingAsSuperAdmin();

        $this->from(route('admin.updates.index'))
            ->post(route('admin.updates.start'), ['password' => 'nope'])
            ->assertRedirect(route('admin.updates.index'))
            ->assertSessionHasErrors('update');

        $this->assertNoJob();
        $this->assertSame(1, AuditLog::where('action', 'portal.update_failed_check')->count());
        $this->assertSame(0, AuditLog::where('action', 'portal.update_started')->count());
    }

    public function test_a_missing_password_is_refused(): void
    {
        $this->actingAsSuperAdmin();

        $this->post(route('admin.updates.start'), [])->assertSessionHasErrors('password');
        $this->assertNoJob();
    }

    public function test_with_two_factor_on_a_missing_or_wrong_code_is_refused(): void
    {
        $this->twoFactorAdmin();

        $this->post(route('admin.updates.start'), ['password' => 'password'])->assertSessionHasErrors('update');
        $this->post(route('admin.updates.start'), ['password' => 'password', 'code' => '000000'])->assertSessionHasErrors('update');

        $this->assertNoJob();
        $this->assertSame(2, AuditLog::where('action', 'portal.update_failed_check')->count());
    }

    public function test_a_replayed_code_is_refused(): void
    {
        [, $secret] = $this->twoFactorAdmin();
        $code = (new Google2FA)->getCurrentOtp($secret);
        $this->host->needsTick = true; // the first try is refused for the missing tick, after the code was used up

        $this->post(route('admin.updates.start'), ['password' => 'password', 'code' => $code])->assertSessionHasErrors('update');
        $this->post(route('admin.updates.start'), ['password' => 'password', 'code' => $code, 'db_backup_ticked' => '1'])
            ->assertSessionHasErrors('update');

        $this->assertNoJob();
    }

    public function test_with_two_factor_a_current_code_starts_the_update(): void
    {
        [, $secret] = $this->twoFactorAdmin();

        $this->post(route('admin.updates.start'), ['password' => 'password', 'code' => (new Google2FA)->getCurrentOtp($secret)])
            ->assertRedirect('/update/');

        $this->assertFileExists(UpdaterFiles::jobPath());
    }

    public function test_a_failing_blocking_check_at_submit_refuses_without_a_job(): void
    {
        $this->actingAsSuperAdmin();
        $this->host->zip = false;

        $this->post(route('admin.updates.start'), ['password' => 'password'])->assertSessionHasErrors('update');

        $this->assertNoJob();
    }

    public function test_a_running_job_refuses_and_keeps_the_running_one(): void
    {
        $this->actingAsSuperAdmin();
        $this->writeJob(['status' => 'running', 'user_name' => 'Aminah']);

        $this->post(route('admin.updates.start'), ['password' => 'password'])->assertSessionHasErrors('update');

        $this->assertSame('Aminah', UpdateJob::current()['user_name']);
    }

    public function test_an_unreadable_job_file_counts_as_running(): void
    {
        $this->actingAsSuperAdmin();
        File::ensureDirectoryExists(UpdaterFiles::dir());
        file_put_contents(UpdaterFiles::jobPath(), '{not json');

        $this->post(route('admin.updates.start'), ['password' => 'password'])->assertSessionHasErrors('update');

        $this->assertSame('{not json', file_get_contents(UpdaterFiles::jobPath()));
        $this->assertTrue((new UpdaterHost)->updateRunning());
    }

    public function test_a_finished_job_does_not_block_a_new_one(): void
    {
        $this->actingAsSuperAdmin();
        foreach (UpdaterHost::TERMINAL_JOB_STATUSES as $status) {
            $this->writeJob(['status' => $status]);
            $this->post(route('admin.updates.start'), ['password' => 'password'])->assertRedirect('/update/');
            $this->assertSame('ready', UpdateJob::current()['status']);
        }
    }

    public function test_an_expired_ready_job_no_longer_blocks(): void
    {
        $this->actingAsSuperAdmin();
        $this->writeJob(['status' => 'ready', 'expires_at' => time() - 5, 'user_name' => 'Aminah', 'to_version' => '2.2.3']);

        $this->get(route('admin.updates.index'))->assertOk()->assertSee('Update to v2.2.3')->assertDontSeeText('Aminah is updating');
        $this->post(route('admin.updates.start'), ['password' => 'password'])->assertRedirect('/update/');
        $this->assertGreaterThan(time(), UpdateJob::current()['expires_at']);
    }

    public function test_an_unexpired_ready_job_still_refuses(): void
    {
        $this->actingAsSuperAdmin();
        $this->writeJob(['status' => 'ready', 'expires_at' => time() + 600, 'user_name' => 'Aminah']);

        $this->post(route('admin.updates.start'), ['password' => 'password'])->assertSessionHasErrors('update');
        $this->assertSame('Aminah', UpdateJob::current()['user_name']);
    }

    public function test_a_held_update_lock_refuses_without_writing_a_job(): void
    {
        $this->actingAsSuperAdmin();
        File::ensureDirectoryExists(UpdaterFiles::dir());
        $lock = fopen(UpdaterFiles::lockPath(), 'c');
        $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));

        try {
            $this->post(route('admin.updates.start'), ['password' => 'password'])
                ->assertSessionHasErrors(['update' => 'An update is already starting or running.']);
            $this->assertNoJob();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        $this->post(route('admin.updates.start'), ['password' => 'password'])->assertRedirect('/update/');
    }

    public function test_a_blocked_flag_refuses(): void
    {
        $this->actingAsSuperAdmin();
        File::ensureDirectoryExists(UpdaterFiles::dir());
        file_put_contents(UpdaterFiles::blockedPath(), '1');

        $this->post(route('admin.updates.start'), ['password' => 'password'])->assertSessionHasErrors('update');

        $this->assertNoJob();
    }

    public function test_a_needed_tick_must_be_given(): void
    {
        $this->actingAsSuperAdmin();
        $this->host->needsTick = true;

        $this->post(route('admin.updates.start'), ['password' => 'password'])->assertSessionHasErrors('update');
        $this->assertNoJob();

        $this->post(route('admin.updates.start'), ['password' => 'password', 'db_backup_ticked' => '1'])->assertRedirect('/update/');
        $this->assertTrue(UpdateJob::current()['db_backup_ticked']);
        $this->assertFalse(UpdateJob::current()['manual_ticked']);
    }

    public function test_a_manual_action_tick_must_be_given(): void
    {
        $this->actingAsSuperAdmin();
        $this->release(['manual_action' => 'Raise the PHP memory limit to 256M']);

        $this->post(route('admin.updates.start'), ['password' => 'password', 'db_backup_ticked' => '1'])->assertSessionHasErrors('update');
        $this->assertNoJob();

        $this->post(route('admin.updates.start'), ['password' => 'password', 'manual_ticked' => '1'])->assertRedirect('/update/');
        $this->assertTrue(UpdateJob::current()['manual_ticked']);
    }

    public function test_a_different_version_than_the_page_showed_is_refused(): void
    {
        $this->actingAsSuperAdmin();

        $this->post(route('admin.updates.start'), ['password' => 'password', 'version' => '2.2.2'])->assertSessionHasErrors('update');

        $this->assertNoJob();
    }

    public function test_success_sets_the_cookie_writes_the_hashed_job_audits_and_redirects(): void
    {
        $user = $this->actingAsSuperAdmin();

        $response = $this->post(route('admin.updates.start'), ['password' => 'password', 'version' => '2.2.3'])
            ->assertRedirect('/update/');

        $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === 'cp_update');
        $this->assertNotNull($cookie);
        $this->assertSame('/update/', $cookie->getPath());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('strict', $cookie->getSameSite());
        $this->assertFalse($cookie->isSecure(), 'plain http: no Secure flag');
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $cookie->getValue());
        $this->assertEqualsWithDelta(60 * 60, $cookie->getExpiresTime() - time(), 30);

        $raw = file_get_contents(UpdaterFiles::jobPath());
        $this->assertStringNotContainsString($cookie->getValue(), $raw);
        $job = json_decode($raw, true);
        $this->assertSame(hash('sha256', $cookie->getValue()), $job['token_hash']);
        $this->assertSame('ready', $job['status']);
        $this->assertSame($user->id, $job['user_id']);
        $this->assertSame($user->name, $job['user_name']);
        $this->assertSame('2.2.0', $job['from_version']);
        $this->assertSame('2.2.3', $job['to_version']);
        $this->assertSame('2.2.3', $job['manifest']['version']);
        $this->assertSame('Asia/Kuala_Lumpur', $job['timezone']);
        $this->assertSame(PortalSettings::defaultLocale(), $job['locale']);
        $this->assertSame(PortalSettings::name(), $job['portal_name']);
        $this->assertSame($job['created_at'], $job['last_step_at']);
        $this->assertSame($job['last_step_at'] + 1800, $job['expires_at']);
        $this->assertFalse($job['db_backup_ticked']);
        $this->assertSame([], glob(UpdaterFiles::dir().'/*.tmp'));

        $audit = AuditLog::where('action', 'portal.update_started')->sole();
        $this->assertEquals(['from' => '2.2.0', 'to' => '2.2.3'], $audit->new_values);
    }

    public function test_the_cookie_is_secure_on_https_only(): void
    {
        $this->actingAsSuperAdmin();

        $response = $this->withServerVariables(['HTTPS' => 'on'])
            ->post('https://localhost'.route('admin.updates.start', [], false), ['password' => 'password']);

        $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === 'cp_update');
        $this->assertTrue($cookie->isSecure());
    }

    public function test_the_page_shows_the_button_the_password_field_and_the_manual_alternative(): void
    {
        $this->actingAsSuperAdmin();

        $this->get(route('admin.updates.index'))
            ->assertOk()
            ->assertSee('Update to v2.2.3')
            ->assertSee('name="password"', false)
            ->assertDontSee('name="code"', false)
            ->assertSeeText('Two-step sign-in is not turned on for your account')
            ->assertSee(UpdatesController::MANUAL_STEPS_URL, false)
            ->assertDontSeeText('To update, follow the upgrade steps');
    }

    public function test_the_page_asks_for_the_code_when_two_factor_is_on(): void
    {
        $this->twoFactorAdmin();

        $this->get(route('admin.updates.index'))
            ->assertOk()
            ->assertSee('name="code"', false)
            ->assertDontSeeText('Two-step sign-in is not turned on');
    }

    public function test_a_failing_host_check_shows_no_button(): void
    {
        $this->actingAsSuperAdmin();
        $this->host->zip = false;

        $this->get(route('admin.updates.index'))->assertOk()->assertDontSee('Update to v2.2.3')->assertDontSee('name="password"', false);
    }

    public function test_the_tick_screens_show_what_is_needed_a_copy_message_and_the_tick(): void
    {
        $this->actingAsSuperAdmin();
        $this->host->needsTick = true;
        $this->release(['manual_action' => 'Raise the PHP memory limit to 256M']);

        $html = $this->get(route('admin.updates.index'))->assertOk()
            ->assertSeeText("This host can't copy your club's data by itself")
            ->assertSeeText('They need to: Raise the PHP memory limit to 256M')
            ->assertSee('id="before-backup_database"', false)
            ->assertSee('id="before-manual_action"', false)
            ->assertSee('name="db_backup_ticked"', false)
            ->assertSee('name="manual_ticked"', false)
            ->assertSeeText("It's been done (ticked by the person who did it)")
            ->assertSee('Update to v2.2.3')
            ->getContent();
        $this->assertSame(2, substr_count($html, 'Copy message</button>'));
    }

    public function test_the_advice_rows_are_not_labelled_failed(): void
    {
        $this->actingAsSuperAdmin();
        $this->host->needsTick = true;

        $html = $this->get(route('admin.updates.index'))->assertOk()->getContent();
        $checks = substr($html, strpos($html, 'data-testid="show-checks"'));
        $checks = substr($checks, 0, strpos($checks, '</details>'));

        $this->assertStringContainsString(e("Your club's data can be copied automatically"), $checks);
        $this->assertStringNotContainsString('Failed:', $checks);
        $this->assertStringContainsString('To do first:', $checks);
    }

    public function test_a_blocking_failure_is_still_labelled_failed(): void
    {
        $this->actingAsSuperAdmin();
        $this->host->zip = false;

        $html = $this->get(route('admin.updates.index'))->assertOk()->getContent();

        $this->assertStringContainsString('Failed:', $html);
    }

    public function test_the_tight_disk_warning_shows_its_fix_and_host_message(): void
    {
        $this->actingAsSuperAdmin();
        $this->host->free = 12345 * 3 * 3 * 1.5;

        $this->get(route('admin.updates.index'))->assertOk()
            ->assertSeeText("We can't see your hosting account's own storage limit")
            ->assertSeeText('Ask your host or whoever installed the portal to check that the account has at least')
            ->assertSee('id="before-disk"', false)
            ->assertSeeText('Please check Disk Usage in cPanel')
            ->assertSee('Update to v2.2.3');
    }

    public function test_a_running_job_shows_who_is_updating_and_no_button(): void
    {
        $this->actingAsSuperAdmin();
        $this->writeJob([
            'status' => 'running', 'user_id' => 999999, 'user_name' => 'Aminah', 'to_version' => '2.2.3',
            'created_at' => CarbonImmutable::parse('2026-12-03 21:30:00', 'Asia/Kuala_Lumpur')->getTimestamp(),
            'timezone' => 'Asia/Kuala_Lumpur',
        ]);

        $this->get(route('admin.updates.index'))->assertOk()
            ->assertSeeText('Aminah is updating the portal to v2.2.3 (started 9:30 pm)')
            ->assertDontSee('Update to v2.2.3')
            ->assertDontSee('id="update-start-form"', false);
    }

    public function test_a_blocked_flag_hides_the_button_and_shows_the_line(): void
    {
        $this->actingAsSuperAdmin();
        File::ensureDirectoryExists(UpdaterFiles::dir());
        file_put_contents(UpdaterFiles::blockedPath(), '1');

        $this->get(route('admin.updates.index'))->assertOk()
            ->assertSeeText('The update button is switched off until this is sorted.')
            ->assertDontSee('Update to v2.2.3')
            ->assertDontSee('id="update-start-form"', false);
    }

    public function test_updater_files_has_the_fixed_names(): void
    {
        $dir = storage_path('app/updater');
        $this->assertSame($dir, UpdaterFiles::dir());
        $this->assertSame("$dir/job.json", UpdaterFiles::jobPath());
        $this->assertSame("$dir/journal.json", UpdaterFiles::journalPath());
        $this->assertSame("$dir/lock", UpdaterFiles::lockPath());
        $this->assertSame("$dir/outcome.json", UpdaterFiles::outcomePath());
        $this->assertSame("$dir/pause.html", UpdaterFiles::pausePath());
        $this->assertSame("$dir/history", UpdaterFiles::historyDir());
        $this->assertSame("$dir/blocked", UpdaterFiles::blockedPath());
        $this->assertSame("$dir/webhooks", UpdaterFiles::webhooksDir());
        $this->assertSame(storage_path('framework/updating'), UpdaterFiles::guardPath());
    }
}
