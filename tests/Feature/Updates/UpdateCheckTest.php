<?php

namespace Tests\Feature\Updates;

use App\Support\Updates\Manifest;
use App\Support\Updates\UpdateCheck;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Tests\TestCase;

class UpdateCheckTest extends TestCase
{
    private string $releaseFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->releaseFile = tempnam(sys_get_temp_dir(), 'rel');
        config(['updates.release_file' => $this->releaseFile]);
        $this->install('2.2.0');
    }

    protected function tearDown(): void
    {
        @unlink($this->releaseFile);
        parent::tearDown();
    }

    private function install(string $version): void
    {
        file_put_contents($this->releaseFile, json_encode(['version' => $version]));
    }

    /** @return array<string, mixed> */
    private function manifest(string $version = '2.2.3'): array
    {
        return [
            'format' => 1, 'app' => 'clubportal', 'version' => $version, 'tag' => 'v'.$version,
            'released_at' => '2026-12-01T00:00:00Z',
            'zip' => [
                'name' => "clubportal-$version.zip",
                'url' => "https://github.com/amirsubhi/clubcollection/releases/download/v$version/clubportal-$version.zip",
                'size' => 12345, 'sha256' => str_repeat('ab', 32),
            ],
            'php_requirement' => '>=8.4.1', 'min_from_version' => '2.0.0', 'security' => false,
            'last_security_version' => '2.2.2', 'summary' => null, 'manual_action' => null,
            'whats_new' => ['One thing'], 'notes_markdown' => 'Notes',
            'notes_url' => "https://github.com/amirsubhi/clubcollection/releases/tag/v$version",
            'signature' => null,
        ];
    }

    private function check(bool $fresh = false)
    {
        return app(UpdateCheck::class)->status($fresh);
    }

    public function test_newer_manifest_is_available(): void
    {
        Http::fake(['*' => Http::response($this->manifest('2.2.3'))]);

        $s = $this->check();

        $this->assertTrue($s->available);
        $this->assertNull($s->error);
        $this->assertSame('2.2.0', $s->installed);
        $this->assertSame('2.2.3', $s->latest->version);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://github.com/amirsubhi/clubcollection/releases/latest/download/clubportal-manifest.json');
    }

    public function test_same_version_is_not_available(): void
    {
        Http::fake(['*' => Http::response($this->manifest('2.2.0'))]);

        $s = $this->check();

        $this->assertFalse($s->available);
        $this->assertNull($s->error);
        $this->assertSame('2.2.0', $s->latest->version);
    }

    public function test_dev_install_is_not_available_and_makes_no_request(): void
    {
        unlink($this->releaseFile);
        Http::fake();

        $s = $this->check();

        $this->assertFalse($s->available);
        $this->assertSame('dev', $s->installed);
        $this->assertSame('This copy was not installed from a release zip: update it with git.', $s->error);
        Http::assertNothingSent();
    }

    public function test_second_call_within_the_hour_makes_no_request_and_fresh_refetches(): void
    {
        Http::fake(['*' => Http::response($this->manifest())]);

        $first = $this->check();
        $second = $this->check();
        $this->assertTrue($second->available);
        $this->assertSame($first->checkedAt->toIso8601String(), $second->checkedAt->toIso8601String());
        Http::assertSentCount(1);

        $this->check(true);
        Http::assertSentCount(2);
    }

    public function test_cached_manifest_is_judged_against_the_current_install(): void
    {
        Http::fake(['*' => Http::response($this->manifest('2.2.3'))]);
        $this->assertTrue($this->check()->available);

        $this->install('2.2.3');

        $this->assertFalse($this->check()->available);
        Http::assertSentCount(1);
    }

    public function test_non_json_and_429_are_cached_as_failure(): void
    {
        $cases = [
            'html' => fn () => Http::response('<html>Too busy</html>', 200),
            '429' => fn () => Http::response('', 429),
            '404' => fn () => Http::response('', 404),
            'connection' => fn () => fn () => throw new ConnectionException('timeout'),
        ];
        foreach ($cases as $name => $response) {
            Cache::flush();
            Http::swap(new Factory);
            $attempts = 0;
            Http::fake(['*' => function () use ($response, &$attempts) {
                $attempts++;

                return value($response());
            }]);

            $s = $this->check();
            $this->assertSame('GitHub did not answer, try again later.', $s->error, $name);
            $this->assertFalse($s->available, $name);
            $this->assertNull($s->latest, $name);
            $this->assertSame('2.2.0', $s->installed, $name);

            $this->check();
            $name === 'connection' ? $this->assertSame(1, $attempts) : Http::assertSentCount(1);

            $this->travel(16)->minutes();
            Http::swap(new Factory);
            Http::fake(['*' => Http::response($this->manifest())]);
            $this->assertTrue($this->check()->available, $name.' retried after 15 minutes');
            $this->travelBack();
        }
    }

    public function test_failure_is_not_retried_before_fifteen_minutes(): void
    {
        Http::fake(['*' => Http::response('', 429)]);
        $this->check();

        $this->travel(14)->minutes();
        $this->check();
        Http::assertSentCount(1);
    }

    public function test_oversized_manifest_is_a_failure(): void
    {
        Http::fake(['*' => Http::response('{}', 200, ['Content-Length' => (string) (Manifest::MAX_BYTES + 1)])]);

        $this->assertSame('GitHub did not answer, try again later.', $this->check()->error);

        Cache::flush();
        $big = $this->manifest();
        $big['notes_markdown'] = str_repeat('x', Manifest::MAX_BYTES);
        Http::fake(['*' => Http::response($big)]);

        $this->assertSame('GitHub did not answer, try again later.', $this->check()->error);
    }

    public function test_oversized_body_without_content_length_is_a_failure(): void
    {
        $big = json_encode($this->manifest() + ['padding' => str_repeat('x', 300 * 1024)]);
        Http::fake(['*' => Http::response($big, 200, ['Content-Length' => ''])]);

        $s = $this->check();

        $this->assertSame('GitHub did not answer, try again later.', $s->error);
        $this->assertFalse($s->available);
    }

    public function test_bounded_read_gives_up_on_a_stalled_stream(): void
    {
        $stalled = new class implements StreamInterface
        {
            public int $reads = 0;

            public function __toString(): string
            {
                return '';
            }

            public function close(): void {}

            public function detach()
            {
                return null;
            }

            public function getSize(): ?int
            {
                return null;
            }

            public function tell(): int
            {
                return 0;
            }

            public function eof(): bool
            {
                return false;
            }

            public function isSeekable(): bool
            {
                return false;
            }

            public function seek(int $offset, int $whence = SEEK_SET): void {}

            public function rewind(): void {}

            public function isWritable(): bool
            {
                return false;
            }

            public function write(string $string): int
            {
                return 0;
            }

            public function isReadable(): bool
            {
                return true;
            }

            public function read(int $length): string
            {
                $this->reads++;
                if ($this->reads > 50) {
                    throw new RuntimeException('looped');
                }

                return '';
            }

            public function getContents(): string
            {
                return '';
            }

            public function getMetadata(?string $key = null)
            {
                return null;
            }
        };

        $this->assertNull(app(UpdateCheck::class)->readBounded($stalled, microtime(true) + 5));
        $this->assertSame(1, $stalled->reads);

        $this->assertNull(app(UpdateCheck::class)->readBounded(Utils::streamFor('abc'), microtime(true) - 1));
        $this->assertSame('abc', app(UpdateCheck::class)->readBounded(Utils::streamFor('abc'), microtime(true) + 5));
        $this->assertSame(Manifest::MAX_BYTES + 1, strlen(app(UpdateCheck::class)->readBounded(Utils::streamFor(str_repeat('x', Manifest::MAX_BYTES * 2)), microtime(true) + 5)));
    }

    public function test_requests_are_https_only_with_bounded_redirects_and_identity_encoding(): void
    {
        Http::fake(['*' => Http::response($this->manifest())]);

        $this->check();

        Http::assertSent(fn (Request $r) => $r->hasHeader('Accept-Encoding', 'identity'));
    }

    public function test_unparseable_cache_entry_is_dropped_and_refetched(): void
    {
        $bad = $this->manifest();
        $bad['zip']['url'] = 'https://evil.example/x.zip';
        foreach ([['checked_at' => now()->toIso8601String(), 'manifest' => $bad], 'junk', ['checked_at' => 5], ['checked_at' => 'not a date', 'manifest' => null]] as $entry) {
            Cache::flush();
            Http::swap(new Factory);
            Http::fake(['*' => Http::response($this->manifest())]);
            Cache::put('updates.status.stable', $entry, 3600);

            $s = $this->check();

            $this->assertNull($s->error);
            $this->assertTrue($s->available);
            Http::assertSentCount(1);
        }
    }

    public function test_manifest_that_fails_validation_is_a_failure(): void
    {
        $bad = $this->manifest();
        $bad['zip']['url'] = 'https://evil.example/clubportal-2.2.3.zip';
        Http::fake(['*' => Http::response($bad)]);

        $s = $this->check();

        $this->assertSame('GitHub did not answer, try again later.', $s->error);
        $this->assertFalse($s->available);
    }

    public function test_next_channel_uses_the_next_release_url(): void
    {
        config(['updates.channel' => 'next']);
        Http::fake(['*' => Http::response($this->manifest())]);

        $this->check();

        $this->assertSame('https://github.com/amirsubhi/clubcollection/releases/download/next/clubportal-manifest.json', app(UpdateCheck::class)->manifestUrl());
        Http::assertSent(fn (Request $r) => $r->url() === 'https://github.com/amirsubhi/clubcollection/releases/download/next/clubportal-manifest.json');
        $this->assertTrue(Cache::has('updates.status.next'));
    }

    public function test_cached_manifest_round_trips(): void
    {
        $m = Manifest::fromJson(json_encode($this->manifest()));

        $this->assertEquals($m, Manifest::fromJson(json_encode($m->toArray())));
    }
}
