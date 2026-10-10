const assert = require('node:assert/strict');
const fs = require('node:fs');
const test = require('node:test');
const vm = require('node:vm');

function harness(injected = [], dialogs = []) {
  const requests = [];
  const root = { classList: { toggle() {} }, innerHTML: '' };
  const document = {
    readyState: 'loading', documentElement: {},
    addEventListener() {}, getElementById: () => root,
    querySelectorAll: (selector) => selector === '.xboard-distributor-injected input[type="checkbox"]' ? injected.length ? injected : [{
      checked: false, offsetParent: {},
      closest: () => ({ dataset: { distributorName: '另一个用户' }, querySelector: () => null }),
    }] : selector === '[role="dialog"], [data-radix-dialog-content], .n-modal' ? dialogs : [],
  };
  class XHR { open() {} send(body) { requests.push({ body }); } addEventListener() {} }
  const window = { settings: { secure_path: 'qa-admin' }, fetch: async (url, init) => {
    requests.push({ url, init });
    return { ok: true, json: async () => ({ data: true }) };
  } };
  const sandbox = {
    document, window, XMLHttpRequest: XHR, MutationObserver: class { observe() {} },
    fetch: (...args) => window.fetch(...args), FormData, URLSearchParams,
    localStorage: { getItem: () => JSON.stringify({ value: 'qa-token' }) },
    setTimeout() {}, setInterval() {},
  };
  const source = fs.readFileSync('public/assets/admin-distributor.js', 'utf8');
  vm.runInNewContext(source.replace(/\}\)\(\);\s*$/, 'window.qa = { api, validateDistributorName, renderUsers, state, userCache, rememberUsers, syncRenamedDistributor, syncInjectedDistributorSwitches }; })();'), sandbox);
  return { ...window.qa, root, requests, window, XHR };
}

function editor(id, initial, checked = initial) {
  const field = {
    dataset: { distributorMode: 'edit', distributorUserId: String(id), distributorInitialRole: initial ? '1' : '0', distributorName: initial ? '旧名称' : '' },
    querySelector: () => ({ value: '新分销商' }),
  };
  return { checked, offsetParent: {}, closest: () => field, field };
}

for (const transport of ['fetch', 'xhr']) for (const format of ['json', 'form', 'params']) {
  test(`native ${transport}/${format} sends only explicit identity changes for the matching target`, async () => {
    const other = editor(8, false);
    const target = editor(7, true);
    const { window, XHR, requests } = harness([other, target]);
    async function send() {
      const body = format === 'json' ? JSON.stringify({ id: 7, remarks: '只改备注' })
        : format === 'params' ? new URLSearchParams({ id: '7', remarks: '只改备注' }) : new FormData();
      if (format === 'form') { body.set('id', '7'); body.set('remarks', '只改备注'); }
      if (transport === 'fetch') await window.fetch('/api/v2/qa-admin/user/update', { method: 'POST', body });
      else { const xhr = new XHR(); xhr.open('POST', '/api/v2/qa-admin/user/update'); xhr.send(body); }
      const sent = requests.at(-1).init?.body || requests.at(-1).body;
      return typeof sent === 'string' ? JSON.parse(sent) : Object.fromEntries(sent);
    }
    const ordinary = { id: format === 'json' ? 7 : '7', remarks: '只改备注' };
    assert.deepEqual(await send(), ordinary, 'unchanged role must not resend cached identity');
    target.checked = false;
    assert.deepEqual(await send(), { ...ordinary, is_distributor: format === 'json' ? 0 : '0' });
    target.checked = true;
    assert.deepEqual(await send(), ordinary, 'toggling back cancels role intent');
    target.field.dataset.distributorInitialRole = '0';
    target.field.dataset.distributorName = '';
    assert.deepEqual(await send(), { ...ordinary, is_distributor: format === 'json' ? 1 : '1', distributor_name: '新分销商' });
  });
}

