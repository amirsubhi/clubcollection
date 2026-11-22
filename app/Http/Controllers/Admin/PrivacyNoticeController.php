<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AuditService;
use App\Support\Portal\PrivacyNotice;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Privacy notice (super admin only, settings phase 2): the BM and English text of the portal's privacy notice and
 * an optional data-protection contact, shown on the public /privacy page. Plain text only. Kept on its own page,
 * apart from Portal settings.
 */
class PrivacyNoticeController extends Controller
{
    public function edit(): View
    {
        return view('admin.portal-settings.privacy', [
            'settings' => PrivacyNotice::all(),
            'maxText' => PrivacyNotice::MAX_TEXT,
        ]);
    }

    public function update(Request $request)
    {
        // One kind of line break, so the length check counts a line break once.
        foreach (['privacy_notice_ms', 'privacy_notice_en'] as $field) {
            if (is_string($request->input($field))) {
                $request->merge([$field => str_replace(["\r\n", "\r"], "\n", $request->input($field))]);
            }
        }
        // Plain text: line breaks allowed in the notice; no other control character anywhere.
        $text = 'nullable|string|max:'.PrivacyNotice::MAX_TEXT.'|not_regex:/[\\x00-\\x09\\x0B-\\x1F\\x7F\\x{80}-\\x{9F}\\x{200B}-\\x{200F}\\x{202A}-\\x{202E}\\x{2066}-\\x{2069}]/u';
        $line = 'not_regex:/[\\x00-\\x1F\\x7F\\x{80}-\\x{9F}\\x{200B}-\\x{200F}\\x{202A}-\\x{202E}\\x{2066}-\\x{2069}]/u';
        $data = $request->validate([
            'privacy_notice_ms' => $text,
            'privacy_notice_en' => $text,
            'privacy_contact_name' => 'nullable|string|max:100|'.$line,
            // strict: no quoted local part, and nothing a mail client would read as extra headers in mailto:
            'privacy_contact_email' => ['nullable', 'string', 'email:rfc,strict', 'max:255', 'not_regex:/[?&"%]/', ...explode('|', $line)],
        ], [
            'privacy_notice_ms.not_regex' => 'The notice can only hold plain text and line breaks.',
            'privacy_notice_en.not_regex' => 'The notice can only hold plain text and line breaks.',
        ], [
            'privacy_notice_ms' => 'notice (Bahasa Melayu)',
            'privacy_notice_en' => 'notice (English)',
            'privacy_contact_name' => 'contact name',
            'privacy_contact_email' => 'contact email',
        ]);

        $new = [];
        foreach (PrivacyNotice::KEYS as $key) {
            $value = trim((string) ($data[$key] ?? ''));
            $new[$key] = $value === '' ? null : $value;
        }
        $old = PrivacyNotice::all();
        $changed = array_keys(array_filter($new, fn ($value, $key) => $old[$key] !== $value, ARRAY_FILTER_USE_BOTH));
        if ($changed === []) {
            return redirect()->route('admin.privacy-notice.edit')->with('success', 'Nothing changed.');
        }

        PrivacyNotice::put($new);

        AuditService::log(
            'portal.privacy_updated',
            'Privacy notice updated ('.implode(', ', $changed).').',
            null,
            null,
            array_intersect_key($old, array_flip($changed)),
            array_intersect_key($new, array_flip($changed))
        );

        return redirect()->route('admin.privacy-notice.edit')->with('success', 'Privacy notice saved.');
    }
}
