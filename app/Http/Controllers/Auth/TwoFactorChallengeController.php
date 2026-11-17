<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

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

        // Per-user lockout (S1): the route throttle is per IP; this stops a
        // spread-out guess at one account's codes.
        $lockKey = self::lockKey($user);
        if (RateLimiter::tooManyAttempts($lockKey, self::MAX_FAILURES)) {
            $minutes = (int) ceil(RateLimiter::availableIn($lockKey) / 60);

            return back()->withErrors(['code' => __('Too many wrong codes. Try again in :minutes minute(s).', ['minutes' => $minutes])]);
        }

        $usedRecoveryCode = false;

        // Try TOTP code first
        if ($request->filled('code')) {
            // verifyKeyNewer: a code (or an older one) that was already used is refused.
            if (! $user->verifyTwoFactorCode((string) $request->code)) {
                $this->fail($request, $user, "Invalid TOTP code for '{$user->name}'.");
                return back()->withErrors(['code' => __('Invalid authentication code.')]);
            }
        } elseif ($request->filled('recovery_code')) {
            $codes = $user->getTwoFactorRecoveryCodes();
            // Compare without case, spaces or dashes ("abcd efgh" matches "ABCD-EFGH"); the stored code is removed below.
            $norm = fn ($c) => strtoupper(preg_replace('/[\s-]+/', '', (string) $c));
            $typed = $norm($request->recovery_code);
            $index = false;
            foreach ($codes as $i => $code) {
                if ($typed !== '' && hash_equals($norm($code), $typed)) {
                    $index = $i;
                    break;
                }
            }

            if ($index === false) {
                $this->fail($request, $user, "Invalid recovery code for '{$user->name}'.");
                return back()->withErrors(['recovery_code' => __('Invalid recovery code.')]);
            }

            // Invalidate used recovery code
            unset($codes[$index]);
            $user->forceFill([
                'two_factor_recovery_codes' => encrypt(json_encode(array_values($codes))),
            ])->save();
            $usedRecoveryCode = true;
        } else {
            return back()->withErrors(['code' => __('Please enter an authentication code or recovery code.')]);
        }

        RateLimiter::clear($lockKey);

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

    /** Failures allowed before the account's 2FA challenge locks for LOCK_SECONDS. */
    public const MAX_FAILURES = 5;

    public const LOCK_SECONDS = 900;

    public static function lockKey(User $user): string
    {
        return '2fa-lock:'.$user->id;
    }

    private function fail(Request $request, User $user, string $description): void
    {
        RateLimiter::hit(self::lockKey($user), self::LOCK_SECONDS);
        $this->writeAudit($request, $user, '2fa.failed', $description);
        if (RateLimiter::tooManyAttempts(self::lockKey($user), self::MAX_FAILURES)) {
            $this->writeAudit($request, $user, '2fa.locked', "2FA challenge locked for '{$user->name}' after ".self::MAX_FAILURES.' wrong codes.');
        }
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
