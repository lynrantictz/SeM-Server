<?php

return [
    'issuer' => [
        'name' => env('PAPERSTIC_BILLING_LEGAL_NAME', 'Paperstic'),
        'address' => env('PAPERSTIC_BILLING_ADDRESS'),
        'email' => env('PAPERSTIC_BILLING_EMAIL', env('MAIL_FROM_ADDRESS')),
        'tax_id' => env('PAPERSTIC_BILLING_TAX_ID'),
    ],
    'logo' => public_path('paperstic-invoice-logo.png'),
];
