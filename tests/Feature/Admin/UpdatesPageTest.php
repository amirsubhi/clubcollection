<?php

namespace Tests\Feature\Admin;

use App\Models\Club;
use App\Models\User;
use App\Support\Updates\PreChecks;
use App\Support\Updates\UpdaterHost;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** A host where every check passes; a test can take the zip extension away. */
class UpdatesPageHost extends UpdaterHost
{
    public bool $zip = true;

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
        return false;
    }

    public function updateRunning(): bool
    {
        return false;
    }

    public function databaseDriver(): string
    {
        return 'mysql';
    }

    public function databaseBackupNeedsTick(): bool
    {
        return false;
    }
}

/** The Updates page, its status endpoint and the dashboard banner and note (spec J1, J2, J3). */
class UpdatesPageTest extends TestCase
{
    private string $releaseFile;

    private UpdatesPageHost $host;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::flush();
        $this->releaseFile = tempnam(sys_get_temp_dir(), 'rel');
        file_put_contents($this->releaseFile, json_encode(['version' => '2.2.0']));
        config(['updates.release_file' => $this->releaseFile]);
        $this->host = new UpdatesPageHost;
        PreChecks::$host = $this->host;
    }

    protected function tearDown(): void
    {
        PreChecks::$host = null;
        @unlink($this->releaseFile);
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function manifest(array $over = []): array
    {
        return array_merge([
            'format' => 1, 'app' => 'clubportal', 'version' => '2.2.3', 'tag' => 'v2.2.3',
            'released_at' => '2026-12-03T00:00:00Z',
            'zip' => [
                'name' => 'clubportal-2.2.3.zip',
                'url' => 'https://github.com/amirsubhi/clubcollection/releases/download/v2.2.3/clubportal-2.2.3.zip',
                'size' => 12345, 'sha256' => str_repeat('ab', 32),
            ],
            'php_requirement' => '>=8.4.1', 'min_from_version' => '2.0.0', 'security' => false,
            'last_security_version' => null, 'summary' => null, 'manual_action' => null,
            'whats_new' => ['Receipts now show the club logo', 'Overdue reminders no longer go out twice'],
            'notes_markdown' => 'Some notes',
            'notes_url' => 'https://github.com/amirsubhi/clubcollection/releases/tag/v2.2.3',
            'signature' => null,
        ], $over);
    }

    private function release(array $over = []): void
    {
        // A fresh client each time: stubs registered earlier would otherwise answer first.
        Http::swap(new Factory);
        Http::fake(['*' => Http::response($this->manifest($over))]);
    }

    private function club(): Club
    {
        return Club::factory()->create();
    }

    public function test_a_member_gets_403_on_all_three_routes(): void
    {
        $this->actingAsMember($this->club());

        $this->get(route('admin.updates.index'))->assertForbidden();
        $this->post(route('admin.updates.check'))->assertForbidden();
        $this->getJson(route('admin.updates.status'))->assertForbidden();
    }

    public function test_a_club_admin_may_only_read_the_limited_status(): void
    {
        $this->release(['security' => true, 'last_security_version' => '2.2.3']);
        User::factory()->create(['role' => 'super_admin', 'name' => 'Aminah']);
        User::factory()->create(['role' => 'super_admin', 'name' => 'Mei Ling']);
        $this->actingAsClubAdmin($this->club());

        $this->get(route('admin.updates.index'))->assertForbidden();
        $this->post(route('admin.updates.check'))->assertForbidden();
        $this->getJson(route('admin.updates.status'))
            ->assertOk()
            ->assertExactJson(['security_waiting' => true, 'super_admins' => ['Aminah', 'Mei Ling']])
            ->assertJsonMissingPath('installed')
            ->assertJsonMissingPath('latest');
    }

    public function test_club_admin_security_waiting_only_for_a_newer_security_release(): void
    {
        $this->release(['security' => false, 'last_security_version' => '2.1.0']);
        $this->actingAsClubAdmin($this->club());
        $this->getJson(route('admin.updates.status'))->assertOk()->assertJson(['security_waiting' => false]);

        // The release's own flag alone is not enough: the installed version must be below the last security fix.
        Cache::flush();
        $this->release(['security' => true, 'last_security_version' => '2.2.0']);
        $this->getJson(route('admin.updates.status'))->assertOk()->assertJson(['security_waiting' => false]);

        Cache::flush();
        $this->release(['security' => false, 'last_security_version' => '2.2.1']);
        $this->getJson(route('admin.updates.status'))->assertOk()->assertJson(['security_waiting' => true]);
    }

    public function test_status_never_fails_when_github_is_unreachable(): void
    {
        Http::fake(['*' => Http::response('nope', 500)]);

        $this->actingAsClubAdmin($this->club());
        $this->getJson(route('admin.updates.status'))->assertOk()->assertJson(['security_waiting' => false, 'super_admins' => []]);

        auth()->logout();
        $this->actingAsSuperAdmin();
        $this->getJson(route('admin.updates.status'))->assertOk()->assertJson(['available' => false, 'latest' => null]);
    }

    public function test_super_admin_status_json_shape(): void
    {
        $this->release(['summary' => 'Fixes receipts']);
        $this->actingAsSuperAdmin();

        $this->getJson(route('admin.updates.status'))
            ->assertOk()
            ->assertExactJson([
                'installed' => '2.2.0',
                'available' => true,
                'latest' => ['version' => '2.2.3', 'security' => false, 'summary' => 'Fixes receipts', 'released_at' => '2026-12-03T00:00:00Z'],
                'error' => null,
            ]);
    }

    public function test_status_security_flag_uses_the_last_security_version_not_the_raw_flag(): void
    {
        $this->release(['security' => false, 'last_security_version' => '2.2.1']);
        $this->actingAsSuperAdmin();

        $this->getJson(route('admin.updates.status'))->assertJsonPath('latest.security', true);
    }

    public function test_the_page_shows_versions_whats_new_and_the_timing_and_members_line(): void
    {
        $this->release();
        $this->actingAsSuperAdmin();

        $this->get(route('admin.updates.index'))
            ->assertOk()
            ->assertSeeText('Club Portal v2.2.3')
            ->assertSeeText('3 Dec 2026')
            ->assertSeeText("You're on v2.2.0")
            ->assertSeeText("What's new")
            ->assertSeeText('Receipts now show the club logo')
            ->assertSeeText('Overdue reminders no longer go out twice')
            ->assertSeeText('Updating takes about 2 minutes.')
            ->assertSeeText("members see a 'back in a few minutes' page")
            ->assertSeeText('Pick a quiet time, such as late evening.')
            ->assertSeeText('Show checks')
            ->assertSeeText('Check now');
    }

    public function test_notes_are_shown_as_escaped_text(): void
    {
        $this->release(['notes_markdown' => "<script>alert('x')</script> **bold**"]);
        $this->actingAsSuperAdmin();

        $this->get(route('admin.updates.index'))
            ->assertOk()
            ->assertSee('&lt;script&gt;alert(&#039;x&#039;)&lt;/script&gt; **bold**', false)
            ->assertDontSee("<script>alert('x')</script>", false)
            ->assertSeeText('Technical details');
    }

    public function test_the_page_lists_the_pre_check_rows(): void
    {
        $this->release();
        $this->actingAsSuperAdmin();

        $this->get(route('admin.updates.index'))
            ->assertSeeText('Your site is ready to update')
            ->assertSeeText("Your host's PHP version is fine")
            ->assertSeeText('There is enough free space');
    }

    public function test_stage_one_has_no_button_and_links_the_manual_steps(): void
    {
        $this->release();
        $this->actingAsSuperAdmin();

        $this->get(route('admin.updates.index'))
            ->assertOk()
            ->assertSeeText('To update, follow the upgrade steps')
            ->assertSee('href="https://github.com/amirsubhi/clubcollection/blob/master/docs/RELEASE.md#2-upgrade-a-site-with-the-zip"', false)
            ->assertDontSee('Update to v2.2.3')
            ->assertDontSee('Update now');
    }

    public function test_a_failing_host_check_shows_the_summary_the_host_message_and_rows_under_show_checks(): void
    {
        $this->release();
        $this->host->zip = false;
        $this->actingAsSuperAdmin();

        $html = $this->get(route('admin.updates.index'))
            ->assertOk()
            ->assertSeeText("Your site can't update itself on this host yet.")
            ->assertSeeText('Copy message for my host')
            ->assertSeeText('PHP zip')
            ->assertDontSeeText('Your site is ready to update')
            ->assertDontSeeText('To update, follow the upgrade steps')
            ->getContent();

        // The individual rows live inside the "Show checks" block, after the summary.
        $this->assertLessThan(strpos($html, 'Show checks'), strpos($html, "can't update itself on this host yet"));
        $this->assertLessThan(strpos($html, 'There is enough free space'), strpos($html, 'Show checks'));
    }

    public function test_a_security_release_shows_the_security_line_and_a_below_last_security_install_does_too(): void
    {
        $this->release(['security' => true, 'last_security_version' => '2.2.3']);
        $this->actingAsSuperAdmin();
        $this->get(route('admin.updates.index'))
            ->assertSeeText('This update fixes a security problem. Updating soon keeps your members\' details safe.');

        Cache::flush();
        $this->release(['security' => false, 'last_security_version' => '2.2.1']);
        $this->get(route('admin.updates.index'))
            ->assertSeeText('This update fixes a security problem.');

        Cache::flush();
        $this->release(['security' => true, 'last_security_version' => '2.1.0']);
        $this->get(route('admin.updates.index'))
            ->assertDontSeeText('This update fixes a security problem.');
    }

    public function test_the_page_uses_none_of_the_jargon_words_outside_technical_details_and_the_host_message(): void
    {
        $this->release(['notes_markdown' => 'swap migrate unpack opcache journal maintenance mode']);
        $this->actingAsSuperAdmin();

        $strip = function (string $html): string {
            $html = preg_replace('#<details[^>]*data-technical.*?</details>#s', '', $html);
            $html = preg_replace('#<textarea.*?</textarea>#s', '', $html);
            $html = preg_replace('#<script.*?</script>#s', '', $html);

            return strtolower(strip_tags($html));
        };
        $words = ['swap', 'migrate', 'unpack', 'opcache', 'journal', 'maintenance mode'];

        foreach ([true, false] as $hostOk) {
            $this->host->zip = $hostOk;
            $text = $strip($this->get(route('admin.updates.index'))->assertOk()->getContent());
            foreach ($words as $word) {
                $this->assertStringNotContainsString($word, $text, "page contains '$word' (zip ok: ".var_export($hostOk, true).')');
            }
        }
    }

    public function test_a_dev_install_shows_the_git_line_and_no_button(): void
    {
        unlink($this->releaseFile);
        Http::fake();
        $this->actingAsSuperAdmin();

        $this->get(route('admin.updates.index'))
            ->assertOk()
            ->assertSeeText('This copy was not installed from a release zip: update it with git.')
            ->assertDontSeeText('To update, follow the upgrade steps');
        Http::assertNothingSent();
    }

    public function test_an_up_to_date_install_says_so(): void
    {
        $this->release(['version' => '2.2.0', 'tag' => 'v2.2.0']);
        $this->actingAsSuperAdmin();

        $this->get(route('admin.updates.index'))
            ->assertOk()
            ->assertSeeText("You're up to date")
            ->assertDontSeeText('To update, follow the upgrade steps');
    }

    public function test_check_now_bypasses_the_cache(): void
    {
        $this->release();
        $this->actingAsSuperAdmin();

        $this->get(route('admin.updates.index'))->assertOk();
        $this->post(route('admin.updates.check'))->assertRedirect(route('admin.updates.index'));

        Http::assertSentCount(2);
    }

    public function test_the_banner_is_on_home_for_a_super_admin_only(): void
    {
        $this->actingAsSuperAdmin();
        $this->get(route('home'))
            ->assertOk()
            ->assertSee('id="update-banner"', false)
            ->assertSee(route('admin.updates.status'), false)
            ->assertDontSee('id="update-note"', false);

        auth()->logout();
        $club = $this->club();
        $this->actingAsClubAdmin($club);
        $this->get(route('home'))
            ->assertOk()
            ->assertDontSee('id="update-banner"', false)
            ->assertSee('id="update-note"', false);
    }

    public function test_the_club_admin_dashboard_carries_the_status_url_for_the_note(): void
    {
        $club = $this->club();
        $this->actingAsClubAdmin($club);

        $this->get(route('admin.clubs.dashboard', $club))
            ->assertInertia(fn ($page) => $page->where('urls.updateStatus', route('admin.updates.status')));
    }

    public function test_the_super_admin_menu_links_to_updates(): void
    {
        $this->actingAsSuperAdmin();

        $this->get(route('home'))->assertSee(route('admin.updates.index'), false)->assertSeeText('Updates');
    }
}
