/**
 * FontSeller — app (views + boot). JavaScript port of the PHP store.
 */
'use strict';

/* ------------------------------------------------------------------ */
/* Small UI helpers                                                    */
/* ------------------------------------------------------------------ */

function el(html) {
    const t = document.createElement('template');
    t.innerHTML = html.trim();
    return t.content.firstElementChild;
}

function setView(html) {
    const view = document.getElementById('view');
    view.innerHTML = html;
    view.scrollTop = 0;
    window.scrollTo(0, 0);
}

function fmtDate(iso) {
    if (!iso) return '';
    const d = parseUtc(iso);
    if (!d) return '';
    return d.toLocaleString('th-TH', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

/* ------------------------------------------------------------------ */
/* HOME view                                                           */
/* ------------------------------------------------------------------ */

async function renderHome() {
    const s = Fonts.stats;
    const statCards = `
        <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5 text-center">
            <p class="text-3xl font-extrabold text-brand-600">${formatCount(s.packCount)}</p>
            <p class="text-sm text-gray-500 mt-1">ฟอนต์ในชุด (TTF/OTF)</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5 text-center">
            <p class="text-3xl font-extrabold text-brand-600">${formatCount(s.ttf)}</p>
            <p class="text-sm text-gray-500 mt-1">ไฟล์ TTF</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5 text-center">
            <p class="text-3xl font-extrabold text-brand-600">${formatCount(s.otf)}</p>
            <p class="text-sm text-gray-500 mt-1">ไฟล์ OTF</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5 text-center">
            <p class="text-3xl font-extrabold text-brand-600">~${(s.totalSizeMB / 1048576).toFixed(0)} MB</p>
            <p class="text-sm text-gray-500 mt-1">ขนาดไฟล์ดาวน์โหลด</p>
        </div>`;

    setView(`
    <!-- Hero -->
    <section class="relative overflow-hidden">
        <div class="absolute inset-0 bg-gradient-to-br from-brand-50 via-white to-white"></div>
        <div class="relative max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-16 sm:py-24 text-center">
            <span class="inline-flex items-center gap-2 bg-brand-600 text-white text-sm font-semibold px-4 py-1.5 rounded-full mb-6">
                🎨 ชุดฟอนต์ไทยและอังกฤษ &middot; ราคาเดียว ${CONFIG.priceThb} บาท
            </span>
            <h1 class="text-4xl sm:text-5xl font-extrabold text-gray-900 leading-tight mb-4">
                ฟอนต์กว่า ${formatCount(s.packCount)} ตัว
                <span class="text-brand-600">ในชุดเดียว</span>
            </h1>
            <p class="text-lg sm:text-xl text-gray-600 mb-8">
                ซื้อครั้งเดียว รับไฟล์ฟอนต์ TTF/OTF ครบชุดทันที ใช้ได้กับทุกโปรแกรม
                ทั้ง Windows, macOS และ Linux — เหมาะกับงานดีไซน์ งานพิมพ์ และงานคอนเทนต์
            </p>
            <div class="flex flex-col sm:flex-row items-center justify-center gap-3">
                <a href="#/checkout"
                   class="w-full sm:w-auto inline-flex items-center justify-center gap-2 bg-brand-600 text-white px-8 py-4 rounded-xl font-bold text-lg shadow-lg shadow-brand-600/25 hover:bg-brand-700 hover:shadow-brand-700/25 transition-all">
                    ซื้อเลย - 100 บาท
                </a>
                <a href="#preview"
                   class="w-full sm:w-auto inline-flex items-center justify-center gap-2 bg-white text-gray-700 px-8 py-4 rounded-xl font-semibold border border-gray-300 hover:bg-gray-50 transition-colors">
                    ทดลองดูตัวอย่างฟอนต์
                </a>
            </div>
        </div>
    </section>

    <!-- Stats -->
    <section class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 -mt-6 pb-4">
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">${statCards}</div>
        <p class="text-center text-xs text-gray-500 mt-3">
            ระบบสแกนพบไฟล์ฟอนต์ทั้งหมด ${formatCount(s.totalFiles)} ไฟล์ — ในชุดขายรวมเฉพาะ TTF/OTF จำนวน ${formatCount(s.packCount)} ไฟล์
            (ไม่รวม TTC ${formatCount(s.ttc)} / FON ${formatCount(s.fon)} ที่ไม่รองรับการใช้งานทั่วไป)
        </p>
    </section>

    <!-- Live preview (sample fonts) -->
    <section id="preview" class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
        <h2 class="text-2xl sm:text-3xl font-bold text-gray-900 text-center mb-2">ลองพิมพ์ดูตัวอย่างฟอนต์</h2>
        <p class="text-center text-sm text-gray-500 mb-6">ตัวอย่างฟอนต์ลิขสิทธิ์ฟรี 9 แบบ — ชุดเต็ม 3,406 ฟอนต์ใช้ได้หลังชำระเงิน</p>
        <div class="mb-5">
            <input id="previewText" type="text" maxlength="${CONFIG.previewMaxText}" value="สวัสดี FontSeller 123"
                   class="w-full bg-white border border-gray-300 rounded-xl px-4 py-3 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-brand-500">
        </div>
        <div id="previewGrid" class="grid sm:grid-cols-2 gap-4"></div>
        <p id="previewLoading" class="text-center text-sm text-gray-400">กำลังโหลดฟอนต์ตัวอย่าง...</p>
    </section>

    <!-- What's included -->
    <section class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
        <h2 class="text-2xl sm:text-3xl font-bold text-gray-900 text-center mb-8">ในชุดนี้มีอะไรบ้าง?</h2>
        <div class="grid sm:grid-cols-2 gap-4">
            <div class="flex items-start gap-3 bg-white rounded-xl border border-gray-200 p-5">
                <span class="text-2xl">📁</span>
                <div><p class="font-semibold text-gray-900">ฟอนต์ครบทุกสไตล์</p>
                <p class="text-sm text-gray-600 mt-1">Sans, Serif, Display, Handwriting, Monospace และอื่นๆ อีกมากมาย — ทั้งฟอนต์ไทยและอังกฤษในชุดเดียว</p></div>
            </div>
            <div class="flex items-start gap-3 bg-white rounded-xl border border-gray-200 p-5">
                <span class="text-2xl">🖥️</span>
                <div><p class="font-semibold text-gray-900">ใช้ได้กับทุกแพลตฟอร์ม</p>
                <p class="text-sm text-gray-600 mt-1">ไฟล์ TTF/OTF มาตรฐาน ใช้ได้กับ Windows, macOS, Linux, Word, Photoshop, Illustrator, Canva และโปรแกรมอื่นๆ</p></div>
            </div>
            <div class="flex items-start gap-3 bg-white rounded-xl border border-gray-200 p-5">
                <span class="text-2xl">⚡</span>
                <div><p class="font-semibold text-gray-900">รับไฟล์ทันที</p>
                <p class="text-sm text-gray-600 mt-1">ชำระเงินเสร็จระบบยืนยันยอดแล้ว รับลิงก์ดาวน์โหลด ZIP ทันที ไม่ต้องรอ</p></div>
            </div>
            <div class="flex items-start gap-3 bg-white rounded-xl border border-gray-200 p-5">
                <span class="text-2xl">💵</span>
                <div><p class="font-semibold text-gray-900">จ่ายครั้งเดียวจบ</p>
                <p class="text-sm text-gray-600 mt-1">ราคา 100 บาท เท่านั้น ไม่มีค่าใช้จ่ายแอบแฝง ดาวน์โหลดซ้ำได้ ${CONFIG.downloadMaxCount} ครั้งภายใน ${CONFIG.downloadTtlHours} ชั่วโมง</p></div>
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

    <!-- Font list -->
    <section id="font-list" class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
        <div class="flex flex-wrap items-end justify-between gap-3 mb-3">
            <div>
                <h2 class="text-2xl sm:text-3xl font-bold text-gray-900">รายชื่อฟอนต์ทั้งหมด</h2>
                <p id="fontCountHint" class="text-sm text-gray-600 mt-1">รวม ${formatCount(s.packCount)} ฟอนต์ (TTF ${formatCount(s.ttf)} + OTF ${formatCount(s.otf)})</p>
            </div>
            <button id="copyBtn" type="button"
                    class="inline-flex items-center gap-1.5 bg-white border border-gray-300 text-sm font-medium text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-50 transition-colors">
                📋 คัดลอกชื่อทั้งหมด
            </button>
        </div>
        <div class="mb-3">
            <div class="relative">
                <input id="fontSearch" type="search" maxlength="100" autocomplete="off"
                       placeholder="🔍 ค้นหาชื่อฟอนต์ เช่น Sarabun, Angsana, ThaiSans ..."
                       class="w-full bg-white border border-gray-300 rounded-lg pl-4 pr-12 py-2.5 text-sm shadow-sm placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-brand-500 transition-colors">
                <button id="searchClear" type="button"
                        class="hidden absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 text-2xl leading-none"
                        title="ล้างการค้นหา" aria-label="ล้างการค้นหา">&times;</button>
            </div>
            <p id="searchStatus" class="text-xs mt-1.5 hidden"></p>
        </div>
        <details open class="bg-white rounded-xl border border-gray-200 shadow-sm">
            <summary class="cursor-pointer text-sm font-medium text-gray-700 px-5 py-4 select-none hover:bg-gray-50 rounded-t-xl">
                แสดง / ซ่อนรายชื่อ (<span id="fontSummaryCount">${formatCount(s.packCount)}</span> ชื่อ)
            </summary>
            <pre id="fontNames" class="max-h-[28rem] overflow-auto px-5 py-4 text-sm leading-6 text-gray-700 whitespace-pre font-mono"></pre>
        </details>
    </section>`);

    bindHomePreview();
    bindHomeFontList();
}

function bindHomePreview() {
    const grid = document.getElementById('previewGrid');
    const input = document.getElementById('previewText');
    const loading = document.getElementById('previewLoading');

    Fonts.loadSampleFonts().then(list => {
        loading.remove();
        for (const def of list) {
            const card = el(`
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
                    <div class="flex items-center justify-between mb-3">
                        <p class="text-sm font-semibold text-gray-900">${escapeHtml(def.name)}</p>
                        <span class="text-[10px] px-2 py-0.5 rounded-full ${def.thai ? 'bg-brand-50 text-brand-700' : 'bg-gray-100 text-gray-500'}">${def.thai ? 'ไทย' : 'Latin'}</span>
                    </div>
                    <p class="text-xl truncate" style="font-family:'${def.family}'">${escapeHtml(input.value)}</p>
                </div>`);
            grid.appendChild(card);
            def._card = card;
        }
        if (list.some(d => !d.ok)) {
            loading.textContent = 'บางฟอนต์โหลดไม่สำเร็จ (แสดงเฉพาะที่โหลดได้)';
            loading.className = 'text-center text-xs text-amber-600';
        }
    });

    input.addEventListener('input', () => {
        const text = escapeHtml(input.value);
        for (const def of Fonts.sampleFonts || []) {
            const p = def._card && def._card.querySelector('p:last-child');
            if (p) p.textContent = input.value;
        }
    });
}

function bindHomeFontList() {
    const ALL = Fonts.stats.supportedNames;
    const pre = document.getElementById('fontNames');
    const input = document.getElementById('fontSearch');
    const clearBtn = document.getElementById('searchClear');
    const status = document.getElementById('searchStatus');
    const summaryCount = document.getElementById('fontSummaryCount');
    const hint = document.getElementById('fontCountHint');

    function render(list) {
        pre.textContent = list.join('\n');
        summaryCount.textContent = formatCount(list.length);
        hint.textContent = 'รวม ' + formatCount(ALL.length) + ' ฟอนต์' +
            (list.length !== ALL.length ? ' — แสดง ' + formatCount(list.length) + ' รายการ' : '');
    }

    function doSearch(q) {
        q = q.trim();
        if (!q) {
            status.classList.add('hidden');
            clearBtn.classList.add('hidden');
            render(ALL);
            return;
        }
        const r = Fonts.searchNames(q, 1000);
        status.classList.remove('hidden');
        if (r.total === 0) {
            status.textContent = 'ไม่พบฟอนต์ที่ตรงกับ "' + q + '"';
            status.className = 'text-xs mt-1.5 text-red-600';
        } else {
            status.textContent = 'พบ ' + formatCount(r.total) + ' ฟอนต์' + (r.truncated ? ' (แสดง ' + formatCount(r.returned) + ' รายการแรก)' : '');
            status.className = 'text-xs mt-1.5 text-gray-500';
        }
        clearBtn.classList.remove('hidden');
        render(r.names);
    }

    input.addEventListener('input', () => doSearch(input.value));
    clearBtn.addEventListener('click', () => { input.value = ''; doSearch(''); input.focus(); });
    document.getElementById('copyBtn').addEventListener('click', () => {
        const text = pre.innerText;
        const btn = document.getElementById('copyBtn');
        const done = () => {
            btn.textContent = 'คัดลอกแล้ว ✓';
            setTimeout(() => { btn.textContent = '📋 คัดลอกชื่อทั้งหมด'; }, 2000);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(done).catch(() => fallbackCopy(text, done));
        } else {
            fallbackCopy(text, done);
        }
    });
    render(ALL);
}

function fallbackCopy(text, done) {
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select();
    try { document.execCommand('copy'); done(); } catch (e) { /* ignore */ }
    ta.remove();
}

/* ------------------------------------------------------------------ */
/* CHECKOUT view                                                       */
/* ------------------------------------------------------------------ */

function renderCheckout() {
    setView(`
    <section class="max-w-lg mx-auto px-4 py-8">
        <h1 class="text-2xl font-bold text-gray-900 mb-6">ชำระเงิน</h1>
        <div class="bg-white rounded-lg border border-gray-200 p-4 mb-6">
            <div class="flex justify-between items-center">
                <span class="font-medium">ชุดฟอนต์ไทยและอังกฤษ</span>
                <span class="font-bold text-brand-700">${moneyTHB(CONFIG.priceSatang)}</span>
            </div>
            <p class="text-xs text-gray-500 mt-1">ฟอนต์ ${formatCount(Fonts.stats.packCount)} ตัว &middot; ดาวน์โหลดได้ ${CONFIG.downloadMaxCount} ครั้งภายใน ${CONFIG.downloadTtlHours} ชั่วโมง</p>
        </div>
        <div id="checkoutError" class="hidden bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-4 text-sm"></div>
        <form id="checkoutForm" class="space-y-4" novalidate>
            <div>
                <label for="customer_name" class="block text-sm font-medium text-gray-700 mb-1">ชื่อ</label>
                <input type="text" id="customer_name" maxlength="120" required
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-500 focus:border-brand-500 text-sm">
            </div>
            <div>
                <label for="customer_email" class="block text-sm font-medium text-gray-700 mb-1">อีเมล</label>
                <input type="email" id="customer_email" maxlength="254" required
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-500 focus:border-brand-500 text-sm">
                <p class="mt-1 text-xs text-gray-500">ใช้สำหรับอ้างอิงคำสั่งซื้อเท่านั้น ไม่มีการส่งอีเมล</p>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">วิธีชำระเงิน</label>
                <div class="space-y-2">
                    <label class="flex items-center gap-3 p-3 border border-gray-300 rounded-lg cursor-pointer hover:bg-gray-50">
                        <input type="radio" name="payment_method" value="promptpay" checked class="text-brand-600 focus:ring-brand-500">
                        <div><span class="font-medium">PromptPay QR</span>
                        <p class="text-xs text-gray-500">สแกน QR เพื่อโอนเงิน</p></div>
                    </label>
                    <label class="flex items-center gap-3 p-3 border border-gray-300 rounded-lg cursor-pointer hover:bg-gray-50">
                        <input type="radio" name="payment_method" value="truewallet" class="text-brand-600 focus:ring-brand-500">
                        <div><span class="font-medium">TrueMoney Wallet</span>
                        <p class="text-xs text-gray-500">ใช้ลิงก์ซองของขวัญมูลค่า 100 บาท</p></div>
                    </label>
                </div>
            </div>
            <button type="submit"
                    class="w-full bg-brand-600 text-white py-3 rounded-lg font-semibold hover:bg-brand-700 transition-colors">
                ดำเนินการชำระเงิน — ${moneyTHB(CONFIG.priceSatang)}
            </button>
        </form>
        <p class="text-xs text-gray-500 text-center mt-4">
            การซื้อครั้งนี้เป็นการยืนยันว่าคุณยอมรับข้อกำหนดการใช้งานฟอนต์ตามลิขสิทธิ์ที่ระบุ
        </p>
    </section>`);

    document.getElementById('checkoutForm').addEventListener('submit', (e) => {
        e.preventDefault();
        const errorEl = document.getElementById('checkoutError');
        const name = document.getElementById('customer_name').value;
        const email = document.getElementById('customer_email').value;
        const method = document.querySelector('input[name="payment_method"]:checked').value;
        try {
            const order = Orders.create(name, email, method);
            Session.set({ order_public_id: order.public_id });
            Router.navigate('pay', { id: order.public_id });
        } catch (err) {
            errorEl.textContent = err.message;
            errorEl.classList.remove('hidden');
        }
    });
}

/* ------------------------------------------------------------------ */
/* PAY view                                                            */
/* ------------------------------------------------------------------ */

function renderPay(params) {
    const publicId = params.id || '';
    const session = Session.get();
    const order = Orders.byPublicId(publicId);
    if (!publicId || !order || session.order_public_id !== publicId) {
        return renderError(404, 'ไม่พบคำสั่งซื้อ');
    }
    const recoverableExpiredPromptpay = order.status === 'expired' &&
        order.payment_method === 'promptpay' && order.provider_transaction_id;
    if (!['pending', 'paid'].includes(order.status) && !recoverableExpiredPromptpay) {
        return renderError(404, 'คำสั่งซื้อไม่พร้อมใช้งาน');
    }
    if (order.status === 'paid') {
        return Router.navigate('success', { id: publicId });
    }

    if (order.payment_method === 'promptpay') {
        renderPayPromptpay(order);
    } else {
        renderPayTruewallet(order);
    }
}

function renderPayPromptpay(order) {
    const publicId = order.public_id;
    setView(`
    <section class="max-w-lg mx-auto px-4 py-8">
        <h1 class="text-2xl font-bold text-gray-900 mb-2">ชำระเงินด้วย PromptPay</h1>
        <p class="text-sm text-gray-600 mb-6">สแกน QR ด้านล่างเพื่อชำระ <strong id="ppAmount">${moneyTHB(order.amount_satang)}</strong></p>
        <div class="bg-white rounded-lg border border-gray-200 p-6 text-center mb-4">
            <div id="qrBox" class="w-72 h-72 mx-auto bg-gray-100 flex items-center justify-center rounded-lg mb-3">
                <span class="text-gray-400 text-sm">กำลังสร้าง QR...</span>
            </div>
            <p class="text-xs text-gray-500 bg-gray-50 border border-gray-200 rounded-full px-3 py-1 inline-block mb-4">
                ℹ️ มีค่าบริการช่องทางชำระเงิน 0.1–0.99 บาท/ครั้ง (รวมอยู่ในยอด QR แล้ว)
            </p>
            <p class="text-sm text-gray-600">หมดอายุใน <span id="countdown" class="font-mono font-medium">--:--</span></p>
        </div>
        <div id="ppStatus" class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 text-center mb-4">
            <div class="flex items-center justify-center gap-2">
                <svg class="animate-spin h-5 w-5 text-yellow-600" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                </svg>
                <span id="ppStatusText" class="font-medium text-yellow-800">กำลังรอการชำระเงิน...</span>
            </div>
        </div>
        <div id="ppDemoBox" class="hidden bg-amber-50 border border-amber-200 rounded-lg p-4 mb-4 text-sm text-amber-800"></div>
        <div class="text-center">
            <p class="text-xs text-gray-500 mb-4">รหัสธุรกรรม: <span id="ppTxn">-</span></p>
            <a href="#/" class="text-sm text-brand-600 hover:underline">ยกเลิกและกลับไปหน้าหลัก</a>
        </div>
    </section>`);

    const countdownEl = document.getElementById('countdown');
    const statusBox = document.getElementById('ppStatus');
    const statusText = document.getElementById('ppStatusText');
    const qrBox = document.getElementById('qrBox');
    let expiresTs = null;
    let pollTimer = null;
    let countdownTimer = null;
    let paymentFinished = false;

    function setStatus(kind, text) {
        const map = {
            ok: ['bg-green-50 border-green-200', 'text-green-800'],
            wait: ['bg-yellow-50 border-yellow-200', 'text-yellow-800'],
            bad: ['bg-red-50 border-red-200', 'text-red-800'],
        };
        const [boxCls, txtCls] = map[kind];
        statusBox.className = boxCls + ' rounded-lg p-4 text-center mb-4 border';
        statusText.textContent = text;
        statusText.className = 'font-medium ' + txtCls;
    }

    function tick() {
        if (!expiresTs) return;
        const diff = Math.max(0, Math.floor((expiresTs - Date.now()) / 1000));
        if (diff <= 0) {
            countdownEl.textContent = 'หมดอายุ';
            if (!paymentFinished) setStatus('bad', 'กำลังตรวจสอบสถานะการชำระเงินครั้งสุดท้าย...');
            return;
        }
        countdownEl.textContent = Math.floor(diff / 60) + ':' + String(diff % 60).padStart(2, '0');
    }

    async function poll() {
        try {
            const r = await Payments.paymentRefreshPromptpay(publicId);
            if (r.status === 'paid') {
                paymentFinished = true;
                setStatus('ok', 'ชำระเงินสำเร็จ! กำลังไปหน้าดาวน์โหลด...');
                clearInterval(pollTimer);
                clearInterval(countdownTimer);
                setTimeout(() => Router.navigate('success', { id: publicId }), 1200);
            } else if (r.status === 'failed' || r.status === 'expired') {
                paymentFinished = true;
                setStatus('bad', r.status === 'expired' ? 'การชำระเงินหมดอายุ' : 'การชำระเงินไม่สำเร็จ');
                clearInterval(pollTimer);
                clearInterval(countdownTimer);
            } else {
                const refreshed = Orders.byPublicId(publicId);
                const refreshedExp = refreshed && parseUtc(refreshed.provider_expires_at);
                if (refreshedExp) expiresTs = refreshedExp.getTime();
            }
            return r;
        } catch (e) {
            return null; // A transient provider/CORS error is retried while pending.
        }
    }

    async function enableDemo(reason) {
        const box = document.getElementById('ppDemoBox');
        box.classList.remove('hidden');
        box.innerHTML = `
            <p class="font-semibold mb-2">⚠️ ${escapeHtml(reason)}</p>
            <p class="mb-3">คุณยังทดลองขั้นตอนทั้งหมดได้ผ่าน <strong>โหมดทดลอง (Demo)</strong> — จะไม่มีการชำระเงินจริง</p>
            <button id="demoPayBtn" type="button"
                class="w-full bg-amber-600 text-white py-2.5 rounded-lg font-semibold hover:bg-amber-700 transition-colors">
                🎬 จำลองชำระเงินสำเร็จ (Demo)
            </button>`;
        document.getElementById('demoPayBtn').addEventListener('click', () => {
            const amount = order.amount_satang || CONFIG.priceSatang;
            if (Orders.markPaid(order.id, 'demo-' + randomHex(6), amount)) {
                setStatus('ok', 'จำลองชำระเงินสำเร็จ (Demo)! กำลังไปหน้าดาวน์โหลด...');
                clearInterval(pollTimer);
                setTimeout(() => Router.navigate('success', { id: publicId }), 1200);
            }
        });
    }

    (async function init() {
        try {
            // Reconcile an existing transaction before paymentStartPromptpay can
            // replace an apparently expired QR with a new transaction.
            if (order.provider_transaction_id) {
                document.getElementById('ppTxn').textContent = order.provider_transaction_id;
                document.getElementById('ppAmount').textContent = moneyTHB(order.amount_satang);
                if (order.provider_qr_url) {
                    qrBox.innerHTML = `<img src="${escapeHtml(order.provider_qr_url)}" alt="PromptPay QR Code" class="mx-auto" style="max-width:288px">`;
                }
                const existingExp = parseUtc(order.provider_expires_at);
                if (existingExp) {
                    expiresTs = existingExp.getTime();
                    tick();
                    countdownTimer = setInterval(tick, 1000);
                }

                await poll();
                if (paymentFinished) return;
                if (order.status === 'expired') {
                    setStatus('bad', 'การชำระเงินหมดอายุ');
                    clearInterval(countdownTimer);
                    return;
                }
            }

            const current = Orders.byPublicId(publicId);
            if (!current || current.status !== 'pending') return;

            const qr = await Payments.paymentStartPromptpay(publicId);
            const qrExp = parseUtc(qr.expiresAt);
            expiresTs = qrExp ? qrExp.getTime() : null;
            document.getElementById('ppAmount').textContent = moneyTHB(qr.amountSatang);
            document.getElementById('ppTxn').textContent = qr.transactionId;
            qrBox.innerHTML = `<img src="${escapeHtml(qr.qrUrl)}" alt="PromptPay QR Code" class="mx-auto" style="max-width:288px">`;
            tick();
            if (!countdownTimer) countdownTimer = setInterval(tick, 1000);

            if (!order.provider_transaction_id) await poll();
            if (!paymentFinished) pollTimer = setInterval(poll, 5000);
        } catch (err) {
            const msg = String(err && err.message || err);
            if (msg.startsWith('BROWSER_BLOCKED')) {
                qrBox.innerHTML = `<span class="text-gray-400 text-sm text-center px-4">ไม่สามารถเชื่อมต่อ API ของ InwCloud จาก browser ได้ (CORS/เครือข่ายถูกบล็อก)</span>`;
                statusBox.classList.add('hidden');
                await enableDemo('InwCloud API ถูกบล็อกจาก browser (CORS) — ต้องใช้เซิร์ฟเวอร์ถึงจะเรียกได้');
            } else {
                qrBox.innerHTML = `<span class="text-red-500 text-sm text-center px-4">${escapeHtml(msg)}</span>`;
                statusBox.classList.add('hidden');
                await enableDemo('ไม่สามารถสร้าง QR จริงได้: ' + msg);
            }
        }
    })();
}

function renderPayTruewallet(order) {
    const publicId = order.public_id;
    setView(`
    <section class="max-w-lg mx-auto px-4 py-8">
        <h1 class="text-2xl font-bold text-gray-900 mb-2">ชำระเงินด้วย TrueMoney Wallet</h1>
        <p class="text-sm text-gray-600 mb-6">วางลิงก์ซองของขวัญ <strong>${CONFIG.priceThb} บาท</strong> ของคุณ</p>
        <div class="bg-amber-50 border border-amber-200 rounded-lg p-4 mb-6">
            <div class="text-sm text-amber-800">
                <p class="font-semibold mb-1">ข้อควรทราบ</p>
                <p>รับเฉพาะซองของขวัญมูลค่า 100 บาทเท่านั้น ระบบอาจแลกซองแล้วก่อนทราบยอด หากยอดไม่ตรงจะไม่ได้รับไฟล์และไม่มีเงินทอน</p>
                <p class="mt-2">Only accepts 100 THB vouchers exactly. The system may redeem the voucher before checking the amount. If the amount does not match, you will not receive the file and there is no change.</p>
            </div>
        </div>
        <form id="twForm" class="space-y-4" novalidate>
            <div>
                <label for="voucher_url" class="block text-sm font-medium text-gray-700 mb-1">ลิงก์ซองของขวัญ</label>
                <input type="url" id="voucher_url" required placeholder="https://gift.truemoney.com/campaign/?v=..."
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-500 focus:border-brand-500 text-sm">
            </div>
            <label class="flex items-start gap-2">
                <input type="checkbox" id="confirmCheckbox" class="mt-1 text-brand-600 focus:ring-brand-500" required>
                <span class="text-sm text-gray-600">ฉันยอมรับว่าต้องใช้ซองของขวัญมูลค่า 100 บาทเท่านั้น และจะไม่มีเงินทอนหากยอดไม่ตรง</span>
            </label>
            <div id="twError" class="hidden bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg text-sm"></div>
            <div id="twSuccess" class="hidden bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg text-sm"></div>
            <button type="submit" id="twSubmit"
                    class="w-full bg-brand-600 text-white py-3 rounded-lg font-semibold hover:bg-brand-700 transition-colors disabled:opacity-50 disabled:cursor-not-allowed">
                แลกซองของขวัญ
            </button>
        </form>
        <div id="twDemoBox" class="hidden mt-4 bg-amber-50 border border-amber-200 rounded-lg p-4 text-sm text-amber-800">
            <p class="font-semibold mb-2">⚠️ API จริงถูกบล็อกจาก browser (CORS)</p>
            <p class="mb-3">ทดลองขั้นตอนทั้งหมดผ่าน <strong>โหมดทดลอง (Demo)</strong> — ไม่มีการแลกซองจริง</p>
            <button id="twDemoBtn" type="button"
                class="w-full bg-amber-600 text-white py-2.5 rounded-lg font-semibold hover:bg-amber-700 transition-colors">
                🎬 จำลองแลกซองสำเร็จ (Demo)
            </button>
        </div>
        <div class="text-center mt-4">
            <a href="#/" class="text-sm text-brand-600 hover:underline">ยกเลิกและกลับไปหน้าหลัก</a>
        </div>
    </section>`);

    const form = document.getElementById('twForm');
    const submitBtn = document.getElementById('twSubmit');
    const errorEl = document.getElementById('twError');
    const successEl = document.getElementById('twSuccess');

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const voucherUrl = document.getElementById('voucher_url').value.trim();
        if (!document.getElementById('confirmCheckbox').checked) {
            errorEl.textContent = 'กรุณายืนยันว่าคุณเข้าใจเงื่อนไขก่อน';
            errorEl.classList.remove('hidden');
            return;
        }
        submitBtn.disabled = true;
        submitBtn.textContent = 'กำลังดำเนินการ...';
        errorEl.classList.add('hidden');
        successEl.classList.add('hidden');

        try {
            const result = await Payments.paymentRedeemTruewallet(publicId, voucherUrl);
            if (result.paid) {
                successEl.textContent = 'ชำระเงินสำเร็จ! กำลังไปหน้าดาวน์โหลด...';
                successEl.classList.remove('hidden');
                setTimeout(() => Router.navigate('success', { id: publicId }), 1500);
            } else if (result.error) {
                errorEl.textContent = result.error;
                errorEl.classList.remove('hidden');
            } else {
                errorEl.textContent = 'ไม่สามารถยืนยันการชำระเงินได้ กรุณาตรวจสอบซองของขวัญและลองใหม่';
                errorEl.classList.remove('hidden');
            }
        } catch (err) {
            const msg = String(err && err.message || err);
            if (msg.startsWith('BROWSER_BLOCKED')) {
                const box = document.getElementById('twDemoBox');
                box.classList.remove('hidden');
                box.querySelector('p.font-semibold').textContent = '⚠️ InwCloud API ถูกบล็อกจาก browser (CORS) — ใช้โหมดทดลองแทน';
                box.querySelector('button').addEventListener('click', () => {
                    if (Orders.markPaid(order.id, 'truewallet-demo-' + randomHex(6), CONFIG.priceSatang)) {
                        successEl.textContent = 'จำลองแลกซองสำเร็จ (Demo)! กำลังไปหน้าดาวน์โหลด...';
                        successEl.classList.remove('hidden');
                        setTimeout(() => Router.navigate('success', { id: publicId }), 1200);
                    }
                });
            } else {
                errorEl.textContent = msg;
                errorEl.classList.remove('hidden');
            }
        } finally {
            submitBtn.disabled = false;
            submitBtn.textContent = 'แลกซองของขวัญ';
        }
    });
}

