<?php

declare(strict_types=1);

namespace App\Support\Updates;

use App\Support\UiLang;

/**
 * The members' pause page (spec J6): one static HTML file with inline CSS, no external files, that reloads the home
 * page every 30 seconds (never re-sending a form). Rendered from lang/{ms,en}/updater.php in the portal's default
 * language; any other language shows the English text until its wording is approved.
 */
final class PausePage
{
    /** The languages that have a lang/<code>/updater.php. */
    private const LANGUAGES = ['ms', 'en'];

    public static function render(string $locale, string $portalName): string
    {
        $code = UiLang::normalise($locale);
        $code = in_array($code, self::LANGUAGES, true) ? $code : 'en';
        $text = fn (string $key, array $replace = []): string => e((string) trans('updater.'.$key, $replace, $code));
        $portal = trim($portalName) === '' ? 'Club Portal' : $portalName;

        $title = $text('pause_title', ['portal' => $portal]);

        return '<!DOCTYPE html>'."\n"
            .'<html lang="'.e(UiLang::htmlLang($code)).'"><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width, initial-scale=1">'
            .'<meta http-equiv="refresh" content="30;url=/">'
            .'<title>'.$title.'</title>'
            .'<style>body{margin:0;font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;background:#f4f6f8;color:#1f2933;line-height:1.5}'
            .'main{max-width:34rem;margin:12vh auto;padding:2rem 1.5rem;background:#fff;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.08)}'
            .'h1{font-size:1.4rem;margin:0 0 .75rem}p{margin:0 0 .75rem}</style></head><body><main>'
            .'<h1>'.$title.'</h1>'
            .'<p>'.$text('pause_back').'</p>'
            .'<p>'.$text('pause_paid').'</p>'
            .'<p>'.$text('pause_form').'</p>'
            .'</main></body></html>'."\n";
    }
}
