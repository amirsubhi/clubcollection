<?php

namespace App\Http\Middleware;

use App\Support\FrontendAssets;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Vite;
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
        // drop 'unsafe-inline' from the CSP.
        $nonce = base64_encode(random_bytes(16));
        View::share('cspNonce', $nonce);
        // Vite's <script>/<link> tags (Inertia pages) carry the same nonce.
        Vite::useCspNonce($nonce);

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
        // 'unsafe-inline' is intentionally absent from script-src and style-src
        // elements; all inline blocks use the nonce attribute. style-src-attr
        // allows inline style="" attributes only (Radix positioning, Recharts),
        // which cannot execute script.
        // Fonts, Bootstrap, Bootstrap Icons and Chart.js are served from public/build
        // (self-hosted, bundled by Vite). cdn.jsdelivr.net is allowed only when the
        // build is missing or predates that, so the classic pages can fall back to
        // the pinned jsDelivr copies (with SRI).
        $scriptSrc = ["'self'", "'nonce-{$nonce}'"];
        $styleSrc = ["'self'", "'nonce-{$nonce}'"];
        $fontSrc = ["'self'"];
        $connectSrc = ["'self'"];
        if (! FrontendAssets::classicBundled()) {
            $scriptSrc[] = 'https://cdn.jsdelivr.net';
            $styleSrc[] = 'https://cdn.jsdelivr.net';
            $fontSrc[] = 'https://cdn.jsdelivr.net';
        }

        // Local development only: allow the Vite dev server (npm run dev) for
        // scripts, styles and its HMR websocket.
        if (app()->environment('local') && ($devServer = $this->viteDevServer()) !== null) {
            $scriptSrc[] = $devServer;
            $styleSrc[] = $devServer;
            $fontSrc[] = $devServer;
            $connectSrc[] = $devServer;
            $connectSrc[] = preg_replace('#^http#', 'ws', $devServer);
        }

        $csp = implode('; ', [
            "default-src 'self'",
            'script-src '.implode(' ', $scriptSrc),
            'style-src '.implode(' ', $styleSrc),
            "style-src-attr 'unsafe-inline'",
            'font-src '.implode(' ', $fontSrc),
            "img-src 'self' data: https:",
            'connect-src '.implode(' ', $connectSrc),
            // PWA: the service worker (public/sw.js) and manifest are same-origin only.
            "worker-src 'self'",
            "manifest-src 'self'",
            "frame-ancestors 'self'",
            "form-action 'self' https://toyyibpay.com https://dev.toyyibpay.com https://www.billplz.com https://www.billplz-sandbox.com",
            "object-src 'none'",
            "base-uri 'self'",
        ]);
        // A response that sets its own (stricter) policy keeps it, e.g. the
        // sandboxed attachment stream (Admin\AttachmentController).
        if (! $response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy', $csp);
        }

        // Limit browser features
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        return $response;
    }

    /**
     * Origin (scheme://host:port) of the running Vite dev server, read from the
     * public/hot file that laravel-vite-plugin writes while `npm run dev` runs.
     */
    private function viteDevServer(): ?string
    {
        $hot = public_path('hot');
        if (! is_file($hot)) {
            return null;
        }

        $url = trim((string) file_get_contents($hot));
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
