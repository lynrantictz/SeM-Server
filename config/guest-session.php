<?php

return [
    'cookie' => env('GUEST_SESSION_COOKIE', 'paperstic_guest_session'),
    'domain' => env('GUEST_SESSION_COOKIE_DOMAIN'),
    'secure' => filter_var(env('GUEST_SESSION_COOKIE_SECURE', true), FILTER_VALIDATE_BOOL),
    'same_site' => env('GUEST_SESSION_COOKIE_SAME_SITE', 'lax'),
    'lifetime_days' => (int) env('GUEST_SESSION_LIFETIME_DAYS', 7),
];
