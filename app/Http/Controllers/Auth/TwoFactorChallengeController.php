<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use PragmaRX\Google2FA\Google2FA;

class TwoFactorChallengeController extends Controller
{
    public function show(Request $request)
    {
        // If already fully authenticated (no pending 2FA), redirect home
        if (auth()->check() && ! session('two_factor_user_id')) {
            return redirect()->intended('/home');
        }

        if (! session('two_factor_user_id')) {
            return redirect()->route('login');
        }

        return view('auth.two-factor-challenge');
    }

    public function verify(Request $request)
    {
        $request->validate([
            'code' => 'nullable|string',
            'recovery_code' => 'nullable|string',
        ]);

        $userId = session('two_factor_user_id');

        if (! $userId) {
            return redirect()->route('login');
        }

        $user = User::find($userId);

        if (! $user) {
            return redirect()->route('login');
        }

        // Try TOTP code first
        if ($request->filled('code')) {
            $google2fa = new Google2FA();
            $secret    = $user->getTwoFactorSecret();
            $valid     = $secret && $google2fa->verifyKey($secret, $request->code);

            if (! $valid) {
                return back()->withErrors(['code' => 'Invalid authentication code.']);
            }
        } elseif ($request->filled('recovery_code')) {
            $codes = $user->getTwoFactorRecoveryCodes();
            $index = array_search($request->recovery_code, $codes, true);

            if ($index === false) {
                return back()->withErrors(['recovery_code' => 'Invalid recovery code.']);
            }

            // Invalidate used recovery code
            unset($codes[$index]);
            $user->forceFill([
                'two_factor_recovery_codes' => encrypt(json_encode(array_values($codes))),
            ])->save();
        } else {
            return back()->withErrors(['code' => 'Please enter an authentication code or recovery code.']);
        }

        // Log the user in and clear the pending 2FA session
        auth()->loginUsingId($userId);
        session()->forget('two_factor_user_id');
        session(['two_factor_verified' => true]);
        $request->session()->regenerate();

        return redirect()->intended('/home');
    }
}
