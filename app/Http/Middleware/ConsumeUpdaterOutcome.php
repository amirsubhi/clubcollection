<?php

namespace App\Http\Middleware;

use App\Support\Updates\UpdateOutcomes;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Moves what the standalone updater left behind (outcome.json, saved payment callbacks) into the portal: the audit
 * log, the history, the email and the webhook handler. When there is nothing, it makes two file checks and no more;
 * whatever it does can never break the request it rides on (spec section 12).
 */
class ConsumeUpdaterOutcome
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    /** After the response is sent (under FPM): a slow gateway check never makes someone's page wait. */
    public function terminate(Request $request, Response $response): void
    {
        try {
            if (UpdateOutcomes::pending()) {
                UpdateOutcomes::consume();
            }
        } catch (Throwable $e) {
            report($e);
        }
    }
}
