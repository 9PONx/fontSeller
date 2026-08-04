<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/inc/bootstrap.php';

site_session_start();

$publicId = $_GET['id'] ?? '';
if ($publicId === '' || ($_SESSION['order_public_id'] ?? '') !== $publicId) {
    json_response(['error' => 'Not found'], 404);
}

try {
    json_response(payment_refresh_promptpay($publicId));
} catch (InvalidArgumentException $e) {
    json_response(['error' => 'Not found'], 404);
} catch (Throwable $e) {
    json_response(['status' => 'error', 'paid' => false], 500);
}
