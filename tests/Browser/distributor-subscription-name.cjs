// Run with Playwright on NODE_PATH. All browser requests use local fixture responses.
const { chromium } = require('playwright');
const fs = require('node:fs');
const assert = require('node:assert/strict');
const os = require('node:os');
const path = require('node:path');

const name = 'GZXBL小北Mustafa-261006-A7K9Q2';
const longestName = '一二三四五六七八九十甲乙丙丁戊己-261006-A7K9Q2';
const longCustomerName = '客户名称较长需要验证自动换行'.repeat(5);
const longDeviceLabel = '较长设备标签及绑定编号 '.repeat(5) + 'ABCDEFG123456789';
const qrFixture = 'data:image/svg+xml;base64,' + Buffer.from('<svg xmlns="http://www.w3.org/2000/svg" width="540" height="540"><rect x="1" y="1" width="538" height="538" fill="#edf2f7" stroke="#64748b" stroke-width="2"/><text x="270" y="275" text-anchor="middle" font-family="sans-serif" font-size="28" fill="#334155">QR fixture area</text></svg>').toString('base64');
const tradeNo = '20261006160123456789012345';
const order = {
  id: 1, type: 1, trade_no: tradeNo, subscription_trade_no: tradeNo,
  subscription_name: name, subscription_code: 'A7K9Q2',
  is_subscription_origin: true, can_view_subscription_qr: true, can_renew: true,
  created_at: 1791244800, period: 'month_price', total_amount: 1000,
  customer_name: '客户甲', settlement_status: 0,
  plan: { id: 1, name: '测试套餐', month_price: 1000 },
  subscription_entitlement: { transfer_enable: 107374182400, remaining_traffic: 107374182400 },
};
const legacyTradeNo = '20260925101012345678901234';
const legacyOrder = { ...order, id: 2, trade_no: legacyTradeNo, subscription_trade_no: legacyTradeNo, subscription_name: null, subscription_code: null };

