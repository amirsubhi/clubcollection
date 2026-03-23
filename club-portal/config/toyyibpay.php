<?php

return [
    'secret_key'    => env('TOYYIBPAY_SECRET_KEY', ''),
    'category_code' => env('TOYYIBPAY_CATEGORY_CODE', ''),
    'sandbox'       => env('TOYYIBPAY_SANDBOX', true),
    'base_url'        => env('TOYYIBPAY_SANDBOX', true)
        ? 'https://dev.toyyibpay.com'
        : 'https://toyyibpay.com',
    // Random secret appended to callback URL — set TOYYIBPAY_WEBHOOK_SECRET in .env
    'webhook_secret'  => env('TOYYIBPAY_WEBHOOK_SECRET', ''),
];
