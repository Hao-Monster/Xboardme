// Full user-theme browser regression. Every network response, including writes
// and logout, is local to this fixture; no production origin is contacted.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { createHash } = require('node:crypto');
const { test, before, after } = require('node:test');
const { expect } = require('playwright/test');
const { launchBrowser } = require('./helpers/browser-runtime.cjs');

const root = path.resolve(__dirname, '../..');
const sourceRoot = process.env.XBOARD_RECOVERY_SOURCE_ROOT || root;
const origin = 'http://distributor-recovery.example.invalid';
const token = 'Bearer QA-MOBILE-RECOVERY';
const artifactRoot = process.env.XBOARD_RECOVERY_ARTIFACT_DIR
  || path.join(os.tmpdir(), `xboard-mobile-recovery-${process.pid}`);
const files = {
  '/auth-session.js': 'theme/Xboard/assets/auth-session.js',
  '/umi.js': 'theme/Xboard/assets/umi.js',
  '/distributor.js': 'theme/Xboard/assets/distributor.js',
  '/distributor.css': 'theme/Xboard/assets/distributor.css',
};
const assets = Object.fromEntries(Object.entries(files).map(([url, file]) => [url, fs.readFileSync(path.join(url.endsWith('.css') ? root : sourceRoot, file))]));
const sourceDigests = Object.fromEntries(Object.entries(assets).map(([url, bytes]) => [url, createHash('sha256').update(bytes).digest('hex')]));
let browser;

before(async () => {
  fs.mkdirSync(artifactRoot, { recursive: true });
  browser = await launchBrowser();
});
after(async () => { await browser?.close(); });

async function fixture(t, { clock = false } = {}) {
  const context = await browser.newContext({
    viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true,
    deviceScaleFactor: 1, locale: 'zh-CN',
  });
  const page = await context.newPage();
  page.setDefaultTimeout(4500);
  if (clock) await page.clock.install({ time: new Date('2026-10-21T12:00:00Z') });
  const requests = [], pageErrors = [], rules = new Map();
  const state = { name: 'QA Merchant', committed: 0, isDistributor: true };
  page.on('pageerror', error => pageErrors.push(error.message));
  await page.addInitScript(() => {
    window.__qaRecoveryNotices = [];
    const seen = new WeakSet();
    new MutationObserver(() => {
      for (const node of document.querySelectorAll('.n-message__content, .dist-toast')) {
        const text = node.textContent.trim();
        if (text && !seen.has(node)) {
          seen.add(node);
          window.__qaRecoveryNotices.push(text);
        }
      }
    }).observe(document, { childList: true, subtree: true, characterData: true });
  });
  const plan = { id: 1, name: 'QA Mobile Plan', month_price: 1000, transfer_enable: 100, capacity_limit: null };
  const fulfill = (route, payload, status = 200) => route.fulfill({ status, json: payload });
  await page.route('**/*', async route => {
    const request = route.request();
    const url = new URL(request.url());
    if (url.origin === origin && assets[url.pathname]) {
      return route.fulfill({ contentType: url.pathname.endsWith('.css') ? 'text/css; charset=utf-8' : 'text/javascript; charset=utf-8', body: assets[url.pathname] });
    }
    if (url.origin === origin && url.pathname.startsWith('/api/')) {
      const record = { path: url.pathname, query: url.search, method: request.method(), resourceType: request.resourceType(), authorization: request.headers().authorization || null };
      requests.push(record);
      if (rules.has(url.pathname)) return rules.get(url.pathname)(route, record);
      if (url.pathname === '/api/v1/user/info') return fulfill(route, { status: 'success', data: {
        id: 7, email: 'qa-merchant@example.invalid', uuid: 'qa-mobile-uuid',
        is_distributor: state.isDistributor, distributor_name: state.name, plan_id: 1,
        balance: 0, commission_balance: 0, transfer_enable: 107374182400, expired_at: 1900000000,
      } });
      if (url.pathname === '/api/v1/user/plan/fetch') return fulfill(route, { data: [plan] });
      if (url.pathname === '/api/v1/user/notice/fetch') return fulfill(route, { data: [], total: 0 });
      if (url.pathname === '/api/v1/user/order/fetch') return fulfill(route, { data: [], total: 0, current_page: 1, last_page: 1, per_page: 20 });
      if (url.pathname === '/api/v1/user/getSubscribe') return fulfill(route, { data: {
        uuid: 'qa-mobile-uuid', subscribe_url: `${origin}/qa-subscription`, plan,
        transfer_enable: 107374182400, u: 0, d: 0, expired_at: 1900000000,
      } });
      if (url.pathname === '/api/v1/user/distributor/delivery') return fulfill(route, { data: {
        trade_no: url.searchParams.get('trade_no'), delivery_status: 1, config_issued_at: 1700000000,
      } });
      if (url.pathname === '/api/v1/user/logout') return fulfill(route, { status: 'success', data: true });
      if (url.pathname === '/api/v1/user/server/fetch') return fulfill(route, { data: [] });
      return fulfill(route, { status: 'success', data: {} });
    }
    if (url.origin === origin && url.pathname === '/') return route.fulfill({ contentType: 'text/html; charset=utf-8', body: `<!doctype html>
      <html lang="zh-CN"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
      <link rel="stylesheet" href="/distributor.css"><style>*{animation:none!important;transition:none!important}</style>
      <script>window.routerBase='/';window.settings={title:'QA Recovery',i18n:['zh-CN'],theme:{color:'default'}};
      localStorage.setItem('VUE_NAIVE_ACCESS_TOKEN',JSON.stringify({value:${JSON.stringify(token)},expire:null}));
      localStorage.setItem('xboard_distributor_locale','zh-CN');</script>
      <script src="/auth-session.js"></script><script type="module" src="/umi.js"></script></head>
      <body><div id="app"></div><script defer src="/distributor.js"></script></body></html>` });
    // This also catches third-party images/fonts. Nothing falls through to a network.
    return route.fulfill({ status: 404, body: '' });
  });
  t.after(async () => {
    const stem = t.name.replace(/[^a-z0-9]+/gi, '-').toLowerCase();
    try {
      const observed = await page.evaluate(() => ({
        hash: location.hash,
        tokenPresent: !!localStorage.getItem('VUE_NAIVE_ACCESS_TOKEN'),
        notices: window.__qaRecoveryNotices,
        error: document.querySelector('.dist-error')?.textContent || null,
      }));
      fs.writeFileSync(path.join(artifactRoot, `${stem}.json`), JSON.stringify({ sourceDigests, requests, pageErrors, observed }, null, 2));
      await page.screenshot({ path: path.join(artifactRoot, `${stem}.png`) });
    } finally {
      await context.close();
    }
  });
  return {
    page, requests, pageErrors, rules, state, fulfill,
    count: (pathname, method) => requests.filter(item => item.path === `/api/v1${pathname}` && (!method || item.method === method)).length,
    async open() {
      await page.goto(`${origin}/#/plan`);
      await expect(page.locator('#distributor-app [data-action="logout"]')).toBeVisible();
    },
    async ready() { await expect(page.locator('#distributor-app [data-buy="1"]')).toBeVisible(); },
    // This intentionally tests coalescing/handler behavior, not real BFCache or OS freezing.
    async resume() {
      await page.evaluate(() => {
        document.dispatchEvent(new Event('visibilitychange'));
        window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true }));
      });
      if (clock) await page.clock.runFor(100);
    },
    notices: () => page.evaluate(() => window.__qaRecoveryNotices.slice()),
    async stableLogin() {
      await expect(page).toHaveURL(/#\/login(?:\?|$)/);
      await expect(page.locator('#distributor-app')).toHaveCount(0);
      await expect(page.locator('#app')).toBeVisible();
      assert.equal(await page.evaluate(() => localStorage.getItem('VUE_NAIVE_ACCESS_TOKEN')), null);
      await page.waitForTimeout(120);
      await expect(page).toHaveURL(/#\/login(?:\?|$)/);
      assert.equal((await page.locator('body').textContent()).includes('Unauthorized'), false);
    },
  };
}

