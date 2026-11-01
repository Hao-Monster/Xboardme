// Local-only served-asset fixtures: no production requests or credentials.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { test, before, after } = require('node:test');
const { expect } = require('playwright/test');
const { launchBrowser } = require('./helpers/browser-runtime.cjs');

const root = path.resolve(__dirname, '../..');
const origin = 'http://subscription-reset.example.invalid';
const tradeNo = '202610300001';
const svg = (color) => 'data:image/svg+xml;base64,' + Buffer.from(`<svg xmlns="http://www.w3.org/2000/svg" width="320" height="320"><rect width="320" height="320" fill="${color}"/></svg>`).toString('base64');
let browser;
before(async () => { browser = await launchBrowser(); });
after(async () => { await browser?.close(); });

async function fixture(t, { width = 390, pageRoute = '/order', locale = 'zh-CN' } = {}) {
  const page = await browser.newPage({ viewport: { width, height: 900 } });
  page.setDefaultTimeout(6000);
  t.after(() => page.close());
  const state = { version: 'a'.repeat(64), commits: 0, requests: [], qrReads: 0, orderReads: 0, mode: 'success', pending: null, emptyDevices: false, deliveryStatus: 1, holdQr: false, pendingQr: [], claimedTradeNos: new Set(), delayChunkMs: 0 };
  const plan = { id: 1, name: 'Fixture Plan', month_price: 1000, transfer_enable: 100 };
  const payload = (no = tradeNo) => ({
    trade_no: no, subscription_name: '客户甲-RESET01', customer_name: '客户甲',
    credential_version: state.version, can_reset_subscription: true, delivery_status: state.claimedTradeNos.has(no) ? 1 : state.deliveryStatus,
    hwid_enabled: true, hwid_devices: state.commits || state.emptyDevices ? [] : ['old-device-001', 'old-device-002'],
    qr_code: svg(state.version === 'a'.repeat(64) ? '#ab1234' : '#12ab34'),
  });
  const order = () => ({
    id: 1, trade_no: tradeNo, subscription_trade_no: tradeNo, subscription_name: '客户甲-RESET01',
    customer_name: '客户甲', type: 1, is_subscription_origin: true, can_view_subscription_qr: true,
    period: 'month_price', total_amount: 1000, plan, bound_devices: payload().hwid_devices,
    hwid_enabled: true, subscription_entitlement: { transfer_enable: 100000, remaining_traffic: 65000, expired_at: 1900000000 },
  });
  const respond = (route, data, status = 200) => route.fulfill({ status, json: { data } });
  const commit = () => { state.commits += 1; state.version = 'b'.repeat(64); };
  await page.route('**/*', async (route) => {
    const url = new URL(route.request().url());
    if (url.origin !== origin) return route.abort();
    if (url.pathname === '/distributor.js' || url.pathname === '/distributor.css') {
      return route.fulfill({ contentType: url.pathname.endsWith('.css') ? 'text/css' : 'text/javascript', body: fs.readFileSync(path.join(root, 'theme/Xboard/assets', url.pathname.slice(1))) });
    }
    if (url.pathname === '/') return route.fulfill({ contentType: 'text/html', body: `<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/distributor.css"><div id="app"></div><script>localStorage.setItem('VUE_NAIVE_ACCESS_TOKEN',JSON.stringify({value:'fixture-account-1',expire:null}));localStorage.setItem('xboard_distributor_locale',${JSON.stringify(locale)});</script><script defer src="/distributor.js"></script>` });
    if (url.pathname.endsWith('/user/info')) return respond(route, { email: 'fixture@example.invalid', is_distributor: true });
    if (url.pathname.endsWith('/user/order/fetch')) { state.orderReads += 1; return route.fulfill({ json: { data: [order()], total: 1 } }); }
    if (url.pathname.endsWith('/user/plan/fetch')) return respond(route, [plan]);
    if (url.pathname.endsWith('/user/notice/fetch')) return respond(route, []);
    if (url.pathname.endsWith('/user/distributor/subscription-qr')) {
      state.qrReads += 1;
      if (state.holdQr) { state.pendingQr.push(route); return; }
      return respond(route, payload(url.searchParams.get('trade_no') || tradeNo));
    }
    if (url.pathname.endsWith('/user/distributor/subscription-reset')) {
      const body = Object.fromEntries(new URLSearchParams(route.request().postData()));
      const targetNo = body.trade_no || tradeNo;
      state.requests.push(body);
      if (state.mode === 'conflict') return route.fulfill({ status: 409, json: { message: '凭据已变化' } });
      if (state.mode === 'rejected') return route.fulfill({ status: 422, json: { message: '暂时无法处理' } });
      if (state.mode === 'pending') { state.pending = route; return; }
      if (!state.commits) commit();
      if (state.mode === 'drop') { state.mode = 'success'; return route.abort('failed'); }
      return respond(route, { ...payload(targetNo), reset_at: 1793300000, cleared_devices: 2, replayed: state.requests.length > 1 });
    }
    if (url.pathname.endsWith('/user/order/save')) {
      const quantity = Number(new URLSearchParams(route.request().postData()).get('quantity') || 1);
      const nos = Array.from({ length: quantity }, (_, idx) => (idx === 0 ? tradeNo : `${tradeNo}${idx + 1}`));
      return respond(route, quantity === 1 ? tradeNo : { trade_nos: nos, quantity });
    }
    if (url.pathname.endsWith('/user/distributor/delivery/batch')) {
      const params = new URLSearchParams(route.request().postData());
      const includeQr = params.get('include_qr') !== '0';
      const requestedNos = (params.get('trade_nos') || '').split(',').filter(Boolean);
      const nos = requestedNos.length ? requestedNos : [tradeNo, tradeNo + '2'];
      if (state.delayChunkMs > 0 && includeQr && nos[0] !== tradeNo) {
        await new Promise((resolve) => setTimeout(resolve, state.delayChunkMs));
      }
      return respond(route, nos.map(no => {
        const isClaimed = state.claimedTradeNos.has(no);
        const item = {
          ...payload(no),
          delivery_status: isClaimed ? 1 : 0,
          config_issued_at: isClaimed ? 1793300010 : null,
          connected_at: isClaimed ? 1793300020 : null,
          connected_node_name: isClaimed ? '新加坡01' : null,
        };
        if (!includeQr || isClaimed) delete item.qr_code;
        return item;
      }));
    }
    if (url.pathname.endsWith('/user/distributor/delivery')) {
      const isClaimed = state.claimedTradeNos.has(tradeNo);
      const item = {
        ...payload(),
        delivery_status: isClaimed ? 1 : 0,
        config_issued_at: isClaimed ? 1793300010 : null,
        connected_at: isClaimed ? 1793300020 : null,
        connected_node_name: isClaimed ? '新加坡01' : null,
      };
      if (isClaimed) delete item.qr_code;
      return respond(route, item);
    }
    return respond(route, {});
  });
  await page.goto(`${origin}/#${pageRoute}`);
  return { page, state, payload, respond, commit,
    async openQr() { await page.locator('[data-subscription-qr]').click(); await expect(page.locator('.dist-subscription-qr-preview')).toBeVisible(); },
    async confirmView(scope = page) { await scope.locator('[data-modal-action="reset-subscription"]').first().click(); await expect(page.locator('#dist-subscription-reset-title')).toBeVisible(); },
  };
}

