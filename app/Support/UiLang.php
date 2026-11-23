<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * The language codes of the sign-in pages, the member app and member emails, the PHP side of
 * resources/js/lib/i18n.ts (normaliseLang / storedLang there).
 *
 * The available languages are the files in lang/member/ (available()), the same files the member app bundles
 * (FILES in i18n.ts; tests/Unit/UiLangTest.php checks both lists match). Inside the app a language is its locale
 * code ("ms", "en"). Malay used to be "bm", and that is still what the cp_lang cookie, the member app's cp-lang
 * storage and the ?lang= switch carry, so pages from before the change keep working; every value read from outside
 * goes through normalise(). Which language a person gets is decided in one place: App\Support\UserLocale.
 */
final class UiLang
{
    /** The built-in default (a portal with no default language set on Portal settings). */
    public const DEFAULT = 'ms';

    /** What the BM/EN switch shows. A new language adds its entry here (UiLangTest asks for one). */
    public const LABELS = ['ms' => 'BM', 'en' => 'EN'];

    /** The language's own name (screen readers, the Portal settings list). */
    public const NAMES = ['ms' => 'Bahasa Melayu', 'en' => 'English'];

    /** @var list<string>|null */
    private static ?array $available = null;

    /** @var array<string, array<string, string>> */
    private static array $memberText = [];

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
        $codes = array_values(array_filter($codes, fn (string $c) => preg_match('/^[a-z]{2,3}(_[A-Z]{2})?$/', $c) === 1));
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

    /** The language's own name ("Bahasa Melayu"), or its code when NAMES has none yet. */
    public static function name(string $code): string
    {
        return self::NAMES[$code] ?? $code;
    }

    /**
     * A date as the member app writes it ("5 Okt 2026"; fmtDate in resources/js/lib/format.ts), with the month's
     * short name from lang/member/<code>.json (monthShort_N): one source for the app and the emails. Carbon's own
     * Malay names differ ("Ogs" where the app says "Ogo"), so they are not used. $code defaults to the current
     * locale (a mail sent to a member runs under the member's locale).
     */
    public static function date(CarbonInterface $date, ?string $code = null): string
    {
        $code = self::normalise($code ?? app()->getLocale()) ?? 'en';
        $month = self::memberText($code)['monthShort_'.$date->month]
            ?? self::memberText('en')['monthShort_'.$date->month]
            ?? $date->format('M');

        return $date->day.' '.$month.' '.$date->year;
    }

    /** lang/member, found from this file (works before the app is booted, e.g. in plain unit tests). */
    private static function memberDir(): string
    {
        return dirname(__DIR__, 2).'/lang/member';
    }

    /** @return array<string, string> */
    private static function memberText(string $code): array
    {
        if (! isset(self::$memberText[$code])) {
            $path = self::memberDir().'/'.$code.'.json';
            $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
            self::$memberText[$code] = is_array($data) ? $data : [];
        }

        return self::$memberText[$code];
    }
}
