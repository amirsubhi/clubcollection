<?php

namespace App\Support\Portal;

use App\Services\LogoService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Icons of the installable app (settings phase 2), made from the portal logo with GD: "any" icons (logo on white
 * with padding), "maskable" icons (logo on a white disc inside the central 80 % safe zone, on the brand colour),
 * the apple-touch-icon and PNG favicons. Stored on the public disk as portal/icons/{hash}-{name}.png; the hash
 * covers the logo path, the brand preset and VERSION, so a change gives new URLs (nothing is ever overwritten and
 * old files are kept, like logos).
 *
 * Generated only when the super admin saves or opens Portal settings, never on a public request. Pages and the
 * manifest just check whether the current set exists and fall back to the built-in public/icons otherwise (no
 * logo, GD missing, generation failed, or a logo saved before this release until Portal settings is opened).
 */
final class AppIcons
{
    /** Bump when the drawing changes, so every portal gets fresh files (and URLs). */
    public const VERSION = 'v1';

    public const DIR = 'portal/icons';

    /** name => [size, kind]. Written in this order; the last one doubles as the "set is complete" marker. */
    public const FILES = [
        'favicon-32' => [32, 'any'],
        'favicon-48' => [48, 'any'],
        'apple-touch-icon' => [180, 'any'],
        'icon-192' => [192, 'any'],
        'icon-512' => [512, 'any'],
        'maskable-192' => [192, 'maskable'],
        'maskable-512' => [512, 'maskable'],
    ];

    /** The built-in icons (public/icons), as in public/manifest.webmanifest. */
    public const BUILT_IN = [
        ['src' => '/icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => '/icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => '/icons/icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
    ];

    public const BUILT_IN_APPLE = '/icons/apple-touch-icon.png';

    /** The file-name prefix for a logo + preset, or null when there is no (valid) logo. */
    public static function hash(?string $logoPath, string $preset): ?string
    {
        $logoPath = LogoService::safePath($logoPath);
        if ($logoPath === null) {
            return null;
        }

        return substr(hash('sha256', self::VERSION.'|'.$logoPath.'|'.$preset), 0, 20);
    }

    public static function path(string $hash, string $name): string
    {
        return self::DIR.'/'.$hash.'-'.$name.'.png';
    }

    /**
     * Public URLs of the current icon set (name => URL), or null when there is none (no logo, or not generated):
     * the callers then use the built-in icons. Never throws.
     *
     * @return array<string, string>|null
     */
    public static function current(): ?array
    {
        $hash = self::hash(PortalSettings::get('logo'), BrandPalette::current());
        if ($hash === null) {
            return null;
        }
        $last = array_key_last(self::FILES);
        if (! rescue(fn () => Storage::disk(LogoService::DISK)->exists(self::path($hash, $last)), false, false)) {
            return null;
        }
        $urls = [];
        foreach (array_keys(self::FILES) as $name) {
            $urls[$name] = asset('storage/'.self::path($hash, $name));
        }

        return $urls;
    }

    /**
     * Make the icon set for the current logo and preset unless it exists. Returns whether a set is ready.
     * Failures (GD missing, unreadable logo, disk full) are logged and leave the built-in icons in use.
     */
    public static function ensure(): bool
    {
        $logo = LogoService::safePath(PortalSettings::get('logo'));
        $preset = BrandPalette::current();
        $hash = self::hash($logo, $preset);
        if ($hash === null || ! LogoService::available()) {
            return false;
        }
        $disk = Storage::disk(LogoService::DISK);
        if (rescue(fn () => $disk->exists(self::path($hash, array_key_last(self::FILES))), false, false)) {
            return true;
        }

        return (bool) rescue(function () use ($disk, $logo, $preset, $hash) {
            $source = $disk->exists($logo) ? @imagecreatefromstring((string) $disk->get($logo)) : false;
            if (! $source instanceof \GdImage) {
                return false;
            }
            try {
                foreach (self::FILES as $name => [$size, $kind]) {
                    $disk->put(self::path($hash, $name), self::draw($source, $size, $kind, $preset));
                }
            } finally {
                imagedestroy($source);
            }

            return true;
        }, function (\Throwable $e) {
            Log::warning('App icons could not be generated: '.$e->getMessage());

            return false;
        }, false);
    }

    /**
     * One square PNG. Drawn at twice the size and scaled down, which smooths the disc's edge (GD has no
     * anti-aliased ellipses).
     */
    public static function draw(\GdImage $logo, int $size, string $kind, string $preset): string
    {
        $big = $size * 2;
        $canvas = imagecreatetruecolor($big, $big);
        imagealphablending($canvas, true);
        $white = imagecolorallocate($canvas, 255, 255, 255);

        if ($kind === 'maskable') {
            // Solid brand colour to the edges (the launcher crops it to its own shape); the logo on a white disc
            // filling the central 80 % (the safe zone every mask keeps).
            [$r, $g, $b] = BrandPalette::rgb(BrandPalette::hex($preset, 'light'));
            imagefill($canvas, 0, 0, imagecolorallocate($canvas, $r, $g, $b));
            $disc = (int) round($big * 0.8);
            imagefilledellipse($canvas, intdiv($big, 2), intdiv($big, 2), $disc, $disc, $white);
            // The logo's box fits inside the disc with a small margin: its diagonal is at most 88 % of the disc.
            $w = imagesx($logo);
            $h = imagesy($logo);
            $scale = ($disc * 0.88) / sqrt($w * $w + $h * $h);
        } else {
            // White, so dark and transparent logos stay visible; 10 % padding on each side.
            imagefill($canvas, 0, 0, $white);
            $w = imagesx($logo);
            $h = imagesy($logo);
            $scale = ($big * 0.8) / max($w, $h);
        }
        $tw = max(1, (int) round($w * $scale));
        $th = max(1, (int) round($h * $scale));
        imagecopyresampled($canvas, $logo, intdiv($big - $tw, 2), intdiv($big - $th, 2), 0, 0, $tw, $th, $w, $h);

        $out = imagecreatetruecolor($size, $size);
        imagecopyresampled($out, $canvas, 0, 0, 0, 0, $size, $size, $big, $big);
        imagedestroy($canvas);

        ob_start();
        imagepng($out, null, 9);
        $png = (string) ob_get_clean();
        imagedestroy($out);

        return $png;
    }
}
