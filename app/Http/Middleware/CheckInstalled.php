<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckInstalled
{
    public function handle(Request $request, Closure $next)
    {
        if (! file_exists(storage_path('app/.installed'))) {
            return redirect()->route('install');
        }

        return $next($request);
    }
}
