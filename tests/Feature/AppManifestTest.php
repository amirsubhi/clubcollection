<?php

namespace Tests\Feature;

use App\Support\Portal\AppIcons;
use App\Support\Portal\BrandPalette;
use App\Support\Portal\PortalSettings;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\LogoFixtures;
use Tests\TestCase;

/** Settings phase 2: the routed web app manifest (/app.webmanifest) and the app icons made from the portal logo. */
class AppManifestTest extends TestCase
{
    use LogoFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('public');
    }

    /** @return array<string, mixed> */
    private function manifest(TestResponse $response): array
    {
        return json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_defaults_match_the_static_manifest_and_need_no_session(): void
    {
        $response = $this->get('/app.webmanifest')->assertOk();

        $this->assertStringStartsWith('application/manifest+json', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('max-age=300', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('public', (string) $response->headers->get('Cache-Control'));
        // No session or cookies: nothing to set.
        $this->assertSame([], $response->headers->getCookies());
        $this->assertFalse($response->headers->has('Set-Cookie'));

        $static = json_decode((string) file_get_contents(public_path('manifest.webmanifest')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($static, $this->manifest($response));
    }

    public function test_it_works_before_the_install(): void
    {
        @unlink(storage_path('app/.installed'));
        try {
            $manifest = $this->manifest($this->get('/app.webmanifest')->assertOk());
        } finally {
            touch(storage_path('app/.installed'));
        }
        $this->assertSame('Club Portal', $manifest['name']);
        $this->assertSame(AppIcons::BUILT_IN, $manifest['icons']);
    }

    public function test_it_works_when_the_settings_cannot_be_read(): void
    {
        $default = config('database.default');
        config([
            'database.connections.unreachable' => ['driver' => 'sqlite', 'database' => '/nonexistent/dir/db.sqlite', 'prefix' => ''],
            'database.default' => 'unreachable',
        ]);
        PortalSettings::flush();
        try {
            $manifest = $this->manifest($this->get('/app.webmanifest')->assertOk());
        } finally {
            config(['database.default' => $default]);
        }
        $this->assertSame('Club Portal', $manifest['name']);
        $this->assertSame('#5B4FCF', $manifest['theme_color']);
    }

    public function test_name_and_colour_come_from_the_settings_json_encoded(): void
    {
        PortalSettings::put(['name' => 'Kelab "Staf" </script><b>&', 'brand' => 'maroon']);

        $response = $this->get('/app.webmanifest')->assertOk();
        $body = (string) $response->getContent();
        $manifest = $this->manifest($response);

        $this->assertSame('Kelab "Staf" </script><b>&', $manifest['name']);
        $this->assertSame('Kelab "Staf" </script><b>&', $manifest['short_name']);
        $this->assertSame('#823447', $manifest['theme_color']);
        $this->assertSame('#F6F5FB', $manifest['background_color']);
        // Escaped in the JSON itself (no raw < or & even if someone renders it as HTML).
        $this->assertStringNotContainsString('<', $body);
        $this->assertStringNotContainsString('&', $body);
        $this->assertStringContainsString('\\"Staf\\"', $body);
        // The installed app stays the same app.
        $this->assertSame('/my/dashboard', $manifest['id']);
    }

    public function test_a_logo_makes_hashed_icons_that_the_manifest_and_pages_use(): void
    {
        $this->actingAsSuperAdmin();
        $this->put(route('admin.portal-settings.update'), ['logo' => $this->upload('logo.png', $this->pngBytes(300, 100, false))])
            ->assertSessionHasNoErrors();

        $logo = PortalSettings::get('logo');
        $hash = AppIcons::hash($logo, BrandPalette::DEFAULT);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{20}$/', $hash);
        foreach (AppIcons::FILES as $name => [$size, $kind]) {
            $path = AppIcons::path($hash, $name);
            Storage::disk('public')->assertExists($path);
            [$w, $h, $type] = getimagesizefromstring(Storage::disk('public')->get($path));
            $this->assertSame([$size, $size, IMAGETYPE_PNG], [$w, $h, $type], $name);
        }

        // Maskable: brand colour to the edge, a white disc in the safe zone, the logo in the middle.
        $img = imagecreatefromstring(Storage::disk('public')->get(AppIcons::path($hash, 'maskable-512')));
        $rgb = fn (int $x, int $y) => array_values(array_slice(imagecolorsforindex($img, imagecolorat($img, $x, $y)), 0, 3));
        $this->assertSame(BrandPalette::rgb('#5B4FCF'), $rgb(2, 2));
        $this->assertSame([255, 255, 255], $rgb(256, 70));
        $this->assertSame([30, 60, 200], $rgb(256, 256));
        // "any": white with the logo centred.
        $any = imagecreatefromstring(Storage::disk('public')->get(AppIcons::path($hash, 'icon-512')));
        $this->assertSame([255, 255, 255], array_values(array_slice(imagecolorsforindex($any, imagecolorat($any, 2, 2)), 0, 3)));

        $manifest = $this->manifest($this->get('/app.webmanifest'));
        $this->assertCount(4, $manifest['icons']);
        $this->assertSame(asset('storage/'.AppIcons::path($hash, 'maskable-512')), $manifest['icons'][3]['src']);
        $this->assertSame(['192x192 any', '512x512 any', '192x192 maskable', '512x512 maskable'], array_map(fn ($i) => $i['sizes'].' '.$i['purpose'], $manifest['icons']));

        auth()->logout();
        $this->get('/login')
            ->assertSee('<link rel="apple-touch-icon" href="'.asset('storage/'.AppIcons::path($hash, 'apple-touch-icon')).'">', false)
            ->assertSee('<link rel="icon" type="image/png" sizes="32x32" href="'.asset('storage/'.AppIcons::path($hash, 'favicon-32')).'">', false);
    }

    public function test_a_new_colour_gives_new_icon_urls_and_keeps_the_old_files(): void
    {
        $this->actingAsSuperAdmin();
        $this->put(route('admin.portal-settings.update'), ['logo' => $this->upload('logo.png', $this->pngBytes())]);
        $logo = PortalSettings::get('logo');
        $before = AppIcons::hash($logo, BrandPalette::DEFAULT);

        $this->put(route('admin.portal-settings.update'), ['brand' => 'teal'])->assertSessionHasNoErrors();

        $after = AppIcons::hash($logo, 'teal');
        $this->assertNotSame($before, $after);
        Storage::disk('public')->assertExists(AppIcons::path($before, 'maskable-512'));
        Storage::disk('public')->assertExists(AppIcons::path($after, 'maskable-512'));
        $img = imagecreatefromstring(Storage::disk('public')->get(AppIcons::path($after, 'maskable-512')));
        $this->assertSame(BrandPalette::rgb('#266D6D'), array_values(array_slice(imagecolorsforindex($img, imagecolorat($img, 2, 2)), 0, 3)));
        $this->assertStringContainsString($after, $this->get('/app.webmanifest')->getContent());

        // Removing the logo goes back to the built-in icons.
        $this->put(route('admin.portal-settings.update'), ['remove_logo' => '1']);
        $this->assertSame(AppIcons::BUILT_IN, $this->manifest($this->get('/app.webmanifest'))['icons']);
    }

    public function test_a_logo_without_generated_icons_uses_the_built_in_ones_until_settings_is_opened(): void
    {
        // As after an upgrade: the logo was saved before app icons existed.
        Storage::disk('public')->put('portal/'.str_repeat('a', 8).'-0000-0000-0000-000000000000.png', $this->pngBytes());
        PortalSettings::put(['logo' => 'portal/'.str_repeat('a', 8).'-0000-0000-0000-000000000000.png']);

        $this->assertNull(AppIcons::current());
        $this->assertSame(AppIcons::BUILT_IN, $this->manifest($this->get('/app.webmanifest'))['icons']);
        $this->get('/login')->assertSee('<link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">', false);

        $this->actingAsSuperAdmin();
        $this->get(route('admin.portal-settings.edit'))->assertOk()->assertSee('icons made from the portal logo');
        $this->assertNotNull(AppIcons::current());
    }

    public function test_an_unreadable_logo_file_leaves_the_built_in_icons(): void
    {
        Storage::disk('public')->put('portal/broken.png', 'not an image');
        PortalSettings::put(['logo' => 'portal/broken.png']);

        $this->assertFalse(AppIcons::ensure());
        $this->assertNull(AppIcons::current());
        $this->get('/app.webmanifest')->assertOk();
    }

    public function test_the_manifest_route_does_no_image_work(): void
    {
        Storage::disk('public')->put('portal/logo.png', $this->pngBytes());
        PortalSettings::put(['logo' => 'portal/logo.png']);

        $this->get('/app.webmanifest')->assertOk();

        $this->assertSame([], Storage::disk('public')->files(AppIcons::DIR));
    }
}
