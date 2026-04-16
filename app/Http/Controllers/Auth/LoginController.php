<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Foundation\Auth\AuthenticatesUsers;
use Illuminate\Http\Request;

class LoginController extends Controller
{
    use AuthenticatesUsers;

    protected $redirectTo = '/home';

    public function __construct()
    {
        $this->middleware('guest')->except('logout');
        $this->middleware('auth')->only('logout');
    }

    /**
     * Called immediately after a successful credential check.
     * Return a Response to override the default redirect; return null to use $redirectTo.
     */
    protected function authenticated(Request $request, $user)
    {
        // If 2FA is required, log the password-verified step (NOT a full login)
        // and pin the user id for the challenge controller. The actual
        // 'auth.login' audit row is written by TwoFactorChallengeController on
        // a successful TOTP / recovery verification.
        if ($user->hasEnabledTwoFactor() && ! session('two_factor_verified')) {
            $this->writeAuditRow($request, $user, 'auth.password_verified_pending_2fa',
                "Password OK for '{$user->name}' — awaiting 2FA verification.");

            auth()->logout();
            $request->session()->put('two_factor_user_id', $user->id);
            $request->session()->regenerate();
            return redirect()->route('two-factor.challenge');
        }

        // Full login (no 2FA enabled).
        $this->writeAuditRow($request, $user, 'auth.login', "User '{$user->name}' logged in.");

        return null; // use default $redirectTo
    }

    private function writeAuditRow(Request $request, $user, string $action, string $description): void
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

    /**
     * Override logout to capture the user before the session is cleared.
     */
    public function logout(Request $request)
    {
        $user = $this->guard()->user();

        $this->guard()->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($user) {
            AuditLog::create([
                'user_id'     => $user->id,
                'user_name'   => $user->name,
                'user_role'   => $user->role,
                'action'      => 'auth.logout',
                'description' => "User '{$user->name}' logged out.",
                'ip_address'  => $request->ip(),
                'user_agent'  => substr((string) $request->userAgent(), 0, 255),
            ]);
        }

        return $this->loggedOut($request) ?: redirect('/');
    }
}
