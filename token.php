<?php
declare(strict_types=1);

/*
 * Backward-compatible token entry point.
 * Include this file and call getToken() to obtain the shared cached bKash id_token.
 */
require_once __DIR__ . '/bkash.php';

function getToken(): string
{
    return getBkashToken();
}
