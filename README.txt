InfinityHub bKash Tokenized Checkout v2
Target: PHP 8.2.12 + XAMPP + MySQL
Sandbox base URL: https://tokenized.sandbox.bka.sh/v2/tokenized-checkout
Production base URL: https://tokenized.pay.bka.sh/v2/tokenized-checkout

V2 ENDPOINTS USED
-----------------
POST /auth/grant-token
POST /auth/refresh-token
POST /payment/create
POST /payment/execute
POST /query/payment
POST /refund/payment/transaction

Refund endpoint supplied by the merchant: POST /refund/payment/transaction.

FILES
-----
payment.php              Payment form and Create Payment request
callback.php             Execute Payment, then Query Payment verification
token.php                Backward-compatible getToken() wrapper
bkash.php                v2 cURL client, Grant/Refresh token cache, API helpers
db.php                   PDO database/config helpers
config.php               Safe placeholder config (do not put secrets here)
config.local.php.example Copy to config.local.php and add your credentials
 database.sql            MySQL schema
success.php              Displays DB-verified successful payment
fail.php                 Failure page
refund.php               Protected JSON endpoint for v2 refunds

INSTALLATION (XAMPP)
--------------------
1. Copy this folder to C:\xampp\htdocs\InfinityHub
2. Import database.sql using phpMyAdmin.
3. Copy config.local.php.example to config.local.php.
4. Put your bKash sandbox username, password, app_key and app_secret in config.local.php.
5. Confirm the database name/user/password in config.local.php.
6. Set refund_admin_key in config.local.php to a unique random secret of at least 32 characters.
7. Enable PHP extensions: curl, pdo_mysql and openssl.
8. Set callback_url to your public HTTPS tunnel URL, for example
   https://YOUR-NGROK-DOMAIN/InfinityHub/callback.php. bKash cannot call localhost.
9. Open http://localhost/InfinityHub/payment.php.

TOKEN MANAGEMENT
----------------
- Reuses the MySQL-cached id_token while it remains valid.
- Refreshes the token when it expires or approaches the configured safety margin.
- Requests a Grant Token only when there is no usable token/refresh token.
- Enforces at most two Grant Token attempts in a rolling one-hour window.
- Uses a MySQL advisory lock to prevent concurrent grant/refresh races.
- Sends Authorization: Bearer <id_token> for payment APIs.
- Never log token values, passwords or app secrets.

PAYMENT FLOW
------------
1. Create Payment uses POST /payment/create and redirects the customer to bkashURL.
2. The callback must be publicly reachable over HTTPS.
3. On a success callback, the server calls POST /payment/execute.
4. It then calls POST /query/payment and uses the query response as the primary
   verification result (Execute response is retained as fallback if Query has no response).
5. A transaction is marked successful when transactionStatus is Completed/Success
   or statusCode is 0000. Review this condition against the current merchant v2 docs.
6. Fulfil an order only after its database payment status is SUCCESS.

IMPORTANT NOTES
---------------
- This archive was not tested against your merchant sandbox credentials.
- Confirm v2 callback parameters, query body casing and response status values
  against the latest bKash merchant v2 documentation supplied to you.
- The v2 sample response commonly uses paymentId and trxId; the code accepts
  paymentId/paymentID and trxId/trxID for compatibility.
- Protect config.local.php from web access and never commit it.
- Rotate any credentials that were previously placed in a shared or uploaded config.php.
- Protect refund.php/admin operations and integrate your own order ownership,
  CSRF, login and fulfilment checks before production.
- Do not trust a customer-submitted amount in a real shop; load the amount from
  your server-side order record.


REFUND API
----------
POST /refund.php
Header: X-Refund-Admin-Key: the refund_admin_key configured in config.local.php
Content-Type: application/json

JSON body example (use the original successful paymentId and trxId):
{
  "paymentId": "PAYMENT_ID_FROM_CREATE",
  "trxId": "TRX_ID_FROM_EXECUTE",
  "amount": "10.00",
  "sku": "ORDER-ITEM-001",
  "reason": "Customer return"
}

The endpoint sends these fields to POST /refund/payment/transaction. Keep this
endpoint server-side/admin-only, use HTTPS outside localhost, and never expose
the refund admin key in browser JavaScript. Verify the original transaction and
remaining refundable amount in your order system before issuing a refund.
