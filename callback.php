<?php
declare(strict_types=1);

require_once __DIR__ . '/bkash.php';

$paymentId = trim((string)($_GET['paymentID'] ?? $_GET['paymentId'] ?? ''));
$status = strtolower(trim((string)($_GET['status'] ?? '')));

if ($paymentId === '') {
    http_response_code(400);
    exit('Missing paymentID.');
}

$pdo = db();
$stmt = $pdo->prepare("SELECT * FROM bkash_payments WHERE payment_id = ? LIMIT 1");
$stmt->execute([$paymentId]);
$payment = $stmt->fetch();

if (!$payment) {
    http_response_code(404);
    exit('Payment record not found.');
}

if ($status !== 'success') {
    $stmt = $pdo->prepare("UPDATE bkash_payments SET status=? WHERE payment_id=?");
    $stmt->execute([strtoupper($status ?: 'CANCELLED'), $paymentId]);
    header('Location: fail.php?reason=' . rawurlencode($status ?: 'cancelled'), true, 303);
    exit;
}

try {
    // Execute the payment, then use the supplied v2 Query Payment API to verify it.
    $execute = executeBkashPayment($paymentId);
    $query = queryBkashPayment($paymentId);
    $verified = is_array($query) ? $query : $execute;

    $transactionStatus = strtolower((string)($verified['transactionStatus'] ?? ''));
    $success = is_array($verified) && (
        (string)($verified['statusCode'] ?? '') === '0000'
        || in_array($transactionStatus, ['completed', 'success'], true)
    );

    $trxId = $verified['trxId'] ?? $verified['trxID'] ?? null;
    $stmt = $pdo->prepare('UPDATE bkash_payments SET status=?, trx_id=?, execute_response=?, query_response=? WHERE payment_id=?');
    $stmt->execute([
        $success ? 'SUCCESS' : 'FAILED',
        $trxId,
        $execute === null ? null : json_encode($execute, JSON_UNESCAPED_SLASHES),
        $query === null ? null : json_encode($query, JSON_UNESCAPED_SLASHES),
        $paymentId,
    ]);

    if ($success) {
        header('Location: success.php?invoice=' . rawurlencode($payment['invoice']), true, 303);
        exit;
    }

    $message = (string)($verified['statusMessage'] ?? 'Payment was not successful.');
    header('Location: fail.php?reason=' . rawurlencode($message), true, 303);
    exit;
} catch (Throwable $e) {
    logError('Callback processing failed', ['payment_id' => $paymentId, 'error' => $e->getMessage()]);
    http_response_code(500);
    exit('Payment verification is pending. Please contact support with your invoice number.');
}
