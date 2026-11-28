<?php

declare(strict_types=1);

namespace ClubPortalUpdater;

/** The text a super admin copies, mails or stores for one update attempt. Never carries secrets or the server's paths. */
final class Report
{
    private const LOG_LINES = 20;

    /** A line that names a secret (also JSON-quoted), carries credentials, a cookie, a bearer token, URL userinfo or a base64: key. */
    private const SECRET_LINE = '~(APP_KEY|DB_PASSWORD|PASSWORD|SECRET|TOKEN)["\']?\s*[=:]|authorization|cookie|bearer|://[^/\s@]+:[^@\s]+@|base64:~i';

    /** Lines about a checksum keep their 64-hex values: support needs them, and they are public release hashes. */
    private const CHECKSUM_LINE = '/sha-?256|checksum|hash|mismatch/i';

    /**
     * @param  array{outcome?: string, from?: string, to?: string, step?: ?string, reason?: ?string, at?: int|string|null, started_at?: int|string|null, php_version?: string, prechecks_failed?: list<string>, log?: list<string>}  $history
     */
    public static function text(array $history, string $appPath): string
    {
        $lines = [
            'Club Portal update report',
            'Outcome: '.self::scalar($history['outcome'] ?? 'unknown'),
            'From version: '.self::scalar($history['from'] ?? '?'),
            'To version: '.self::scalar($history['to'] ?? '?'),
            'PHP version: '.self::scalar($history['php_version'] ?? PHP_VERSION),
            'Started: '.self::time($history['started_at'] ?? null),
            'Ended: '.self::time($history['at'] ?? null),
            'Step: '.self::scalar($history['step'] ?? '-'),
            'Reason: '.self::scalar($history['reason'] ?? '-'),
        ];
        $failed = $history['prechecks_failed'] ?? [];
        if ($failed !== []) {
            $lines[] = 'Checks that failed:';
            foreach ($failed as $check) {
                $lines[] = '- '.self::scalar($check);
            }
        }
        $log = array_slice(array_values($history['log'] ?? []), -self::LOG_LINES);
        if ($log !== []) {
            $lines[] = 'Last log lines:';
            foreach ($log as $line) {
                $lines[] = '  '.mb_substr(self::scalar($line), 0, 500);
            }
        }

        return self::clean(implode("\n", $lines)."\n", $appPath);
    }

    private static function clean(string $text, string $appPath): string
    {
        $paths = array_filter(array_unique([rtrim($appPath, '/\\'), (string) realpath($appPath)]), static fn (string $p): bool => strlen($p) > 1);
        usort($paths, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        foreach ($paths as $path) {
            $text = str_replace($path, '<app>', $text);
        }
        $kept = array_filter(explode("\n", $text), static fn (string $line): bool => preg_match(self::SECRET_LINE, $line) !== 1);

        $clean = array_map(
            static fn (string $line): string => preg_match(self::CHECKSUM_LINE, $line) === 1
                ? $line
                : (preg_replace('/[0-9a-fA-F]{64}/', '[removed]', $line) ?? ''),
            $kept,
        );

        return implode("\n", $clean);
    }

    private static function time(int|string|null $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        return is_int($value) ? gmdate('Y-m-d H:i:s', $value).' UTC' : $value;
    }

    private static function scalar(mixed $value): string
    {
        return is_scalar($value) ? preg_replace('/\s+/', ' ', (string) $value) ?? '' : '-';
    }
}
