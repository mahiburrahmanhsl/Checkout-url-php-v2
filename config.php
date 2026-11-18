<?php
declare(strict_types=1);

/*
 * Copy this file to config.local.php and put real credentials there.
 * Keep config.local.php outside public web access where possible.
 */
return [
    'environment' => 'sandbox', // sandbox | production
    'base_url' => 'https://tokenized.sandbox.bka.sh/v2/tokenized-checkout',

    'username' => 'YOUR_BKASH_USERNAME',
    'password' => 'YOUR_BKASH_PASSWORD',
    'app_key' => 'YOUR_APP_KEY',
    'app_secret' => 'YOUR_APP_SECRET',

    'callback_url' => 'http://localhost/InfinityHub/callback.php',

    'db' => [
        'host' => '127.0.0.1',
        'name' => 'bkash',
        'user' => 'root',
        'password' => '',
        'charset' => 'utf8mb4',
    ],

    'currency' => 'BDT',
    'token_safety_seconds' => 120,
    'api_timeout_seconds' => 30,
    // Generate a random secret of at least 32 characters for refund.php access.
    'refund_admin_key' => 'REPLACE_WITH_A_LONG_RANDOM_SECRET_32_CHARS_MIN',
];
