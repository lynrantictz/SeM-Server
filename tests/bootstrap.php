<?php

// Run before Laravel boots. Never load development config or route caches.
require __DIR__.'/../vendor/autoload.php';

foreach ([
    'APP_ENV' => 'testing',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => ':memory:',
    'APP_CONFIG_CACHE' => sys_get_temp_dir().'/paperstic-tests-'.getmypid().'-config.php',
    'APP_ROUTES_CACHE' => sys_get_temp_dir().'/paperstic-tests-'.getmypid().'-routes.php',
] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
