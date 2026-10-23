<?php
/**
 * includes/mpesa.php
 * Everything about M-Pesa (Safaricom Daraja "Lipa Na M-Pesa Online" / STK push).
 *
 * Three modes, chosen with MPESA_MODE in .env:
 *   simulate  No network. The prompt "succeeds" a few seconds later with a fake receipt code.
 *             ONLY works while APP_DEBUG=true, so it can never be left on by accident on the live site.
 *   sandbox   Real calls to Safaricom's test server (sandbox.safaricom.co.ke) with your sandbox keys.
 *   live      Real calls to api.safaricom.co.ke with your production keys.
 *
 * The flow:  pay.php -> api/stk_push.php (asks M-Pesa for the SERVER-calculated amount)
 *            -> parent enters PIN on their phone
 *            -> Safaricom calls api/mpesa_callback.php  -> mpesa_apply_result() records the payment
 *            -> pay.js polls api/stk_status.php and sends the parent to receipt.php
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/billing.php';

class MpesaException extends RuntimeException
{
}

/** 'simulate', 'sandbox' or 'live'. Refuses simulate on a non-debug site. */
function mpesa_mode(): string
{
    $mode = strtolower((string) env('MPESA_MODE', 'simulate'));
    if (!in_array($mode, ['simulate', 'sandbox', 'live'], true)) {
        throw new MpesaException('MPESA_MODE must be simulate, sandbox or live.');
    }
    if ($mode === 'simulate' && !APP_DEBUG) {
        throw new MpesaException('M-Pesa simulation only works when APP_DEBUG=true. Set MPESA_MODE=live (or sandbox) with your Daraja keys.');
    }
    return $mode;
}

function mpesa_base_url(): string
{
    return mpesa_mode() === 'live' ? 'https://api.safaricom.co.ke' : 'https://sandbox.safaricom.co.ke';
}

/** Read a required setting or explain exactly what is missing. */
function mpesa_setting(string $key): string
{
    $v = trim((string) env($key, ''));
    if ($v === '') {
        throw new MpesaException("M-Pesa setting $key is missing in .env.");
    }
    return $v;
}

/** The full callback URL we give Safaricom, with the secret token appended. */
function mpesa_callback_url(): string
{
    $base = mpesa_setting('MPESA_CALLBACK_URL');
    $secret = mpesa_setting('MPESA_CALLBACK_SECRET');
    if (mpesa_mode() === 'live' && !str_starts_with($base, 'https://')) {
        throw new MpesaException('MPESA_CALLBACK_URL must start with https:// in live mode.');
    }
    return $base . (str_contains($base, '?') ? '&' : '?') . 'token=' . rawurlencode($secret);
}

/** Small JSON-over-HTTPS helper. Returns the decoded JSON body. */
function mpesa_http(string $method, string $url, array $headers, ?array $json = null): array
{
    if (!function_exists('curl_init')) {
        throw new MpesaException('The PHP cURL extension is required for M-Pesa.');
    }
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_SSL_VERIFYPEER => true,
    ];
    if ($json !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($json);
        $opts[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
    }
    curl_setopt_array($ch, $opts);

    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false) {
        throw new MpesaException('Could not reach M-Pesa: ' . $err);
    }
    $data = json_decode((string) $body, true);
    if (!is_array($data)) {
        throw new MpesaException("M-Pesa returned an unreadable response (HTTP $code).");
    }
    $data['_http'] = $code;
    return $data;
}

/** OAuth access token (valid ~1 hour; fetched once per request). */
function mpesa_token(): string
{
    static $token = null;
    if ($token !== null) {
        return $token;
    }
    $auth = base64_encode(mpesa_setting('MPESA_CONSUMER_KEY') . ':' . mpesa_setting('MPESA_CONSUMER_SECRET'));
    $res  = mpesa_http('GET', mpesa_base_url() . '/oauth/v1/generate?grant_type=client_credentials', ["Authorization: Basic $auth"]);
    if (empty($res['access_token'])) {
        throw new MpesaException('M-Pesa rejected the consumer key/secret.');
    }
    return $token = (string) $res['access_token'];
}

