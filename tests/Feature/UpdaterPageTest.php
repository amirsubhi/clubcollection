<?php

namespace Tests\Feature;

use ClubPortalUpdater\Context;
use ClubPortalUpdater\Journal;
use ClubPortalUpdater\Maintenance;
use ClubPortalUpdater\Outcome;
use ClubPortalUpdater\Page;
use Tests\TestCase;
use Tests\Unit\Updater\UpdateRunFixture;

require_once __DIR__.'/../../public/update/lib/load.php';

/**
 * public/update/ (Page::handle, which public/update/index.php calls): the runner page, the step endpoint and the result
 * screens, against a scratch app folder with fake Laravel boots.
 */
class UpdaterPageTest extends TestCase
{
    use UpdateRunFixture;

    private const SCREEN_STEPS = [
        'Checking your site is ready',
        'Downloading Club Portal v2.2.1',
        "Saving a copy of your club's data",
        'Checking the new files',
        'Pausing the portal for members',
        'Switching to the new version',
        "Updating your club's records to the new version",
        'Checking everything works',
        'Opening the portal again',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeSite();
    }

    protected function tearDown(): void
    {
        $this->cleanTempDirs();
        parent::tearDown();
    }

    private function page(?string $cookie = null): array
    {
        return Page::handle($this->site, ['method' => 'GET', 'cookies' => $cookie === null ? [] : ['cp_update' => $cookie]],
            fn (string $app): Context => $this->context());
    }

    private function step(string $step, ?string $cookie, array $server = [], string $body = '{}'): array
    {
        $response = Page::handle($this->site, [
            'method' => 'POST',
            'query' => ['step' => $step],
            'cookies' => $cookie === null ? [] : ['cp_update' => $cookie],
            'server' => $server + ['HTTP_X_UPDATER' => '1', 'HTTP_HOST' => 'club.example'],
            'body' => $body,
        ], fn (string $app): Context => $this->context());
        $response['json'] = json_decode($response['body'], true);

        return $response;
    }

    /** The text a person reads: no scripts, styles or Show details. */
    private function visibleText(string $html): string
    {
        $html = (string) preg_replace('~<(script|style|details)\b.*?</\1>~is', ' ', $html);

        return html_entity_decode(strip_tags($html), ENT_QUOTES);
    }

    /** An ended update: the history entry, and the job in its final state as the steps leave it. */
    private function writeHistory(array $o): void
    {
        $job = json_decode((string) file_get_contents($this->dir.'/job.json'), true);
        $job['status'] = ['finished' => 'finished', 'undone' => 'undone', 'blocked' => 'rolled_back', 'rolled_back' => 'rolled_back'][$o['outcome']] ?? 'failed';
        file_put_contents($this->dir.'/job.json', json_encode($job));
        Outcome::write($this->dir, $o + ['from' => '2.2.0', 'to' => '2.2.1', 'at' => time(), 'started_at' => time() - 120, 'timezone' => 'Asia/Kuala_Lumpur']);
    }

    // ------------------------------------------------------------------ the runner page

    public function test_the_runner_page_lists_the_nine_steps_and_keeps_the_screen_on(): void
    {
        $r = $this->page($this->token);

        $this->assertSame(200, $r['status']);
        $html = $r['body'];
        foreach (self::SCREEN_STEPS as $name) {
            $this->assertStringContainsString(htmlspecialchars($name, ENT_QUOTES), $html);
        }
        $this->assertSame(9, substr_count($html, '<li data-row='));
        $this->assertStringContainsString('Keep this page open and your screen on until it says Done.', $html);
        $this->assertStringContainsString('class="keep bold"', $html, 'plain http: no wake lock, so the line is bold');
        foreach (["navigator.wakeLock.request('screen')", "'visibilitychange'", "'beforeunload'", 'Welcome back, carrying on',
            'Connection lost, retrying', 'This update is running in another tab', 'RETRY_DELAYS = [5000, 10000, 15000]', "attempt('rollback'"] as $code) {
            $this->assertStringContainsString($code, $html);
        }
        $this->assertPlain($this->visibleText($html));
        $this->assertDoesNotMatchRegularExpression('~https?://~', $html, 'no external addresses on the runner page');
        $this->assertStringContainsString("script-src 'nonce-", $r['headers']['Content-Security-Policy']);
        $this->assertSame('no-store', $r['headers']['Cache-Control']);
    }

