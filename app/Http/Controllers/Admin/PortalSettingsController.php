<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AuditService;
use App\Services\LogoService;
use App\Support\Portal\AppIcons;
use App\Support\Portal\BrandPalette;
use App\Support\Portal\PortalSettings;
use App\Support\UiLang;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Portal settings (super admin only): the portal name (page titles, sign-in pages, emails, admin sidebar), the
 * portal logo (sign-in header when no single club's logo is shown, admin sidebar, app icons) and the brand colour
 * (one of BrandPalette's presets; only its key is stored), and the breached-password check for new passwords (on
 * unless switched off; only "off" is stored), and the default language of the member side (members with no saved
 * choice; Malay is stored as nothing). Left empty, the name is APP_NAME, the logo the
 * built-in mark and the colour lavender.
 */
class PortalSettingsController extends Controller
{
    public function __construct(private LogoService $logos) {}

    public function edit(): View
    {
        // A logo saved before app icons existed (or a failed attempt) gets its icons the next time this opens.
        AppIcons::ensure();

        return view('admin.portal-settings.edit', [
            'name' => PortalSettings::get('name'),
            'defaultName' => PortalSettings::defaultName(),
            'logoUrl' => PortalSettings::logoUrl(),
            'gdAvailable' => LogoService::available(),
            'brand' => BrandPalette::current(),
            'presets' => BrandPalette::keys(),
            'appIcons' => AppIcons::current(),
            'breachCheckOn' => PortalSettings::breachCheckOn(),
            'defaultLocale' => PortalSettings::defaultLocale(),
            // Every language with a member text file (UiLang::available()), by its own name.
            'languages' => collect(UiLang::available())->mapWithKeys(fn (string $code) => [$code => UiLang::name($code)])->all(),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'name' => 'nullable|string|max:100|not_regex:/[\\x00-\\x1F\\x7F]/u',
            'logo' => LogoService::rules(),
            'remove_logo' => 'boolean',
            // Only a preset key; never a colour value or CSS.
            'brand' => ['sometimes', 'string', Rule::in(BrandPalette::keys())],
            // Only changed when the form sends it (hidden 0 + checkbox 1), so another form never turns it off.
            'breach_check' => 'sometimes|boolean',
            // One of the available languages (a lang/member/<code>.json file); left alone when not sent.
            'default_locale' => ['sometimes', 'string', Rule::in(UiLang::available())],
        ], LogoService::messages() + [
            'brand.in' => 'Choose one of the listed colours.',
            'default_locale.in' => 'Choose one of the listed languages.',
            'name.not_regex' => 'The portal name can\'t contain tabs, line breaks or other hidden characters.',
        ], ['name' => 'portal name', 'brand' => 'brand colour', 'default_locale' => 'default language']);

        $old = ['name' => PortalSettings::get('name'), 'logo' => PortalSettings::get('logo'), 'brand' => PortalSettings::get('brand'), 'breach_check' => PortalSettings::get('breach_check'), 'default_locale' => PortalSettings::get('default_locale')];
        $name = trim((string) ($data['name'] ?? ''));
        $new = ['name' => $name === '' ? null : $name, 'logo' => $old['logo'], 'brand' => $old['brand'], 'breach_check' => $old['breach_check'], 'default_locale' => $old['default_locale']];
        // The default preset is stored as nothing, so it renders exactly as tokens.css.
        if (array_key_exists('brand', $data)) {
            $new['brand'] = $data['brand'] === BrandPalette::DEFAULT ? null : $data['brand'];
        }

        if (array_key_exists('breach_check', $data)) {
            $new['breach_check'] = $request->boolean('breach_check') ? null : PortalSettings::BREACH_CHECK_OFF;
        }

        // The built-in default (Malay) is stored as nothing, like the default colour.
        if (array_key_exists('default_locale', $data)) {
            $new['default_locale'] = $data['default_locale'] === UiLang::DEFAULT ? null : $data['default_locale'];
        }

        // A new file wins over "remove". Old files are kept (a sign-in page cached by a browser may still show it).
        if ($request->hasFile('logo')) {
            $new['logo'] = $this->logos->store($request->file('logo'), 'portal');
        } elseif ($request->boolean('remove_logo')) {
            $new['logo'] = null;
        }

        $changed = array_keys(array_filter($new, fn ($value, $key) => $old[$key] !== $value, ARRAY_FILTER_USE_BOTH));
        if ($changed === []) {
            return redirect()->route('admin.portal-settings.edit')->with('success', 'Nothing changed.');
        }

        PortalSettings::put($new);
        // New logo or colour: new app icons (new file names). Never blocks the save; without GD (or on failure)
        // the built-in icons stay in use.
        AppIcons::ensure();

        $what = implode(', ', array_map(fn ($key) => match ($key) {
            'name' => 'portal name',
            'logo' => 'logo',
            'brand' => 'brand colour',
            'breach_check' => 'breached-password check turned '.($new['breach_check'] === null ? 'on' : 'off'),
            'default_locale' => 'default language: '.UiLang::name($new['default_locale'] ?? UiLang::DEFAULT),
        }, $changed));

        AuditService::log(
            'portal.settings_updated',
            'Portal settings updated ('.$what.').',
            null,
            null,
            array_intersect_key($old, array_flip($changed)),
            array_intersect_key($new, array_flip($changed))
        );

        // Whole sentences (no pieces joined around a word), so each one can become one translation later.
        $saved = array_values(array_map(fn ($key) => ['name' => 'portal name', 'logo' => 'logo', 'brand' => 'brand colour', 'default_locale' => 'default language'][$key],
            array_diff($changed, ['breach_check'])));
        $flash = $saved === [] ? ['Saved.'] : ['Saved: '.implode(', ', $saved).'.'];
        if (in_array('breach_check', $changed, true)) {
            $flash[] = $new['breach_check'] === null ? 'Breached-password check turned on.' : 'Breached-password check turned off.';
        }

        return redirect()->route('admin.portal-settings.edit')->with('success', implode(' ', $flash));
    }
}
