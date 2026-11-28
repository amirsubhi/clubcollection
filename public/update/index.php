<?php

declare(strict_types=1);

/*
 * The standalone updater (spec section 5): the page that runs an update and its step endpoint (POST ?step=<name>).
 * Plain PHP, no Composer and no Laravel except the steps that boot it on purpose. Reachable without a portal session:
 * the steps need the job's token cookie (cp_update). Every path is derived from this file's location.
 * All the library is loaded first, because the finish step moves this folder while the request is running.
 */

require __DIR__.'/lib/load.php';

use ClubPortalUpdater\Page;

// Tests point the updater at a scratch app with a PHP constant (a request can never set one).
$clubportalApp = defined('CLUBPORTAL_UPDATER_APP') ? (string) constant('CLUBPORTAL_UPDATER_APP') : dirname(__DIR__, 2);
$clubportalMethod = isset($_SERVER['REQUEST_METHOD']) && is_string($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
$clubportalPost = strtoupper($clubportalMethod) === 'POST';

try {
    $clubportalResponse = Page::handle($clubportalApp, [
        'method' => $clubportalMethod,
        'query' => $_GET,
        'cookies' => $_COOKIE,
        'server' => $_SERVER,
        'body' => $clubportalPost ? (string) @file_get_contents('php://input', false, null, 0, 65536) : '',
    ]);
} catch (Throwable $e) {
    @error_log('Club Portal updater: '.$e->getMessage());
    // Not the expected JSON: the page retries the step, then puts the old version back if it has to.
    $clubportalResponse = ['status' => 500, 'headers' => ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-store'],
        'body' => "Something went wrong on the site. Reload this page in a minute.\n", 'cookies' => []];
}

if (! headers_sent()) {
    http_response_code($clubportalResponse['status']);
    foreach ($clubportalResponse['headers'] as $clubportalName => $clubportalValue) {
        header($clubportalName.': '.$clubportalValue);
    }
    foreach ($clubportalResponse['cookies'] as $clubportalCookie) {
        // setcookie, never setrawcookie: the value is base64 and its "+" must reach Laravel url-encoded.
        setcookie($clubportalCookie['name'], $clubportalCookie['value'], [
            'expires' => $clubportalCookie['expires'],
            'path' => '/',
            'secure' => Page::isHttps($_SERVER),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
echo $clubportalResponse['body'];
