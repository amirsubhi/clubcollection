<?php

/*
 * The guard at the top of public/index.php: it runs before the Composer autoloader, so a server that
 * can't run the app at all (PHP too old, before install a missing PDO / OpenSSL extension or a folder
 * PHP can't write to) gets a short page that says what to change, instead of Composer's platform-check
 * error or a bare HTTP 500. Everything else is checked by the /install page and `php artisan app:doctor`;
 * the requirements come from bootstrap/requirements.php.
 *
 * Written so that an old PHP (5.6 / 7.x) can still parse it: no short arrays, typed properties, arrow
 * functions, match, named arguments, nullsafe operator or ::class on objects. Once the app is installed
 * a request costs one small include, a version compare and a file_exists.
 */

if (! function_exists('clubportal_preflight')) {
    /**
     * Null when this server can serve the app, else the page to send:
     * array('status' => 503, 'headers' => array(name => value), 'body' => html).
     *
     * @param string        $basePath        the app folder (the parent of public/)
     * @param string|null   $phpVersion      the PHP version to check (tests); null = PHP_VERSION
     * @param callable|null $extensionLoaded extension name => bool (tests); null = extension_loaded
     * @param callable|null $isWritable      absolute path => bool (tests); null = is_writable
     */
    function clubportal_preflight($basePath, $phpVersion = null, $extensionLoaded = null, $isWritable = null)
    {
        $req = require $basePath.'/bootstrap/requirements.php';

        if ($phpVersion === null) {
            $phpVersion = PHP_VERSION;
        }
        $parts = explode('.', $req['php']);
        $series = $parts[0].'.'.$parts[1];
        if (version_compare($phpVersion, $req['php'], '<')) {
            return clubportal_preflight_page(
                'This portal needs PHP '.$req['php'].' or newer.',
                'In cPanel, open MultiPHP Manager (or Select PHP Version) and choose PHP '.$series.' for this site, then reload this page.'
            );
        }

        // Installed: the extensions and folders were checked by /install; app:doctor checks them later.
        if (file_exists($basePath.'/storage/app/.installed')) {
            return null;
        }

        if ($extensionLoaded === null) {
            $extensionLoaded = 'extension_loaded';
        }
        if ($isWritable === null) {
            $isWritable = 'is_writable';
        }

        // The PDO extension of the database in .env (DB_CONNECTION; Laravel's default is sqlite).
        $needed = $req['boot_extensions'];
        $connection = 'sqlite';
        $env = @file_get_contents($basePath.'/.env');
        if (is_string($env) && preg_match('/^[ \t]*DB_CONNECTION[ \t]*=[ \t]*["\']?([A-Za-z0-9_]+)/m', $env, $m)) {
            $connection = strtolower($m[1]);
        }
        if (isset($req['pdo_drivers'][$connection])) {
            $needed[] = $req['pdo_drivers'][$connection];
        }
        $missing = array();
        foreach ($needed as $ext) {
            if (! call_user_func($extensionLoaded, $ext)) {
                $missing[] = $ext;
            }
        }
        if ($missing) {
            return clubportal_preflight_page(
                'This portal needs the PHP extension'.(count($missing) > 1 ? 's ' : ' ').implode(', ', $missing).'.',
                'In cPanel, open Select PHP Version, then Extensions, tick '.(count($missing) > 1 ? 'them' : 'it').' for PHP '.$series.', then reload this page.'
            );
        }

        $notWritable = array();
        foreach ($req['boot_writable'] as $dir) {
            if (! call_user_func($isWritable, $basePath.'/'.$dir)) {
                $notWritable[] = $dir;
            }
        }
        if ($notWritable) {
            return clubportal_preflight_page(
                'PHP cannot write to the folder'.(count($notWritable) > 1 ? 's ' : ' ').implode(' and ', $notWritable).'.',
                'In the Terminal, run chmod -R 775 storage bootstrap/cache in the app folder (or set the permissions in cPanel File Manager), then reload this page.'
            );
        }

        return null;
    }

    /** The plain, self-contained page (no app, no assets) for one problem and its fix. */
    function clubportal_preflight_page($problem, $fix)
    {
        $problem = htmlspecialchars($problem, ENT_QUOTES, 'UTF-8');
        $fix = htmlspecialchars($fix, ENT_QUOTES, 'UTF-8');
        $body = '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Server setup needed</title>
<style>
body{margin:0;padding:2rem 1rem;font:16px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;background:#f4f5f7;color:#1f2330}
main{max-width:36rem;margin:10vh auto 0;background:#fff;border-radius:12px;padding:1.75rem 1.5rem;box-shadow:0 2px 12px rgba(0,0,0,.08)}
h1{font-size:1.25rem;margin:0 0 .75rem}
p{margin:.5rem 0}
@media (prefers-color-scheme:dark){body{background:#15171e;color:#e8e9ee}main{background:#1f2230;box-shadow:none}}
</style>
</head>
<body>
<main>
<h1>Server setup needed</h1>
<p>'.$problem.'</p>
<p>'.$fix.'</p>
</main>
</body>
</html>
';

        return array(
            'status' => 503,
            'headers' => array(
                'Content-Type' => 'text/html; charset=UTF-8',
                'Cache-Control' => 'no-store',
                'X-Content-Type-Options' => 'nosniff',
                'X-Robots-Tag' => 'noindex',
                'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; frame-ancestors 'none'",
            ),
            'body' => $body,
        );
    }

    /** Sends a page from clubportal_preflight(). */
    function clubportal_preflight_send($page)
    {
        if (! headers_sent()) {
            header('HTTP/1.1 503 Service Unavailable', true, $page['status']);
            foreach ($page['headers'] as $name => $value) {
                header($name.': '.$value, true);
            }
        }
        echo $page['body'];
    }
}
