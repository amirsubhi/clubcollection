<?php

declare(strict_types=1);

namespace ClubPortalUpdater;

/** Fetches the release zip in slices that each fit one web request, resuming with a Range header. */
final class Download
{
    private const MAX_REDIRECTS = 3;

    /** curl errors that mean "try the next slice": timeout, partial file, receive failure. */
    private const SOFT_ERRORS = [28, 18, 56];

    /**
     * Downloads for at most $maxSeconds, appending to $dest from its current size.
     *
     * @param  bool  $allowLocalHttp  tests only: also accept http://127.0.0.1 (never set by the updater itself)
     * @return array{done: bool, bytes: int}
     */
    public static function slice(string $url, string $dest, int $maxSeconds = 20, bool $allowLocalHttp = false): array
    {
        $https = str_starts_with($url, 'https://');
        if (! $https && ! ($allowLocalHttp && str_starts_with($url, 'http://127.0.0.1'))) {
            throw new UpdaterError('The download address must start with https://.');
        }

        if (! is_dir(dirname($dest)) || ! is_writable(dirname($dest))) {
            throw new UpdaterError('The downloaded file cannot be written (folder missing or not writable).');
        }
        clearstatcache(true, $dest);
        $have = is_file($dest) ? (int) filesize($dest) : 0;

        $status = 0;
        $total = null;
        $rangeStart = null;
        $handle = null;
        $writeError = null;

        $ch = curl_init($url);
        $options = [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => self::MAX_REDIRECTS,
            CURLOPT_CONNECTTIMEOUT => min(10, max(1, $maxSeconds)),
            CURLOPT_TIMEOUT => max(1, $maxSeconds),
            CURLOPT_USERAGENT => 'ClubPortalUpdater',
            CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$status, &$total, &$rangeStart): int {
                if (preg_match('~^HTTP/\S+\s+(\d{3})~', $line, $m)) {
                    $status = (int) $m[1];
                    $total = null;
                    $rangeStart = null;
                } elseif (preg_match('~^content-range:\s*bytes\s+(?:(\d+)-\d+|\*)/(\d+|\*)~i', $line, $m)) {
                    $rangeStart = isset($m[1]) && $m[1] !== '' ? (int) $m[1] : null;
                    $total = $m[2] === '*' ? null : (int) $m[2];
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($ch, string $data) use (&$status, &$rangeStart, &$handle, &$writeError, $dest, $have): int {
                if ($status !== 200 && $status !== 206) {
                    return strlen($data); // a redirect or error body: not part of the file
                }
                if ($handle === null) {
                    if ($status === 206 && $rangeStart !== $have) {
                        $writeError = 'The download server answered with the wrong part of the file.';

                        return 0;
                    }
                    // 200 means the server ignored Range: start the file again.
                    $handle = @fopen($dest, $status === 206 ? 'ab' : 'wb');
                    if ($handle === false) {
                        $handle = null;
                        $writeError = 'The downloaded file cannot be written (disk full or folder not writable).';

                        return 0;
                    }
                }
                $written = fwrite($handle, $data);
                if ($written !== strlen($data)) {
                    $writeError = 'The downloaded file cannot be written (disk full?).';

                    return 0;
                }

                return $written;
            },
        ];
        if ($have > 0) {
            $options[CURLOPT_RANGE] = $have.'-';
        }
        foreach ($options as $option => $value) {
            curl_setopt($ch, $option, $value);
        }
        self::restrictProtocols($ch, $allowLocalHttp);

        curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        if ($status === 0) {
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        }
        if (is_resource($handle)) {
            fclose($handle);
        }
        clearstatcache(true, $dest);

        if ($writeError !== null) {
            throw new UpdaterError($writeError);
        }
        $size = is_file($dest) ? (int) filesize($dest) : 0;

        if ($errno !== 0) {
            if (in_array($errno, self::SOFT_ERRORS, true)) {
                return ['done' => false, 'bytes' => $size];
            }
            throw new UpdaterError('The download failed: '.($error !== '' ? $error : "curl error $errno").'.');
        }
        if ($status === 416) {
            if ($total !== null && $total === $have) {
                return ['done' => true, 'bytes' => $have];
            }
            @unlink($dest); // the partial file does not fit the server's file: start again next slice

            return ['done' => false, 'bytes' => 0];
        }
        if ($status === 200) {
            if (! is_file($dest)) {
                file_put_contents($dest, ''); // an empty body
            }

            return ['done' => true, 'bytes' => (int) filesize($dest)];
        }
        if ($status === 206) {
            return ['done' => $total === null || $size === $total, 'bytes' => $size];
        }

        throw new UpdaterError("The download server answered with HTTP $status.");
    }

    /** True when the file has exactly this size and SHA-256. */
    public static function verify(string $file, int $size, string $sha256): bool
    {
        clearstatcache(true, $file);
        if (! is_file($file) || filesize($file) !== $size) {
            return false;
        }
        $hash = hash_file('sha256', $file);

        return $hash !== false && hash_equals(strtolower($sha256), $hash);
    }

    private static function restrictProtocols(\CurlHandle $ch, bool $allowLocalHttp): void
    {
        $allowed = $allowLocalHttp ? 'http,https' : 'https';
        if (defined('CURLOPT_PROTOCOLS_STR') && @curl_setopt($ch, CURLOPT_PROTOCOLS_STR, $allowed)) {
            @curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS_STR, $allowed);

            return;
        }
        $bits = $allowLocalHttp ? CURLPROTO_HTTP | CURLPROTO_HTTPS : CURLPROTO_HTTPS;
        @curl_setopt($ch, CURLOPT_PROTOCOLS, $bits);
        @curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, $bits);
    }
}
