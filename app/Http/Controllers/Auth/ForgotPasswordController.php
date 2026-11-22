<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\AfterResponse;
use Illuminate\Foundation\Auth\SendsPasswordResetEmails;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ForgotPasswordController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Password Reset Controller
    |--------------------------------------------------------------------------
    |
    | This controller is responsible for handling password reset emails and
    | includes a trait which assists in sending these notifications from
    | your application to your users. Feel free to explore this trait.
    |
    */

    use SendsPasswordResetEmails;

    /** Same answer whether or not the email has an account (S1: no account discovery). */
    public const GENERIC_STATUS = 'If that email address has an account, we have emailed a password reset link to it.';

    /**
     * POST /password/email. The look-up, token and email (SMTP, slow) all run after the answer has been sent
     * (AfterResponse, no queue worker needed), so a known email is not answered more slowly than an unknown
     * one. The answer is always the same generic line.
     */
    public function sendResetLinkEmail(Request $request)
    {
        $this->validateEmail($request);

        $credentials = $this->credentials($request);
        AfterResponse::run(fn () => $this->broker()->sendResetLink($credentials), 'password reset link');

        return $this->genericResponse($request);
    }

    protected function sendResetLinkResponse(Request $request, $response)
    {
        return $this->genericResponse($request);
    }

    /** Unknown email, or a link already sent a moment ago: the same answer as success. */
    protected function sendResetLinkFailedResponse(Request $request, $response)
    {
        return $this->genericResponse($request);
    }

    private function genericResponse(Request $request)
    {
        return $request->wantsJson()
            ? new JsonResponse(['message' => self::GENERIC_STATUS], 200)
            : back()->with('status', self::GENERIC_STATUS);
    }
}
