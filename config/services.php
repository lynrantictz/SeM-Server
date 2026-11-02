<?php

return [

    'paperstic' => [
        'inquiry_email' => env('PAPERSTIC_INQUIRY_EMAIL', 'paperstic@lynrantic.co.tz'),
        'operations_super_admin_email' => env('OPERATIONS_SUPER_ADMIN_EMAIL'),
        'operations_super_admin_name' => env('OPERATIONS_SUPER_ADMIN_NAME'),
        'operations_super_admin_password' => env('OPERATIONS_SUPER_ADMIN_PASSWORD'),
        'operations_super_admin_type' => env('OPERATIONS_SUPER_ADMIN_TYPE', 'paperstic'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
