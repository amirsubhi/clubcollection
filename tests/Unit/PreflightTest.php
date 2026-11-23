<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The guard at the top of public/index.php (bootstrap/preflight.php): runs before the autoloader, so it
 * is tested without the app, against a scratch app folder holding the real bootstrap/requirements.php.
 */
class PreflightTest extends TestCase
{
    private string $base;

    private array $req;

    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__, 2).'/bootstrap/preflight.php';
        $this->base = sys_get_temp_dir().'/preflight-'.bin2hex(random_bytes(4));
        mkdir($this->base.'/bootstrap', 0777, true);
        mkdir($this->base.'/storage/app', 0777, true);
        copy(dirname(__DIR__, 2).'/bootstrap/requirements.php', $this->base.'/bootstrap/requirements.php');
        $this->req = require $this->base.'/bootstrap/requirements.php';
    }

    protected function tearDown(): void
    {
        @unlink($this->base.'/bootstrap/requirements.php');
        @unlink($this->base.'/storage/app/.installed');
        @unlink($this->base.'/.env');
        @rmdir($this->base.'/storage/app');
        @rmdir($this->base.'/storage');
        @rmdir($this->base.'/bootstrap');
        @rmdir($this->base);
        parent::tearDown();
    }

    private static function all(): \Closure
    {
        return fn () => true;
    }

    private static function never(string $what): \Closure
    {
        return function () use ($what) {
            throw new \LogicException("{$what} must not be checked once installed");
        };
    }

    public function test_an_old_php_gets_the_friendly_503_page(): void
    {
        $page = clubportal_preflight($this->base, '8.3.6', self::all(), self::all());

        $this->assertIsArray($page);
        $this->assertSame(503, $page['status']);
        $this->assertSame('no-store', $page['headers']['Cache-Control']);
        $this->assertSame('nosniff', $page['headers']['X-Content-Type-Options']);
        $this->assertSame('noindex', $page['headers']['X-Robots-Tag']);
        $this->assertStringStartsWith('text/html', $page['headers']['Content-Type']);
        $this->assertStringContainsString('This portal needs PHP 8.4.1 or newer.', $page['body']);
        $this->assertStringContainsString('In cPanel, open MultiPHP Manager (or Select PHP Version) and choose PHP 8.4 for this site, then reload this page.', $page['body']);
        // Only the required version: not the running one, no paths.
        $this->assertStringNotContainsString('8.3.6', $page['body']);
        $this->assertStringNotContainsString($this->base, $page['body']);
        $this->assertStringNotContainsString('<script', $page['body']);
    }

    public function test_an_old_php_is_refused_even_once_installed(): void
    {
        touch($this->base.'/storage/app/.installed');

        $this->assertSame(503, clubportal_preflight($this->base, '8.4.0', self::all(), self::all())['status']);
    }

    public function test_a_new_enough_php_passes(): void
    {
        $this->assertNull(clubportal_preflight($this->base, $this->req['php'], self::all(), self::all()));
        $this->assertNull(clubportal_preflight($this->base, '8.5.0', self::all(), self::all()));
    }

    public function test_once_installed_only_the_version_is_checked(): void
    {
        touch($this->base.'/storage/app/.installed');

        $this->assertNull(clubportal_preflight($this->base, '8.4.12', self::never('an extension'), self::never('a folder')));
    }

    public function test_the_real_php_passes_with_the_defaults_when_installed(): void
    {
        if (version_compare(PHP_VERSION, $this->req['php'], '<')) {
            $this->markTestSkipped('this PHP is older than the requirement');
        }
        touch($this->base.'/storage/app/.installed');

        $this->assertNull(clubportal_preflight($this->base));
    }

    public function test_before_install_a_missing_pdo_extension_of_the_database_is_named(): void
    {
        file_put_contents($this->base.'/.env', "APP_NAME=x\nDB_CONNECTION=mariadb\n");
        $loaded = fn (string $ext) => $ext !== 'pdo_mysql';

        $page = clubportal_preflight($this->base, '8.4.12', $loaded, self::all());

        $this->assertSame(503, $page['status']);
        $this->assertStringContainsString('This portal needs the PHP extension pdo_mysql.', $page['body']);
        $this->assertStringContainsString('Select PHP Version, then Extensions, tick it for PHP 8.4', $page['body']);

        // The sqlite default (no .env) needs pdo_sqlite instead, which is there.
        unlink($this->base.'/.env');
        $this->assertNull(clubportal_preflight($this->base, '8.4.12', $loaded, self::all()));
    }

    public function test_before_install_pdo_and_openssl_are_needed(): void
    {
        $page = clubportal_preflight($this->base, '8.4.12', fn (string $ext) => ! in_array($ext, ['pdo', 'openssl'], true), self::all());

        $this->assertStringContainsString('the PHP extensions openssl, pdo.', $page['body']);
    }

    public function test_extensions_with_a_polyfill_are_left_to_the_install_page(): void
    {
        $this->assertNull(clubportal_preflight($this->base, '8.4.12', fn (string $ext) => ! in_array($ext, ['mbstring', 'zip', 'gd'], true), self::all()));
    }

    public function test_before_install_an_unwritable_views_or_cache_folder_is_named(): void
    {
        $page = clubportal_preflight($this->base, '8.4.12', self::all(), fn (string $path) => ! str_ends_with($path, '/bootstrap/cache'));

        $this->assertSame(503, $page['status']);
        $this->assertStringContainsString('PHP cannot write to the folder bootstrap/cache.', $page['body']);
        $this->assertStringContainsString('chmod -R 775 storage bootstrap/cache', $page['body']);
    }

    /**
     * The guard has to run on the PHP it refuses. A token scan for newer syntax (CI also runs php -l on
     * these files under PHP 5.6 and 7.4: .github/workflows/tests.yml).
     */
    public function test_the_guard_files_use_no_newer_php_syntax(): void
    {
        $newer = array_filter(array_map(fn (string $t) => defined($t) ? constant($t) : null,
            ['T_FN', 'T_MATCH', 'T_NULLSAFE_OBJECT_OPERATOR', 'T_ATTRIBUTE', 'T_COALESCE', 'T_COALESCE_EQUAL', 'T_SPACESHIP', 'T_ENUM', 'T_READONLY']));
        foreach (['bootstrap/preflight.php', 'bootstrap/requirements.php', 'public/index.php'] as $file) {
            $code = (string) file_get_contents(dirname(__DIR__, 2).'/'.$file);
            foreach (\PhpToken::tokenize($code) as $token) {
                $this->assertNotContains($token->id, $newer, "{$file}:{$token->line} uses {$token->getTokenName()}");
            }
            $this->assertDoesNotMatchRegularExpression('/function\s*\w*\s*\([^)]*\)\s*:/', $code, "{$file}: no return types");
            $this->assertDoesNotMatchRegularExpression('/function\s*\w*\s*\(\s*\??[\w\\\\]+\s+&?\$/', $code, "{$file}: no parameter types");
            $this->assertStringNotContainsString('::class', $code, $file);
        }
    }

    public function test_the_page_is_sent_with_its_headers(): void
    {
        $page = clubportal_preflight($this->base, '7.4.33', self::all(), self::all());

        ob_start();
        clubportal_preflight_send($page);
        $this->assertSame($page['body'], ob_get_clean());
    }
}
