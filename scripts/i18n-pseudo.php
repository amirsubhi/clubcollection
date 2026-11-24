<?php

/*
 * Pseudo-language "xx" for checking a copy of the app before translators start (language phase 3). It is never
 * shipped: the files it writes are in .gitignore, and it refuses to write into a checkout whose .env says
 * APP_ENV=production.
 *
 *   php scripts/i18n-pseudo.php [root]   writes lang/member/xx.json, lang/xx.json, lang/xx/{auth,passwords,validation}.php
 *   php scripts/i18n-pseudo.php --clean [root]   removes them
 *
 * Every text is English with its letters accented, wrapped in [ ] and padded about 40% longer, for example
 * "[Pàý {x} ~~~~]". Placeholders ({x}, :name, :Attribute), the ** bold markers and the | between plural forms are
 * kept. So a run in "xx" shows:
 * - any member-page or email text WITHOUT brackets: hard-coded or worded by the server;
 * - text cut off or overflowing: no room for a longer language.
 * Run `npm run build` after it (the member app bundles its text). tests/Unit/PseudoLangTest.php checks the transform.
 */

$args = array_slice($argv, 1);
$clean = in_array('--clean', $args, true);
$root = rtrim(array_values(array_filter($args, fn ($a) => $a !== '--clean'))[0] ?? dirname(__DIR__), '/');

$env = is_file("{$root}/.env") ? (string) file_get_contents("{$root}/.env") : '';
if (preg_match('/^APP_ENV=\s*"?production"?\s*$/m', $env) === 1) {
    fwrite(STDERR, "Refusing: {$root}/.env has APP_ENV=production. The pseudo-language is for test and dev copies only.\n");
    exit(1);
}

$files = ["{$root}/lang/member/xx.json", "{$root}/lang/xx.json"];
foreach (['auth', 'passwords', 'validation'] as $name) {
    $files[] = "{$root}/lang/xx/{$name}.php";
}
if ($clean) {
    foreach ($files as $file) {
        is_file($file) && unlink($file);
    }
    is_dir("{$root}/lang/xx") && rmdir("{$root}/lang/xx");
    echo "Removed the pseudo-language files.\n";
    exit(0);
}

require_once __DIR__.'/../tests/Support/PseudoLang.php';

$member = json_decode((string) file_get_contents("{$root}/lang/member/en.json"), true, 512, JSON_THROW_ON_ERROR);
$out = ['_meta' => Tests\Support\PseudoLang::META];
foreach ($member as $key => $text) {
    if ($key !== '_meta') {
        $out[$key] = Tests\Support\PseudoLang::text($text);
    }
}
file_put_contents($files[0], json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");

// Laravel's sentences: the key is the English.
$ms = json_decode((string) file_get_contents("{$root}/lang/ms.json"), true, 512, JSON_THROW_ON_ERROR);
$sentences = [];
foreach (array_keys($ms) as $key) {
    $sentences[$key] = Tests\Support\PseudoLang::text($key);
}
file_put_contents($files[1], json_encode($sentences, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");

// Laravel's own English lines for the keys the Malay files have.
is_dir("{$root}/lang/xx") || mkdir("{$root}/lang/xx");
$vendor = "{$root}/vendor/laravel/framework/src/Illuminate/Translation/lang/en";
foreach (['auth', 'passwords', 'validation'] as $i => $name) {
    $english = is_file("{$vendor}/{$name}.php") ? require "{$vendor}/{$name}.php" : [];
    $lines = Tests\Support\PseudoLang::lines(require "{$root}/lang/ms/{$name}.php", $english);
    file_put_contents($files[2 + $i], "<?php\n\n// Pseudo-language for tests (scripts/i18n-pseudo.php). Never shipped.\nreturn ".var_export($lines, true).";\n");
}

echo 'Wrote '.implode(', ', array_map(fn ($f) => substr($f, strlen($root) + 1), $files)).". Now run npm run build.\n";
