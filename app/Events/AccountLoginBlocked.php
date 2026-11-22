<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Too many wrong passwords for one email: sign-in for it is paused (App\Support\Auth\AccountLoginBlock).
 * Fired once per pause. $user is null when the email has no account.
 */
class AccountLoginBlocked
{
    use Dispatchable;

    public function __construct(
        public ?User $user,
        public int $minutes,
    ) {}
}