test('internal minimal rename cannot inherit another native user editor role/name', async () => {
  const { api, requests } = harness();
  await api('/user/distributor/rename', { method: 'POST', data: { id: 7, distributor_name: '新商户', expected_distributor_revision: null } });
  assert.equal(requests.length, 1);
  assert.equal(requests[0].url, '/api/v2/qa-admin/user/distributor/rename');
  assert.deepEqual(JSON.parse(requests[0].init.body), { id: 7, distributor_name: '新商户', expected_distributor_revision: null });
});

test('existing distributors have explicit rename actions and escaped identities in list/search', () => {
  const { state, renderUsers, root } = harness();
  state.open = true;
  const user = { id: 7, email: 'qa@example.invalid', distributor_name: '<店>&"', is_distributor: true };
  state.distributors = [user];
  renderUsers(user);
  assert.equal((root.innerHTML.match(/data-distributor-rename="7"/g) || []).length, 2);
  assert.match(root.innerHTML, /&lt;店&gt;&amp;&quot;/);
  assert.doesNotMatch(root.innerHTML, /<店>/);
  assert.match(root.innerHTML, /id="admin-dist-user-name"[^>]*readonly/);
});

test('rename uses existing trim, UTF-16, control character and email validation', () => {
  const { validateDistributorName } = harness();
  assert.equal(validateDistributorName('  商户 A  '), '商户 A');
  assert.equal(validateDistributorName('😀'.repeat(8)), '😀'.repeat(8));
  assert.equal(validateDistributorName('甲'.repeat(16)), '甲'.repeat(16));
  for (const invalid of ['', '   ', '甲'.repeat(17), '😀'.repeat(9), '商\u200b户', 'a@b.co']) {
    assert.throws(() => validateDistributorName(invalid));
  }
});

test('verified rename survives an older user-list response without replacing money units or pinning later changes', () => {
  const { rememberUsers, syncRenamedDistributor, userCache } = harness();
  const listUser = { id: 7, email: 'qa@example.invalid', distributor_name: '旧名称', is_distributor: true, balance: 123.45, commission_balance: 67.89 };
  rememberUsers({ data: [listUser] }, 0);
  syncRenamedDistributor({ ...listUser, distributor_name: '核对后新名', balance: 12345, commission_balance: 6789 });
  assert.equal(userCache.get(listUser.email).balance, 123.45);
  assert.equal(userCache.get(listUser.email).commission_balance, 67.89);
  rememberUsers({ data: [{ ...listUser, distributor_name: '旧名称' }] }, 0);
  assert.equal(userCache.get(listUser.email).distributor_name, '核对后新名');
  rememberUsers({ data: [{ ...listUser, distributor_name: '后续另一管理员改名' }] });
  assert.equal(userCache.get(listUser.email).distributor_name, '后续另一管理员改名');
  rememberUsers({ data: [{ ...listUser, distributor_name: '旧名称' }] }, 0);
  assert.equal(userCache.get(listUser.email).distributor_name, '后续另一管理员改名');
  rememberUsers({ data: [{ ...listUser, distributor_name: null, is_distributor: false }] });
  assert.equal(userCache.get(listUser.email).is_distributor, false);
});

test('authoritative reads after a saved email change replace the old ID cache alias', () => {
  const target = editor(7, true);
  const { rememberUsers, userCache, syncInjectedDistributorSwitches } = harness([target], [{ querySelector: () => target }]);
  rememberUsers({ data: [{ id: 7, email: 'qa-old@example.invalid', is_distributor: true, distributor_name: '旧名称' }] });
  rememberUsers({ data: [{ id: 7, email: 'qa-new@example.invalid', is_distributor: false, distributor_name: null }] });
  syncInjectedDistributorSwitches();
  assert.equal(target.checked, false, 'the latest role must be reflected, not the first cached ID match');
  assert.equal(target.field.dataset.distributorName, '');
  assert.deepEqual([...userCache.keys()], ['qa-new@example.invalid']);
});
