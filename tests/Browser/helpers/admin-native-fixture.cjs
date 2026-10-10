const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { randomUUID } = require('node:crypto');
const { expect } = require('playwright/test');

const publicRoot = path.resolve(__dirname, '../../..', 'public');
const adminRoot = path.join(publicRoot, 'assets/admin');
const manifest = JSON.parse(fs.readFileSync(path.join(adminRoot, 'manifest.json'), 'utf8'));

function nativeDocument() {
  const scripts = new Set();
  const styles = new Set();
  const visited = new Set();
  function collect(name) {
    if (visited.has(name)) return;
    visited.add(name);
    const chunk = manifest[name];
    assert.ok(chunk, `Missing admin manifest entry ${name}`);
    for (const css of chunk.css || []) styles.add(css);
    for (const dependency of chunk.imports || []) collect(dependency);
    if (chunk.isEntry) scripts.add(chunk.file);
  }
  collect('index.html');
  assert.ok(scripts.size > 0, 'The native admin entry must be present');
  const locales = fs.readdirSync(path.join(adminRoot, 'locales')).filter((file) => file.endsWith('.js')).sort();
  // Keep the companion/native CSS and script order from admin.blade.php. The
  // native React editor, Radix dialogs and style rules are not simulated here.
  return `<!doctype html><html lang="zh-CN"><head><meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>QA native distributor editor</title>
    <script>window.settings={base_url:'/',secure_path:'qa-admin',title:'QA local fixture',version:'qa-only'};</script>
    <link rel="stylesheet" href="/assets/admin-distributor.css">
    <script defer src="/assets/admin-distributor.js"></script>
    ${[...styles].map((file) => `<link rel="stylesheet" href="/assets/admin/${file}">`).join('')}
    ${locales.map((file) => `<script src="/assets/admin/locales/${file}"></script>`).join('')}
    ${[...scripts].map((file) => `<script type="module" src="/assets/admin/${file}"></script>`).join('')}
    </head><body><div id="root"></div></body></html>`;
}

