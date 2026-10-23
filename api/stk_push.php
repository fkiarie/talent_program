<?php
/**
 * api/stk_push.php  (POST)
 * Called by the "Pay with M-Pesa" button. The browser sends ONLY the phone number and a CSRF token.
 * Who is paying and how much comes from the server: the students saved in the visitor's session
 * and what the database says they still owe. A visitor cannot choose or change the amount.
 */
require_once __DIR__ . '/_json.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['ok' => false, 'message' => 'POST only.'], 405);
}
json_csrf_check();

$checkout = $_SESSION['checkout'] ?? null;
if (!$checkout || empty($checkout['student_ids']) || empty($checkout['guardian_id'])) {
    json_out(['ok' => false, 'message' => 'Your registration session has ended. Please register again.'], 400);
}

$phone = normalize_phone(post_str('phone'));
if ($phone === null || !str_starts_with($phone, '254') || strlen($phone) !== 12) {
    json_out(['ok' => false, 'message' => 'Enter a valid Safaricom number, e.g. 0712 345 678.'], 422);
}

$ip = client_ip();
if (rate_limited('stk_ip', $ip, 10, 600) || rate_limited('stk_guardian', (string) $checkout['guardian_id'], 6, 600)) {
    json_out(['ok' => false, 'message' => 'Too many payment attempts. Please wait a few minutes.'], 429);
}

$studentIds = array_map('intval', $checkout['student_ids']);
$due   = amount_due($studentIds);
$whole = (int) ceil($due - 0.0001);          // Daraja only accepts whole shillings
if ($whole < 1) {
    json_out(['ok' => false, 'message' => 'Nothing is due. This registration is already paid.'], 409);
}

$pdo = db();

// A prompt for the same family is already waiting: do not send a second one on a double-click.
$pending = $pdo->prepare(
    "SELECT id FROM mpesa_requests
      WHERE guardian_id = ? AND status = 'PENDING' AND created_at > (NOW() - INTERVAL 90 SECOND)
      ORDER BY id DESC LIMIT 1"
);
$pending->execute([(int) $checkout['guardian_id']]);
if ($existingId = $pending->fetchColumn()) {
    $_SESSION['mpesa_requests'][] = (int) $existingId;
    json_out(['ok' => true, 'request_id' => (int) $existingId, 'message' => 'A prompt was already sent. Check your phone.']);
}

rate_limit_hit('stk_ip', $ip);
rate_limit_hit('stk_guardian', (string) $checkout['guardian_id']);

$pdo->prepare(
    'INSERT INTO mpesa_requests (guardian_id, student_ids, phone, amount) VALUES (?, ?, ?, ?)'
)->execute([(int) $checkout['guardian_id'], json_encode($studentIds), $phone, $whole]);
$requestId = (int) $pdo->lastInsertId();

try {
    $res = mpesa_stk_push($phone, $whole, 'MIST-' . $requestId, 'Talent fees');

    $pdo->prepare('UPDATE mpesa_requests SET merchant_request_id = ?, checkout_request_id = ? WHERE id = ?')
        ->execute([$res['merchant_request_id'], $res['checkout_request_id'], $requestId]);

    $_SESSION['mpesa_requests'][] = $requestId;        // only this visitor may poll this request
    json_out(['ok' => true, 'request_id' => $requestId, 'message' => 'Check your phone and enter your M-Pesa PIN.']);
} catch (MpesaException $ex) {
    $pdo->prepare("UPDATE mpesa_requests SET status = 'FAILED', result_desc = ?, completed_at = NOW() WHERE id = ?")
        ->execute([mb_substr($ex->getMessage(), 0, 255), $requestId]);
    error_log('STK push failed: ' . $ex->getMessage());

    // Visitors get a plain message; the technical reason is only shown while developing.
    json_out(['ok' => false, 'message' => APP_DEBUG ? $ex->getMessage() : 'We could not start the M-Pesa prompt. Please try again shortly.'], 502);
}
