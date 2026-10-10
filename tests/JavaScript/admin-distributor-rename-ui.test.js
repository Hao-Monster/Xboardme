const assert = require('node:assert/strict');
const fs = require('node:fs');
const test = require('node:test');
const vm = require('node:vm');

function harness() {
  const requests = [];
  const root = { classList: { toggle() {} }, innerHTML: '' };
  const document = {
    readyState: 'loading', documentElement: {},
    addEventListener() {}, getElementById: () => root,
    querySelectorAll: (selector) => selector === '.xboard-distributor-injected input[type="checkbox"]' ? [{
      checked: false, offsetParent: {},
      closest: () => ({ dataset: { distributorName: '另一个用户' }, querySelector: () => null }),
    }] : [],
  };
  class XHR { open() {} send() {} }
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
  vm.runInNewContext(source.replace(/\}\)\(\);\s*$/, 'window.qa = { api, validateDistributorName, renderUsers, state, userCache, rememberUsers, syncRenamedDistributor }; })();'), sandbox);
  return { ...window.qa, root, requests };
}

test('internal minimal rename cannot inherit another native user editor role/name', async () => {
  const { api, requests } = harness();
  await api('/user/update', { method: 'POST', data: { id: 7, distributor_name: '新商户' } });
  assert.equal(requests.length, 1);
  assert.equal(requests[0].url, '/api/v2/qa-admin/user/update');
  assert.deepEqual(JSON.parse(requests[0].init.body), { id: 7, distributor_name: '新商户' });
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
