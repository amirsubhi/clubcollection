<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AuditService;
use App\Services\LogoService;
use App\Support\Portal\AppIcons;
use App\Support\Portal\BrandPalette;
use App\Support\Portal\PortalSettings;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Portal settings (super admin only): the portal name (page titles, sign-in pages, emails, admin sidebar), the
 * portal logo (sign-in header when no single club's logo is shown, admin sidebar, app icons) and the brand colour
 * (one of BrandPalette's presets; only its key is stored). Left empty, the name is APP_NAME, the logo the
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
        ], LogoService::messages() + ['brand.in' => 'Choose one of the listed colours.'], ['name' => 'portal name', 'brand' => 'brand colour']);

        $old = ['name' => PortalSettings::get('name'), 'logo' => PortalSettings::get('logo'), 'brand' => PortalSettings::get('brand')];
        $name = trim((string) ($data['name'] ?? ''));
        $new = ['name' => $name === '' ? null : $name, 'logo' => $old['logo'], 'brand' => $old['brand']];
        // The default preset is stored as nothing, so it renders exactly as tokens.css.
        if (array_key_exists('brand', $data)) {
            $new['brand'] = $data['brand'] === BrandPalette::DEFAULT ? null : $data['brand'];
        }

        // A new file wins over "remove". Old files are kept (a sign-in page cached by a browser may still show it).
        if ($request->hasFile('logo')) {
            $new['logo'] = $this->logos->store($request->file('logo'), 'portal');
        } elseif ($request->boolean('remove_logo')) {
            $new['logo'] = null;
        }

        $changed = array_keys(array_filter(['name' => $old['name'] !== $new['name'], 'logo' => $old['logo'] !== $new['logo'], 'brand' => $old['brand'] !== $new['brand']]));
        if ($changed === []) {
            return redirect()->route('admin.portal-settings.edit')->with('success', 'Nothing changed.');
        }

        PortalSettings::put($new);
        // New logo or colour: new app icons (new file names). Never blocks the save; without GD (or on failure)
        // the built-in icons stay in use.
        AppIcons::ensure();

        AuditService::log(
            'portal.settings_updated',
            'Portal settings updated ('.implode(', ', $changed).').',
            null,
            null,
            array_intersect_key($old, array_flip($changed)),
            array_intersect_key($new, array_flip($changed))
        );

        return redirect()->route('admin.portal-settings.edit')->with('success', 'Portal settings saved.');
    }
}
