<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Club;

/**
 * Provides club-scoped authorization for shallow resource routes.
 *
 * Shallow routes (show/edit/update/destroy) lose the {club} URL segment,
 * so the ClubAdmin middleware can't verify club ownership there.
 * Call $this->authorizeClubAdmin($club) in each such action.
 */
trait AuthorizesClubResource
{
    protected function authorizeClubAdmin(Club $club): void
    {
        $user = auth()->user();

        if ($user->isSuperAdmin()) {
            return;
        }

        // Only an active admin of an active club (same rule as FundUsageService::isClubAdmin).
        $manages = $club->is_active && $club->members()
            ->where('users.id', $user->id)
            ->wherePivot('role', 'admin')
            ->wherePivot('is_active', true)
            ->exists();

        if (!$manages) {
            abort(403, 'You are not an administrator of this club.');
        }
    }
}
