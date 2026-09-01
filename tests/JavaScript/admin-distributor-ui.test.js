const assert = require('node:assert/strict');
const fs = require('node:fs');
const test = require('node:test');
const vm = require('node:vm');

class ElementMock {
  constructor(tagName, ownerDocument) {
    this.tagName = String(tagName).toUpperCase();
    this.ownerDocument = ownerDocument;
    this.children = [];
    this.parentElement = null;
    this.listeners = new Map();
    this.dataset = {};
    this.textContent = '';
    this.innerHTML = '';
    this.className = '';
    this.classList = { add() {}, remove() {}, toggle() {} };
    this.attributes = new Map();
    this._id = '';
  }

  set id(value) {
    this._id = value;
    if (value) this.ownerDocument.elementsById.set(value, this);
  }

  get id() {
    return this._id;
  }

  appendChild(child) {
    child.parentElement = this;
    this.children.push(child);
    return child;
  }

  insertBefore(child, reference) {
    child.parentElement = this;
    const index = this.children.indexOf(reference);
    if (index === -1) this.children.push(child);
    else this.children.splice(index, 0, child);
    return child;
  }

  addEventListener(type, listener) {
    const listeners = this.listeners.get(type) || [];
    listeners.push(listener);
    this.listeners.set(type, listeners);
  }

  setAttribute(name, value) {
    this.attributes.set(name, String(value));
  }

  removeAttribute(name) {
    this.attributes.delete(name);
  }

  remove() {
    if (!this.parentElement) return;
    this.parentElement.children = this.parentElement.children.filter((child) => child !== this);
    if (this.id) this.ownerDocument.elementsById.delete(this.id);
    this.parentElement = null;
  }

  click() {
    this.clicked = true;
  }

  closest(selector) {
    if (selector === 'main') {
      let current = this;
      while (current) {
        if (current.tagName === 'MAIN') return current;
        current = current.parentElement;
      }
    }
    return null;
  }

  querySelector(selector) {
    if (selector === 'table') return this.find((element) => element.tagName === 'TABLE');
    return null;
  }

  find(predicate) {
    for (const child of this.children) {
      if (predicate(child)) return child;
      const nested = child.find(predicate);
      if (nested) return nested;
    }
    return null;
  }
}

class DocumentMock {
  constructor() {
    this.elementsById = new Map();
    this.listeners = new Map();
    this.readyState = 'complete';
    this.documentElement = new ElementMock('html', this);
    this.body = new ElementMock('body', this);
    this.documentElement.appendChild(this.body);
    this.injectedSwitches = [];

    this.main = new ElementMock('main', this);
    this.heading = new ElementMock('h1', this);
    this.heading.textContent = '订单管理';
    this.tableContainer = new ElementMock('div', this);
    this.table = new ElementMock('table', this);
    this.tableContainer.appendChild(this.table);
    this.main.appendChild(this.heading);
    this.main.appendChild(this.tableContainer);
    this.body.appendChild(this.main);
  }

  createElement(tagName) {
    return new ElementMock(tagName, this);
  }

  getElementById(id) {
    return this.elementsById.get(id) || null;
  }

  querySelectorAll(selector) {
    if (selector === 'h1,h2') return [this.heading];
    if (selector === '.xboard-distributor-injected input[type="checkbox"]') return this.injectedSwitches;
    return [];
  }

  addEventListener(type, listener) {
    const listeners = this.listeners.get(type) || [];
    listeners.push(listener);
    this.listeners.set(type, listeners);
  }
}

function response(payload) {
  return {
    ok: true,
    json: async () => payload,
    clone() {
      return { text: async () => JSON.stringify(payload) };
    },
  };
}

function errorResponse(status, message) {
  return {
    ok: false,
    status,
    json: async () => ({ status: 'fail', message }),
    clone() { return this; },
  };
}

function binaryResponse() {
  return {
    ok: true,
    headers: {
      get(name) {
        if (name.toLowerCase() === 'content-type') return 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
        if (name.toLowerCase() === 'content-disposition') return "attachment; filename*=UTF-8''%E5%88%86%E9%94%80%E8%AE%A2%E5%8D%95.xlsx";
        return null;
      },
    },
    blob: async () => new Blob(['xlsx']),
    json: async () => null,
    clone() { return this; },
  };
}

