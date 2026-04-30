<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
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
        // Generate a per-request nonce for inline scripts/styles so we can
        // drop 'unsafe-inline' from the CSP while keeping Bootstrap CDN scripts.
        $nonce = base64_encode(random_bytes(16));
        View::share('cspNonce', $nonce);

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
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');
        }

        // Content Security Policy — per-request nonce for inline scripts/styles.
        // 'unsafe-inline' is intentionally absent; all inline blocks use the nonce attribute.
        $csp = implode('; ', [
            "default-src 'self'",
            "script-src 'self' https://cdn.jsdelivr.net 'nonce-{$nonce}'",
            "style-src 'self' https://cdn.jsdelivr.net 'nonce-{$nonce}'",
            "font-src 'self' https://cdn.jsdelivr.net",
            "img-src 'self' data: https:",
            "connect-src 'self'",
            "frame-ancestors 'self'",
            "form-action 'self' https://toyyibpay.com https://dev.toyyibpay.com https://www.billplz.com https://www.billplz-sandbox.com",
            "object-src 'none'",
            "base-uri 'self'",
        ]);
        $response->headers->set('Content-Security-Policy', $csp);

        // Limit browser features
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        return $response;
    }
}
