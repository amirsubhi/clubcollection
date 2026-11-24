<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The owner's rule: Malaysian Malay only (Dewan Bahasa dan Pustaka usage), never Indonesian. Every Malay string
 * (lang/ms.json values, lang/member/ms.json values, lang/ms/*.php) is checked against clear-cut Indonesian words,
 * whole words, any case. The Malaysian word is in the comment; a new entry here needs one.
 */
class MalayWordingTest extends TestCase
{
    /** Indonesian => the Malaysian Malay word to use instead. */
    private const INDONESIAN = [
        'kata sandi' => 'kata laluan',
        'sandi' => 'kata laluan',
        'surel' => 'e-mel',
        'telepon' => 'telefon',
        'kantor' => 'pejabat',
        'silakan' => 'sila',
        'unduh' => 'muat turun',
        'mengunduh' => 'memuat turun',
        'unggah' => 'muat naik',
        'mengunggah' => 'memuat naik',
        'akun' => 'akaun',
        'nomor' => 'nombor',
        'rekening' => 'akaun bank',
        'tautan' => 'pautan',
        'beranda' => 'laman utama',
        'pengaturan' => 'tetapan',
        'sukses' => 'berjaya',
        'kedaluwarsa' => 'tamat tempoh',
        'kadaluarsa' => 'tamat tempoh',
    ];

    /** @return array<string, string> "file: key" => Malay text */
    private static function malayStrings(): array
    {
        $root = dirname(__DIR__, 2).'/lang';
        $out = [];
        foreach (['ms.json', 'member/ms.json'] as $file) {
            $data = json_decode((string) file_get_contents("{$root}/{$file}"), true, 512, JSON_THROW_ON_ERROR);
            foreach ($data as $key => $value) {
                if (is_array($value)) {
                    // lang/member/ms.json "_meta": its name and label are checked too.
                    foreach (array_filter($value, 'is_string') as $sub => $text) {
                        $out["{$file}: {$key}.{$sub}"] = $text;
                    }

                    continue;
                }
                $out["{$file}: {$key}"] = (string) $value;
            }
        }
        foreach (glob("{$root}/ms/*.php") ?: [] as $path) {
            $walk = function (array $items, string $prefix) use (&$walk, &$out): void {
                foreach ($items as $key => $value) {
                    is_array($value) ? $walk($value, "{$prefix}.{$key}") : $out["{$prefix}.{$key}"] = (string) $value;
                }
            };
            $walk(require $path, 'ms/'.basename($path, '.php'));
        }

        return $out;
    }

    public function test_no_malay_string_uses_an_indonesian_word(): void
    {
        $strings = self::malayStrings();
        // All three sources are read (a path change must not make this pass on nothing).
        $this->assertGreaterThan(100, count(array_filter(array_keys($strings), fn ($k) => str_starts_with($k, 'ms.json: '))));
        $this->assertGreaterThan(100, count(array_filter(array_keys($strings), fn ($k) => str_starts_with($k, 'member/ms.json: '))));
        $this->assertGreaterThan(10, count(array_filter(array_keys($strings), fn ($k) => str_starts_with($k, 'ms/'))));

        $found = [];
        foreach ($strings as $where => $text) {
            foreach (self::INDONESIAN as $word => $malay) {
                if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote($word, '/').'(?![\p{L}\p{N}])/iu', $text) === 1) {
                    $found[] = "{$where}: \"{$word}\" (Malaysian: {$malay})";
                }
            }
        }
        $this->assertSame([], $found, "Indonesian words in the Malay text:\n".implode("\n", $found));
    }

    public function test_the_check_catches_whole_words_in_any_case_only(): void
    {
        $match = fn (string $word, string $text) => preg_match('/(?<![\p{L}\p{N}])'.preg_quote($word, '/').'(?![\p{L}\p{N}])/iu', $text) === 1;
        $this->assertTrue($match('akun', 'Akun anda'));
        $this->assertTrue($match('kata sandi', 'Masukkan KATA SANDI.'));
        $this->assertFalse($match('akun', 'akaun anda'));
        $this->assertFalse($match('sandi', 'persandian')); // part of another word
    }
}
