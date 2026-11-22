<?php

namespace Tests\Support;

use Illuminate\Http\UploadedFile;

/**
 * Real image bytes (made with GD) and uploads whose MIME type PHP detects from the content, as a browser
 * upload would be (UploadedFile::fake() reports the type from the file name instead).
 */
trait LogoFixtures
{
    protected function pngBytes(int $w = 64, int $h = 32, bool $alpha = true): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        imagefill($img, 0, 0, imagecolorallocatealpha($img, 30, 60, 200, $alpha ? 64 : 0));
        ob_start();
        imagepng($img);

        return (string) ob_get_clean();
    }

    protected function jpegBytes(int $w = 64, int $h = 32): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 200, 30, 30));
        ob_start();
        imagejpeg($img);

        return (string) ob_get_clean();
    }

    protected function webpBytes(int $w = 64, int $h = 32): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 30, 200, 30));
        ob_start();
        imagewebp($img);

        return (string) ob_get_clean();
    }

    protected function gifBytes(): string
    {
        $img = imagecreatetruecolor(16, 16);
        ob_start();
        imagegif($img);

        return (string) ob_get_clean();
    }

    /** A JPEG with an EXIF (APP1) segment holding a marker, inserted after the SOI marker. */
    protected function jpegWithExif(string $marker): string
    {
        $jpeg = $this->jpegBytes();
        $payload = "Exif\0\0".$marker;
        $app1 = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;

        return substr($jpeg, 0, 2).$app1.substr($jpeg, 2);
    }

    protected function svgBytes(): string
    {
        return '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><script>alert(1)</script></svg>';
    }

    protected function upload(string $name, string $bytes): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'logo');
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $name, null, null, true);
    }
}
