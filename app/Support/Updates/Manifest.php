<?php

declare(strict_types=1);

namespace App\Support\Updates;

use JsonException;

/** The release manifest (clubportal-manifest.json, format 1), checked before anything else looks at it. */
final class Manifest
{
    public const MAX_BYTES = 262144;

    public const ZIP_URL_PREFIX = 'https://github.com/amirsubhi/clubcollection/releases/download/';

    public const NOTES_URL_PREFIX = 'https://github.com/amirsubhi/clubcollection/';

    /** @param  list<string>  $whatsNew */
    private function __construct(
        public readonly string $version,
        public readonly string $tag,
        public readonly string $releasedAt,
        public readonly string $zipName,
        public readonly string $zipUrl,
        public readonly int $zipSize,
        public readonly string $zipSha256,
        public readonly string $phpRequirement,
        public readonly string $minFromVersion,
        public readonly bool $security,
        public readonly ?string $lastSecurityVersion,
        public readonly ?string $summary,
        public readonly ?string $manualAction,
        public readonly string $notesMarkdown,
        public readonly string $notesUrl,
        public readonly array $whatsNew,
    ) {}

    /** @throws InvalidManifest */
    public static function fromJson(string $json): self
    {
        if (strlen($json) > self::MAX_BYTES) {
            throw new InvalidManifest('The manifest is too large.');
        }
        try {
            $d = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidManifest('The manifest is not valid JSON.');
        }
        if (! is_array($d) || array_is_list($d)) {
            throw new InvalidManifest('The manifest is not a JSON object.');
        }
        if (($d['app'] ?? null) !== 'clubportal') {
            throw new InvalidManifest('The manifest is not for Club Portal.');
        }
        if (($d['format'] ?? null) !== 1) {
            throw new InvalidManifest('The manifest format is not supported.');
        }
        if (($d['signature'] ?? null) !== null) {
            throw new InvalidManifest('The manifest carries a signature this version cannot check.');
        }

        $zip = $d['zip'] ?? null;
        if (! is_array($zip)) {
            throw new InvalidManifest('The manifest has no zip entry.');
        }
        $zipUrl = self::string($zip, 'url', 'zip.url');
        if (! self::isReleaseUrl($zipUrl, self::ZIP_URL_PREFIX, 2)) {
            throw new InvalidManifest('The download address is not a Club Portal release.');
        }
        $zipName = self::string($zip, 'name', 'zip.name');
        if (preg_match('/\A[A-Za-z0-9._-]+\z/', $zipName) !== 1 || $zipName === '.' || $zipName === '..'
            || $zipName !== basename($zipUrl)) {
            throw new InvalidManifest('The download file name does not match its address.');
        }
        $notesUrl = self::string($d, 'notes_url', 'notes_url');
        if (! self::isReleaseUrl($notesUrl, self::NOTES_URL_PREFIX, null)) {
            throw new InvalidManifest('The release notes address is not a Club Portal page.');
        }
        if (array_key_exists('security', $d) && ! is_bool($d['security'])) {
            throw new InvalidManifest('The manifest field security must be true or false.');
        }
        $sha = self::string($zip, 'sha256', 'zip.sha256');
        if (preg_match('/\A[0-9a-f]{64}\z/i', $sha) !== 1) {
            throw new InvalidManifest('The download checksum is not a SHA-256.');
        }
        $size = $zip['size'] ?? null;
        if (! is_int($size) || $size < 1) {
            throw new InvalidManifest('The download size is missing.');
        }

        $whatsNew = $d['whats_new'] ?? null;
        if (! is_array($whatsNew) || ! array_is_list($whatsNew) || $whatsNew === [] || count($whatsNew) > 5) {
            throw new InvalidManifest('whats_new must list 1 to 5 items.');
        }
        foreach ($whatsNew as $item) {
            if (! is_string($item) || $item === '' || mb_strlen($item) > 200) {
                throw new InvalidManifest('whats_new items must be text of at most 200 characters.');
            }
        }

        $summary = self::optionalString($d, 'summary');

        return new self(
            version: self::string($d, 'version', 'version'),
            tag: self::string($d, 'tag', 'tag'),
            releasedAt: self::string($d, 'released_at', 'released_at'),
            zipName: $zipName,
            zipUrl: $zipUrl,
            zipSize: $size,
            zipSha256: strtolower($sha),
            phpRequirement: self::string($d, 'php_requirement', 'php_requirement'),
            minFromVersion: self::string($d, 'min_from_version', 'min_from_version'),
            security: ($d['security'] ?? false) === true,
            lastSecurityVersion: self::optionalString($d, 'last_security_version'),
            summary: $summary === '' ? null : $summary,
            manualAction: self::optionalString($d, 'manual_action'),
            notesMarkdown: self::string($d, 'notes_markdown', 'notes_markdown'),
            notesUrl: $notesUrl,
            whatsNew: $whatsNew,
        );
    }

    /** True when the installed version is older than the last version that carried a security fix. */
    public function isSecurityFor(string $installed): bool
    {
        return $this->lastSecurityVersion !== null
            && Version::compare($installed, $this->lastSecurityVersion) < 0;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'format' => 1,
            'app' => 'clubportal',
            'version' => $this->version,
            'tag' => $this->tag,
            'released_at' => $this->releasedAt,
            'zip' => [
                'name' => $this->zipName,
                'url' => $this->zipUrl,
                'size' => $this->zipSize,
                'sha256' => $this->zipSha256,
            ],
            'php_requirement' => $this->phpRequirement,
            'min_from_version' => $this->minFromVersion,
            'security' => $this->security,
            'last_security_version' => $this->lastSecurityVersion,
            'summary' => $this->summary,
            'manual_action' => $this->manualAction,
            'whats_new' => $this->whatsNew,
            'notes_markdown' => $this->notesMarkdown,
            'notes_url' => $this->notesUrl,
            'signature' => null,
        ];
    }

    /**
     * The URL starts with $prefix and the rest is plain path segments (letters, digits, dot, dash, underscore),
     * none of them `.` or `..`: no encoding, query, fragment, backslash or userinfo that could leave the repository.
     * $segments is the exact number of segments required, or null for one or more.
     */
    private static function isReleaseUrl(string $url, string $prefix, ?int $segments): bool
    {
        if (! str_starts_with($url, $prefix)) {
            return false;
        }
        $rest = substr($url, strlen($prefix));
        if (preg_match('/\A[A-Za-z0-9._-]+(?:\/[A-Za-z0-9._-]+)*\z/', $rest) !== 1) {
            return false;
        }
        $parts = explode('/', $rest);
        if ($segments !== null && count($parts) !== $segments) {
            return false;
        }

        return ! array_intersect($parts, ['.', '..']);
    }

    /** @param array<string, mixed> $a */
    private static function string(array $a, string $key, string $label): string
    {
        $v = $a[$key] ?? null;
        if (! is_string($v) || $v === '') {
            throw new InvalidManifest("The manifest is missing $label.");
        }

        return $v;
    }

    /** @param array<string, mixed> $a */
    private static function optionalString(array $a, string $key): ?string
    {
        $v = $a[$key] ?? null;
        if ($v === null) {
            return null;
        }
        if (! is_string($v)) {
            throw new InvalidManifest("The manifest field $key must be text.");
        }

        return $v;
    }
}
