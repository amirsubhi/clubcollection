<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2).'/scripts/lib/release_manifest.php';

/**
 * The release build's manifest (clubportal-manifest.json) and file list (release-files.json):
 * scripts/lib/release_manifest.php, run by scripts/release-manifest.php.
 */
class ReleaseManifestScriptTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    private function changelog(): string
    {
        return <<<'MD'
# Changelog

## [Unreleased]

> **Upgrade:** the *unreleased* upgrade note.

### What's new
- Unreleased bullet.

## [v2.2.3] — 2026-12-20

> **Upgrade:** from v2.2.0 or later with the **in-app updater**. Second sentence on a later line.
> More text.

### What's new
- Plain fix for members.

### Fixed
- A thing.

## [v2.2.2] — 2026-12-15

> **Upgrade:** security.

### Security
- Fixed a thing.

### What's new
- Security fix.

## [v2.2.1] — 2026-12-10

> **Upgrade:** only 2.2.1.

### What's new
- Only the 2.2.1 bullet.

## [v2.2.0] — 2026-12-01

### What's new
- The 2.2.0 bullet.
MD;
    }

    private function zip(string $content = 'zip bytes'): string
    {
        $path = tempnam(sys_get_temp_dir(), 'relzip');
        file_put_contents($path, $content);
        $this->tempFiles[] = $path;

        return $path;
    }

    private function policy(): array
    {
        return ['min_from_version' => '2.2.0', 'manual_action' => null, 'php_requirement' => '>=8.4.1'];
    }

    public function test_section_is_extracted_for_the_version(): void
    {
        $section = release_changelog_section($this->changelog(), '2.2.1');

        $this->assertStringContainsString('Only the 2.2.1 bullet.', $section);
        $this->assertStringNotContainsString('The 2.2.0 bullet.', $section);
        $this->assertStringNotContainsString('Security fix.', $section);
    }

    public function test_prerelease_falls_back_to_unreleased(): void
    {
        $section = release_changelog_section($this->changelog(), '2.2.0-dev.1');

        $this->assertStringContainsString('Unreleased bullet.', $section);
        $this->assertStringNotContainsString('Plain fix for members.', $section);
    }

    public function test_missing_section_is_an_error(): void
    {
        $this->assertNull(release_changelog_section($this->changelog(), '9.9.9'));

        $this->expectException(\RuntimeException::class);
        release_manifest('9.9.9', $this->zip(), $this->changelog(), $this->policy(), '2026-12-10T03:00:00Z');
    }

    public function test_security_flag_follows_the_heading(): void
    {
        $plain = release_manifest('2.2.3', $this->zip(), $this->changelog(), $this->policy(), '2026-12-20T03:00:00Z');
        $security = release_manifest('2.2.2', $this->zip(), $this->changelog(), $this->policy(), '2026-12-15T03:00:00Z');

        $this->assertFalse($plain['security']);
        $this->assertTrue($security['security']);
    }

    public function test_last_security_version_scans_older_sections(): void
    {
        $latest = release_manifest('2.2.3', $this->zip(), $this->changelog(), $this->policy(), '2026-12-20T03:00:00Z');
        $security = release_manifest('2.2.2', $this->zip(), $this->changelog(), $this->policy(), '2026-12-15T03:00:00Z');
        $none = release_manifest('2.2.1', $this->zip(), $this->changelog(), $this->policy(), '2026-12-10T03:00:00Z');

        $this->assertSame('2.2.2', $latest['last_security_version']);
        $this->assertSame('2.2.2', $security['last_security_version']);
        $this->assertNull($none['last_security_version']);
    }

    public function test_manifest_fields_and_zip_hash(): void
    {
        $zip = $this->zip(str_repeat('club portal ', 100));

        $m = release_manifest('2.2.1', $zip, $this->changelog(), $this->policy(), '2026-12-10T03:00:00Z');

        $this->assertSame(1, $m['format']);
        $this->assertSame('clubportal', $m['app']);
        $this->assertSame('2.2.1', $m['version']);
        $this->assertSame('v2.2.1', $m['tag']);
        $this->assertSame('2026-12-10T03:00:00Z', $m['released_at']);
        $this->assertSame('clubportal-2.2.1.zip', $m['zip']['name']);
        $this->assertSame('https://github.com/amirsubhi/clubcollection/releases/download/v2.2.1/clubportal-2.2.1.zip', $m['zip']['url']);
        $this->assertSame(filesize($zip), $m['zip']['size']);
        $this->assertSame(hash_file('sha256', $zip), $m['zip']['sha256']);
        $this->assertSame('>=8.4.1', $m['php_requirement']);
        $this->assertSame('2.2.0', $m['min_from_version']);
        $this->assertNull($m['manual_action']);
        $this->assertNull($m['signature']);
        $this->assertSame('only 2.2.1.', $m['summary']);
        $this->assertSame('https://github.com/amirsubhi/clubcollection/releases/tag/v2.2.1', $m['notes_url']);
        $this->assertStringContainsString('Only the 2.2.1 bullet.', $m['notes_markdown']);
        $this->assertSame(['Only the 2.2.1 bullet.'], $m['whats_new']);

        $custom = release_manifest('2.2.1', $zip, $this->changelog(), ['min_from_version' => '2.2.1', 'manual_action' => 'Add X to .env', 'php_requirement' => '>=8.4.1'], '2026-12-10T03:00:00Z');
        $this->assertSame('2.2.1', $custom['min_from_version']);
        $this->assertSame('Add X to .env', $custom['manual_action']);
    }

    public function test_summary_is_the_first_sentence_of_the_upgrade_note_without_markup_or_label(): void
    {
        $m = release_manifest('2.2.3', $this->zip(), $this->changelog(), $this->policy(), '2026-12-20T03:00:00Z');

        $this->assertSame('from v2.2.0 or later with the in-app updater.', $m['summary']);
    }

    public function test_summary_joins_continuation_lines_and_is_not_cut_mid_sentence(): void
    {
        $changelog = "## [v2.1.0] — 2026-11-26\n\n> **Upgrade:** from v2.0.0 or v2.0.1 with the release zip\n"
            ."> and `docs/RELEASE.md` §2. One new migration (`users.locale`).\n> More.\n\n### What's new\n- Thing.\n";
        $wrapped = "## [v2.1.1] — 2026-11-27\n\n> **Upgrade:** no database changes, a patch release that\n> carries two small features\n\n### What's new\n- Thing.\n";
        $none = "## [v2.1.2] — 2026-11-28\n\n### What's new\n- Thing.\n";

        $this->assertSame('from v2.0.0 or v2.0.1 with the release zip and docs/RELEASE.md §2.', release_manifest('2.1.0', $this->zip(), $changelog, $this->policy(), '2026-11-26T03:00:00Z')['summary']);
        $this->assertSame('no database changes, a patch release that carries two small features', release_manifest('2.1.1', $this->zip(), $wrapped, $this->policy(), '2026-11-27T03:00:00Z')['summary']);
        $this->assertNull(release_manifest('2.1.2', $this->zip(), $none, $this->policy(), '2026-11-28T03:00:00Z')['summary']);
    }

    public function test_unreleased_security_counts_only_for_a_prerelease_build(): void
    {
        $changelog = "## [Unreleased]\n\n### Security\n- Fix.\n\n### What's new\n- Next thing.\n\n"
            ."## [v2.2.1] — 2026-12-10\n\n### What's new\n- Only 2.2.1.\n";

        $stable = release_manifest('2.2.1', $this->zip(), $changelog, $this->policy(), '2026-12-10T03:00:00Z');
        $rc = release_manifest('2.3.0-rc.1', $this->zip(), $changelog, $this->policy(), '2026-12-11T03:00:00Z');

        $this->assertNull($stable['last_security_version']);
        $this->assertFalse($stable['security']);
        $this->assertSame('2.3.0-rc.1', $rc['last_security_version']);
        $this->assertTrue($rc['security']);
    }

    public function test_a_bullet_over_200_characters_fails_the_build(): void
    {
        $long = "## [v2.3.0] — 2026-12-30\n\n### What's new\n- ".str_repeat('x', 201)."\n";
        $ok = "## [v2.3.0] — 2026-12-30\n\n### What's new\n- ".str_repeat('x', 200)."\n";

        $this->assertCount(1, release_manifest('2.3.0', $this->zip(), $ok, $this->policy(), '2026-12-30T03:00:00Z')['whats_new']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('200');
        release_manifest('2.3.0', $this->zip(), $long, $this->policy(), '2026-12-30T03:00:00Z');
    }

    public function test_the_command_refuses_a_manifest_the_app_would_reject(): void
    {
        $root = sys_get_temp_dir().'/relcli'.bin2hex(random_bytes(4));
        mkdir($root.'/bootstrap', 0777, true);
        mkdir($root.'/app/Support/Updates', 0777, true);
        foreach (['Version', 'InvalidManifest', 'Manifest'] as $class) {
            copy(dirname(__DIR__, 2)."/app/Support/Updates/$class.php", "$root/app/Support/Updates/$class.php");
        }
        file_put_contents($root.'/release-policy.json', '{"min_from_version": "2.2.0-rc.1", "manual_action": null}');
        file_put_contents($root.'/bootstrap/requirements.php', "<?php return ['php' => '8.4.1'];");
        $zip = $this->zip();
        $script = dirname(__DIR__, 2).'/scripts/release-manifest.php';
        $run = function (string $changelog) use ($root, $script, $zip): array {
            file_put_contents($root.'/CHANGELOG.md', $changelog);
            $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' 2.3.0 '.escapeshellarg($zip).' '.escapeshellarg($root.'/out.json').' '.escapeshellarg($root).' 2>&1';
            exec($cmd, $output, $code);

            return [$code, implode("\n", $output)];
        };

        try {
            [$goodCode, $goodOut] = $run("## [v2.3.0] — 2026-12-30\n\n### What's new\n- Fine.\n");
            $this->assertSame(0, $goodCode, $goodOut);
            $this->assertSame(['Fine.'], json_decode(file_get_contents($root.'/out.json'), true)['whats_new']);

            @unlink($root.'/out.json');
            [$code, $output] = $run("## [v2.3.0] — 2026-12-30\n\n### What's new\n- ".str_repeat('x', 224)."\n");
            $this->assertSame(1, $code);
            $this->assertStringContainsString('release-manifest:', $output);
            $this->assertFileDoesNotExist($root.'/out.json');
        } finally {
            array_map('unlink', glob($root.'/{*.*,bootstrap/*,app/Support/Updates/*}', GLOB_BRACE));
            rmdir($root.'/bootstrap');
            rmdir($root.'/app/Support/Updates');
            rmdir($root.'/app/Support');
            rmdir($root.'/app');
            rmdir($root);
        }
    }

    public function test_release_files_fails_on_a_symbolic_link(): void
    {
        $dir = sys_get_temp_dir().'/relsym'.bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir.'/a.txt', 'ay');
        symlink($dir.'/a.txt', $dir.'/link.txt');

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('link.txt');
            release_files('2.2.1', $dir);
        } finally {
            unlink($dir.'/link.txt');
            unlink($dir.'/a.txt');
            rmdir($dir);
        }
    }

    public function test_whats_new_bullets(): void
    {
        $changelog = "## [v2.3.0] — 2026-12-30\n\n### What's new\n"
            ."- The **portal** now says when `a new version` is out.\n"
            ."- A second line that\n  wraps onto the next line.\n"
            ."* Third one.\n\n### Fixed\n- Not this.\n";

        $m = release_manifest('2.3.0', $this->zip(), $changelog, $this->policy(), '2026-12-30T03:00:00Z');

        $this->assertSame([
            'The portal now says when a new version is out.',
            'A second line that wraps onto the next line.',
            'Third one.',
        ], $m['whats_new']);
    }

    public function test_whats_new_missing_or_over_five_fails_the_build(): void
    {
        $missing = "## [v2.3.0] — 2026-12-30\n\n### Fixed\n- A thing.\n";
        $empty = "## [v2.3.0] — 2026-12-30\n\n### What's new\n\n### Fixed\n- A thing.\n";
        $six = "## [v2.3.0] — 2026-12-30\n\n### What's new\n".implode("\n", array_map(fn ($i) => "- Bullet $i.", range(1, 6)))."\n";
        $five = "## [v2.3.0] — 2026-12-30\n\n### What's new\n".implode("\n", array_map(fn ($i) => "- Bullet $i.", range(1, 5)))."\n";

        foreach ([$missing, $empty, $six] as $changelog) {
            try {
                release_manifest('2.3.0', $this->zip(), $changelog, $this->policy(), '2026-12-30T03:00:00Z');
                $this->fail('Expected the build to fail');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString("What's new", $e->getMessage());
            }
        }

        $this->assertCount(5, release_manifest('2.3.0', $this->zip(), $five, $this->policy(), '2026-12-30T03:00:00Z')['whats_new']);
    }

    public function test_release_files_list_is_sorted_hashed_and_excludes_itself(): void
    {
        $dir = sys_get_temp_dir().'/relfiles'.bin2hex(random_bytes(4));
        mkdir($dir.'/sub', 0777, true);
        file_put_contents($dir.'/b.txt', 'bee');
        file_put_contents($dir.'/a.txt', 'ay');
        file_put_contents($dir.'/sub/c.txt', 'see');
        file_put_contents($dir.'/release-files.json', 'old');

        try {
            $list = release_files('2.2.1', $dir);
        } finally {
            array_map('unlink', glob($dir.'/{*.*,sub/*}', GLOB_BRACE));
            rmdir($dir.'/sub');
            rmdir($dir);
        }

        $this->assertSame(1, $list['format']);
        $this->assertSame('2.2.1', $list['version']);
        $this->assertSame(['a.txt', 'b.txt', 'sub/c.txt'], array_keys($list['files']));
        $this->assertSame(hash('sha256', 'ay'), $list['files']['a.txt']);
    }
}
