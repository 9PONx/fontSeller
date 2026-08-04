/**
 * FontSeller — payments (InwCloud real API, mirrors PHP payments.php)
 */
'use strict';

const Payments = {
    /* ---------------- HTTP transport ---------------- */

    async inwcloudPost(endpoint, payload) {
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), CONFIG.apiTimeoutMs);
        let res;
        try {
            res = await fetch(CONFIG.apiBase + endpoint, {
                method: 'POST',
                headers: {
                    'Authorization': 'Bearer ' + CONFIG.apiKey,
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(payload),
                signal: controller.signal,
            });
        } catch (e) {
            clearTimeout(timer);
            // CORS / network error from the browser
            return { ok: false, status: 0, data: null, category: e.name === 'AbortError' ? 'timeout' : 'cors', message: String(e.message || e) };
        }
        clearTimeout(timer);

        let data = null;
        try { data = await res.json(); } catch (e) { /* empty body */ }

        return {
            ok: res.ok && res.status >= 200 && res.status < 300,
            status: res.status,
            data,
            category: 'provider_error',
            message: (data && data.message) || '',
        };
    },

    /* ---------------- PromptPay ---------------- */

    async generatePromptpay() {
        const amount = (CONFIG.priceSatang / 100).toFixed(2);
        const res = await this.inwcloudPost('/v1/promptpay/generate', { amount: parseFloat(amount) });

        if (!res.ok || !res.data || res.data.status !== 'success') {
            const code = (res.data && res.data.code) || '';
            if (code === 'INVALID_API_KEY' || res.status === 401) {
                throw new Error('คีย์ InwCloud ไม่ถูกต้อง (INVALID_API_KEY) — ตรวจสอบ INWCLOUD_API_KEY แล้วลองใหม่');
            }
            if (res.category === 'cors') {
                throw new Error('BROWSER_BLOCKED: ' + res.message);
            }
            throw new Error('ไม่สามารถสร้าง QR ได้ชั่วคราว กรุณาลองใหม่');
        }

        const d = (res.data && res.data.data) || {};
        const transactionId = String(d.transactionId || '');
        const qrUrl = String(d.qr_url || '');
        const amountSatang = parseAmountSatang(d.amount);
        const expiresAt = d.expires_at ? new Date(Number(d.expires_at) * 1000).toISOString().slice(0, 19).replace('T', ' ') : null;

        if (!transactionId || !qrUrl || amountSatang === null || !expiresAt) {
            throw new Error('ข้อมูลจากผู้ให้บริการชำระเงินไม่ครบถ้วน');
        }
        if (amountSatang <= 0) {
            throw new Error('ข้อมูลจากผู้ให้บริการชำระเงินไม่ครบถ้วน');
        }

        // QR URL safety: https + api.qrserver.com + no userinfo/fragment
        let parts;
        try { parts = new URL(qrUrl); } catch (e) { throw new Error('QR ไม่ถูกต้องจากผู้ให้บริการชำระเงิน'); }
        if (parts.protocol !== 'https:' || parts.hostname.toLowerCase() !== CONFIG.qrHost ||
            parts.username || parts.password || parts.hash) {
            throw new Error('QR ไม่ถูกต้องจากผู้ให้บริการชำระเงิน');
        }

        return { transactionId, qrUrl, amountSatang, expiresAt };
    },

    async checkPromptpay(transactionId) {
        const res = await this.inwcloudPost('/v1/promptpay/check', { transactionId });
        if (!res.ok || !res.data) return null;
        const d = res.data;
        return {
            status: String(d.status || 'error'),
            transactionId: String(d.transactionId || transactionId),
            amountSatang: parseAmountSatang(d.amount),
            expiresAt: d.expires_at ? new Date(Number(d.expires_at) * 1000).toISOString().slice(0, 19).replace('T', ' ') : null,
        };
    },

    /* ---------------- TrueMoney ---------------- */

    async redeemTruewallet(voucherUrl) {
        const res = await this.inwcloudPost('/v1/truewallet/redeem', { voucher_link: voucherUrl });
        if (!res.ok || !res.data || res.data.status !== 'success') {
            return { status: 'error', amountSatang: null, message: (res.data && res.data.message) || 'provider_error' };
        }
        const d = (res.data && res.data.data) || {};
        return { status: 'success', amountSatang: parseAmountSatang(d.amount), message: '' };
    },

    /* ---------------- Voucher validation (mirrors PHP) ---------------- */

    validateVoucherUrl(raw) {
        const url = String(raw || '').trim();
        if (url === '') throw new Error('กรุณากรอกลิงก์ซองของขวัญ');
        if (url.length > 2048) throw new Error('ลิงก์ยาวเกินไป');
        if (/\s/.test(url)) throw new Error('ลิงก์ต้องไม่มีช่องว่าง');

        let parts;
        try { parts = new URL(url); } catch (e) { throw new Error('รูปแบบลิงก์ไม่ถูกต้อง'); }
        if (parts.protocol !== 'https:') throw new Error('ลิงก์ต้องเป็น HTTPS เท่านั้น');
        if (parts.hostname.toLowerCase() !== 'gift.truemoney.com') throw new Error('ลิงก์ต้องมาจาก gift.truemoney.com เท่านั้น');
        if (parts.username || parts.password || parts.hash) throw new Error('ลิงก์มีส่วนประกอบที่ไม่ได้รับอนุญาต');
        if (!parts.searchParams.get('v')) throw new Error('ลิงก์ไม่มีรหัสซองของขวัญ (พารามิเตอร์ v)');
        return url;
    },

    /* ---------------- Payment attempts ---------------- */

    attemptCreate(orderId, provider, fingerprint) {
        const row = Store.insert(CONFIG.keyAttempts, {
            order_id: orderId,
            provider,
            provider_reference: null,
            request_fingerprint: fingerprint,
            amount_satang: null,
            status: 'created',
            response_code: null,
            created_at: nowUtcString(),
            updated_at: nowUtcString(),
        }, () => []);
        return row.id; // PHP storage_insert() returns the new row's id
    },

    attemptUpdate(id, status, reference = null, amountSatang = null, responseCode = null) {
        Store.updateWhere(
            CONFIG.keyAttempts,
            r => Number(r.id) === Number(id),
            r => {
                r.status = status;
                if (reference !== null) r.provider_reference = reference;
                if (amountSatang !== null) r.amount_satang = amountSatang;
                if (responseCode !== null) r.response_code = responseCode;
                r.updated_at = nowUtcString();
                return r;
            },
            () => []
        );
    },

    attemptByFingerprint(fingerprint) {
        return Store.find(CONFIG.keyAttempts, r => r.request_fingerprint === fingerprint, () => []);
    },

    async hmacSha256Hex(text, keyText) {
        const key = await crypto.subtle.importKey('raw', new TextEncoder().encode(keyText), { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']);
        const sig = await crypto.subtle.sign('HMAC', key, new TextEncoder().encode(text));
        return Array.from(new Uint8Array(sig), b => b.toString(16).padStart(2, '0')).join('');
    },

    /* ---------------- Orchestration ---------------- */

    async paymentStartPromptpay(publicId) {
        const order = Orders.byPublicId(publicId);
        if (!order || order.status !== 'pending') throw new Error('Order not available');

        // Reuse an unexpired transaction
        if (order.provider_transaction_id && order.provider_expires_at) {
            const exp = parseUtc(order.provider_expires_at);
            if (exp && exp > new Date()) {
                return {
                    transactionId: order.provider_transaction_id,
                    qrUrl: order.provider_qr_url,
                    amountSatang: Number(order.amount_satang || 0),
                    expiresAt: order.provider_expires_at,
                    status: order.status,
                };
            }
        }

        const qr = await this.generatePromptpay();

        Orders.setProvider(Number(order.id), qr.transactionId, qr.expiresAt, nowUtcString(), qr.qrUrl);
        Orders.setAmount(Number(order.id), qr.amountSatang);

        const attemptId = this.attemptCreate(Number(order.id), 'promptpay', null);
        this.attemptUpdate(attemptId, 'pending', qr.transactionId, qr.amountSatang);

        return { transactionId: qr.transactionId, qrUrl: qr.qrUrl, amountSatang: qr.amountSatang, expiresAt: qr.expiresAt, status: order.status };
    },

    async paymentRefreshPromptpay(publicId) {
        const order = Orders.byPublicId(publicId);
        if (!order) throw new Error('Order not found');

        if (order.status !== 'pending') {
            return { status: order.status, paid: order.status === 'paid' };
        }

        const id = Number(order.id);

        // Throttle to once per 10s
        const nextCheck = parseUtc(order.next_provider_check_at);
        if (nextCheck && nextCheck > new Date()) {
            return { status: 'pending', paid: false, throttled: true };
        }

        // Provider expiry
        const providerExp = parseUtc(order.provider_expires_at);
        if (providerExp && providerExp <= new Date()) {
            Orders.markExpired(id);
            return { status: 'expired', paid: false };
        }

        if (!order.provider_transaction_id) {
            return { status: 'pending', paid: false };
        }

        const check = await this.checkPromptpay(order.provider_transaction_id);

        if (!check) {
            Orders.setNextCheck(id, nowUtcString());
            return { status: 'pending', paid: false };
        }

        const minExpected = CONFIG.priceSatang;
        const maxExpected = minExpected + 5000;

        if (check.status === 'success') {
            if (check.transactionId !== order.provider_transaction_id) {
                Orders.markFailed(id);
                return { status: 'failed', paid: false };
            }
            const paidAmount = check.amountSatang;
            if (paidAmount === null || paidAmount < minExpected || paidAmount > maxExpected) {
                Orders.markFailed(id);
                return { status: 'failed', paid: false };
            }
            if (Number(order.amount_satang || 0) !== paidAmount) {
                Orders.setAmount(id, paidAmount);
            }
            const paid = Orders.markPaid(id, check.transactionId, paidAmount);
            return { status: 'paid', paid };
        }

        if (check.status === 'pending') {
            // PHP: next check = now + 10s (real throttle, avoids hammering the API)
            const next = futureUtcString(10000);
            Orders.setNextCheck(id, next);
            if (check.expiresAt) {
                Orders.setProvider(id, order.provider_transaction_id, check.expiresAt, next);
            }
            return { status: 'pending', paid: false };
        }

        Orders.markFailed(id);
        return { status: 'failed', paid: false };
    },

    async paymentRedeemTruewallet(publicId, voucherUrl) {
        const order = Orders.byPublicId(publicId);
        if (!order || order.status !== 'pending') throw new Error('Order not available');

        const url = this.validateVoucherUrl(voucherUrl);
        const expected = CONFIG.priceSatang;
        const fingerprint = await this.hmacSha256Hex(url, CONFIG.apiKey + CONFIG.priceSatang);
        const id = Number(order.id);

        // Idempotency: same voucher never redeemed twice
        const existing = this.attemptByFingerprint(fingerprint);
        if (existing) {
            return { status: existing.status === 'succeeded' ? 'paid' : 'failed', paid: existing.status === 'succeeded' };
        }

        const attemptId = this.attemptCreate(id, 'truewallet', fingerprint);
        const result = await this.redeemTruewallet(url);

        if (result.status === 'success' && result.amountSatang === expected) {
            this.attemptUpdate(attemptId, 'succeeded', null, result.amountSatang);
            const paid = Orders.markPaid(id, 'truewallet-' + fingerprint.slice(0, 16), expected);
            return { status: 'paid', paid };
        }

        if (result.status === 'success') {
            this.attemptUpdate(attemptId, 'rejected', null, result.amountSatang);
            Orders.markFailed(id);
            return { status: 'failed', paid: false };
        }

        this.attemptUpdate(attemptId, 'error', null, null, 'provider_error');
        return { status: 'pending', paid: false, error: 'ไม่สามารถแลกซองได้ กรุณาลองใหม่' };
    },
};
