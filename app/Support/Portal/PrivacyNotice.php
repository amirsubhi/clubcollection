<?php

namespace App\Support\Portal;

use App\Models\PortalSetting;

/**
 * The portal's privacy notice (settings phase 2): a Bahasa Melayu and an English text and an optional
 * data-protection contact (name + email), set by the super admin on /admin/portal-settings/privacy and shown on
 * the public /privacy page. Stored as portal_settings rows under their own keys (kept apart from
 * PortalSettings::KEYS). Plain text only: shown escaped, paragraphs split on blank lines. No legal wording ships
 * as a default: with no text set, /privacy says the notice has not been published yet.
 *
 * Read without the cache (one small query on a rarely opened page) and inside rescue(), so the page still
 * renders with the database down or before the install.
 */
final class PrivacyNotice
{
    public const KEYS = ['privacy_notice_ms', 'privacy_notice_en', 'privacy_contact_name', 'privacy_contact_email'];

    /** Longest notice, in characters, per language (portal_settings.value is TEXT: 65,535 bytes). */
    public const MAX_TEXT = 10000;

    /**
     * Every privacy setting (missing ones null).
     *
     * @return array{privacy_notice_ms: ?string, privacy_notice_en: ?string, privacy_contact_name: ?string, privacy_contact_email: ?string}
     */
    public static function all(): array
    {
        $empty = array_fill_keys(self::KEYS, null);
        $read = ! file_exists(storage_path('app/.installed')) ? null : rescue(fn () => PortalSetting::query()
            ->whereIn('key', self::KEYS)->pluck('value', 'key')->all(), null, false);
        if (! is_array($read)) {
            return $empty;
        }
        foreach ($read as $key => $value) {
            $read[$key] = is_string($value) && $value !== '' ? $value : null;
        }

        return array_merge($empty, array_intersect_key($read, $empty));
    }

    /**
     * Save the settings (null or '' clears one).
     *
     * @param  array<string, ?string>  $values
     */
    public static function put(array $values): void
    {
        foreach (array_intersect_key($values, array_flip(self::KEYS)) as $key => $value) {
            if ($value === null || $value === '') {
                PortalSetting::query()->where('key', $key)->delete();
            } else {
                PortalSetting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
            }
        }
    }

    /**
     * What /privacy shows for a language ('bm' or 'en'): the notice in that language, else the other one (with
     * its language, so the page can say so), else nothing.
     *
     * @return array{paragraphs: list<string>, lang: ?string, contactName: ?string, contactEmail: ?string}
     */
    public static function forLanguage(string $lang): array
    {
        $all = self::all();
        $mine = $lang === 'en' ? 'en' : 'bm';
        $texts = ['bm' => $all['privacy_notice_ms'], 'en' => $all['privacy_notice_en']];
        $shown = $texts[$mine] !== null ? $mine : ($texts[$mine === 'en' ? 'bm' : 'en'] !== null ? ($mine === 'en' ? 'bm' : 'en') : null);

        return [
            'paragraphs' => $shown === null ? [] : self::paragraphs($texts[$shown]),
            'lang' => $shown,
            'contactName' => $all['privacy_contact_name'],
            'contactEmail' => $all['privacy_contact_email'],
        ];
    }

    /**
     * Plain text to paragraphs: split on blank lines; single line breaks stay inside a paragraph (the view
     * escapes each paragraph and turns its line breaks into <br>).
     *
     * @return list<string>
     */
    public static function paragraphs(?string $text): array
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", (string) $text));
        if ($text === '') {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', preg_split('/\n\s*\n/u', $text) ?: []),
            fn (string $p) => $p !== ''
        ));
    }
}
