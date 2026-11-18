<?php
declare(strict_types=1);
$reason = (string)($_GET['reason'] ?? 'Payment was not completed.');
?>
<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Payment unsuccessful</title><body style="font-family:Arial;padding:40px">
<h2>Payment unsuccessful</h2>
<p><?= htmlspecialchars($reason) ?></p>
<a href="payment.php">Try again</a>
</body></html>
