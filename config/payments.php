<?php

return [
    'azampay' => [
        'base_url'   => env('AZAMPAY_BASE_URL', 'https://sandbox.azampay.co.tz'),
        'token_url'  => env('AZAMPAY_TOKEN_URL', 'https://sandbox.azampay.co.tz/auth/token'),
        'app_key'    => env('AZAMPAY_APP_KEY'),
        'app_secret' => env('AZAMPAY_APP_SECRET'),
    ]
];
