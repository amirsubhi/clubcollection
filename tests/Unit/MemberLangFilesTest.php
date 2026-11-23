<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The member app's text lives in lang/member/<code>.json (English is the reference) and every language choice in
 * resources/js goes through resources/js/lib/i18n.ts, so a new language is a new file and never silently English.
 */
class MemberLangFilesTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /** @return array<string, string> */
    private static function strings(string $file): array
    {
        $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data, basename($file));

        return $data;
    }

    /** @return iterable<string, array{string}> */
    public static function languageFiles(): iterable
    {
        foreach (glob(self::root().'/lang/member/*.json') ?: [] as $file) {
            yield basename($file) => [$file];
        }
    }

    /** @return list<string> */
    private static function placeholders(string $text): array
    {
        preg_match_all('/\{(\w+)\}/', $text, $m);
        $names = array_values(array_unique($m[1]));
        sort($names);

        return $names;
    }

    public function test_english_and_malay_files_exist(): void
    {
        $this->assertFileExists(self::root().'/lang/member/en.json');
        $this->assertFileExists(self::root().'/lang/member/ms.json');
    }

    #[DataProvider('languageFiles')]
    public function test_every_file_has_exactly_the_english_keys(string $file): void
    {
        $en = array_keys(self::strings(self::root().'/lang/member/en.json'));
        $keys = array_keys(self::strings($file));

        $this->assertSame([], array_values(array_diff($en, $keys)), basename($file).' is missing keys');
        $this->assertSame([], array_values(array_diff($keys, $en)), basename($file).' has keys English does not');
    }

    #[DataProvider('languageFiles')]
    public function test_every_entry_is_text_with_the_english_placeholders(string $file): void
    {
        $en = self::strings(self::root().'/lang/member/en.json');
        foreach (self::strings($file) as $key => $text) {
            $this->assertIsString($text, basename($file).": {$key}");
            $this->assertNotSame('', trim($text), basename($file).": {$key} is empty");
            if (isset($en[$key])) {
                $this->assertSame(self::placeholders($en[$key]), self::placeholders($text), basename($file).": {$key} placeholders");
            }
        }
    }

    public function test_month_names_are_all_there(): void
    {
        $en = self::strings(self::root().'/lang/member/en.json');
        for ($m = 1; $m <= 12; $m++) {
            $this->assertArrayHasKey("month_{$m}", $en);
            $this->assertArrayHasKey("monthShort_{$m}", $en);
        }
    }

    /**
     * Outside the i18n module no code compares a language code ("bm", "ms", "en") or writes a "bm" literal or an
     * inline { bm: "…" } pair: such a branch would show English to any third language.
     */
    public function test_no_language_branches_outside_the_i18n_module(): void
    {
        $dir = self::root().'/resources/js';
        $module = realpath($dir.'/lib/i18n.ts');
        $patterns = [
            'comparison' => '/[=!]==?\s*["\'](?:bm|ms|en)["\']|["\'](?:bm|ms|en)["\']\s*[=!]==?/',
            '"bm" literal' => '/["\'`]bm["\'`]/',
            'inline bm pair' => '/\bbm\s*:\s*["\'`]/',
        ];
        $found = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (! preg_match('/\.(ts|tsx)$/', $file->getFilename()) || realpath($file->getPathname()) === $module) {
                continue;
            }
            foreach (file($file->getPathname()) ?: [] as $i => $line) {
                foreach ($patterns as $what => $re) {
                    if (preg_match($re, $line)) {
                        $found[] = substr($file->getPathname(), strlen($dir) + 1).':'.($i + 1)." ({$what}): ".trim($line);
                    }
                }
            }
        }

        $this->assertSame([], $found, "Use the helpers in resources/js/lib/i18n.ts instead:\n".implode("\n", $found));
    }
}
