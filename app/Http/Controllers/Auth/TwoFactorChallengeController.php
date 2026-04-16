<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
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

        $usedRecoveryCode = false;

        // Try TOTP code first
        if ($request->filled('code')) {
            $google2fa = new Google2FA();
            $secret    = $user->getTwoFactorSecret();
            $valid     = $secret && $google2fa->verifyKey($secret, $request->code);

            if (! $valid) {
                $this->writeAudit($request, $user, '2fa.failed', "Invalid TOTP code for '{$user->name}'.");
                return back()->withErrors(['code' => 'Invalid authentication code.']);
            }
        } elseif ($request->filled('recovery_code')) {
            $codes = $user->getTwoFactorRecoveryCodes();
            $index = array_search($request->recovery_code, $codes, true);

            if ($index === false) {
                $this->writeAudit($request, $user, '2fa.failed', "Invalid recovery code for '{$user->name}'.");
                return back()->withErrors(['recovery_code' => 'Invalid recovery code.']);
            }

            // Invalidate used recovery code
            unset($codes[$index]);
            $user->forceFill([
                'two_factor_recovery_codes' => encrypt(json_encode(array_values($codes))),
            ])->save();
            $usedRecoveryCode = true;
        } else {
            return back()->withErrors(['code' => 'Please enter an authentication code or recovery code.']);
        }

        // Log the user in and clear the pending 2FA session
        auth()->loginUsingId($userId);
        session()->forget('two_factor_user_id');
        session(['two_factor_verified' => true]);
        $request->session()->regenerate();

        if ($usedRecoveryCode) {
            $this->writeAudit($request, $user, '2fa.recovery_code_used',
                "Recovery code consumed for '{$user->name}' (".count($user->getTwoFactorRecoveryCodes())." remaining).");
        }
        $this->writeAudit($request, $user, 'auth.login', "User '{$user->name}' logged in (2FA verified).");

        return redirect()->intended('/home');
    }

    private function writeAudit(Request $request, User $user, string $action, string $description): void
    {
        AuditLog::create([
            'user_id'     => $user->id,
            'user_name'   => $user->name,
            'user_role'   => $user->role,
            'action'      => $action,
            'description' => $description,
            'ip_address'  => $request->ip(),
            'user_agent'  => substr((string) $request->userAgent(), 0, 255),
        ]);
    }
}
