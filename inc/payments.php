<?php
declare(strict_types=1);

const ATTEMPTS_FILE = 'payment_attempts.json';
const INWCLOUD_QR_HOST = 'api.qrserver.com';

/* ------------------------------------------------------------------ */
/* InwCloud HTTP transport                                             */
/* ------------------------------------------------------------------ */

/**
 * @return array{ok:bool, status:int, data:?array, category:string, message:string}
 */
function inwcloud_post(string $endpoint, array $payload): array
{
    $url = rtrim((string) config('INWCLOUD_API_BASE_URL', 'https://api.inwcloud.shop'), '/') . $endpoint;
    $timeout = config_int('INWCLOUD_TIMEOUT_SECONDS', 15);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . config('INWCLOUD_API_KEY', ''),
            'Content-Type: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $body = curl_exec($ch);
    if ($body === false) {
        $error = curl_error($ch);
        curl_close($ch);
        return ['ok' => false, 'status' => 0, 'data' => null, 'category' => 'transport', 'message' => $error];
    }

    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'status' => $status, 'data' => null, 'category' => 'invalid_json', 'message' => 'Invalid provider response'];
    }

    return [
        'ok' => $status >= 200 && $status < 300,
        'status' => $status,
        'data' => $decoded,
        'category' => 'provider_error',
        'message' => (string) ($decoded['message'] ?? ''),
    ];
}

/** Convert "100.00" / "100" / 100.0 into integer satang (max 2 decimals). */
function inwcloud_parse_amount(mixed $value): ?int
{
    $str = (string) $value;
    if (!preg_match('/^-?\d+(\.\d{1,2})?$/', $str)) {
        return null;
    }
    return (int) round((float) $str * 100);
}

/* ------------------------------------------------------------------ */
/* Real InwCloud calls                                                 */
/* ------------------------------------------------------------------ */

/**
 * @return array{transactionId:string, qrUrl:string, amountSatang:int, expiresAt:string}
 */
function inwcloud_generate_promptpay(): array
{
    $amount = number_format(config_int('PRODUCT_PRICE_SATANG', 10000) / 100, 2, '.', '');
    $res = inwcloud_post('/v1/promptpay/generate', ['amount' => (float) $amount]);

    if (!$res['ok'] || ($res['data']['status'] ?? '') !== 'success') {
        $code = (string) ($res['data']['code'] ?? '');
        error_log('InwCloud promptpay generate failed: HTTP ' . $res['status'] . ' code=' . $code . ' message=' . $res['message']);

        if ($code === 'INVALID_API_KEY' || $res['status'] === 401) {
            throw new RuntimeException('คีย์ InwCloud ไม่ถูกต้องหรือยังไม่ได้ตั้งค่า (INVALID_API_KEY) — ตรวจสอบ INWCLOUD_API_KEY ในไฟล์ .env แล้วลองใหม่');
        }
        throw new RuntimeException('ไม่สามารถสร้าง QR ได้ชั่วคราว กรุณาลองใหม่');
    }

    $data = $res['data']['data'] ?? [];
    $transactionId = (string) ($data['transactionId'] ?? '');
    $qrUrl = (string) ($data['qr_url'] ?? '');
    $amountSatang = inwcloud_parse_amount($data['amount'] ?? null);
    $expiresAt = isset($data['expires_at']) ? date('Y-m-d H:i:s', (int) $data['expires_at']) : null;

    if ($transactionId === '' || $qrUrl === '' || $amountSatang === null || $expiresAt === null) {
        throw new RuntimeException('ข้อมูลจากผู้ให้บริการชำระเงินไม่ครบถ้วน');
    }
    // InwCloud adds its channel fee on top of the requested amount (e.g. 100.00 -> 100.03).
    // The amount returned is the real amount the customer must pay via the QR, so accept it
    // and let the order record it as the expected amount. Never assume a fixed fee.
    if ($amountSatang <= 0) {
        throw new RuntimeException('ข้อมูลจากผู้ให้บริการชำระเงินไม่ครบถ้วน');
    }

    // QR URL must be HTTPS, host must match, no userinfo/fragment
    $parts = parse_url($qrUrl);
    if ($parts === false
        || ($parts['scheme'] ?? '') !== 'https'
        || strtolower($parts['host'] ?? '') !== INWCLOUD_QR_HOST
        || isset($parts['user'], $parts['pass'], $parts['fragment'])) {
        throw new RuntimeException('QR ไม่ถูกต้องจากผู้ให้บริการชำระเงิน');
    }

    return [
        'transactionId' => $transactionId,
        'qrUrl' => $qrUrl,
        'amountSatang' => $amountSatang,
        'expiresAt' => $expiresAt,
    ];
}

