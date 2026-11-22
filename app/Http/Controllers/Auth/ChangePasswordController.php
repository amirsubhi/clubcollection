<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\AccountSecurityNotice;
use App\Services\AuditService;
use App\Support\AfterResponse;
use App\Support\Auth\NewPassword;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * /password/change: choose a new password while signed in. Required first for an account created with an
 * emailed temporary password (must_change_password, EnsurePasswordChanged); open to everyone otherwise.
 */
class ChangePasswordController extends Controller
{
    public function show(Request $request)
    {
        $response = response()->view('auth.passwords.change', [
            'forced' => (bool) $request->user()->must_change_password,
        ]);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    public function update(Request $request)
    {
        $request->validate([
            'current_password' => ['required', 'string', 'current_password'],
            'password' => ['required', 'string', 'confirmed', Password::defaults(), 'different:current_password'],
        ], NewPassword::messages() + [
            'current_password.required' => __('Please type your current password.'),
            'current_password.current_password' => __('That is not your current password. Please try again.'),
        ]);

        $user = $request->user();
        $forced = (bool) $user->must_change_password;

        // On the signed-in instance, so auth.session (AuthenticateSession) stores the new hash for this
        // session after the response: this browser stays signed in, every other session is signed out.
        $user->forceFill([
            'password' => Hash::make($request->input('password')),
            'must_change_password' => false,
        ])->setRememberToken(Str::random(60));
        $user->save();

        AuditService::log('auth.password_changed', $forced
            ? "User '{$user->name}' replaced their temporary password."
            : "User '{$user->name}' changed their password.", $user);

        // A first-time change of the emailed temporary password is expected; any other change is worth a heads-up.
        if (! $forced) {
            AfterResponse::run(
                fn () => Mail::to($user->email)->send(new AccountSecurityNotice($user, AccountSecurityNotice::KIND_PASSWORD_CHANGED)),
                'password changed email',
            );
        }

        return redirect('/home');
    }
}
