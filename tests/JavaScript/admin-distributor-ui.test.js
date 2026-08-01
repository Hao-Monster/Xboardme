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

async function flush() {
  await new Promise((resolve) => setImmediate(resolve));
  await new Promise((resolve) => setImmediate(resolve));
}

test('admin order page exposes distributor filters, summary and settlement action', async () => {
  const document = new DocumentMock();
  const requests = [];
  let settled = false;

  const fetchMock = async (url, init = {}) => {
    requests.push({ url, init });
    if (url.includes('/user/distributor/options')) {
      return response({ status: 'success', data: [{ id: 7, email: 'dealer@example.com', banned: false }] });
    }
    if (url.includes('/order/settlement/preview')) {
      return response({ status: 'success', data: { count: settled ? 0 : 2, total_amount: settled ? 0 : 6000 } });
    }
    if (url.includes('/order/settlement/settle')) {
      settled = true;
      return response({ status: 'success', data: { count: 2, total_amount: 6000 } });
    }
    if (url.includes('/order/fetch')) {
      return response({
        total: 1,
        data: [{
          id: 99,
          trade_no: 'DIST-ORDER-1',
          created_at: 1785580800,
          distributor_email: 'dealer@example.com',
          plan: { name: 'Test Plan' },
          total_amount: 3000,
          delivery_status: 0,
          settlement_status: settled ? 1 : 0,
        }],
      });
    }
    throw new Error(`Unexpected request: ${url}`);
  };

  class XMLHttpRequestMock {
    addEventListener() {}
  }
  XMLHttpRequestMock.prototype.open = function () {};
  XMLHttpRequestMock.prototype.send = function () {};

  const storage = new Map([
    ['XBOARD_ACCESS_TOKEN', JSON.stringify({ value: 'Bearer test-token', expire: null })],
  ]);
  const window = {
    settings: { secure_path: 'admin-api' },
    fetch: fetchMock,
    confirm: () => true,
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
  assert.match(host.innerHTML, /dealer@example\.com/);
  assert.match(host.innerHTML, /DIST-ORDER-1/);

  const change = host.listeners.get('change')[0];
  change({ target: { id: 'native-dist-distributor', value: '7' } });
  await flush();

  assert.match(host.innerHTML, /未结算：<b>2<\/b> 个订单/);
  assert.match(host.innerHTML, /合计 <b>¥60\.00<\/b>/);
  const filteredRequest = requests.filter(({ url }) => url.includes('/order/fetch')).at(-1);
  assert.equal(JSON.parse(filteredRequest.init.body).distributor_user_id, '7');

  const click = host.listeners.get('click')[0];
  const settleTarget = {
    closest(selector) {
      if (selector === '[data-native-dist]') return { dataset: { nativeDist: 'settle' } };
      return null;
    },
  };
  await click({ target: settleTarget });
  await flush();

  const settleRequest = requests.find(({ url }) => url.includes('/order/settlement/settle'));
  assert.ok(settleRequest, 'settlement endpoint should be called');
  assert.equal(JSON.parse(settleRequest.init.body).distributor_user_id, 7);
  assert.match(host.innerHTML, /未结算：<b>0<\/b> 个订单/);
});
