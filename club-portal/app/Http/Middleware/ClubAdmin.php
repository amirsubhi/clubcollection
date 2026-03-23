<?php

namespace App\Http\Middleware;

use App\Models\Club;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ClubAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!auth()->check() || !auth()->user()->isAdmin()) {
            abort(403, 'Access denied.');
        }

        // For routes that include a {club} parameter, verify the user
        // is actually an admin OF that specific club (not just any club).
        $club = $request->route('club');
        if ($club instanceof Club && !auth()->user()->isSuperAdmin()) {
            $manages = $club->members()
                ->where('users.id', auth()->id())
                ->wherePivot('role', 'admin')
                ->exists();

            if (!$manages) {
                abort(403, 'You are not an administrator of this club.');
            }
        }

        return $next($request);
    }
}
