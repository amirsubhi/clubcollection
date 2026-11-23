<?php

namespace App\Support;

use Illuminate\Support\HtmlString;

/**
 * A translated sentence for an email with bold words kept inside it: __($key, $replace), escaped as a whole, then
 * **words** become <strong>words</strong>. So a sentence stays one translation (the translator moves the bold part
 * where their language puts it) and neither a translation nor a member's name can add HTML.
 */
final class MailText
{
    /** @param  array<string, string|int|float>  $replace */
    public static function bold(string $key, array $replace = []): HtmlString
    {
        $text = e(__($key, $replace));

        return new HtmlString((string) preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $text));
    }

    /**
     * The opening line of a member email ("Hi **:name**,"). In Malay it greets by the Malaysia time the email is
     * written: "Selamat pagi" 05:00-11:59, "Selamat petang" 12:00-18:59, "Selamat malam" 19:00-04:59. Other
     * languages keep $key. Malaysia time is fixed here (as the 08:00 reminder schedule), not APP_TIMEZONE, which is
     * UTC on an install that never set it.
     */
    public static function greeting(string $key, string $name): HtmlString
    {
        return self::bold(app()->getLocale() === 'ms' ? 'Good '.self::partOfDay().' **:name**,' : $key, ['name' => $name]);
    }

    /** Laravel's reset-link email opens with "Hello!"; in Malay the same greeting by the time of day, without a name. */
    public static function plainGreeting(): string
    {
        return app()->getLocale() === 'ms' ? __('Good '.self::partOfDay().'!') : __('Hello!');
    }

    /** @return 'morning'|'afternoon'|'evening' */
    public static function partOfDay(): string
    {
        $hour = (int) now('Asia/Kuala_Lumpur')->format('G');

        return match (true) {
            $hour >= 5 && $hour < 12 => 'morning',
            $hour >= 12 && $hour < 19 => 'afternoon',
            default => 'evening',
        };
    }
}
