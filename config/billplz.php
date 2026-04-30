<?php

return [
    'api_key'         => env('BILLPLZ_API_KEY', ''),
    'collection_id'   => env('BILLPLZ_COLLECTION_ID', ''),
    'x_signature_key' => env('BILLPLZ_X_SIGNATURE_KEY', ''),
    'sandbox'         => env('BILLPLZ_SANDBOX', true),
    'base_url'        => env('BILLPLZ_SANDBOX', true)
        ? 'https://www.billplz-sandbox.com'
        : 'https://www.billplz.com',
];
