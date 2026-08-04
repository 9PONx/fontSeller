const { chromium } = require('playwright');

(async () => {
  const browser = await chromium.launch({ channel: 'chrome' });
  const page = await browser.newPage();
  const errors = [];
  page.on('pageerror', e => errors.push('PAGEERROR: ' + e.message));
  page.on('console', m => { if (m.type() === 'error') errors.push('CONSOLE: ' + m.text()); });

  await page.goto('http://127.0.0.1:8123/', { waitUntil: 'networkidle' });

  // 1) Home renders with stats
  await page.waitForSelector('text=ฟอนต์ในชุด');
  const hero = await page.textContent('h1');
  console.log('home hero:', hero.trim().replace(/\s+/g, ' '));
  const packCount = await page.textContent('.grid > div p');
  console.log('pack count stat:', packCount);

  // font list populated
  await page.waitForFunction(() => document.getElementById('fontNames').innerText.split('\n').length > 3000);
  console.log('font list lines:', await page.evaluate(() => document.getElementById('fontNames').innerText.split('\n').length));

  // search
  await page.fill('#fontSearch', 'sarabun');
  await page.waitForFunction(() => document.getElementById('searchStatus').textContent.includes('พบ'));
  console.log('search status:', await page.textContent('#searchStatus'));
  await page.fill('#fontSearch', '');

  // sample preview fonts loaded
  await page.waitForSelector('#previewGrid .rounded-xl');
  const previewCards = await page.$$eval('#previewGrid .rounded-xl', els => els.length);
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

  // countdown must be a sane mm:ss (regression: ms-vs-seconds unit bug)
  const cd = await page.textContent('#countdown');
  const m = (cd || '').match(/^(\d+):(\d{2})$/);
  const cdOk = m && Number(m[1]) <= 60;
  console.log('countdown:', cd, cdOk ? 'OK' : '❌ broken');
  if (!cdOk) throw new Error('countdown broken: ' + cd);

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

  const dl = page.waitForEvent('download', { timeout: 30000 });
  await page.click('#downloadBtn');
  const download = await dl;
  console.log('download:', download.suggestedFilename());
  let size = 0;
  for await (const c of await download.createReadStream()) size += c.length;
  console.log('download size:', size, 'bytes');

  const pageErrors = errors.filter(e => !e.includes('net::ERR') && !e.includes('favicon'));
  console.log('JS errors:', pageErrors.length ? pageErrors : 'none');
  await browser.close();
  if (pageErrors.length) process.exit(1);
  console.log('E2E PASSED');
})().catch(e => { console.error('E2E FAIL:', e.message); process.exit(1); });
