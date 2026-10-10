// Real browser UI, synthetic API boundary only; never connects to production.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { randomUUID } = require('node:crypto');
const { test, before, after } = require('node:test');
const { launchBrowser } = require('./helpers/browser-runtime.cjs');
const { expect } = require('playwright/test');

let browser;
before(async () => { browser = await launchBrowser(); });
after(async () => { await browser?.close(); });

async function fixture(t, width = 1440) {
  const page = await browser.newPage({ viewport: { width, height: 900 } });
  page.setDefaultTimeout(5000);
  const state = {
    user: { id: 7, email: 'qa-merchant@example.invalid', distributor_name: '旧<&商户>', distributor_revision: null, is_distributor: true, banned: false, balance: 12345, commission_balance: 6789 },
    updates: [], completed: [], reads: 0, mode: 'ok', readFailure: false, release: null,
  };
  const errors = [];
  page.on('pageerror', (error) => errors.push(error.message));
  page.on('console', (message) => {
    if (message.type() === 'error' && !/Failed to load resource/.test(message.text())) errors.push(message.text());
  });
  t.after(async () => { await page.close(); assert.deepEqual(errors, []); });
  await page.route('**/*', async (route) => {
    const url = new URL(route.request().url());
    if (url.pathname === '/') return route.fulfill({ contentType: 'text/html', body: '<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><main><h1>用户管理</h1></main>' });
    if (url.pathname.endsWith('/user/distributor/options')) {
      const { id, email, distributor_name, banned } = state.user;
      return route.fulfill({ json: { data: state.user.is_distributor ? [{ id, email, distributor_name, banned }] : [] } });
    }
    if (url.pathname.endsWith('/order/fetch')) return route.fulfill({ json: { total: 0, data: [] } });
    if (url.pathname.endsWith('/user/fetch')) {
      const user = { ...state.user, balance: state.user.balance / 100, commission_balance: state.user.commission_balance / 100 };
      if (state.delayUserList) await new Promise((resolve) => { state.releaseUserList = resolve; });
      return route.fulfill({ json: { data: [user] } });
    }
    if (url.pathname.endsWith('/getUserInfoById')) {
      state.reads++;
      if (state.readFailure) return route.fulfill({ status: 503, json: { message: 'QA读取失败' } });
      const user = url.searchParams.get('id') === '8'
        ? { id: 8, email: 'qa-other@example.invalid', is_distributor: false, distributor_name: null }
        : state.user;
      return route.fulfill({ json: { data: user } });
    }
    if (url.pathname.endsWith('/user/update')) {
      const data = route.request().postDataJSON();
      state.updates.push(data);
      if ('is_distributor' in data) state.user.is_distributor = Boolean(data.is_distributor);
      if ('distributor_name' in data) state.user.distributor_name = data.distributor_name;
      if ('is_distributor' in data || 'distributor_name' in data) state.user.distributor_revision = randomUUID();
      return route.fulfill({ json: { status: 'success', data: true } });
    }
    if (url.pathname.endsWith('/user/distributor/rename')) {
      const data = route.request().postDataJSON();
      const mode = state.mode;
      state.updates.push(data);
      // Browser abort is not server cancellation: release can still apply the CAS.
      if (mode === 'pending' || mode === 'timeout') await new Promise((resolve) => { state.release = resolve; });
      if (mode === 'reject') return route.fulfill({ status: 422, json: { status: 'fail', message: 'QA名称被拒绝' } });
      if (mode === 'server-error') return route.fulfill({ status: 503, json: { message: 'QA服务暂不可用' } });
      if (mode === 'role-changed') {
        state.user.is_distributor = false;
        state.user.distributor_name = null;
        state.user.distributor_revision = randomUUID();
      } else if (!state.user.is_distributor || data.expected_distributor_revision !== state.user.distributor_revision) {
        state.completed.push({ name: data.distributor_name, status: 409 });
        return route.fulfill({ status: 409, json: { message: '分销商信息已变化，请核对后重试' } });
      } else if (mode !== 'mismatch') {
        state.user.distributor_name = data.distributor_name;
        state.user.distributor_revision = randomUUID();
      }
      state.completed.push({ name: data.distributor_name, status: 200 });
      if (mode === 'lost-response') return route.abort('failed');
      if (mode === 'read-failed') state.readFailure = true;
      return route.fulfill({ json: { status: 'success', data: true } });
    }
    if (url.pathname === '/favicon.ico') return route.fulfill({ status: 204 });
    errors.push(`Unexpected request: ${url.pathname}`);
    return route.abort();
  });
  await page.goto('http://127.0.0.1/');
  await page.evaluate(() => {
    localStorage.setItem('XBOARD_ACCESS_TOKEN', JSON.stringify({ value: 'qa-only', expire: null }));
    window.settings = { secure_path: 'qa-admin' };
  });
  await page.addStyleTag({ content: fs.readFileSync('public/assets/admin-distributor.css', 'utf8') });
  await page.addScriptTag({ content: fs.readFileSync('public/assets/admin-distributor.js', 'utf8') });
  await page.locator('#admin-dist-entry').click();
  await page.locator('[data-tab="users"]').click();
  await page.locator('.admin-dist-user-list [data-distributor-rename="7"]').waitFor();
  const dialog = page.locator('#admin-dist-rename');
  const input = page.locator('#admin-dist-rename-name');
  const save = dialog.locator('[type="submit"]');
  const status = dialog.locator('[role="status"]');
  async function open() {
    await page.locator('.admin-dist-user-list [data-distributor-rename="7"]').click();
    await expect(input).toBeEnabled();
    await expect(input).toBeFocused();
  }
  async function saved(name) {
    await expect(dialog).toHaveCount(0);
    await expect(page.locator('.admin-dist-user-list strong')).toHaveText(name);
    await expect(page.locator('.admin-dist-toast').last()).toHaveText('分销商名称已更新并核对');
  }
  return { page, state, dialog, input, save, status, open, saved };
}

