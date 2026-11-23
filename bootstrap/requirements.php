<?php

/*
 * What this server needs to run the portal: the one list read by `php artisan app:doctor`, the /install
 * page (App\Support\Requirements) and the guard at the top of public/index.php (bootstrap/preflight.php).
 *
 * Keep this file plain: it is read before the Composer autoloader, possibly by a PHP that is too old for
 * the app, so it must parse on old PHP (no short array syntax, no newer syntax of any kind).
 */

return array(
    // Lowest PHP version: vendor/composer/platform_check.php (from composer.lock) refuses anything older.
    'php' => '8.4.1',

    // PHP extensions the app needs; the database driver's PDO extension ('pdo_drivers') comes on top.
    'extensions' => array('ctype', 'curl', 'dom', 'fileinfo', 'filter', 'gd', 'mbstring', 'openssl', 'pdo', 'tokenizer', 'xml', 'zip'),

    // The PDO extension for each database driver (config/database.php's DB_CONNECTION drivers).
    'pdo_drivers' => array(
        'sqlite' => 'pdo_sqlite',
        'mysql' => 'pdo_mysql',
        'mariadb' => 'pdo_mysql',
        'pgsql' => 'pdo_pgsql',
        'sqlsrv' => 'pdo_sqlsrv',
    ),

    // Of 'extensions', those without which Laravel can't even show /install (checked by the guard
    // before the app is installed, with the driver's PDO extension). The others have polyfills or
    // are only needed later, and /install lists them.
    'boot_extensions' => array('openssl', 'pdo'),

    // Folders PHP must be able to write to (relative to the app folder). Without the first ones
    // (compiled views, the package manifest) no page renders, so the guard checks them too.
    'writable' => array('storage/framework/views', 'bootstrap/cache', 'storage/framework/cache', 'storage/framework/sessions', 'storage/app', 'storage/logs'),
    'boot_writable' => array('storage/framework/views', 'bootstrap/cache'),
);
