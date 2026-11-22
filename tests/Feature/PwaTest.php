<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Option A, step A4: installable PWA (static fallback manifest, static-only service worker, CSP). The routed manifest: AppManifestTest. */
class PwaTest extends TestCase
{
    public function test_manifest_is_valid_and_points_members_home(): void
    {
        $manifest = json_decode((string) file_get_contents(public_path('manifest.webmanifest')), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('Club Portal', $manifest['name']);
        $this->assertSame('/my/dashboard', $manifest['start_url']);
        $this->assertSame('/', $manifest['scope']);
        $this->assertSame('standalone', $manifest['display']);
        $this->assertSame('#5B4FCF', $manifest['theme_color']);
        $this->assertSame('#F6F5FB', $manifest['background_color']);
        $this->assertSame(route('member.dashboard', absolute: false), $manifest['start_url']);

        $purposes = [];
        foreach ($manifest['icons'] as $icon) {
            $path = public_path(ltrim($icon['src'], '/'));
            $this->assertFileExists($path);
            [$w, $h, $type] = getimagesize($path);
            $this->assertSame($icon['sizes'], "{$w}x{$h}");
            $this->assertSame(IMAGETYPE_PNG, $type);
            $purposes[$icon['sizes'].' '.$icon['purpose']] = true;
        }
        $this->assertArrayHasKey('192x192 any', $purposes);
        $this->assertArrayHasKey('512x512 any', $purposes);
        $this->assertArrayHasKey('512x512 maskable', $purposes);
        $this->assertFileExists(public_path('icons/apple-touch-icon.png'));
    }

    public function test_service_worker_caches_static_assets_only(): void
    {
        $sw = (string) file_get_contents(public_path('sw.js'));

        $this->assertMatchesRegularExpression('/const CACHE_VERSION = "[^"]+";/', $sw);
        $this->assertStringContainsString('"/build/assets/"', $sw);
        $this->assertStringContainsString('"/icons/"', $sw);
        // App icons made from the portal logo have hashed names, so cache-first is safe; the manifest is not cached.
        $this->assertStringContainsString('"/storage/portal/icons/"', $sw);
        $this->assertMatchesRegularExpression('/function isStaticAsset\(url\) \{([\s\S]*?)\n\}/', $sw);
        preg_match('/function isStaticAsset\(url\) \{([\s\S]*?)\n\}/', $sw, $m);
        $this->assertStringNotContainsString('manifest', $m[1]);
        $this->assertStringContainsString('/offline.html', $sw);
        // Exactly one place writes to a runtime cache: the static-asset cache-first branch.
        $this->assertSame(1, substr_count($sw, 'cache.put('));
        $this->assertFileExists(public_path('offline.html'));
        $this->assertStringContainsString('Tiada sambungan internet', (string) file_get_contents(public_path('offline.html')));
    }

    public function test_htaccess_serves_sw_without_caching_and_manifest_type(): void
    {
        $htaccess = (string) file_get_contents(public_path('.htaccess'));

        $this->assertStringContainsString('sw\.js', $htaccess);
        $this->assertStringContainsString('Header set Cache-Control "no-cache"', $htaccess);
        $this->assertStringContainsString('AddType application/manifest+json .webmanifest', $htaccess);
    }

    public function test_csp_allows_same_origin_worker_and_manifest(): void
    {
        $this->withoutVite();
        $csp = $this->get('/login')->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("worker-src 'self'", $csp);
        $this->assertStringContainsString("manifest-src 'self'", $csp);
    }

    public function test_inertia_root_view_links_manifest_and_icons(): void
    {
        $this->withoutVite();

        $this->get('/dev/ui-kit')
            ->assertOk()
            ->assertSee('<link rel="manifest" href="/app.webmanifest">', false)
            ->assertSee('<meta name="theme-color" content="#1E1F23" media="(prefers-color-scheme: dark)">', false)
            ->assertSee('<meta name="apple-mobile-web-app-capable" content="yes">', false)
            ->assertSee('<meta name="apple-mobile-web-app-title" content="Club Portal">', false)
            ->assertSee('<link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">', false);
    }

    public function test_every_layout_links_the_routed_manifest(): void
    {
        $this->withoutVite();
        $club = \App\Models\Club::factory()->create();

        $this->get('/login')->assertSee('<link rel="manifest" href="/app.webmanifest">', false);
        $this->get('/no-such-page')->assertNotFound()->assertSee('<link rel="manifest" href="/app.webmanifest">', false);
        $this->actingAsClubAdmin($club);
        $this->get(route('admin.clubs.members.index', $club))->assertSee('<link rel="manifest" href="/app.webmanifest">', false);
    }
}
