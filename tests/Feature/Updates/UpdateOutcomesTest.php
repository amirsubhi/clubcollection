<?php

namespace Tests\Feature\Updates;

use App\Mail\PortalUpdateResult;
use App\Models\AuditLog;
use App\Models\Club;
use App\Models\User;
use App\Support\Updates\UpdateOutcomes;
use App\Support\Updates\UpdaterFiles;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class UpdateOutcomesTest extends TestCase
{
    use UsesUpdaterStorage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->useUpdaterStorage();
        config(['app.timezone' => 'Asia/Kuala_Lumpur']);
    }

    protected function tearDown(): void
    {
        $this->dropUpdaterStorage();
        parent::tearDown();
    }

    public function test_the_outcome_is_consumed_once_and_a_second_request_finds_nothing(): void
    {
        Mail::fake();
        User::factory()->create(['role' => 'super_admin']);
        $this->writeOutcome();

        $this->get('/login')->assertOk();
        $this->assertFileDoesNotExist(UpdaterFiles::outcomePath());
        $this->assertSame([], glob(UpdaterFiles::dir().'/outcome.json*') ?: []);
        $this->assertSame(1, AuditLog::where('action', 'portal.update_rolled_back')->count());

        $this->get('/login')->assertOk();
        $this->assertSame(1, AuditLog::where('action', 'portal.update_rolled_back')->count());
        Mail::assertSent(PortalUpdateResult::class, 1);
    }

    public function test_a_request_that_loses_the_rename_does_nothing(): void
    {
        Mail::fake();
        $this->writeOutcome();
        // Someone else already took it: only the file with the temporary name exists.
        rename(UpdaterFiles::outcomePath(), UpdaterFiles::outcomePath().'.consuming-abc');

        UpdateOutcomes::consume();

        $this->assertSame(0, AuditLog::count());
    }

    /** @return array<string, array{string, string}> */
    public static function outcomes(): array
    {
        return [
            'finished' => ['finished', 'portal.update_finished'],
            'rolled_back' => ['rolled_back', 'portal.update_rolled_back'],
            'undone' => ['undone', 'portal.update_rolled_back'],
            'blocked' => ['blocked', 'portal.update_rolled_back'],
            'failed' => ['failed', 'portal.update_failed_check'],
        ];
    }

    #[DataProvider('outcomes')]
    public function test_each_outcome_writes_its_audit_row_with_the_details(string $outcome, string $action): void
    {
        Mail::fake();
        $this->writeOutcome(['outcome' => $outcome, 'step' => 'migrate', 'reason' => 'a reason']);

        UpdateOutcomes::consume();

        $row = AuditLog::where('action', $action)->first();
        $this->assertNotNull($row);
        $this->assertSame('System', $row->user_name);
        $this->assertSame(['outcome' => $outcome, 'from' => '2.2.0', 'to' => '2.2.1', 'step' => 'migrate', 'reason' => 'a reason'], $row->new_values);
    }

    public function test_the_history_file_is_kept_and_written_when_the_updater_did_not(): void
    {
        Mail::fake();
        $o = $this->writeOutcome(['report' => 'R'], history: false);

        UpdateOutcomes::consume();

        $file = UpdaterFiles::historyDir().'/'.gmdate('YmdHis', $o['started_at']).'-2.2.0-2.2.1.json';
        $this->assertFileExists($file);
        $this->assertSame('rolled_back', json_decode(file_get_contents($file), true)['outcome']);
    }

    public function test_an_existing_history_file_is_left_alone(): void
    {
        Mail::fake();
        $o = $this->writeOutcome(['extra' => 'kept']);
        $file = UpdaterFiles::historyDir().'/'.gmdate('YmdHis', $o['started_at']).'-2.2.0-2.2.1.json';

        UpdateOutcomes::consume();

        $this->assertSame('kept', json_decode(file_get_contents($file), true)['extra']);
    }

    public function test_a_blocked_outcome_writes_the_blocked_flag_and_others_do_not(): void
    {
        Mail::fake();
        $this->writeOutcome(['outcome' => 'rolled_back']);
        UpdateOutcomes::consume();
        $this->assertFileDoesNotExist(UpdaterFiles::blockedPath());

        $this->writeOutcome(['outcome' => 'blocked']);
        UpdateOutcomes::consume();
        $this->assertFileExists(UpdaterFiles::blockedPath());
    }

    public function test_nothing_is_consumed_while_the_swap_guard_is_up(): void
    {
        Mail::fake();
        $this->writeOutcome();
        touch(UpdaterFiles::guardPath());

        UpdateOutcomes::consume();
        $this->assertFileExists(UpdaterFiles::outcomePath());
        $this->assertSame(0, AuditLog::count());

        unlink(UpdaterFiles::guardPath());
        UpdateOutcomes::consume();
        $this->assertFileDoesNotExist(UpdaterFiles::outcomePath());
    }

    public function test_every_super_admin_gets_the_email_with_the_report_and_club_admins_do_not(): void
    {
        Mail::fake();
        $a = User::factory()->create(['role' => 'super_admin']);
        $b = User::factory()->create(['role' => 'super_admin']);
        $club = Club::factory()->create();
        $clubAdmin = $this->actingAsClubAdmin($club);
        $this->writeOutcome(['outcome' => 'undone']);

        UpdateOutcomes::consume();

        Mail::assertSent(PortalUpdateResult::class, 2);
        Mail::assertSent(PortalUpdateResult::class, fn ($m) => $m->hasTo($a->email) && $m->report !== '' && str_contains($m->text['title'], "didn't finish"));
        Mail::assertSent(PortalUpdateResult::class, fn ($m) => $m->hasTo($b->email));
        Mail::assertNotSent(PortalUpdateResult::class, fn ($m) => $m->hasTo($clubAdmin->email));
    }

    public function test_the_email_is_never_queued_and_carries_the_screen_text(): void
    {
        $this->assertFalse((new PortalUpdateResult(['subject' => 's', 'title' => 't', 'lines' => [], 'what_happened' => null, 'what_to_do' => null]))
            instanceof ShouldQueue);

        $html = (new PortalUpdateResult(UpdateOutcomes::describe(['outcome' => 'finished', 'from' => '2.2.0', 'to' => '2.2.1']), 'THE REPORT'))->render();
        $this->assertStringContainsString('Done. Club Portal is now v2.2.1.', $html);
        $this->assertStringContainsString('Members can use the portal again.', $html);
        $this->assertStringContainsString('THE REPORT', $html);
    }

    public function test_a_throwing_mailer_does_not_break_the_request(): void
    {
        Mail::shouldReceive('to')->andThrow(new RuntimeException('smtp down'));
        User::factory()->create(['role' => 'super_admin']);
        $this->writeOutcome();

        $this->get('/login')->assertOk();

        $this->assertFileDoesNotExist(UpdaterFiles::outcomePath());
        $this->assertSame(1, AuditLog::where('action', 'portal.update_rolled_back')->count());
    }

    public function test_the_texts_follow_the_journeys_and_cope_with_unknown_versions(): void
    {
        $undone = UpdateOutcomes::describe(['outcome' => 'undone', 'from' => '2.2.0', 'to' => '2.2.1', 'at' => 1796330000, 'timezone' => 'UTC']);
        $this->assertSame("The update to v2.2.1 didn't finish, because the update page was closed or the phone's screen went off.", $undone['title']);
        $this->assertSame(['Your portal was put back on v2.2.0 at 8:33 pm and is working normally. Nothing was lost.'], $undone['lines']);

        $unknown = UpdateOutcomes::describe(['outcome' => 'undone', 'from' => '?', 'to' => '?', 'at' => 1796330000]);
        $this->assertStringStartsWith("The update didn't finish", $unknown['title']);
        $this->assertStringNotContainsString('?', json_encode($unknown));

        $blocked = UpdateOutcomes::describe(['outcome' => 'blocked', 'from' => '2.2.0', 'to' => '2.2.1', 'started_at' => 1796329800, 'timezone' => 'UTC']);
        $this->assertStringContainsString("one change to your club's records didn't finish", $blocked['title']);
        $this->assertStringContainsString('switched off', $blocked['lines'][0]);
        $this->assertStringContainsString('restore the copy of your data', $blocked['what_to_do']);

        $rolled = UpdateOutcomes::describe(['outcome' => 'rolled_back', 'from' => '2.2.0', 'to' => '2.2.1', 'reason' => 'It broke.']);
        $this->assertSame("The update didn't work on your site, so we put it back.", $rolled['title']);
        $this->assertSame('It broke.', $rolled['what_happened']);
        $this->assertStringContainsString('send the report to whoever installed the portal', $rolled['what_to_do']);
    }

    // ── J7 notice ───────────────────────────────────────────────────────────────────────────────────────────────

    public function test_the_notice_shows_to_every_super_admin_until_each_dismisses_it(): void
    {
        $a = User::factory()->create(['role' => 'super_admin']);
        $b = User::factory()->create(['role' => 'super_admin']);
        $o = $this->writeOutcome(['outcome' => 'undone']);
        rename(UpdaterFiles::outcomePath(), UpdaterFiles::dir().'/x'); // history only

        $this->actingAs($a)->get(route('home'))->assertSee('id="update-outcome-notice"', false)
            ->assertSeeText("didn't finish and was undone at")->assertSeeText('Try again')->assertSeeText('Dismiss');
        $this->actingAs($b)->get(route('home'))->assertSee('id="update-outcome-notice"', false);

        $file = basename(glob(UpdaterFiles::historyDir().'/*.json')[0]);
        $this->actingAs($a)->post(route('admin.updates.dismiss'), ['file' => $file])->assertRedirect();

        $this->actingAs($a)->get(route('home'))->assertDontSee('id="update-outcome-notice"', false);
        $this->actingAs($b)->get(route('home'))->assertSee('id="update-outcome-notice"', false);
        $this->assertSame([$a->id], json_decode(file_get_contents(UpdaterFiles::historyDir().'/'.$file), true)['dismissed_by']);
    }

    public function test_the_notice_goes_after_a_later_update_finishes_and_never_shows_to_club_admins(): void
    {
        $a = User::factory()->create(['role' => 'super_admin']);
        $this->writeOutcome(['outcome' => 'rolled_back']);
        rename(UpdaterFiles::outcomePath(), UpdaterFiles::dir().'/x');
        $club = Club::factory()->create();

        $clubAdmin = $this->actingAsClubAdmin($club);
        $this->get(route('home'))->assertDontSee('id="update-outcome-notice"', false);
        $this->assertNull(UpdateOutcomes::noticeFor($clubAdmin));
        $this->assertNotNull(UpdateOutcomes::noticeFor($a));

        $this->writeOutcome(['outcome' => 'finished', 'started_at' => 1796339800, 'at' => 1796340000]);
        rename(UpdaterFiles::outcomePath(), UpdaterFiles::dir().'/y');
        $this->assertNull(UpdateOutcomes::noticeFor($a));
    }

    public function test_dismiss_refuses_a_path_that_is_not_a_history_file(): void
    {
        $a = User::factory()->create(['role' => 'super_admin']);
        $this->assertFalse(UpdateOutcomes::dismiss($a, '../job.json'));
        $this->assertFalse(UpdateOutcomes::dismiss($a, '20261201000000-2.2.0-2.2.1.json'));
    }

    public function test_a_club_admin_cannot_dismiss(): void
    {
        $club = Club::factory()->create();
        $this->actingAsClubAdmin($club);
        $this->post(route('admin.updates.dismiss'), ['file' => 'x'])->assertForbidden();
    }
}
