<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';

// --- Gather stats + font names straight from the catalog (no previews) ---
$rows = storage_read('fonts.json');

$totalFiles = 0;
$byExt = [];
$supportedNames = [];
$totalBytes = 0;

foreach ($rows as $row) {
    if (!($row['is_active'] ?? false)) {
        continue;
    }
    $totalFiles++;
    $ext = strtolower((string) ($row['extension'] ?? ''));
    $byExt[$ext] = ($byExt[$ext] ?? 0) + 1;

    if (in_array($ext, ['ttf', 'otf'], true)) {
        $totalBytes += (int) ($row['file_size'] ?? 0);
        $displayName = trim((string) ($row['display_name'] ?? ''));
        $supportedNames[] = $displayName !== '' ? $displayName : (string) ($row['file_name'] ?? '');
    }
}

usort($supportedNames, 'strnatcasecmp');

$ttfCount = $byExt['ttf'] ?? 0;
$otfCount = $byExt['otf'] ?? 0;
$ttcCount = $byExt['ttc'] ?? 0;
$fonCount = $byExt['fon'] ?? 0;
$packCount = count($supportedNames);
$totalSizeMB = $totalBytes / 1048576;

$zipPath = (string) config('PRODUCT_ZIP_PATH', '');
$zipSizeMB = is_file($zipPath) ? filesize($zipPath) / 1048576 : null;

$fontListText = implode("\n", $supportedNames);

page_header('ชุดฟอนต์ไทยและอังกฤษ 100 บาท');
?>
<!-- Hero -->
<section class="relative overflow-hidden">
    <div class="absolute inset-0 bg-gradient-to-br from-brand-50 via-white to-white"></div>
    <div class="relative max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-16 sm:py-24 text-center">
        <span class="inline-flex items-center gap-2 bg-brand-600 text-white text-sm font-semibold px-4 py-1.5 rounded-full mb-6">
            🎨 ชุดฟอนต์ไทยและอังกฤษ &middot; ราคาเดียว 100 บาท
        </span>
        <h1 class="text-4xl sm:text-5xl font-extrabold text-gray-900 leading-tight mb-4">
            ฟอนต์กว่า <?= number_format($packCount) ?> ตัว
            <span class="text-brand-600">ในชุดเดียว</span>
        </h1>
        <p class="text-lg sm:text-xl text-gray-600 mb-8">
            ซื้อครั้งเดียว รับไฟล์ฟอนต์ TTF/OTF ครบชุดทันที ใช้ได้กับทุกโปรแกรม
            ทั้ง Windows, macOS และ Linux — เหมาะกับงานดีไซน์ งานพิมพ์ และงานคอนเทนต์
        </p>
        <div class="flex flex-col sm:flex-row items-center justify-center gap-3">
            <a href="<?= e(url('/checkout.php')) ?>"
               class="w-full sm:w-auto inline-flex items-center justify-center gap-2 bg-brand-600 text-white px-8 py-4 rounded-xl font-bold text-lg shadow-lg shadow-brand-600/25 hover:bg-brand-700 hover:shadow-brand-700/25 transition-all">
                ซื้อเลย - 100 บาท
            </a>
            <a href="#font-list"
               class="w-full sm:w-auto inline-flex items-center justify-center gap-2 bg-white text-gray-700 px-8 py-4 rounded-xl font-semibold border border-gray-300 hover:bg-gray-50 transition-colors">
                ดูรายชื่อฟอนต์ทั้งหมด
            </a>
        </div>
    </div>
</section>

<!-- Stats -->
<section class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 -mt-6 pb-4">
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5 text-center">
            <p class="text-3xl font-extrabold text-brand-600"><?= number_format($packCount) ?></p>
            <p class="text-sm text-gray-500 mt-1">ฟอนต์ในชุด (TTF/OTF)</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5 text-center">
            <p class="text-3xl font-extrabold text-brand-600"><?= number_format($ttfCount) ?></p>
            <p class="text-sm text-gray-500 mt-1">ไฟล์ TTF</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5 text-center">
            <p class="text-3xl font-extrabold text-brand-600"><?= number_format($otfCount) ?></p>
            <p class="text-sm text-gray-500 mt-1">ไฟล์ OTF</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5 text-center">
            <p class="text-3xl font-extrabold text-brand-600"><?= $zipSizeMB !== null ? '~' . number_format($zipSizeMB) . ' MB' : '~' . number_format($totalSizeMB) . ' MB' ?></p>
            <p class="text-sm text-gray-500 mt-1">ขนาดไฟล์ดาวน์โหลด</p>
        </div>
    </div>
    <p class="text-center text-xs text-gray-500 mt-3">
        ระบบสแกนพบไฟล์ฟอนต์ทั้งหมด <?= number_format($totalFiles) ?> ไฟล์ — ในชุดขายรวมเฉพาะ TTF/OTF จำนวน <?= number_format($packCount) ?> ไฟล์
        <?php if ($ttcCount + $fonCount > 0): ?>
            (ไม่รวม TTC <?= number_format($ttcCount) ?> / FON <?= number_format($fonCount) ?> ที่ไม่รองรับการใช้งานทั่วไป)
        <?php endif; ?>
    </p>
