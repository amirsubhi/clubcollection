<?php

namespace App\Support\Portal;

use App\Models\PortalSetting;
use App\Services\LogoService;
use Illuminate\Support\Facades\Cache;

/**
 * Portal-wide settings the super admin sets on /admin/portal-settings (settings phase 1): the portal name and
 * logo. Stored as key / value rows (portal_settings) and cached forever under one key; the cache is cleared on
 * save. Every read is wrapped in rescue(): the installer, a fresh install before migrations, and error pages
 * with the database down all fall back to the defaults (APP_NAME, the built-in mark).
 */
final class PortalSettings
{
    public const CACHE_KEY = 'portal-settings.v1';

    public const KEYS = ['name', 'logo', 'brand'];

    /** config key holding APP_NAME as it was before a portal name was applied (AppServiceProvider). */
    public const DEFAULT_NAME_CONFIG = 'app.default_name';

    /** Container key of the settings read during this request (per app instance, so tests never share it). */
    private const MEMO = 'portal-settings.memo';

    /**
     * Every setting (missing ones null).
     *
     * @return array{name: ?string, logo: ?string, brand: ?string}
     */
    public static function all(): array
    {
        if (app()->bound(self::MEMO)) {
            return app(self::MEMO);
        }
        $empty = array_fill_keys(self::KEYS, null);
        // Before the install there is no table (and maybe no database settings yet): don't try.
        $read = ! file_exists(storage_path('app/.installed')) ? null : rescue(fn () => Cache::rememberForever(self::CACHE_KEY, fn () => PortalSetting::query()
            ->whereIn('key', self::KEYS)->pluck('value', 'key')->all()), null, false);
        // No database yet, or it is down: the defaults for the rest of this request (one try, not one per call);
        // nothing is cached, so the next request reads again.
        $all = is_array($read) ? array_merge($empty, array_intersect_key($read, $empty)) : $empty;
        app()->instance(self::MEMO, $all);

        return $all;
    }

    public static function get(string $key): ?string
    {
        $value = self::all()[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** The portal name: the saved one, else APP_NAME ("Club Portal" when APP_NAME is unset or "Laravel"). */
    public static function name(): string
    {
        return self::get('name') ?? self::defaultName();
    }

    public static function defaultName(): string
    {
        $name = (string) config(self::DEFAULT_NAME_CONFIG, config('app.name'));

        return in_array($name, ['', 'Laravel'], true) ? 'Club Portal' : $name;
    }

    /** Public URL of the portal logo, or null for the built-in mark. */
    public static function logoUrl(): ?string
    {
        return LogoService::url(self::get('logo'));
    }

    /**
     * Save settings (null clears one), clear the cache and apply the name to this process at once.
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
        self::flush();
        self::apply();
    }

    public static function flush(): void
    {
        app()->forgetInstance(self::MEMO);
        rescue(fn () => Cache::forget(self::CACHE_KEY), null, false);
    }

    /**
     * Use the portal name app-wide (page titles, sign-in pages, emails): config('app.name'). The APP_NAME it
     * replaces is kept in config('app.default_name'), so clearing the setting goes back to it.
     */
    public static function apply(): void
    {
        if (config(self::DEFAULT_NAME_CONFIG) === null) {
            config([self::DEFAULT_NAME_CONFIG => (string) config('app.name')]);
        }
        $default = (string) config(self::DEFAULT_NAME_CONFIG);
        $previous = (string) config('app.name');
        $name = self::name();
        config(['app.name' => $name]);
        // Emails are sent "from" the portal name too, unless MAIL_FROM_NAME was set to something else on purpose.
        if (in_array((string) config('mail.from.name'), [$default, $previous, '${APP_NAME}'], true)) {
            config(['mail.from.name' => $name]);
        }
    }
}