test('mobile resume retries the first transient identity GET once and coalesces both resume events', { timeout: 12000 }, async t => {
  const f = await fixture(t);
  await f.open(); await f.ready();
  const baseline = f.count('/user/info');
  let resumed = 0;
  f.rules.set('/api/v1/user/info', async route => {
    resumed++;
    if (resumed === 1) return route.abort('failed');
    return f.fulfill(route, { data: { email: 'qa-merchant@example.invalid', uuid: 'qa-mobile-uuid', is_distributor: true, distributor_name: 'QA Recovered' } });
  });
  await f.resume();
  await expect.poll(() => f.count('/user/info') - baseline, { timeout: 3500 }).toBe(2);
  await expect(f.page.locator('.dist-account')).toContainText('QA Recovered');
  await f.page.waitForTimeout(200);
  assert.equal(resumed, 2, 'one recovery batch permits exactly one replacement read');
  assert.ok(await f.page.evaluate(() => localStorage.getItem('VUE_NAIVE_ACCESS_TOKEN')));
  assert.ok((await f.notices()).length <= 1);
  assert.deepEqual(f.pageErrors, []);
});

test('mobile persistent offline business GET presents one failure and merged online resume restores one catalog', { timeout: 12000 }, async t => {
  const f = await fixture(t);
  await f.open(); await f.ready();
  await f.page.evaluate(() => { location.hash = '#/order'; });
  await expect(f.page.locator('.dist-orders-table')).toBeVisible();
  const baseline = f.count('/user/plan/fetch');
  f.rules.set('/api/v1/user/plan/fetch', route => route.abort('failed'));
  await f.page.evaluate(() => { location.hash = '#/plan'; });
  await expect(f.page.locator('#distributor-app .dist-error')).toBeVisible();
  await expect.poll(() => f.count('/user/plan/fetch') - baseline, { timeout: 3500 }).toBe(2);
  await f.page.waitForTimeout(700);
  assert.equal(f.count('/user/plan/fetch') - baseline, 2, 'persistent offline must not become a polling storm');
  await expect(f.page.locator('#distributor-app .dist-error')).toHaveCount(1);
  assert.ok((await f.notices()).length <= 1, 'both failed attempts must not stack toast notifications');
  assert.ok(await f.page.evaluate(() => localStorage.getItem('VUE_NAIVE_ACCESS_TOKEN')));
  f.rules.delete('/api/v1/user/plan/fetch');
  await f.page.evaluate(() => window.dispatchEvent(new Event('online')));
  await f.resume();
  await f.ready();
  await f.page.waitForTimeout(200);
  assert.equal(f.count('/user/plan/fetch') - baseline, 3, 'online and resume must merge into one successful replacement render');
  assert.deepEqual(f.pageErrors, []);
});

