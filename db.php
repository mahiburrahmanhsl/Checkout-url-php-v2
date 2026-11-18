<?php
declare(strict_types=1);

function appConfig(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $local = __DIR__ . '/config.local.php';
    $config = require is_file($local) ? $local : (__DIR__ . '/config.php');

    if (($config['environment'] ?? '') === 'production') {
        $config['base_url'] = 'https://tokenized.pay.bka.sh/v2/tokenized-checkout';
    } else {
        $config['base_url'] = 'https://tokenized.sandbox.bka.sh/v2/tokenized-checkout';
    }

    foreach (['username', 'password', 'app_key', 'app_secret', 'callback_url'] as $key) {
        if (empty($config[$key]) || str_contains((string)$config[$key], 'YOUR_')) {
            throw new RuntimeException("Set the bKash {$key} value in config.local.php.");
        }
    }

    return $config;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $c = appConfig()['db'];
    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        $c['host'],
        $c['name'],
        $c['charset'] ?? 'utf8mb4'
    );

    $pdo = new PDO($dsn, $c['user'], $c['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}

function jsonResponse(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}


function logError(string $message, array $context = []): void
{
    $logDirectory = __DIR__ . '/logs';

    if (!is_dir($logDirectory)) {
        mkdir($logDirectory, 0755, true);
    }

    $safe = [
        'time' => date(DATE_ATOM),
        'message' => $message,
        'context' => $context,
    ];

    $logMessage = '[bKash] ' . json_encode(
        $safe,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    ) . PHP_EOL;

    error_log($logMessage, 3, $logDirectory . '/bkash.log');
}