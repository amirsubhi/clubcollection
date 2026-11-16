<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthPageLocale;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Branded error pages (resources/views/errors): BM by default, EN by the saved choice, the sign-in pages' look
 * (layouts.auth) with every inline style/script carrying the CSP nonce, and a way back. 500 and 503 must render
 * with no session and no database.
 */
class ErrorPagesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false]);
        // A route inside the web group (session, CSRF…) and one outside it (like an error before routing).
        Route::middleware('web')->get('/__test/abort/{code}', fn (int $code) => abort($code));
        Route::get('/__test/bare/{code}', fn (int $code) => abort($code));
        Route::get('/__test/crash', fn () => throw new \RuntimeException('boom'));
    }

    /** Every inline <style>/<script> carries the response's CSP nonce, and the CSP has no 'unsafe-inline' for them. */
    private function assertNonced(TestResponse $res): void
    {
        $csp = (string) $res->headers->get('Content-Security-Policy');
        $this->assertMatchesRegularExpression("/style-src [^;]*'nonce-([^']+)'/", $csp);
        preg_match("/'nonce-([^']+)'/", $csp, $m);
        $this->assertDoesNotMatchRegularExpression("/(script|style)-src [^;]*'unsafe-inline'/", $csp);

        preg_match_all('/<(style|script)\b([^>]*)>/i', $res->getContent(), $tags, PREG_SET_ORDER);
        $this->assertNotEmpty($tags);
        foreach ($tags as [$tag, $name, $attrs]) {
            if ($name === 'script' && str_contains($attrs, 'src=')) {
                continue;
            }
            $this->assertStringContainsString('nonce="'.$m[1].'"', $attrs, "inline {$tag} without the nonce");
        }
        // No inline handlers either.
        $this->assertDoesNotMatchRegularExpression('/\son[a-z]+="/i', $res->getContent());
    }

    public static function pages(): array
    {
        return [
            '403' => [403, 'Tiada akses ke halaman ini', 'No access to this page'],
            '404' => [404, 'Halaman tidak dijumpai', 'Page not found'],
            '419' => [419, 'Sesi anda tamat', 'Your session has ended'],
            '500' => [500, 'Ada masalah di pihak kami', 'Something went wrong'],
            '503' => [503, 'Sedang dikemas kini sebentar', 'Down for a short update'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('pages')]
    public function test_each_error_page_is_branded_in_bahasa_melayu_by_default(int $code, string $bm, string $en): void
    {
        $res = $this->get("/__test/abort/{$code}");

        $res->assertStatus($code)
            ->assertSee('<html lang="ms">', false)
            ->assertSee('class="auth-card"', false)
            ->assertSee('data-status="'.$code.'"', false)
            ->assertDontSee($en)
            ->assertSee('Ke laman utama');
        $this->assertMatchesRegularExpression('/<h1[^>]*>\s*'.preg_quote(e($bm), '/').'\s*<\/h1>/', $res->getContent());
        $this->assertNonced($res);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('pages')]
    public function test_each_error_page_follows_the_saved_language(int $code, string $bm, string $en): void
    {
        $res = $this->withUnencryptedCookie(AuthPageLocale::COOKIE, 'en')->get("/__test/abort/{$code}");

        $res->assertStatus($code)->assertSee('<html lang="en">', false)->assertSee('Go to the home page');
        $this->assertMatchesRegularExpression('/<h1[^>]*>\s*'.preg_quote(e($en), '/').'\s*<\/h1>/', $res->getContent());
        $this->assertNonced($res);
    }

    public function test_the_session_expired_page_says_sign_in_again(): void
    {
        $this->get('/__test/abort/419')
            ->assertStatus(419)
            ->assertSee('Sesi anda tamat. Sila log masuk semula.')
            ->assertSee('href="'.route('login').'"', false)
            ->assertSee('Log masuk semula');
    }

    public function test_an_unknown_url_gets_the_branded_404_with_the_csp_nonce(): void
    {
        // Thrown while routing, before the web middleware: the global SecurityHeaders still sets the nonce.
        $res = $this->get('/no-such-page-'.uniqid());
        $res->assertNotFound()->assertSee('Halaman tidak dijumpai')->assertDontSee('Not Found');
        $this->assertNonced($res);
    }

    public function test_back_goes_only_to_a_page_of_this_site(): void
    {
        $this->get('/__test/abort/404', ['referer' => url('/member/payments')])
            ->assertSee('href="'.url('/member/payments').'"', false)
            ->assertSee('>Kembali<', false);
        $this->get('/__test/abort/404', ['referer' => 'https://evil.example/phish'])
            ->assertDontSee('evil.example');
        // Same host but another scheme trick, or another port: no Back link.
        $host = parse_url(url('/'), PHP_URL_HOST);
        $this->get('/__test/abort/404', ['referer' => 'javascript://'.$host.'/%0aalert(1)'])
            ->assertDontSee('javascript:', false)->assertDontSee('>Kembali<', false);
        $this->get('/__test/abort/404', ['referer' => 'http://'.$host.':8999/member/payments'])
            ->assertDontSee(':8999', false)->assertDontSee('>Kembali<', false);
    }

    public function test_a_crash_shows_the_500_page_without_a_session_or_database(): void
    {
        $default = config('database.default');
        config([
            'database.connections.broken' => ['driver' => 'sqlite', 'database' => '/nonexistent-dir/'.uniqid().'.sqlite', 'prefix' => ''],
            'database.default' => 'broken',
        ]);
        try {
            $res = $this->get('/__test/crash');
            $res->assertStatus(500)->assertSee('Ada masalah di pihak kami')->assertDontSee('boom');
            $this->assertNonced($res);

            $this->get('/__test/bare/500')->assertStatus(500)->assertSee('semak bayaran anda');
        } finally {
            config(['database.default' => $default]);
        }
    }

    public function test_maintenance_mode_shows_the_branded_503(): void
    {
        $this->app->maintenanceMode()->activate([]);
        try {
            $res = $this->get('/login');
            $res->assertStatus(503)->assertSee('<html lang="ms">', false)->assertSee('Sedang dikemas kini sebentar');
            $this->assertNonced($res);
        } finally {
            $this->app->maintenanceMode()->deactivate();
        }
    }
}
