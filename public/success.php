<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';

site_session_start();

$publicId = $_GET['id'] ?? '';
if ($publicId === '' || ($_SESSION['order_public_id'] ?? '') !== $publicId) {
    error_page(404, 'ไม่พบคำสั่งซื้อ');
}

$order = order_by_public_id($publicId);
if ($order === null || $order['status'] !== 'paid') {
    redirect(url('/pay.php?id=' . $publicId));
}

// Issue the download token once and keep the raw token in the session.
// If the session lost the raw token (different browser / cleared cookies)
// but the order is already paid, rotate to a fresh link so the buyer is
// never locked out of what they paid for.
$rawToken = $_SESSION['download_token'] ?? null;
if ($rawToken === null) {
    try {
        if (download_token_by_order((int) $order['id']) === null) {
            $rawToken = download_issue_token((int) $order['id']);
        } else {
            $rawToken = download_rotate_token((int) $order['id']);
        }
        $_SESSION['download_token'] = $rawToken;
    } catch (RuntimeException $e) {
        $rawToken = null;
    }
}

$token = download_token_by_order((int) $order['id']);
$remaining = $token ? (int) $token['max_downloads'] - (int) $token['download_count'] : 3;
$expiresAt = $token['expires_at'] ?? null;

page_header('ชำระเงินสำเร็จ');
?>
<section class="max-w-lg mx-auto px-4 py-8 text-center">
    <div class="bg-white rounded-lg border border-green-200 p-8 shadow-sm">
        <div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4">
            <svg class="w-8 h-8 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
            </svg>
        </div>
        <h1 class="text-2xl font-bold text-gray-900 mb-2">ชำระเงินสำเร็จ!</h1>
        <p class="text-gray-600 mb-6">ขอบคุณสำหรับการสั่งซื้อ ฟอนต์ของคุณพร้อมดาวน์โหลดแล้ว</p>

        <div class="bg-gray-50 rounded-lg p-4 mb-6 text-sm text-left">
            <p class="text-gray-600">คำสั่งซื้อ: <span class="font-mono font-medium"><?= e($publicId) ?></span></p>
            <?php if ($expiresAt): ?>
                <p class="text-gray-600 mt-1">ลิงก์หมดอายุ: <strong><?= e(date('d/m/Y H:i', strtotime($expiresAt))) ?></strong></p>
            <?php endif; ?>
            <p class="text-gray-600 mt-1">ดาวน์โหลดได้อีก: <strong><?= $remaining ?> ครั้ง</strong></p>
        </div>

        <?php if ($rawToken): ?>
            <a href="<?= e(url('/download.php?token=' . urlencode($rawToken))) ?>"
               class="inline-flex items-center gap-2 bg-green-600 text-white px-6 py-3 rounded-lg font-semibold hover:bg-green-700 transition-colors">
                ⬇ ดาวน์โหลดชุดฟอนต์
            </a>
            <p class="text-xs text-gray-500 mt-3">fontseller-all-fonts.zip</p>
        <?php else: ?>
            <p class="text-sm text-red-600">ไม่สามารถออกลิงก์ดาวน์โหลดได้ กรุณาติดต่อผู้ขายพร้อมรหัสคำสั่งซื้อ <?= e($publicId) ?></p>
        <?php endif; ?>
    </div>

    <a href="<?= e(url('/')) ?>" class="inline-block mt-6 text-brand-600 hover:underline">กลับไปหน้าหลัก</a>
</section>
<?php page_footer(); ?>
