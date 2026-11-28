<?php

declare(strict_types=1);

namespace App\Support\Updates;

use App\Support\ReleaseInfo;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
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
            if (! is_array($entry) || ! array_key_exists('checked_at', $entry)) {
                $entry = $this->fetch();
                Cache::put($key, $entry, (int) config($entry['manifest'] === null ? 'updates.failure_cache_seconds' : 'updates.cache_seconds'));
            }

            $latest = $entry['manifest'] === null ? null : Manifest::fromJson((string) json_encode($entry['manifest']));

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

    /** @return array{checked_at: string, manifest: array<string, mixed>|null} */
    private function fetch(): array
    {
        $now = CarbonImmutable::now()->toIso8601String();
        try {
            $response = Http::timeout((int) config('updates.timeout'))
                ->withOptions(['allow_redirects' => true])
                ->accept('application/json')
                ->get($this->manifestUrl());
            $length = (int) $response->header('Content-Length');
            if (! $response->successful() || $length > Manifest::MAX_BYTES) {
                return ['checked_at' => $now, 'manifest' => null];
            }

            return ['checked_at' => $now, 'manifest' => Manifest::fromJson($response->body())->toArray()];
        } catch (Throwable) {
            return ['checked_at' => $now, 'manifest' => null];
        }
    }
}
