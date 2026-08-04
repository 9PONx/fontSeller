<?php
declare(strict_types=1);

const ORDERS_FILE = 'orders.json';
const PAYMENT_METHODS = ['promptpay', 'truewallet'];
const ORDER_STATUSES = ['pending', 'paid', 'failed', 'expired'];

function order_create(string $name, string $email, string $method): array
{
    $name = trim($name);
    if ($name === '' || mb_strlen($name, 'UTF-8') > 120) {
        throw new InvalidArgumentException('กรุณากรอกชื่อ (ไม่เกิน 120 ตัวอักษร)');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('รูปแบบอีเมลไม่ถูกต้อง');
    }
    if (!in_array($method, PAYMENT_METHODS, true)) {
        throw new InvalidArgumentException('เลือกวิธีชำระเงินไม่ถูกต้อง');
    }

    $now = now_utc();

    $id = storage_insert(ORDERS_FILE, [
        'public_id' => bin2hex(random_bytes(16)),
        'customer_name' => $name,
        'customer_email' => $email,
        'amount_satang' => config_int('PRODUCT_PRICE_SATANG', 10000),
        'payment_method' => $method,
        'status' => 'pending',
        'provider_transaction_id' => null,
        'provider_qr_url' => null,
        'provider_expires_at' => null,
        'next_provider_check_at' => null,
        'paid_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return order_by_id($id);
}

function order_by_id(int $id): ?array
{
    return storage_find(ORDERS_FILE, fn (array $r) => (int) ($r['id'] ?? 0) === $id);
}

function order_by_public_id(string $publicId): ?array
{
    return storage_find(ORDERS_FILE, fn (array $r) => ($r['public_id'] ?? '') === $publicId);
}

function order_mark_paid(int $id, string $providerTransactionId, int $amountSatang): bool
{
    return storage_update_where(
        ORDERS_FILE,
        fn (array $r) => (int) ($r['id'] ?? 0) === $id
            && ($r['status'] ?? '') === 'pending'
            && (int) ($r['amount_satang'] ?? 0) === $amountSatang,
        function (array $r) use ($providerTransactionId): array {
            $r['status'] = 'paid';
            $r['provider_transaction_id'] = $providerTransactionId;
            $r['paid_at'] = now_utc();
            $r['updated_at'] = now_utc();
            return $r;
        }
    ) === 1;
}

function order_mark_failed(int $id): bool
{
    return storage_update_where(
        ORDERS_FILE,
        fn (array $r) => (int) ($r['id'] ?? 0) === $id && ($r['status'] ?? '') === 'pending',
        function (array $r): array {
            $r['status'] = 'failed';
            $r['updated_at'] = now_utc();
            return $r;
        }
    ) === 1;
}

function order_mark_expired(int $id): bool
{
    return storage_update_where(
        ORDERS_FILE,
        fn (array $r) => (int) ($r['id'] ?? 0) === $id && ($r['status'] ?? '') === 'pending',
        function (array $r): array {
            $r['status'] = 'expired';
            $r['updated_at'] = now_utc();
            return $r;
        }
    ) === 1;
}

function order_set_provider(int $id, string $transactionId, ?string $expiresAt, ?string $nextCheckAt, ?string $qrUrl = null): void
{
    storage_update(ORDERS_FILE, $id, function (array $r) use ($transactionId, $expiresAt, $nextCheckAt, $qrUrl): array {
        $r['provider_transaction_id'] = $transactionId;
        if ($expiresAt !== null) {
            $r['provider_expires_at'] = $expiresAt;
        }
        if ($nextCheckAt !== null) {
            $r['next_provider_check_at'] = $nextCheckAt;
        }
        if ($qrUrl !== null) {
            $r['provider_qr_url'] = $qrUrl;
        }
        $r['updated_at'] = now_utc();
        return $r;
    });
}

function order_set_amount(int $id, int $amountSatang): void
{
    storage_update(ORDERS_FILE, $id, function (array $r) use ($amountSatang): array {
        $r['amount_satang'] = $amountSatang;
        $r['updated_at'] = now_utc();
        return $r;
    });
}

function order_set_next_check(int $id, string $nextCheckAt): void
{
    storage_update(ORDERS_FILE, $id, function (array $r) use ($nextCheckAt): array {
        $r['next_provider_check_at'] = $nextCheckAt;
        $r['updated_at'] = now_utc();
        return $r;
    });
}

function order_qr_url(int $id): ?string
{
    $order = order_by_id($id);
    return $order['provider_qr_url'] ?? null;
}
