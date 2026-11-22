<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AuditService;
use App\Services\LogoService;
use App\Support\Portal\PortalSettings;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Portal settings (super admin only): the portal name (page titles, sign-in pages, emails, admin sidebar) and
 * the portal logo (sign-in header when no single club's logo is shown, admin sidebar). Left empty, the name is
 * APP_NAME and the logo the built-in mark.
 */
class PortalSettingsController extends Controller
{
    public function __construct(private LogoService $logos) {}

    public function edit(): View
    {
        return view('admin.portal-settings.edit', [
            'name' => PortalSettings::get('name'),
            'defaultName' => PortalSettings::defaultName(),
            'logoUrl' => PortalSettings::logoUrl(),
            'gdAvailable' => LogoService::available(),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'name' => 'nullable|string|max:100|not_regex:/[\\x00-\\x1F\\x7F]/u',
            'logo' => LogoService::rules(),
            'remove_logo' => 'boolean',
        ], LogoService::messages(), ['name' => 'portal name']);

        $old = ['name' => PortalSettings::get('name'), 'logo' => PortalSettings::get('logo')];
        $name = trim((string) ($data['name'] ?? ''));
        $new = ['name' => $name === '' ? null : $name, 'logo' => $old['logo']];

        // A new file wins over "remove". Old files are kept (a sign-in page cached by a browser may still show it).
        if ($request->hasFile('logo')) {
            $new['logo'] = $this->logos->store($request->file('logo'), 'portal');
        } elseif ($request->boolean('remove_logo')) {
            $new['logo'] = null;
        }

        $changed = array_keys(array_filter(['name' => $old['name'] !== $new['name'], 'logo' => $old['logo'] !== $new['logo']]));
        if ($changed === []) {
            return redirect()->route('admin.portal-settings.edit')->with('success', 'Nothing changed.');
        }

        PortalSettings::put($new);

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