    public function test_on_https_the_line_is_not_bold_from_the_start(): void
    {
        $r = Page::handle($this->site, ['method' => 'GET', 'cookies' => ['cp_update' => $this->token], 'server' => ['HTTPS' => 'on']],
            fn (string $app): Context => $this->context());

        $this->assertStringContainsString('class="keep"', $r['body']);
    }

    public function test_the_runner_page_shows_the_steps_already_done(): void
    {
        $this->step('prechecks', $this->token);

        $html = $this->page($this->token)['body'];

        $this->assertStringContainsString('<li data-row="prechecks" class="state-done">', $html);
        $this->assertStringContainsString('"next":"download"', $html);
    }

    // ------------------------------------------------------------------ the step endpoint

    public function test_a_step_needs_the_token(): void
    {
        foreach ([null, bin2hex(random_bytes(32))] as $cookie) {
            $r = $this->step('prechecks', $cookie);
            $this->assertSame(403, $r['status']);
            $this->assertSame('denied', $r['json']['status']);
        }
        $this->assertFileDoesNotExist($this->dir.'/journal.json');
    }

    public function test_a_step_from_another_site_is_refused(): void
    {
        $this->assertSame(403, $this->step('prechecks', $this->token, ['HTTP_ORIGIN' => 'https://evil.example'])['status']);
        $this->assertSame(403, $this->step('prechecks', $this->token, ['HTTP_X_UPDATER' => ''])['status']);
        $this->assertSame(200, $this->step('prechecks', $this->token, ['HTTP_ORIGIN' => 'https://club.example'])['status']);
    }

    public function test_steps_answer_json_and_the_pause_gives_the_bypass_cookie(): void
    {
        $next = 'prechecks';
        $cookies = [];
        while ($next !== 'swap') {
            $r = $this->step($next, $this->token);
            $this->assertSame(200, $r['status']);
            $this->assertContains($r['json']['status'], ['done', 'more']);
            $next = $r['json']['next'];
            $cookies = array_merge($cookies, $r['cookies']);
        }

        $this->assertSame(Maintenance::COOKIE, $cookies[0]['name'] ?? null);
        $this->assertSame('application/json; charset=UTF-8', $r['headers']['Content-Type']);
    }

    public function test_a_watching_tab_reads_the_state_without_changing_it(): void
    {
        $this->step('prechecks', $this->token);
        $before = (string) file_get_contents($this->dir.'/journal.json');
        $job = (string) file_get_contents($this->dir.'/job.json');

        $r = $this->step('status', $this->token);

        $this->assertSame('watch', $r['json']['status']);
        $this->assertSame('download', $r['json']['next']);
        $this->assertSame(['prechecks' => 'done'], $r['json']['steps']);
        $this->assertFalse($r['json']['locked']);
        $this->assertSame($before, (string) file_get_contents($this->dir.'/journal.json'));
        $this->assertSame($job, (string) file_get_contents($this->dir.'/job.json'));
    }

    // ------------------------------------------------------------------ result screens

    public function test_a_running_second_attempt_is_shown_instead_of_the_first_attempts_result(): void
    {
        $this->writeHistory(['outcome' => 'rolled_back', 'step' => 'health', 'reason' => 'x']);
        $this->writeReadyJob(['status' => 'running']);

        $text = $this->visibleText($this->page()['body']);

        $this->assertStringContainsString('is updating the portal', $text);
        $this->assertStringNotContainsString('put it back', $text);
    }

    public function test_a_failed_step_tells_the_page_whether_the_update_has_ended(): void
    {
        $this->laravel->codes['backup:run'] = 1;
        $next = 'prechecks';
        for ($i = 0; $i < 10 && $next !== null; $i++) {
            $r = $this->step($next, $this->token);
            $next = $r['json']['next'];
        }

        $this->assertSame(['failed', true], [$r['json']['status'], $r['json']['ended']]);
        $this->assertStringContainsString("j.status === 'failed' && j.ended", $this->runnerHtml());
    }

    private function runnerHtml(): string
    {
        $this->writeReadyJob();

        return $this->page($this->token)['body'];
    }

    public function test_without_an_update_the_page_says_so(): void
    {
        unlink($this->dir.'/job.json');

        $r = $this->page();

        $this->assertStringContainsString('No update in progress', $r['body']);
        $this->assertStringContainsString('href="/admin/updates"', $r['body']);
    }

    public function test_someone_else_sees_that_an_update_is_running(): void
    {
        $this->step('prechecks', $this->token);

        $text = $this->visibleText($this->page()['body']);

        $this->assertStringContainsString('A super admin is updating the portal to v2.2.1', $text);
        $this->assertStringNotContainsString('Aminah', $text, 'no names for visitors who are not signed in');
        $this->assertStringNotContainsString('Keep this page open', $text);
    }

