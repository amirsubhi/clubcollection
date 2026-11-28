<?php

namespace Tests\Unit\Updates;

use App\Support\Updates\InvalidManifest;
use App\Support\Updates\Manifest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ManifestTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function fixture(): array
    {
        return [
            'format' => 1,
            'app' => 'clubportal',
            'version' => '2.2.3',
            'tag' => 'v2.2.3',
            'released_at' => '2026-12-01T00:00:00Z',
            'zip' => [
                'name' => 'clubportal-2.2.3.zip',
                'url' => 'https://github.com/amirsubhi/clubcollection/releases/download/v2.2.3/clubportal-2.2.3.zip',
                'size' => 12345,
                'sha256' => str_repeat('ab', 32),
            ],
            'php_requirement' => '>=8.4.1',
            'min_from_version' => '2.0.0',
            'security' => false,
            'last_security_version' => '2.2.2',
            'summary' => 'Quick update.',
            'manual_action' => null,
            'whats_new' => ['One thing', 'Another'],
            'notes_markdown' => 'Notes',
            'notes_url' => 'https://github.com/amirsubhi/clubcollection/releases/tag/v2.2.3',
            'signature' => null,
        ];
    }

    /** @param array<string, mixed> $d */
    private static function json(array $d): string
    {
        return json_encode($d, JSON_THROW_ON_ERROR);
    }

    public function test_valid_manifest_parses(): void
    {
        $m = Manifest::fromJson(self::json(self::fixture()));

        $this->assertSame('2.2.3', $m->version);
        $this->assertSame('v2.2.3', $m->tag);
        $this->assertSame('clubportal-2.2.3.zip', $m->zipName);
        $this->assertSame(12345, $m->zipSize);
        $this->assertSame(str_repeat('ab', 32), $m->zipSha256);
        $this->assertSame('>=8.4.1', $m->phpRequirement);
        $this->assertSame('2.0.0', $m->minFromVersion);
        $this->assertFalse($m->security);
        $this->assertSame('2.2.2', $m->lastSecurityVersion);
        $this->assertSame('Quick update.', $m->summary);
        $this->assertNull($m->manualAction);
        $this->assertSame(['One thing', 'Another'], $m->whatsNew);
        $this->assertSame(self::fixture(), $m->toArray());
    }

    public function test_empty_summary_becomes_null_and_optional_fields_may_be_absent(): void
    {
        $d = self::fixture();
        $d['summary'] = '';
        unset($d['manual_action'], $d['last_security_version'], $d['signature']);
        $m = Manifest::fromJson(self::json($d));

        $this->assertNull($m->summary);
        $this->assertNull($m->manualAction);
        $this->assertNull($m->lastSecurityVersion);
    }

    /** @return iterable<string, array{callable(array<string, mixed>): array<string, mixed>}> */
    public static function badManifests(): iterable
    {
        yield 'wrong app' => [fn ($d) => ['app' => 'other'] + $d];
        yield 'wrong format' => [fn ($d) => ['format' => 2] + $d];
        yield 'signature set' => [fn ($d) => ['signature' => 'abc'] + $d];
        yield 'other host' => [fn ($d) => array_replace_recursive($d, ['zip' => ['url' => 'https://example.com/amirsubhi/clubcollection/releases/download/v2.2.3/x.zip']])];
        yield 'other repo' => [fn ($d) => array_replace_recursive($d, ['zip' => ['url' => 'https://github.com/evil/clubcollection/releases/download/v2.2.3/x.zip']])];
        yield 'lookalike host' => [fn ($d) => array_replace_recursive($d, ['zip' => ['url' => 'https://github.com.evil.test/amirsubhi/clubcollection/releases/download/v2.2.3/x.zip']])];
        yield 'http url' => [fn ($d) => array_replace_recursive($d, ['zip' => ['url' => 'http://github.com/amirsubhi/clubcollection/releases/download/v2.2.3/x.zip']])];
        yield 'short sha' => [fn ($d) => array_replace_recursive($d, ['zip' => ['sha256' => 'abc123']])];
        yield 'non-hex sha' => [fn ($d) => array_replace_recursive($d, ['zip' => ['sha256' => str_repeat('zz', 32)]])];
        yield 'dot-dot traversal' => [fn ($d) => array_replace_recursive($d, ['zip' => ['url' => 'https://github.com/amirsubhi/clubcollection/releases/download/../../../../evil/repo/releases/download/v1/x.zip', 'name' => 'x.zip']])];
        yield 'encoded dots' => [fn ($d) => array_replace_recursive($d, ['zip' => ['url' => 'https://github.com/amirsubhi/clubcollection/releases/download/%2e%2e/%2e%2e/evil/x.zip', 'name' => 'x.zip']])];
        yield 'encoded slash' => [fn ($d) => array_replace_recursive($d, ['zip' => ['url' => 'https://github.com/amirsubhi/clubcollection/releases/download/v2.2.3%2fx.zip', 'name' => 'x.zip']])];
        yield 'backslash' => [fn ($d) => array_replace_recursive($d, ['zip' => ['url' => 'https://github.com/amirsubhi/clubcollection/releases/download/v2.2.3\x.zip', 'name' => 'x.zip']])];
        yield 'query' => [fn ($d) => array_replace_recursive($d, ['zip' => ['url' => 'https://github.com/amirsubhi/clubcollection/releases/download/v2.2.3/x.zip?x', 'name' => 'x.zip']])];
        yield 'fragment' => [fn ($d) => array_replace_recursive($d, ['zip' => ['url' => 'https://github.com/amirsubhi/clubcollection/releases/download/v2.2.3/x.zip#x', 'name' => 'x.zip']])];
        yield 'at sign' => [fn ($d) => array_replace_recursive($d, ['zip' => ['url' => 'https://github.com/amirsubhi/clubcollection/releases/download/v2.2.3/x@evil.zip', 'name' => 'x.zip']])];
        yield 'ends in slash dot-dot' => [fn ($d) => array_replace_recursive($d, ['zip' => ['url' => 'https://github.com/amirsubhi/clubcollection/releases/download/v2.2.3/..', 'name' => 'x.zip']])];
        yield 'single dot segment' => [fn ($d) => array_replace_recursive($d, ['zip' => ['url' => 'https://github.com/amirsubhi/clubcollection/releases/download/./x.zip', 'name' => 'x.zip']])];
        yield 'too many segments' => [fn ($d) => array_replace_recursive($d, ['zip' => ['url' => 'https://github.com/amirsubhi/clubcollection/releases/download/v2.2.3/a/x.zip', 'name' => 'x.zip']])];
        yield 'del char' => [fn ($d) => array_replace_recursive($d, ['zip' => ['url' => "https://github.com/amirsubhi/clubcollection/releases/download/v2.2.3/x\x7f.zip", 'name' => 'x.zip']])];
        yield 'notes other host' => [fn ($d) => ['notes_url' => 'https://example.com/amirsubhi/clubcollection/releases/tag/v2.2.3'] + $d];
        yield 'notes javascript' => [fn ($d) => ['notes_url' => 'javascript:alert(1)'] + $d];
        yield 'notes traversal' => [fn ($d) => ['notes_url' => 'https://github.com/amirsubhi/clubcollection/../evil/repo'] + $d];
        yield 'notes query' => [fn ($d) => ['notes_url' => 'https://github.com/amirsubhi/clubcollection/releases/tag/v2.2.3?x'] + $d];
        yield 'notes lookalike repo' => [fn ($d) => ['notes_url' => 'https://github.com/amirsubhi/clubcollection-evil/releases'] + $d];
        yield 'zip name traversal' => [fn ($d) => array_replace_recursive($d, ['zip' => ['name' => '..']])];
        yield 'zip name differs from url' => [fn ($d) => array_replace_recursive($d, ['zip' => ['name' => 'other.zip']])];
        yield 'security as string' => [fn ($d) => ['security' => 'true'] + $d];
        yield 'missing version' => [function ($d) {
            unset($d['version']);

            return $d;
        }];
        yield 'missing whats_new' => [function ($d) {
            unset($d['whats_new']);

            return $d;
        }];
        yield 'empty whats_new' => [fn ($d) => ['whats_new' => []] + $d];
        yield 'six whats_new' => [fn ($d) => ['whats_new' => ['a', 'b', 'c', 'd', 'e', 'f']] + $d];
        yield 'non-string whats_new' => [fn ($d) => ['whats_new' => ['a', 2]] + $d];
        yield 'long whats_new item' => [fn ($d) => ['whats_new' => [str_repeat('x', 201)]] + $d];
    }

    /** @param callable(array<string, mixed>): array<string, mixed> $mutate */
    #[DataProvider('badManifests')]
    public function test_invalid_manifest_throws(callable $mutate): void
    {
        $this->expectException(InvalidManifest::class);
        Manifest::fromJson(self::json($mutate(self::fixture())));
    }

    public function test_not_json_throws(): void
    {
        $this->expectException(InvalidManifest::class);
        Manifest::fromJson('<html>not json</html>');
    }

    public function test_json_over_256_kb_throws(): void
    {
        $d = self::fixture();
        $d['notes_markdown'] = str_repeat('x', 262144);

        $this->expectException(InvalidManifest::class);
        Manifest::fromJson(self::json($d));
    }

    public function test_is_security_for(): void
    {
        $m = Manifest::fromJson(self::json(self::fixture())); // latest 2.2.3, last security 2.2.2

        $this->assertTrue($m->isSecurityFor('2.2.1'));
        $this->assertFalse($m->isSecurityFor('2.2.2'));
        $this->assertFalse($m->isSecurityFor('2.2.3'));
    }

    public function test_no_last_security_version_is_never_security(): void
    {
        $d = self::fixture();
        $d['last_security_version'] = null;

        $this->assertFalse(Manifest::fromJson(self::json($d))->isSecurityFor('1.0.0'));
    }

    public function test_manifest_built_by_the_release_script_parses(): void
    {
        require_once dirname(__DIR__, 3).'/scripts/lib/release_manifest.php';
        $zip = tempnam(sys_get_temp_dir(), 'zip');
        file_put_contents($zip, 'zip bytes');
        $changelog = "## [2.2.0]\n\n### What's new\n- A thing\n\n### Security\n- Fixed\n\n## [2.0.0]\n\nOld.\n";

        try {
            $built = release_manifest('2.2.0', $zip, $changelog, ['min_from_version' => '2.0.0', 'manual_action' => null, 'php_requirement' => '>=8.4.1'], '2026-12-01T00:00:00Z');
        } finally {
            unlink($zip);
        }
        $m = Manifest::fromJson(json_encode($built));

        $this->assertSame('2.2.0', $m->version);
        $this->assertNull($m->summary);
        $this->assertTrue($m->security);
        $this->assertSame('2.2.0', $m->lastSecurityVersion);
    }
}
