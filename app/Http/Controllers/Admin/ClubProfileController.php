<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Club;
use App\Services\AuditService;
use App\Services\LogoService;
use App\Support\Payments\ReceiptNumbers;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Club profile (settings phase 1): the club's name, logo, contact email, registration number, address and the
 * month its financial year starts. Members see these on invoices and receipts (an issued receipt keeps the
 * details it was issued with: payments.issuer_snapshot). Club admins of this club (club_admin middleware) and
 * the super admin.
 */
class ClubProfileController extends Controller
{
    /** Fields compared for the audit log (the logo as its stored path, never the file). */
    private const AUDITED = ['name', 'email', 'registration_no', 'address', 'financial_year_start_month', 'receipt_footer', 'receipt_prefix', 'logo'];

    /** Receipt footer: plain text, at most this many characters (line breaks count as one). */
    public const FOOTER_MAX = 300;

    public const MONTHS = [
        1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April', 5 => 'May', 6 => 'June',
        7 => 'July', 8 => 'August', 9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
    ];

    public function __construct(private LogoService $logos) {}

    public function edit(Club $club): View
    {
        return view('admin.clubs.profile', [
            'club' => $club,
            'months' => self::MONTHS,
            'gdAvailable' => LogoService::available(),
            'footerMax' => self::FOOTER_MAX,
            // What the next receipt would be numbered (nothing is reserved).
            'nextReceiptNo' => ReceiptNumbers::preview($club),
        ]);
    }

    public function update(Request $request, Club $club)
    {
        // Windows / old Mac line endings become \n before the length check, so a line break counts once.
        if (is_string($request->input('receipt_footer'))) {
            $request->merge(['receipt_footer' => str_replace(["\r\n", "\r"], "\n", $request->input('receipt_footer'))]);
        }
        // The receipt prefix is stored in capitals: "kbs" is taken as "KBS".
        if (is_string($request->input('receipt_prefix'))) {
            $request->merge(['receipt_prefix' => strtoupper(trim($request->input('receipt_prefix')))]);
        }
        $data = $request->validate([
            'name' => 'required|string|max:255|not_regex:/[\\x00-\\x1F\\x7F]/u',
            'email' => 'nullable|email|max:255',
            'registration_no' => 'nullable|string|max:60|not_regex:/[\\x00-\\x1F\\x7F]/u',
            'address' => 'nullable|string|max:500',
            'financial_year_start_month' => 'required|integer|between:1,12',
            // Plain text: line breaks are allowed, every other control character (tab included) is refused.
            'receipt_footer' => 'nullable|string|max:'.self::FOOTER_MAX.'|not_regex:/[\\x00-\\x09\\x0B-\\x1F\\x7F\\x{80}-\\x{9F}\\x{200B}-\\x{200F}\\x{202A}-\\x{202E}\\x{2066}-\\x{2069}]/u',
            // 2-6 of A-Z / 0-9 (anchored with \A…\z). Left out of the form: unchanged; emptied: back to RCP.
            'receipt_prefix' => ['nullable', 'string', 'regex:'.ReceiptNumbers::PREFIX_PATTERN],
            'logo' => LogoService::rules(),
            'remove_logo' => 'boolean',
        ], LogoService::messages() + [
            'receipt_prefix.regex' => 'The receipt prefix must be 2 to 6 capital letters or digits (A-Z, 0-9), for example RCP.',
            'financial_year_start_month.between' => 'Choose a month from January to December.',
            'receipt_footer.not_regex' => 'The receipt footer can only hold plain text and line breaks.',
        ], [
            'registration_no' => 'registration no.',
            'financial_year_start_month' => 'financial year start',
            'receipt_footer' => 'receipt footer',
            'receipt_prefix' => 'receipt prefix',
        ]);

        $new = [
            'name' => trim($data['name']),
            'email' => self::clean($data['email'] ?? null),
            'registration_no' => self::clean($data['registration_no'] ?? null),
            'address' => self::cleanAddress($data['address'] ?? null),
            'financial_year_start_month' => (int) $data['financial_year_start_month'],
            'receipt_footer' => self::cleanAddress($data['receipt_footer'] ?? null),
        ];
        // Only receipts issued from now on use a new prefix; the year's sequence carries on.
        if ($request->has('receipt_prefix')) {
            $new['receipt_prefix'] = $data['receipt_prefix'] ?? ReceiptNumbers::DEFAULT_PREFIX;
        }

        // A new file wins over "remove". The old file is kept on disk: receipts issued with it still show it.
        if ($request->hasFile('logo')) {
            $new['logo'] = $this->logos->store($request->file('logo'), 'club-logos');
        } elseif ($request->boolean('remove_logo')) {
            $new['logo'] = null;
        }

        $old = $club->only(self::AUDITED);
        // Explicit attributes only (no mass assignment from the request).
        $club->forceFill($new)->save();
        $after = $club->only(self::AUDITED);

        $changedOld = array_diff_assoc(array_map(fn ($v) => (string) $v, $old), array_map(fn ($v) => (string) $v, $after));
        $changed = array_keys($changedOld);
        if ($changed !== []) {
            AuditService::log(
                'club.profile_updated',
                "Club '{$club->name}' profile updated (".implode(', ', $changed).').',
                $club,
                $club->id,
                array_intersect_key($old, array_flip($changed)),
                array_intersect_key($after, array_flip($changed))
            );
        }

        return redirect()->route('admin.clubs.profile.edit', $club)->with('success', $changed === [] ? 'Nothing changed.' : 'Club profile saved.');
    }

    private static function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /** Trim each line and drop empty ones at the ends; Windows line endings become \n. (Address and receipt footer.) */
    private static function cleanAddress(?string $value): ?string
    {
        $value = trim(str_replace(["\r\n", "\r"], "\n", (string) $value));
        if ($value === '') {
            return null;
        }

        return implode("\n", array_map('trim', explode("\n", $value)));
    }
}
