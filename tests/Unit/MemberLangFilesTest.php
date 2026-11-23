<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The member app's text lives in lang/member/<code>.json (English is the reference) and every language choice in
 * resources/js goes through resources/js/lib/i18n.ts, so a new language is a new file and never silently English.
 * The rules a translator's file must meet are in docs/i18n-translator-brief.md.
 */
class MemberLangFilesTest extends TestCase
{
    /** Extra plural forms a language may add next to <base>_one / <base>_other (CLDR categories). */
    private const EXTRA_PLURAL_FORMS = ['zero', 'two', 'few', 'many'];

    /**
     * Pattern keys: a translation may reorder them and use any of the listed placeholders, not only English's
     * (e.g. a 24-hour clock in timeFormat).
     */
    private const FORMAT_KEYS = [
        'dateFormat' => ['d', 'm', 'y'],
        'monthYearFormat' => ['m', 'y'],
        'periodSameYear' => ['from', 'to', 'y'],
        'periodRange' => ['from', 'to'],
        'dateTimeFormat' => ['date', 'time'],
        'timeFormat' => ['h', 'H', 'HH', 'mm', 'ap'],
    ];

    /** Every value a language must give for itself in "_meta". */
    private const META_KEYS = ['name' => 'string', 'label' => 'string', 'htmlLang' => 'string', 'localeTag' => 'string', 'beta' => 'boolean'];

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /** @return array<string, mixed> */
    private static function file(string $file): array
    {
        $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data, basename($file));

        return $data;
    }

    /** The texts of a file, without its _meta. @return array<string, mixed> */
    private static function strings(string $file): array
    {
        $data = self::file($file);
        unset($data['_meta']);

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
    public function test_every_file_describes_itself(string $file): void
    {
        $data = self::file($file);
        $this->assertSame('_meta', array_key_first($data), basename($file).': _meta comes first');
        $meta = $data['_meta'];
        $this->assertIsArray($meta, basename($file).': _meta');
        $this->assertEqualsCanonicalizing(array_keys(self::META_KEYS), array_keys($meta), basename($file).': _meta keys');
        foreach (self::META_KEYS as $key => $type) {
            $this->assertSame($type, gettype($meta[$key]), basename($file).": _meta.{$key}");
            if ($type === 'string') {
                $this->assertNotSame('', trim($meta[$key]), basename($file).": _meta.{$key} is empty");
            }
        }
        // BCP 47 shapes, e.g. "ms", "zh-Hans", "ms-MY", "pt-BR".
        $this->assertMatchesRegularExpression('/^[a-z]{2,3}(-[A-Z][a-z]{3})?(-[A-Z]{2})?$/', $meta['htmlLang'], basename($file));
        $this->assertMatchesRegularExpression('/^[a-z]{2,3}(-[A-Z][a-z]{3})?(-[A-Z]{2})?$/', $meta['localeTag'], basename($file));
        $this->assertLessThanOrEqual(6, mb_strlen($meta['label']), basename($file).': the closed picker shows the label on a 320px phone');
    }

    #[DataProvider('languageFiles')]
    public function test_every_file_has_exactly_the_english_keys(string $file): void
    {
        $en = array_keys(self::strings(self::root().'/lang/member/en.json'));
        $keys = array_keys(self::strings($file));
        // Extra plural forms are allowed where English has the base (feesN_few next to feesN_one / feesN_other).
        $extra = array_filter(array_diff($keys, $en), function (string $key) use ($en) {
            return preg_match('/^(.+)_('.implode('|', self::EXTRA_PLURAL_FORMS).')$/', $key, $m) !== 1 || ! in_array($m[1].'_one', $en, true);
        });

        $this->assertSame([], array_values(array_diff($en, $keys)), basename($file).' is missing keys');
        $this->assertSame([], array_values($extra), basename($file).' has keys English does not');
    }

    #[DataProvider('languageFiles')]
    public function test_every_entry_is_text_with_the_english_placeholders(string $file): void
    {
        $en = self::strings(self::root().'/lang/member/en.json');
        foreach (self::strings($file) as $key => $text) {
            $this->assertIsString($text, basename($file).": {$key}");
            $this->assertNotSame('', trim($text), basename($file).": {$key} is empty");
            if (isset(self::FORMAT_KEYS[$key])) {
                $this->assertSame([], array_values(array_diff(self::placeholders($text), self::FORMAT_KEYS[$key])), basename($file).": {$key} uses an unknown placeholder");

                continue;
            }
            $reference = $en[$key] ?? (preg_match('/^(.+)_('.implode('|', self::EXTRA_PLURAL_FORMS).')$/', $key, $m) === 1 ? (isset($en[$m[1].'_one']) ? $en[$m[1].'_other'] : null) : null);
            if ($reference !== null) {
                $this->assertSame(self::placeholders($reference), self::placeholders($text), basename($file).": {$key} placeholders");
            }
        }
    }

    /** A date needs its day, month and year; a pattern key keeps every part the code fills in. */
    #[DataProvider('languageFiles')]
    public function test_date_patterns_keep_their_parts(string $file): void
    {
        $strings = self::strings($file);
        foreach (['dateFormat' => ['d', 'm', 'y'], 'monthYearFormat' => ['m', 'y'], 'periodSameYear' => ['from', 'to', 'y'], 'periodRange' => ['from', 'to'], 'dateTimeFormat' => ['date', 'time']] as $key => $parts) {
            $this->assertSame($parts, array_values(array_intersect($parts, self::placeholders($strings[$key]))), basename($file).": {$key} must use every one of {".implode('}, {', $parts).'}');
        }
        $time = self::placeholders($strings['timeFormat']);
        $this->assertContains('mm', $time, basename($file).': timeFormat needs {mm}');
        $this->assertNotSame([], array_intersect(['h', 'H', 'HH'], $time), basename($file).': timeFormat needs an hour');
        if (in_array('h', $time, true)) {
            $this->assertContains('ap', $time, basename($file).': a 12-hour {h} needs {ap}');
        }
    }

    /** Text with a count comes as <base>_one + <base>_other (countText() in i18n.ts picks the form). */
    public function test_count_texts_come_in_pairs(): void
    {
        $en = self::strings(self::root().'/lang/member/en.json');
        $bases = [];
        // A count text is a <base>_one key (cancelReason_other, a reason code, is not one).
        foreach (array_keys($en) as $key) {
            if (preg_match('/^(.+)_one$/', $key, $m) === 1) {
                $bases[] = $m[1];
            }
        }
        $this->assertGreaterThanOrEqual(14, count($bases));
        foreach ($bases as $base) {
            $this->assertArrayHasKey($base.'_other', $en, "{$base}: needs _one and _other");
            $this->assertContains('n', self::placeholders($en[$base.'_one']), "{$base}_one: needs {n}");
            $this->assertContains('n', self::placeholders($en[$base.'_other']), "{$base}_other: needs {n}");
        }
        // A count in a plain key would leave the form to English grammar.
        foreach ($en as $key => $text) {
            if (! in_array(preg_replace('/_(one|other)$/', '', $key), $bases, true)) {
                $this->assertNotContains('n', self::placeholders($text), "{$key}: a text with {n} is a count (use {$key}_one / {$key}_other)");
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
            'language list' => '/lang\/member\/(?!en\.json)[a-z_A-Z]+\.json/',
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
