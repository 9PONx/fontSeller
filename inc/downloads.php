<?php
declare(strict_types=1);

const TOKENS_FILE = 'download_tokens.json';

/**
 * Issue a download token for a paid order (one per order).
 * @throws RuntimeException when a token already exists
 */
function download_issue_token(int $orderId): string
{
    if (download_token_by_order($orderId) !== null) {
        throw new RuntimeException('Token already issued');
    }

    $raw = bin2hex(random_bytes(32));
    $ttlHours = config_int('DOWNLOAD_TTL_HOURS', 24);

    storage_insert(TOKENS_FILE, [
        'order_id' => $orderId,
        'token_hash' => hash('sha256', $raw),
        'expires_at' => date('Y-m-d H:i:s', time() + $ttlHours * 3600),
        'download_count' => 0,
        'max_downloads' => config_int('DOWNLOAD_MAX_COUNT', 3),
        'last_downloaded_at' => null,
        'created_at' => now_utc(),
        'updated_at' => now_utc(),
    ]);

    return $raw;
}

/**
 * Replace the existing token of an order with a fresh one. Used when the
 * buyer returns without the original raw token (e.g. session lost after
 * payment) so they are never locked out of a link they paid for.
 * Old links stop working — the new link supersedes them.
 */
function download_rotate_token(int $orderId): string
{
    storage_delete_where(TOKENS_FILE, fn (array $r) => (int) ($r['order_id'] ?? 0) === $orderId);
    return download_issue_token($orderId);
}

/**
 * Token-level authorization only (no side effects).
 * @return ?array{token_id:int, remaining:int} null on any denial → 404
 */
function download_authorize(string $rawToken): ?array
{
    $token = storage_find(TOKENS_FILE, fn (array $r) => ($r['token_hash'] ?? '') === hash('sha256', $rawToken));
    if ($token === null) {
        return null;
    }

    $order = storage_find(ORDERS_FILE, fn (array $r) => (int) ($r['id'] ?? 0) === (int) ($token['order_id'] ?? 0));
    if ($order === null || ($order['status'] ?? '') !== 'paid') {
        return null;
    }
    if (strtotime($token['expires_at'] ?? '') <= time()) {
        return null;
    }
    if ((int) ($token['download_count'] ?? 0) >= (int) ($token['max_downloads'] ?? 3)) {
        return null;
    }

    return [
        'token_id' => (int) $token['id'],
        'remaining' => (int) ($token['max_downloads'] ?? 3) - (int) ($token['download_count'] ?? 0),
    ];
}

/**
 * Validate the product ZIP on disk. Returns file info or null (→ 503).
 * @return ?array{path:string, name:string, size:int}
 */
function download_product(): ?array
{
    $zip = realpath((string) config('PRODUCT_ZIP_PATH', ''));
    if ($zip === false || !is_file($zip) || strtolower(pathinfo($zip, PATHINFO_EXTENSION)) !== 'zip') {
        return null;
    }

    $size = filesize($zip);
    if ($size === false || $size === 0) {
        return null;
    }

    return [
        'path' => $zip,
        'name' => 'fontseller-all-fonts.zip',
        'size' => $size,
    ];
}

/**
 * Atomically take one download slot. Returns true when the slot was taken.
 * Called only after the file handle is already open, so a failed stream
 * never burns a download count.
 */
function download_consume(int $tokenId): bool
{
    return storage_update_where(
        TOKENS_FILE,
        fn (array $r) => (int) ($r['id'] ?? 0) === $tokenId
            && (int) ($r['download_count'] ?? 0) < (int) ($r['max_downloads'] ?? 3),
        function (array $r): array {
            $r['download_count'] = (int) ($r['download_count'] ?? 0) + 1;
            $r['last_downloaded_at'] = now_utc();
            $r['updated_at'] = now_utc();
            return $r;
        }
    ) === 1;
}

function download_token_by_order(int $orderId): ?array
{
    return storage_find(TOKENS_FILE, fn (array $r) => (int) ($r['order_id'] ?? 0) === $orderId);
}
