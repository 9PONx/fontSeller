#!/usr/bin/env node
'use strict';

const assert = require('node:assert');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const clicks = [];
const appended = [];
const document = {
    body: {
        appendChild(node) { appended.push(node); },
    },
    createElement(tag) {
        assert.strictEqual(tag, 'a');
        return {
            href: '',
            download: '',
            rel: '',
            click() { clicks.push({ href: this.href, download: this.download, rel: this.rel }); },
            remove() {},
        };
    },
};

const context = vm.createContext({
    console,
    document,
    URL,
    Blob,
    TextEncoder,
    Uint8Array,
    crypto: require('node:crypto').webcrypto,
    setTimeout(callback) { callback(); },
});

for (const file of ['config.js', 'downloads.js']) {
    const source = fs.readFileSync(path.join(__dirname, '../../../js', file), 'utf8');
    new vm.Script(source, { filename: file }).runInContext(context);
}

new vm.Script(`Downloads.triggerFileDownload(CONFIG.fullZipUrl, CONFIG.fullZipName)`)
    .runInContext(context);

assert.strictEqual(appended.length, 1, 'download link should be attached once');
assert.deepStrictEqual(clicks, [{
    href: 'downloads/fontseller-open-fonts.zip',
    download: 'fontseller-open-fonts.zip',
    rel: 'noopener',
}]);

console.log('1 static archive download test passed');