/* ------------------------------------------------------------------ */
/* SUCCESS + download view                                             */
/* ------------------------------------------------------------------ */

async function renderSuccess(params) {
    const publicId = params.id || '';
    const session = Session.get();
    const order = Orders.byPublicId(publicId);
    if (!publicId || !order || session.order_public_id !== publicId) {
        return renderError(404, 'ไม่พบคำสั่งซื้อ');
    }
    if (order.status !== 'paid') {
        return Router.navigate('pay', { id: publicId });
    }

    // Issue download token once, keep raw token in session
    let rawToken = session.download_token || null;
    if (!rawToken) {
        const existing = Downloads.tokenByOrder(order.id);
        rawToken = existing
            ? await Downloads.rotateToken(order.id)
            : await Downloads.issueToken(order.id);
        Session.set({ download_token: rawToken });
    }

    const token = Downloads.tokenByOrder(order.id);
    const remaining = token ? token.max_downloads - token.download_count : CONFIG.downloadMaxCount;
    const expiresAt = token ? token.expires_at : null;

    setView(`
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
                <p class="text-gray-600">คำสั่งซื้อ: <span class="font-mono font-medium">${escapeHtml(publicId)}</span></p>
                <p class="text-gray-600 mt-1">ลิงก์หมดอายุ: <strong>${fmtDate(expiresAt)}</strong></p>
                <p class="text-gray-600 mt-1">ดาวน์โหลดได้อีก: <strong>${remaining} ครั้ง</strong></p>
            </div>
            <div id="dlBox">
                <button id="downloadBtn"
                    class="inline-flex items-center gap-2 bg-green-600 text-white px-6 py-3 rounded-lg font-semibold hover:bg-green-700 transition-colors">
                    ⬇ ดาวน์โหลดชุดฟอนต์
                </button>
                <p class="text-xs text-gray-500 mt-3">${escapeHtml(CONFIG.demoZipName)} (ชุดตัวอย่างฟรี 10 ฟอนต์ — ชุดเต็ม 455MB มีเฉพาะเวอร์ชัน PHP)</p>
            </div>
            <div id="dlProgress" class="hidden text-sm text-gray-500 mt-3">กำลังสร้างไฟล์ ZIP...</div>
            <div id="dlError" class="hidden text-sm text-red-600 mt-3"></div>
        </div>
        <a href="#/" class="inline-block mt-6 text-brand-600 hover:underline">กลับไปหน้าหลัก</a>
    </section>`);

    document.getElementById('downloadBtn').addEventListener('click', async () => {
        const btn = document.getElementById('downloadBtn');
        const progress = document.getElementById('dlProgress');
        const errorEl = document.getElementById('dlError');
        btn.disabled = true;
        progress.classList.remove('hidden');
        errorEl.classList.add('hidden');
        try {
            const auth = await Downloads.authorize(rawToken);
            if (!auth) {
                throw new Error('ลิงก์ดาวน์โหลดไม่ถูกต้อง หมดอายุ หรือใช้งานครบจำนวนแล้ว');
            }
            if (!Downloads.consume(auth.token.id)) {
                throw new Error('ลิงก์ดาวน์โหลดไม่ถูกต้อง หมดอายุ หรือใช้งานครบจำนวนแล้ว');
            }
            const blob = await Downloads.buildSampleZip();
            Downloads.triggerDownload(blob, CONFIG.demoZipName);
            progress.textContent = 'ดาวน์โหลดสำเร็จ ✓ (เหลือ ' + (auth.token.max_downloads - auth.token.download_count) + ' ครั้ง)';
        } catch (err) {
            errorEl.textContent = err.message;
            errorEl.classList.remove('hidden');
        } finally {
            btn.disabled = false;
        }
    });
}