for (const width of [390, 1440]) test(`rename via list at ${width}px: trim, escaping, layout and focus`, async (t) => {
  const f = await fixture(t, width);
  await f.open();
  await expect(f.dialog.locator('[data-rename-current]')).toHaveText('当前名称：旧<&商户>');
  await expect(f.input).toHaveAttribute('maxlength', '16');
  await f.page.keyboard.press('Shift+Tab');
  await expect(f.save).toBeFocused();
  await f.page.keyboard.press('Tab');
  await expect(f.input).toBeFocused();
  assert.equal(await f.dialog.evaluate((el) => el.scrollWidth <= el.clientWidth && el.getBoundingClientRect().right <= innerWidth), true);
  assert.equal(await f.page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
  if (process.env.QA_ARTIFACT_DIR) await f.page.screenshot({ path: path.join(process.env.QA_ARTIFACT_DIR, `rename-${width}.png`) });
  await f.input.fill('  新<&店>😀  ');
  await f.input.press('Enter');
  await f.saved('新<&店>😀');
  assert.deepEqual(f.state.updates, [{ id: 7, distributor_name: '新<&店>😀', expected_distributor_revision: null }]);
  await expect(f.page.locator('.admin-dist-user-list [data-distributor-rename="7"]')).toBeFocused();
});

test('search entry, cancel, unchanged trimmed name and invalid inputs do not write', async (t) => {
  const f = await fixture(t);
  await f.page.locator('#admin-dist-user-email').fill(f.state.user.email);
  await f.page.locator('[data-admin-dist="search-user"]').click();
  await expect(f.page.locator('#admin-dist-user-name')).toHaveAttribute('readonly', '');
  await f.page.locator('.admin-dist-user-result [data-distributor-rename]').click();
  await expect(f.input).toBeEnabled();
  await f.input.fill('未保存草稿');
  await f.input.press('Escape');
  await expect(f.dialog).toHaveCount(0);
  await f.open();
  for (const [value, message] of [['   ', '请输入'], ['甲'.repeat(17), '16'], ['😀'.repeat(9), '16'], ['a@b.co', '邮箱'], ['商\u200b户', '控制字符']]) {
    await f.input.evaluate((el, text) => { el.value = text; }, value);
    await f.save.click();
    await expect(f.status).toContainText(message);
    assert.equal(f.state.updates.length, 0);
  }
  await f.input.fill(` ${f.state.user.distributor_name} `);
  await f.save.click();
  await expect(f.dialog).toHaveCount(0);
  assert.equal(f.state.updates.length, 0);
});

test('duplicate submits are blocked and Escape/cancel cannot close an in-flight write', async (t) => {
  const f = await fixture(t);
  await f.open();
  f.state.mode = 'pending';
  await f.input.fill('新名字');
  await f.dialog.locator('form').evaluate((form) => { form.requestSubmit(); form.requestSubmit(); });
  await expect.poll(() => f.state.updates.length).toBe(1);
  await expect(f.save).toBeDisabled();
  await expect(f.input).toBeDisabled();
  await expect(f.dialog.locator('[data-rename-cancel]')).toBeDisabled();
  await f.page.keyboard.press('Escape');
  await expect(f.dialog).toBeVisible();
  f.state.release();
  await f.saved('新名字');
  assert.equal(f.state.updates.length, 1);
});

test('server rejection preserves draft, has no false success, permits an explicit corrected retry', async (t) => {
  const f = await fixture(t);
  await f.open();
  f.state.mode = 'reject';
  await f.input.fill('被拒绝的名称');
  await f.save.click();
  await expect(f.status).toContainText('QA名称被拒绝');
  await expect(f.input).toHaveValue('被拒绝的名称');
  assert.equal(f.state.updates.length, 1);
  await expect(f.page.locator('.admin-dist-toast')).toHaveCount(0);
  f.state.mode = 'ok';
  await f.input.fill('合法新名称');
  await f.save.click();
  await f.saved('合法新名称');
  assert.equal(f.state.updates.length, 2);
});

test('lost write response is resolved by readback, never an automatic write retry', async (t) => {
  const f = await fixture(t);
  await f.open();
  f.state.mode = 'lost-response';
  await f.input.fill('服务器已经保存');
  await f.save.click();
  await f.saved('服务器已经保存');
  assert.equal(f.state.updates.length, 1);
});

test('failed readback locks saving until a read-only recheck confirms the committed name', async (t) => {
  const f = await fixture(t);
  await f.open();
  f.state.mode = 'read-failed';
  await f.input.fill('待核对名称');
  await f.save.click();
  await expect(f.status).toContainText('保存结果未确认');
  await expect(f.save).toBeDisabled();
  await expect(f.page.locator('.admin-dist-toast')).toHaveCount(0);
  f.state.readFailure = false;
  await f.dialog.locator('[data-rename-recheck]').click();
  await f.saved('待核对名称');
  assert.equal(f.state.updates.length, 1);
});

for (const mode of ['mismatch', 'role-changed']) test(`HTTP success with ${mode} is not rename success`, async (t) => {
  const f = await fixture(t);
  await f.open();
  f.state.mode = mode;
  await f.input.fill('请求的新名称');
  await f.save.click();
  await expect(f.status).toContainText(mode === 'mismatch' ? '保存结果未确认' : '已不是分销商');
  await expect(f.page.locator('.admin-dist-toast')).toHaveCount(0);
  assert.equal(f.state.updates.length, 1);
  await expect(f.save).toBeDisabled();
});

test('expired local authentication sends no write and cannot report success', async (t) => {
  const f = await fixture(t);
  await f.open();
  await f.page.evaluate(() => localStorage.removeItem('XBOARD_ACCESS_TOKEN'));
  await f.input.fill('不能保存');
  await f.save.click();
  await expect(f.status).toContainText('管理员登录已失效');
  await expect(f.save).toBeDisabled();
  assert.equal(f.state.updates.length, 0);
});

test('visible native editors cannot contaminate rename and target cached name stays updated', async (t) => {
  const f = await fixture(t);
  await f.page.evaluate(async () => {
    for (const [id, email] of [[7, 'qa-merchant@example.invalid'], [8, 'qa-other@example.invalid']]) {
      const dialog = document.createElement('section');
      dialog.id = `qa-native-${id}`;
      dialog.setAttribute('role', 'dialog');
      dialog.innerHTML = `<h2>编辑用户</h2><input value="${email}"><div class="space-y-2"><label>是否员工</label></div>`;
      document.body.appendChild(dialog);
      await window.fetch(`/api/v2/qa-admin/user/getUserInfoById?id=${id}`);
    }
  });
  await expect(f.page.locator('#qa-native-7 [data-distributor-name-value]')).toHaveText('旧<&商户>');
  await f.open();
  await f.input.fill('缓存同步新名');
  await f.save.click();
  await f.saved('缓存同步新名');
  assert.deepEqual(f.state.updates, [{ id: 7, distributor_name: '缓存同步新名', expected_distributor_revision: null }]);
  await expect(f.page.locator('#qa-native-7 [data-distributor-name-value]')).toHaveText('缓存同步新名');
  await f.page.locator('[data-admin-dist="close"]').click();
  await f.page.evaluate(() => window.fetch('/api/v2/qa-admin/user/update', {
    method: 'POST', body: JSON.stringify({ id: 7, remarks: '仅改备注' }),
  }));
  assert.deepEqual(f.state.updates[1], { id: 7, remarks: '仅改备注' });
});

test('35-second write timeout and unchanged readback keep saving locked', async (t) => {
  const f = await fixture(t);
  await f.page.clock.install();
  await f.open();
  f.state.mode = 'timeout';
  await f.input.fill('超时测试');
  await f.save.click();
  await expect.poll(() => f.state.updates.length).toBe(1);
  await f.page.clock.fastForward(35001);
  await expect(f.status).toContainText('保存结果未确认');
  await expect(f.save).toBeDisabled();
  await expect(f.input).toHaveValue('超时测试');
  assert.equal(f.state.updates.length, 1);
  f.state.release();
  await expect.poll(() => f.state.completed.length).toBe(1);
  await f.dialog.locator('[data-rename-recheck]').click();
  await f.saved('超时测试');
  assert.equal(f.state.updates.length, 1, 'recheck must not repeat the write');
});

test('a timed-out earlier command cannot overwrite a later successful rename after reopening', async (t) => {
  const f = await fixture(t);
  await f.page.clock.install();
  await f.open();
  f.state.mode = 'timeout';
  await f.input.fill('先发出的请求');
  await f.save.click();
  await expect.poll(() => f.state.updates.length).toBe(1);
  const releaseFirst = f.state.release;
  await f.page.clock.fastForward(35001);
  await expect(f.status).toContainText('保存结果未确认');
  await expect(f.save).toBeDisabled();
  await f.dialog.locator('[data-rename-cancel]').click();
  await expect(f.dialog).toHaveCount(0);
  f.state.mode = 'ok';
  await f.open();
  await f.input.fill('后发成功的名称');
  await f.save.click();
  await f.saved('后发成功的名称');
  const revision = f.state.user.distributor_revision;
  releaseFirst();
  await expect.poll(() => f.state.completed.length).toBe(2);
  assert.deepEqual(f.state.completed, [
    { name: '后发成功的名称', status: 200 }, { name: '先发出的请求', status: 409 },
  ]);
  assert.equal(f.state.user.distributor_name, '后发成功的名称');
  assert.equal(f.state.user.distributor_revision, revision);
  assert.equal(f.state.updates.length, 2);
});

test('version conflict preserves draft and only an explicit retry uses the refreshed revision', async (t) => {
  const f = await fixture(t);
  await f.open();
  f.state.user.distributor_name = '另一个管理员改名';
  f.state.user.distributor_revision = randomUUID();
  const revision = f.state.user.distributor_revision;
  await f.input.fill('我的名称草稿');
  await f.save.click();
  await expect(f.status).toContainText('分销商信息已变化');
  await expect(f.input).toHaveValue('我的名称草稿');
  await expect(f.save).toBeEnabled();
  await expect(f.page.locator('.admin-dist-toast')).toHaveCount(0);
  assert.equal(f.state.updates.length, 1);
  assert.equal(f.state.user.distributor_name, '另一个管理员改名');
  await f.save.click();
  await f.saved('我的名称草稿');
  assert.equal(f.state.updates.length, 2);
  assert.equal(f.state.updates[1].expected_distributor_revision, revision);
});

test('explicit conflict is not reported as this request succeeding even if another writer chose the same name', async (t) => {
  const f = await fixture(t);
  await f.open();
  f.state.user.distributor_name = '同一个名称';
  f.state.user.distributor_revision = randomUUID();
  await f.input.fill('同一个名称');
  await f.save.click();
  await expect(f.status).toContainText('分销商信息已变化');
  await expect(f.page.locator('.admin-dist-toast')).toHaveCount(0);
  await expect(f.dialog).toBeVisible();
  assert.equal(f.state.updates.length, 1);
});

for (const revision of [undefined, 'not-a-uuid', 3]) test(`invalid revision ${String(revision)} fails closed without legacy fallback`, async (t) => {
  const f = await fixture(t);
  if (revision === undefined) delete f.state.user.distributor_revision;
  else f.state.user.distributor_revision = revision;
  await f.page.locator('.admin-dist-user-list [data-distributor-rename="7"]').click();
  await expect(f.status).toContainText('缺少有效的分销商版本');
  await expect(f.save).toBeDisabled();
  assert.equal(f.state.updates.length, 0);
});

test('server error with unchanged revision cannot unlock an uncertain save', async (t) => {
  const f = await fixture(t);
  await f.open();
  f.state.mode = 'server-error';
  await f.input.fill('未知保存结果');
  await f.save.click();
  await expect(f.status).toContainText('保存结果未确认');
  await expect(f.save).toBeDisabled();
  await f.dialog.locator('[data-rename-recheck]').click();
  await expect(f.save).toBeDisabled();
  assert.equal(f.state.updates.length, 1);
});

for (const transport of ['fetch', 'xhr']) test(`late native ${transport} user-list response cannot restore the old name on an ordinary profile save`, async (t) => {
  const f = await fixture(t);
  await f.page.evaluate(async () => {
    const editor = document.createElement('section');
    editor.id = 'qa-native-late';
    editor.setAttribute('role', 'dialog');
    editor.innerHTML = '<h2>编辑用户</h2><input value="qa-merchant@example.invalid"><div class="space-y-2"><label>是否员工</label></div>';
    document.body.appendChild(editor);
    await (await window.fetch('/api/v2/qa-admin/user/fetch', { method: 'POST', body: '{}' })).json();
  });
  await expect(f.page.locator('#qa-native-late [data-distributor-name-value]')).toHaveText('旧<&商户>');
  f.state.delayUserList = true;
  await f.page.evaluate((transport) => {
    window.qaLateResponse = transport === 'fetch'
      ? window.fetch('/api/v2/qa-admin/user/fetch', { method: 'POST', body: '{}' }).then((response) => response.json())
      : new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        xhr.open('POST', '/api/v2/qa-admin/user/fetch');
        xhr.onload = () => resolve(JSON.parse(xhr.responseText));
        xhr.onerror = reject;
        xhr.send('{}');
      });
  }, transport);
  await expect.poll(() => typeof f.state.releaseUserList).toBe('function');
  await f.open();
  await f.input.fill('不能被旧响应覆盖');
  await f.save.click();
  await f.saved('不能被旧响应覆盖');
  f.state.releaseUserList();
  await f.page.evaluate(async () => {
    window.qaOldUser = (await window.qaLateResponse).data[0];
    await new Promise(requestAnimationFrame);
    await new Promise(requestAnimationFrame);
  });
  await f.page.locator('[data-admin-dist="close"]').click();
  await f.page.evaluate(() => window.fetch('/api/v2/qa-admin/user/update', {
    method: 'POST', body: JSON.stringify({ id: 7, remarks: '仅改备注', balance: window.qaOldUser.balance }),
  }));
  assert.deepEqual(f.state.updates[1], { id: 7, remarks: '仅改备注', balance: 123.45 });
});
