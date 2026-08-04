/**
 * FontSeller — download tokens (mirrors PHP downloads.php) + sample pack zip
 */
'use strict';

const Downloads = {
    /* ---------------- tokens ---------------- */

    tokenByOrder(orderId) {
        return Store.find(CONFIG.keyTokens, r => Number(r.order_id) === Number(orderId), () => []);
    },

    issueToken(orderId) {
        const raw = randomHex(32);
        return sha256Hex(raw).then(hash => {
            const now = nowUtcString();
            const expires = new Date(Date.now() + CONFIG.downloadTtlHours * 3600 * 1000).toISOString().slice(0, 19).replace('T', ' ');
            Store.insert(CONFIG.keyTokens, {
                order_id: orderId,
                token_hash: hash,
                expires_at: expires,
                download_count: 0,
                max_downloads: CONFIG.downloadMaxCount,
                created_at: now,
                updated_at: now,
            }, () => []);
            return raw;
        });
    },

    rotateToken(orderId) {
        return this.issueToken(orderId);
    },

    /** Returns the token record + order, or null. Mirrors download_authorize(). */
    async authorize(rawToken) {
        if (!rawToken || rawToken.length < 10) return null;
        const hash = await sha256Hex(rawToken);
        const token = Store.find(CONFIG.keyTokens, r => r.token_hash === hash, () => []);
        if (!token) return null;

        const order = Orders.byId(token.order_id);
        if (!order || order.status !== 'paid') return null;

        const exp = parseUtc(token.expires_at);
        if (exp && exp <= new Date()) return null;
        if (token.download_count >= token.max_downloads) return null;

        return { token, order };
    },

    consume(tokenId) {
        return Store.updateWhere(
            CONFIG.keyTokens,
            r => Number(r.id) === Number(tokenId) && r.download_count < r.max_downloads,
            r => { r.download_count = Number(r.download_count) + 1; r.updated_at = nowUtcString(); return r; },
            () => []
        ) === 1;
    },

    /* ---------------- sample pack ---------------- */

    /** Builds a ZIP of the bundled sample fonts using JSZip (loaded from CDN). */
    async buildSampleZip() {
        if (typeof JSZip === 'undefined') {
            throw new Error('JSZip library not loaded');
        }
        const zip = new JSZip();
        const defs = Fonts.sampleFontDefs();
        const seen = new Set();
        for (const def of defs) {
            for (const file of def.files) {
                const name = file.split('/').pop();
                if (seen.has(name)) continue;
                seen.add(name);
                const res = await fetch(file);
                if (!res.ok) continue;
                zip.file(name, await res.arrayBuffer());
            }
        }
        // Include the full font name list too
        if (Fonts.stats) {
            zip.file('all-font-names.txt', Fonts.stats.supportedNames.join('\n'));
        }
        const blob = await zip.generateAsync({ type: 'blob' });
        return blob;
    },

    triggerDownload(blob, filename) {
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 2000);
    },

    /** Downloads the full font list as a text file (no server needed). */
    downloadFontListTxt() {
        const text = Fonts.stats ? Fonts.stats.supportedNames.join('\n') : '';
        const blob = new Blob([text], { type: 'text/plain;charset=utf-8' });
        this.triggerDownload(blob, 'fontseller-all-font-names.txt');
    },
};
