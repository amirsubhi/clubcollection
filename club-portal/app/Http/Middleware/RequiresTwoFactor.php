<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequiresTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = auth()->user();

        if ($user && $user->hasEnabledTwoFactor() && ! session('two_factor_verified')) {
            // Log out and redirect to challenge
            $userId = $user->id;
            auth()->logout();
            $request->session()->put('two_factor_user_id', $userId);

            return redirect()->route('two-factor.challenge');
        }

        return $next($request);
    }
}
