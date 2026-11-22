<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Strict-Transport-Security (HSTS)
    |--------------------------------------------------------------------------
    |
    | Sent by App\Http\Middleware\SecurityHeaders on HTTPS responses only.
    | include_subdomains is off by default: a club portal often lives on a
    | subdomain (e.g. portal.example.org) and must not force HTTPS onto the
    | rest of the organisation's domain. preload is never sent unless asked
    | for: preload lists are hard to leave, so it is a decision for the owner
    | of the whole domain. max_age 0 tells browsers to drop HSTS.
    |
    */

    'hsts' => [
        'max_age' => (int) env('HSTS_MAX_AGE', 31536000),
        'include_subdomains' => (bool) env('HSTS_INCLUDE_SUBDOMAINS', false),
        'preload' => (bool) env('HSTS_PRELOAD', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Sign-in protection per account
    |--------------------------------------------------------------------------
    |
    | After max_failures wrong passwords for one email within decay_minutes
    | (from any IP), sign-in for that email pauses for block_minutes. It is
    | never permanent and never blocks a password reset
    | (App\Support\Auth\AccountLoginBlock).
    |
    */

    'account_login' => [
        'max_failures' => (int) env('LOGIN_ACCOUNT_MAX_FAILURES', 10),
        'decay_minutes' => (int) env('LOGIN_ACCOUNT_DECAY_MINUTES', 15),
        'block_minutes' => (int) env('LOGIN_ACCOUNT_BLOCK_MINUTES', 15),
    ],

];