/**
 * @return ?array{status:string, transactionId:string, amountSatang:?int, expiresAt:?string} null on provider error
 */
function inwcloud_check_promptpay(string $transactionId): ?array
{
    $res = inwcloud_post('/v1/promptpay/check', ['transactionId' => $transactionId]);
    if (!$res['ok']) {
        return null;
    }

    $data = $res['data'];
    $status = (string) ($data['status'] ?? 'error');
    $txn = (string) ($data['transactionId'] ?? $transactionId);
    $amount = isset($data['amount']) ? inwcloud_parse_amount($data['amount']) : null;
    $expires = isset($data['expires_at']) ? date('Y-m-d H:i:s', (int) $data['expires_at']) : null;

    return [
        'status' => $status,
        'transactionId' => $txn,
        'amountSatang' => $amount,
        'expiresAt' => $expires,
    ];
}

/**
 * @return array{status:string, amountSatang:?int}
 */
function inwcloud_redeem_truewallet(string $voucherUrl): array
{
    $res = inwcloud_post('/v1/truewallet/redeem', ['voucher_link' => $voucherUrl]);

    if (!$res['ok']) {
        return ['status' => 'error', 'amountSatang' => null];
    }

    if (($res['data']['status'] ?? '') !== 'success') {
        return ['status' => 'error', 'amountSatang' => null];
    }

    $data = $res['data']['data'] ?? [];
    $amount = isset($data['amount']) ? inwcloud_parse_amount($data['amount']) : null;

    return ['status' => 'success', 'amountSatang' => $amount];
}

/* ------------------------------------------------------------------ */
/* Voucher validation                                                  */
/* ------------------------------------------------------------------ */

function validate_voucher_url(string $url): string
{
    $url = trim($url);

    if ($url === '') {
        throw new InvalidArgumentException('กรุณากรอกลิงก์ซองของขวัญ');
    }
    if (strlen($url) > 2048) {
        throw new InvalidArgumentException('ลิงก์ยาวเกินไป');
    }
    if (preg_match('/\s/', $url)) {
        throw new InvalidArgumentException('ลิงก์ต้องไม่มีช่องว่าง');
    }

    $parts = parse_url($url);
    if ($parts === false) {
        throw new InvalidArgumentException('รูปแบบลิงก์ไม่ถูกต้อง');
    }
    if (($parts['scheme'] ?? '') !== 'https') {
        throw new InvalidArgumentException('ลิงก์ต้องเป็น HTTPS เท่านั้น');
    }
    if (strtolower($parts['host'] ?? '') !== 'gift.truemoney.com') {
        throw new InvalidArgumentException('ลิงก์ต้องมาจาก gift.truemoney.com เท่านั้น');
    }
    if (isset($parts['user'], $parts['pass'], $parts['fragment'])) {
        throw new InvalidArgumentException('ลิงก์มีส่วนประกอบที่ไม่ได้รับอนุญาต');
    }

    parse_str($parts['query'] ?? '', $query);
    if (empty($query['v'])) {
        throw new InvalidArgumentException('ลิงก์ไม่มีรหัสซองของขวัญ (พารามิเตอร์ v)');
    }

    return $url;
}

/* ------------------------------------------------------------------ */
/* Payment attempts (idempotency)                                      */
/* ------------------------------------------------------------------ */