/* ------------------------------------------------------------------ */
/* Error view                                                          */
/* ------------------------------------------------------------------ */

function renderError(status, message) {
    setView(`
    <div class="max-w-md mx-auto px-4 py-16 text-center">
        <h1 class="text-5xl font-bold text-gray-300 mb-3">${status}</h1>
        <p class="text-gray-600 mb-6">${escapeHtml(message)}</p>
        <a href="#/" class="text-green-600 hover:underline">กลับไปหน้าแรก</a>
    </div>`);
}

/* ------------------------------------------------------------------ */
/* Boot                                                                */
/* ------------------------------------------------------------------ */

(async function boot() {
    try {
        await Fonts.loadCatalog();
        if (!Fonts.stats || Fonts.stats.packCount === 0) {
            throw new Error('catalog empty');
        }
    } catch (e) {
        document.getElementById('view').innerHTML = `
            <div class="max-w-md mx-auto px-4 py-16 text-center">
                <h1 class="text-2xl font-bold text-gray-900 mb-3">โหลดข้อมูลฟอนต์ไม่สำเร็จ</h1>
                <p class="text-gray-600 mb-6">${escapeHtml(String(e.message || e))}</p>
                <button onclick="location.reload()" class="bg-brand-600 text-white px-6 py-3 rounded-lg font-semibold hover:bg-brand-700">ลองใหม่</button>
            </div>`;
        return;
    }

    Router.init({
        home: () => renderHome(),
        checkout: () => renderCheckout(),
        pay: (p) => renderPay(p),
        success: (p) => renderSuccess(p),
        '404': () => renderError(404, 'ไม่พบหน้าที่ต้องการ'),
    });
})();
