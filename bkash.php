<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/** Send one request to the supplied bKash Tokenized Checkout v2 API. */
function bkashRequest(
    string $method,
    string $path,
    ?array $body = null,
    ?string $idToken = null,
    bool $authCall = false
): ?array {
    $config = appConfig();
    $url = rtrim($config['base_url'], '/') . '/' . ltrim($path, '/');
    $headers = ['Accept: application/json', 'Content-Type: application/json'];

    if ($authCall) {
        $headers[] = 'username: ' . $config['username'];
        $headers[] = 'password: ' . $config['password'];
    } else {
        if ($idToken === null || $idToken === '') {
            throw new RuntimeException('Missing bKash id_token.');
        }
        $headers[] = 'Authorization: Bearer ' . $idToken;
    }
    $headers[] = 'x-app-key: ' . $config['app_key'];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => (int)($config['api_timeout_seconds'] ?? 30),
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    if ($body !== null) {
        $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('Could not encode bKash request JSON.');
        }
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
    }

    $raw = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false) {
        logError('bKash cURL transport failure', ['path' => $path, 'error' => $curlError]);
        return null;
    }
    $decoded = json_decode((string)$raw, true);
    if (!is_array($decoded)) {
        logError('Invalid JSON from bKash', ['path' => $path, 'http_code' => $httpCode]);
        return ['_http_code' => $httpCode, '_raw' => (string)$raw, '_invalid_json' => true];
    }
    $decoded['_http_code'] = $httpCode;
    return $decoded;
}

/** Return the shared merchant token, reusing it until it approaches expiry. */
function getBkashToken(): string
{
    $pdo = db();
    $config = appConfig();
    $lock = $pdo->query("SELECT GET_LOCK('infinityhub_bkash_token_lock', 15)")->fetchColumn();
    if ((int)$lock !== 1) {
        throw new RuntimeException('Could not acquire bKash token lock. Please retry.');
    }

    try {
        $row = $pdo->query('SELECT * FROM bkash_tokens WHERE id = 1')->fetch();
        if (!$row) {
            $pdo->exec('INSERT INTO bkash_tokens (id) VALUES (1)');
            $row = $pdo->query('SELECT * FROM bkash_tokens WHERE id = 1')->fetch();
        }
        $now = time();
        $safety = (int)($config['token_safety_seconds'] ?? 120);
        if (!empty($row['id_token']) && (int)$row['expires_at'] > $now + $safety) {
            return (string)$row['id_token'];
        }

        if (!empty($row['refresh_token'])) {
            $refresh = bkashRequest('POST', '/auth/refresh-token', [
                'app_key' => $config['app_key'],
                'app_secret' => $config['app_secret'],
                'refresh_token' => $row['refresh_token'],
            ], null, true);
            if (is_array($refresh) && !empty($refresh['id_token'])
                && (string)($refresh['statusCode'] ?? '0000') === '0000') {
                $expiresIn = max(60, (int)($refresh['expires_in'] ?? 3600));
                $newRefresh = $refresh['refresh_token'] ?? $row['refresh_token'];
                $stmt = $pdo->prepare('UPDATE bkash_tokens SET id_token=?, refresh_token=?, expires_at=? WHERE id=1');
                $stmt->execute([$refresh['id_token'], $newRefresh, $now + $expiresIn]);
                return (string)$refresh['id_token'];
            }
            logError('bKash refresh token failed', [
                'http_code' => $refresh['_http_code'] ?? null,
                'statusCode' => $refresh['statusCode'] ?? null,
                'statusMessage' => $refresh['statusMessage'] ?? null,
            ]);
        }

        // Enforce the merchant's maximum of two Grant Token attempts per hour.
        $windowStart = (int)$row['grant_window_started_at'];
        $grantCount = (int)$row['grant_count'];
        if ($windowStart === 0 || ($now - $windowStart) >= 3600) {
            $windowStart = $now;
            $grantCount = 0;
        }
        if ($grantCount >= 2) {
            throw new RuntimeException('bKash Grant Token limit reached (2 attempts in one hour).');
        }
        $stmt = $pdo->prepare('UPDATE bkash_tokens SET grant_window_started_at=?, grant_count=? WHERE id=1');
        $stmt->execute([$windowStart, $grantCount + 1]);

        $grant = bkashRequest('POST', '/auth/grant-token', [
            'app_key' => $config['app_key'],
            'app_secret' => $config['app_secret'],
        ], null, true);
        if (!is_array($grant) || empty($grant['id_token'])
            || (string)($grant['statusCode'] ?? '0000') !== '0000') {
            logError('bKash Grant Token failed', [
                'http_code' => $grant['_http_code'] ?? null,
                'statusCode' => $grant['statusCode'] ?? null,
                'statusMessage' => $grant['statusMessage'] ?? null,
            ]);
            throw new RuntimeException('Unable to obtain bKash token. Check credentials and API access.');
        }
        $expiresIn = max(60, (int)($grant['expires_in'] ?? 3600));
        $stmt = $pdo->prepare('UPDATE bkash_tokens SET id_token=?, refresh_token=?, expires_at=? WHERE id=1');
        $stmt->execute([$grant['id_token'], $grant['refresh_token'] ?? null, $now + $expiresIn]);
        return (string)$grant['id_token'];
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('infinityhub_bkash_token_lock')");
    }
}

function createBkashPayment(string $amount, string $invoice, string $payerReference = 'InfinityHub'): array
{
    $config = appConfig();
    $payload = [
        'mode' => '0011',
        'payerReference' => $payerReference,
        'callbackURL' => $config['callback_url'],
        'amount' => number_format((float)$amount, 2, '.', ''),
        'currency' => $config['currency'] ?? 'BDT',
        'intent' => 'sale',
        'merchantInvoiceNumber' => $invoice,
    ];
    $response = bkashRequest('POST', '/payment/create', $payload, getBkashToken());
    if (!is_array($response)) {
        throw new RuntimeException('No response received from bKash Create Payment API.');
    }
    return $response;
}

function executeBkashPayment(string $paymentId): ?array
{
    return bkashRequest('POST', '/payment/execute', ['paymentId' => $paymentId], getBkashToken());
}

function queryBkashPayment(string $paymentId): ?array
{
    // The supplied v2 route is POST /query/payment.
    return bkashRequest('POST', '/query/payment', ['paymentID' => $paymentId], getBkashToken());
}


/** Refund a completed Tokenized Checkout v2 transaction. */
function refundBkashPayment(
    string $paymentId,
    string $trxId,
    string $amount,
    string $sku,
    string $reason
): ?array {
    $paymentId = trim($paymentId);
    $trxId = trim($trxId);
    $sku = trim($sku);
    $reason = trim($reason);
    $amount = trim($amount);

    if ($paymentId === '' || $trxId === '' || $sku === '' || $reason === '') {
        throw new InvalidArgumentException('paymentId, trxId, sku and reason are required.');
    }
    if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $amount) || (float)$amount <= 0) {
        throw new InvalidArgumentException('Refund amount must be a positive amount with up to 2 decimal places.');
    }
    if (strlen($sku) > 255 || strlen($reason) > 255) {
        throw new InvalidArgumentException('SKU and reason must each be 255 characters or fewer.');
    }

    $payload = [
        'paymentId' => $paymentId,
        'trxId' => $trxId,
        'amount' => number_format((float)$amount, 2, '.', ''),
        'sku' => $sku,
        'reason' => $reason,
    ];
    return bkashRequest('POST', '/refund/payment/transaction', $payload, getBkashToken());
}