function attempt_create(int $orderId, string $provider, ?string $fingerprint): int
{
    return storage_insert(ATTEMPTS_FILE, [
        'order_id' => $orderId,
        'provider' => $provider,
        'provider_reference' => null,
        'request_fingerprint' => $fingerprint,
        'amount_satang' => null,
        'status' => 'created',
        'response_code' => null,
        'created_at' => now_utc(),
        'updated_at' => now_utc(),
    ]);
}

function attempt_update(int $id, string $status, ?string $reference = null, ?int $amountSatang = null, ?string $responseCode = null): void
{
    storage_update(ATTEMPTS_FILE, $id, function (array $r) use ($status, $reference, $amountSatang, $responseCode): array {
        $r['status'] = $status;
        if ($reference !== null) {
            $r['provider_reference'] = $reference;
        }
        if ($amountSatang !== null) {
            $r['amount_satang'] = $amountSatang;
        }
        if ($responseCode !== null) {
            $r['response_code'] = $responseCode;
        }
        $r['updated_at'] = now_utc();
        return $r;
    });
}

function attempt_by_fingerprint(string $fingerprint): ?array
{
    return storage_find(ATTEMPTS_FILE, fn (array $r) => ($r['request_fingerprint'] ?? null) === $fingerprint);
}

/* ------------------------------------------------------------------ */
/* Payment orchestration                                               */
/* ------------------------------------------------------------------ */

/**
 * Create (or reuse) the PromptPay QR for a pending order.
 * @return array{transactionId:string, qrUrl:?string, expiresAt:string, status:string}
 */
function payment_start_promptpay(string $publicId): array
{
    $order = order_by_public_id($publicId);
    if ($order === null || $order['status'] !== 'pending') {
        throw new InvalidArgumentException('Order not available');
    }

    // Reuse an unexpired transaction instead of generating a new one.
    if (!empty($order['provider_transaction_id']) && !empty($order['provider_expires_at'])) {
        if (strtotime($order['provider_expires_at']) > time()) {
            return [
                'transactionId' => $order['provider_transaction_id'],
                'qrUrl' => order_qr_url((int) $order['id']),
                'amountSatang' => (int) ($order['amount_satang'] ?? 0),
                'expiresAt' => $order['provider_expires_at'],
                'status' => $order['status'],
            ];
        }
    }

    $qr = inwcloud_generate_promptpay();

    order_set_provider((int) $order['id'], $qr['transactionId'], $qr['expiresAt'], now_utc(), $qr['qrUrl']);
    // Persist the provider's actual QR amount (list price + channel fee) as the expected payment.
    order_set_amount((int) $order['id'], $qr['amountSatang']);

    $attemptId = attempt_create((int) $order['id'], 'promptpay', null);
    attempt_update($attemptId, 'pending', $qr['transactionId'], $qr['amountSatang']);

    return [
        'transactionId' => $qr['transactionId'],
        'qrUrl' => $qr['qrUrl'],
        'amountSatang' => $qr['amountSatang'],
        'expiresAt' => $qr['expiresAt'],
        'status' => $order['status'],
    ];
}

/**
 * Check PromptPay status (throttled to once per 10 seconds per order).
 * @return array{status:string, paid:bool, throttled?:bool}
 */
