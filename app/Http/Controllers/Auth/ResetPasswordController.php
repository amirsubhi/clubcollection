<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\Auth\NewPassword;
use Illuminate\Foundation\Auth\ResetsPasswords;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

class ResetPasswordController extends Controller
{
    // Reset form and POST /password/reset (laravel/ui). The new password follows Password::defaults()
    // (AppServiceProvider): 12+ characters, not breached.
    use ResetsPasswords;

    /**
     * Where to redirect users after resetting their password.
     *
     * @var string
     */
    protected $redirectTo = '/home';

    protected function validationErrorMessages()
    {
        return NewPassword::messages();
    }

    /**
     * Unknown email, wrong or expired link: always the same "link is not valid" answer, so the reset
     * form can't be used to find out which emails have an account. (The stock trait says "we can't find
     * a user with that email" for an unknown email.) Errors on the password fields still show as usual:
     * they come from validation, before the broker runs.
     */
    protected function sendResetFailedResponse(Request $request, $response)
    {
        $message = trans(Password::INVALID_TOKEN);

        if ($request->wantsJson()) {
            throw ValidationException::withMessages(['email' => [$message]]);
        }

        return redirect()->back()
            ->withInput($request->only('email'))
            ->withErrors(['email' => $message]);
    }
}
