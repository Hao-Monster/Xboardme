const assert = require('node:assert/strict');
const fs = require('node:fs');
const { test, before, after } = require('node:test');
const { launchBrowser } = require('./helpers/browser-runtime.cjs');

let browser;
before(async () => { browser = await launchBrowser(); });
after(async () => { await browser?.close(); });

const qrSvg = 'data:image/svg+xml;base64,' + Buffer.from(
  '<svg xmlns="http://www.w3.org/2000/svg" width="540" height="540"><rect x="1" y="1" width="538" height="538" fill="#ffffff" stroke="#287c83" stroke-width="2"/><text x="270" y="275" text-anchor="middle" font-family="sans-serif" font-size="28" fill="#17212b">QR</text></svg>'
).toString('base64');

function assertPngBuffer(buffer, label) {
  assert.deepEqual(
    Array.from(buffer.subarray(0, 8)),
    [0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a],
    `${label} must be a valid PNG`
  );
}

for (const width of [390, 1440]) {
  test(`batch quantity selection, manual 10-image PNG delivery with top-left card header, and sticky idempotent recovery at ${width}px`, async () => {
    const page = await browser.newPage({ viewport: { width, height: 900 }, acceptDownloads: true });
    page.setDefaultTimeout(5000);
    try {
      const errors = [];
      page.on('pageerror', (err) => errors.push(err.message));

      let savedPayload = null;
      const savedAttempts = [];
      let failNextOrderSaveWith503 = false;
      let pollCount = 0;
      const deliveries = new Map();

      await page.route('**/*', async (route) => {
        const req = route.request();
        const url = new URL(req.url());
        if (url.protocol === 'blob:' || url.protocol === 'data:') {
          return route.continue();
        }
        if (url.pathname === '/') {
          return route.fulfill({
            contentType: 'text/html',
            body: '<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><div id="app"></div>',
          });
        }
        if (url.pathname.endsWith('/user/info')) {
          return route.fulfill({ json: { data: { id: 7, email: 'dealer@example.invalid', distributor_name: '测试', is_distributor: true } } });
        }
        if (url.pathname.endsWith('/user/notice/fetch')) {
          return route.fulfill({ json: { data: [], total: 0 } });
        }
        if (url.pathname.endsWith('/user/plan/fetch')) {
          return route.fulfill({
            json: {
              data: [{
                id: 11,
                name: '代理商线路',
                transfer_enable: 500,
                speed_limit: null,
                device_limit: 11,
                reset_traffic_method: 1,
                month_price: 3000,
                quarter_price: 8000,
                half_year_price: 15000,
                year_price: 28000,
                capacity_limit: null,
              }],
            },
          });
        }
        if (url.pathname.endsWith('/user/order/fetch')) {
          const perPage = url.searchParams.get('per_page');
          const idempotencyKey = url.searchParams.get('idempotency_key');
          if (!idempotencyKey && perPage !== null && !['20', '50', '100'].includes(perPage)) {
            return route.fulfill({
              status: 422,
              contentType: 'application/json',
              body: JSON.stringify({ message: 'The selected per page is invalid.', errors: { per_page: ['The selected per page is invalid.'] } }),
            });
          }
          if (idempotencyKey) {
            const perPageNum = perPage === null ? 500 : Number(perPage);
            if (!Number.isInteger(perPageNum) || perPageNum < 1 || perPageNum > 500) {
              return route.fulfill({
                status: 422,
                contentType: 'application/json',
                body: JSON.stringify({ message: 'Invalid idempotency per_page', errors: { per_page: ['Invalid'] } }),
              });
            }
            return route.fulfill({ json: { data: [], total: 0, current_page: 1, per_page: perPageNum, last_page: 1 } });
          }
          // Non-empty historical order list exercises the real idempotency reconciliation branch
          return route.fulfill({
            json: {
              data: [{
                id: 9001,
                trade_no: '202610010000999',
                type: 1,
                period: 'month_price',
                total_amount: 3000,
                settlement_status: 0,
                created_at: 1790812800,
                plan: { id: 11, name: '代理商线路' },
              }],
              total: 1,
              current_page: 1,
              per_page: Number(perPage || 20),
              last_page: 1,
            },
          });
        }
        if (url.pathname.endsWith('/user/distributor/statistics')) {
          return route.fulfill({ json: { data: { order_count: 0, total_amount: 0, settled_amount: 0, unsettled_amount: 0 } } });
        }
        if (url.pathname.endsWith('/user/order/save')) {
          const params = new URLSearchParams(req.postData() || '');
          const qty = Number(params.get('quantity') || 1);
          savedPayload = Object.fromEntries(params.entries());
          savedAttempts.push(savedPayload);
          if (failNextOrderSaveWith503) {
            failNextOrderSaveWith503 = false;
            return route.fulfill({
              status: 503,
              contentType: 'application/json',
              body: JSON.stringify({ message: 'Gateway Timeout' }),
            });
          }
          const tradeNos = Array.from({ length: qty }, (_, idx) => `2026102822300000${String(idx + 1).padStart(2, '0')}`);
          tradeNos.forEach((no, idx) => {
            const seq = String(idx + 1).padStart(2, '0');
            deliveries.set(no, {
              trade_no: no,
              subscription_code: `CODE${seq}`,
              subscription_name: `测试-261028-CODE${seq}`,
              customer_name: '',
              plan_id: 11,
              plan_name: '代理商线路',
              period: params.get('period') || 'month_price',
              delivery_status: 0,
              settlement_status: 0,
              config_issued_at: null,
              connected_at: null,
              connected_node_name: null,
              hwid_enabled: true,
              hwid_limit: 2,
              hwid_devices: [],
              qr_code: qrSvg,
            });
          });
          return route.fulfill({ json: { data: qty > 1 ? { trade_nos: tradeNos, quantity: qty } : tradeNos[0] } });
        }
        if (url.pathname.endsWith('/user/distributor/delivery/batch')) {
          const params = new URLSearchParams(req.postData() || '');
          const tradeNos = (params.get('trade_nos') || '').split(',').filter(Boolean);
          const includeQr = params.get('include_qr') !== '0';
          if (!includeQr) {
            pollCount += 1;
            const first = deliveries.get('202610282230000001');
            const second = deliveries.get('202610282230000002');
            if (pollCount === 1 && first) {
              first.delivery_status = 1;
              first.config_issued_at = null;
            } else if (pollCount >= 2 && first && second) {
              first.delivery_status = 1;
              first.config_issued_at = 1793215800;
              first.connected_at = 1793215805;
              first.connected_node_name = '香港01';
              second.delivery_status = 1;
              second.config_issued_at = 1793215802;
            }
          }
          const result = tradeNos.map((no) => {
            const item = { ...deliveries.get(no) };
            if (!includeQr) delete item.qr_code;
            return item;
          });
          return route.fulfill({ json: { data: result } });
        }
        return route.abort();
      });

      await page.goto('http://127.0.0.1/');
      await page.evaluate(() => {
        localStorage.setItem('VUE_NAIVE_ACCESS_TOKEN', JSON.stringify({ value: 'fixture', expire: null }));
        location.hash = '#/plan';
      });
      await page.addStyleTag({ content: fs.readFileSync('theme/Xboard/assets/distributor.css', 'utf8') });
      await page.addScriptTag({ content: fs.readFileSync('theme/Xboard/assets/distributor.js', 'utf8') });

      await page.locator('[data-plan-qty-input="11"]').waitFor();
      assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), true, `no horizontal overflow at ${width}px`);

      // Uncertain 503 write with empty /user/order/fetch MUST NOT unlock a fresh batch; resume replays the exact same idempotency_key
      await page.locator('[data-plan-qty-input="11"]').fill('2');
      failNextOrderSaveWith503 = true;
      await page.locator('[data-buy="11"]').click();
      await page.locator('[data-action="resume-purchase"]').waitFor();
      assert.equal(savedAttempts.length, 1);
      assert.equal(savedAttempts[0].quantity, '2');
      assert.ok(savedAttempts[0].idempotency_key);

      await page.locator('[data-action="check-purchase-orders"]').click();
      await page.locator('#dist-order-search').waitFor();
      assert.equal(await page.locator('[data-action="acknowledge-order-check"]').count(), 0, 'empty order list must not unlock duplicate purchase');
      await page.locator('[data-nav="/plan"]:visible').first().click();
      await page.locator('[data-buy="11"]').waitFor();
      assert.equal(await page.locator('[data-buy="11"]').isDisabled(), true, 'buy button must remain locked until uncertain write resolves');

      const downloads = [];
      page.on('download', (dl) => downloads.push(dl));

      await page.locator('[data-action="resume-purchase"]').click();
      await page.locator('.dist-batch-qr-modal').waitFor();
      await page.waitForTimeout(150);
      assert.equal(downloads.length, 0, 'opening recovered batch modal must NOT auto-download');
      assert.equal(savedAttempts.length, 2);
      assert.equal(savedAttempts[1].idempotency_key, savedAttempts[0].idempotency_key, 'uncertain recovery must reuse original idempotency_key');
      assert.equal(savedAttempts[1].quantity, '2');
      await page.locator('#dist-modal-root .dist-modal-x').click();
      pollCount = 0;
      deliveries.clear();

      // Default quantity = 1
      assert.equal(await page.locator('[data-plan-qty-input="11"]').inputValue(), '1');
      assert.equal(await page.locator('[data-buy="11"]').innerText(), '已确认，直接下单');

      // Quick-select 5 -> toggle off -> Quick-select 10 -> free input 10
      await page.locator('[data-plan-qty-preset="5"]').click();
      assert.equal(await page.locator('[data-plan-qty-input="11"]').inputValue(), '5');
      assert.match(await page.locator('[data-buy="11"]').innerText(), /已确认，直接下单（5个）/);
      assert.doesNotMatch(await page.locator('[data-buy="11"]').innerText(), /¥/, 'price privacy must stay hidden');

      // Toggle off back to 1 when clicking active preset
      await page.locator('[data-plan-qty-preset="5"]').click();
      assert.equal(await page.locator('[data-plan-qty-input="11"]').inputValue(), '1');

      // Select 10, open prices, verify multiplied total & savings
      await page.locator('[data-plan-period="quarter_price"]').click();
      await page.locator('[data-plan-qty-preset="10"]').click();
      await page.locator('[data-action="toggle-plan-prices"]').click();
      const pricedActionText = await page.locator('[data-buy="11"]').innerText();
      assert.match(pricedActionText, /合计 ¥800\.00/);
      assert.match(pricedActionText, /共省 ¥100\.00/);
      assert.match(pricedActionText, /已确认，直接下单（10个）/);

      // Pasting >= 1000 clamps to 500 with warning toast instead of silently truncating to 100
      const qtyInput = page.locator('[data-plan-qty-input="11"]');
      await qtyInput.fill('1000');
      assert.equal(await qtyInput.inputValue(), '500');
      await page.locator('.dist-toast').filter({ hasText: '单次最多支持 500 个订阅' }).waitFor();

      // Free input typing = 10 without losing focus
      await qtyInput.fill('10');
      assert.match(await page.locator('[data-buy="11"]').innerText(), /合计 ¥800\.00/);

      // Direct checkout without confirmation -> opens 10 QR cards WITHOUT automatic download
      await page.locator('[data-buy="11"]').click();
      await page.locator('.dist-batch-qr-modal').waitFor();
      await page.waitForTimeout(200);
      assert.equal(downloads.length, 0, 'opening batch QR modal must NOT auto-download any ZIP or image');

      assert.equal(savedPayload.quantity, '10');
      assert.ok(savedPayload.idempotency_key);
      assert.equal(await page.locator('.dist-batch-qr-card').count(), 10);
      assert.equal(await page.locator('.dist-batch-qr-card .dist-subscription-qr-preview').count(), 10);

      // Card #2 keeps top-left UI header AND renders #2 + subscription name + order number in top-left of PNG
      const secondCard = page.locator('.dist-batch-qr-card').nth(1);
      const secondHead = secondCard.locator('.dist-batch-qr-card-head');
      const secondHeadText = await secondHead.innerText();
      assert.match(secondHeadText, /#2/);
      assert.match(secondHeadText, /测试-261028-CODE02/);
      assert.match(secondHeadText, /订单号：202610282230000002/);
      const [cardBox, headBox] = await Promise.all([secondCard.boundingBox(), secondHead.boundingBox()]);
      assert.ok(headBox.x - cardBox.x < 30 && headBox.y - cardBox.y < 30, 'card head must stay in top-left corner');

      const topLeftHasHeaderInk = await secondCard.locator('.dist-subscription-qr-preview').evaluate(async (img) => {
        const probe = document.createElement('canvas');
        probe.width = img.naturalWidth;
        probe.height = img.naturalHeight;
        const ctx = probe.getContext('2d');
        ctx.drawImage(img, 0, 0);
        const badgePatch = ctx.getImageData(52, 38, 180, 64).data;
        let nonWhitePixels = 0;
        for (let i = 0; i < badgePatch.length; i += 4) {
          if (badgePatch[i] < 250 || badgePatch[i + 1] < 250 || badgePatch[i + 2] < 250) nonWhitePixels += 1;
        }
        return { width: img.naturalWidth, height: img.naturalHeight, nonWhitePixels };
      });
      assert.equal(topLeftHasHeaderInk.width, 760);
      assert.ok(topLeftHasHeaderInk.height > 800, 'batch PNG height includes top-left card header block');
      assert.ok(topLeftHasHeaderInk.nonWhitePixels > 200, 'top-left corner of batch PNG must render #2 + name + order number');

      // Clicking download button downloads all 10 individual PNG images (no ZIP)
      const downloadBtn = page.locator('[data-modal-action="download-batch-qr-images"]');
      await downloadBtn.waitFor();
      assert.match(await downloadBtn.innerText(), /下载全部二维码 \(10张\)/);
      await downloadBtn.click();
      await page.waitForFunction(() => !document.querySelector('[data-modal-action="download-batch-qr-images"]')?.disabled, null, { timeout: 5000 });
      const deadline = Date.now() + 3000;
      while (downloads.length < 10 && Date.now() < deadline) {
        await page.waitForTimeout(50);
      }
      assert.equal(downloads.length, 10, 'clicking batch download button must download all 10 individual images');
      const downloadedNames = downloads.map((dl) => dl.suggestedFilename());
      assert.deepEqual(
        downloadedNames,
        Array.from({ length: 10 }, (_, idx) => {
          const seq = String(idx + 1).padStart(2, '0');
          return `订阅二维码-#${seq}-测试-261028-CODE${seq}.png`;
        })
      );
      for (const dl of downloads) {
        const pngBuf = fs.readFileSync(await dl.path());
        assertPngBuffer(pngBuf, dl.suggestedFilename());
      }
      downloads.length = 0;

      // Quantity on underlying card automatically resets back to 1
      assert.equal(await page.locator('[data-plan-qty-input="11"]').inputValue(), '1');

      // Wait for poll #1 (card #1 transitions to issuing while #2-#10 keep QR) and poll #2 (card #1 connected, #2 waiting connection)
      await page.waitForFunction(() => {
        const cards = document.querySelectorAll('.dist-batch-qr-card');
        return cards.length === 10 && cards[0].innerText.includes('香港01') && cards[1].innerText.includes('等待用户开启代理进入网络');
      }, null, { timeout: 10000 });

      assert.equal(await page.locator('.dist-batch-qr-card .dist-subscription-qr-preview').count(), 8, 'unscanned QRs remain visible');
      assert.match(await page.locator('#dist-batch-qr-heading').innerText(), /待领取 8 \/ 已领取 2/);
      assert.match(await downloadBtn.innerText(), /下载全部二维码 \(10张\)/);

      // Buy-again inside batch modal preserves the batch count (10) and does NOT auto-download
      await page.locator('[data-modal-action="buy-again"]').click();
      await page.waitForFunction(() => document.querySelectorAll('.dist-batch-qr-card .dist-subscription-qr-preview').length === 10);
      await page.waitForTimeout(150);
      assert.equal(downloads.length, 0, 'buy-again must NOT auto-download');
      assert.equal(savedPayload.quantity, '10');

      // Closing batch modal immediately after clicking batch download aborts remaining scheduled downloads
      const firstAbortDownload = page.waitForEvent('download');
      await page.locator('[data-modal-action="download-batch-qr-images"]').click();
      await firstAbortDownload;
      await page.locator('#dist-modal-root .dist-modal-x').click();
      await page.waitForTimeout(350);
      assert.ok(downloads.length < 10, `closing modal mid-download must stop background loop (got ${downloads.length})`);

      assert.deepEqual(errors, []);
    } finally {
      await page.close();
    }
  });
}