test('mobile real startup request failures produce one notice without hidden SPA business requests', { timeout: 12000 }, async t => {
  const f = await fixture(t);
  f.rules.set('/api/v1/user/comm/config', route => route.abort('failed'));
  f.rules.set('/api/v1/user/plan/fetch', route => route.abort('failed'));
  await f.open();
  await expect(f.page.locator('#distributor-app .dist-error')).toBeVisible();
  await f.page.waitForTimeout(150);
  await expect(f.page.locator('#distributor-app .dist-error')).toHaveCount(1);
  assert.ok((await f.notices()).length <= 1, 'the actual bootstrap failures must not stack unknown-error toasts');
  assert.deepEqual(f.pageErrors, [], 'the hidden ordinary SPA must not mount a failing plan component');
});

test('mobile distributor startup mounts one catalog and never the hidden ordinary-user business page', { timeout: 10000 }, async t => {
  const f = await fixture(t);
  await f.open(); await f.ready();
  await f.page.waitForTimeout(200);
  assert.equal(f.count('/user/plan/fetch'), 1, 'one distributor catalog must not fetch a second hidden SPA catalog');
  assert.equal(f.count('/user/getSubscribe'), 0, 'ordinary dashboard subscriptions must not load behind distributor view');
  assert.deepEqual(f.pageErrors, []);
});