async function flush() {
  await new Promise((resolve) => setImmediate(resolve));
  await new Promise((resolve) => setImmediate(resolve));
}

test('admin order page exposes distributor filters, summary and settlement action', async () => {
  const document = new DocumentMock();
  const requests = [];
  const confirmMessages = [];
  let settled = false;
  let settleAttempts = 0;

  const fetchMock = async (url, init = {}) => {
    requests.push({ url, init });
    if (url.includes('/user/distributor/options')) {
      return response({ status: 'success', data: [{ id: 7, email: 'dealer@example.com', distributor_name: '华东渠道', banned: false }] });
    }
    if (url.includes('/order/settlement/preview')) {
      return response({ status: 'success', data: { count: settled ? 0 : 2, total_amount: settled ? 0 : 6000 } });
    }
    if (url.includes('/order/settlement/settle')) {
      settleAttempts += 1;
      if (settleAttempts === 1) {
        return errorResponse(409, '结算范围已变化，请刷新后重新确认');
      }
      settled = true;
      return response({ status: 'success', data: { count: 2, total_amount: 6000 } });
    }
    if (url.includes('/order/export')) return binaryResponse();
    if (url.includes('/order/fetch')) {
      return response({
        total: 1,
        data: [{
          id: 99,
          trade_no: 'DIST-ORDER-1',
          customer_name: '终端客户甲',
          bound_device_count: 2,
          bound_devices: ['vivo V2227A ntqwnji2mzky', 'Pixel 10 secondary-hwid'],
          used_traffic: 5 * 1073741824,
          created_at: 1785580800,
          distributor_email: 'dealer@example.com',
          distributor_name: '华东渠道',
          plan: { name: 'Test Plan' },
          total_amount: 3000,
          delivery_status: 0,
          settlement_status: settled ? 1 : 0,
          remark: '线下补款后结算',
        }],
      });
    }
    throw new Error(`Unexpected request: ${url}`);
  };

  class XMLHttpRequestMock {
    addEventListener() {}
  }
  XMLHttpRequestMock.prototype.open = function () {};
  XMLHttpRequestMock.prototype.send = function (body) { this.sentBody = body; };

  const storage = new Map([
    ['XBOARD_ACCESS_TOKEN', JSON.stringify({ value: 'Bearer test-token', expire: null })],
  ]);
  const window = {
    settings: { secure_path: 'admin-api' },
    fetch: fetchMock,
    confirm: (message) => { confirmMessages.push(message); return true; },
  };

  const sandbox = {
    console,
    document,
    window,
    fetch: (...args) => window.fetch(...args),
    localStorage: {
      getItem: (key) => storage.get(key) || null,
    },
    XMLHttpRequest: XMLHttpRequestMock,
    MutationObserver: class { observe() {} },
    FormData,
    URLSearchParams,
    navigator: { clipboard: { writeText: async () => {} } },
    URL: { createObjectURL: () => 'blob:test', revokeObjectURL: () => {} },
    Blob,
    setInterval: () => 1,
    setTimeout: (callback) => { callback(); return 1; },
    clearInterval: () => {},
  };
  sandbox.globalThis = sandbox;

  const source = fs.readFileSync('public/assets/admin-distributor.js', 'utf8');
  vm.runInNewContext(source, sandbox, { filename: 'admin-distributor.js' });
  await flush();

  const host = document.getElementById('xboard-native-distributor-orders');
  assert.ok(host, 'the distributor settlement section should mount in the existing order page');
  assert.match(host.innerHTML, /id="native-dist-distributor"/);
  assert.match(host.innerHTML, /id="native-dist-settlement"/);
  assert.match(host.innerHTML, /id="native-dist-settlement-month"[^>]*type="month"[^>]*lang="zh-CN"/);
  assert.doesNotMatch(host.innerHTML, /data-native-dist="settle"/);
  assert.match(host.innerHTML, /华东渠道/);
  assert.doesNotMatch(host.innerHTML, /dealer@example\.com/);
  assert.match(host.innerHTML, /DIST-ORDER-1/);
  assert.match(host.innerHTML, /用户名称/);
  assert.match(host.innerHTML, /终端客户甲/);
  assert.match(host.innerHTML, /已绑定设备/);
  assert.match(host.innerHTML, /vivo V2227A ntqwnji2mzky/);
  assert.match(host.innerHTML, /Pixel 10 secondary-hwid/);
  assert.match(host.innerHTML, /已用流量/);
  assert.match(host.innerHTML, /5 GB/);
  assert.match(host.innerHTML, /备注/);
  assert.match(host.innerHTML, /线下补款后结算/);
  assert.match(host.innerHTML, /data-edit-remark="99"/);
  assert.match(host.innerHTML, />🖊<\/button>/);
  assert.match(host.innerHTML, /id="native-dist-order-search"/);
  assert.match(host.innerHTML, /订单号\/用户名称\/订阅链接/);
  assert.match(host.innerHTML, /data-native-dist="search-orders"/);
  assert.match(host.innerHTML, /data-native-dist="clear-order-search"/);

  const entry = document.getElementById('admin-dist-entry');
  const panelRoot = document.getElementById('admin-dist-root');
  await entry.listeners.get('click')[0]();
  await flush();
  assert.match(panelRoot.innerHTML, /id="admin-dist-settlement-month"[^>]*type="month"[^>]*lang="zh-CN"/);
  assert.doesNotMatch(panelRoot.innerHTML, /data-admin-dist="settle"/);

  const change = host.listeners.get('change')[0];
  const click = host.listeners.get('click')[0];
  change({ target: { id: 'native-dist-distributor', value: '7' } });
  await flush();
  change({ target: { id: 'native-dist-settlement', value: '0' } });
  await flush();
  assert.doesNotMatch(host.innerHTML, /data-native-dist="settle"/);

  change({ target: { id: 'native-dist-settlement-month', value: '2026-08' } });
  await flush();

  assert.match(host.innerHTML, /未结算：<b>2<\/b> 个订单/);
  assert.match(host.innerHTML, /合计 <b>¥60\.00<\/b>/);
  assert.match(host.innerHTML, /结算 华东渠道 2026年8月未结算订单/);
  const filteredRequest = requests.filter(({ url }) => url.includes('/order/fetch')).at(-1);
  const filteredBody = JSON.parse(filteredRequest.init.body);
  assert.equal(filteredBody.distributor_user_id, '7');
  assert.equal(filteredBody.settlement_status, 0);
  assert.equal(filteredBody.settlement_month, '2026-08');
  const previewRequest = requests.filter(({ url }) => url.includes('/order/settlement/preview')).at(-1);
  assert.match(previewRequest.url, /distributor_user_id=7/);
  assert.match(previewRequest.url, /settlement_month=2026-08/);

  await entry.listeners.get('click')[0]();
  await flush();
  assert.match(panelRoot.innerHTML, /id="admin-dist-settlement-month"[^>]*value="2026-08"/);
  assert.match(panelRoot.innerHTML, /结算 华东渠道 2026年8月未结算订单/);

  const exportTarget = {
    disabled: false,
    closest(selector) {
      if (selector === '[data-native-dist]') return this;
      return null;
    },
    dataset: { nativeDist: 'export' },
  };
  await click({ target: exportTarget });
  const exportRequest = requests.find(({ url }) => url.includes('/order/export'));
  assert.ok(exportRequest, 'xlsx export endpoint should be called');
  assert.match(exportRequest.url, /distributor_user_id=7/);
  assert.match(exportRequest.url, /settlement_status=0/);
  assert.match(exportRequest.url, /settlement_month=2026-08/);
  assert.doesNotMatch(exportRequest.url, /current|pageSize/);

  const settleTarget = {
    closest(selector) {
      if (selector === '[data-native-dist]') return { dataset: { nativeDist: 'settle' } };
      return null;
    },
  };
  const previewCountBeforeConflict = requests.filter(({ url }) => url.includes('/order/settlement/preview')).length;
  await click({ target: settleTarget });
  await flush();

  assert.match(host.innerHTML, /未结算：<b>2<\/b> 个订单/);
  assert.ok(
    requests.filter(({ url }) => url.includes('/order/settlement/preview')).length > previewCountBeforeConflict,
    'a stale preview conflict should refresh the settlement summary'
  );

  await click({ target: settleTarget });
  await flush();

  const settleRequests = requests.filter(({ url }) => url.includes('/order/settlement/settle'));
  assert.equal(settleRequests.length, 2);
  settleRequests.forEach((settleRequest) => {
    assert.deepEqual(JSON.parse(settleRequest.init.body), {
      distributor_user_id: 7,
      settlement_month: '2026-08',
      expected_count: 2,
      expected_total_amount: 6000,
    });
  });
  assert.deepEqual(confirmMessages, [
    '确认结算 华东渠道 2026年8月的 2 个未结算订单，共 ¥60.00？',
    '确认结算 华东渠道 2026年8月的 2 个未结算订单，共 ¥60.00？',
  ]);
  assert.match(host.innerHTML, /未结算：<b>0<\/b> 个订单/);

  const previewCountBeforeSettledFilter = requests.filter(({ url }) => url.includes('/order/settlement/preview')).length;
  change({ target: { id: 'native-dist-settlement', value: '1' } });
  await flush();
  assert.doesNotMatch(host.innerHTML, /data-native-dist="settle"/);
  assert.equal(
    requests.filter(({ url }) => url.includes('/order/settlement/preview')).length,
    previewCountBeforeSettledFilter
  );

  const injectedName = { value: ' 华东渠道 ' };
  const injectedWrapper = { querySelector: () => injectedName };
  const injectedSwitch = {
    checked: true,
    offsetParent: {},
    closest: () => injectedWrapper,
  };
  document.injectedSwitches = [injectedSwitch];
  const xhr = new XMLHttpRequestMock();
  xhr.open('POST', '/api/v2/admin-api/user/update');
  xhr.send(JSON.stringify({ id: 7 }));
  assert.deepEqual(JSON.parse(xhr.sentBody), {
    id: 7,
    is_distributor: 1,
    distributor_name: '华东渠道',
  });
  const readonlyWrapper = {
    dataset: { distributorName: '已保存分销商' },
    querySelector: () => null,
  };
  document.injectedSwitches = [{
    checked: true,
    offsetParent: {},
    closest: () => readonlyWrapper,
  }];
  const readonlyXhr = new XMLHttpRequestMock();
  readonlyXhr.open('POST', '/api/v2/admin-api/user/update');
  readonlyXhr.send(JSON.stringify({ id: 8, remarks: '只修改备注' }));
  assert.deepEqual(JSON.parse(readonlyXhr.sentBody), {
    id: 8,
    remarks: '只修改备注',
    is_distributor: 1,
    distributor_name: '已保存分销商',
  });
  assert.match(source, /data-distributor-name/);
  assert.match(source, /data-distributor-name-readonly-row/);
  assert.match(source, /data-distributor-name-value/);
  assert.match(source, /maxlength="100"/);
  assert.match(source, /showReadonly = checkbox\.checked && savedName !== ''/);
  assert.match(source, /\/order\/remark\/update/);
  assert.match(source, /id="admin-dist-remark-title"/);
  assert.match(source, /textarea maxlength="500"/);
  assert.match(source, /data-remark-cancel/);
  assert.match(source, /data-remark-save/);
  assert.match(source, /input\.required = checkbox\.checked && !showReadonly/);
  assert.match(source, /<dt>用户名称<\/dt><dd>\$\{escapeHtml\(order\.customer_name \|\| '-'\)\}<\/dd>/);
  assert.match(source, /search: state\.orderSearch \|\| null/);
  assert.match(source, /params\.set\('search', state\.orderSearch\)/);
  assert.match(source, /event\.key !== 'Enter'/);
});

test('release smoke checks the monthly contract against the requested asset version', () => {
  const adminSmoke = fs.readFileSync('.github/scripts/smoke-admin-assets-remote.sh', 'utf8');
  const distributorSmoke = fs.readFileSync('.github/scripts/smoke-distributor-remote.sh', 'utf8');
  const releaseWorkflow = fs.readFileSync('.github/workflows/production-release.yml', 'utf8');

  for (const source of [adminSmoke, distributorSmoke]) {
    assert.match(source, /native-dist-settlement-month/);
    assert.match(source, /expected_total_amount/);
    assert.match(source, /cmp --silent/);
  }
  assert.match(adminSmoke, /EXPECTED_ADMIN_ASSET_VERSION:public\/assets\/admin-distributor\.js/);
  assert.match(distributorSmoke, /V2_EXPECTED_ASSET_VERSION:public\/assets\/admin-distributor\.js/);
  assert.match(releaseWorkflow, /EXPECTED_ADMIN_ASSET_VERSION: \$\{\{ inputs\.release_expected_sha \}\}/);
});
