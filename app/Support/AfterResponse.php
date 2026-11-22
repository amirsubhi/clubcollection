<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Run work (sending an email over SMTP) after the response has gone to the browser, without a queue
 * worker (cPanel has none). Laravel calls terminating callbacks after the response is sent; under
 * PHP-FPM / LiteSpeed the connection is closed first (fastcgi_finish_request), so the visitor does not
 * wait and the answer's timing does not depend on the work. A failure is logged, never shown.
 */
final class AfterResponse
{
    public static function run(callable $task, string $what): void
    {
        $done = false;
        app()->terminating(function () use (&$done, $task, $what) {
            // Terminating callbacks stay registered for the life of the application object (tests send
            // several requests through one): run each task once.
            if ($done) {
                return;
            }
            $done = true;

            try {
                $task();
            } catch (Throwable $e) {
                Log::error('After-response task failed: '.$what, ['error' => $e->getMessage()]);
            }
        });
    }
}
