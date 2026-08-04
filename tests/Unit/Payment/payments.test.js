#!/usr/bin/env node
/**
 * FontSeller — Unit tests for the payment module (js/payments.js)
 *
 * Verifies the InwCloud API contract and the "ยอด" (amount) update rules:
 *   - generate -> order.amount_satang updated to list price + channel fee
 *   - check -> on success, order marked paid with the actual paid amount
 *   - amount guards: paid < 100.00 or > 150.00 -> order failed, no update
 *   - throttling (once per 10s), txn mismatch, voucher rules, idempotency
 *
 * Run:  node tests/Unit/Payment/payments.test.js
 */
'use strict';

const assert = require('node:assert');
const fs = require('node:fs');
const path = require('node:path');
const { webcrypto } = require('node:crypto');

/* ------------------------------------------------------------------ */
/* Harness: load app modules (config/store/fonts/payments/downloads)   */
/* in a shared scope with a mocked browser environment.                */
/* ------------------------------------------------------------------ */

const APP_SRC = ['config', 'store', 'fonts', 'payments', 'downloads']
    .map(f => fs.readFileSync(path.join(__dirname, '../../../js', f + '.js'), 'utf8'))
    .join('\n');

function mockLocalStorage() {
    const m = new Map();
    return {
        getItem: k => (m.has(k) ? m.get(k) : null),
        setItem: (k, v) => m.set(k, String(v)),
        removeItem: k => m.delete(k),
        _clear: () => m.clear(),
    };
}

// Mutable API stub — tests configure __mock.routes / __mock.calls
const __mock = { routes: new Map(), calls: [] };
const mockFetch = async (url) => {
    if (url.includes('fonts.json')) {
        return { ok: true, json: async () => JSON.parse(fs.readFileSync(path.join(__dirname, '../../../data/fonts.json'), 'utf8')) };
    }
    __mock.calls.push(url);
    const route = __mock.routes.get(url);
    if (!route) return { ok: false, status: 404, data: null };
    return {
        ok: route.status >= 200 && route.status < 300,
        status: route.status,
        json: async () => route.body,
    };
};

const sandbox = {
    console, URL, TextEncoder, TextDecoder, Uint8Array, Uint16Array, Array, Map, Math,
    Date, JSON, Number, String, Promise, setTimeout, clearTimeout,
    AbortController, AbortSignal,
    crypto: webcrypto,
    localStorage: mockLocalStorage(),
    fetch: mockFetch,
};
const ctx = new (require('node:vm').createContext)(sandbox);
const run = (code) => new (require('node:vm').Script)(code).runInContext(ctx);
run(APP_SRC);

const store = { run };

/* ------------------------------------------------------------------ */
/* Test helpers                                                        */
/* ------------------------------------------------------------------ */

function resetStore() { sandbox.localStorage._clear(); }

function seedOrder(method = 'promptpay', amount = 10000) {
    const o = run(`Orders.create("Test Buyer", "test@example.com", "${method}")`);
    if (amount !== 10000) run(`Orders.setAmount(${o.id}, ${amount})`);
    return o;
}

function setRoute(url, status, body) { __mock.routes.set(url, { status, body }); }
const calls = (sub) => __mock.calls.filter(c => c.includes(sub)).length;
const futureUtcStringForTest = (ms) => new Date(Date.now() + ms).toISOString().slice(0, 19).replace('T', ' ');

/** Minimal amount for a fresh order after QR generate (100.00 + fee). */
const GEN = 'https://api.inwcloud.shop/v1/promptpay/generate';
const CHECK = 'https://api.inwcloud.shop/v1/promptpay/check';
const REDEEM = 'https://api.inwcloud.shop/v1/truewallet/redeem';

const okGenerate = (transactionId = 'Market-1', amount = '100.03', qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?data=x&size=300x300') => ({
    status: 'success',
    data: { transactionId, qr_url: qrUrl, payload: '000201', amount, expires_at: Math.floor(Date.now() / 1000) + 1800 },
});

