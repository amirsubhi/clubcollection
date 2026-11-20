<?php

namespace App\Support\Doctor;

/**
 * What `app:doctor` reads from the PHP runtime, in one place so tests can
 * swap in a fake host (another PHP version, other ini limits).
 */
class HostProbe
{
    public function phpVersion(): string
    {
        return PHP_VERSION;
    }

    public function extensionLoaded(string $name): bool
    {
        return extension_loaded($name);
    }

    public function ini(string $key): string|false
    {
        return ini_get($key);
    }

    /** "12M" → bytes; "-1", "0" (no limit) or "" (not set) → -1. */
    public static function bytes(string|false $value): int
    {
        $value = trim((string) $value);
        if ($value === '' || (int) $value <= 0) {
            return -1;
        }
        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
