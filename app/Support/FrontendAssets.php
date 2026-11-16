<?php

namespace App\Support;

use Illuminate\Support\Facades\Vite;

/**
 * What the Vite build in public/build can serve, for the Blade heads and the CSP
 * (App\Http\Middleware\SecurityHeaders). The server has no Node: assets are built
 * before upload, so a missing or older build must still give a working page.
 */
final class FrontendAssets
{
    /** The latin body font (resources/css/fonts.css), preloaded in the Blade heads. */
    public const BODY_FONT = 'node_modules/@fontsource-variable/plus-jakarta-sans/files/plus-jakarta-sans-latin-wght-normal.woff2';

    /** @var array<string, array<string, mixed>|null> manifest per public path (tests swap the public path) */
    private static array $manifests = [];

    /**
     * Bootstrap, Bootstrap Icons and Chart.js come from the build (resources/css/classic.css,
     * resources/js/classic.ts, resources/js/classic-charts.ts). When false (no build, or one made
     * before they were bundled) the classic pages fall back to the pinned jsDelivr copies with SRI.
     */
    public static function classicBundled(): bool
    {
        return Vite::isRunningHot() || isset(self::manifest()['resources/js/classic.ts']);
    }

    /** URL of the body font for <link rel="preload">, or null when the build does not have it (or Vite runs hot). */
    public static function bodyFontUrl(): ?string
    {
        if (Vite::isRunningHot() || ! isset(self::manifest()[self::BODY_FONT])) {
            return null;
        }

        return Vite::asset(self::BODY_FONT);
    }

    /** @return array<string, mixed> */
    private static function manifest(): array
    {
        $path = public_path('build/manifest.json');
        if (! array_key_exists($path, self::$manifests)) {
            $json = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
            self::$manifests[$path] = is_array($json) ? $json : null;
        }

        return self::$manifests[$path] ?? [];
    }
}
