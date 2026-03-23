<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Prevent clickjacking
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');

        // Prevent MIME-type sniffing
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // Enable XSS filter in legacy browsers
        $response->headers->set('X-XSS-Protection', '1; mode=block');

        // Only send referrer to same origin
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // Strict HTTPS (1 year, include subdomains)
        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        // Content Security Policy — restricts resource origins
        // Allows Bootstrap CDN and Bootstrap Icons CDN used throughout the app.
        $csp = implode('; ', [
            "default-src 'self'",
            "script-src 'self' https://cdn.jsdelivr.net 'unsafe-inline'",   // unsafe-inline needed for inline JS in views
            "style-src 'self' https://cdn.jsdelivr.net 'unsafe-inline'",
            "font-src 'self' https://cdn.jsdelivr.net",
            "img-src 'self' data: https:",
            "connect-src 'self'",
            "frame-ancestors 'self'",
            "form-action 'self' https://toyyibpay.com https://dev.toyyibpay.com",
            "object-src 'none'",
            "base-uri 'self'",
        ]);
        $response->headers->set('Content-Security-Policy', $csp);

        // Limit browser features
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        return $response;
    }
}