(async () => {
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  try {
    for (const width of [390, 1440]) for (const maximum of [false, true]) {
      const currentName = maximum ? longestName : name;
      const currentOrder = { ...order, subscription_name: currentName, customer_name: maximum ? longCustomerName : order.customer_name };
      const page = await browser.newPage({ viewport: { width, height: 900 } });
      const errors = [];
      page.on('pageerror', (error) => errors.push(error.message));
      await page.route('**/*', async (route) => {
        const url = new URL(route.request().url());
        if (url.pathname === '/') return route.fulfill({ contentType: 'text/html', body: '<meta name="viewport" content="width=device-width,initial-scale=1"><div id="app"></div>' });
        if (url.pathname.endsWith('/user/info')) return route.fulfill({ json: { data: { email: 'fixture@example.invalid', distributor_name: '小北', is_distributor: true } } });
        if (url.pathname.endsWith('/user/order/fetch')) return route.fulfill({ json: { total: 2, data: [currentOrder, legacyOrder] } });
        if (url.pathname.endsWith('/user/distributor/subscription-qr')) return route.fulfill({ json: { data: {
          ...(url.searchParams.get('trade_no') === legacyTradeNo ? legacyOrder : currentOrder), hwid_enabled: maximum, hwid_devices: maximum ? [longDeviceLabel, longDeviceLabel + 'SECOND'] : [],
          qr_code: qrFixture,
        } } });
        return route.abort();
      });
      await page.goto('http://127.0.0.1/');
      await page.evaluate(() => {
        localStorage.setItem('VUE_NAIVE_ACCESS_TOKEN', JSON.stringify({ value: 'fixture', expire: null }));
        location.hash = '#/order';
        window.qrTexts = [];
        window.qrImages = [];
        const original = CanvasRenderingContext2D.prototype.fillText;
        CanvasRenderingContext2D.prototype.fillText = function (text, x, y, ...args) {
          const metrics = this.measureText(text);
          window.qrTexts.push({
            text, x, y, textAlign: this.textAlign, textBaseline: this.textBaseline,
            width: metrics.width, left: x - metrics.actualBoundingBoxLeft,
            right: x + metrics.actualBoundingBoxRight, top: y - metrics.actualBoundingBoxAscent,
            bottom: y + metrics.actualBoundingBoxDescent,
            canvasWidth: this.canvas.width, canvasHeight: this.canvas.height,
          });
          return original.call(this, text, x, y, ...args);
        };
        const originalDrawImage = CanvasRenderingContext2D.prototype.drawImage;
        CanvasRenderingContext2D.prototype.drawImage = function (image, x, y, width, height) {
          window.qrImages.push({ x, y, width, height, canvasWidth: this.canvas.width, canvasHeight: this.canvas.height });
          return originalDrawImage.call(this, image, x, y, width, height);
        };
      });
      await page.addStyleTag({ content: fs.readFileSync('theme/Xboard/assets/distributor.css', 'utf8') });
      await page.addScriptTag({ content: fs.readFileSync('theme/Xboard/assets/distributor.js', 'utf8') });
      await page.locator('.dist-order-identity strong').first().waitFor();
      assert.equal(await page.locator('.dist-order-identity strong').first().innerText(), currentName);
      assert.equal(await page.locator('.dist-order-identity small').innerText(), `订单号：${tradeNo}`);
      assert.equal(await page.locator('.dist-order-identity strong').nth(1).innerText(), legacyTradeNo);
      assert.equal(await page.locator('.dist-order-identity').nth(1).locator('small').count(), 0);
      assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
      assert.match(await page.locator('#dist-order-search').getAttribute('placeholder'), /短订阅号/);
      await page.locator('[data-subscription-qr]').first().click();
      await page.locator('.dist-subscription-qr-preview').waitFor();
      const image = await page.locator('.dist-subscription-qr-preview').evaluate((el) => ({ complete: el.complete, width: el.naturalWidth }));
      assert.equal(image.complete, true);
      assert.equal(image.width, 760);
      const qrTexts = await page.evaluate(() => window.qrTexts);
      const qrImages = await page.evaluate(() => window.qrImages);
      assert.ok(qrTexts.some(({ text }) => text === currentName));
      assert.equal(qrTexts.some(({ text }) => /订单号|20261006160123456789012345/.test(text)), false);
      assert.equal(qrImages.length, 1);
      const qr = qrImages[0];
      assert.ok(qr.x >= 0 && qr.y >= 0);
      assert.ok(qr.x + qr.width <= qr.canvasWidth && qr.y + qr.height <= qr.canvasHeight);
      assert.equal(qr.width, 540);
      assert.equal(qr.height, 540);
      for (const text of qrTexts) {
        assert.equal(text.textAlign, 'center');
        assert.equal(text.textBaseline, 'top');
        assert.equal(text.x, text.canvasWidth / 2);
        assert.ok(text.width <= text.canvasWidth - 104, `wrapped text width: ${text.text}`);
        assert.ok(text.left >= 0 && text.right <= text.canvasWidth, `text clipped horizontally: ${text.text}`);
        assert.ok(text.top >= 0 && text.bottom < qr.y, `text intersects QR: ${text.text}`);
      }
      if (maximum) {
        assert.equal(currentName.length, 30);
        assert.ok(qrTexts.length > 6, 'long customer and device labels must wrap across lines');
        assert.ok(qrTexts.map(({ text }) => text).join('').includes(longCustomerName));
        assert.ok(qrTexts.map(({ text }) => text).join('').includes(longDeviceLabel));
        if (width === 390) {
          const png = await page.locator('.dist-subscription-qr-preview').getAttribute('src');
          const output = path.join(os.tmpdir(), 'xboard-subscription-longest-qr.png');
          fs.writeFileSync(output, Buffer.from(png.split(',')[1], 'base64'));
          console.log(`QR layout inspection image: ${output}`);
        }
      }
      await page.locator('[data-modal-action="cancel"]').first().click();
      await page.locator('[data-renew]').first().click();
      const dialog = page.locator('.dist-renewal-modal');
      await dialog.waitFor();
      assert.ok((await dialog.innerText()).includes(currentName));
      assert.equal(await dialog.evaluate((el) => el.scrollWidth <= el.clientWidth), true);
      await page.locator('[data-modal-action="cancel"]').first().click();
      await page.evaluate(() => { window.qrTexts = []; window.qrImages = []; });
      await page.locator(`[data-subscription-qr="${legacyTradeNo}"]`).click();
      await page.locator('.dist-subscription-qr-preview').waitFor();
      assert.ok((await page.evaluate(() => window.qrTexts)).some(({ text }) => text === `订单号 ${legacyTradeNo}`));
      await page.locator('[data-modal-action="cancel"]').first().click();
      await page.locator(`[data-renew="${legacyTradeNo}"]`).click();
      await dialog.waitFor();
      assert.equal(await dialog.locator('dt').filter({ hasText: '订阅名称' }).count(), 0);
      assert.deepEqual(errors, []);
      console.log(`PASS subscription naming browser ${width}px ${maximum ? 'maximum' : 'normal'}: mixed legacy/new names, old QR caption, measured new QR bounds, renewal, overflow, console`);
      await page.close();
    }

    const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
    const errors = [];
    let creates = 0;
    page.on('pageerror', (error) => errors.push(error.message));
    await page.route('**/*', async (route) => {
      const url = new URL(route.request().url());
      if (url.pathname === '/') return route.fulfill({ contentType: 'text/html', body: '<meta charset="utf-8"><main><h1>订单管理</h1><div><table></table></div></main>' });
      if (url.pathname.endsWith('/user/distributor/options')) return route.fulfill({ json: { data: [] } });
      if (url.pathname.endsWith('/order/fetch')) return route.fulfill({ json: { total: 2, data: [order, legacyOrder] } });
      if (url.pathname.endsWith('/user/generate')) { creates += 1; return route.fulfill({ json: { data: true } }); }
      return route.abort();
    });
    await page.goto('http://127.0.0.1/');
    await page.evaluate(() => {
      localStorage.setItem('XBOARD_ACCESS_TOKEN', JSON.stringify({ value: 'fixture', expire: null }));
      window.settings = { secure_path: 'fixture-admin' };
    });
    await page.addStyleTag({ content: fs.readFileSync('public/assets/admin-distributor.css', 'utf8') });
    await page.addScriptTag({ content: fs.readFileSync('public/assets/admin-distributor.js', 'utf8') });
    await page.locator('#xboard-native-distributor-orders tbody strong').first().waitFor();
    assert.equal(await page.locator('#xboard-native-distributor-orders tbody strong').first().innerText(), name);
    assert.equal(await page.locator('#xboard-native-distributor-orders tbody strong').nth(1).innerText(), legacyTradeNo);
    assert.equal(await page.locator('#xboard-native-distributor-orders tbody').getByText(`订单号：${legacyTradeNo}`, { exact: true }).count(), 0);
    await page.locator('#admin-dist-entry').click();
    await page.locator('[data-tab="users"]').click();
    assert.equal(await page.locator('#admin-dist-create-name').getAttribute('maxlength'), '16');
    await page.locator('#admin-dist-create-email').fill('fixture@example.invalid');
    await page.locator('#admin-dist-create-name').evaluate((el) => { el.value = 'A'.repeat(17); });
    await page.locator('[data-admin-dist="create-user"]').click();
    await page.locator('.admin-dist-toast.error').waitFor();
    assert.match(await page.locator('.admin-dist-toast.error').innerText(), /16/);
    assert.equal(creates, 0);
    await page.locator('#admin-dist-create-name').fill('a@b.co');
    await page.locator('[data-admin-dist="create-user"]').click();
    await page.getByText('分销商名称请填写商户名称，不要使用邮箱', { exact: true }).waitFor();
    assert.equal(creates, 0);
    assert.deepEqual(errors, []);
    console.log('PASS admin naming browser: named order, 16-character input, rejected overlong and email requests, console');
    await page.close();
  } finally {
    await browser.close();
  }
})().catch((error) => { console.error(error); process.exitCode = 1; });