</section>

<!-- What's included -->
<section class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
    <h2 class="text-2xl sm:text-3xl font-bold text-gray-900 text-center mb-8">ในชุดนี้มีอะไรบ้าง?</h2>
    <div class="grid sm:grid-cols-2 gap-4">
        <div class="flex items-start gap-3 bg-white rounded-xl border border-gray-200 p-5">
            <span class="text-2xl">📁</span>
            <div>
                <p class="font-semibold text-gray-900">ฟอนต์ครบทุกสไตล์</p>
                <p class="text-sm text-gray-600 mt-1">Sans, Serif, Display, Handwriting, Monospace และอื่นๆ อีกมากมาย — ทั้งฟอนต์ไทยและอังกฤษในชุดเดียว</p>
            </div>
        </div>
        <div class="flex items-start gap-3 bg-white rounded-xl border border-gray-200 p-5">
            <span class="text-2xl">🖥️</span>
            <div>
                <p class="font-semibold text-gray-900">ใช้ได้กับทุกแพลตฟอร์ม</p>
                <p class="text-sm text-gray-600 mt-1">ไฟล์ TTF/OTF มาตรฐาน ใช้ได้กับ Windows, macOS, Linux, Word, Photoshop, Illustrator, Canva และโปรแกรมอื่นๆ</p>
            </div>
        </div>
        <div class="flex items-start gap-3 bg-white rounded-xl border border-gray-200 p-5">
            <span class="text-2xl">⚡</span>
            <div>
                <p class="font-semibold text-gray-900">รับไฟล์ทันที</p>
                <p class="text-sm text-gray-600 mt-1">ชำระเงินเสร็จระบบยืนยันยอดแล้ว รับลิงก์ดาวน์โหลด ZIP ทันที ไม่ต้องรอ</p>
            </div>
        </div>
        <div class="flex items-start gap-3 bg-white rounded-xl border border-gray-200 p-5">
            <span class="text-2xl">💵</span>
            <div>
                <p class="font-semibold text-gray-900">จ่ายครั้งเดียวจบ</p>
                <p class="text-sm text-gray-600 mt-1">ราคา 100 บาท เท่านั้น ไม่มีค่าใช้จ่ายแอบแฝง ดาวน์โหลดซ้ำได้ 3 ครั้งภายใน 24 ชั่วโมง</p>
            </div>
        </div>
    </div>
</section>

<!-- How it works -->
<section class="bg-white border-y border-gray-200 py-12">
    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
        <h2 class="text-2xl sm:text-3xl font-bold text-gray-900 text-center mb-8">วิธีสั่งซื้อง่ายๆ 3 ขั้นตอน</h2>
        <div class="grid sm:grid-cols-3 gap-6">
            <div class="text-center">
                <div class="w-12 h-12 mx-auto rounded-full bg-brand-600 text-white font-bold text-lg flex items-center justify-center mb-3">1</div>
                <p class="font-semibold text-gray-900 mb-1">กดปุ่มซื้อเลย</p>
                <p class="text-sm text-gray-600">กรอกอีเมลสำหรับรับลิงก์ดาวน์โหลด</p>
            </div>
            <div class="text-center">
                <div class="w-12 h-12 mx-auto rounded-full bg-brand-600 text-white font-bold text-lg flex items-center justify-center mb-3">2</div>
                <p class="font-semibold text-gray-900 mb-1">ชำระเงิน 100 บาท</p>
                <p class="text-sm text-gray-600">ผ่าน PromptPay QR หรือ TrueMoney Wallet</p>
            </div>
            <div class="text-center">
                <div class="w-12 h-12 mx-auto rounded-full bg-brand-600 text-white font-bold text-lg flex items-center justify-center mb-3">3</div>
                <p class="font-semibold text-gray-900 mb-1">ดาวน์โหลดทันที</p>
                <p class="text-sm text-gray-600">ระบบยืนยันยอดแล้วปลดล็อก ZIP ให้ทันที</p>
            </div>
        </div>
    </div>
</section>

