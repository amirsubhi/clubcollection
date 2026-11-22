<?php

namespace App\Services;

use App\Support\Doctor\HostProbe;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Club and portal logos (settings phase 1). One path for every upload:
 *
 *  - only PNG, JPEG or WebP (checked by extension, by the detected MIME type and by the image header);
 *    never SVG (scripts) or GIF;
 *  - at most 2 MB and 4000 × 4000 px;
 *  - decoded and re-encoded with GD as a PNG of at most 512 px on the long side, so metadata (EXIF, GPS) and
 *    anything hidden after or inside the image data (polyglots) are dropped;
 *  - stored under a random name on the public disk (served same-origin from /storage, so the CSP needs no change).
 *
 * Old files are never deleted on replace or remove: an issued receipt keeps the logo path it was issued with
 * (payments.issuer_snapshot) and must still show it.
 */
class LogoService
{
    public const DISK = 'public';

    public const MAX_KB = 2048;

    public const MAX_SIDE_IN = 4000;

    public const MAX_SIDE_OUT = 512;

    /** GD holds about this many bytes per pixel for a truecolor image (as Thumbnailer). */
    private const BYTES_PER_PIXEL = 5;

    private const TYPES = [
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_JPEG => 'jpeg',
        IMAGETYPE_WEBP => 'webp',
    ];

    /** The PHP GD extension is loaded (with PNG output). */
    public static function available(): bool
    {
        return extension_loaded('gd') && function_exists('imagepng');
    }

    public static function missingGdMessage(): string
    {
        return 'This server cannot process images: the PHP GD extension is missing. Ask your host to enable GD (see docs/INSTALL.md), or save without a logo.';
    }

    /**
     * Validation rules for an optional logo upload field.
     *
     * @return list<mixed>
     */
    public static function rules(): array
    {
        return [
            'nullable',
            'file',
            'max:'.self::MAX_KB,
            // The name the browser sent and the type PHP detects from the content must both be PNG / JPEG / WebP.
            'extensions:png,jpg,jpeg,webp',
            'mimes:png,jpg,jpeg,webp',
            'mimetypes:image/png,image/jpeg,image/webp',
            function (string $attribute, mixed $value, \Closure $fail) {
                if ($value instanceof UploadedFile && ! self::available()) {
                    $fail(self::missingGdMessage());
                }
            },
        ];
    }

    /** @return array<string, string> */
    public static function messages(string $field = 'logo'): array
    {
        $type = 'The logo must be a PNG, JPEG or WebP image (SVG and GIF are not accepted).';

        return [
            "{$field}.extensions" => $type,
            "{$field}.mimes" => $type,
            "{$field}.mimetypes" => $type,
            "{$field}.max" => 'The logo may be at most 2 MB.',
        ];
    }

    /**
     * Re-encode an uploaded logo and store it; returns the path on the public disk (e.g. club-logos/{uuid}.png).
     *
     * @throws ValidationException when the file is not a usable PNG / JPEG / WebP image
     */
    public function store(UploadedFile $file, string $directory, string $field = 'logo'): string
    {
        $png = $this->reencode((string) file_get_contents($file->getRealPath()), $field);
        $path = trim($directory, '/').'/'.Str::uuid()->toString().'.png';
        Storage::disk(self::DISK)->put($path, $png);

        return $path;
    }

    /**
     * Decode the image (PNG / JPEG / WebP only, by its header) and draw it again as a PNG, at most MAX_SIDE_OUT px
     * on the long side, keeping transparency.
     *
     * @throws ValidationException
     */
    public function reencode(string $contents, string $field = 'logo'): string
    {
        $fail = fn (string $message) => throw ValidationException::withMessages([$field => $message]);

        if (! self::available()) {
            $fail(self::missingGdMessage());
        }
        if (strlen($contents) > self::MAX_KB * 1024) {
            $fail('The logo may be at most 2 MB.');
        }
        $info = @getimagesizefromstring($contents);
        $type = is_array($info) ? (self::TYPES[$info[2]] ?? null) : null;
        if ($type === null) {
            $fail('The logo must be a PNG, JPEG or WebP image (SVG and GIF are not accepted).');
        }
        [$w, $h] = $info;
        if ($w < 1 || $h < 1 || $w > self::MAX_SIDE_IN || $h > self::MAX_SIDE_IN) {
            $fail('The logo may be at most '.self::MAX_SIDE_IN.' × '.self::MAX_SIDE_IN.' pixels.');
        }
        if (! $this->fitsInMemory($w, $h)) {
            $fail('This image is too large for the server to process. Try a smaller image (e.g. 1000 × 1000 pixels).');
        }
        $decoder = 'imagecreatefrom'.$type;
        if (! function_exists($decoder)) {
            $fail('This server cannot read '.strtoupper($type).' images. Upload a PNG instead.');
        }

        // The type-specific decoder: a file whose header says PNG is read as a PNG, never sniffed again (so a GIF
        // can't slip through imagecreatefromstring). Through a temporary file: data:// URLs need allow_url_fopen.
        $tmp = tempnam(sys_get_temp_dir(), 'logo');
        try {
            file_put_contents($tmp, $contents);
            $src = @$decoder($tmp);
        } finally {
            @unlink($tmp);
        }
        if (! $src instanceof \GdImage) {
            $fail('The logo could not be read as an image. Save it again as a PNG and upload that.');
        }

        try {
            $scale = min(1, self::MAX_SIDE_OUT / max($w, $h));
            $tw = max(1, (int) round($w * $scale));
            $th = max(1, (int) round($h * $scale));
            $dst = imagecreatetruecolor($tw, $th);
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw, $th, $w, $h);

            ob_start();
            $ok = imagepng($dst, null, 6);
            $bytes = (string) ob_get_clean();
            imagedestroy($dst);
        } finally {
            imagedestroy($src);
        }

        if (! $ok || $bytes === '') {
            $fail('The logo could not be processed. Try another image.');
        }

        return $bytes;
    }

    /**
     * The public URL of a stored logo path, or null when there is none (or the file is gone). Only paths this
     * app writes (club-logos/, portal/, and the older clubs/logos/) are turned into a URL.
     */
    public static function url(?string $path): ?string
    {
        if (! is_string($path) || $path === '' || str_contains($path, '..')
            || ! preg_match('#^(club-logos|portal|clubs/logos)/[A-Za-z0-9._-]+$#', $path)) {
            return null;
        }
        $exists = rescue(fn () => Storage::disk(self::DISK)->exists($path), false, false);

        return $exists ? asset('storage/'.$path) : null;
    }

    /** Decoding the source and drawing the copy must fit in what memory_limit leaves. */
    private function fitsInMemory(int $w, int $h): bool
    {
        $limit = HostProbe::bytes(ini_get('memory_limit'));
        if ($limit === -1) {
            return true;
        }
        $needed = ($w * $h + self::MAX_SIDE_OUT * self::MAX_SIDE_OUT) * self::BYTES_PER_PIXEL;

        return memory_get_usage() + $needed < $limit * 0.9;
    }
}
