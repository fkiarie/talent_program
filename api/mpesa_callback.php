<?php
/**
 * api/mpesa_callback.php  (called BY SAFARICOM, not by a browser)
 *
 * Safaricom posts the result of every STK prompt here. Safaricom does not sign these messages, so we
 * protect the endpoint in three ways:
 *   1. the URL we gave Safaricom contains a long secret token (MPESA_CALLBACK_SECRET);
 *   2. we only act on a CheckoutRequestID that WE created earlier and that is still PENDING;
 *   3. recording is idempotent, so a repeated delivery can never pay twice.
 * The amount we record is the amount M-Pesa reports it collected.
 *
 * Give Safaricom the URL  https://your-domain/talent-program/api/mpesa_callback.php  (the token is added for you).
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/mpesa.php';

header('Content-Type: application/json');

$ack = static function (string $desc = 'Accepted'): void {
    echo json_encode(['ResultCode' => 0, 'ResultDesc' => $desc]);
    exit;
};

$secret = (string) env('MPESA_CALLBACK_SECRET', '');
$token  = $_GET['token'] ?? '';
if ($secret === '' || !is_string($token) || !hash_equals($secret, $token)) {
    http_response_code(403);
    error_log('M-Pesa callback rejected: bad or missing token from ' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
    echo json_encode(['ResultCode' => 1, 'ResultDesc' => 'Forbidden']);
    exit;
}

$raw  = file_get_contents('php://input', false, null, 0, 65536);
$data = json_decode((string) $raw, true);
$cb   = $data['Body']['stkCallback'] ?? null;

if (!is_array($cb) || empty($cb['CheckoutRequestID'])) {
    http_response_code(400);
    echo json_encode(['ResultCode' => 1, 'ResultDesc' => 'Bad payload']);
    exit;
}

$meta = null;
$resultCode = (int) ($cb['ResultCode'] ?? 1);

if ($resultCode === 0) {
    $items = [];
    foreach (($cb['CallbackMetadata']['Item'] ?? []) as $item) {
        if (isset($item['Name'])) {
            $items[$item['Name']] = $item['Value'] ?? null;
        }
    }
    $paidOn = null;
    if (!empty($items['TransactionDate']) && ($d = DateTime::createFromFormat('YmdHis', (string) $items['TransactionDate']))) {
        $paidOn = $d->format('Y-m-d H:i:s');
    }
    $meta = [
        'amount'  => (float) ($items['Amount'] ?? 0),
        'receipt' => (string) ($items['MpesaReceiptNumber'] ?? ''),
        'phone'   => isset($items['PhoneNumber']) ? (string) $items['PhoneNumber'] : null,
        'paid_on' => $paidOn,
    ];
}

try {
    $result = mpesa_apply_result((string) $cb['CheckoutRequestID'], $resultCode, (string) ($cb['ResultDesc'] ?? ''), $meta);
    if ($result['status'] === 'UNKNOWN') {
        error_log('M-Pesa callback for unknown CheckoutRequestID ' . $cb['CheckoutRequestID']);
    }
} catch (Throwable $ex) {
    // Log it but still answer 200: the full callback is in the log so staff can reconcile by hand.
    error_log('M-Pesa callback error: ' . $ex->getMessage() . ' | ' . $raw);
}

$ack();