<!-- Font name list (plain text) -->
<section id="font-list" class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-3">
        <div>
            <h2 class="text-2xl sm:text-3xl font-bold text-gray-900">รายชื่อฟอนต์ทั้งหมด</h2>
            <p id="fontCountHint" class="text-sm text-gray-600 mt-1">
                รวม <?= number_format($packCount) ?> ฟอนต์ (TTF <?= number_format($ttfCount) ?> + OTF <?= number_format($otfCount) ?>) เรียงตามตัวอักษร
            </p>
        </div>
        <button id="copyBtn" type="button" onclick="copyFontList()"
                class="inline-flex items-center gap-1.5 bg-white border border-gray-300 text-sm font-medium text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-50 transition-colors">
            📋 คัดลอกชื่อทั้งหมด
        </button>
    </div>
    <div class="mb-3">
        <div class="relative">
            <input id="fontSearch" type="search" maxlength="100" autocomplete="off"
                   placeholder="🔍 ค้นหาชื่อฟอนต์ เช่น Sarabun, Angsana, ThaiSans ..."
                   class="w-full bg-white border border-gray-300 rounded-lg pl-4 pr-12 py-2.5 text-sm shadow-sm placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-brand-500 transition-colors">
            <span id="searchSpinner" class="hidden absolute right-3 top-1/2 -translate-y-1/2">
                <svg class="animate-spin h-4 w-4 text-brand-600" viewBox="0 0 24 24" fill="none">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                </svg>
            </span>
            <button id="searchClear" type="button"
                    class="hidden absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 text-2xl leading-none"
                    title="ล้างการค้นหา" aria-label="ล้างการค้นหา">&times;</button>
        </div>
        <p id="searchStatus" class="text-xs mt-1.5 hidden"></p>
    </div>
    <details open class="bg-white rounded-xl border border-gray-200 shadow-sm">
        <summary class="cursor-pointer text-sm font-medium text-gray-700 px-5 py-4 select-none hover:bg-gray-50 rounded-t-xl">
            แสดง / ซ่อนรายชื่อ (<span id="fontSummaryCount"><?= number_format($packCount) ?></span> ชื่อ)
        </summary>
        <pre id="fontNames" class="max-h-[28rem] overflow-auto px-5 py-4 text-sm leading-6 text-gray-700 whitespace-pre font-mono"><?= e($fontListText) ?></pre>
    </details>
</section>

<!-- FAQ -->
<section class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <h2 class="text-2xl sm:text-3xl font-bold text-gray-900 text-center mb-8">คำถามที่พบบ่อย</h2>
    <div class="space-y-3">
        <details class="bg-white rounded-xl border border-gray-200">
            <summary class="cursor-pointer font-medium text-gray-900 px-5 py-4 select-none hover:bg-gray-50 rounded-xl">ฟอนต์เหล่านี้ถูกต้องตามลิขสิทธิ์หรือไม่?</summary>
            <p class="px-5 pb-4 text-sm text-gray-600">ฟอนต์มาจากชุดฟอนต์มาตรฐานที่ติดมากับ Windows กรุณาตรวจสอบสิทธิ์การใช้งานของแต่ละฟอนต์ก่อนนำไปใช้เชิงพาณิชย์ (มีไฟล์ README อธิบายแนบมาใน ZIP)</p>
        </details>
        <details class="bg-white rounded-xl border border-gray-200">
            <summary class="cursor-pointer font-medium text-gray-900 px-5 py-4 select-none hover:bg-gray-50 rounded-xl">ได้ไฟล์รูปแบบอะไรบ้าง?</summary>
            <p class="px-5 pb-4 text-sm text-gray-600">ไฟล์ TTF และ OTF ซึ่งเป็นรูปแบบมาตรฐาน ใช้ได้กับทุกแพลตฟอร์มและทุกโปรแกรมออกแบบ</p>
        </details>
        <details class="bg-white rounded-xl border border-gray-200">
            <summary class="cursor-pointer font-medium text-gray-900 px-5 py-4 select-none hover:bg-gray-50 rounded-xl">ดาวน์โหลดได้กี่ครั้ง?</summary>
            <p class="px-5 pb-4 text-sm text-gray-600">ดาวน์โหลดได้ 3 ครั้งภายใน 24 ชั่วโมงหลังชำระเงินสำเร็จ</p>
        </details>
        <details class="bg-white rounded-xl border border-gray-200">
            <summary class="cursor-pointer font-medium text-gray-900 px-5 py-4 select-none hover:bg-gray-50 rounded-xl">จ่ายเงินแบบไหนได้บ้าง?</summary>
            <p class="px-5 pb-4 text-sm text-gray-600">PromptPay ผ่าน QR Code หรือ TrueMoney Wallet ยอด 100 บาทพอดี</p>
        </details>
    </div>
</section>

