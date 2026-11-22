<?php

namespace App\Support\Auth;

/**
 * Plain-language messages for a new password (reset and change-password forms). The rule itself is
 * Password::defaults() (AppServiceProvider): 12+ characters, not in a known data breach.
 */
final class NewPassword
{
    public const HINT = 'Use at least 12 characters. A few ordinary words together is easiest to remember.';

    /** Custom validation messages for the `password` field, in the page language. */
    public static function messages(): array
    {
        return [
            'password.required' => __('Please type a new password.'),
            'password.min' => __('Your new password needs at least 12 characters. A few ordinary words together is easiest to remember.'),
            // The Password rule reports a breached password as the rule "password.uncompromised".
            'password.password.uncompromised' => __('This password has appeared in a data leak on another website, so it is not safe. Please choose a different one.'),
            'password.confirmed' => __('The two new passwords are not the same. Please type them again.'),
            'password.different' => __('Your new password must be different from your current one.'),
        ];
    }
}
