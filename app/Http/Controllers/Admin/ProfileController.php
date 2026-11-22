<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Http\Controllers\Controller;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Inertia;
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

        if (! $user->isAdmin()) {
            return $this->memberSecurity($user, $recoveryCodes);
        }

        // Shown when RequireAdminTwoFactor sent them here: 2FA must be on before the admin pages open.
        $twoFactorRequired = \App\Http\Middleware\RequireAdminTwoFactor::applies($user);

        return $this->noStore(response()->view('admin.profile.security', compact('user', 'recoveryCodes', 'twoFactorRequired')));
    }

    /** Secrets and recovery codes must not be kept by the browser or a proxy cache. */
    private function noStore($response)
    {
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    /** member.* error codes (translated in the member UI) for members; Laravel's English for admins. */
    private function memberMessages(array $messages): array
    {
        return auth()->user()->isAdmin() ? [] : $messages;
    }

    /**
     * Members get the page inside the member layout (BM first). Setup (QR + key) is shown while a setup
     * this session started with the password is still waiting for its first code.
     */
    private function memberSecurity($user, ?array $recoveryCodes)
    {
        $setup = null;
        $secret = $user->getTwoFactorSecret();
        if (session('two_factor_setup_pending') && $secret && ! $user->hasEnabledTwoFactor()) {
            $setup = [
                'qrSvg'  => $this->generateQrSvg($this->google2fa->getQRCodeUrl(config('app.name'), $user->email, $secret)),
                'secret' => $secret,
            ];
        }

        $club = $user->clubs()->wherePivot('is_active', true)->orderBy('name')->first();

        // Inertia keeps page props in history.state: encrypt it (the key lives in sessionStorage and is
        // dropped on logout and 2FA disable through clearHistory).
        Inertia::encryptHistory();

        $response = $this->noStore(Inertia::render('Member/Security', [
            'clubName'      => $club?->name ?? config('app.name'),
            'enabled'       => $user->hasEnabledTwoFactor(),
            'setup'         => $setup,
            'recoveryCodes' => $recoveryCodes ? array_values($recoveryCodes) : null,
            'urls'          => [
                'home'    => route('member.dashboard'),
                'enable'  => route('profile.2fa.enable'),
                'confirm' => route('profile.2fa.confirm'),
                'disable' => route('profile.2fa.disable'),
            ],
        ])->toResponse(request()));
        // Only this page: back to the config default for anything rendered later in the same process.
        Inertia::encryptHistory(null);

        return $response;
    }

    /** Flash for the page: a "member.*" code (translated in the member UI) for members, English for admins. */
    private function flashText(string $memberCode, string $adminText): string
    {
        return auth()->user()->isAdmin() ? $adminText : 'member.'.$memberCode;
    }

    public function enableTwoFactor(Request $request)
    {
        // A borrowed, unlocked session must not replace (or set up) the second factor (S1).
        $request->validate(['current_password' => 'required|current_password'], $this->memberMessages([
            'current_password.required' => 'member.2fa_password_invalid',
            'current_password.current_password' => 'member.2fa_password_invalid',
        ]), ['current_password' => 'password']);

        $user   = auth()->user();
        if ($user->hasEnabledTwoFactor()) {
            return redirect()->route('profile.security')
                ->with('error', $this->flashText('2fa_already_on', '2FA is already on. Disable it first (with your password) to set it up again.'));
        }
        $secret = $this->google2fa->generateSecretKey();

        // Store the secret temporarily (unconfirmed) in the DB
        $user->forceFill([
            'two_factor_secret'          => encrypt($secret),
            'two_factor_recovery_codes'  => null,
            'two_factor_confirmed_at'    => null,
            'two_factor_last_used_step'  => null,
        ])->save();

        if (! $user->isAdmin()) {
            // The member page shows the QR + key from this flag (kept until confirm or disable).
            session(['two_factor_setup_pending' => true]);

            return redirect()->route('profile.security');
        }

        return $this->adminSetupPage($user, $secret);
    }

    /**
     * The admin setup page (QR + key + confirm form). It is the answer to a POST, so a mistake on the confirm
     * form shows this page again with the same secret and the error: back() would land on the POST-only
     * enable URL (404/405) in the middle of setup.
     */
    private function adminSetupPage($user, string $secret, ?\Illuminate\Support\MessageBag $errors = null)
    {
        $qrSvg = $this->generateQrSvg($this->google2fa->getQRCodeUrl(config('app.name'), $user->email, $secret));
        $data = compact('user', 'qrSvg', 'secret');
        if ($errors) {
            $data['errors'] = (new \Illuminate\Support\ViewErrorBag)->put('default', $errors);
        }

        return $this->noStore(response()->view('admin.profile.two-factor-setup', $data, $errors ? 422 : 200));
    }

    public function confirmTwoFactor(Request $request)
    {
        $user = auth()->user();
        $pendingSecret = $user->hasEnabledTwoFactor() ? null : $user->getTwoFactorSecret();
        if ($user->isAdmin() && $pendingSecret) {
            // The key is shown again only to someone who just typed the right password: a borrowed session
            // must not read a pending secret by posting junk. A wrong or missing password starts over.
            $password = $request->input('current_password');
            if (! is_string($password) || ! \Illuminate\Support\Facades\Hash::check($password, $user->password)) {
                return redirect()->route('profile.security')
                    ->withErrors(['current_password' => 'That password is not right. Click Enable 2FA to start again.']);
            }
            $validator = \Illuminate\Support\Facades\Validator::make($request->all(), ['code' => 'required|string|digits:6']);
            if ($validator->fails()) {
                return $this->adminSetupPage($user, $pendingSecret, $validator->errors());
            }
        }

        $request->validate([
            'code' => 'required|string|digits:6',
            'current_password' => 'required|current_password',
        ], $this->memberMessages([
            'code.required' => 'member.2fa_code_invalid',
            'code.string' => 'member.2fa_code_invalid',
            'code.digits' => 'member.2fa_code_invalid',
            'current_password.required' => 'member.2fa_password_invalid',
            'current_password.current_password' => 'member.2fa_password_invalid',
        ]), ['current_password' => 'password']);

        $secret = $user->getTwoFactorSecret();

        if ($user->hasEnabledTwoFactor()) {
            return redirect()->route('profile.security')->with('error', $this->flashText('2fa_already_on', '2FA is already on.'));
        }
        if (! $secret) {
            return redirect()->route('profile.security')->with('error', $this->flashText('2fa_not_started', '2FA setup not started. Please click Enable first.'));
        }

        $valid = $user->verifyTwoFactorCode($request->code, $secret);

        if (! $valid && $user->isAdmin()) {
            return $this->adminSetupPage($user, $secret, new \Illuminate\Support\MessageBag(['code' => 'Invalid code. Please try again.']));
        }
        if (! $valid) {
            return back()->withErrors(['code' => $this->flashText('2fa_code_invalid', 'Invalid code. Please try again.')]);
        }

        // Generate recovery codes and store them encrypted
        $recoveryCodes = $this->generateRecoveryCodes();

        $user->forceFill([
            'two_factor_recovery_codes' => encrypt(json_encode($recoveryCodes)),
            'two_factor_confirmed_at'   => now(),
        ])->save();

        // Flash plaintext codes once so the view can display them
        session(['two_factor_recovery_codes_plaintext' => $recoveryCodes]);
        session()->forget('two_factor_setup_pending');
        // This session just passed the password and a fresh code, the same as the sign-in challenge: without this,
        // the redirect below signs the user out (RequiresTwoFactor) before the one-time recovery codes are shown.
        session(['two_factor_verified' => true]);
        // Drop earlier history entries that may hold the setup key.
        Inertia::clearHistory();

        AuditService::log('2fa.enabled', '2FA (TOTP) enabled by user.');

        return redirect()->route('profile.security')
            ->with('success', $this->flashText('2fa_enabled', '2FA enabled. Save your recovery codes — they will not be shown again.'));
    }

    public function disableTwoFactor(Request $request)
    {
        // The password alone is not enough (a borrowed, unlocked session, or a password someone saw): the
        // second factor itself must be shown too, as a current code or one of the recovery codes.
        $request->validate([
            'password' => 'required|current_password',
            'code' => 'required|string|max:64',
        ], $this->memberMessages([
            'password.required' => 'member.2fa_password_invalid',
            'password.current_password' => 'member.2fa_password_invalid',
            'code.required' => 'member.2fa_disable_code_invalid',
            'code.string' => 'member.2fa_disable_code_invalid',
            'code.max' => 'member.2fa_disable_code_invalid',
        ]));

        $user = auth()->user();
        // Same lock as the sign-in challenge: 5 wrong codes lock the account's 2FA checks for 15 minutes.
        $lockKey = TwoFactorChallengeController::lockKey($user);
        if ($user->hasEnabledTwoFactor() && RateLimiter::tooManyAttempts($lockKey, TwoFactorChallengeController::MAX_FAILURES)) {
            $minutes = (int) ceil(RateLimiter::availableIn($lockKey) / 60);

            return back()->withErrors(['code' => $this->flashText('2fa_disable_locked', "Too many wrong codes. Try again in {$minutes} minutes.")]);
        }
        if ($user->hasEnabledTwoFactor() && ! $user->verifyTwoFactorCodeOrRecoveryCode((string) $request->input('code'))) {
            RateLimiter::hit($lockKey, TwoFactorChallengeController::LOCK_SECONDS);
            AuditService::log('2fa.disable_failed', '2FA not disabled: wrong authenticator or recovery code.');

            return back()->withErrors(['code' => $this->flashText('2fa_disable_code_invalid', 'That code is not right. Enter the 6-digit code from your authenticator app, or one of your recovery codes.')]);
        }
        $user->forceFill([
            'two_factor_secret'         => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at'   => null,
            'two_factor_last_used_step' => null,
        ])->save();

        session()->forget(['two_factor_verified', 'two_factor_setup_pending']);
        // Drop the browser's history key, so earlier encrypted pages (setup key, codes) can't be read back.
        Inertia::clearHistory();

        AuditService::log('2fa.disabled', '2FA disabled by user.');

        return redirect()->route('profile.security')
            ->with('success', $this->flashText('2fa_disabled', '2FA has been disabled.'));
    }

    /** Letters and digits that cannot be misread when copied by hand (no 0/O, 1/I/L). */
    public const RECOVERY_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    private function generateRecoveryCodes(): array
    {
        $part = function (): string {
            $max = strlen(self::RECOVERY_ALPHABET) - 1;
            $s = '';
            for ($i = 0; $i < 4; $i++) {
                $s .= self::RECOVERY_ALPHABET[random_int(0, $max)];
            }

            return $s;
        };

        return array_map(fn () => $part().'-'.$part(), range(1, 8));
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
