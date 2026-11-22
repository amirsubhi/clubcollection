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

];
