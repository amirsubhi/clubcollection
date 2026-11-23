<?php

namespace App\Support;

/**
 * The language codes of the sign-in pages and the member app (the BM/EN choice), the PHP side of
 * resources/js/lib/i18n.ts (normaliseLang / storedLang there).
 *
 * Inside the app a language is its locale code ("ms", "en"). Malay used to be "bm", and that is still what the
 * cp_lang cookie, the member app's cp-lang storage and the ?lang= switch carry, so pages from before the change keep
 * working; every value read from outside goes through normalise().
 */
final class UiLang
{
    /** Supported codes; the first is the default. Laravel's lang/<code>.json and lang/<code>/*.php go with each. */
    public const CODES = ['ms', 'en'];

    public const DEFAULT = 'ms';

    /** What the BM/EN switch shows. */
    public const LABELS = ['ms' => 'BM', 'en' => 'EN'];

    /** The language's own name (screen readers). */
    public const NAMES = ['ms' => 'Bahasa Melayu', 'en' => 'English'];

    /** A value from a query string, cookie or storage to a supported code ("bm" = "ms"), or null when it isn't one. */
    public static function normalise(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $code = $value === 'bm' ? 'ms' : $value;

        return in_array($code, self::CODES, true) ? $code : null;
    }

    /** The value written to the cookie, storage and ?lang= links: still "bm" for Malay (what older pages read). */
    public static function stored(string $code): string
    {
        return $code === 'ms' ? 'bm' : $code;
    }
}
