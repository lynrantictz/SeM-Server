<?php

return [
    'enabled' => (bool) env('WHATSAPP_ENABLED', false),
    'driver' => env('WHATSAPP_DRIVER', 'log'),
    'queue' => env('WHATSAPP_QUEUE', 'whatsapp'),
    'graph_version' => env('META_GRAPH_VERSION'),
    'access_token' => env('META_WHATSAPP_ACCESS_TOKEN'),
    'phone_number_id' => env('META_WHATSAPP_PHONE_NUMBER_ID'),
    'business_account_id' => env('META_WHATSAPP_BUSINESS_ACCOUNT_ID'),
    'app_secret' => env('META_APP_SECRET'),
    'verify_token' => env('META_WHATSAPP_VERIFY_TOKEN'),
    'verify_webhook_signature' => (bool) env('META_WHATSAPP_VERIFY_WEBHOOK_SIGNATURE', true),
    'order_verification_template' => env('META_WHATSAPP_ORDER_VERIFICATION_TEMPLATE', 'paperstic_order_verification'),
    'template_language' => env('META_WHATSAPP_TEMPLATE_LANGUAGE', 'en_US'),
    'timeout_seconds' => (int) env('META_WHATSAPP_TIMEOUT_SECONDS', 15),
];
