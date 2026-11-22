<?php

namespace Tests\Feature;

use App\Services\LogoService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\LogoFixtures;
use Tests\TestCase;

/** Settings phase 1: every logo is a re-encoded PNG of at most 512 px under a random name; SVG / GIF never get in. */
class LogoServiceTest extends TestCase
{
    use LogoFixtures;

    private LogoService $logos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTrue(LogoService::available(), 'These tests need the PHP GD extension.');
        Storage::fake('public');
        $this->logos = new LogoService;
    }

    private function rejects(string $bytes, string $message): void
    {
        try {
            $this->logos->reencode($bytes);
            $this->fail('Expected the logo to be refused.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString($message, $e->errors()['logo'][0]);
        }
    }

    public function test_png_jpeg_and_webp_become_a_png(): void
    {
        foreach ([$this->pngBytes(), $this->jpegBytes(), $this->webpBytes()] as $bytes) {
            $out = $this->logos->reencode($bytes);
            $info = getimagesizefromstring($out);
            $this->assertSame(IMAGETYPE_PNG, $info[2]);
            $this->assertSame([64, 32], [$info[0], $info[1]]);
        }
    }

    public function test_a_large_logo_is_scaled_down_to_512_on_the_long_side_keeping_its_shape(): void
    {
        $info = getimagesizefromstring($this->logos->reencode($this->pngBytes(2000, 1000)));

        $this->assertSame([512, 256], [$info[0], $info[1]]);
    }

    public function test_svg_and_gif_are_refused(): void
    {
        $this->rejects($this->svgBytes(), 'PNG, JPEG or WebP');
        $this->rejects($this->gifBytes(), 'PNG, JPEG or WebP');
        $this->rejects('not an image at all', 'PNG, JPEG or WebP');
    }

    public function test_a_polyglot_loses_whatever_was_hidden_in_it(): void
    {
        // A valid PNG with a script after the image data (and in a text chunk): only the pixels survive.
        $png = $this->pngBytes();
        $iend = strrpos($png, 'IEND') - 4;
        $text = 'Comment'."\0".'<script>alert(1)</script>';
        $chunk = pack('N', strlen($text)).'tEXt'.$text.pack('N', crc32('tEXt'.$text));
        $polyglot = substr($png, 0, $iend).$chunk.substr($png, $iend).'<?php system($_GET["c"]); ?>';

        $out = $this->logos->reencode($polyglot);

        $this->assertStringNotContainsString('<?php', $out);
        $this->assertStringNotContainsString('<script>', $out);
        $this->assertSame(IMAGETYPE_PNG, getimagesizefromstring($out)[2]);
    }

    public function test_exif_metadata_is_dropped(): void
    {
        $out = $this->logos->reencode($this->jpegWithExif('GPS-SECRET-3.1390,101.6869'));

        $this->assertStringNotContainsString('GPS-SECRET', $out);
    }

    public function test_oversize_files_and_images_are_refused(): void
    {
        $this->rejects($this->pngBytes().str_repeat("\0", 2 * 1024 * 1024), 'at most 2 MB');
        $this->rejects($this->pngBytes(4001, 2), 'at most 4000');
    }

    public function test_store_uses_a_random_png_name_on_the_public_disk(): void
    {
        $a = $this->logos->store($this->upload('../../evil.php.png', $this->pngBytes()), 'club-logos');
        $b = $this->logos->store($this->upload('logo.png', $this->pngBytes()), 'club-logos');

        $this->assertMatchesRegularExpression('#^club-logos/[0-9a-f-]{36}\.png$#', $a);
        $this->assertNotSame($a, $b);
        Storage::disk('public')->assertExists([$a, $b]);
    }

    public function test_url_is_built_only_for_stored_paths_this_app_writes(): void
    {
        $path = $this->logos->store($this->upload('logo.png', $this->pngBytes()), 'club-logos');

        $this->assertSame(asset('storage/'.$path), LogoService::url($path));
        $this->assertNull(LogoService::url(null));
        $this->assertNull(LogoService::url('club-logos/missing.png'));
        $this->assertNull(LogoService::url('../.env'));
        $this->assertNull(LogoService::url('https://evil.example/x.png'));
    }
}
