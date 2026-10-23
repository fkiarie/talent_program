<?php
/**
 * api/stk_status.php?id=123  (GET)
 * pay.js asks this every few seconds: "did the parent pay yet?"
 * Only the visitor who started the prompt (or logged-in staff) may ask.
 */
require_once __DIR__ . '/_json.php';

$id = (int) ($_GET['id'] ?? 0);
$owned = in_array($id, array_map('intval', $_SESSION['mpesa_requests'] ?? []), true);
if (!$owned && current_user() === null) {
    json_out(['ok' => false, 'status' => 'FORBIDDEN', 'message' => 'Not allowed.'], 403);
}

$stmt = db()->prepare('SELECT * FROM mpesa_requests WHERE id = ?');
$stmt->execute([$id]);
$req = $stmt->fetch();
if (!$req) {
    json_out(['ok' => false, 'status' => 'NOT_FOUND', 'message' => 'Unknown payment request.'], 404);
}

$age = time() - strtotime($req['created_at']);

// --- Simulation: pretend the parent entered their PIN about 5 seconds after the prompt ---
if ($req['status'] === 'PENDING' && str_starts_with((string) $req['checkout_request_id'], 'SIM-')
    && mpesa_mode() === 'simulate' && $age >= 5) {
    mpesa_apply_result($req['checkout_request_id'], 0, 'Simulated payment', [
        'amount'  => (float) $req['amount'],
        'receipt' => 'SIM' . strtoupper(bin2hex(random_bytes(4))),
        'phone'   => $req['phone'],
        'paid_on' => date('Y-m-d H:i:s'),
    ]);
    $stmt->execute([$id]);
    $req = $stmt->fetch();
}

// --- Real prompts: if the callback is late, ask M-Pesa so a cancelled prompt does not spin forever ---
if ($req['status'] === 'PENDING' && !str_starts_with((string) $req['checkout_request_id'], 'SIM-')
    && $age >= 25 && $age % 5 < 3) {
    try {
        $q = mpesa_stk_query($req['checkout_request_id']);
        if ($q['result_code'] !== null && $q['result_code'] !== 0) {     // failures only; success waits for the callback (it carries the receipt)
            mpesa_apply_result($req['checkout_request_id'], $q['result_code'], $q['result_desc']);
            $stmt->execute([$id]);
            $req = $stmt->fetch();
        }
    } catch (Throwable $ex) {
        error_log('STK query failed: ' . $ex->getMessage());
    }
}

$out = ['ok' => true, 'status' => $req['status'], 'message' => (string) $req['result_desc']];

if ($req['status'] === 'SUCCESS' && $req['payment_id']) {
    $code = db()->prepare('SELECT mpesa_code FROM payments WHERE id = ?');
    $code->execute([$req['payment_id']]);
    $out['receipt_code'] = (string) $code->fetchColumn();
    $_SESSION['receipt_codes'][] = $out['receipt_code'];      // lets this visitor open their receipt
}

if ($req['status'] === 'PENDING' && $age > 150) {
    $out['status']  = 'DELAYED';
    $out['message'] = 'We have not received confirmation yet. If M-Pesa already deducted the money, do not pay again: staff will see it once it arrives.';
}

json_out($out);
