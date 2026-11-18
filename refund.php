<?php
declare(strict_types=1);

require_once __DIR__ . '/bkash.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    jsonResponse(['error' => 'Method not allowed. Send a POST request.'], 405);
}

try {
    $config = appConfig();
    $expectedKey = (string)($config['refund_admin_key'] ?? '');
    $providedKey = (string)($_SERVER['HTTP_X_REFUND_ADMIN_KEY'] ?? '');
    if ($expectedKey === '' || strlen($expectedKey) < 32 || !hash_equals($expectedKey, $providedKey)) {
        jsonResponse(['error' => 'Unauthorized refund request. Configure a strong refund_admin_key and send it in X-Refund-Admin-Key.'], 401);
    }

    $input = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($input)) {
        jsonResponse(['error' => 'Request body must be valid JSON.'], 400);
    }

    $result = refundBkashPayment(
        (string)($input['paymentId'] ?? ''),
        (string)($input['trxId'] ?? ''),
        (string)($input['amount'] ?? ''),
        (string)($input['sku'] ?? ''),
        (string)($input['reason'] ?? '')
    );

    if (!is_array($result)) {
        jsonResponse(['error' => 'No response received from bKash Refund API.'], 502);
    }
    jsonResponse($result, (int)($result['_http_code'] ?? 200));
} catch (InvalidArgumentException $e) {
    jsonResponse(['error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    logError('bKash refund request failed', ['error' => $e->getMessage()]);
    jsonResponse(['error' => $e->getMessage()], 500);
}