/**
 * Ask M-Pesa to pop the PIN prompt on the customer's phone.
 * $amount must be a whole number of shillings (Daraja does not accept cents).
 * Returns ['merchant_request_id', 'checkout_request_id', 'message'].
 */
function mpesa_stk_push(string $phone, int $amount, string $accountRef, string $description): array
{
    if (mpesa_mode() === 'simulate') {
        return [
            'merchant_request_id' => 'SIM-M' . bin2hex(random_bytes(6)),
            'checkout_request_id' => 'SIM-C' . bin2hex(random_bytes(8)),
            'message'             => 'Simulation: no real prompt was sent.',
        ];
    }

    $shortcode = mpesa_setting('MPESA_SHORTCODE');
    $timestamp = date('YmdHis');
    $res = mpesa_http('POST', mpesa_base_url() . '/mpesa/stkpush/v1/processrequest',
        ['Authorization: Bearer ' . mpesa_token()],
        [
            'BusinessShortCode' => $shortcode,
            'Password'          => base64_encode($shortcode . mpesa_setting('MPESA_PASSKEY') . $timestamp),
            'Timestamp'         => $timestamp,
            'TransactionType'   => (string) env('MPESA_TRANSACTION_TYPE', 'CustomerPayBillOnline'),
            'Amount'            => $amount,
            'PartyA'            => $phone,
            'PartyB'            => (string) env('MPESA_PARTY_B', $shortcode),
            'PhoneNumber'       => $phone,
            'CallBackURL'       => mpesa_callback_url(),
            'AccountReference'  => mb_substr($accountRef, 0, 12),
            'TransactionDesc'   => mb_substr($description, 0, 13),
        ]);

    if (($res['ResponseCode'] ?? '') !== '0' || empty($res['CheckoutRequestID'])) {
        $why = $res['errorMessage'] ?? $res['ResponseDescription'] ?? 'Unknown error';
        error_log('M-Pesa STK push rejected: ' . json_encode($res));
        throw new MpesaException('M-Pesa could not send the prompt: ' . $why);
    }

    return [
        'merchant_request_id' => (string) ($res['MerchantRequestID'] ?? ''),
        'checkout_request_id' => (string) $res['CheckoutRequestID'],
        'message'             => (string) ($res['CustomerMessage'] ?? ''),
    ];
}

/**
 * Ask M-Pesa what happened to a prompt (used when the callback is late or lost).
 * Returns ['result_code' => int|null, 'result_desc' => string]. result_code is null while it is still processing.
 * NOTE: this does not return the receipt number, so a success found this way must wait for the callback.
 */
function mpesa_stk_query(string $checkoutRequestId): array
{
    $shortcode = mpesa_setting('MPESA_SHORTCODE');
    $timestamp = date('YmdHis');
    $res = mpesa_http('POST', mpesa_base_url() . '/mpesa/stkpushquery/v1/query',
        ['Authorization: Bearer ' . mpesa_token()],
        [
            'BusinessShortCode' => $shortcode,
            'Password'          => base64_encode($shortcode . mpesa_setting('MPESA_PASSKEY') . $timestamp),
            'Timestamp'         => $timestamp,
            'CheckoutRequestID' => $checkoutRequestId,
        ]);

    if (!isset($res['ResultCode'])) {          // still being processed (Daraja replies with an error body)
        return ['result_code' => null, 'result_desc' => (string) ($res['errorMessage'] ?? 'Processing')];
    }
    return ['result_code' => (int) $res['ResultCode'], 'result_desc' => (string) ($res['ResultDesc'] ?? '')];
}

