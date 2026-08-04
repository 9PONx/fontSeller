/**
 * FontSeller — localStorage JSON store (mirrors PHP storage.php + orders.php)
 */
'use strict';

const Store = {
    _read(key, seed) {
        try {
            const raw = localStorage.getItem(key);
            if (raw) return JSON.parse(raw);
        } catch (e) { /* corrupted -> reseed */ }
        const arr = seed();
        localStorage.setItem(key, JSON.stringify(arr));
        return arr;
    },

    _write(key, arr) {
        localStorage.setItem(key, JSON.stringify(arr));
    },

    _nextId() {
        const n = (parseInt(localStorage.getItem(CONFIG.keySeq) || '0', 10) || 0) + 1;
        localStorage.setItem(CONFIG.keySeq, String(n));
        return n;
    },

    insert(key, row, seed = () => []) {
        const arr = this._read(key, seed);
        row.id = this._nextId();
        arr.push(row);
        this._write(key, arr);
        return row;
    },

    find(key, pred, seed = () => []) {
        return this._read(key, seed).find(pred) ?? null;
    },

    findAll(key, pred = null, seed = () => []) {
        const arr = this._read(key, seed);
        return pred ? arr.filter(pred) : arr;
    },

    updateWhere(key, pred, mutator, seed = () => []) {
        const arr = this._read(key, seed);
        let changed = 0;
        for (let i = 0; i < arr.length; i++) {
            if (pred(arr[i])) {
                arr[i] = mutator({ ...arr[i] });
                changed++;
            }
        }
        if (changed) this._write(key, arr);
        return changed;
    },
};

/* --------------------------- session --------------------------- */

const Session = {
    get() {
        try {
            return JSON.parse(localStorage.getItem(CONFIG.keySession) || '{}');
        } catch (e) {
            return {};
        }
    },
    set(patch) {
        localStorage.setItem(CONFIG.keySession, JSON.stringify({ ...this.get(), ...patch }));
    },
    clear() {
        localStorage.removeItem(CONFIG.keySession);
    },
};

/* --------------------------- orders --------------------------- */

const Orders = {
    PAYMENT_METHODS: ['promptpay', 'truewallet'],
    STATUSES: ['pending', 'paid', 'failed', 'expired'],

    create(name, email, method) {
        name = String(name || '').trim();
        email = String(email || '').trim();
        if (name === '') throw new Error('กรุณากรอกชื่อ');
        if (name.length > 120) throw new Error('ชื่อยาวเกิน 120 ตัวอักษร');
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) throw new Error('รูปแบบอีเมลไม่ถูกต้อง');
        if (!this.PAYMENT_METHODS.includes(method)) throw new Error('เลือกวิธีชำระเงินไม่ถูกต้อง');

        const now = nowUtcString();
        const order = Store.insert(CONFIG.keyOrders, {
            public_id: randomHex(16),
            customer_name: name,
            customer_email: email,
            amount_satang: CONFIG.priceSatang,
            payment_method: method,
            status: 'pending',
            provider_transaction_id: null,
            provider_qr_url: null,
            provider_expires_at: null,
            next_provider_check_at: null,
            paid_at: null,
            created_at: now,
            updated_at: now,
        }, () => []);
        return order;
    },

    byPublicId(publicId) {
        return Store.find(CONFIG.keyOrders, r => r.public_id === publicId, () => []);
    },

    byId(id) {
        return Store.find(CONFIG.keyOrders, r => Number(r.id) === Number(id), () => []);
    },

    markPaid(id, txnId, amountSatang) {
        return Store.updateWhere(
            CONFIG.keyOrders,
            r => Number(r.id) === Number(id) && r.status === 'pending' && Number(r.amount_satang) === Number(amountSatang),
            r => {
                r.status = 'paid';
                r.provider_transaction_id = txnId;
                r.paid_at = nowUtcString();
                r.updated_at = nowUtcString();
                return r;
            },
            () => []
        ) === 1;
    },

    markFailed(id) {
        return Store.updateWhere(
            CONFIG.keyOrders,
            r => Number(r.id) === Number(id) && r.status === 'pending',
            r => { r.status = 'failed'; r.updated_at = nowUtcString(); return r; },
            () => []
        ) === 1;
    },

    markExpired(id) {
        return Store.updateWhere(
            CONFIG.keyOrders,
            r => Number(r.id) === Number(id) && r.status === 'pending',
            r => { r.status = 'expired'; r.updated_at = nowUtcString(); return r; },
            () => []
        ) === 1;
    },

    setProvider(id, txnId, expiresAt, nextCheckAt, qrUrl) {
        Store.updateWhere(
            CONFIG.keyOrders,
            r => Number(r.id) === Number(id),
            r => {
                r.provider_transaction_id = txnId;
                if (expiresAt) r.provider_expires_at = expiresAt;
                if (nextCheckAt) r.next_provider_check_at = nextCheckAt;
                if (qrUrl) r.provider_qr_url = qrUrl;
                r.updated_at = nowUtcString();
                return r;
            },
            () => []
        );
    },

    setAmount(id, amountSatang) {
        Store.updateWhere(
            CONFIG.keyOrders,
            r => Number(r.id) === Number(id),
            r => { r.amount_satang = Number(amountSatang); r.updated_at = nowUtcString(); return r; },
            () => []
        );
    },

    setNextCheck(id, nextCheckAt) {
        Store.updateWhere(
            CONFIG.keyOrders,
            r => Number(r.id) === Number(id),
            r => { r.next_provider_check_at = nextCheckAt; r.updated_at = nowUtcString(); return r; },
            () => []
        );
    },
};
