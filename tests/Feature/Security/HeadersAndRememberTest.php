<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Tests\TestCase;

/** HSTS is configurable (no preload by default), the XSS auditor is off, static files get headers, remember-me is capped. */
class HeadersAndRememberTest extends TestCase
{
    public function test_hsts_defaults_to_this_host_only_without_preload(): void
    {
        $hsts = $this->get('https://localhost/login')->assertOk()->headers->get('Strict-Transport-Security');

        $this->assertSame('max-age=31536000', $hsts);
    }

    public function test_hsts_max_age_subdomains_and_preload_follow_the_config(): void
    {
        config(['security.hsts' => ['max_age' => 600, 'include_subdomains' => true, 'preload' => true]]);

        $this->assertSame('max-age=600; includeSubDomains; preload', $this->get('https://localhost/login')->headers->get('Strict-Transport-Security'));
    }

    public function test_hsts_is_not_sent_over_plain_http(): void
    {
        $this->assertFalse($this->get('http://localhost/login')->headers->has('Strict-Transport-Security'));
    }

    public function test_the_legacy_xss_auditor_is_turned_off(): void
    {
        $this->assertSame('0', $this->get('/login')->headers->get('X-XSS-Protection'));
    }

    public function test_images_are_limited_to_this_site_and_data_uris(): void
    {
        $csp = $this->get('/login')->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("img-src 'self' data:;", $csp);
        $this->assertStringNotContainsString('https:;', $csp);
    }

    public function test_an_asset_url_on_another_host_is_allowed_for_images(): void
    {
        config(['app.asset_url' => 'https://cdn.example.test/club']);

        $this->assertStringContainsString("img-src 'self' data: https://cdn.example.test;", $this->get('/login')->headers->get('Content-Security-Policy'));
    }

    public function test_htaccess_adds_the_basic_headers_to_static_files_without_doubling_php_ones(): void
    {
        $htaccess = file_get_contents(public_path('.htaccess'));

        foreach ([
            'Header setifempty X-Content-Type-Options "nosniff"',
            'Header setifempty X-Frame-Options "SAMEORIGIN"',
            'Header setifempty Referrer-Policy "strict-origin-when-cross-origin"',
        ] as $line) {
            $this->assertStringContainsString($line, $htaccess);
        }
        // The same values as the middleware, so a page never carries two different answers.
        $response = $this->get('/login');
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
        $this->assertSame('strict-origin-when-cross-origin', $response->headers->get('Referrer-Policy'));
    }

    public function test_keep_me_signed_in_lasts_thirty_days_by_default(): void
    {
        $this->assertSame(60 * 24 * 30, config('auth.guards.web.remember'));

        $user = User::factory()->create(['role' => 'member']);
        $response = $this->post('/login', ['email' => $user->email, 'password' => 'password', 'remember' => 'on']);

        $cookie = collect($response->headers->getCookies())->first(fn ($c) => str_starts_with($c->getName(), 'remember_web_'));
        $this->assertNotNull($cookie);
        $this->assertEqualsWithDelta(30, ($cookie->getExpiresTime() - time()) / 86400, 0.1);
    }
}
