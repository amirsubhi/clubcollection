<?php

declare(strict_types=1);

namespace App\Support\Updates;

use App\Support\ReleaseInfo;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\StreamInterface;
use Throwable;

/** Asks GitHub's release download address (not the API) for the manifest, cached so pages never wait on it twice. */
final class UpdateCheck
{
    public const ERROR_DEV = 'This copy was not installed from a release zip: update it with git.';

    public const ERROR_UNREACHABLE = 'GitHub did not answer, try again later.';

    private const BASE = 'https://github.com/';

    /** Never throws: any failure becomes an UpdateStatus with a plain error sentence. */
    public function status(bool $fresh = false): UpdateStatus
    {
        $installed = 'dev';
        try {
            $installed = ReleaseInfo::version((string) config('updates.release_file'));
            if (! Version::isRelease($installed)) {
                return new UpdateStatus($installed, null, false, self::ERROR_DEV, CarbonImmutable::now());
            }

            $key = $this->cacheKey();
            $entry = $fresh ? null : Cache::get($key);
            $latest = null;
            if ($entry !== null) {
                try {
                    $latest = $this->parseEntry($entry);
                } catch (Throwable) {
                    // A cached entry that no longer parses (e.g. after a deploy) is dropped and fetched again.
                    Cache::forget($key);
                    $entry = null;
                }
            }
            if ($entry === null) {
                $entry = $this->fetch();
                Cache::put($key, $entry, (int) config($entry['manifest'] === null ? 'updates.failure_cache_seconds' : 'updates.cache_seconds'));
                $latest = $this->parseEntry($entry);
            }

            return new UpdateStatus(
                $installed,
                $latest,
                $latest !== null && Version::compare($latest->version, $installed) > 0,
                $latest === null ? self::ERROR_UNREACHABLE : null,
                CarbonImmutable::parse($entry['checked_at']),
            );
        } catch (Throwable) {
            return new UpdateStatus($installed, null, false, self::ERROR_UNREACHABLE, CarbonImmutable::now());
        }
    }

    public function manifestUrl(): string
    {
        $repo = (string) config('updates.repo');

        return config('updates.channel') === 'next'
            ? self::BASE.$repo.'/releases/download/next/clubportal-manifest.json'
            : self::BASE.$repo.'/releases/latest/download/clubportal-manifest.json';
    }

    private function cacheKey(): string
    {
        return 'updates.status.'.config('updates.channel');
    }

    /** @param  mixed  $entry  a cache entry; throws when it is not a well-formed one */
    private function parseEntry(mixed $entry): ?Manifest
    {
        if (! is_array($entry) || ! is_string($entry['checked_at'] ?? null) || ! array_key_exists('manifest', $entry)) {
            throw new \UnexpectedValueException('Malformed cache entry.');
        }
        CarbonImmutable::parse($entry['checked_at']);
        if ($entry['manifest'] === null) {
            return null;
        }
        if (! is_array($entry['manifest'])) {
            throw new \UnexpectedValueException('Malformed cache entry.');
        }

        return Manifest::fromJson((string) json_encode($entry['manifest']));
    }

    /** @return array{checked_at: string, manifest: array<string, mixed>|null} */
    private function fetch(): array
    {
        $now = CarbonImmutable::now()->toIso8601String();
        $deadline = hrtime(true) / 1e9 + (int) config('updates.timeout');
        $stream = null;
        try {
            $response = Http::timeout((int) config('updates.timeout'))
                ->connectTimeout(2)
                ->withOptions([
                    'allow_redirects' => ['max' => 3, 'protocols' => ['https']],
                    'stream' => true,
                    'decode_content' => false,
                ])
                ->withHeaders(['Accept-Encoding' => 'identity'])
                ->accept('application/json')
                ->get($this->manifestUrl());
            if (! $response->successful() || (int) $response->header('Content-Length') > Manifest::MAX_BYTES) {
                return ['checked_at' => $now, 'manifest' => null];
            }

            $stream = $response->toPsrResponse()->getBody();
            $body = $this->readBounded($stream, $deadline);
            if ($body === null) {
                return ['checked_at' => $now, 'manifest' => null];
            }

            return ['checked_at' => $now, 'manifest' => Manifest::fromJson($body)->toArray()];
        } catch (Throwable) {
            return ['checked_at' => $now, 'manifest' => null];
        } finally {
            try {
                $stream?->close();
            } catch (Throwable) {
            }
        }
    }

    /**
     * Reads at most MAX_BYTES + 1 bytes (the extra byte makes an oversized body fail validation), and gives up
     * with null on a stalled or trickling server: an empty read before the end (a read timeout) or passing the deadline (seconds on the hrtime clock).
     *
     * @internal public for tests
     */
    public function readBounded(StreamInterface $stream, float $deadline): ?string
    {
        $body = '';
        while (! $stream->eof() && strlen($body) <= Manifest::MAX_BYTES) {
            $chunk = $stream->read(Manifest::MAX_BYTES + 1 - strlen($body));
            if ($chunk === '') {
                // The last bytes can arrive before eof() turns true; an empty read at the end is fine, a stall is not.
                return $stream->eof() ? $body : null;
            }
            if (hrtime(true) / 1e9 > $deadline) {
                return null;
            }
            $body .= $chunk;
        }

        return $body;
    }
}