async function installCrossTabRecoveryFixture(context, state) {
  await context.route('**/*', async (route) => {
    const req = route.request();
    const url = new URL(req.url());
    if (url.protocol === 'blob:' || url.protocol === 'data:') return route.continue();
    if (url.pathname === '/') {
      return route.fulfill({
        contentType: 'text/html',
        body: '<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><div id="app"></div>',
      });
    }
    if (url.pathname.endsWith('/user/info')) {
      return route.fulfill({ json: { data: { id: state.userId, email: `dealer${state.userId}@example.invalid`, distributor_name: '测试', is_distributor: true } } });
    }
    if (url.pathname.endsWith('/user/notice/fetch')) return route.fulfill({ json: { data: [], total: 0 } });
    if (url.pathname.endsWith('/user/plan/fetch')) {
      return route.fulfill({
        json: { data: [{ id: 11, name: '代理商线路', transfer_enable: 500, device_limit: 11, reset_traffic_method: 1, month_price: 3000, capacity_limit: null }] },
      });
    }
    if (url.pathname.endsWith('/user/order/fetch')) {
      return route.fulfill({ json: { data: [], total: 0, current_page: 1, per_page: 20, last_page: 1 } });
    }
    if (url.pathname.endsWith('/user/logout')) return route.fulfill({ json: { data: true } });
    if (url.pathname.endsWith('/user/order/save')) {
      const params = new URLSearchParams(req.postData() || '');
      state.saves.push(Object.fromEntries(params.entries()));
      if (state.failSaves > 0) {
        state.failSaves -= 1;
        return route.fulfill({ status: 503, contentType: 'application/json', body: JSON.stringify({ message: 'Gateway Timeout' }) });
      }
      const qty = Number(params.get('quantity') || 1);
      const tradeNos = Array.from({ length: qty }, (_, idx) => `2026103123000000${idx + 1}`);
      return route.fulfill({ json: { data: qty > 1 ? { trade_nos: tradeNos, quantity: qty } : tradeNos[0] } });
    }
    if (url.pathname.endsWith('/user/distributor/delivery/batch')) {
      const params = new URLSearchParams(req.postData() || '');
      const includeQr = params.get('include_qr') !== '0';
      const data = (params.get('trade_nos') || '').split(',').filter(Boolean).map((no, idx) => ({
        trade_no: no,
        subscription_code: `TAB0${idx + 1}`,
        subscription_name: `测试-261031-TAB0${idx + 1}`,
        plan_id: 11,
        plan_name: '代理商线路',
        period: 'month_price',
        delivery_status: 0,
        settlement_status: 0,
        config_issued_at: null,
        hwid_enabled: true,
        hwid_limit: 2,
        hwid_devices: [],
        ...(includeQr ? { qr_code: qrSvg } : {}),
      }));
      return route.fulfill({ json: { data } });
    }
    return route.abort();
  });
}

