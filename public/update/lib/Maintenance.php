<?php

declare(strict_types=1);

namespace ClubPortalUpdater;

/**
 * The pause for members: Laravel's own maintenance files (storage/framework/down and maintenance.php, the same as
 * `php artisan down` writes) plus the swap guard (storage/framework/updating) that public/index.php and artisan check
 * before anything from bootstrap/ or vendor/ is loaded.
 *
 * Order rule: the guard goes up first and comes down last. Laravel's down file without the guard would keep the portal
 * paused with nothing to undo an abandoned update; the guard without the down file is still recovered when idle.
 */
final class Maintenance
{
    /** Laravel's maintenance.php source; `php artisan down` copies it as it is (it has no placeholders). */
    public const STUB = 'vendor/laravel/framework/src/Illuminate/Foundation/Console/stubs/maintenance-mode.stub';

    /** Laravel's bypass cookie (Illuminate\Foundation\Http\MaintenanceModeBypassCookie) and its 12-hour life. */
    public const COOKIE = 'laravel_maintenance';

    public const COOKIE_LIFETIME = 43200;

    /** Seconds members' browsers are asked to wait (the Retry-After header). */
    public const RETRY = 120;

    /**
     * Reads the stub from the installed (old) vendor folder before anything is written, so it runs before the switch.
     *
     * @throws UpdaterError when the stub is missing or a file cannot be written
     */
    public static function on(string $app, string $secret, string $pauseHtml): void
    {
        if (preg_match('/^[A-Za-z0-9]{16,128}$/', $secret) !== 1) {
            throw new UpdaterError('The maintenance secret is not valid.');
        }
        $app = rtrim($app, '/\\');
        $stub = is_file($app.'/'.self::STUB) ? @file_get_contents($app.'/'.self::STUB) : false;
        if (! is_string($stub) || $stub === '') {
            throw new UpdaterError("Laravel's maintenance page script cannot be read from the vendor folder.");
        }
        // The same payload as `php artisan down`, in the same key order and encoding.
        $down = json_encode([
            'except' => ['webhook/*'],
            'redirect' => null,
            'retry' => self::RETRY,
            'refresh' => null,
            'secret' => $secret,
            'status' => 503,
            'template' => $pauseHtml,
        ], JSON_PRETTY_PRINT);
        if ($down === false) {
            throw new UpdaterError('The pause page cannot be stored (it is not valid UTF-8).');
        }
        $framework = $app.'/storage/framework';
        if (! is_dir($framework) && ! @mkdir($framework, 0755, true) && ! is_dir($framework)) {
            throw new UpdaterError('The storage/framework folder cannot be created.');
        }

        Files::writeAtomic(Files::forApp($app)->guardPath, time()."\n");
        Files::writeAtomic($framework.'/maintenance.php', $stub);
        Files::writeAtomic($framework.'/down', $down);
    }

    /** Removes only the swap guard; Laravel's maintenance files stay. */
    public static function liftGuard(string $app): void
    {
        self::remove(Files::forApp($app)->guardPath);
    }

    /** Removes Laravel's maintenance files, then the guard. Safe to repeat. */
    public static function off(string $app): void
    {
        $framework = rtrim($app, '/\\').'/storage/framework';
        self::remove($framework.'/down');
        self::remove($framework.'/maintenance.php');
        self::remove(Files::forApp($app)->guardPath);
    }

    /** A random secret for the down file. */
    public static function newSecret(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Laravel's bypass cookie for the updating admin's browser, the same format as MaintenanceModeBypassCookie::create.
     *
     * @return array{name: string, value: string, expires: int}
     */
    public static function bypassCookie(string $secret): array
    {
        $expires = time() + self::COOKIE_LIFETIME;
        $value = base64_encode((string) json_encode([
            'expires_at' => $expires,
            'mac' => hash_hmac('sha256', (string) $expires, $secret),
        ]));

        return ['name' => self::COOKIE, 'value' => $value, 'expires' => $expires];
    }

    private static function remove(string $path): void
    {
        if (! is_file($path)) {
            return;
        }
        if (! is_writable(dirname($path)) || ! @unlink($path)) {
            clearstatcache(true, $path);
            if (is_file($path)) {
                throw new UpdaterError('Cannot remove '.basename($path).'.');
            }
        }
    }
}