    public function test_the_done_screen(): void
    {
        $this->writeHistory(['outcome' => 'finished', 'step' => 'finish', 'whats_new' => ['Receipts now show the club\'s logo'], 'site_local' => ['app/.user.ini']]);

        $text = $this->visibleText($this->page($this->token)['body']);

        $this->assertStringContainsString('Done. Club Portal is now v2.2.1.', $text);
        $this->assertStringContainsString('Members can use the portal again.', $text);
        $this->assertStringContainsString('Receipts now show the club\'s logo', $text);
        $this->assertStringContainsString('A copy of your club\'s data from before the update is kept on your site', $text);
        $this->assertStringContainsString('app/.user.ini', $text);
        $this->assertStringContainsString('Back to the dashboard', $text);
        $this->assertPlain($text);
    }

    public function test_the_undone_screen_uses_the_job_timezone(): void
    {
        $at = (new \DateTimeImmutable('2026-12-03 13:42:00', new \DateTimeZone('UTC')))->getTimestamp(); // 9:42 pm in Kuala Lumpur
        Outcome::write($this->dir, ['outcome' => 'undone', 'from' => '2.2.0', 'to' => '2.2.1', 'at' => $at, 'started_at' => $at - 900, 'timezone' => 'Asia/Kuala_Lumpur']);
        $now = $at + 60;
        $c = fn (string $app): Context => tap($this->context(), function (Context $c) use ($now) {
            $c->clock = static fn (): int => $now;
        });

        $text = $this->visibleText(Page::handle($this->site, ['method' => 'GET'], $c)['body']);

        $this->assertStringContainsString("The update to v2.2.1 didn't finish, because this page was closed or the phone's screen went off.", $text);
        $this->assertStringContainsString('Your portal was put back on v2.2.0 at 9:42 pm and is working normally. Nothing was lost.', $text);
        $this->assertStringContainsString('Try again', $text);
    }

    public function test_the_put_back_screen_with_its_report_for_the_token_holder(): void
    {
        $this->writeHistory(['outcome' => 'rolled_back', 'step' => 'health', 'reason' => "The new version showed an error on this host's settings.", 'log' => ['GET /login answered HTTP 500.']]);

        $r = $this->page($this->token);
        $text = $this->visibleText($r['body']);

        $this->assertStringContainsString("The update didn't work on your site, so we put it back.", $text);
        $this->assertStringContainsString('Your portal is on v2.2.0 and working normally.', $text);
        $this->assertStringContainsString('Nothing was lost.', $text);
        $this->assertStringContainsString("What happened: The new version showed an error on this host's settings.", $text);
        $this->assertStringContainsString('What to do: Nothing right now. Your portal is safe on v2.2.0.', $text);
        foreach (['Copy report', 'Report a problem', 'Back to the dashboard'] as $button) {
            $this->assertStringContainsString($button, $text);
        }
        $this->assertPlain($text);
        preg_match('~href="(https://github\.com/[^"]+)"~', $r['body'], $m);
        $url = html_entity_decode($m[1] ?? '');
        $this->assertStringStartsWith(Page::ISSUE_URL.'?title=', $url);
        $this->assertStringContainsString(rawurlencode('GET /login answered HTTP 500.'), $url);
        preg_match_all('~https?://[^"\'\s<)]+~', $r['body'], $all);
        foreach ($all[0] as $address) {
            $this->assertStringStartsWith(Page::ISSUE_URL, html_entity_decode($address), 'the only external address is the issue link');
        }
    }

    public function test_others_see_the_result_without_the_report(): void
    {
        $this->writeHistory(['outcome' => 'rolled_back', 'step' => 'health', 'reason' => 'x', 'log' => ['GET /login answered HTTP 500.']]);

        $html = $this->page()['body'];

        $this->assertStringContainsString("The update didn't work on your site, so we put it back.", $this->visibleText($html));
        $this->assertStringNotContainsString('Copy report', $html);
        $this->assertStringNotContainsString('answered HTTP 500', $html);
        $this->assertStringContainsString('The full report is on the Updates page.', $html);
    }

    public function test_the_blocked_screen_says_a_person_must_restore_the_data(): void
    {
        $this->writeHistory(['outcome' => 'blocked', 'step' => 'migrate', 'reason' => "One change to your club's records didn't finish."]);

        $text = $this->visibleText($this->page($this->token)['body']);

        $this->assertStringContainsString("The portal is back on v2.2.0, but one change to your club's records didn't finish.", $text);
        $this->assertStringContainsString('The update button is switched off until this is sorted.', $text);
        $this->assertStringContainsString('they may need to restore the copy of your data saved at', $text);
        $this->assertPlain($text);
    }