<!-- Final CTA -->
<section class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
    <div class="text-center bg-gradient-to-br from-brand-600 to-brand-700 rounded-2xl p-10 sm:p-14 text-white shadow-xl shadow-brand-600/25">
        <h2 class="text-2xl sm:text-3xl font-extrabold mb-3">พร้อมรับฟอนต์ครบชุดแล้วหรือยัง?</h2>
        <p class="text-brand-100 mb-6"><?= number_format($packCount) ?> ฟอนต์ &middot; TTF + OTF &middot; 100 บาท จ่ายครั้งเดียวรับทันที</p>
        <a href="<?= e(url('/checkout.php')) ?>"
           class="inline-flex items-center gap-2 bg-white text-brand-700 px-10 py-4 rounded-xl font-bold text-lg shadow-lg hover:bg-brand-50 transition-colors">
            ซื้อเลย - 100 บาท
        </a>
    </div>
</section>

<script>
function fallbackCopy(text, done) {
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select();
    try { document.execCommand('copy'); done(); } catch (e) { /* ignore */ }
    ta.remove();
}
function copyFontList() {
    var text = document.getElementById('fontNames').innerText;
    var btn = document.getElementById('copyBtn');
    var done = function () {
        btn.textContent = 'คัดลอกแล้ว ✓';
        setTimeout(function () { btn.textContent = '📋 คัดลอกชื่อทั้งหมด'; }, 2000);
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(done).catch(function () { fallbackCopy(text, done); });
    } else {
        fallbackCopy(text, done);
    }
}

// --- Live font name search via AJAX (GET /api/fonts.php?q=...) ---
(function () {
    var input = document.getElementById('fontSearch');
    var pre = document.getElementById('fontNames');
    var countHint = document.getElementById('fontCountHint');
    var summaryCount = document.getElementById('fontSummaryCount');
    var status = document.getElementById('searchStatus');
    var spinner = document.getElementById('searchSpinner');
    var clearBtn = document.getElementById('searchClear');
    if (!input || !pre) return;

    var apiUrl = <?= json_encode(url('/api/fonts.php')) ?>;
    var fullList = pre.innerText;
    var fullCountHint = countHint ? countHint.textContent.trim() : '';
    var fullSummaryCount = summaryCount ? summaryCount.textContent : '';

    var debounceTimer = null;
    var activeController = null;

    function showStatus(msg, isError) {
        if (!status) return;
        if (msg === null) {
            status.textContent = '';
            status.className = 'text-xs mt-1.5 hidden';
            return;
        }
        status.textContent = msg;
        status.className = 'text-xs mt-1.5 ' + (isError ? 'text-red-600' : 'text-gray-500');
    }

    function doSearch(q) {
        if (activeController) { activeController.abort(); }

        // Empty query -> restore the full server-rendered list, no AJAX needed.
        if (q === '') {
            pre.textContent = fullList;
            if (countHint) countHint.textContent = fullCountHint;
            if (summaryCount) summaryCount.textContent = fullSummaryCount;
            clearBtn.classList.add('hidden');
            spinner.classList.add('hidden');
            showStatus(null);
            return;
        }

        spinner.classList.remove('hidden');
        var controller = new AbortController();
        activeController = controller;

        fetch(apiUrl + '?q=' + encodeURIComponent(q), { signal: controller.signal })
            .then(function (res) {
                if (!res.ok) { throw new Error('HTTP ' + res.status); }
                return res.json();
            })
            .then(function (data) {
                if (activeController !== controller) return;
                var names = Array.isArray(data.names) ? data.names : [];
                var total = Number(data.total) || 0;

                pre.textContent = names.length > 0
                    ? names.join('\n')
                    : 'ไม่พบฟอนต์ที่ตรงกับ "' + q + '"';

                if (summaryCount) summaryCount.textContent = total.toLocaleString('th-TH');

                if (countHint) {
                    if (total === 0) {
                        countHint.textContent = 'ไม่พบฟอนต์ที่ตรงกับ "' + q + '" — ลองคำค้นอื่น หรือกด “×” เพื่อแสดงทั้งหมด';
                    } else {
                        countHint.textContent = 'พบ ' + total.toLocaleString('th-TH') + ' รายการที่ตรงกับ "' + q + '"'
                            + (data.truncated ? ' (แสดง ' + names.length + ' รายการแรก)' : '');
                    }
                }
                showStatus(null);
            })
            .catch(function (err) {
                if (err.name === 'AbortError') return;
                if (activeController !== controller) return;
                showStatus('เกิดข้อผิดพลาดในการค้นหา กรุณาลองใหม่อีกครั้ง', true);
            })
            .finally(function () {
                if (activeController === controller) { activeController = null; }
                spinner.classList.add('hidden');
            });
    }

    input.addEventListener('input', function () {
        clearBtn.classList.toggle('hidden', input.value === '');
        clearTimeout(debounceTimer);
        var q = input.value.trim();
        debounceTimer = setTimeout(function () { doSearch(q); }, 300);
    });

    clearBtn.addEventListener('click', function () {
        input.value = '';
        clearTimeout(debounceTimer);
        doSearch('');
        input.focus();
    });
})();
</script>
<?php page_footer(); ?>