async function assertSingleRowButtons(locatorList) {
  const boxes = await Promise.all(locatorList.map((loc) => loc.boundingBox()));
  boxes.forEach((box) => assert.ok(box && box.width > 0 && box.height > 0));
  for (let i = 1; i < boxes.length; i += 1) {
    assert.ok(Math.abs(boxes[i].y - boxes[0].y) <= 2, `buttons should share one horizontal row: y0=${boxes[0].y}, y${i}=${boxes[i].y}`);
    assert.ok(boxes[i].x >= boxes[i - 1].x + boxes[i - 1].width - 1, 'buttons should sit side-by-side left to right');
  }
}

for (const width of [390, 1440]) test(`reset confirms all devices, keeps 3 buttons in one row, and replaces the downloadable PNG at ${width}px`, async t => {
  const f = await fixture(t, { width });
  await f.openQr();
  await expect(f.page.locator('.dist-reset-gold-tag')).toHaveCount(0);
  await assertSingleRowButtons([
    f.page.locator('[data-modal-action="copy-subscription-qr"]'),
    f.page.locator('[data-modal-action="download-subscription-qr"]'),
    f.page.locator('[data-modal-action="reset-subscription"]'),
  ]);
  const oldImage = await f.page.locator('.dist-subscription-qr-preview').getAttribute('src');
  await f.confirmView();
  await expect(f.page.locator('#dist-reset-modal-root .dist-reset-hint')).toContainText('套餐流量、已用流量和到期时间保持不变');
  assert.equal(f.state.requests.length, 0);
  await f.page.locator('[data-modal-action="confirm-subscription-reset"]').click();
  await expect(f.page.locator('#dist-modal-root [role="status"]')).toContainText('订阅已重置');
  assert.equal(f.state.requests.length, 1);
  assert.equal(f.state.requests[0].credential_version, 'a'.repeat(64));
  assert.equal(f.state.requests[0].confirm, 'true');
  assert.notEqual(await f.page.locator('.dist-subscription-qr-preview').getAttribute('src'), oldImage);
  await expect(f.page.locator('.dist-bound-device-list')).toHaveCount(0);
  assert.ok(f.state.orderReads >= 2);
  assert.equal(await f.page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
  const downloadPromise = f.page.waitForEvent('download');
  await f.page.locator('[data-modal-action="download-subscription-qr"]').click();
  assert.match((await downloadPromise).suggestedFilename(), /客户甲-RESET01/);
});

test('lost response hides old credentials and close/reopen recovers with the identical key', async t => {
  const f = await fixture(t);
  await f.openQr(); await f.confirmView(); f.state.mode = 'drop';
  await f.page.locator('[data-modal-action="confirm-subscription-reset"]').click();
  await expect(f.page.locator('[data-modal-action="confirm-subscription-reset"]')).toHaveText('恢复本次重置');
  assert.equal(f.state.requests.length, 1, 'no automatic POST retry');
  await expect(f.page.locator('.dist-subscription-qr-preview')).toHaveCount(0);
  await expect(f.page.locator('[data-modal-action="download-subscription-qr"]')).toHaveCount(0);
  await f.page.locator('#dist-reset-modal-root .dist-modal-x').click();
  await expect(f.page.locator('#dist-subscription-reset-title')).toHaveCount(0);
  await expect(f.page.locator('.dist-subscription-qr-modal')).toBeVisible();
  await expect(f.page.locator('.dist-subscription-qr-preview')).toHaveCount(0);
  await f.page.locator('#dist-modal-root .dist-modal-x').click();
  await f.page.locator('[data-subscription-qr]').click();
  await expect(f.page.locator('[data-modal-action="confirm-subscription-reset"]')).toHaveText('恢复本次重置');
  await f.page.locator('[data-modal-action="confirm-subscription-reset"]').click();
  await expect(f.page.locator('#dist-modal-root [role="status"]')).toContainText('订阅已重置');
  assert.deepEqual(f.state.requests[0], f.state.requests[1]);
  assert.equal(f.state.commits, 1);
});

test('stale version refresh requires an explicit new confirmation', async t => {
  const f = await fixture(t); await f.openQr(); await f.confirmView(); f.state.mode = 'conflict';
  await f.page.locator('[data-modal-action="confirm-subscription-reset"]').click();
  await expect(f.page.locator('[data-modal-action="refresh-subscription-reset"]')).toBeVisible();
  f.state.version = 'c'.repeat(64);
  await f.page.locator('[data-modal-action="refresh-subscription-reset"]').click();
  await expect(f.page.locator('.dist-subscription-qr-preview')).toBeVisible();
  assert.equal(f.state.requests.length, 1);
  await f.confirmView(); f.state.mode = 'success';
  await f.page.locator('[data-modal-action="confirm-subscription-reset"]').click();
  await expect(f.page.locator('[role="status"]')).toContainText('订阅已重置');
  assert.equal(f.state.requests[1].credential_version, 'c'.repeat(64));
  assert.notEqual(f.state.requests[0].idempotency_key, f.state.requests[1].idempotency_key);
});

test('a stalled POST times out without retrying and retains its key for recovery', async t => {
  const f = await fixture(t); await f.openQr(); await f.confirmView();
  await f.page.clock.install(); f.state.mode = 'pending';
  await f.page.locator('[data-modal-action="confirm-subscription-reset"]').click();
  await expect.poll(() => f.state.requests.length).toBe(1);
  await f.page.clock.runFor(20001);
  await expect(f.page.locator('[data-modal-action="confirm-subscription-reset"]')).toHaveText('恢复本次重置');
  assert.equal(f.state.requests.length, 1);
  f.commit();
  await f.respond(f.state.pending, { ...f.payload(), replayed: false });
  await expect(f.page.locator('.dist-subscription-qr-preview')).toHaveCount(0);
  f.state.mode = 'success';
  await f.page.locator('[data-modal-action="confirm-subscription-reset"]').click();
  await expect(f.page.locator('[role="status"]')).toContainText('订阅已重置');
  assert.deepEqual(f.state.requests[0], f.state.requests[1]);
});

test('a later validation rejection does not discard an earlier uncertain reset key', async t => {
  const f = await fixture(t); await f.openQr(); await f.confirmView(); f.state.mode = 'drop';
  await f.page.locator('[data-modal-action="confirm-subscription-reset"]').click();
  await expect(f.page.locator('[data-modal-action="confirm-subscription-reset"]')).toHaveText('恢复本次重置');
  f.state.mode = 'rejected';
  await f.page.locator('[data-modal-action="confirm-subscription-reset"]').click();
  await expect(f.page.locator('[data-modal-action="confirm-subscription-reset"]')).toHaveText('恢复本次重置');
  f.state.mode = 'success';
  await f.page.locator('[data-modal-action="confirm-subscription-reset"]').click();
  await expect(f.page.locator('[role="status"]')).toContainText('订阅已重置');
  assert.equal(f.state.requests.length, 3);
  assert.deepEqual(f.state.requests[0], f.state.requests[2]);
});

test('double submit is fenced and account switch ignores the in-flight result', async t => {
  const f = await fixture(t); await f.openQr(); await f.confirmView(); f.state.mode = 'pending';
  await f.page.locator('[data-modal-action="confirm-subscription-reset"]').evaluate(button => { button.click(); button.click(); });
  await expect.poll(() => f.state.requests.length).toBe(1);
  await expect(f.page.locator('[data-modal-action="confirm-subscription-reset"]')).toBeDisabled();
  await f.page.evaluate(() => { localStorage.removeItem('VUE_NAIVE_ACCESS_TOKEN'); window.dispatchEvent(new StorageEvent('storage', { key: 'VUE_NAIVE_ACCESS_TOKEN' })); });
  f.commit(); await f.respond(f.state.pending, { ...f.payload(), replayed: false });
  await expect(f.page.locator('#distributor-app')).toHaveCount(0);
  await expect(f.page.locator('.dist-subscription-qr-preview')).toHaveCount(0);
});

for (const width of [390, 1440]) test(`single checkout delivery renders 1-row buttons + gold tag and keeps modal open on reset cancel at ${width}px`, async t => {
  const f = await fixture(t, { width, pageRoute: '/plan' });
  await f.page.locator('[data-buy="1"]').click();
  await expect(f.page.locator('.dist-qr-modal')).toBeVisible();
  await expect(f.page.locator('.dist-qr-modal .dist-reset-gold-tag')).toHaveText('我的订单页面也可以重置');
  await assertSingleRowButtons([
    f.page.locator('.dist-qr-modal [data-modal-action="copy-subscription-qr"]'),
    f.page.locator('.dist-qr-modal [data-modal-action="download-subscription-qr"]'),
    f.page.locator('.dist-qr-modal [data-modal-action="reset-subscription"]'),
  ]);
  assert.equal(await f.page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);

  await f.confirmView(f.page.locator('.dist-qr-modal'));
  await f.page.locator('#dist-reset-modal-root .dist-modal-x').click();
  await expect(f.page.locator('#dist-subscription-reset-title')).toHaveCount(0);
  await expect(f.page.locator('.dist-qr-modal')).toBeVisible();

  await f.confirmView(f.page.locator('.dist-qr-modal'));
  await f.page.locator('#dist-reset-modal-root .dist-modal-actions [data-modal-action="close-reset-confirm"]').click();
  await expect(f.page.locator('#dist-subscription-reset-title')).toHaveCount(0);
  await expect(f.page.locator('.dist-qr-modal')).toBeVisible();

  await f.confirmView(f.page.locator('.dist-qr-modal'));
  await f.page.keyboard.press('Escape');
  await expect(f.page.locator('#dist-subscription-reset-title')).toHaveCount(0);
  await expect(f.page.locator('.dist-qr-modal')).toBeVisible();

  await f.confirmView(f.page.locator('.dist-qr-modal'));
  await f.page.locator('[data-modal-action="confirm-subscription-reset"]').click();
  await expect(f.page.locator('#dist-subscription-reset-title')).toHaveCount(0);
  await expect(f.page.locator('.dist-qr-modal [role="status"]')).toContainText('订阅已重置');
  await expect(f.page.locator('.dist-qr-modal .dist-subscription-qr-preview')).toBeVisible();
});

for (const width of [390, 1440]) test(`batch checkout cards render 1-row buttons + gold tag, keep batch modal open on cancel, and reset in-place at ${width}px`, async t => {
  const f = await fixture(t, { width, pageRoute: '/plan' });
  await f.page.locator('[data-plan-qty-input="1"]').fill('2');
  await f.page.locator('[data-buy="1"]').click();
  await expect(f.page.locator('.dist-batch-qr-card')).toHaveCount(2);
  await expect(f.page.locator('.dist-batch-qr-card .dist-subscription-qr-preview')).toHaveCount(2);
  const firstCard = f.page.locator('.dist-batch-qr-card').first();
  await expect(firstCard.locator('.dist-reset-gold-tag')).toHaveText('我的订单页面也可以重置');
  await assertSingleRowButtons([
    firstCard.locator('[data-modal-action="copy-batch-item-qr"]'),
    firstCard.locator('[data-modal-action="download-batch-item-qr"]'),
    firstCard.locator('[data-modal-action="reset-subscription"]'),
  ]);
  assert.equal(await f.page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);

  const oldImage = await firstCard.locator('.dist-subscription-qr-preview').getAttribute('src');
  await f.confirmView(firstCard);
  await f.page.locator('#dist-reset-modal-root .dist-modal-x').click();
  await expect(f.page.locator('#dist-subscription-reset-title')).toHaveCount(0);
  await expect(f.page.locator('.dist-batch-qr-modal')).toBeVisible();
  await expect(f.page.locator('.dist-batch-qr-card')).toHaveCount(2);

  await f.confirmView(firstCard);
  await f.page.keyboard.press('Escape');
  await expect(f.page.locator('#dist-subscription-reset-title')).toHaveCount(0);
  await expect(f.page.locator('.dist-batch-qr-modal')).toBeVisible();

  await f.confirmView(firstCard);
  await f.page.locator('[data-modal-action="confirm-subscription-reset"]').click();
  await expect(f.page.locator('#dist-subscription-reset-title')).toHaveCount(0);
  await expect(f.page.locator('.dist-batch-qr-modal')).toBeVisible();
  await expect(firstCard.locator('[role="status"]')).toContainText('订阅已重置');
  assert.notEqual(await firstCard.locator('.dist-subscription-qr-preview').getAttribute('src'), oldImage);
  await expect(f.page.locator('[data-modal-action="download-batch-qr-images"]')).toBeEnabled();
});

test('closing an unsubmitted reset confirmation via X, cancel button, Escape, or backdrop keeps My Orders QR modal open', async t => {
  const f = await fixture(t); await f.openQr();
  await f.confirmView();
  await f.page.locator('#dist-reset-modal-root .dist-modal-x').click();
  await expect(f.page.locator('#dist-subscription-reset-title')).toHaveCount(0);
  await expect(f.page.locator('.dist-subscription-qr-modal')).toBeVisible();
  await expect(f.page.locator('.dist-subscription-qr-preview')).toBeVisible();

  await f.confirmView();
  await f.page.locator('#dist-reset-modal-root .dist-modal-actions [data-modal-action="close-reset-confirm"]').click();
  await expect(f.page.locator('#dist-subscription-reset-title')).toHaveCount(0);
  await expect(f.page.locator('.dist-subscription-qr-modal')).toBeVisible();

  await f.confirmView();
  await f.page.keyboard.press('Escape');
  await expect(f.page.locator('#dist-subscription-reset-title')).toHaveCount(0);
  await expect(f.page.locator('.dist-subscription-qr-modal')).toBeVisible();

  await f.confirmView();
  await f.page.locator('.dist-reset-modal-backdrop').click({ position: { x: 5, y: 5 } });
  await expect(f.page.locator('#dist-subscription-reset-title')).toHaveCount(0);
  await expect(f.page.locator('.dist-subscription-qr-modal')).toBeVisible();
  assert.equal(f.state.requests.length, 0);
});

test('pending single delivery invalidates its old PNG on version-only rotation before composing the new one', async t => {
  const f = await fixture(t, { pageRoute: '/plan' });
  f.state.emptyDevices = true; f.state.deliveryStatus = 0;
  await f.page.locator('[data-buy="1"]').click();
  await expect(f.page.locator('.dist-subscription-qr-preview')).toBeVisible();
  const oldImage = await f.page.locator('.dist-subscription-qr-preview').getAttribute('src');
  await f.page.clock.install();
  await f.page.evaluate(() => {
    const original = HTMLCanvasElement.prototype.toBlob;
    HTMLCanvasElement.prototype.toBlob = function (callback, ...args) {
      original.call(this, blob => { window.releaseResetPng = () => callback(blob); }, ...args);
    };
  });
  f.commit();
  await f.page.clock.runFor(3001);
  await expect.poll(() => f.page.evaluate(() => typeof window.releaseResetPng)).toBe('function');
  await expect(f.page.locator('.dist-subscription-qr-preview')).toHaveCount(0);
  await expect(f.page.locator('[data-modal-action="download-subscription-qr"]')).toHaveCount(0);
  await f.page.evaluate(() => window.releaseResetPng());
  await expect(f.page.locator('.dist-subscription-qr-preview')).toBeVisible();
  assert.notEqual(await f.page.locator('.dist-subscription-qr-preview').getAttribute('src'), oldImage);
});

test('batch version-only rotation hides stale cards and disables batch image download until fresh images rebuild', async t => {
  const f = await fixture(t, { pageRoute: '/plan' });
  f.state.emptyDevices = true; f.state.deliveryStatus = 0;
  await f.page.locator('[data-plan-qty-input="1"]').fill('2');
  await f.page.locator('[data-buy="1"]').click();
  await expect(f.page.locator('.dist-subscription-qr-preview')).toHaveCount(2);
  const oldImage = await f.page.locator('.dist-subscription-qr-preview').first().getAttribute('src');
  await expect(f.page.locator('[data-modal-action="download-batch-qr-images"]')).toBeEnabled();
  await f.page.clock.install(); f.state.holdQr = true; f.commit();
  await f.page.clock.runFor(3001);
  await expect.poll(() => f.state.pendingQr.length).toBe(2);
  await expect(f.page.locator('.dist-subscription-qr-preview')).toHaveCount(0);
  await expect(f.page.locator('[data-modal-action="download-batch-qr-images"]')).toBeDisabled();
  f.state.holdQr = false;
  await Promise.all(f.state.pendingQr.map(route => f.respond(route, f.payload(new URL(route.request().url()).searchParams.get('trade_no')))));
  await expect(f.page.locator('.dist-subscription-qr-preview')).toHaveCount(2);
  await expect(f.page.locator('[data-modal-action="download-batch-qr-images"]')).toBeEnabled();
  const image = await f.page.locator('.dist-subscription-qr-preview').first().getAttribute('src');
  assert.notEqual(image, oldImage);
  const imageBytes = image.startsWith('blob:')
    ? Buffer.from(await f.page.evaluate(async (url) => Array.from(new Uint8Array(await (await fetch(url)).arrayBuffer())), image))
    : Buffer.from(image.split(',')[1], 'base64');
  const downloaded = [];
  f.page.on('download', (dl) => downloaded.push(dl));
  await f.page.locator('[data-modal-action="download-batch-qr-images"]').click();
  await f.page.clock.runFor(300);
  await expect.poll(() => downloaded.length).toBe(2);
  const firstPng = fs.readFileSync(await downloaded[0].path());
  assert.deepEqual(Array.from(firstPng.subarray(0, 8)), [0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]);
  assert.deepEqual(firstPng, imageBytes);
});

test('late cross-tab QR refresh cannot reopen a closed batch modal', async t => {
  const f = await fixture(t, { pageRoute: '/plan' });
  f.state.emptyDevices = true; f.state.deliveryStatus = 0;
  await f.page.locator('[data-plan-qty-input="1"]').fill('2');
  await f.page.locator('[data-buy="1"]').click();
  await expect(f.page.locator('.dist-subscription-qr-preview')).toHaveCount(2);
  await f.page.clock.install(); f.state.holdQr = true; f.commit();
  await f.page.clock.runFor(3001);
  await expect.poll(() => f.state.pendingQr.length).toBe(2);
  await f.page.locator('.dist-modal-x').click();
  await Promise.all(f.state.pendingQr.map(route => f.respond(route, f.payload())));
  await expect(f.page.locator('.dist-subscription-qr-preview')).toHaveCount(0);
  await expect(f.page.locator('.dist-batch-qr-modal')).toHaveCount(0);
});

test('progressive >25 batch completes all chunks after resetting card #1, and newly claimed reset card transitions to claimed status', async t => {
  const f = await fixture(t, { width: 1440, pageRoute: '/plan' });
  f.state.emptyDevices = true;
  f.state.deliveryStatus = 0;
  f.state.delayChunkMs = 250;
  await f.page.locator('[data-plan-qty-input="1"]').fill('28');
  await f.page.locator('[data-buy="1"]').click();
  await expect(f.page.locator('.dist-batch-qr-modal')).toBeVisible();
  const downloadBtn = f.page.locator('[data-modal-action="download-batch-qr-images"]');
  await expect(downloadBtn).toHaveText('下载全部二维码 (28张)');
  await expect(downloadBtn).toBeDisabled();

  // Reset card #1 while chunk #2 (cards 11..28) is still fetching/rendering
  const firstCard = f.page.locator('.dist-batch-qr-card').first();
  await f.confirmView(firstCard);
  await f.page.locator('[data-modal-action="confirm-subscription-reset"]').click();
  await expect(firstCard.locator('[role="status"]')).toContainText('订阅已重置');

  // All 28 cards must finish rendering and batch download button must enable with stable 28 count
  await expect(f.page.locator('.dist-subscription-qr-preview')).toHaveCount(28);
  await expect(downloadBtn).toBeEnabled();
  await expect(downloadBtn).toHaveText('下载全部二维码 (28张)');

  // When customer scans the newly reset pending card #1 (0 -> 1), card #1 transitions to claimed status
  f.state.claimedTradeNos.add(tradeNo);
  await expect(firstCard).toContainText('新加坡01', { timeout: 6000 });
  await expect(firstCard.locator('.dist-subscription-qr-preview')).toHaveCount(0);
});

test('en-US mobile 390px keeps 3 QR buttons in one row without text overflow and restores focus after conflict close', async t => {
  const f = await fixture(t, { width: 390, pageRoute: '/plan', locale: 'en-US' });
  f.state.emptyDevices = true;
  f.state.deliveryStatus = 0;
  await f.page.locator('[data-buy="1"]').click();
  await expect(f.page.locator('.dist-qr-modal .dist-subscription-qr-preview')).toBeVisible();
  const buttons = [
    f.page.locator('.dist-qr-modal [data-modal-action="copy-subscription-qr"]'),
    f.page.locator('.dist-qr-modal [data-modal-action="download-subscription-qr"]'),
    f.page.locator('.dist-qr-modal [data-modal-action="reset-subscription"]'),
  ];
  await assertSingleRowButtons(buttons);
  for (const btn of buttons) {
    const fits = await btn.evaluate((el) => el.scrollWidth <= el.clientWidth + 1);
    assert.equal(fits, true, `en-US mobile button "${await btn.innerText()}" must not overflow`);
  }
  assert.equal(await f.page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), true);

  // Trigger a 409 conflict (which re-renders #dist-modal-root), close reset confirm, and verify focus restores to reset trigger
  f.state.mode = 'conflict';
  await f.page.locator('.dist-qr-modal [data-modal-action="reset-subscription"]').click();
  await expect(f.page.locator('#dist-subscription-reset-title')).toBeVisible();
  await f.page.locator('[data-modal-action="confirm-subscription-reset"]').click();
  await expect(f.page.locator('#dist-reset-modal-root [data-modal-action="refresh-subscription-reset"]')).toBeVisible();
  await f.page.locator('#dist-reset-modal-root .dist-modal-x').click();
  await expect.poll(() => f.page.evaluate(() => document.activeElement?.getAttribute('data-modal-action'))).toBe('reset-subscription');
});
