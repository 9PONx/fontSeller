const { chromium } = require('playwright');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const contentTypes = {
  '.html': 'text/html; charset=utf-8',
  '.js': 'text/javascript; charset=utf-8',
  '.json': 'application/json; charset=utf-8',
  '.ttf': 'font/ttf',
  '.otf': 'font/otf',
  '.woff2': 'font/woff2',
};
const server = http.createServer((request, response) => {
  const pathname = decodeURIComponent(new URL(request.url, 'http://127.0.0.1').pathname);
  let target = path.resolve(root, '.' + pathname);
  if (target === root) target = path.join(root, 'index.html');
  if (!target.startsWith(root + path.sep)) {
    response.writeHead(403).end();
    return;
  }
  fs.readFile(target, (error, body) => {
    if (error) {
      response.writeHead(error.code === 'ENOENT' ? 404 : 500).end();
      return;
    }
    response.writeHead(200, { 'Content-Type': contentTypes[path.extname(target)] || 'application/octet-stream' });
    response.end(body);
  });
});
const listen = () => new Promise((resolve, reject) => {
  server.once('error', reject);
  server.listen(0, '127.0.0.1', () => resolve(server.address().port));
});
const closeServer = () => new Promise(resolve => server.close(resolve));
let browser;

(async () => {
  const port = await listen();
  browser = await chromium.launch({ channel: 'chrome' });
  const page = await browser.newPage();
  const errors = [];
  page.on('pageerror', e => errors.push('PAGEERROR: ' + e.message));
  page.on('console', m => { if (m.type() === 'error') errors.push('CONSOLE: ' + m.text()); });
  await page.route('https://api.inwcloud.shop/v1/promptpay/generate', route => route.abort());

  await page.goto(`http://127.0.0.1:${port}/`, { waitUntil: 'networkidle' });

  // 1) Home renders with stats
  await page.waitForSelector('text=ฟอนต์ในชุด');
  const hero = await page.textContent('h1');
  console.log('home hero:', hero.trim().replace(/\s+/g, ' '));
  const packCount = await page.textContent('.grid > div p');
  console.log('pack count stat:', packCount);

  // generated catalog and visible list must describe the same bundle
  const catalogCount = await page.evaluate(async () => {
    const response = await fetch('data/fonts.json');
    return (await response.json()).length;
  });
  if (catalogCount < 400) throw new Error('filtered catalog unexpectedly small: ' + catalogCount);
  await page.waitForFunction(expected => {
    return document.getElementById('fontNames').innerText.split('\n').length === expected;
  }, catalogCount);
  const listCount = await page.evaluate(() => document.getElementById('fontNames').innerText.split('\n').length);
  const statsCount = await page.evaluate(() => Fonts.stats.packCount);
  if (listCount !== catalogCount || statsCount !== catalogCount) {
    throw new Error(`catalog/list/stats mismatch: ${catalogCount}/${listCount}/${statsCount}`);
  }
  console.log('font list lines:', listCount);

  // search
  await page.fill('#fontSearch', 'sarabun');
  await page.waitForFunction(() => document.getElementById('searchStatus').textContent.includes('พบ'));
  console.log('search status:', await page.textContent('#searchStatus'));
  await page.fill('#fontSearch', '');

  // sample preview fonts loaded
  await page.waitForSelector('#previewGrid .rounded-xl');
  const previewCards = await page.$$eval('#previewGrid .rounded-xl', els => els.length);
  if (previewCards !== 9) throw new Error('expected 9 preview examples, got ' + previewCards);
  console.log('preview cards:', previewCards);

  // 2) Checkout
  await page.click('a[href="#/checkout"]');
  await page.waitForSelector('#checkoutForm');
  await page.fill('#customer_name', 'Playwright Test');
  await page.fill('#customer_email', 'pw@test.com');
  await page.click('#checkoutForm button[type=submit]');

  // 3) Pay view
  await page.waitForSelector('text=ชำระเงินด้วย PromptPay', { timeout: 15000 });
  console.log('pay view OK — URL:', page.url());
  await page.waitForTimeout(6000); // allow QR generation attempt


  const qrState = await page.evaluate(() => {
    const qr = document.getElementById('qrBox').innerText;
    const demo = document.getElementById('ppDemoBox');
    return { qrText: qr.slice(0, 80), demoVisible: demo && !demo.classList.contains('hidden') };
  });
  console.log('QR box:', JSON.stringify(qrState));

  if (qrState.demoVisible) {
    console.log('CORS blocked → demo fallback shown (expected on some setups)');
    await page.click('#demoPayBtn');
    await page.waitForSelector('text=ชำระเงินสำเร็จ', { timeout: 10000 });
  } else {
    // countdown must be a sane mm:ss when the provider returned a real QR
    const cd = await page.textContent('#countdown');
    const m = (cd || '').match(/^(\d+):(\d{2})$/);
    const cdOk = m && Number(m[1]) <= 60;
    console.log('countdown:', cd, cdOk ? 'OK' : '❌ broken');
    if (!cdOk) throw new Error('countdown broken: ' + cd);
    // QR generated (real API reachable) — demo button not available; simulate by marking paid
    await page.evaluate(() => {
      // mark current order paid via store for test purposes
      const o = Orders.byPublicId(new URLSearchParams(location.hash.split('?')[1]).get('id'));
      Orders.markPaid(o.id, 'e2e-test', o.amount_satang);
    });
    await page.waitForTimeout(7000); // next poll sees paid
    await page.waitForSelector('text=ชำระเงินสำเร็จ', { timeout: 15000 });
  }

  // 4) Success + download
  await page.waitForSelector('#downloadBtn', { timeout: 15000 });
  console.log('success view OK — order id shown:', (await page.textContent('section .bg-gray-50')).replace(/\s+/g, ' ').slice(0, 90));

  await page.route('**/downloads/fontseller-open-fonts.zip', route => route.fulfill({
    status: 200,
    contentType: 'application/zip',
    headers: { 'Content-Disposition': 'attachment; filename="fontseller-open-fonts.zip"' },
    body: Buffer.from('PK\u0005\u0006' + '\u0000'.repeat(18), 'binary'),
  }));
  const dl = page.waitForEvent('download', { timeout: 30000 });
  await page.click('#downloadBtn');
  const download = await dl;
  if (download.suggestedFilename() !== 'fontseller-open-fonts.zip') {
    throw new Error('wrong download filename: ' + download.suggestedFilename());
  }
  console.log('download:', download.suggestedFilename(), '(large response intercepted)');

  // 5) Recover an order that an older cached build prematurely expired.
  const recoveryTxn = 'Market-403-1785837007-513a73973c911f331548';
  await page.route('https://api.inwcloud.shop/v1/promptpay/check', route => route.fulfill({
    status: 200,
    contentType: 'application/json',
    body: JSON.stringify({
      status: 'success',
      message: 'ชำระเงินสำเร็จ',
      transactionId: recoveryTxn,
      amount: '100.12',
      customer_type: 'existing',
      cost: 0,
    }),
  }));
  const recoveryPublicId = await page.evaluate(txn => {
    const order = Orders.create('Recovery Test', 'recovery@test.com', 'promptpay');
    Orders.setProvider(order.id, txn, '2026-08-04 09:51:00', null, 'https://api.qrserver.com/v1/create-qr-code/?data=recovery&size=300x300');
    Orders.markExpired(order.id);
    Session.set({ order_public_id: order.public_id });
    return order.public_id;
  }, recoveryTxn);
  await page.evaluate(id => Router.navigate('pay', { id }), recoveryPublicId);
  await page.waitForSelector('#downloadBtn', { timeout: 5000 });
  const recoveredOrder = await page.evaluate(id => Orders.byPublicId(id), recoveryPublicId);
  if (recoveredOrder.status !== 'paid' || recoveredOrder.amount_satang !== 10012) {
    throw new Error('expired PromptPay order was not recovered: ' + JSON.stringify(recoveredOrder));
  }
  console.log('expired PromptPay recovery OK:', recoveredOrder.status, recoveredOrder.amount_satang);

  const pageErrors = errors.filter(e => !e.includes('net::ERR') && !e.includes('favicon'));
  console.log('JS errors:', pageErrors.length ? pageErrors : 'none');
  await browser.close();
  browser = null;
  await closeServer();
  if (pageErrors.length) process.exit(1);
  console.log('E2E PASSED');
})().catch(async e => {
  if (browser) await browser.close();
  if (server.listening) await closeServer();
  console.error('E2E FAIL:', e.message);
  process.exit(1);
});
