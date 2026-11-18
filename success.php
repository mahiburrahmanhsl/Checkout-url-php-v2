<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

$invoice = trim((string)($_GET['invoice'] ?? ''));
$stmt = db()->prepare("SELECT invoice, amount, trx_id, status FROM bkash_payments WHERE invoice=? LIMIT 1");
$stmt->execute([$invoice]);
$payment = $stmt->fetch();

if (!$payment || $payment['status'] !== 'SUCCESS') {
    http_response_code(404);
    exit('Verified successful payment not found.');
}
?>
<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Payment successful</title><body style="font-family:Arial;padding:40px">
<h2>Payment successful</h2>
<p>Invoice: <?= htmlspecialchars($payment['invoice']) ?></p>
<p>Amount: BDT <?= htmlspecialchars($payment['amount']) ?></p>
<p>Transaction ID: <?= htmlspecialchars((string)$payment['trx_id']) ?></p>
</body></html>
