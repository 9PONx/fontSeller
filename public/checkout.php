<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    site_session_start();

    $csrf = $_POST['_csrf_token'] ?? '';
    if (!csrf_check($csrf)) {
        error_page(419, 'โทเค็น CSRF ไม่ถูกต้อง กรุณากลับไปลองใหม่');
    }

    $name = trim($_POST['customer_name'] ?? '');
    $email = trim($_POST['customer_email'] ?? '');
    $method = $_POST['payment_method'] ?? '';

    try {
        $order = order_create($name, $email, $method);
        $_SESSION['order_public_id'] = $order['public_id'];

        redirect(url('/pay.php?id=' . $order['public_id']));
    } catch (InvalidArgumentException $ex) {
        $error = $ex->getMessage();
        $old = ['customer_name' => $name, 'customer_email' => $email, 'payment_method' => $method];
    }
}

page_header('ชำระเงิน');
?>
<section class="max-w-lg mx-auto px-4 py-8">
    <h1 class="text-2xl font-bold text-gray-900 mb-6">ชำระเงิน</h1>

    <div class="bg-white rounded-lg border border-gray-200 p-4 mb-6">
        <div class="flex justify-between items-center">
            <span class="font-medium">ชุดฟอนต์ไทยและอังกฤษ</span>
            <span class="font-bold text-brand-700"><?= money_thai(config_int('PRODUCT_PRICE_SATANG', 10000)) ?></span>
        </div>
        <p class="text-xs text-gray-500 mt-1">ฟอนต์ 3,600+ ตัว &middot; ดาวน์โหลดได้ 3 ครั้งภายใน 24 ชั่วโมง</p>
    </div>

    <?php if (!empty($error)): ?>
        <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-4 text-sm"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="<?= e(url('/checkout.php')) ?>" class="space-y-4">
        <?= csrf_field() ?>

        <div>
            <label for="customer_name" class="block text-sm font-medium text-gray-700 mb-1">ชื่อ</label>
            <input type="text" id="customer_name" name="customer_name" required maxlength="120"
                   value="<?= e($old['customer_name'] ?? '') ?>"
                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-500 focus:border-brand-500 text-sm">
        </div>

        <div>
            <label for="customer_email" class="block text-sm font-medium text-gray-700 mb-1">อีเมล</label>
            <input type="email" id="customer_email" name="customer_email" required maxlength="254"
                   value="<?= e($old['customer_email'] ?? '') ?>"
                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-500 focus:border-brand-500 text-sm">
            <p class="mt-1 text-xs text-gray-500">ใช้สำหรับอ้างอิงคำสั่งซื้อเท่านั้น ไม่มีการส่งอีเมล</p>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">วิธีชำระเงิน</label>
            <div class="space-y-2">
                <label class="flex items-center gap-3 p-3 border border-gray-300 rounded-lg cursor-pointer hover:bg-gray-50">
                    <input type="radio" name="payment_method" value="promptpay" required
                           <?= ($old['payment_method'] ?? '') === 'promptpay' ? 'checked' : '' ?>
                           class="text-brand-600 focus:ring-brand-500">
                    <div>
                        <span class="font-medium">PromptPay QR</span>
                        <p class="text-xs text-gray-500">สแกน QR เพื่อโอนเงิน</p>
                    </div>
                </label>
                <label class="flex items-center gap-3 p-3 border border-gray-300 rounded-lg cursor-pointer hover:bg-gray-50">
                    <input type="radio" name="payment_method" value="truewallet"
                           <?= ($old['payment_method'] ?? '') === 'truewallet' ? 'checked' : '' ?>
                           class="text-brand-600 focus:ring-brand-500">
                    <div>
                        <span class="font-medium">TrueMoney Wallet</span>
                        <p class="text-xs text-gray-500">ใช้ลิงก์ซองของขวัญมูลค่า 100 บาท</p>
                    </div>
                </label>
            </div>
        </div>

        <button type="submit"
                class="w-full bg-brand-600 text-white py-3 rounded-lg font-semibold hover:bg-brand-700 transition-colors">
            ดำเนินการชำระเงิน — <?= money_thai(config_int('PRODUCT_PRICE_SATANG', 10000)) ?>
        </button>
    </form>

    <p class="text-xs text-gray-500 text-center mt-4">
        การซื้อครั้งนี้เป็นการยืนยันว่าคุณยอมรับข้อกำหนดการใช้งานฟอนต์ตามลิขสิทธิ์ที่ระบุ
    </p>
</section>
<?php page_footer(); ?>
