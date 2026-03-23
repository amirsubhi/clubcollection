<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

class ProfileController extends Controller
{
    private Google2FA $google2fa;

    public function __construct()
    {
        $this->google2fa = new Google2FA();
    }

    public function security()
    {
        $user         = auth()->user();
        $recoveryCodes = session()->pull('two_factor_recovery_codes_plaintext');

        return view('admin.profile.security', compact('user', 'recoveryCodes'));
    }

    public function enableTwoFactor(Request $request)
    {
        $user   = auth()->user();
        $secret = $this->google2fa->generateSecretKey();

        // Store the secret temporarily (unconfirmed) in the DB
        $user->forceFill([
            'two_factor_secret'          => encrypt($secret),
            'two_factor_recovery_codes'  => null,
            'two_factor_confirmed_at'    => null,
        ])->save();

        // Generate QR code as inline SVG
        $qrCodeUrl = $this->google2fa->getQRCodeUrl(
            config('app.name'),
            $user->email,
            $secret
        );

        $qrSvg = $this->generateQrSvg($qrCodeUrl);

        return view('admin.profile.two-factor-setup', compact('user', 'qrSvg', 'secret'));
    }

    public function confirmTwoFactor(Request $request)
    {
        $request->validate(['code' => 'required|string|digits:6']);

        $user   = auth()->user();
        $secret = $user->getTwoFactorSecret();

        if (! $secret) {
            return back()->with('error', '2FA setup not started. Please click Enable first.');
        }

        $valid = $this->google2fa->verifyKey($secret, $request->code);

        if (! $valid) {
            return back()->withErrors(['code' => 'Invalid code. Please try again.']);
        }

        // Generate recovery codes and store them encrypted
        $recoveryCodes = $this->generateRecoveryCodes();

        $user->forceFill([
            'two_factor_recovery_codes' => encrypt(json_encode($recoveryCodes)),
            'two_factor_confirmed_at'   => now(),
        ])->save();

        // Flash plaintext codes once so the view can display them
        session(['two_factor_recovery_codes_plaintext' => $recoveryCodes]);

        AuditService::log('2fa.enabled', '2FA (TOTP) enabled by user.');

        return redirect()->route('profile.security')
            ->with('success', '2FA enabled. Save your recovery codes — they will not be shown again.');
    }

    public function disableTwoFactor(Request $request)
    {
        $request->validate(['password' => 'required|current_password']);

        $user = auth()->user();
        $user->forceFill([
            'two_factor_secret'         => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at'   => null,
        ])->save();

        session()->forget('two_factor_verified');

        AuditService::log('2fa.disabled', '2FA disabled by user.');

        return redirect()->route('profile.security')
            ->with('success', '2FA has been disabled.');
    }

    private function generateRecoveryCodes(): array
    {
        return array_map(
            fn () => strtoupper(Str::random(4)) . '-' . strtoupper(Str::random(4)),
            range(1, 8)
        );
    }

    private function generateQrSvg(string $url): string
    {
        $renderer = new \BaconQrCode\Renderer\ImageRenderer(
            new \BaconQrCode\Renderer\RendererStyle\RendererStyle(200),
            new \BaconQrCode\Renderer\Image\SvgImageBackEnd()
        );
        return (new \BaconQrCode\Writer($renderer))->writeString($url);
    }
}