    public function test_the_failed_screen_lists_the_checks(): void
    {
        $this->writeHistory(['outcome' => 'failed', 'step' => 'prechecks', 'reason' => "Your site wasn't ready to update.", 'prechecks_failed' => ['No member is paying right now: A member is paying a fee right now.'], 'checks_failed' => ['No member is paying right now: A member is paying a fee right now.']]);

        $text = $this->visibleText($this->page($this->token)['body']);

        $this->assertStringContainsString('The update to v2.2.1 stopped before anything was changed.', $text);
        $this->assertStringContainsString('A member is paying a fee right now.', $text);
        $this->assertPlain($text);
    }

    public function test_a_whole_update_through_the_page_ends_on_the_done_screen(): void
    {
        $next = 'prechecks';
        for ($i = 0; $i < 50 && $next !== null; $i++) {
            $r = $this->step($next, $this->token, [], (string) json_encode(['up' => 200, 'login' => 200, 'login_marker' => true]));
            $next = $r['json']['next'];
        }

        $this->assertSame(['finish', 'done'], [$r['json']['step'], $r['json']['status']]);
        $this->assertStringContainsString('Done. Club Portal is now v2.2.1.', $this->page($this->token)['body']);
    }

    // ------------------------------------------------------------------ could not be undone

    public function test_an_unreadable_journal_while_paused_gives_the_manual_way_out(): void
    {
        file_put_contents($this->site.'/storage/framework/updating', (string) time());
        file_put_contents($this->dir.'/journal.json', '{broken');

        $r = $this->page();
        $text = $this->visibleText($r['body']);

        $this->assertStringContainsString('The update could not be undone by itself.', $text);
        $this->assertStringContainsString('Members see a page saying the portal is being updated', $text);
        $this->assertStringContainsString('.clubportal-old-2.2.0', $text);
        $this->assertStringContainsString('delete the files updating, down and maintenance.php', $text);
        $this->assertStringContainsString('Copy report', $text);
        $this->assertStringContainsString('id="report"', $r['body']);
    }

    public function test_a_failed_undo_gives_the_manual_way_out(): void
    {
        $c = $this->context();
        $this->runSteps($c, 'migrate');
        $j = Journal::open($this->dir);
        $j->set('recovery_error', ['at' => time(), 'message' => 'The old files could not all be put back: app is in use.']);

        $r = $this->page($this->token);

        $this->assertStringContainsString('The update could not be undone by itself.', $r['body']);
        $this->assertStringContainsString('app is in use', $r['body']);
    }

    // ------------------------------------------------------------------ the report link and index.php

    public function test_a_long_report_is_shortened_to_fit_the_link(): void
    {
        $report = str_repeat("Last log line with some words in it\n", 1000);

        $url = Page::issueUrl('Update to v2.2.1: rolled back', $report);

        $this->assertLessThanOrEqual(Page::MAX_URL, strlen($url));
        $this->assertStringEndsWith(rawurlencode('[report shortened]'), $url);
        $this->assertSame(Page::ISSUE_URL.'?title='.rawurlencode('t').'&body='.rawurlencode('short'), Page::issueUrl('t', 'short'));
    }

    public function test_the_real_laravel_boot_seam_renders_the_pause_page(): void
    {
        $script = sprintf('require %s; echo ClubPortalUpdater\LaravelApp::boot(%s)->pausePage("ms", "Kelab Sukan");',
            var_export(base_path('public/update/lib/load.php'), true), var_export(base_path(), true));

        exec(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($script).' 2>&1', $out, $code);

        $this->assertSame(0, $code, implode("\n", $out));
        $this->assertStringContainsString('Kelab Sukan sedang dikemas kini', implode("\n", $out));
    }

    public function test_index_php_serves_the_page(): void
    {
        unlink($this->dir.'/job.json');
        $script = sprintf('define("CLUBPORTAL_UPDATER_APP", %s); $_SERVER["REQUEST_METHOD"] = "GET"; require %s;',
            var_export($this->site, true), var_export(base_path('public/update/index.php'), true));

        exec(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($script).' 2>&1', $out, $code);

        $this->assertSame(0, $code, implode("\n", $out));
        $this->assertStringContainsString('No update in progress', implode("\n", $out));
    }
}