const okCheck = (transactionId, amount) => ({ status: 'success', transactionId, amount });
const pendingCheck = (transactionId) => ({ status: 'pending', transactionId, expires_at: Math.floor(Date.now() / 1000) + 600 });

/* ------------------------------------------------------------------ */
/* Tests                                                               */
/* ------------------------------------------------------------------ */

async function main() {
    let passed = 0;
    const T = async (name, fn) => {
        resetStore();
        __mock.routes.clear();
        __mock.calls.length = 0;
        try { await fn(); console.log('  ✓', name); passed++; }
        catch (e) { console.error('  ✗', name, '\n   ', e.message); process.exitCode = 1; }
    };

    // --- generate: ยอดอัปเดต (100.00 -> 100.03 = ราคา + ค่าธรรมเนียม) ---
    await T('generate อัปเดตยอด order จาก 10000 เป็น 10003', async () => {
        setRoute(GEN, 200, okGenerate());
        const o = seedOrder();
        const qr = await run(`Payments.paymentStartPromptpay(${JSON.stringify(o.public_id)})`);
        assert.strictEqual(qr.amountSatang, 10003);
        const after = run(`Orders.byPublicId(${JSON.stringify(o.public_id)})`);
        assert.strictEqual(after.amount_satang, 10003, 'order.amount_satang ควรอัปเดตเป็น 10003');
        assert.strictEqual(after.provider_transaction_id, 'Market-1');
        assert.strictEqual(after.status, 'pending');
    });

    // --- generate: reuse ไม่เรียก API ซ้ำ ---
    await T('reuse transaction ยังไม่หมดอายุ ไม่เรียก generate ซ้ำ', async () => {
        setRoute(GEN, 200, okGenerate());
        const o = seedOrder();
        await run(`Payments.paymentStartPromptpay(${JSON.stringify(o.public_id)})`);
        const before = calls('generate');
        const again = await run(`Payments.paymentStartPromptpay(${JSON.stringify(o.public_id)})`);
        assert.strictEqual(again.transactionId, 'Market-1');
        assert.strictEqual(calls('generate'), before, 'ไม่ควรเรียก generate อีกรอบ');
    });

    // --- generate: txn หมดอายุ -> เรียกใหม่ ---
    await T('transaction หมดอายุ -> สร้าง QR ใหม่', async () => {
        setRoute(GEN, 200, okGenerate('Market-1'));
        const o = seedOrder();
        run(`Orders.setProvider(${o.id}, "OLD-TXN", "2020-01-01 00:00:00", null, "https://api.qrserver.com/v1/x")`);
        const qr = await run(`Payments.paymentStartPromptpay(${JSON.stringify(o.public_id)})`);
        assert.strictEqual(qr.transactionId, 'Market-1', 'ควรได้ txn ใหม่');
        assert.strictEqual(calls('generate'), 1);
    });

    // --- generate: key ผิด ---
    await T('INVALID_API_KEY -> throw พร้อมข้อความ', async () => {
        setRoute(GEN, 401, { status: 'error', message: 'Invalid API key', code: 'INVALID_API_KEY' });
        const o = seedOrder();
        await assert.rejects(
            run(`Payments.paymentStartPromptpay(${JSON.stringify(o.public_id)})`),
            /INVALID_API_KEY/
        );
    });

    // --- check: success ยอดตรง -> paid + ยอดอัปเดต ---
    await T('check success (10003) -> order เป็น paid, ยอด 10003', async () => {
        setRoute(GEN, 200, okGenerate('Market-1', '100.03'));
        const o = seedOrder();
        await run(`Payments.paymentStartPromptpay(${JSON.stringify(o.public_id)})`);
        setRoute(CHECK, 200, okCheck('Market-1', '100.03'));
        const r = await run(`Payments.paymentRefreshPromptpay(${JSON.stringify(o.public_id)})`);
        assert.strictEqual(r.status, 'paid');
        assert.strictEqual(r.paid, true);
        const after = run(`Orders.byPublicId(${JSON.stringify(o.public_id)})`);
        assert.strictEqual(after.status, 'paid');
        assert.strictEqual(after.amount_satang, 10003, 'ยอดที่จ่ายจริง 10003');
        assert.strictEqual(after.provider_transaction_id, 'Market-1');
        assert.ok(after.paid_at);
    });

    // --- recovery: cached old build marked the paid transaction expired ---
    await T('expired order + provider success 100.12 -> กู้เป็น paid', async () => {
        const txn = 'Market-403-1785837007-513a73973c911f331548';
        const o = seedOrder();
        run(`Orders.setProvider(${o.id}, ${JSON.stringify(txn)}, "2026-08-04 09:51:00", null, "https://api.qrserver.com/v1/x")`);
        run(`Orders.markExpired(${o.id})`);
        setRoute(CHECK, 200, {
            status: 'success',
            message: 'ชำระเงินสำเร็จ',
            transactionId: txn,
            amount: '100.12',
            customer_type: 'existing',
            cost: 0,
        });

        const r = await run(`Payments.paymentRefreshPromptpay(${JSON.stringify(o.public_id)})`);

        assert.strictEqual(r.status, 'paid');
        assert.strictEqual(r.paid, true);
        const after = run(`Orders.byPublicId(${JSON.stringify(o.public_id)})`);
        assert.strictEqual(after.status, 'paid');
        assert.strictEqual(after.amount_satang, 10012);
        assert.ok(after.paid_at);
    });

    await T('expired order + provider pending -> ยัง expired', async () => {
        const o = seedOrder();
        run(`Orders.setProvider(${o.id}, "Market-expired", "2020-01-01 00:00:00", null, "https://api.qrserver.com/v1/x")`);
        run(`Orders.markExpired(${o.id})`);
        setRoute(CHECK, 200, pendingCheck('Market-expired'));

        const r = await run(`Payments.paymentRefreshPromptpay(${JSON.stringify(o.public_id)})`);

        assert.strictEqual(r.status, 'expired');
        assert.strictEqual(r.paid, false);
        assert.strictEqual(run(`Orders.byPublicId(${JSON.stringify(o.public_id)}).status`), 'expired');
    });

    await T('pending order ที่ local expiry ผ่านแล้ว + provider success -> paid', async () => {
        const o = seedOrder();
        run(`Orders.setProvider(${o.id}, "Market-boundary", "2020-01-01 00:00:00", null, "https://api.qrserver.com/v1/x")`);
        setRoute(CHECK, 200, okCheck('Market-boundary', '100.12'));

        const r = await run(`Payments.paymentRefreshPromptpay(${JSON.stringify(o.public_id)})`);

        assert.strictEqual(r.status, 'paid');
        assert.strictEqual(r.paid, true);
        assert.strictEqual(run(`Orders.byPublicId(${JSON.stringify(o.public_id)}).status`), 'paid');
    });

    // --- check: success แต่จ่ายยอดต่ำกว่า 100.00 -> failed ไม่ up เงิน ---
    await T('check success แต่ยอด 99.99 (< 100) -> failed', async () => {
        setRoute(GEN, 200, okGenerate('Market-1', '100.03'));
        const o = seedOrder();
        await run(`Payments.paymentStartPromptpay(${JSON.stringify(o.public_id)})`);
        setRoute(CHECK, 200, okCheck('Market-1', '99.99'));
        const r = await run(`Payments.paymentRefreshPromptpay(${JSON.stringify(o.public_id)})`);
        assert.strictEqual(r.status, 'failed');
        assert.strictEqual(run(`Orders.byPublicId(${JSON.stringify(o.public_id)}).status`), 'failed');
    });

    // --- check: success แต่ยอดเกิน cap 150.00 -> failed ---
    await T('check success แต่ยอด 150.01 (> 150) -> failed', async () => {
        setRoute(GEN, 200, okGenerate('Market-1', '100.03'));
        const o = seedOrder();
        await run(`Payments.paymentStartPromptpay(${JSON.stringify(o.public_id)})`);
        setRoute(CHECK, 200, okCheck('Market-1', '150.01'));
        const r = await run(`Payments.paymentRefreshPromptpay(${JSON.stringify(o.public_id)})`);
        assert.strictEqual(r.status, 'failed');
    });

    // --- check: transactionId ต่าง -> failed ---
    await T('check transactionId ไม่ตรง -> failed', async () => {
        setRoute(GEN, 200, okGenerate('Market-1', '100.03'));
        const o = seedOrder();
        await run(`Payments.paymentStartPromptpay(${JSON.stringify(o.public_id)})`);
        setRoute(CHECK, 200, okCheck('OTHER-TXN', '100.03'));
        const r = await run(`Payments.paymentRefreshPromptpay(${JSON.stringify(o.public_id)})`);
        assert.strictEqual(r.status, 'failed');
    });

    // --- check: pending -> ยัง pending + throttle ถัดไป ---
    await T('check pending -> order ยัง pending, throttle 10 วิ', async () => {
        setRoute(GEN, 200, okGenerate('Market-1', '100.03'));
        const o = seedOrder();
        await run(`Payments.paymentStartPromptpay(${JSON.stringify(o.public_id)})`);
        setRoute(CHECK, 200, pendingCheck('Market-1'));
        const r1 = await run(`Payments.paymentRefreshPromptpay(${JSON.stringify(o.public_id)})`);
        assert.strictEqual(r1.status, 'pending');
        const r2 = await run(`Payments.paymentRefreshPromptpay(${JSON.stringify(o.public_id)})`);
        assert.strictEqual(r2.throttled, true, 'poll รอบถัดไปใน 10 วิต้องถูก throttle');
        assert.strictEqual(r2.status, 'pending');
    });

    await T('provider pending + expires_at ผ่านแล้ว -> expired', async () => {
        const o = seedOrder();
        run(`Orders.setProvider(${o.id}, "Market-expired-pending", ${JSON.stringify(futureUtcStringForTest(60000))}, null, "https://api.qrserver.com/v1/x")`);
        setRoute(CHECK, 200, {
            status: 'pending',
            transactionId: 'Market-expired-pending',
            expires_at: Math.floor(Date.now() / 1000) - 1,
        });

        const r = await run(`Payments.paymentRefreshPromptpay(${JSON.stringify(o.public_id)})`);

        assert.strictEqual(r.status, 'expired');
        assert.strictEqual(run(`Orders.byPublicId(${JSON.stringify(o.public_id)}).status`), 'expired');
    });

    // --- check: provider error (network) -> pending, ไม่พัง ---
    await T('provider error -> ยัง pending (ลองใหม่ทีหลัง)', async () => {
        setRoute(GEN, 200, okGenerate('Market-1', '100.03'));
        const o = seedOrder();
        await run(`Payments.paymentStartPromptpay(${JSON.stringify(o.public_id)})`);
        __mock.routes.delete(CHECK); // no route -> 404
        const r = await run(`Payments.paymentRefreshPromptpay(${JSON.stringify(o.public_id)})`);
        assert.strictEqual(r.status, 'pending');
        assert.strictEqual(run(`Orders.byPublicId(${JSON.stringify(o.public_id)}).status`), 'pending');
    });

    // --- truewallet: ยอดตรง 100.00 -> paid ---
    await T('truewallet redeem 100.00 -> paid', async () => {
        setRoute(REDEEM, 200, { status: 'success', data: { amount: '100.00', voucher_link: 'https://gift.truemoney.com/campaign/?v=abc' } });
        const o = seedOrder('truewallet');
        const r = await run(`Payments.paymentRedeemTruewallet(${JSON.stringify(o.public_id)}, "https://gift.truemoney.com/campaign/?v=abc")`);
        assert.strictEqual(r.paid, true);
        assert.strictEqual(run(`Orders.byPublicId(${JSON.stringify(o.public_id)}).status`), 'paid');
    });

    // --- truewallet: ยอดไม่ตรง (99.00) -> failed ---
    await T('truewallet redeem 99.00 (ไม่ตรง) -> failed', async () => {
        setRoute(REDEEM, 200, { status: 'success', data: { amount: '99.00', voucher_link: 'https://gift.truemoney.com/campaign/?v=abc' } });
        const o = seedOrder('truewallet');
        const r = await run(`Payments.paymentRedeemTruewallet(${JSON.stringify(o.public_id)}, "https://gift.truemoney.com/campaign/?v=abc")`);
        assert.strictEqual(r.paid, false);
        assert.strictEqual(run(`Orders.byPublicId(${JSON.stringify(o.public_id)}).status`), 'failed');
    });

    // --- truewallet: ซองซ้ำ -> ไม่ redeem ซ้ำ (idempotent, ข้าม order) ---
    await T('ซองของขวัญซ้ำ -> ไม่เรียก redeem ซ้ำ (idempotent)', async () => {
        setRoute(REDEEM, 200, { status: 'success', data: { amount: '100.00', voucher_link: 'https://gift.truemoney.com/campaign/?v=abc' } });
        const o1 = seedOrder('truewallet');
        const o2 = seedOrder('truewallet');
        const url = 'https://gift.truemoney.com/campaign/?v=abc';
        const r1 = await run(`Payments.paymentRedeemTruewallet(${JSON.stringify(o1.public_id)}, ${JSON.stringify(url)})`);
        assert.strictEqual(r1.paid, true, 'order แรกจ่ายสำเร็จ');
        const before = calls('redeem');
        const r2 = await run(`Payments.paymentRedeemTruewallet(${JSON.stringify(o2.public_id)}, ${JSON.stringify(url)})`);
        assert.strictEqual(calls('redeem'), before, 'ไม่ควรเรียก redeem อีกรอบ (เจอ fingerprint เดิม)');
        assert.strictEqual(r2.paid, true, 'order ที่สองได้ผลลัพธ์เดิมโดยไม่ต้องแลกซ้ำ');
    });

    // --- voucher validation ---
    await T('validateVoucherUrl: ปฏิเสธ http / โดเมนอื่น / ไม่มีพารามิเตอร์ v', async () => {
        run(`Payments.validateVoucherUrl("https://gift.truemoney.com/campaign/?v=abc")`);
        assert.throws(() => run(`Payments.validateVoucherUrl("http://gift.truemoney.com/campaign/?v=abc")`), /HTTPS/);
        assert.throws(() => run(`Payments.validateVoucherUrl("https://evil.com/?v=abc")`), /gift\.truemoney\.com/);
        assert.throws(() => run(`Payments.validateVoucherUrl("https://gift.truemoney.com/campaign/")`), /พารามิเตอร์ v/);
        assert.throws(() => run(`Payments.validateVoucherUrl("")`), /กรุณากรอก/);
    });

    // --- QR url safety ---
    await T('generate: ปฏิเสธ QR url ที่ไม่ใช่ api.qrserver.com', async () => {
        setRoute(GEN, 200, okGenerate('Market-1', '100.03', 'https://evil.example.com/qr.png'));
        const o = seedOrder();
        await assert.rejects(
            run(`Payments.paymentStartPromptpay(${JSON.stringify(o.public_id)})`),
            /QR ไม่ถูกต้อง/
        );
    });

    console.log(`\n${passed} payment tests passed`);
}

main().catch(e => { console.error('FATAL:', e); process.exit(1); });