function payment_refresh_promptpay(string $publicId): array
{
    $order = order_by_public_id($publicId);
    if ($order === null) {
        throw new InvalidArgumentException('Order not found');
    }

    if ($order['status'] !== 'pending') {
        return ['status' => $order['status'], 'paid' => $order['status'] === 'paid'];
    }

    $id = (int) $order['id'];

    // Throttle
    if (!empty($order['next_provider_check_at']) && strtotime($order['next_provider_check_at']) > time()) {
        return ['status' => 'pending', 'paid' => false, 'throttled' => true];
    }

    // Provider expiry
    if (!empty($order['provider_expires_at']) && strtotime($order['provider_expires_at']) <= time()) {
        order_mark_expired($id);
        return ['status' => 'expired', 'paid' => false];
    }

    if (empty($order['provider_transaction_id'])) {
        return ['status' => 'pending', 'paid' => false];
    }

    $check = inwcloud_check_promptpay($order['provider_transaction_id']);

    if ($check === null) {
        // Provider error: try again later
        order_set_next_check($id, date('Y-m-d H:i:s', time() + 10));
        return ['status' => 'pending', 'paid' => false];
    }

    // Accept the payment when the customer paid at least the list price. InwCloud's
    // channel fee varies between generate calls (100.00 -> 100.03, 100.05, ...), and
    // the check amount must match the QR the customer actually scanned, so compare
    // against the list price as a floor instead of an exact constant.
    $minExpected = config_int('PRODUCT_PRICE_SATANG', 10000);
    $maxExpected = $minExpected + 5000; // generous sanity cap: never accept a 50 THB over-charge

    if ($check['status'] === 'success') {
        if ($check['transactionId'] !== $order['provider_transaction_id']) {
            order_mark_failed($id);
            return ['status' => 'failed', 'paid' => false];
        }
        $paidAmount = $check['amountSatang'];
        if ($paidAmount === null || $paidAmount < $minExpected || $paidAmount > $maxExpected) {
            order_mark_failed($id);
            return ['status' => 'failed', 'paid' => false];
        }
        // Record the actual paid amount (list price + channel fee) before marking paid.
        if ((int) ($order['amount_satang'] ?? 0) !== $paidAmount) {
            order_set_amount($id, $paidAmount);
        }
        $paid = order_mark_paid($id, $check['transactionId'], $paidAmount);
        return ['status' => 'paid', 'paid' => $paid];
    }

    if ($check['status'] === 'pending') {
        $next = date('Y-m-d H:i:s', time() + 10);
        order_set_next_check($id, $next);
        if (!empty($check['expiresAt'])) {
            order_set_provider($id, $order['provider_transaction_id'], $check['expiresAt'], $next);
        }
        return ['status' => 'pending', 'paid' => false];
    }

    order_mark_failed($id);
    return ['status' => 'failed', 'paid' => false];
}

/**
 * Redeem a TrueMoney voucher for a pending order (idempotent by fingerprint).
 * @return array{status:string, paid:bool, error?:string}
 */
function payment_redeem_truewallet(string $publicId, string $voucherUrl): array
{
    $order = order_by_public_id($publicId);
    if ($order === null || $order['status'] !== 'pending') {
        throw new InvalidArgumentException('Order not available');
    }

    $voucherUrl = validate_voucher_url($voucherUrl);
    $expected = config_int('PRODUCT_PRICE_SATANG', 10000);
    $fingerprint = hash_hmac('sha256', $voucherUrl, (string) config('APP_KEY', ''));
    $id = (int) $order['id'];

    // Idempotency: same voucher never redeemed twice.
    $existing = attempt_by_fingerprint($fingerprint);
    if ($existing !== null) {
        return [
            'status' => $existing['status'] === 'succeeded' ? 'paid' : 'failed',
            'paid' => $existing['status'] === 'succeeded',
        ];
    }

    $attemptId = attempt_create($id, 'truewallet', $fingerprint);

    $result = inwcloud_redeem_truewallet($voucherUrl);

    if ($result['status'] === 'success' && $result['amountSatang'] === $expected) {
        attempt_update($attemptId, 'succeeded', null, $result['amountSatang']);
        $paid = order_mark_paid($id, 'truewallet-' . substr($fingerprint, 0, 16), $expected);
        return ['status' => 'paid', 'paid' => $paid];
    }

    if ($result['status'] === 'success') {
        // Redeemed but wrong value: irreversible, reject the order.
        attempt_update($attemptId, 'rejected', null, $result['amountSatang']);
        order_mark_failed($id);
        return ['status' => 'failed', 'paid' => false];
    }

    attempt_update($attemptId, 'error', null, null, 'provider_error');
    return ['status' => 'pending', 'paid' => false, 'error' => 'ไม่สามารถแลกซองได้ กรุณาลองใหม่'];
}