async function nativeAdminFixture(browser, t) {
  const context = await browser.newContext({ viewport: { width: 1440, height: 1100 }, locale: 'zh-CN' });
  const errors = [];
  const state = {
    user: {
      id: 7, email: 'qa-native-merchant@example.invalid', distributor_name: 'QA Old Merchant',
      distributor_revision: null, is_distributor: true, is_admin: false, is_staff: false,
      banned: false, balance: 12345, commission_balance: 6789, remarks: '', u: 0, d: 0,
      transfer_enable: 10737418240, expired_at: null, plan_id: null, group_id: null,
      commission_type: 0, commission_rate: null, discount: null, speed_limit: null,
      device_limit: null, created_at: 1791561600, updated_at: 1791561600,
      uuid: '00000000-0000-4000-8000-000000000007', token: 'qa-public-subscription',
    },
    updates: [], renames: [], requests: [], holdUserFetch: false, releaseUserFetch: null,
  };
  t.after(async () => {
    state.releaseUserFetch?.();
    await context.close();
    assert.deepEqual(errors, [], 'Native admin fixture must have no unhandled requests or JavaScript errors');
  });
  await context.addInitScript(() => {
    localStorage.setItem('XBOARD_ACCESS_TOKEN', JSON.stringify({ value: 'qa-only', expire: null }));
    localStorage.setItem('i18nextLng', 'zh-CN');
  });
  await context.route('**/*', async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    state.requests.push(`${request.method()} ${url.pathname}`);
    if (['https://api.iconify.design', 'https://api.unisvg.com', 'https://api.simplesvg.com'].includes(url.origin) && url.pathname === '/ion.json') {
      // The native sidebar loads icons from Iconify. Fulfil only this known
      // asset request locally; no external request is allowed to reach a server.
      const names = (url.searchParams.get('icons') || '').split(',').filter(Boolean);
      const icons = Object.fromEntries(names.map((name) => [name, { body: '<path d="M4 4h16v16H4z"/>' }]));
      return route.fulfill({ json: { prefix: 'ion', width: 24, height: 24, icons } });
    }
    if (url.origin !== 'http://127.0.0.1') {
      errors.push(`External request blocked: ${url.origin}${url.pathname}`);
      return route.abort();
    }
    if (url.pathname === '/') return route.fulfill({ contentType: 'text/html', body: nativeDocument() });
    if (url.pathname.startsWith('/assets/')) {
      const file = path.resolve(publicRoot, `.${url.pathname}`);
      if (!file.startsWith(`${publicRoot}${path.sep}`) || !fs.existsSync(file)) {
        errors.push(`Missing fixture asset: ${url.pathname}`);
        return route.abort();
      }
      const contentType = file.endsWith('.js') ? 'text/javascript' : file.endsWith('.css') ? 'text/css' : 'application/octet-stream';
      return route.fulfill({ contentType, body: fs.readFileSync(file) });
    }
    if (url.pathname === '/api/v2/user/info') return route.fulfill({ json: { data: {
      id: 1, email: 'qa-admin@example.invalid', is_admin: true, is_staff: false, avatar_url: null,
    } } });
    if (url.pathname.endsWith('/guest/comm/config')) return route.fulfill({ json: { data: { app_name: 'QA local fixture' } } });
    if (url.pathname.endsWith('/user/fetch')) {
      const user = { ...state.user, balance: state.user.balance / 100, commission_balance: state.user.commission_balance / 100 };
      if (state.holdUserFetch) await new Promise((resolve) => { state.releaseUserFetch = resolve; });
      return route.fulfill({ json: { total: 1, data: [user] } });
    }
    if (url.pathname.endsWith('/user/getUserInfoById')) return route.fulfill({ json: { data: { ...state.user } } });
    if (url.pathname.endsWith('/user/distributor/options')) {
      const { id, email, distributor_name, banned } = state.user;
      return route.fulfill({ json: { data: state.user.is_distributor ? [{ id, email, distributor_name, banned }] : [] } });
    }
    if (['/plan/fetch', '/server/group/fetch', '/order/fetch', '/plugin/getPlugins'].some((suffix) => url.pathname.endsWith(suffix))) {
      return route.fulfill({ json: { total: 0, data: [] } });
    }
    if (url.pathname.endsWith('/user/distributor/rename')) {
      const body = request.postDataJSON();
      state.renames.push(body);
      if (!state.user.is_distributor || body.expected_distributor_revision !== state.user.distributor_revision) {
        return route.fulfill({ status: 409, json: { status: 'fail', message: '分销商资料已变化，请重新读取后再试' } });
      }
      state.user.distributor_name = body.distributor_name;
      state.user.distributor_revision = randomUUID();
      return route.fulfill({ json: { status: 'success', data: true } });
    }
    if (url.pathname.endsWith('/user/update')) {
      const body = request.postDataJSON();
      state.updates.push(body);
      // Model the API's partial-update contract, including the unsafe legacy
      // identity fields so a polluted native request actually reproduces loss.
      if (Object.hasOwn(body, 'remarks')) state.user.remarks = body.remarks;
      if (Object.hasOwn(body, 'email')) state.user.email = body.email;
      if (Object.hasOwn(body, 'is_distributor')) state.user.is_distributor = Boolean(body.is_distributor);
      if (Object.hasOwn(body, 'distributor_name')) state.user.distributor_name = body.distributor_name;
      if (!state.user.is_distributor) state.user.distributor_name = null;
      if (Object.hasOwn(body, 'is_distributor') || Object.hasOwn(body, 'distributor_name')) state.user.distributor_revision = randomUUID();
      return route.fulfill({ json: { status: 'success', data: true } });
    }
    if (url.pathname === '/favicon.ico') return route.fulfill({ status: 204 });
    errors.push(`Unhandled fixture request: ${request.method()} ${url.pathname}`);
    return route.abort();
  });
  async function page() {
    const page = await context.newPage();
    page.setDefaultTimeout(5000);
    page.on('pageerror', (error) => errors.push(error.message));
    page.on('console', (message) => {
      if (message.type() === 'error' && !message.text().startsWith('Failed to load resource:')) errors.push(message.text());
    });
    page.on('dialog', (dialog) => dialog.accept());
    await page.goto('http://127.0.0.1/#/user/manage');
    await expect(page.getByRole('heading', { name: '用户管理', exact: true })).toBeVisible();
    return page;
  }
  async function openNativeEditor(page) {
    const row = page.getByRole('row').filter({ hasText: state.user.email });
    await row.getByRole('button', { name: '操作', exact: true }).click();
    await page.getByRole('menuitem', { name: '编辑', exact: true }).click();
    const editor = page.getByRole('dialog', { name: '用户管理', exact: true });
    await expect(editor.locator('[data-distributor-name-value]')).toHaveText(state.user.distributor_name);
    return editor;
  }
  async function openDistributors(page) {
    await page.locator('#admin-dist-entry').click();
    await page.locator('[data-tab="users"]').click();
    await expect(page.locator('.admin-dist-user-list [data-distributor-rename="7"]')).toBeVisible();
  }
  return { state, page, openNativeEditor, openDistributors };
}

module.exports = { nativeAdminFixture };
