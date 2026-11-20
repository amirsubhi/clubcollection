<?php

namespace App\Support;

/**
 * G3: reads release.json, which scripts/build-release.sh writes into the
 * release zip ({version, commit, built_at, php_requirement}). A git checkout
 * has no release.json, so the version is "dev" there.
 */
final class ReleaseInfo
{
    /** @return array<string, string> the string fields of release.json, or [] when it is missing or unreadable */
    public static function read(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        $data = json_decode((string) @file_get_contents($path), true);

        return is_array($data) ? array_filter($data, fn ($value) => is_string($value) && $value !== '') : [];
    }

    public static function version(string $path): string
    {
        return self::read($path)['version'] ?? 'dev';
    }
}
