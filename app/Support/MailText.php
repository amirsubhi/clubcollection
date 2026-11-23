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
}