test('mobile same-hash role changes unmount the ordinary catalog and restore it when the role returns', { timeout: 15000 }, async t => {
  const f = await fixture(t);
  f.state.isDistributor = false;
  await f.page.goto(`${origin}/#/plan`);
  await expect(f.page.locator('#app')).toBeVisible();
  await expect(f.page.locator('#app')).toContainText('QA Mobile Plan');
  await expect(f.page.locator('#distributor-app')).toHaveCount(0);

  f.state.isDistributor = true;
  await f.resume();
  await f.ready();
  await expect(f.page.locator('#app')).not.toContainText('QA Mobile Plan');
  await expect(f.page.locator('#app')).toBeHidden();
  await expect(f.page).toHaveURL(/#\/plan$/);
  const distributorCatalogReads = f.count('/user/plan/fetch');
  await f.page.waitForTimeout(200);
  assert.equal(f.count('/user/plan/fetch'), distributorCatalogReads, 'the previous ordinary catalog must stay unmounted');

  f.state.isDistributor = false;
  await f.resume();
  await expect(f.page.locator('#distributor-app')).toHaveCount(0);
  await expect(f.page.locator('#app')).toBeVisible();
  await expect(f.page.locator('#app')).toContainText('QA Mobile Plan');
  await expect(f.page).toHaveURL(/#\/plan$/);
  assert.ok(f.count('/user/plan/fetch') > distributorCatalogReads, 'the restored ordinary catalog must make its own fresh business read');
  assert.ok(await f.page.evaluate(() => localStorage.getItem('VUE_NAIVE_ACCESS_TOKEN')));
  assert.deepEqual(f.pageErrors, []);
});

for (const result of ['success', 'http-500', 'network-failure']) {
  test(`mobile logout ${result} stays on login after an outstanding GET completes`, { timeout: 12000 }, async t => {
    const f = await fixture(t);
    await f.open(); await f.ready();
    const pending = [];
    f.rules.set('/api/v1/user/order/fetch', route => { pending.push(route); });
    f.rules.set('/api/v1/user/logout', route => result === 'network-failure'
      ? route.abort('failed')
      : f.fulfill(route, result === 'http-500' ? { message: 'QA logout server unavailable' } : { data: true }, result === 'http-500' ? 500 : 200));
    await f.page.evaluate(() => { location.hash = '#/order'; });
    await expect.poll(() => pending.length).toBeGreaterThan(0);
    await f.page.locator('#distributor-app [data-action="logout"]').click();
    await f.stableLogin();
    for (const route of pending) {
      if (!route.request().failure()) await f.fulfill(route, { data: [], total: 0, current_page: 1, last_page: 1 });
    }
    await f.stableLogin();
    assert.equal(f.count('/user/logout', 'POST'), 1);
    assert.ok(f.requests.filter(item => item.path.endsWith('/logout')).every(item => item.authorization === token));
    assert.deepEqual(f.pageErrors, []);
  });
}

test('mobile business 422 remains visible and is not automatically retried', { timeout: 12000 }, async t => {
  const f = await fixture(t);
  await f.open(); await f.ready();
  await f.page.evaluate(() => { location.hash = '#/order'; });
  await expect(f.page.locator('.dist-orders-table')).toBeVisible();
  const baseline = f.count('/user/plan/fetch');
  f.rules.set('/api/v1/user/plan/fetch', route => f.fulfill(route, { message: 'QA 套餐当前不可购买' }, 422));
  await f.page.evaluate(() => { location.hash = '#/plan'; });
  await expect(f.page.locator('.dist-error')).toContainText('QA 套餐当前不可购买');
  await f.page.waitForTimeout(700);
  assert.equal(f.count('/user/plan/fetch') - baseline, 1);
  assert.ok(await f.page.evaluate(() => localStorage.getItem('VUE_NAIVE_ACCESS_TOKEN')));
  assert.deepEqual(f.pageErrors, []);
});

test('mobile confirmed 401 on resume visibly returns to login without retrying or revoking another session', { timeout: 12000 }, async t => {
  const f = await fixture(t);
  await f.open(); await f.ready();
  const baseline = f.count('/user/info');
  f.rules.set('/api/v1/user/info', route => f.fulfill(route, { message: 'QA session expired' }, 401));
  await f.resume();
  await f.stableLogin();
  assert.equal(f.count('/user/info') - baseline, 1);
  assert.equal(f.count('/user/logout', 'POST'), 0, 'confirmed expiry clears locally instead of sending a new logout mutation');
  assert.deepEqual(f.pageErrors, []);
});

test('mobile purchase pending beyond twelve seconds survives resume and read retry with exactly one POST', { timeout: 18000 }, async t => {
  const f = await fixture(t, { clock: true });
  await f.open(); await f.ready();
  const pending = [];
  f.rules.set('/api/v1/user/order/save', route => { pending.push(route); });
  await f.page.locator('[data-buy="1"]').click();
  await expect.poll(() => pending.length).toBe(1);
  await f.page.clock.runFor(13000);
  await f.resume();
  await f.page.evaluate(() => { location.hash = '#/order'; });
  await expect(f.page.locator('.dist-orders-table')).toBeVisible();
  f.rules.set('/api/v1/user/plan/fetch', route => route.abort('failed'));
  await f.page.evaluate(() => { location.hash = '#/plan'; });
  await expect(f.page.locator('.dist-error')).toBeVisible();
  f.rules.delete('/api/v1/user/plan/fetch');
  await f.page.locator('[data-action="retry"]').click();
  await f.ready();
  await f.page.locator('[data-buy="1"]').dispatchEvent('click');
  assert.equal(f.count('/user/order/save', 'POST'), 1);
  assert.equal(pending[0].request().failure(), null, 'a long write must not be client-aborted with the twelve-second read policy');
  await f.fulfill(pending[0], { data: 'QA-LONG-WRITE' });
  await expect(f.page.locator('#dist-modal-root .dist-delivery-result')).toBeVisible();
  assert.equal(f.count('/user/order/save', 'POST'), 1);
  assert.deepEqual(f.pageErrors, []);
});

test('mobile lost purchase response remains uncertain across resume and cannot submit the same purchase again', { timeout: 15000 }, async t => {
  const f = await fixture(t);
  await f.open(); await f.ready();
  f.rules.set('/api/v1/user/order/save', route => {
    f.state.committed++;
    return route.abort('failed'); // The server committed; the browser never received its response.
  });
  await f.page.locator('[data-buy="1"]').click();
  await expect.poll(() => f.state.committed).toBe(1);
  await f.page.waitForTimeout(100);
  await f.resume();
  await f.page.waitForTimeout(150);
  const buy = f.page.locator('#distributor-app [data-buy="1"]');
  if (await buy.count()) await buy.dispatchEvent('click');
  await f.page.waitForTimeout(150);
  assert.equal(f.count('/user/order/save', 'POST'), 1, 'a lost result must be reconciled or explicitly resolved before another purchase mutation');
  assert.equal(f.state.committed, 1);
  await expect(f.page.locator('#distributor-app')).toContainText('未能确认下单结果');
  assert.deepEqual(f.pageErrors, []);
});

for (const pathname of ['/', '/plan', '/ticket']) {
  for (const recovery of ['manual', 'automatic']) {
    test(`mobile ordinary startup ${pathname} recovers its original query after ${recovery} identity recovery`, { timeout: 12000 }, async t => {
      const f = await fixture(t);
      f.state.isDistributor = false;
      let networkRecovered = false;
      const identityReads = { xhr: 0, fetch: 0 };
      f.rules.set('/api/v1/user/info', route => {
        const type = route.request().resourceType();
        identityReads[type] = (identityReads[type] || 0) + 1;
        if ((recovery === 'manual' && !networkRecovered)
          || (recovery === 'automatic' && identityReads[type] === 1)) return route.abort('failed');
        return f.fulfill(route, { data: {
          id: 7, email: 'qa-ordinary@example.invalid', uuid: 'qa-ordinary', is_distributor: false,
        } });
      });
      f.rules.set('/api/v1/user/ticket/fetch', route => f.fulfill(route, { data: [] }));
      await f.page.goto(`${origin}/#${pathname}?ref=qa%20value&x=2`);
      await expect.poll(() => identityReads.fetch).toBe(2);
      if (recovery === 'manual') {
        await expect(f.page.locator('#xboard-auth-recovery-btn')).toBeVisible();
        networkRecovered = true;
        await f.page.locator('#xboard-auth-recovery-btn').click();
      }
      await expect(f.page.locator('#xboard-auth-recovery')).toHaveCount(0);
      await expect(f.page.locator('#distributor-app')).toHaveCount(0);
      await expect(f.page.locator('#app')).toContainText(pathname === '/plan' ? 'QA Mobile Plan' : pathname === '/ticket' ? '工单历史' : '仪表盘');
      const businessPath = pathname === '/' ? '/user/getSubscribe' : `/user/${pathname === '/plan' ? 'plan' : 'ticket'}/fetch`;
      await expect.poll(() => f.count(businessPath)).toBe(1);
      const hash = await f.page.evaluate(() => location.hash);
      assert.equal(hash.split('?')[0], pathname === '/' ? '#/dashboard' : `#${pathname}`);
      const query = new URLSearchParams(hash.split('?')[1]);
      assert.equal(query.get('ref'), 'qa value');
      assert.equal(query.get('x'), '2');
      assert.equal(f.count('/user/logout', 'POST'), 0);
      assert.deepEqual(f.pageErrors, []);
    });
  }
}

for (const recovery of ['manual', 'automatic']) {
  test(`mobile distributor startup keeps route ownership after ${recovery} identity recovery`, { timeout: 12000 }, async t => {
    const f = await fixture(t);
    let networkRecovered = false;
    const identityReads = { xhr: 0, fetch: 0 };
    f.rules.set('/api/v1/user/info', route => {
      const type = route.request().resourceType();
      identityReads[type] = (identityReads[type] || 0) + 1;
      if ((recovery === 'manual' && !networkRecovered)
        || (recovery === 'automatic' && identityReads[type] === 1)) return route.abort('failed');
      return f.fulfill(route, { data: {
        id: 7, email: 'qa-merchant@example.invalid', uuid: 'qa-mobile-uuid', is_distributor: true,
      } });
    });
    await f.page.goto(`${origin}/#/plan?ref=qa%20value&x=2`);
    await expect.poll(() => identityReads.fetch).toBe(2);
    if (recovery === 'manual') {
      await expect(f.page.locator('#xboard-auth-recovery-btn')).toBeVisible();
      networkRecovered = true;
      await f.page.locator('#xboard-auth-recovery-btn').click();
    }
    await f.ready();
    await expect(f.page.locator('#xboard-auth-recovery')).toHaveCount(0);
    await expect(f.page.locator('#app')).toBeHidden();
    assert.equal(f.count('/user/plan/fetch'), 1);
    assert.equal(f.requests.filter(item => item.path === '/api/v1/user/plan/fetch' && item.resourceType === 'xhr').length, 0);
    assert.equal(f.count('/user/getSubscribe'), 0);
    assert.deepEqual(f.pageErrors, []);
  });
}

test('mobile delayed ordinary identity cannot restore an abandoned startup target', { timeout: 12000 }, async t => {
  const f = await fixture(t);
  f.state.isDistributor = false;
  const pending = [];
  const identityReads = { xhr: 0, fetch: 0 };
  const identity = { data: { id: 7, email: 'qa-ordinary@example.invalid', uuid: 'qa-ordinary', is_distributor: false } };
  f.rules.set('/api/v1/user/info', route => {
    const type = route.request().resourceType();
    identityReads[type] = (identityReads[type] || 0) + 1;
    if (identityReads[type] === 1) return route.abort('failed');
    if (type === 'fetch') { pending.push(route); return; }
    return f.fulfill(route, identity);
  });
  f.rules.set('/api/v1/user/ticket/fetch', route => f.fulfill(route, { data: [] }));
  await f.page.goto(`${origin}/#/plan?abandoned=1`);
  await expect.poll(() => pending.length).toBe(1);
  await f.page.evaluate(() => { location.hash = '#/ticket?current=2'; });
  await expect(f.page.locator('#app')).toContainText('工单历史');
  await f.fulfill(pending[0], identity);
  await f.page.waitForTimeout(200);
  await expect(f.page).toHaveURL(/#\/ticket\?current=2$/);
  assert.equal(f.count('/user/plan/fetch'), 0);
  assert.equal(f.count('/user/ticket/fetch'), 1);
  await expect(f.page.locator('#xboard-auth-recovery')).toHaveCount(0);
  assert.deepEqual(f.pageErrors, []);
});

test('mobile queued startup retry cannot navigate a replacement account to the old target', { timeout: 12000 }, async t => {
  const f = await fixture(t);
  f.state.isDistributor = false;
  f.rules.set('/api/v1/user/info', (route, record) => record.authorization === 'Bearer QA-REPLACEMENT'
    ? f.fulfill(route, { data: { id: 8, email: 'qa-replacement@example.invalid', uuid: 'qa-replacement', is_distributor: false } })
    : route.abort('failed'));
  f.rules.set('/api/v1/user/ticket/fetch', route => f.fulfill(route, { data: [] }));
  await f.page.goto(`${origin}/#/plan?old-account=1`);
  await expect.poll(() => f.count('/user/info')).toBe(3);
  await expect(f.page.locator('#xboard-auth-recovery-btn')).toBeVisible();
  const before = f.requests.length;
  await f.page.evaluate(() => {
    window.__xboardAuthSession.retryRecovery();
    localStorage.setItem('VUE_NAIVE_ACCESS_TOKEN', JSON.stringify({ value: 'Bearer QA-REPLACEMENT', expire: null }));
    location.hash = '#/ticket?new-account=2';
  });
  await expect(f.page.locator('#app')).toContainText('工单历史');
  await expect(f.page).toHaveURL(/#\/ticket\?new-account=2$/);
  await f.page.waitForTimeout(200);
  assert.equal(f.requests.slice(before).filter(item => item.path.endsWith('/user/info') && item.authorization === token).length, 0);
  assert.equal(f.count('/user/plan/fetch'), 0);
  assert.equal(f.count('/user/ticket/fetch'), 1);
  await expect(f.page.locator('#xboard-auth-recovery')).toHaveCount(0);
  assert.deepEqual(f.pageErrors, []);
});

function reviewBrowserOrder(tradeNo = 'QA-REVIEW-SUBSCRIPTION') {
  return {
    id: 101, trade_no: tradeNo, subscription_trade_no: tradeNo, is_subscription_origin: true,
    can_renew: true, period: 'month_price', total_amount: 1000, created_at: 1700000000,
    settlement_status: 0, delivery_status: 1, bound_devices: [],
    plan: { id: 1, name: 'QA Mobile Plan', month_price: 1000, quarter_price: 2800 },
    subscription_entitlement: { expired_at: 1900000000, transfer_enable: 107374182400 },
  };
}

test('review browser renewal completed while its modal is closed remains confirmed on reopen', { timeout: 12000 }, async t => {
  const f = await fixture(t);
  const order = reviewBrowserOrder();
  f.rules.set('/api/v1/user/order/fetch', route => f.fulfill(route, { data: [order], current_page: 1, per_page: 20, total: 1, last_page: 1 }));
  const pending = [];
  f.rules.set('/api/v1/user/order/renew', route => { pending.push(route); });
  await f.open(); await f.ready();
  await f.page.locator('[data-nav="/order"]').click();
  const renew = f.page.locator('[data-renew="QA-REVIEW-SUBSCRIPTION"]:visible').first();
  await renew.click();
  await f.page.locator('[data-modal-action="confirm-renewal"]').click();
  await expect.poll(() => pending.length).toBe(1);
  await f.page.locator('.dist-modal-x[data-modal-action="cancel"]').click();
  await renew.click();
  await expect(f.page.locator('[data-modal-action="confirm-renewal"]')).toBeDisabled();
  await f.page.locator('[data-modal-action="confirm-renewal"]').dispatchEvent('click');
  assert.equal(f.count('/user/order/renew', 'POST'), 1);
  await f.page.locator('.dist-modal-x[data-modal-action="cancel"]').click();
  await f.fulfill(pending[0], { data: { trade_no: 'QA-REVIEW-RENEWED', expired_at_after: 1910000000 } });
  await f.page.waitForTimeout(150);
  await expect(f.page.locator('.dist-renewal-modal')).toHaveCount(0);
  await renew.click();
  await expect(f.page.locator('.dist-renewal-modal')).toContainText('QA-REVIEW-RENEWED');
  await expect(f.page.locator('[data-modal-action="confirm-renewal"]')).toHaveCount(0);
  assert.equal(f.count('/user/order/renew', 'POST'), 1);
  assert.deepEqual(f.pageErrors, []);
});

test('review browser uncertain renewal retries only on a tap with the same key and billing period', { timeout: 12000 }, async t => {
  const f = await fixture(t);
  const order = reviewBrowserOrder();
  const writes = [], committed = new Set();
  f.rules.set('/api/v1/user/order/fetch', route => f.fulfill(route, { data: [order], current_page: 1, per_page: 20, total: 1, last_page: 1 }));
  f.rules.set('/api/v1/user/order/renew', route => {
    const write = Object.fromEntries(new URLSearchParams(route.request().postData()));
    writes.push(write); committed.add(write.idempotency_key);
    return writes.length === 1 ? route.abort('failed') : f.fulfill(route, { data: { trade_no: 'QA-REVIEW-REPLAY', expired_at_after: 1910000000 } });
  });
  await f.open(); await f.ready();
  await f.page.locator('[data-nav="/order"]').click();
  await f.page.locator('[data-renew="QA-REVIEW-SUBSCRIPTION"]:visible').first().click();
  await f.page.locator('[data-modal-action="confirm-renewal"]').click();
  await expect(f.page.locator('.dist-renewal-modal [role="alert"]')).toContainText('续费结果尚未确认');
  await expect(f.page.locator('#dist-renewal-period')).toBeDisabled();
  await f.page.locator('.dist-modal-x[data-modal-action="cancel"]').click();
  await f.page.locator('[data-renew="QA-REVIEW-SUBSCRIPTION"]:visible').first().click();
  await expect(f.page.locator('#dist-renewal-period')).toBeDisabled();
  assert.equal(writes.length, 1, 'closing and reopening never replays a mutation');
  await f.page.locator('[data-modal-action="confirm-renewal"]').click();
  await expect(f.page.locator('.dist-renewal-modal')).toContainText('QA-REVIEW-REPLAY');
  assert.equal(writes.length, 2);
  assert.equal(writes[0].idempotency_key, writes[1].idempotency_key);
  assert.equal(writes[0].period, writes[1].period);
  assert.equal(committed.size, 1, 'the mocked backend accepts only one operation identity');
  assert.deepEqual(f.pageErrors, []);
});

for (const entry of ['banner', 'navigation']) {
  test(`review browser ${entry} order check clears old filters and pagination without unlocking an empty result`, { timeout: 15000 }, async t => {
    const f = await fixture(t);
    f.rules.set('/api/v1/user/order/fetch', (route, record) => {
      const query = new URLSearchParams(record.query);
      const filtered = query.has('search');
      return f.fulfill(route, { data: filtered ? [reviewBrowserOrder()] : [], current_page: Number(query.get('page') || 1), per_page: 20, total: filtered ? 60 : 0, last_page: filtered ? 3 : 1 });
    });
    f.rules.set('/api/v1/user/order/save', route => route.abort('failed'));
    await f.open(); await f.ready();
    await f.page.locator('[data-nav="/order"]').click();
    await f.page.locator('#dist-order-search').fill('OLD-CUSTOMER');
    await f.page.locator('[data-action="search-orders"]').click();
    await expect(f.page.locator('[data-renew="QA-REVIEW-SUBSCRIPTION"]:visible').first()).toBeVisible();
    await f.page.locator('[data-action="toggle-order-filters"]:visible').first().click();
    await f.page.locator('#dist-order-start').fill('2020-01-01');
    await f.page.locator('#dist-order-end').fill('2020-01-02');
    await f.page.locator('#dist-order-settlement').selectOption('1');
    await f.page.locator('#dist-order-min-amount').fill('99');
    await f.page.locator('[data-action="apply-order-filters"]').click();
    await f.page.locator('[data-order-page="2"]').click();
    await expect.poll(() => f.requests.some(item => item.path.endsWith('/order/fetch') && new URLSearchParams(item.query).get('page') === '2')).toBe(true);
    await f.page.locator('[data-nav="/plan"]').click();
    await f.ready();
    await f.page.locator('[data-buy="1"]').click();
    await expect(f.page.locator('.dist-purchase-recovery')).toContainText('未能确认下单结果');
    const before = f.count('/user/order/fetch');
    await f.page.locator(entry === 'banner' ? '[data-action="check-purchase-orders"]' : '[data-nav="/order"]').click();
    await expect(f.page.locator('[data-action="acknowledge-order-check"]')).toBeVisible();
    assert.equal(f.count('/user/order/fetch') - before, 1);
    const query = new URLSearchParams(f.requests.filter(item => item.path.endsWith('/order/fetch')).at(-1).query);
    assert.equal(query.get('page'), '1');
    for (const key of ['search', 'start_date', 'end_date', 'settlement_status', 'min_amount', 'max_amount', 'periods[]']) assert.equal(query.has(key), false, key);
    await f.page.locator('[data-nav="/plan"]').click();
    await f.ready();
    await f.page.locator('[data-buy="1"]').dispatchEvent('click');
    assert.equal(f.count('/user/order/save', 'POST'), 1, 'empty orders do not release the purchase lock');
    await expect(f.page.locator('.dist-purchase-recovery')).toBeVisible();
    assert.deepEqual(f.pageErrors, []);
  });
}

test('review browser delivery recovery removes the order warning while keeping its successful modal', { timeout: 12000 }, async t => {
  const f = await fixture(t);
  f.rules.set('/api/v1/user/order/save', route => f.fulfill(route, { data: 'QA-KNOWN-REVIEW-ORDER' }));
  f.rules.set('/api/v1/user/order/detail', (route, record) => {
    assert.equal(new URLSearchParams(record.query).get('trade_no'), 'QA-KNOWN-REVIEW-ORDER');
    return f.fulfill(route, { data: reviewBrowserOrder('QA-KNOWN-REVIEW-ORDER') });
  });
  let deliveryReads = 0;
  f.rules.set('/api/v1/user/distributor/delivery', route => ++deliveryReads === 1
    ? f.fulfill(route, { message: 'QA initial delivery failure' }, 503)
    : f.fulfill(route, { data: { trade_no: 'QA-KNOWN-REVIEW-ORDER', delivery_status: 1, config_issued_at: 1700000000 } }));
  await f.open(); await f.ready();
  await f.page.locator('[data-buy="1"]').click();
  await expect(f.page.locator('.dist-purchase-recovery')).toContainText('QA-KNOWN-REVIEW-ORDER');
  await f.page.locator('[data-nav="/order"]').click();
  await expect(f.page.locator('.dist-orders-table')).toBeVisible();
  await f.page.locator('[data-action="retry-purchase-delivery"]').click();
  await expect(f.page.locator('.dist-delivery-result')).toBeVisible();
  await expect(f.page.locator('.dist-purchase-recovery')).toHaveCount(0);
  await f.page.locator('.dist-modal-x[data-modal-action="close-delivery"]').click();
  await expect(f.page.locator('.dist-purchase-recovery')).toHaveCount(0);
  assert.equal(deliveryReads, 2);
  assert.equal(f.count('/user/order/save', 'POST'), 1);
  assert.deepEqual(f.pageErrors, []);
});

test('review browser foreground restores a cancelled new search while retaining the previous table', { timeout: 12000 }, async t => {
  const f = await fixture(t);
  const searches = [];
  f.rules.set('/api/v1/user/order/fetch', (route, record) => {
    if (new URLSearchParams(record.query).get('search') === 'NEW-CUSTOMER') { searches.push(route); return; }
    return f.fulfill(route, { data: [reviewBrowserOrder('QA-OLD-ORDER')], current_page: 1, per_page: 20, total: 1, last_page: 1 });
  });
  await f.open(); await f.ready();
  await f.page.locator('[data-nav="/order"]').click();
  await expect(f.page.locator('.dist-orders-table')).toContainText('QA-OLD-ORDER');
  await f.page.locator('#dist-order-search').fill('NEW-CUSTOMER');
  await f.page.locator('[data-action="search-orders"]').click();
  await expect.poll(() => searches.length).toBe(1);
  await f.page.evaluate(() => {
    Object.defineProperty(document, 'hidden', { configurable: true, value: true });
    document.dispatchEvent(new Event('visibilitychange'));
  });
  await f.page.evaluate(() => Object.defineProperty(document, 'hidden', { configurable: true, value: false }));
  await f.resume();
  await expect.poll(() => searches.length).toBe(2);
  await expect(f.page.locator('.dist-orders-table')).toContainText('QA-OLD-ORDER');
  await expect(f.page.locator('.dist-loading')).toHaveCount(0);
  await f.fulfill(searches[1], { data: [reviewBrowserOrder('QA-NEW-ORDER')], current_page: 1, per_page: 20, total: 1, last_page: 1 });
  await expect(f.page.locator('.dist-orders-table')).toContainText('QA-NEW-ORDER');
  await expect(f.page.locator('.dist-orders-table')).not.toContainText('QA-OLD-ORDER');
  await expect(f.page.locator('#dist-order-search')).toHaveValue('NEW-CUSTOMER');
  assert.equal(searches.length, 2);
  assert.deepEqual(f.pageErrors, []);
});

for (const result of ['network', 502, 429, 401, 403]) {
  test(`review R8 delayed main identity ${result} respects a concurrently confirmed distributor`, { timeout: 12000 }, async t => {
    const f = await fixture(t);
    const pendingMain = [];
    f.rules.set('/api/v1/user/info', route => {
      if (route.request().resourceType() === 'xhr') { pendingMain.push(route); return; }
      return f.fulfill(route, { data: {
        id: 7, email: 'qa-merchant@example.invalid', uuid: 'qa-mobile-uuid', is_distributor: true,
      } });
    });
    await f.open(); await f.ready();
    await expect.poll(() => pendingMain.length).toBe(1);
    if (result === 'network') await pendingMain[0].abort('failed');
    else await f.fulfill(pendingMain[0], { message: result === 403 ? '未登录或登陆已过期' : 'QA delayed identity failure' }, result);
    await f.page.waitForTimeout(150);
    if (result === 401 || result === 403) {
      await f.stableLogin();
      assert.equal(f.count('/user/logout', 'POST'), 0, 'a confirmed expired session clears locally');
    } else {
      await f.ready();
      await expect(f.page.locator('#xboard-auth-recovery')).toHaveCount(0);
      await expect(f.page.locator('#app')).toBeHidden();
      assert.ok(await f.page.evaluate(() => localStorage.getItem('VUE_NAIVE_ACCESS_TOKEN')));
      assert.equal(f.count('/user/info'), 2, 'the confirmed identity must be reused without another read');
      assert.equal(f.count('/user/plan/fetch'), 1);
      assert.equal(f.count('/user/getSubscribe'), 0);
    }
    assert.deepEqual(f.pageErrors, []);
  });
}

test('review R7 uncertain renewal stays bound to the original key after a later 422 rejection', { timeout: 15000 }, async t => {
  const f = await fixture(t);
  const order = reviewBrowserOrder();
  const writes = [], committed = new Set();
  f.rules.set('/api/v1/user/order/fetch', route => f.fulfill(route, { data: [order], current_page: 1, per_page: 20, total: 1, last_page: 1 }));
  f.rules.set('/api/v1/user/order/renew', route => {
    const write = Object.fromEntries(new URLSearchParams(route.request().postData()));
    writes.push(write);
    if (writes.length === 2) return f.fulfill(route, { message: 'QA replay temporarily rejected' }, 422);
    committed.add(write.idempotency_key);
    return writes.length === 1 ? route.abort('failed') : f.fulfill(route, { data: { trade_no: 'QA-STICKY-RENEWAL', expired_at_after: 1910000000 } });
  });
  await f.open(); await f.ready();
  await f.page.locator('[data-nav="/order"]').click();
  const renew = f.page.locator('[data-renew="QA-REVIEW-SUBSCRIPTION"]:visible').first();
  await renew.click();
  await f.page.locator('[data-modal-action="confirm-renewal"]').click();
  await expect(f.page.locator('.dist-renewal-modal [role="alert"]')).toContainText('续费结果尚未确认');
  await f.page.locator('[data-modal-action="confirm-renewal"]').click();
  await expect.poll(() => writes.length).toBe(2);
  await expect(f.page.locator('#dist-renewal-period')).toBeDisabled();
  await expect(f.page.locator('.dist-renewal-modal [role="alert"]')).toContainText('续费结果尚未确认');
  await f.page.locator('#dist-renewal-period').evaluate(select => {
    select.value = 'quarter_price';
    select.dispatchEvent(new Event('change', { bubbles: true }));
  });
  await f.page.locator('.dist-modal-x[data-modal-action="cancel"]').click();
  await renew.click();
  await expect(f.page.locator('#dist-renewal-period')).toBeDisabled();
  await expect(f.page.locator('#dist-renewal-period')).toHaveValue('month_price');
  await f.page.locator('[data-modal-action="confirm-renewal"]').click();
  await expect(f.page.locator('.dist-renewal-modal')).toContainText('QA-STICKY-RENEWAL');
  assert.equal(writes.length, 3);
  assert.equal(new Set(writes.map(write => write.idempotency_key)).size, 1);
  assert.ok(writes[0].idempotency_key);
  assert.deepEqual(writes.map(write => write.period), ['month_price', 'month_price', 'month_price']);
  assert.equal(committed.size, 1, 'the mock backend must commit one operation even after an intermediate rejection');
  assert.deepEqual(f.pageErrors, []);
});

test('review R9 hidden order to plan navigation never retains old order content after failed recovery', { timeout: 15000 }, async t => {
  const f = await fixture(t);
  f.rules.set('/api/v1/user/order/fetch', route => f.fulfill(route, { data: [reviewBrowserOrder('QA-PREVIOUS-ORDER')], current_page: 1, per_page: 20, total: 1, last_page: 1 }));
  await f.open(); await f.ready();
  await f.page.locator('[data-nav="/order"]').click();
  await expect(f.page.locator('.dist-orders-table')).toContainText('QA-PREVIOUS-ORDER');
  const before = f.count('/user/plan/fetch');
  f.rules.set('/api/v1/user/plan/fetch', route => route.abort('failed'));
  await f.page.evaluate(() => {
    Object.defineProperty(document, 'hidden', { configurable: true, value: true });
    document.dispatchEvent(new Event('visibilitychange'));
    location.hash = '#/plan';
  });
  await f.page.waitForTimeout(100);
  await f.page.evaluate(() => Object.defineProperty(document, 'hidden', { configurable: true, value: false }));
  await f.resume();
  await expect.poll(() => f.count('/user/plan/fetch') - before).toBe(2);
  await expect(f.page.locator('.dist-error [data-action="retry"]')).toBeVisible();
  await expect(f.page.locator('.dist-orders-table')).toHaveCount(0);
  await expect(f.page.locator('#distributor-app')).not.toContainText('QA-PREVIOUS-ORDER');
  await expect(f.page).toHaveURL(/#\/plan$/);
  f.rules.delete('/api/v1/user/plan/fetch');
  await f.page.locator('.dist-error [data-action="retry"]').click();
  await f.ready();
  assert.equal(f.count('/user/plan/fetch') - before, 3);
  assert.deepEqual(f.pageErrors, []);
});
