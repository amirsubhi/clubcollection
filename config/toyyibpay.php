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
    // Set TOYYIBPAY_CHARGE_VERIFIED=true only after a sandbox run has confirmed
    // what billChargeToCustomer does (see ToyyibPayGateway::BILL_CHARGE_TO_CUSTOMER).
    // Until then ToyyibPay clubs cannot pass the gateway fee on to members.
    'charge_to_customer_verified' => (bool) env('TOYYIBPAY_CHARGE_VERIFIED', false),
];