/**
 * Apply the outcome of a prompt to the database. Used by the real callback AND by the simulator,
 * so there is only one place that records money.
 *
 * It is safe to call twice for the same prompt (Safaricom sometimes retries): the first call wins.
 *
 * @param array|null $meta on success: ['amount' => float, 'receipt' => string, 'phone' => ?string, 'paid_on' => ?string]
 * @return array ['status' => string, 'payment_id' => ?int]  or ['status' => 'UNKNOWN'] if no such request
 */
function mpesa_apply_result(string $checkoutRequestId, int $resultCode, string $resultDesc, ?array $meta = null): array
{
    $pdo = db();
    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare('SELECT * FROM mpesa_requests WHERE checkout_request_id = ? FOR UPDATE');
        $stmt->execute([$checkoutRequestId]);
        $req = $stmt->fetch();

        if (!$req) {
            $pdo->rollBack();
            return ['status' => 'UNKNOWN', 'payment_id' => null];
        }
        if ($req['status'] !== 'PENDING') {                 // already handled (a retry)
            $pdo->commit();
            return ['status' => $req['status'], 'payment_id' => $req['payment_id'] !== null ? (int) $req['payment_id'] : null];
        }

        if ($resultCode !== 0) {
            // 1032 = customer cancelled the prompt, 1037 = the prompt timed out on the phone
            $status = $resultCode === 1032 ? 'CANCELLED' : ($resultCode === 1037 ? 'TIMEOUT' : 'FAILED');
            $pdo->prepare('UPDATE mpesa_requests SET status = ?, result_code = ?, result_desc = ?, completed_at = NOW() WHERE id = ?')
                ->execute([$status, $resultCode, mb_substr($resultDesc, 0, 255), $req['id']]);
            $pdo->commit();
            return ['status' => $status, 'payment_id' => null];
        }

        // ---- Success: record the payment and spread it over the unpaid charges ----
        $receipt = strtoupper(trim((string) ($meta['receipt'] ?? '')));
        $amount  = (float) ($meta['amount'] ?? 0);
        if ($receipt === '' || strlen($receipt) > 15 || $amount <= 0) {
            throw new MpesaException('Success callback without a valid receipt number or amount.');
        }

        $studentIds = json_decode((string) $req['student_ids'], true) ?: [];
        $payer = $meta['phone'] ?? null;
        $payer = ($payer !== null && preg_match('/^\d{10,15}$/', (string) $payer)) ? (string) $payer : $req['phone'];
        $paidOn = $meta['paid_on'] ?? date('Y-m-d H:i:s');

        // If this receipt was already saved (e.g. a duplicate delivery), reuse it instead of paying twice.
        $find = $pdo->prepare('SELECT id FROM payments WHERE mpesa_code = ?');
        $find->execute([$receipt]);
        $paymentId = $find->fetchColumn();

        if ($paymentId === false) {
            $pdo->prepare(
                "INSERT INTO payments (mpesa_code, student_id, amount, paid_on, payer_phone, status, recorded_by)
                 VALUES (?, ?, ?, ?, ?, 'VALID', NULL)"
            )->execute([$receipt, (int) $studentIds[0], $amount, $paidOn, $payer]);
            $paymentId = (int) $pdo->lastInsertId();

            $split = allocate_payment($pdo, $paymentId, $amount, $studentIds);
            if ($split['unallocated'] > 0) {
                error_log("M-Pesa $receipt: Ksh {$split['unallocated']} paid above what was owed (kept as credit).");
            }
        }

        $pdo->prepare(
            "UPDATE mpesa_requests SET status = 'SUCCESS', result_code = 0, result_desc = ?, payment_id = ?, completed_at = NOW() WHERE id = ?"
        )->execute([mb_substr($resultDesc, 0, 255), $paymentId, $req['id']]);

        $pdo->commit();
        return ['status' => 'SUCCESS', 'payment_id' => (int) $paymentId];
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $ex;
    }
}
