<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';

$token = $_GET['token'] ?? '';
if ($token === '' || strlen($token) < 10) {
    error_page(404, 'ไม่พบไฟล์ที่ต้องการดาวน์โหลด');
}

// 1) Token-level checks (unknown / not paid / expired / count exhausted).
$auth = download_authorize($token);
if ($auth === null) {
    error_page(404, 'ลิงก์ดาวน์โหลดไม่ถูกต้อง หมดอายุ หรือใช้งานครบจำนวนแล้ว');
}

// 2) Product file checks — the link itself is fine; the store is missing
//    its bundle, so tell the buyer to contact the seller instead of
//    showing a misleading "invalid link" message.
$product = download_product();
if ($product === null) {
    error_page(503, 'ไฟล์ชุดฟอนต์ยังไม่พร้อมใช้งาน กรุณาติดต่อผู้ขายพร้อมรหัสคำสั่งซื้อ');
}

// 3) Open the file BEFORE consuming a slot, so a failed open never burns
//    one of the limited downloads.
$handle = fopen($product['path'], 'rb');
if ($handle === false) {
    error_page(500, 'ไม่สามารถอ่านไฟล์สินค้าได้ กรุณาติดต่อผู้ขาย');
}

// 4) Atomically take one slot. A concurrent request may have taken the
//    last slot in between — that is a legitimate 404.
if (!download_consume($auth['token_id'])) {
    fclose($handle);
    error_page(404, 'ลิงก์ดาวน์โหลดไม่ถูกต้อง หมดอายุ หรือใช้งานครบจำนวนแล้ว');
}

// Stream the ZIP
while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $product['name'] . '"');
header('Content-Length: ' . $product['size']);
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');

while (!feof($handle)) {
    if (connection_aborted()) {
        break;
    }
    echo fread($handle, 1048576);
    flush();
}
fclose($handle);
exit;
