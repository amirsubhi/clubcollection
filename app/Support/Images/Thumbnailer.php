<?php

namespace App\Support\Images;

use App\Support\Doctor\HostProbe;

/**
 * G2: a downscaled, re-encoded copy of an uploaded image for gallery tiles
 * and the slip preview, so those pages never send the full original.
 * Re-encoding with GD drops EXIF (GPS position, camera serial...); the
 * EXIF orientation is applied to the small copy (after downscaling, so no
 * second full-size buffer) so phone photos stay upright.
 *
 * Returns null (the caller answers 404, never the original) when the image
 * cannot be decoded, is over MAX_PIXELS, or would not fit in memory.
 */
final class Thumbnailer
{
    public const MAX_PIXELS = 25_000_000;

    /** Long side of a gallery tile. */
    public const TILE = 480;

    /** Long side of the large slip preview (still a copy, never the original). */
    public const LARGE = 1600;

    /** GD holds about this many bytes per pixel for a truecolor image. */
    private const BYTES_PER_PIXEL = 5;

    /**
     * @return array{0: string, 1: string}|null [bytes, mime]
     */
    public static function make(string $contents, string $mime, int $maxSide = self::TILE): ?array
    {
        if (! function_exists('imagecreatefromstring')) {
            return null;
        }
        $info = @getimagesizefromstring($contents);
        if ($info === false || $info[0] < 1 || $info[1] < 1) {
            return null;
        }
        [$w, $h] = $info;
        if ($w * $h > self::MAX_PIXELS || ! self::fitsInMemory($w, $h, $maxSide)) {
            return null;
        }

        $src = @imagecreatefromstring($contents);
        if ($src === false) {
            return null;
        }
        $orientation = $mime === 'image/jpeg' ? self::exifOrientation($contents) : 1;
        try {
            $scale = min(1, $maxSide / max($w, $h));
            $tw = max(1, (int) round($w * $scale));
            $th = max(1, (int) round($h * $scale));

            $dst = imagecreatetruecolor($tw, $th);
            $keepAlpha = in_array($mime, ['image/png', 'image/webp'], true);
            if ($keepAlpha) {
                imagealphablending($dst, false);
                imagesavealpha($dst, true);
                imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
            } else {
                imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
            }
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw, $th, $w, $h);
            $dst = self::orient($dst, $orientation);

            ob_start();
            $out = match (true) {
                $mime === 'image/png' => imagepng($dst, null, 6) ? 'image/png' : null,
                $mime === 'image/webp' && function_exists('imagewebp') => imagewebp($dst, null, 80) ? 'image/webp' : null,
                default => imagejpeg($dst, null, 82) ? 'image/jpeg' : null,
            };
            $bytes = (string) ob_get_clean();
            imagedestroy($dst);

            return $out !== null && $bytes !== '' ? [$bytes, $out] : null;
        } finally {
            imagedestroy($src);
        }
    }

    /** Decoding the source and drawing the copy must fit in what memory_limit leaves. */
    private static function fitsInMemory(int $w, int $h, int $maxSide): bool
    {
        $limit = HostProbe::bytes(ini_get('memory_limit'));
        if ($limit === -1) {
            return true;
        }
        // The source, the copy, and the rotated copy (orientation applied after downscaling).
        $needed = ($w * $h + 2 * $maxSide * $maxSide) * self::BYTES_PER_PIXEL;

        return memory_get_usage() + $needed < $limit * 0.9;
    }

    /** The EXIF Orientation tag (1 when absent or unreadable). */
    private static function exifOrientation(string $contents): int
    {
        if (! function_exists('exif_read_data')) {
            return 1;
        }
        $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($contents));

        return is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;
    }

    /** Apply the EXIF Orientation tag (phone photos) to the small copy before EXIF is dropped. */
    private static function orient(\GdImage $img, int $orientation): \GdImage
    {

        $rotated = match ($orientation) {
            3 => imagerotate($img, 180, 0),
            6 => imagerotate($img, -90, 0),
            8 => imagerotate($img, 90, 0),
            default => null,
        };
        if ($rotated instanceof \GdImage) {
            imagedestroy($img);
            $img = $rotated;
        }
        if (in_array($orientation, [2, 4, 5, 7], true)) {
            // Mirrored variants: rare (front cameras); flip then rotate.
            imageflip($img, $orientation === 4 ? IMG_FLIP_VERTICAL : IMG_FLIP_HORIZONTAL);
            $turn = match ($orientation) { 5 => 90, 7 => -90, default => 0 };
            if ($turn !== 0 && ($r = imagerotate($img, $turn, 0)) instanceof \GdImage) {
                imagedestroy($img);
                $img = $r;
            }
        }

        return $img;
    }
}
