<?php

namespace Tests\Unit;

use App\Support\UiLang;
use PHPUnit\Framework\TestCase;

/**
 * Laravel's own language files (sign-in pages, member emails, validation messages). English is the key itself
 * (no lang/en.json); lang/ms.json is the reference list of sentences. Every other lang/<code>.json must translate
 * every one of them, and every member language but English needs lang/<code>/{auth,passwords,validation}.php,
 * or its sign-in pages and form errors show English (Laravel's fallback_locale). See docs/i18n-translator-brief.md.
 */
class LaravelLangFilesTest extends TestCase
{
    private const PHP_FILES = ['auth', 'passwords', 'validation'];

    /**
     * Keys only Malay uses: its emails open by the time of day (MailText::greeting / plainGreeting); every other
     * language keeps the "Hi / Dear / Hello **:name**," and "Hello!" keys, which stay in lang/ms.json for them.
     */
    private const MALAY_ONLY = [
        'Good morning **:name**,', 'Good afternoon **:name**,', 'Good evening **:name**,',
        'Good morning!', 'Good afternoon!', 'Good evening!',
    ];

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /** @return array<string, string> */
    private static function json(string $file): array
    {
        $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data, basename($file));

        return $data;
    }

    /** @return list<string> */
    private static function placeholders(string $text): array
    {
        preg_match_all('/:([a-zA-Z_]+)/', $text, $m);
        $names = array_values(array_unique($m[1]));
        sort($names);

        return $names;
    }

    public function test_malay_sentences_keep_the_placeholders_and_bold_markers(): void
    {
        foreach (self::json(self::root().'/lang/ms.json') as $key => $text) {
            $this->assertIsString($text, $key);
            $this->assertNotSame('', trim($text), $key);
            $this->assertSame(self::placeholders($key), self::placeholders($text), "ms.json: {$key}");
            $this->assertSame(substr_count($key, '**'), substr_count($text, '**'), "ms.json: {$key} bold markers");
        }
    }

    public function test_every_sentence_file_covers_the_malay_keys(): void
    {
        $ms = array_diff_key(self::json(self::root().'/lang/ms.json'), array_flip(self::MALAY_ONLY));
        $files = array_filter(glob(self::root().'/lang/*.json') ?: [], fn (string $f) => basename($f) !== 'ms.json');
        $this->assertGreaterThan(100, count($ms));
        foreach ($files as $file) {
            $this->assertSentenceFile($ms, $file);
        }
    }

    /** @param  array<string, string>  $ms */
    private function assertSentenceFile(array $ms, string $file): void
    {
        $other = self::json($file);
        $this->assertSame([], array_values(array_diff(array_keys($ms), array_keys($other))), basename($file).' is missing sentences');
        foreach ($ms as $key => $_) {
            $text = $other[$key];
            $this->assertIsString($text, basename($file).": {$key}");
            $this->assertNotSame('', trim($text), basename($file).": {$key}");
            $this->assertSame(self::placeholders($key), self::placeholders($text), basename($file).": {$key} placeholders");
            $this->assertSame(substr_count($key, '**'), substr_count($text, '**'), basename($file).": {$key} bold markers");
        }
    }

    /** A member language needs Laravel's files too (English has Laravel's own). */
    public function test_every_member_language_has_its_laravel_files(): void
    {
        foreach (UiLang::available() as $code) {
            if ($code === 'en') {
                continue;
            }
            $this->assertFileExists(self::root()."/lang/{$code}.json", "{$code}: sign-in and email sentences");
            foreach (self::PHP_FILES as $name) {
                $this->assertFileExists(self::root()."/lang/{$code}/{$name}.php", "{$code}: {$name}.php");
            }
        }
    }

    /** Each lang/<code>/{auth,passwords,validation}.php has the Malay file's keys, with the same :placeholders. */
    public function test_laravel_php_files_have_the_malay_keys(): void
    {
        foreach (self::PHP_FILES as $name) {
            $this->assertNotEmpty(self::flatten(require self::root()."/lang/ms/{$name}.php"), "ms/{$name}.php");
        }
        foreach (glob(self::root().'/lang/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $code = basename($dir);
            if (in_array($code, ['ms', 'member'], true)) {
                continue;
            }
            foreach (self::PHP_FILES as $name) {
                $ms = self::flatten(require self::root()."/lang/ms/{$name}.php");
                $path = "{$dir}/{$name}.php";
                $this->assertFileExists($path);
                $other = self::flatten(require $path);
                $this->assertSame([], array_values(array_diff(array_keys($ms), array_keys($other))), "{$code}/{$name}.php is missing keys");
                foreach ($ms as $key => $text) {
                    $this->assertSame(self::placeholders($text), self::placeholders($other[$key]), "{$code}/{$name}.php: {$key}");
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $lines
     * @return array<string, string>
     */
    private static function flatten(array $lines, string $prefix = ''): array
    {
        $out = [];
        foreach ($lines as $key => $value) {
            if (is_array($value)) {
                $out += self::flatten($value, $prefix.$key.'.');
            } else {
                $out[$prefix.$key] = (string) $value;
            }
        }

        return $out;
    }
}
