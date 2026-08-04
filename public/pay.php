<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';

site_session_start();

$publicId = $_GET['id'] ?? '';
if ($publicId === '' || ($_SESSION['order_public_id'] ?? '') !== $publicId) {
    error_page(404, 'ไม่พบคำสั่งซื้อ');
}

$order = order_by_public_id($publicId);
if ($order === null || !in_array($order['status'], ['pending', 'paid'], true)) {
    error_page(404, 'คำสั่งซื้อไม่พร้อมใช้งาน');
}
if ($order['status'] === 'paid') {
    redirect(url('/success.php?id=' . $publicId));
}

$method = $order['payment_method'];

if ($method === 'promptpay') {
    try {
        $qr = payment_start_promptpay($publicId);
    } catch (RuntimeException $ex) {
        error_page(502, $ex->getMessage());
    } catch (InvalidArgumentException $ex) {
        error_page(404, $ex->getMessage());
    }

    $expiresTs = strtotime($qr['expiresAt']);

    page_header('ชำระเงินด้วย PromptPay');
    ?>
    <section class="max-w-lg mx-auto px-4 py-8">
        <h1 class="text-2xl font-bold text-gray-900 mb-2">ชำระเงินด้วย PromptPay</h1>
        <p class="text-sm text-gray-600 mb-6">สแกน QR ด้านล่างเพื่อชำระ <strong><?= money_thai((int) ($qr['amountSatang'] ?? config_int('PRODUCT_PRICE_SATANG', 10000))) ?></strong><?php
            $qrAmount = (int) ($qr['amountSatang'] ?? 0);
            if ($qrAmount > 0 && $qrAmount !== config_int('PRODUCT_PRICE_SATANG', 10000)): ?>
            <span class="text-xs text-gray-400"> (รวมค่าธรรมเนียมช่องทางชำระเงินแล้ว)</span>
        <?php endif; ?></p>

        <div class="bg-white rounded-lg border border-gray-200 p-6 text-center mb-6">
            <?php if (!empty($qr['qrUrl'])): ?>
                <img src="<?= e($qr['qrUrl']) ?>" alt="PromptPay QR Code" class="mx-auto mb-3" style="max-width:300px">
            <?php else: ?>
                <div class="w-48 h-48 bg-gray-100 mx-auto mb-3 flex items-center justify-center rounded-lg">
                    <span class="text-gray-500 text-sm">กำลังโหลด QR...</span>
                </div>
            <?php endif; ?>

            <p class="text-xs text-gray-500 bg-gray-50 border border-gray-200 rounded-full px-3 py-1 inline-block mb-4">
                ℹ️ มีค่าบริการช่องทางชำระเงิน 0.1–0.99 บาท/ครั้ง (รวมอยู่ในยอด QR แล้ว)
            </p>

            <?php if ($expiresTs): ?>
                <p class="text-sm text-gray-600">
                    หมดอายุใน <span id="countdown" data-expires="<?= $expiresTs ?>" class="font-mono font-medium">--:--</span>
                </p>
            <?php endif; ?>
        </div>

        <div id="paymentStatus" class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 text-center mb-4">
            <div class="flex items-center justify-center gap-2">
                <svg class="animate-spin h-5 w-5 text-yellow-600" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                </svg>
                <span id="statusText" class="font-medium text-yellow-800" aria-live="polite">กำลังรอการชำระเงิน...</span>
            </div>
        </div>

        <div class="text-center">
            <p class="text-xs text-gray-500 mb-4">รหัสธุรกรรม: <?= e($qr['transactionId']) ?></p>
            <a href="<?= e(url('/')) ?>" class="text-sm text-brand-600 hover:underline">ยกเลิกและกลับไปหน้าหลัก</a>
        </div>
    </section>

    <script>
    (function () {
        var baseUrl = <?= json_encode(url('/')) ?>;
        var publicId = <?= json_encode($publicId) ?>;
        var expiresTs = <?= (int) $expiresTs ?>;
        var countdownEl = document.getElementById('countdown');
        var statusBox = document.getElementById('paymentStatus');
        var statusText = document.getElementById('statusText');
        var pollTimer = null;

        function tick() {
            if (!countdownEl) return;
            var diff = Math.max(0, expiresTs - Math.floor(Date.now() / 1000));
            if (diff <= 0) {
                countdownEl.textContent = 'หมดอายุ';
                clearInterval(pollTimer);
                statusBox.className = 'bg-red-50 border border-red-200 rounded-lg p-4 text-center mb-4';
                statusText.textContent = 'การชำระเงินหมดอายุ กรุณาสั่งซื้อใหม่';
                statusText.className = 'font-medium text-red-800';
                return;
            }
            var m = Math.floor(diff / 60);
            var s = String(diff % 60).padStart(2, '0');
            countdownEl.textContent = m + ':' + s;
        }

        function poll() {
            fetch(baseUrl + 'api/status.php?id=' + encodeURIComponent(publicId))
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.status === 'paid') {
                        statusBox.className = 'bg-green-50 border border-green-200 rounded-lg p-4 text-center mb-4';
                        statusText.textContent = 'ชำระเงินสำเร็จ! กำลังไปหน้าดาวน์โหลด...';
                        statusText.className = 'font-medium text-green-800';
                        clearInterval(pollTimer);
                        window.location.href = baseUrl + 'success.php?id=' + encodeURIComponent(publicId);
                    } else if (data.status === 'failed' || data.status === 'expired') {
                        statusBox.className = 'bg-red-50 border border-red-200 rounded-lg p-4 text-center mb-4';
                        statusText.textContent = data.status === 'expired' ? 'การชำระเงินหมดอายุ' : 'การชำระเงินไม่สำเร็จ';
                        statusText.className = 'font-medium text-red-800';
                        clearInterval(pollTimer);
                    }
                })
                .catch(function () {});
        }

        tick();
        setInterval(tick, 1000);
        pollTimer = setInterval(poll, 5000);
    })();
    </script>
    <?php
} else { // truewallet
    page_header('ชำระเงินด้วย TrueMoney');
    ?>
    <section class="max-w-lg mx-auto px-4 py-8">
        <h1 class="text-2xl font-bold text-gray-900 mb-2">ชำระเงินด้วย TrueMoney Wallet</h1>
        <p class="text-sm text-gray-600 mb-6">วางลิงก์ซองของขวัญ <strong>100 บาท</strong> ของคุณ</p>

        <div class="bg-amber-50 border border-amber-200 rounded-lg p-4 mb-6">
            <div class="flex gap-3">
                <div class="text-sm text-amber-800">
                    <p class="font-semibold mb-1">ข้อควรทราบ</p>
                    <p>รับเฉพาะซองของขวัญมูลค่า 100 บาทเท่านั้น ระบบอาจแลกซองแล้วก่อนทราบยอด หากยอดไม่ตรงจะไม่ได้รับไฟล์และไม่มีเงินทอน</p>
                    <p class="mt-2">Only accepts 100 THB vouchers exactly. The system may redeem the voucher before checking the amount. If the amount does not match, you will not receive the file and there is no change.</p>
                </div>
            </div>
        </div>

        <form id="truewalletForm" class="space-y-4">
            <?= csrf_field() ?>

            <div>
                <label for="voucher_url" class="block text-sm font-medium text-gray-700 mb-1">ลิงก์ซองของขวัญ</label>
                <input type="url" id="voucher_url" name="voucher_url" required
                       placeholder="https://gift.truemoney.com/campaign/?v=..."
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-500 focus:border-brand-500 text-sm">
            </div>

            <label class="flex items-start gap-2">
                <input type="checkbox" id="confirmCheckbox" class="mt-1 text-brand-600 focus:ring-brand-500" required>
                <span class="text-sm text-gray-600">ฉันยอมรับว่าต้องใช้ซองของขวัญมูลค่า 100 บาทเท่านั้น และจะไม่มีเงินทอนหากยอดไม่ตรง</span>
            </label>

            <div id="formError" class="hidden bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg text-sm"></div>
            <div id="formSuccess" class="hidden bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg text-sm"></div>

            <button type="submit" id="submitBtn"
                    class="w-full bg-brand-600 text-white py-3 rounded-lg font-semibold hover:bg-brand-700 transition-colors disabled:opacity-50 disabled:cursor-not-allowed">
                แลกซองของขวัญ
            </button>
        </form>

        <div class="text-center mt-4">
            <a href="<?= e(url('/')) ?>" class="text-sm text-brand-600 hover:underline">ยกเลิกและกลับไปหน้าหลัก</a>
        </div>
    </section>

    <script>
    (function () {
        var baseUrl = <?= json_encode(url('/')) ?>;
        var form = document.getElementById('truewalletForm');
        var submitBtn = document.getElementById('submitBtn');
        var errorEl = document.getElementById('formError');
        var successEl = document.getElementById('formSuccess');
        var csrfToken = <?= json_encode(csrf_token()) ?>;
        var publicId = <?= json_encode($publicId) ?>;
        var processing = false;

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            if (processing) return;

            var voucherUrl = document.getElementById('voucher_url').value.trim();
            if (!document.getElementById('confirmCheckbox').checked) {
                errorEl.textContent = 'กรุณายืนยันว่าคุณเข้าใจเงื่อนไขก่อน';
                errorEl.classList.remove('hidden');
                return;
            }

            processing = true;
            submitBtn.disabled = true;
            submitBtn.textContent = 'กำลังดำเนินการ...';
            errorEl.classList.add('hidden');
            successEl.classList.add('hidden');

            fetch(baseUrl + 'api/truemoney.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    id: publicId,
                    voucher_url: voucherUrl,
                    _csrf_token: csrfToken
                })
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.paid) {
                    successEl.textContent = 'ชำระเงินสำเร็จ! กำลังไปหน้าดาวน์โหลด...';
                    successEl.classList.remove('hidden');
                    setTimeout(function () {
                        window.location.href = baseUrl + 'success.php?id=' + encodeURIComponent(publicId);
                    }, 1500);
                } else if (data.error) {
                    errorEl.textContent = data.error;
                    errorEl.classList.remove('hidden');
                    processing = false;
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'แลกซองของขวัญ';
                } else {
                    errorEl.textContent = 'ไม่สามารถยืนยันการชำระเงินได้ กรุณาตรวจสอบซองของขวัญและลองใหม่';
                    errorEl.classList.remove('hidden');
                    processing = false;
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'แลกซองของขวัญ';
                }
            })
            .catch(function () {
                errorEl.textContent = 'เกิดข้อผิดพลาดเครือข่าย กรุณาลองใหม่';
                errorEl.classList.remove('hidden');
                processing = false;
                submitBtn.disabled = false;
                submitBtn.textContent = 'แลกซองของขวัญ';
            });
        });
    })();
    </script>
    <?php
}

page_footer();
