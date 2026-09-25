<?php

return [
    'azampay' => [
        'environment' => env('AZAMPAY_ENV', 'sandbox'),
        'base_url' => env('AZAMPAY_SANDBOX_BASEURL') ?: 'https://sandbox.azampay.co.tz',
        'token_base_url' => env('AZAMPAY_TOKEN_BASEURL') ?: 'https://authenticator-sandbox.azampay.co.tz',
        'token_path' => env('AZAMPAY_TOKEN_PATH', '/AppRegistration/GenerateToken'),
        'mno_checkout_path' => env('AZAMPAY_MNO_CHECKOUT_PATH', '/azampay/mno/checkout'),
        'public_key_path' => env('AZAMPAY_PUBLIC_KEY_PATH', '/azampay/v1/public-key'),
        'client_id' => env('AZAMPAY_SANDBOX_CLIENTID'),
        'client_secret' => env('AZAMPAY_SANDBOX_CLIENTSECRET'),
        'app_name' => env('AZAMPAY_APP_NAME', 'Paperstic'),
        // Keep external gateway calls comfortably below PHP's request deadline.
        // A timeout must be handled by PaymentCheckoutService, not terminate the API response.
        'timeout_seconds' => (int) env('AZAMPAY_TIMEOUT_SECONDS', 10),
        'checkout_timeout_seconds' => (int) env('AZAMPAY_CHECKOUT_TIMEOUT_SECONDS', 18),
        'require_callback_signature' => filter_var(env('AZAMPAY_REQUIRE_CALLBACK_SIGNATURE', true), FILTER_VALIDATE_BOOL),
    ],
];
