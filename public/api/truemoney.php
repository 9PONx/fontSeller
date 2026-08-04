<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/inc/bootstrap.php';

site_session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw === false ? '' : $raw, true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$publicId = (string) ($payload['id'] ?? '');
if ($publicId === '' || ($_SESSION['order_public_id'] ?? '') !== $publicId) {
    json_response(['error' => 'Not found'], 404);
}

if (!csrf_check($payload['_csrf_token'] ?? null)) {
    json_response(['error' => 'CSRF token invalid'], 419);
}

$voucherUrl = (string) ($payload['voucher_url'] ?? '');

try {
    $result = payment_redeem_truewallet($publicId, $voucherUrl);
    json_response($result);
} catch (InvalidArgumentException $e) {
    json_response(['error' => $e->getMessage()], 422);
} catch (Throwable $e) {
    json_response(['error' => 'เกิดข้อผิดพลาดในการชำระเงิน กรุณาลองใหม่', 'paid' => false], 500);
}
