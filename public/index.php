<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Before anything else (the autoloader refuses an old PHP with a bare error): a server that can't run the
// app gets a short page saying what to change. Keep this file parseable by old PHP (bootstrap/preflight.php).
require __DIR__.'/../bootstrap/preflight.php';
if (($preflight = clubportal_preflight(dirname(__DIR__))) !== null) {
    clubportal_preflight_send($preflight);
    exit;
}

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
