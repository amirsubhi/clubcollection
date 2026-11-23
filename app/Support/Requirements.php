<?php

namespace App\Support;

/**
 * The server requirements from bootstrap/requirements.php: the one list behind `app:doctor`, the /install
 * page and the guard in public/index.php (bootstrap/preflight.php).
 */
class Requirements
{
    /** @var array{php: string, extensions: list<string>, pdo_drivers: array<string, string>, boot_extensions: list<string>, writable: list<string>, boot_writable: list<string>}|null */
    private static ?array $list = null;

    public static function all(): array
    {
        return self::$list ??= require base_path('bootstrap/requirements.php');
    }

    /** Lowest PHP version, e.g. "8.4.1". */
    public static function php(): string
    {
        return self::all()['php'];
    }

    /** "8.4": the PHP series to choose in cPanel. */
    public static function phpSeries(): string
    {
        return implode('.', array_slice(explode('.', self::php()), 0, 2));
    }

    /** @return list<string> */
    public static function extensions(): array
    {
        return self::all()['extensions'];
    }

    /** The PDO extension of a database driver (unknown drivers: pdo_sqlite, as before). */
    public static function pdoExtension(string $driver): string
    {
        return self::all()['pdo_drivers'][$driver] ?? 'pdo_sqlite';
    }

    /** @return list<string> folders (relative to the app folder) PHP must be able to write to */
    public static function writable(): array
    {
        return self::all()['writable'];
    }
}