async function openDistributorTab(context, width, { setToken = false } = {}) {
  const page = await context.newPage();
  await page.setViewportSize({ width, height: 900 });
  page.setDefaultTimeout(5000);
  const errors = [];
  page.on('pageerror', (err) => errors.push(err.message));
  await page.goto('http://127.0.0.1/');
  await page.evaluate((shouldSetToken) => {
    if (shouldSetToken) localStorage.setItem('VUE_NAIVE_ACCESS_TOKEN', JSON.stringify({ value: 'fixture', expire: null }));
    location.hash = '#/plan';
  }, setToken);
  await page.addStyleTag({ content: fs.readFileSync('theme/Xboard/assets/distributor.css', 'utf8') });
  await page.addScriptTag({ content: fs.readFileSync('theme/Xboard/assets/distributor.js', 'utf8') });
  await page.locator('[data-buy="11"]').waitFor();
  return { page, errors };
}

for (const width of [390, 1440]) {
  test(`uncertain batch purchase stays locked across tabs, tab close and logout at ${width}px`, async () => {
    const context = await browser.newContext({ acceptDownloads: true });
    const state = { userId: 7, saves: [], failSaves: 1 };
    try {
      await installCrossTabRecoveryFixture(context, state);
      const tabA = await openDistributorTab(context, width, { setToken: true });
      const tabB = await openDistributorTab(context, width);
      assert.equal(await tabB.page.locator('[data-action="resume-purchase"]').count(), 0);

      await tabA.page.locator('[data-plan-qty-input="11"]').fill('2');
      await tabA.page.locator('[data-buy="11"]').click();
      await tabA.page.locator('[data-action="resume-purchase"]').waitFor();
      assert.equal(state.saves.length, 1);
      const originalKey = state.saves[0].idempotency_key;
      assert.ok(originalKey);

      // The already-open second tab is locked through the real storage event.
      await tabB.page.locator('[data-action="resume-purchase"]').waitFor();
      assert.equal(await tabB.page.locator('[data-buy="11"]').isDisabled(), true);
      assert.equal(await tabB.page.evaluate(() => sessionStorage.length), 0, 'recovery must not rely on per-tab sessionStorage');

      // Closing the purchasing tab and opening a fresh one keeps the lock.
      await tabA.page.close();
      const tabC = await openDistributorTab(context, width);
      await tabC.page.locator('[data-action="resume-purchase"]').waitFor();
      assert.equal(await tabC.page.locator('[data-buy="11"]').isDisabled(), true);
      assert.equal(await tabC.page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), true, `no horizontal overflow at ${width}px`);

      // Logging out and back in to the same account restores the same operation.
      await tabC.page.locator('[data-action="logout"]:visible').first().click();
      await tabC.page.waitForFunction(() => location.hash === '#/login' && !document.getElementById('distributor-app'));
      await tabC.page.evaluate(() => {
        localStorage.setItem('VUE_NAIVE_ACCESS_TOKEN', JSON.stringify({ value: 'fixture-relogin', expire: null }));
        location.hash = '#/plan';
      });
      await tabC.page.locator('[data-action="resume-purchase"]').waitFor();
      assert.equal(state.saves.length, 1, 'no write happens while the purchase is uncertain');

      const tabCDownloads = [];
      tabC.page.on('download', (dl) => tabCDownloads.push(dl));
      await tabC.page.locator('[data-action="resume-purchase"]').click();
      await tabC.page.locator('.dist-batch-qr-modal').waitFor();
      await tabC.page.waitForTimeout(150);
      assert.equal(tabCDownloads.length, 0, 'cross-tab purchase recovery must NOT auto-download');
      assert.equal(state.saves.length, 2);
      assert.equal(state.saves[1].idempotency_key, originalKey);
      assert.equal(state.saves[1].quantity, '2');
      assert.equal(await tabC.page.evaluate(() => localStorage.getItem('xboard_distributor_purchase_recovery:id:7')), null);

      // The resolved record unlocks the other open tab as well.
      await tabB.page.waitForFunction(() => !document.querySelector('[data-action="resume-purchase"]'));
      assert.equal(await tabB.page.locator('[data-buy="11"]').isDisabled(), false);
      assert.deepEqual([...tabB.errors, ...tabC.errors, ...tabA.errors], []);
    } finally {
      await context.close();
    }
  });
}
