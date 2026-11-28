<?php

declare(strict_types=1);

/**
 * Writes the release manifest (clubportal-manifest.json) for a built zip.
 *
 *   php scripts/release-manifest.php <version> <zip> <out.json> [<app-folder>]
 *
 * <app-folder> holds CHANGELOG.md, release-policy.json and bootstrap/requirements.php (default: this repository).
 * Exits 1 with a message when the CHANGELOG section or its "What's new" bullets are missing, or when the finished
 * manifest would not pass the app's own check (App\Support\Updates\Manifest::fromJson): the build fails.
 */
use App\Support\Updates\InvalidManifest;
use App\Support\Updates\Manifest;

require __DIR__.'/lib/release_manifest.php';

if ($argc < 4 || $argc > 5) {
    fwrite(STDERR, "usage: php {$argv[0]} <version> <zip> <out.json> [<app-folder>]\n");
    exit(2);
}

[, $version, $zip, $out] = $argv;
$root = rtrim($argv[4] ?? dirname(__DIR__), '/');

try {
    // The app's validator, loaded by hand (no Composer autoloader here); it lives in the app folder being released.
    foreach (['Version', 'InvalidManifest', 'Manifest'] as $class) {
        require_once "$root/app/Support/Updates/$class.php";
    }
    if (! is_file($zip)) {
        throw new RuntimeException("No such zip: $zip");
    }
    $policy = json_decode((string) file_get_contents("$root/release-policy.json"), true, 512, JSON_THROW_ON_ERROR);
    $requirements = require "$root/bootstrap/requirements.php";
    $policy['php_requirement'] = '>='.$requirements['php'];

    $manifest = release_manifest(
        $version,
        $zip,
        (string) file_get_contents("$root/CHANGELOG.md"),
        $policy,
        gmdate('Y-m-d\TH:i:s\Z'),
    );
    $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
    try {
        Manifest::fromJson($json);
    } catch (InvalidManifest $e) {
        throw new RuntimeException('The manifest would be rejected by every site: '.$e->getMessage());
    }
    file_put_contents($out, $json);
} catch (Throwable $e) {
    fwrite(STDERR, 'release-manifest: '.$e->getMessage()."\n");
    exit(1);
}
