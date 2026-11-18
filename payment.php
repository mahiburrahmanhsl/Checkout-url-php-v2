<?php
declare(strict_types=1);

require_once __DIR__ . '/bkash.php';

session_start();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $amountRaw = trim((string)($_POST['amount'] ?? ''));

    if (!preg_match('/^\d{1,8}(\.\d{1,2})?$/', $amountRaw) || (float)$amountRaw < 1) {
        $error = 'Enter a valid amount (minimum BDT 1.00).';
    } else {
        $amount = number_format((float)$amountRaw, 2, '.', '');
        $invoice = 'IH' . date('YmdHis') . bin2hex(random_bytes(4));

        try {
            $pdo = db();
            $stmt = $pdo->prepare(
                "INSERT INTO bkash_payments (invoice, amount, status) VALUES (?, ?, 'CREATING')"
            );
            $stmt->execute([$invoice, $amount]);

            $response = createBkashPayment($amount, $invoice);

            $stmt = $pdo->prepare(
                "UPDATE bkash_payments SET payment_id=?, create_response=?, status=? WHERE invoice=?"
            );
            $stmt->execute([
                $response['paymentId'] ?? $response['paymentID'] ?? null,
                json_encode($response, JSON_UNESCAPED_SLASHES),
                !empty($response['bkashURL']) ? 'PENDING' : 'CREATE_FAILED',
                $invoice,
            ]);

            if (!empty($response['bkashURL']) && filter_var($response['bkashURL'], FILTER_VALIDATE_URL)) {
                header('Location: ' . $response['bkashURL'], true, 303);
                exit;
            }

            $error = 'bKash could not create the payment: ' .
                htmlspecialchars((string)($response['statusMessage'] ?? 'Unknown API response'));
        } catch (Throwable $e) {
            logError('Payment initiation failed', ['error' => $e->getMessage()]);
            $error = 'Unable to initiate payment. Check configuration and PHP error log.';
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>InfinityHub - bKash Payment</title>
<style>
body{font-family:Arial,sans-serif;background:#f5f5f5;margin:0;display:grid;min-height:100vh;place-items:center}
.card{background:#fff;padding:28px;border-radius:12px;box-shadow:0 4px 20px #0001;width:min(360px,calc(100% - 48px))}
input,button{box-sizing:border-box;width:100%;padding:12px;margin-top:10px}
button{background:#d12053;color:#fff;border:0;border-radius:6px;font-weight:bold;cursor:pointer}
.error{color:#b00020}
</style>
</head>
<body>
<div class="card">
<h2>Pay with bKash</h2>
<?php if ($error !== ''): ?><p class="error"><?= htmlspecialchars($error) ?></p><?php endif; ?>
<form method="post">
<label for="amount">Amount (BDT)</label>
<input id="amount" name="amount" type="number" min="1" step="0.01" required>
<button type="submit">Continue to bKash</button>
</form>
</div>
</body>
</html>
