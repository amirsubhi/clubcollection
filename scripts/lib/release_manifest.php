<?php

declare(strict_types=1);

/**
 * Release metadata built by scripts/build-release.sh: the manifest (clubportal-manifest.json) and the file
 * list inside the zip (release-files.json). Plain PHP, no Laravel: the build and the unit test `require` it.
 */
const RELEASE_REPO_URL = 'https://github.com/amirsubhi/clubcollection';

/**
 * Splits the changelog into its `## [x]` sections: a list of ['version' => 'Unreleased'|'2.2.1', 'body' => text
 * after the heading line]. A leading `v` is dropped from the version.
 *
 * @return list<array{version: string, body: string}>
 */
function release_changelog_sections(string $changelog): array
{
    $sections = [];
    $current = null;
    foreach (preg_split('/\R/', $changelog) as $line) {
        if (preg_match('/^## \[([^\]]+)\]/', $line, $m)) {
            if ($current !== null) {
                $sections[] = $current;
            }
            $current = ['version' => ltrim($m[1], 'vV'), 'body' => ''];

            continue;
        }
        if ($current !== null) {
            $current['body'] .= $line."\n";
        }
    }
    if ($current !== null) {
        $sections[] = $current;
    }

    return array_map(fn (array $s) => ['version' => $s['version'], 'body' => trim($s['body'])], $sections);
}

/** The body of the version's changelog section; a pre-release (x.y.z-rc.1) without its own section uses [Unreleased]. */
function release_changelog_section(string $changelog, string $version): ?string
{
    $version = ltrim($version, 'vV');
    $sections = release_changelog_sections($changelog);

    foreach ($sections as $section) {
        if ($section['version'] === $version) {
            return $section['body'];
        }
    }
    if (str_contains($version, '-')) {
        foreach ($sections as $section) {
            if (strcasecmp($section['version'], 'Unreleased') === 0) {
                return $section['body'];
            }
        }
    }

    return null;
}

function release_has_security_heading(string $body): bool
{
    return (bool) preg_match('/^###\s+Security\b/mi', $body);
}

/** Strips the Markdown the Updates page must not show: bold, italics, backticks and link syntax. */
function release_plain_text(string $text): string
{
    $text = preg_replace('/\[([^\]]+)\]\([^)]*\)/', '$1', $text);
    $text = str_replace(['**', '`'], '', $text);

    return trim(preg_replace('/\s+/', ' ', $text));
}

/** First line of the section's `> **Upgrade:**` note, without the markup. */
function release_summary(string $body): string
{
    foreach (preg_split('/\R/', $body) as $line) {
        if (preg_match('/^>\s*\*\*Upgrade:\*\*/', $line)) {
            return release_plain_text(preg_replace('/^>\s*/', '', $line));
        }
    }

    return '';
}

/**
 * The bullets of the section's `### What's new` subsection, as plain strings (wrapped lines joined).
 *
 * @return list<string>
 */
function release_whats_new(string $body): array
{
    $bullets = [];
    $inside = false;
    foreach (preg_split('/\R/', $body) as $line) {
        if (preg_match('/^###\s/', $line)) {
            $inside = (bool) preg_match("/^###\s+What['’]s new\s*$/i", $line);

            continue;
        }
        if (! $inside) {
            continue;
        }
        if (preg_match('/^[-*]\s+(.*)$/', $line, $m)) {
            $bullets[] = $m[1];
        } elseif ($bullets !== [] && preg_match('/^\s+\S/', $line)) {
            $bullets[count($bullets) - 1] .= ' '.trim($line);
        }
    }

    return array_values(array_filter(array_map('release_plain_text', $bullets), fn (string $b) => $b !== ''));
}

/**
 * The manifest for one release. $policy carries min_from_version, manual_action and php_requirement.
 *
 * @param  array{min_from_version: string, manual_action: ?string, php_requirement: string}  $policy
 * @return array<string, mixed>
 */
function release_manifest(string $version, string $zipPath, string $changelog, array $policy, string $releasedAt): array
{
    $version = ltrim($version, 'vV');
    $body = release_changelog_section($changelog, $version);
    if ($body === null) {
        throw new RuntimeException("CHANGELOG.md has no section for $version (and no [Unreleased] to fall back on).");
    }

    $whatsNew = release_whats_new($body);
    if ($whatsNew === [] || count($whatsNew) > 5) {
        throw new RuntimeException("The CHANGELOG section for $version needs a \"### What's new\" subsection with 1 to 5 bullets (found ".count($whatsNew).').');
    }

    // The newest version up to and including this one whose section has a Security heading ([Unreleased] counts as this version).
    $lastSecurity = null;
    foreach (release_changelog_sections($changelog) as $section) {
        $sectionVersion = strcasecmp($section['version'], 'Unreleased') === 0 ? $version : $section['version'];
        if (! release_has_security_heading($section['body']) || version_compare($sectionVersion, $version, '>')) {
            continue;
        }
        if ($lastSecurity === null || version_compare($sectionVersion, $lastSecurity, '>')) {
            $lastSecurity = $sectionVersion;
        }
    }

    $name = "clubportal-$version.zip";

    return [
        'format' => 1,
        'app' => 'clubportal',
        'version' => $version,
        'tag' => "v$version",
        'released_at' => $releasedAt,
        'zip' => [
            'name' => $name,
            'url' => RELEASE_REPO_URL."/releases/download/v$version/$name",
            'size' => filesize($zipPath),
            'sha256' => hash_file('sha256', $zipPath),
        ],
        'php_requirement' => $policy['php_requirement'],
        'min_from_version' => $policy['min_from_version'],
        'security' => release_has_security_heading($body),
        'last_security_version' => $lastSecurity,
        'summary' => release_summary($body),
        'manual_action' => $policy['manual_action'] ?? null,
        'whats_new' => $whatsNew,
        'notes_markdown' => $body,
        'notes_url' => RELEASE_REPO_URL."/releases/tag/v$version",
        'signature' => null,
    ];
}

/**
 * release-files.json: every regular file under $root with its SHA-256, sorted by path, not listing itself.
 * Symbolic links are skipped (the zip stores them as links).
 *
 * @return array{format: int, version: string, files: array<string, string>}
 */
function release_files(string $version, string $root): array
{
    $root = rtrim($root, '/');
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isLink() || ! $file->isFile()) {
            continue;
        }
        $relative = substr($file->getPathname(), strlen($root) + 1);
        if ($relative === 'release-files.json') {
            continue;
        }
        $files[$relative] = hash_file('sha256', $file->getPathname());
    }
    ksort($files, SORT_STRING);

    return ['format' => 1, 'version' => ltrim($version, 'vV'), 'files' => $files];
}
