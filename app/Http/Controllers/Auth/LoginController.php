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
        AuditLog::create([
            'user_id'     => $user->id,
            'user_name'   => $user->name,
            'user_role'   => $user->role,
            'action'      => 'auth.login',
            'description' => "User '{$user->name}' logged in.",
            'ip_address'  => $request->ip(),
            'user_agent'  => substr((string) $request->userAgent(), 0, 255),
        ]);

        if ($user->hasEnabledTwoFactor() && ! session('two_factor_verified')) {
            auth()->logout();
            $request->session()->put('two_factor_user_id', $user->id);
            $request->session()->regenerate();
            return redirect()->route('two-factor.challenge');
        }

        return null; // use default $redirectTo
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
