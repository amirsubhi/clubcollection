<?php

namespace App\Http\Controllers;

use App\Support\Portal\AppIcons;
use App\Support\Portal\BrandPalette;
use App\Support\Portal\PortalSettings;
use Illuminate\Http\Response;

/**
 * The web app manifest with the portal's name, brand colour and icons (settings phase 2), at /app.webmanifest.
 *
 * public/manifest.webmanifest stays as the static fallback: the web server serves that file before Laravel runs,
 * so it can't be the dynamic one. This route runs without session, cookies or CSRF (no Set-Cookie), outside
 * check_installed (before the install it shows the defaults) and only reads settings (icons are generated on
 * Portal settings, never here). id / start_url / scope match the static file, so an installed app stays the same app.
 */
class AppManifestController extends Controller
{
    public function __invoke(): Response
    {
        $name = PortalSettings::name();
        $icons = AppIcons::current();

        $manifest = [
            'name' => $name,
            'short_name' => $name,
            'description' => 'Yuran dan dana kelab anda / Your club fees and fund',
            'id' => '/my/dashboard',
            'start_url' => '/my/dashboard',
            'scope' => '/',
            'display' => 'standalone',
            'lang' => 'ms',
            'background_color' => BrandPalette::SURFACES['light']['background'],
            'theme_color' => BrandPalette::hex(BrandPalette::current(), 'light'),
            'icons' => $icons === null ? AppIcons::BUILT_IN : [
                ['src' => $icons['icon-192'], 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => $icons['icon-512'], 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => $icons['maskable-192'], 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'maskable'],
                ['src' => $icons['maskable-512'], 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ];

        return response(
            json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR),
            200,
            [
                'Content-Type' => 'application/manifest+json; charset=utf-8',
                // Short: a new name or icon reaches browsers within minutes; the service worker never caches it.
                'Cache-Control' => 'public, max-age=300',
            ]
        );
    }
}
