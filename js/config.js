/**
 * FontSeller — JavaScript version (static / GitHub Pages)
 * Config + shared helpers
 */
'use strict';

const CONFIG = {
    // --- InwCloud (real API, called from the browser per user request) ---
    apiBase: 'https://api.inwcloud.shop',
    // NOTE: key is public on purpose (static site). Rotate at inwcloud.shop if needed.
    apiKey: 'inwcloud_live_ec23fd98c7d229933d9a6904f1a076cf5c3c06b4',
    apiTimeoutMs: 15000,
    qrHost: 'api.qrserver.com',

    // --- Product ---
    priceSatang: 10000,            // 100.00 THB
    priceThb: '100.00',

    // --- Catalog ---
    fontsJsonUrl: 'data/fonts.json',

    // --- Downloads ---
    downloadTtlHours: 24,
    downloadMaxCount: 3,
    demoZipName: 'fontseller-demo-fonts.zip',

    // --- Storage keys ---
    keyOrders: 'fontseller.orders',
    keyAttempts: 'fontseller.attempts',
    keyTokens: 'fontseller.tokens',
    keySession: 'fontseller.session',
    keySeq: 'fontseller.seq',
};

/* --------------------------- helpers --------------------------- */

function moneyTHB(satang) {
    return (Number(satang) / 100).toFixed(2) + ' THB';
}

function nowUtcString() {
    return new Date().toISOString().slice(0, 19).replace('T', ' ');
}

/**
 * Parse "YYYY-MM-DD HH:MM:SS" (UTC wall clock, as stored by nowUtcString)
 * as UTC — NOT local time. Fixes the 7h skew (GMT+7) that made payment
 * polls think the QR already expired / always throttled.
 */
function parseUtc(str) {
    if (!str) return null;
    let s = String(str).trim();
    if (!/[zZ]$|[+-]\d{2}:?\d{2}$/.test(s)) s = s.replace(' ', 'T') + 'Z';
    else s = s.replace(' ', 'T');
    const d = new Date(s);
    return isNaN(d.getTime()) ? null : d;
}

function futureUtcString(msFromNow) {
    return new Date(Date.now() + msFromNow).toISOString().slice(0, 19).replace('T', ' ');
}

function randomHex(bytes) {
    const arr = new Uint8Array(bytes);
    crypto.getRandomValues(arr);
    return Array.from(arr, b => b.toString(16).padStart(2, '0')).join('');
}

function sha256Hex(text) {
    return crypto.subtle.digest('SHA-256', new TextEncoder().encode(text))
        .then(buf => Array.from(new Uint8Array(buf), b => b.toString(16).padStart(2, '0')).join(''));
}

function escapeHtml(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;').replaceAll("'", '&#39;');
}

/** Approximates PHP strnatcasecmp — natural sort, case-insensitive. */
function naturalCompare(a, b) {
    return String(a).localeCompare(String(b), undefined, { numeric: true, sensitivity: 'base' });
}

function formatCount(n) {
    return Number(n).toLocaleString('th-TH');
}

function parseAmountSatang(value) {
    const str = String(value ?? '').trim();
    if (!/^-?\d+(\.\d{1,2})?$/.test(str)) return null;
    return Math.round(parseFloat(str) * 100);
}
