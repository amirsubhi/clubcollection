<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * The language codes of the sign-in pages, the member app and member emails, the PHP side of
 * resources/js/lib/i18n.ts (normaliseLang / storedLang / langMeta there).
 *
 * The available languages are the files in lang/member/ (available()), the same files the member app bundles
 * (import.meta.glob in i18n.ts, with the same file-name rule and order; tests/Unit/UiLangTest.php checks both). Each
 * file describes itself in "_meta" (name, label, htmlLang, localeTag, beta), so a new language is a new file and no
 * code. Inside the app a language is its locale code ("ms", "en"). Malay used to be "bm", and that is still what the
 * cp_lang cookie, the member app's cp-lang storage and the ?lang= switch carry, so pages from before the change keep
 * working; every value read from outside goes through normalise(). Which language a person gets is decided in one
 * place: App\Support\UserLocale.
 */
final class UiLang
{
    /** The built-in default (a portal with no default language set on Portal settings). */
    public const DEFAULT = 'ms';

    /** The file-name rule for a language code, the same as CODE in resources/js/lib/i18n.ts. */
    public const CODE_PATTERN = '/^[a-z]{2,3}(_[A-Z]{2})?$/';

    /** @var list<string>|null */
    private static ?array $available = null;

    /** @var array<string, array<string, mixed>> */
    private static array $memberText = [];

    /** Another lang/member folder, for tests only (useDirectory()). */
    private static ?string $dir = null;

    /**
     * Every language with a lang/member/<code>.json file: the default first, then the rest by code.
     *
     * @return list<string>
     */
    public static function available(): array
    {
        if (self::$available !== null) {
            return self::$available;
        }
        $codes = array_map(fn (string $f) => basename($f, '.json'), glob(self::memberDir().'/*.json') ?: []);
        $codes = array_values(array_filter($codes, fn (string $c) => preg_match(self::CODE_PATTERN, $c) === 1));
        sort($codes);
        // Never empty: the default is always offered (its file is checked by MemberLangFilesTest).
        $codes = array_values(array_unique([self::DEFAULT, ...$codes]));

        return self::$available = $codes;
    }

    /** A value from a query string, cookie, storage or the database to a supported code ("bm" = "ms"), or null. */
    public static function normalise(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $code = $value === 'bm' ? 'ms' : $value;

        return in_array($code, self::available(), true) ? $code : null;
    }

    /** The value written to the cookie, storage and ?lang= links: still "bm" for Malay (what older pages read). */
    public static function stored(string $code): string
    {
        return $code === 'ms' ? 'bm' : $code;
    }

    /**
     * The file's own description ("_meta" in lang/member/<code>.json), as langMeta() in i18n.ts: never English's;
     * a file without it shows its code.
     *
     * @return array{name: string, label: string, htmlLang: string, localeTag: string, beta: bool}
     */
    public static function meta(string $code): array
    {
        $meta = self::memberText($code)['_meta'] ?? [];
        $meta = is_array($meta) ? $meta : [];
        $str = fn (string $key, string $default): string => is_string($meta[$key] ?? null) && $meta[$key] !== '' ? $meta[$key] : $default;

        return [
            'name' => $str('name', $code),
            'label' => $str('label', strtoupper($code)),
            'htmlLang' => $str('htmlLang', str_replace('_', '-', $code)),
            'localeTag' => $str('localeTag', str_replace('_', '-', $code)),
            'beta' => ($meta['beta'] ?? false) === true,
        ];
    }

    /** The language's own name ("Bahasa Melayu"), or its code when its file has none. */
    public static function name(string $code): string
    {
        return self::meta($code)['name'];
    }

    /** The short label on the closed language picker ("BM"). */
    public static function label(string $code): string
    {
        return self::meta($code)['label'];
    }

    /** What the picker lists for a language: its own name, with "(beta)" for a first translation. */
    public static function pickerName(string $code): string
    {
        $meta = self::meta($code);

        return $meta['beta'] ? $meta['name'].' (beta)' : $meta['name'];
    }

    /**
     * The <html lang> value for a locale ("ms", "zh-Hans"); a locale with no file here (an admin's "en_US") keeps
     * its own code, with "-" for "_".
     */
    public static function htmlLang(?string $locale): string
    {
        $code = self::normalise($locale);

        return $code !== null ? self::meta($code)['htmlLang'] : str_replace('_', '-', $locale ?: 'en');
    }

    /**
     * A date as the member app writes it ("5 Okt 2026"; fmtDate in resources/js/lib/format.ts): the language's
     * dateFormat ("{d} {m} {y}") with the month's short name (monthShort_N) from lang/member/<code>.json, one source
     * for the app and the emails. Carbon's own Malay names differ ("Ogs" where the app says "Ogo"), so they are not
     * used. $code defaults to the current locale (a mail sent to a member runs under the member's locale); a locale
     * with no file here gets English.
     */
    public static function date(CarbonInterface $date, ?string $code = null): string
    {
        $code = self::normalise($code ?? app()->getLocale()) ?? 'en';
        $month = self::text($code, 'monthShort_'.$date->month) ?? $date->format('M');
        $format = self::text($code, 'dateFormat') ?? '{d} {m} {y}';

        return strtr($format, ['{d}' => (string) $date->day, '{m}' => $month, '{y}' => (string) $date->year]);
    }

    /** A member app text in a language, else English's, else null (short keys: never through __()). */
    public static function text(string $code, string $key): ?string
    {
        if ($key === '_meta') {
            return null;
        }
        foreach ([$code, 'en'] as $c) {
            $text = self::memberText($c)[$key] ?? null;
            if (is_string($text)) {
                return $text;
            }
        }

        return null;
    }

    /** Tests only: read the languages from another folder (null = lang/member again). */
    public static function useDirectory(?string $dir): void
    {
        self::$dir = $dir;
        self::$available = null;
        self::$memberText = [];
    }

    /** lang/member, found from this file (works before the app is booted, e.g. in plain unit tests). */
    private static function memberDir(): string
    {
        return self::$dir ?? dirname(__DIR__, 2).'/lang/member';
    }

    /** @return array<string, mixed> */
    private static function memberText(string $code): array
    {
        if (! isset(self::$memberText[$code])) {
            $path = self::memberDir().'/'.$code.'.json';
            $data = preg_match(self::CODE_PATTERN, $code) === 1 && is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
            self::$memberText[$code] = is_array($data) ? $data : [];
        }

        return self::$memberText[$code];
    }
}
