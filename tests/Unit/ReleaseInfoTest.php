<?php

namespace Tests\Unit;

use App\Support\ReleaseInfo;
use PHPUnit\Framework\TestCase;

/** G3: release.json parsing (config('app.version')) and the build script's exclude list. */
class ReleaseInfoTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->file = sys_get_temp_dir().'/release-info-test-'.bin2hex(random_bytes(4)).'.json';
    }

    protected function tearDown(): void
    {
        if (is_file($this->file)) {
            unlink($this->file);
        }
        parent::tearDown();
    }

    public function test_a_checkout_without_release_json_is_dev(): void
    {
        $this->assertSame('dev', ReleaseInfo::version($this->file));
        $this->assertSame([], ReleaseInfo::read($this->file));
    }

    public function test_reads_version_and_commit_from_release_json(): void
    {
        file_put_contents($this->file, json_encode(['version' => '0.0.2', 'commit' => 'abc123', 'built_at' => '2026-11-20T00:00:00Z', 'php_requirement' => '^8.4']));

        $this->assertSame('0.0.2', ReleaseInfo::version($this->file));
        $this->assertSame('abc123', ReleaseInfo::read($this->file)['commit']);
    }

    public function test_broken_or_odd_release_json_falls_back_to_dev(): void
    {
        foreach (['not json', '"0.0.2"', '{"version": ""}', '{"version": 2}', '[]'] as $content) {
            file_put_contents($this->file, $content);
            $this->assertSame('dev', ReleaseInfo::version($this->file), $content);
        }
    }

    public function test_build_script_builds_from_git_and_drops_dev_files_and_secrets(): void
    {
        $script = (string) file_get_contents(dirname(__DIR__, 2).'/scripts/build-release.sh');

        $this->assertStringContainsString('git -C "$REPO" archive', $script, 'built from a clean git export, never the working tree');
        $this->assertStringContainsString('composer install --no-dev', $script);
        $this->assertStringContainsString('npm ci', $script);
        $this->assertMatchesRegularExpression('/REMOVE_PATHS=\(\s*node_modules tests mockup scripts \.github/', $script);
        $this->assertStringContainsString("! -name '.env.example'", $script);
        $this->assertStringContainsString("'*.sqlite'", $script);
        $this->assertStringContainsString('find storage -type f ! -name .gitignore -delete', $script);
    }

    public function test_zip_check_refuses_secrets_state_and_dev_files(): void
    {
        $bad = ['.env', '.env.production', 'config/.env.local', 'auth.json', 'database/database.sqlite', 'database/x.sqlite-wal',
            'storage/app/.installed', 'storage/app/private/slip.jpg', 'storage/logs/laravel.log', 'bootstrap/cache/config.php',
            'public/storage/', 'public/hot', 'tests/Unit/X.php', 'mockup/index.html', 'node_modules/x/index.js', 'vendor/a/b/.git/HEAD'];
        $good = ['app/Models/User.php', '.env.example', '.htaccess', 'storage/app/.gitignore', 'storage/app/private/', 'bootstrap/cache/.gitignore',
            'public/build/manifest.json', 'public/.htaccess', 'release.json', 'vendor/autoload.php', 'database/migrations/0001_01_01_000000_create_users_table.php'];

        [$code, $out] = $this->checkList([...$bad, ...$good]);

        $this->assertSame(1, $code);
        $this->assertSame($bad, array_values(array_filter(explode("\n", $out))));
        $this->assertSame([0, ''], $this->checkList($good));
    }

    /** @param list<string> $paths @return array{int, string} */
    private function checkList(array $paths): array
    {
        $proc = proc_open(['bash', dirname(__DIR__, 2).'/scripts/build-release.sh', '--check-list'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w']], $pipes);
        fwrite($pipes[0], implode("\n", array_map(fn ($p) => "clubportal-1.2.3/{$p}", $paths))."\n");
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        return [proc_close($proc), trim($out)];
    }
}
