<?php

namespace App\Http\Controllers\Auth;

use App\Events\AccountLoginBlocked;
use App\Http\Controllers\Controller;
use App\Listeners\AuthSecurityListener;
use App\Models\AuditLog;
use App\Support\Auth\AccountLoginBlock;
use Illuminate\Foundation\Auth\AuthenticatesUsers;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class LoginController extends Controller
{
    use AuthenticatesUsers {
        login as protected attemptSignIn;
    }

    protected $redirectTo = '/home';

    public function __construct()
    {
        $this->middleware('guest')->except('logout');
        $this->middleware('auth')->only('logout');
    }

    /** Shown while sign-in for an email is paused. The same for every email, with or without an account. */
    public const PAUSED_MESSAGE = 'Signing in to this account is paused for :minutes minutes after too many wrong passwords. You can reset your password now, or wait and try again.';

    /**
     * POST /login. Limits: the route's "login" limiter (per IP, and per email + IP; AppServiceProvider),
     * then the per-account pause (AccountLoginBlock), checked before the password so even the right
     * password waits until the pause ends. A password reset is never blocked and ends the pause.
     */
    public function login(Request $request)
    {
        $this->validateLogin($request);

        $email = (string) $request->input($this->username());
        if (AccountLoginBlock::isBlocked($email)) {
            $this->throwPaused($email);
        }

        return $this->attemptSignIn($request);
    }

    /** A wrong password (or unknown email): count it for the account; the failure that starts a pause says so. */
    protected function sendFailedLoginResponse(Request $request)
    {
        $email = (string) $request->input($this->username());

        if (AccountLoginBlock::recordFailure($email)) {
            event(new AccountLoginBlocked(AuthSecurityListener::findUser($email), (int) config('security.account_login.block_minutes')));
            $this->throwPaused($email);
        }

        throw ValidationException::withMessages([$this->username() => [trans('auth.failed')]]);
    }

    private function throwPaused(string $email): never
    {
        $minutes = max(1, (int) ceil(AccountLoginBlock::secondsLeft($email) / 60));

        throw ValidationException::withMessages([
            $this->username() => [__(self::PAUSED_MESSAGE, ['minutes' => $minutes])],
        ]);
    }

    /**
     * Called immediately after a successful credential check.
     * Return a Response to override the default redirect; return null to use $redirectTo.
     */
    protected function authenticated(Request $request, $user)
    {
        AccountLoginBlock::clear((string) $request->input($this->username()));

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
            'user_agent'  => mb_strcut(mb_scrub((string) $request->userAgent()), 0, 255),
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
        // The next Inertia page tells the browser to drop its history key (encrypted 2FA pages); the
        // member and admin logout buttons also clear it in the browser before posting.
        Inertia::clearHistory();

        if ($user) {
            AuditLog::create([
                'user_id'     => $user->id,
                'user_name'   => $user->name,
                'user_role'   => $user->role,
                'action'      => 'auth.logout',
                'description' => "User '{$user->name}' logged out.",
                'ip_address'  => $request->ip(),
                'user_agent'  => mb_strcut(mb_scrub((string) $request->userAgent()), 0, 255),
            ]);
        }

        return $this->loggedOut($request) ?: redirect('/');
    }

    /**
     * Inertia pages (the member portal) log out with an XHR; tell the client
     * to do a full visit so the Blade login page is not shown in a modal.
     */
    protected function loggedOut(Request $request)
    {
        return $request->header('X-Inertia') ? Inertia::location(url('/')) : null;
    }
}
