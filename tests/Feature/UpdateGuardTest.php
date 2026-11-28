<?php

namespace Tests\Feature;

use ClubPortalUpdater\Lock;
use ClubPortalUpdater\Maintenance;
use Tests\TestCase;
use Tests\Unit\Updater\RecoveryFixture;

require_once __DIR__.'/../../public/update/lib/load.php';
require_once __DIR__.'/../../public/update-guard.php';

/**
 * The swap guard (public/update-guard.php): the first lines of public/index.php and artisan. Every test works on a
 * scratch app folder; the real storage/framework/updating is never created (it would stop `php artisan test`).
 */
class UpdateGuardTest extends TestCase
{
    use RecoveryFixture;

    private const MESSAGE = "Club Portal is being updated; try again in a few minutes.\n";

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeApp();
        $this->writeJob();
    }

    protected function tearDown(): void
    {
        $this->cleanTempDirs();
        parent::tearDown();
    }

    private function guardUp(): void
    {
        touch($this->site.'/storage/framework/updating');
    }

    private function decide(string $method = 'GET', string $path = '/', ?string $cookie = null): ?array
    {
        return clubportal_update_guard_decide($this->site, $method, $path, $cookie);
    }

    private function cookieFor(string $secret, int $expiresAt): string
    {
        return base64_encode(json_encode(['expires_at' => $expiresAt, 'mac' => hash_hmac('sha256', (string) $expiresAt, $secret)]));
    }

    // ------------------------------------------------------------------ decide

    public function test_without_the_guard_file_every_request_continues(): void
    {
        $this->assertNull($this->decide());
        $this->assertNull($this->decide('POST', '/webhook/billplz'));
    }

    public function test_with_the_guard_file_a_page_gets_the_pause_page(): void
    {
        $this->guardUp();

        $this->assertSame(['action' => 'pause'], $this->decide('GET', '/'));
        $this->assertSame(['action' => 'pause'], $this->decide('GET', '/member/payments'));
        $this->assertSame(['action' => 'pause'], $this->decide('POST', '/login'));
        $this->assertSame(['action' => 'pause'], $this->decide('GET', '/webhook/billplz'));
        $this->assertSame(['action' => 'pause'], $this->decide('POST', '/webhook/other'));
        $this->assertSame(['action' => 'pause'], $this->decide('POST', '/x/webhook/billplz'));
    }

    public function test_a_payment_callback_is_saved(): void
    {
        $this->guardUp();

        foreach (['/webhook/billplz', '/webhook/toyyibpay', '/webhook/billplz/', '/webhook/toyyibpay/'] as $path) {
            $this->assertSame(['action' => 'save_webhook'], $this->decide('POST', $path), $path);
            $this->assertSame(['action' => 'save_webhook'], $this->decide('post', $path), $path);
        }
    }

    public function test_the_admins_bypass_cookie_goes_back_to_the_updater_until_the_switch_is_done(): void
    {
        $secret = $this->runUpdateUntil('opcache', 30);
        $cookie = Maintenance::bypassCookie($secret)['value'];

        $this->assertSame(['action' => 'to_updater'], $this->decide('GET', '/admin', $cookie));
    }

    public function test_the_admins_bypass_cookie_continues_into_the_portal_after_the_switch(): void
    {
        $secret = $this->runUpdateUntil('migrate', 30);
        $cookie = Maintenance::bypassCookie($secret)['value'];

        $this->assertNull($this->decide('GET', '/up', $cookie));
    }

    public function test_a_forged_or_expired_cookie_gets_the_pause_page(): void
    {
        $secret = $this->runUpdateUntil('migrate', 30);

        foreach ([
            'expired' => $this->cookieFor($secret, time() - 1),
            'other secret' => $this->cookieFor(Maintenance::newSecret(), time() + 600),
            'empty secret' => $this->cookieFor('', time() + 600),
            'bad mac' => base64_encode(json_encode(['expires_at' => time() + 600, 'mac' => str_repeat('0', 64)])),
            'mac not a string' => base64_encode(json_encode(['expires_at' => time() + 600, 'mac' => 5])),
            'no expiry' => base64_encode(json_encode(['mac' => 'x'])),
            'not json' => base64_encode('nope'),
            'garbage' => '%%%',
            'empty' => '',
        ] as $case => $cookie) {
            $this->assertSame(['action' => 'pause'], $this->decide('GET', '/', $cookie), $case);
        }
    }

    public function test_a_cookie_is_refused_when_the_down_file_has_no_secret(): void
    {
        $this->runUpdateUntil('migrate', 30);
        file_put_contents($this->site.'/storage/framework/down', json_encode(['status' => 503]));

        $this->assertSame(['action' => 'pause'], $this->decide('GET', '/', $this->cookieFor('', time() + 600)));
    }

    // ------------------------------------------------------------------ responses

    public function test_the_pause_page_is_a_503_with_retry_after_and_the_saved_page(): void
    {
        $this->guardUp();
        file_put_contents($this->dir.'/pause.html', '<html>Portal Kelab sedang dikemas kini</html>');

        $r = clubportal_update_guard_response($this->site, ['action' => 'pause']);

        $this->assertSame(503, $r['status']);
        $this->assertSame('120', $r['headers']['Retry-After']);
        $this->assertStringStartsWith('text/html', $r['headers']['Content-Type']);
        $this->assertSame('no-store', $r['headers']['Cache-Control']);
        $this->assertSame('<html>Portal Kelab sedang dikemas kini</html>', $r['body']);
    }

    public function test_without_a_pause_page_the_built_in_one_is_served(): void
    {
        $this->guardUp();

        $r = clubportal_update_guard_response($this->site, ['action' => 'pause']);

        $this->assertSame(503, $r['status']);
        $this->assertSame('120', $r['headers']['Retry-After']);
        $body = $r['body'];
        $this->assertStringContainsString('<meta http-equiv="refresh" content="30;url=/">', $body);
        foreach ([
            'Portal sedang dikemas kini',
            'Kembali dalam beberapa minit. Halaman ini akan dimuat semula sendiri.',
            'Jika anda baru membuat bayaran, bayaran anda selamat.',
            'Resit akan dipaparkan sebaik sahaja portal dibuka semula.',
            'sila hantar semula apabila kami kembali.',
            'Portal is being updated',
            'payment is safe',
        ] as $line) {
            $this->assertStringContainsString($line, $body);
        }
        $this->assertLessThan(strpos($body, 'Portal is being updated'), strpos($body, 'Portal sedang dikemas kini'), 'Malay first');
        $this->assertDoesNotMatchRegularExpression('~(src|href)\s*=\s*["\']?(https?:)?//~i', $body, 'no external files');
        $this->assertStringNotContainsString('<script', $body);
    }

    public function test_back_to_the_updater_is_a_redirect(): void
    {
        $r = clubportal_update_guard_response($this->site, ['action' => 'to_updater']);

        $this->assertSame(302, $r['status']);
        $this->assertSame('/update/', $r['headers']['Location']);
    }

    public function test_a_saved_callback_is_answered_503(): void
    {
        $r = clubportal_update_guard_response($this->site, ['action' => 'save_webhook']);

        $this->assertSame(503, $r['status']);
        $this->assertSame('120', $r['headers']['Retry-After']);
    }

    // ------------------------------------------------------------------ saving callbacks

    private function savedFiles(): array
    {
        return glob($this->dir.'/webhooks/*.json') ?: [];
    }

    public function test_a_callback_is_saved_with_only_the_needed_headers(): void
    {
        $saved = clubportal_update_guard_save_webhook($this->site, '/webhook/billplz/', 'id=abc&paid=true', [
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
            'HTTP_X_SIGNATURE' => 'sig',
            'HTTP_X_BILLPLZ_ID' => 'b1',
            'HTTP_X_TOYYIBPAY_REF' => 't1',
            'HTTP_COOKIE' => 'laravel_session=secret',
            'HTTP_AUTHORIZATION' => 'Bearer x',
            'HTTP_HOST' => 'example.test',
            'HTTP_X_FORWARDED_FOR' => '10.0.0.1',
            'REMOTE_ADDR' => '10.0.0.2',
            'HTTP_X_BILLPLZ_ARRAY' => ['not', 'a', 'string'],
        ]);

        $this->assertTrue($saved);
        $files = $this->savedFiles();
        $this->assertCount(1, $files);
        $this->assertMatchesRegularExpression('/^\d{16}-[0-9a-f]{12}\.json$/', basename($files[0]));
        $this->assertSame('750', sprintf('%o', fileperms($this->dir.'/webhooks') & 0777));
        $data = json_decode((string) file_get_contents($files[0]), true);
        $this->assertSame('/webhook/billplz', $data['path']);
        $this->assertSame('id=abc&paid=true', $data['body']);
        $this->assertSame([
            'content-type' => 'application/x-www-form-urlencoded',
            'x-billplz-id' => 'b1',
            'x-signature' => 'sig',
            'x-toyyibpay-ref' => 't1',
        ], $data['headers']);
        $this->assertEqualsWithDelta(time(), $data['at'], 5);
        $this->assertSame([], glob($this->dir.'/webhooks/.*.tmp') ?: []);
    }

    public function test_a_body_over_64_kb_is_refused(): void
    {
        $this->assertFalse(clubportal_update_guard_save_webhook($this->site, '/webhook/billplz', str_repeat('a', 65537), []));
        $this->assertSame([], $this->savedFiles());
        $this->assertTrue(clubportal_update_guard_save_webhook($this->site, '/webhook/billplz', str_repeat('a', 65536), []));
    }

    public function test_the_201st_callback_is_refused(): void
    {
        mkdir($this->dir.'/webhooks', 0750);
        for ($i = 0; $i < 199; $i++) {
            file_put_contents($this->dir.'/webhooks/'.sprintf('%016d', $i).'-000000000000.json', '{}');
        }
        $this->assertTrue(clubportal_update_guard_save_webhook($this->site, '/webhook/toyyibpay', 'a=1', []));
        $this->assertCount(200, $this->savedFiles());

        $this->assertFalse(clubportal_update_guard_save_webhook($this->site, '/webhook/toyyibpay', 'a=2', []));
        $this->assertCount(200, $this->savedFiles());
    }

    public function test_the_file_name_and_place_never_come_from_the_request(): void
    {
        foreach (['/webhook/../../x', '/webhook/billplz/../../x', '../job', '/webhook/billplz%2f..'] as $path) {
            $this->assertFalse(clubportal_update_guard_save_webhook($this->site, $path, 'a=1', []), $path);
        }
        $this->assertSame([], $this->savedFiles());
        $this->assertTrue(clubportal_update_guard_save_webhook($this->site, '/webhook/billplz', 'a=1', ['HTTP_X_BILLPLZ_NAME' => '../../evil']));
        $this->assertStringNotContainsString('evil', basename($this->savedFiles()[0]));
    }

    public function test_a_body_that_is_not_utf8_is_not_saved(): void
    {
        $this->assertFalse(clubportal_update_guard_save_webhook($this->site, '/webhook/billplz', "a=\xff\xfe", []));
        $this->assertSame([], $this->savedFiles());
    }

    // ------------------------------------------------------------------ recovery without a token

    public function test_stale_journal_after_swap_rolls_back_without_token(): void
    {
        $this->runUpdateUntil('migrate', 660);
        $this->assertSame('<?php // new A', $this->read('app/A.php'));

        $this->assertTrue(clubportal_update_guard_recover($this->site));

        $this->assertNull($this->decide('GET', '/'));
        $this->assertOldTree();
        $this->assertMaintenanceGone();
        $this->assertSame('undone', json_decode((string) file_get_contents($this->dir.'/outcome.json'), true)['outcome']);
        $this->assertSame('undone', $this->job()['status']);
        $this->assertCount(1, glob($this->dir.'/history/*.json'));
    }

    public function test_stale_before_the_switch_lifts_the_pause(): void
    {
        $this->runUpdateUntil('swap', 660);

        $this->assertTrue(clubportal_update_guard_recover($this->site));

        $this->assertNull($this->decide('GET', '/'));
        $this->assertMaintenanceGone();
        $this->assertSame('undone', $this->job()['status']);
    }

    public function test_a_recent_journal_is_not_recovered(): void
    {
        $this->runUpdateUntil('migrate', 540);

        $this->assertFalse(clubportal_update_guard_recover($this->site));

        $this->assertSame(['action' => 'pause'], $this->decide('GET', '/'));
        $this->assertSame('<?php // new A', $this->read('app/A.php'));
    }

    public function test_recovery_while_another_request_holds_the_lock_does_nothing(): void
    {
        $this->runUpdateUntil('migrate', 660);
        $lock = Lock::acquire($this->dir);

        $this->assertFalse(clubportal_update_guard_recover($this->site));

        $lock->release();
        $this->assertSame(['action' => 'pause'], $this->decide('GET', '/'));
        $this->assertSame('<?php // new A', $this->read('app/A.php'));
        $this->assertFileDoesNotExist($this->dir.'/outcome.json');
    }

    public function test_an_unreadable_journal_keeps_the_pause_page(): void
    {
        $this->runUpdateUntil('migrate', 660);
        file_put_contents($this->dir.'/journal.json', 'broken');

        $this->assertFalse(clubportal_update_guard_recover($this->site));
        $this->assertSame(['action' => 'pause'], $this->decide('GET', '/'));
    }

    // ------------------------------------------------------------------ run as the real entry points

    /** A copy of public/update-guard.php in the scratch app, run by a separate PHP. */
    private function runPhp(string $code): array
    {
        copy(dirname(__DIR__, 2).'/public/update-guard.php', $this->site.'/public/update-guard.php');
        $out = [];
        exec(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($code).' 2>&1', $out, $status);

        return [implode("\n", $out), $status];
    }

    private function snapshot(): array
    {
        clearstatcache();
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $out[$f->getPathname()] = [filemtime($f->getPathname()), md5_file($f->getPathname())];
        }
        ksort($out);

        return $out;
    }

    public function test_artisan_prints_one_line_and_exits_without_touching_anything(): void
    {
        $this->runUpdateUntil('migrate', 660);
        copy(dirname(__DIR__, 2).'/artisan', $this->site.'/artisan-real');
        copy(dirname(__DIR__, 2).'/public/update-guard.php', $this->site.'/public/update-guard.php');
        $before = $this->snapshot();

        $out = [];
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($this->site.'/artisan-real').' schedule:run 2>&1', $out, $status);

        $this->assertSame(0, $status);
        $this->assertSame(rtrim(self::MESSAGE), implode("\n", $out));
        $this->assertSame($before, $this->snapshot(), 'the CLI never recovers');
        $this->assertSame('<?php // new A', $this->read('app/A.php'));
    }

    public function test_artisan_without_the_guard_file_carries_on(): void
    {
        [$out, $status] = $this->runPhp('require '.var_export($this->site.'/public/update-guard.php', true).'; clubportal_update_guard_cli('.var_export($this->site, true).'); echo "carried on";');

        $this->assertSame(0, $status);
        $this->assertSame('carried on', $out);
    }

    public function test_the_web_guard_serves_the_pause_page_and_stops(): void
    {
        $this->guardUp();

        [$out, $status] = $this->runPhp('$_SERVER["REQUEST_METHOD"] = "GET"; $_SERVER["REQUEST_URI"] = "/?a=1"; require '
            .var_export($this->site.'/public/update-guard.php', true).'; clubportal_update_guard_web('.var_export($this->site, true).'); echo "LEAKED";');

        $this->assertSame(0, $status);
        $this->assertStringContainsString('Portal sedang dikemas kini', $out);
        $this->assertStringNotContainsString('LEAKED', $out);
    }

    public function test_the_web_guard_saves_a_callback_posted_with_a_query_string(): void
    {
        $this->guardUp();

        [, $status] = $this->runPhp('$_SERVER["REQUEST_METHOD"] = "POST"; $_SERVER["REQUEST_URI"] = "/webhook/billplz?x=1"; require '
            .var_export($this->site.'/public/update-guard.php', true).'; clubportal_update_guard_web('.var_export($this->site, true).');');

        $this->assertSame(0, $status);
        $this->assertCount(1, $this->savedFiles());
    }

    public function test_without_the_updater_library_the_guard_still_pauses(): void
    {
        $this->runUpdateUntil('migrate', 660);

        // The scratch app has no public/update/lib: recovery cannot run, the request is paused, nothing breaks.
        [$out, $status] = $this->runPhp('require '.var_export($this->site.'/public/update-guard.php', true).'; var_export(clubportal_update_guard_recover('
            .var_export($this->site, true).')); echo " "; var_export(clubportal_update_guard_decide('.var_export($this->site, true).', "GET", "/", null));');

        $this->assertSame(0, $status);
        $this->assertStringStartsWith('false ', $out);
        $this->assertStringContainsString("'pause'", $out);
        $this->assertSame('<?php // new A', $this->read('app/A.php'));
    }

    // ------------------------------------------------------------------ Laravel reads our files

    public function test_laravel_reads_the_down_file_and_accepts_the_bypass_cookie(): void
    {
        $secret = Maintenance::newSecret();
        Maintenance::on($this->site, $secret, '<html>paused for members</html>');
        $this->app->useStoragePath($this->site.'/storage');

        $this->assertTrue(app()->maintenanceMode()->active());
        $data = app()->maintenanceMode()->data();
        $this->assertSame(['webhook/*'], $data['except']);
        $this->assertSame($secret, $data['secret']);
        $this->assertSame(503, $data['status']);
        $this->assertSame(120, $data['retry']);

        // Laravel never pauses /up, so a page that does not exist shows the middleware at work: 503, then 404 with the cookie.
        $this->get('/no-such-page')->assertStatus(503)->assertHeader('Retry-After', '120')->assertSee('paused for members', false);

        $cookie = Maintenance::bypassCookie($secret);
        $this->withUnencryptedCookie($cookie['name'], $cookie['value'])->get('/no-such-page')->assertNotFound();
    }
}
