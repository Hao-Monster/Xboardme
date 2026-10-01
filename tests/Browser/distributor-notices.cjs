// Run with Playwright available on NODE_PATH; uses only local assets and fixture responses.
const { chromium } = require('playwright');
const fs = require('node:fs');
const assert = require('node:assert/strict');
(async () => {
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  try {
    for (const width of [390, 1440]) {
      const page = await browser.newPage({ viewport: { width, height: 900 } });
      let fail = false;
      let count = 6;
      const errors = [];
      page.on('pageerror', (e) => errors.push(e.message));
      await page.route('**/*', async (route) => {
        const url = new URL(route.request().url());
        if (url.pathname === '/') return route.fulfill({ contentType: 'text/html', body: '<div id="app"></div>' });
        if (url.pathname.endsWith('/user/info')) return route.fulfill({ json: { data: { is_distributor: true, email: 'fixture' } } });
        if (url.pathname.endsWith('/user/plan/fetch')) return route.fulfill({ json: { data: [{ id: 1, name: 'Test', month_price: 100, transfer_enable: 100 }] } });
        if (url.pathname.endsWith('/user/notice/fetch')) {
          if (fail) return route.fulfill({ status: 503, json: {} });
          const current = Number(url.searchParams.get('current'));
          const all = Array.from({ length: count }, (_, i) => ({ title: `公告${i + 1} ${'长标题'.repeat(25)}`, content: '<p>正文内容</p><script>window.pwned=true</script><img src="x" onerror="window.pwned=true"><a href="javascript:alert(1)">bad</a><a href="https://example.com">safe</a>' }));
          return route.fulfill({ json: { data: all.slice((current - 1) * 5, current * 5), total: count } });
        }
        return route.abort();
      });
      await page.goto('http://127.0.0.1/');
      await page.evaluate(() => { localStorage.setItem('VUE_NAIVE_ACCESS_TOKEN', JSON.stringify({ value: 'fixture', expire: null })); location.hash = '#/plan'; });
      await page.addStyleTag({ content: fs.readFileSync('theme/Xboard/assets/distributor.css', 'utf8') });
      await page.clock.install();
      await page.addScriptTag({ content: fs.readFileSync('theme/Xboard/assets/distributor.js', 'utf8') });
      const title = page.locator('.dist-notice-title');
      await title.waitFor();
      assert.match(await page.locator('[data-action="next-notice"]').innerText(), /1\/6/);
      await page.mouse.move(0, 0);
      await page.clock.fastForward(5100);
      assert.match(await title.innerText(), /^公告2/);
      await page.clock.runFor(300);
      await title.hover();
      await page.clock.fastForward(5100);
      assert.match(await title.innerText(), /^公告2/);
      await title.focus();
      await page.mouse.move(0, 0);
      await page.clock.fastForward(5100);
      assert.match(await title.innerText(), /^公告2/);
      await title.click();
      assert.equal(await page.locator('.dist-notice-body script, .dist-notice-body [onerror], .dist-notice-body a[href^="javascript:"]').count(), 0);
      await page.keyboard.press('Shift+Tab');
      assert.equal(await page.evaluate(() => document.activeElement.textContent), 'safe');
      await page.keyboard.press('Tab');
      assert.equal(await page.evaluate(() => document.activeElement.dataset.modalAction), 'cancel');
      await page.keyboard.press('Escape');
      await page.clock.runFor(50);
      assert.equal(await title.evaluate((el) => el === document.activeElement), true);
      assert.equal(await page.evaluate(() => Boolean(window.pwned)), false);
      assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
      fail = true;
      await page.evaluate(() => window.dispatchEvent(new Event('hashchange')));
      await page.locator('[data-action="retry-notices"]').waitFor();
      fail = false; count = 1;
      await page.locator('[data-action="retry-notices"]').click();
      await title.waitFor();
      assert.equal(await page.locator('[data-action="next-notice"]').count(), 0);
      count = 0;
      await page.evaluate(() => window.dispatchEvent(new Event('hashchange')));
      await page.waitForFunction(() => document.querySelector('#dist-notice-slot')?.innerHTML === '');
      assert.deepEqual(errors, []);
      console.log(`PASS notice browser ${width}px: pagination, rotation, pause, safe details, keyboard, retry, single, empty`);
      await page.close();
    }
  } finally { await browser.close(); }
})().catch((error) => { console.error(error); process.exitCode = 1; });
