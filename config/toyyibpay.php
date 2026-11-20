<?php

return [
    'secret_key'    => env('TOYYIBPAY_SECRET_KEY', ''),
    'category_code' => env('TOYYIBPAY_CATEGORY_CODE', ''),
    'sandbox'       => env('TOYYIBPAY_SANDBOX', true),
    'base_url'        => env('TOYYIBPAY_SANDBOX', true)
        ? 'https://dev.toyyibpay.com'
        : 'https://toyyibpay.com',
    // Random secret (at least 32 characters) used to sign each bill's callback
    // URL: the token is an HMAC of the bill reference, so a leaked token only
    // works for that one bill. Set TOYYIBPAY_WEBHOOK_SECRET in .env.
    'webhook_secret'  => env('TOYYIBPAY_WEBHOOK_SECRET', ''),
    // Before settling a "paid" callback, ask ToyyibPay (getBillTransactions)
    // for a successful transaction of at least the fee amount. When the API
    // can't be reached the fee is not settled and the callback gets a non-200
    // answer, so ToyyibPay retries it later.
    'verify_callback' => (bool) env('TOYYIBPAY_VERIFY_CALLBACK', true),
    // Set TOYYIBPAY_CHARGE_VERIFIED=true only after a sandbox run has confirmed
    // what billChargeToCustomer does (see ToyyibPayGateway::BILL_CHARGE_TO_CUSTOMER).
    // Until then ToyyibPay clubs cannot pass the gateway fee on to members.
    'charge_to_customer_verified' => (bool) env('TOYYIBPAY_CHARGE_VERIFIED', false),
    // Sandbox test only (docs/SANDBOX-TEST.md): write each verified callback's
    // field names and its non-secret values (allow-listed) to the audit log,
    // so the tester can read what ToyyibPay really sends. Never active when
    // TOYYIBPAY_SANDBOX=false, whatever this is set to.
    'log_callbacks' => (bool) env('TOYYIBPAY_LOG_CALLBACKS', env('TOYYIBPAY_SANDBOX', true)),
];
