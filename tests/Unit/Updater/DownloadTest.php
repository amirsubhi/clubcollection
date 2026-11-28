<?php

namespace Tests\Unit\Updater;

use ClubPortalUpdater\Download;
use ClubPortalUpdater\UpdaterError;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../../public/update/lib/load.php';

class DownloadTest extends TestCase
{
    use Support;

    private const ROUTER = <<<'PHP'
<?php
$file = __DIR__.'/fixture.bin';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$body = (string) file_get_contents($file);
$size = strlen($body);
switch ($path) {
    case '/hop1': header('Location: /hop2', true, 302); return;
    case '/hop2': header('Location: /fixture.bin', true, 302); return;
    case '/loop': header('Location: /loop', true, 302); return;
    case '/missing': http_response_code(404); echo 'no'; return;
    case '/norange': header('Content-Length: '.$size); echo $body; return;
}
$range = $_SERVER['HTTP_RANGE'] ?? '';
if (preg_match('/^bytes=(\d+)-$/', $range, $m)) {
    $start = (int) $m[1];
    if ($start >= $size) {
        http_response_code(416);
        header("Content-Range: bytes */$size");
        return;
    }
    http_response_code(206);
    header("Content-Range: bytes $start-".($size - 1)."/$size");
    header('Content-Length: '.($size - $start));
    echo substr($body, $start);
    return;
}
header('Content-Length: '.$size);
echo $body;
PHP;

    /** @var resource|null */
    private $server = null;

    private string $base = '';

    private string $payload = '';

    protected function setUp(): void
    {
        if (! function_exists('proc_open') || ! function_exists('curl_init')) {
            $this->markTestSkipped('proc_open and ext-curl are needed to start the local download server.');
        }
        $dir = $this->tempDir();
        $this->payload = random_bytes(300_000);
        file_put_contents($dir.'/fixture.bin', $this->payload);
        file_put_contents($dir.'/router.php', self::ROUTER);

        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);

        $this->server = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $dir, $dir.'/router.php'],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );
        $this->base = "http://127.0.0.1:$port";
        for ($i = 0; $i < 50; $i++) {
            set_error_handler(fn () => true); // a refused connection while the server starts is expected
            try {
                $c = fsockopen('127.0.0.1', $port, $e, $s, 0.2);
            } finally {
                restore_error_handler();
            }
            if ($c) {
                fclose($c);

                return;
            }
            usleep(100_000);
        }
        $this->markTestSkipped('The PHP built-in web server could not be started.');
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        $this->cleanTempDirs();
    }

    private function fetch(string $path, string $dest, int $secs = 20): array
    {
        return Download::slice($this->base.$path, $dest, $secs, true);
    }

    public function test_a_whole_file_in_one_slice(): void
    {
        $dest = $this->tempDir().'/z.zip';
        $r = $this->fetch('/fixture.bin', $dest);

        $this->assertSame(['done' => true, 'bytes' => 300_000], $r);
        $this->assertSame($this->payload, file_get_contents($dest));
        $this->assertTrue(Download::verify($dest, 300_000, hash('sha256', $this->payload)));
    }

    public function test_it_resumes_from_the_current_size_with_a_range_request(): void
    {
        $dest = $this->tempDir().'/z.zip';
        file_put_contents($dest, substr($this->payload, 0, 120_000));

        $r = $this->fetch('/fixture.bin', $dest);

        $this->assertSame(['done' => true, 'bytes' => 300_000], $r);
        $this->assertSame($this->payload, file_get_contents($dest));
    }

    public function test_a_complete_file_is_reported_done_without_changing_it(): void
    {
        $dest = $this->tempDir().'/z.zip';
        file_put_contents($dest, $this->payload);

        $this->assertSame(['done' => true, 'bytes' => 300_000], $this->fetch('/fixture.bin', $dest));
        $this->assertSame($this->payload, file_get_contents($dest));
    }

    public function test_a_server_that_ignores_range_restarts_the_file(): void
    {
        $dest = $this->tempDir().'/z.zip';
        file_put_contents($dest, 'stale partial bytes');

        $this->assertSame(['done' => true, 'bytes' => 300_000], $this->fetch('/norange', $dest));
        $this->assertSame($this->payload, file_get_contents($dest));
    }

    public function test_up_to_three_redirects_are_followed(): void
    {
        $dest = $this->tempDir().'/z.zip';
        $this->assertTrue($this->fetch('/hop1', $dest)['done']);
        $this->assertSame($this->payload, file_get_contents($dest));
    }

    public function test_a_redirect_loop_is_an_error(): void
    {
        $this->expectException(UpdaterError::class);
        $this->fetch('/loop', $this->tempDir().'/z.zip');
    }

    public function test_an_http_error_is_an_error_and_writes_nothing(): void
    {
        $dest = $this->tempDir().'/z.zip';
        try {
            $this->fetch('/missing', $dest);
            $this->fail('A 404 must throw');
        } catch (UpdaterError $e) {
            $this->assertStringContainsString('404', $e->getMessage());
        }
        $this->assertFileDoesNotExist($dest);
    }

    public function test_plain_http_is_refused_by_default(): void
    {
        $this->expectException(UpdaterError::class);
        Download::slice($this->base.'/fixture.bin', $this->tempDir().'/z.zip');
    }

    public function test_only_https_addresses_pass_without_the_test_seam(): void
    {
        foreach (['http://example.com/a.zip', 'ftp://example.com/a.zip', 'file:///etc/passwd'] as $url) {
            try {
                Download::slice($url, $this->tempDir().'/z.zip', 5, true);
                $this->fail("$url must be refused");
            } catch (UpdaterError) {
            }
        }
        $this->addToAssertionCount(1);
    }

    public function test_a_write_failure_is_an_error(): void
    {
        $dest = $this->tempDir().'/missing-folder/z.zip';
        $this->expectException(UpdaterError::class);
        $this->fetch('/fixture.bin', $dest);
    }

    public function test_verify_flags_a_wrong_size_or_hash(): void
    {
        $dest = $this->tempDir().'/z.zip';
        file_put_contents($dest, 'hello');
        $hash = hash('sha256', 'hello');

        $this->assertTrue(Download::verify($dest, 5, $hash));
        $this->assertTrue(Download::verify($dest, 5, strtoupper($hash)));
        $this->assertFalse(Download::verify($dest, 6, $hash));
        $this->assertFalse(Download::verify($dest, 5, hash('sha256', 'other')));
        $this->assertFalse(Download::verify($dest.'.nope', 5, $hash));
    }
}
