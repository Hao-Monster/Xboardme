const assert = require('node:assert/strict');
const fs = require('node:fs');
const test = require('node:test');
const vm = require('node:vm');

const source = fs.readFileSync('theme/Xboard/assets/auth-session.js', 'utf8');
const distributorSource = fs.readFileSync('theme/Xboard/assets/distributor.js', 'utf8');
const umiSource = fs.readFileSync('theme/Xboard/assets/umi.js', 'utf8');
const dashboard = fs.readFileSync('theme/Xboard/dashboard.blade.php', 'utf8');

class MemoryStorage {
  constructor(entries = []) {
    this.values = new Map(entries);
  }

  getItem(key) {
    return this.values.has(String(key)) ? this.values.get(String(key)) : null;
  }

  setItem(key, value) {
    this.values.set(String(key), String(value));
  }

  removeItem(key) {
    this.values.delete(String(key));
  }
}

class FakeElement {
  constructor(tagName = 'div', ownerDocument = null) {
    this.tagName = String(tagName || 'div').toUpperCase();
    this.ownerDocument = ownerDocument;
    this.id = '';
    this.className = '';
    this.style = {};
    this.dataset = {};
    this.attributes = new Map();
    this.children = [];
    this.parentNode = null;
    this.disabled = false;
    this.hidden = false;
    this._textContent = '';
    this._innerHTML = '';
    this.listeners = new Map();
  }

  get isConnected() {
    let cur = this;
    while (cur) {
      if (cur === this.ownerDocument?.body || cur === this.ownerDocument?.documentElement) return true;
      cur = cur.parentNode;
    }
    return false;
  }

  get classList() {
    const self = this;
    const classes = () => String(self.className || '').split(/\s+/).filter(Boolean);
    return {
      add(...items) {
        const set = new Set(classes());
        items.forEach((item) => set.add(item));
        self.className = Array.from(set).join(' ');
      },
      remove(...items) {
        const set = new Set(classes());
        items.forEach((item) => set.delete(item));
        self.className = Array.from(set).join(' ');
      },
      contains(item) {
        return classes().includes(item);
      },
      toggle(item, force) {
        const has = classes().includes(item);
        const shouldHave = force === undefined ? !has : Boolean(force);
        if (shouldHave) this.add(item);
        else this.remove(item);
        return shouldHave;
      },
    };
  }

  get textContent() {
    return this._textContent;
  }

  set textContent(value) {
    this._textContent = String(value ?? '');
    this._innerHTML = this._textContent;
    this.children = [];
  }

  get innerHTML() {
    return this._innerHTML;
  }

  set innerHTML(value) {
    this._innerHTML = String(value ?? '');
    this._textContent = this._innerHTML.replace(/<[^>]+>/g, '');
    this.children = this._innerHTML ? [new FakeElement('div', this.ownerDocument)] : [];
  }

  appendChild(child) {
    if (!child) return child;
    child.parentNode = this;
    this.children.push(child);
    return child;
  }

  removeChild(child) {
    const idx = this.children.indexOf(child);
    if (idx >= 0) {
      this.children.splice(idx, 1);
      child.parentNode = null;
    }
    return child;
  }

  remove() {
    if (this.parentNode) {
      this.parentNode.removeChild(this);
    }
  }

  setAttribute(name, value) {
    const strVal = String(value);
    this.attributes.set(name, strVal);
    if (name === 'id') this.id = strVal;
    if (name === 'class') this.className = strVal;
  }

  getAttribute(name) {
    return this.attributes.has(name) ? this.attributes.get(name) : null;
  }

  removeAttribute(name) {
    this.attributes.delete(name);
  }

  addEventListener(type, listener) {
    if (!this.listeners.has(type)) this.listeners.set(type, []);
    this.listeners.get(type).push(listener);
  }

  dispatchEvent(event) {
    const list = this.listeners.get(event.type) || [];
    list.forEach((fn) => fn(event));
  }

  focus() {}

  querySelector(selector) {
    if (selector === '.dist-loading, .dist-error') {
      if (this._innerHTML.includes('class="dist-loading"') || this._innerHTML.includes('class="dist-error"')) {
        return new FakeElement('div', this.ownerDocument);
      }
      return null;
    }
    if (selector === '.dist-loading') {
      return this._innerHTML.includes('class="dist-loading"') ? new FakeElement('div', this.ownerDocument) : null;
    }
    if (selector === '.dist-error') {
      return this._innerHTML.includes('class="dist-error"') ? new FakeElement('div', this.ownerDocument) : null;
    }
    if (selector === '.dist-purchase-recovery') {
      const recoverySection = /<section\b[^>]*class="[^"]*\bdist-purchase-recovery\b[^"]*"[^>]*>[\s\S]*?<\/section>/;
      if (!recoverySection.test(this._innerHTML)) return null;
      return { remove: () => { this.innerHTML = this._innerHTML.replace(recoverySection, ''); } };
    }
    if (selector === '.dist-account') {
      if (!this._innerHTML.includes('class="dist-account"')) return null;
      const self = this;
      return {
        get textContent() {
          const m = self._innerHTML.match(/<span class="dist-account">([^<]*)<\/span>/);
          return m ? m[1] : '';
        },
        set textContent(val) {
          self._innerHTML = self._innerHTML.replace(
            /<span class="dist-account">[^<]*<\/span>/,
            `<span class="dist-account">${val}</span>`
          );
        },
      };
    }
    return null;
  }

  querySelectorAll() {
    return [];
  }
}

class FakeDocument {
  constructor() {
    this.documentElement = new FakeElement('html', this);
    this.body = new FakeElement('body', this);
    this.hidden = false;
    this.title = '';
    this.listeners = new Map();
    this.inputs = new Map();
    const app = new FakeElement('div', this);
    app.id = 'app';
    this.body.appendChild(app);
  }

  createElement(tag) {
    const el = new FakeElement(tag, this);
    if (String(tag).toLowerCase() === 'canvas') {
      el.width = 0;
      el.height = 0;
      el.getContext = () => ({
        font: '',
        fillStyle: '',
        textAlign: '',
        textBaseline: '',
        measureText: (txt) => ({ width: String(txt || '').length * 12 }),
        fillRect: () => {},
        fillText: () => {},
        drawImage: () => {},
      });
      el.toDataURL = () => 'data:image/png;base64,FAKEPNG';
      el.toBlob = (cb) => cb({ type: 'image/png' });
    }
    return el;
  }

  getElementById(id) {
    if (this.inputs.has(id)) return this.inputs.get(id);
    const findIn = (el) => {
      if (el.id === id) return el;
      for (const child of el.children) {
        const found = findIn(child);
        if (found) return found;
      }
      return null;
    };
    return findIn(this.body);
  }

  querySelector(selector) {
    if (selector.startsWith('#')) {
      const [idPart, rest] = selector.slice(1).split(/\s+/, 2);
      const root = this.getElementById(idPart);
      if (!root) return null;
      return rest ? root.querySelector(rest) : root;
    }
    return this.getElementById('distributor-app')?.querySelector(selector) || null;
  }

  querySelectorAll() {
    return [];
  }

  addEventListener(type, listener) {
    if (!this.listeners.has(type)) this.listeners.set(type, []);
    this.listeners.get(type).push(listener);
  }

  dispatchEvent(event) {
    const list = this.listeners.get(event.type) || [];
    list.forEach((fn) => fn(event));
  }
}

function createUmiGuardHarness({ initialToken = null, getConfigImpl, getUserInfoRawImpl }) {
  const rnSlice = umiSource.slice(umiSource.indexOf('function RN(e){'), umiSource.indexOf('const EN='));
  const bnSlice = umiSource.slice(umiSource.indexOf('const BN=$s("user",'), umiSource.indexOf('function DN(e,t){'));
  const vnSlice = umiSource.slice(umiSource.indexOf('function VN(e){'), umiSource.indexOf('const qN=function(e){'));

  const entries = initialToken
    ? [['VUE_NAIVE_ACCESS_TOKEN', JSON.stringify({ value: initialToken, time: 1, expire: null })]]
    : [];
  const storage = new MemoryStorage(entries);
  const doc = new FakeDocument();
  const logoutRequests = [];
  const redirects = [];
  const messages = [];
  const loadingBarCalls = [];
  const replaceCalls = [];
  const addedRoutes = [];
  const hasRoutes = new Set();
  const windowListeners = new Map();

  const windowObj = {
    localStorage: storage,
    document: doc,
    routerBase: '/',
    location: { origin: 'https://xboard.example', href: 'https://xboard.example/#/dashboard', hash: '#/dashboard' },
    CustomEvent: class CustomEvent {
      constructor(type, init = {}) { this.type = type; this.detail = init.detail; }
    },
    addEventListener(type, listener) {
      if (!windowListeners.has(type)) windowListeners.set(type, []);
      windowListeners.get(type).push(listener);
    },
    dispatchEvent(event) {
      (windowListeners.get(event.type) || []).forEach((listener) => listener(event));
    },
    fetch: (url, options) => {
      logoutRequests.push({ url, options });
      return Promise.resolve();
    },
    $message: {
      error(msg) {
        messages.push(msg);
      },
    },
    $loadingBar: {
      start() { loadingBarCalls.push('start'); },
      finish() { loadingBarCalls.push('finish'); },
      error() { loadingBarCalls.push('error'); },
    },
  };

  function runGenerator( thiz, args, genFn ) {
    return new Promise((resolve, reject) => {
      const gen = genFn.apply(thiz, args || []);
      function step(verb, arg) {
        let info;
        try {
          info = gen[verb](arg);
        } catch (err) {
          reject(err);
          return;
        }
        if (info.done) {
          resolve(info.value);
        } else {
          Promise.resolve(info.value).then(
            (val) => step('next', val),
            (err) => step('throw', err)
          );
        }
      }
      step('next');
    });
  }

  let userStoreInstance = null;
  const permissionStoreInstance = {
    generateRoutes() {
      return [{ name: 'Dashboard', path: '/dashboard' }];
    },
  };

  const sandbox = {
    window: windowObj,
    document: doc,
    localStorage: storage,
    Promise,
    Object,
    Set,
    Map,
    Number,
    String,
    Boolean,
    JSON,
    setTimeout: (fn) => { fn(); return 1; },
    clearTimeout: () => {},
    v: runGenerator,
    d: (target, sourceObj) => Object.assign({}, target, sourceObj),
    p: (target, sourceObj) => Object.assign({}, target, sourceObj),
    UN: ['/404', '/login', '/register'],
    bs: { name: 'NotFound', path: '/:pathMatch(.*)*' },
    Cs: 'XBoard',
    dd() {
      try {
        const parsed = JSON.parse(storage.getItem('VUE_NAIVE_ACCESS_TOKEN') || 'null');
        return { value: parsed?.value || null };
      } catch (_) {
        return { value: null };
      }
    },
    pd() {
      storage.removeItem('VUE_NAIVE_ACCESS_TOKEN');
    },
    hd() {
      redirects.push('/login');
    },
    _N() {
      return {
        getConfig: (options) => getConfigImpl(options),
      };
    },
    $N() {
      return permissionStoreInstance;
    },
    EN: {
      get(path, options) {
        if (path === '/user/info') {
          return getUserInfoRawImpl(options).then(
            (res) => res,
            (err) => {
              err.config ||= { method: 'get', url: path, ...(options || {}) };
              return sandbox.RN(err);
            }
          );
        }
        return Promise.resolve({ data: {} });
      },
    },
    $s(id, def) {
      if (id === 'user') {
        return function () {
          if (!userStoreInstance) {
            const stateObj = def.state();
            userStoreInstance = stateObj;
            Object.entries(def.getters).forEach(([k, fn]) => {
              Object.defineProperty(userStoreInstance, k, {
                get() { return fn.call(userStoreInstance); },
              });
            });
            Object.entries(def.actions).forEach(([k, fn]) => {
              userStoreInstance[k] = fn.bind(userStoreInstance);
            });
          }
          return userStoreInstance;
        };
      }
      return () => permissionStoreInstance;
    },
  };

  vm.runInNewContext(source, sandbox, { filename: 'auth-session.js' });
  vm.runInNewContext(`${rnSlice}\n${bnSlice}\n${vnSlice}\nthis.RN = RN; this.BN = BN; this.VN = VN;`, sandbox, { filename: 'umi-slice.js' });

  const beforeEachHooks = [];
  const router = {
    currentRoute: { value: { path: '/', fullPath: '/', matched: [] } },
    beforeEach(fn) { beforeEachHooks.push(fn); },
    afterEach() {},
    onError() {},
    hasRoute(name) { return hasRoutes.has(name); },
    addRoute(route) {
      if (route?.name) hasRoutes.add(route.name);
      addedRoutes.push(route);
      return () => {
        if (route?.name) hasRoutes.delete(route.name);
        const index = addedRoutes.indexOf(route);
        if (index >= 0) addedRoutes.splice(index, 1);
      };
    },
    replace(target) {
      replaceCalls.push(target);
      return Promise.resolve(target);
    },
  };

  sandbox.VN(router);
  const authGuard = beforeEachHooks[1];

  return {
    storage,
    doc,
    windowObj,
    logoutRequests,
    redirects,
    messages,
    loadingBarCalls,
    replaceCalls,
    addedRoutes,
    router,
    getUserStore: () => sandbox.BN(),
    runGuard: async (to, from = { path: '/' }) => {
      const nextCalls = [];
      await authGuard(to, from, (arg) => nextCalls.push(arg));
      return nextCalls;
    },
  };
}

function createDistributorHarness({ initialToken = null, fetchImpl, initialHash = '#/plan', online = true }) {
  const entries = initialToken
    ? [['VUE_NAIVE_ACCESS_TOKEN', JSON.stringify({ value: initialToken, time: 1, expire: null })]]
    : [];
  const storage = new MemoryStorage(entries);
  const doc = new FakeDocument();
  const timers = new Map();
  const intervals = new Map();
  let nextTimerId = 1;
  let nowMs = 1700000000000;
  const windowListeners = new Map();

  let currentHash = initialHash;
  let currentHref = `https://xboard.example/${currentHash}`;
  const navigatorObj = { onLine: online };
  const windowObj = {
    localStorage: storage,
    document: doc,
    routerBase: '/',
    settings: { title: 'XBoard' },
    navigator: navigatorObj,
    location: {
      origin: 'https://xboard.example',
      get href() {
        return currentHref;
      },
      set href(value) {
        const next = new URL(value, currentHref);
        currentHref = next.href;
        this.hash = next.hash;
      },
      get hash() {
        return currentHash;
      },
      set hash(nextHash) {
        const prev = currentHash;
        currentHash = String(nextHash || '');
        currentHref = `https://xboard.example/${currentHash}`;
        if (currentHash !== prev) {
          Promise.resolve().then(() => windowObj.dispatchEvent({ type: 'hashchange' }));
        }
      },
    },
    history: {
      state: {},
      pushState(_state, _title, hash) {
        currentHash = String(hash || '');
      },
    },
    CustomEvent: class CustomEvent {
      constructor(type, init = {}) {
        this.type = type;
        this.detail = init.detail;
      }
    },
    addEventListener(type, fn) {
      if (!windowListeners.has(type)) windowListeners.set(type, []);
      windowListeners.get(type).push(fn);
    },
    dispatchEvent(event) {
      const list = windowListeners.get(event.type) || [];
      list.forEach((fn) => fn(event));
    },
    requestAnimationFrame(fn) { fn(); return 1; },
    queueMicrotask(fn) { Promise.resolve().then(fn); },
    matchMedia() { return { matches: false }; },
    scrollTo() {},
    setTimeout(fn, delay) {
      const id = nextTimerId++;
      timers.set(id, { id, fn, delay: Number(delay || 0), runAt: nowMs + Number(delay || 0) });
      return id;
    },
    clearTimeout(id) {
      timers.delete(id);
    },
    setInterval(fn, delay) {
      const id = nextTimerId++;
      intervals.set(id, { id, fn, delay: Number(delay || 0) });
      return id;
    },
    clearInterval(id) {
      intervals.delete(id);
    },
    fetch(url, options) {
      return fetchImpl(url, options);
    },
  };

  const pendingImages = [];
  class FakeImage {
    set src(value) {
      this._src = value;
      if (createDistributorHarness.manualImageLoad) {
        pendingImages.push(this);
      } else if (typeof this.onload === 'function') {
        Promise.resolve().then(() => this.onload());
      }
    }
  }

  const sandbox = {
    window: windowObj,
    document: doc,
    localStorage: storage,
    navigator: navigatorObj,
    Image: FakeImage,
    URL: Object.assign(URL, {
      createObjectURL: () => 'blob:fake',
      revokeObjectURL: () => {},
    }),
    URLSearchParams,
    AbortController,
    Intl,
    Date: Object.assign(function (...args) {
      return args.length ? new Date(...args) : new Date(nowMs);
    }, {
      now: () => nowMs,
      parse: Date.parse,
      UTC: Date.UTC,
    }),
    Promise,
    Object,
    Array,
    Set,
    Map,
    Number,
    String,
    Boolean,
    Math,
    RegExp,
    JSON,
    Error,
    TypeError,
    setTimeout: windowObj.setTimeout,
    clearTimeout: windowObj.clearTimeout,
    setInterval: windowObj.setInterval,
    clearInterval: windowObj.clearInterval,
    fetch: windowObj.fetch,
  };

  vm.runInNewContext(source, sandbox, { filename: 'auth-session.js' });
  vm.runInNewContext(distributorSource, sandbox, { filename: 'distributor.js' });

  return {
    storage,
    doc,
    windowObj,
    timers,
    intervals,
    pendingImages,
    setHidden(hidden) {
      doc.hidden = Boolean(hidden);
      doc.dispatchEvent({ type: 'visibilitychange' });
    },
    setOnline(isOnline) {
      navigatorObj.onLine = Boolean(isOnline);
      windowObj.dispatchEvent({ type: isOnline ? 'online' : 'offline' });
    },
    click({ buy, action, modalAction, subscriptionQr, renew, nav } = {}) {
      const target = new FakeElement('button', doc);
      if (buy !== undefined) target.dataset.buy = String(buy);
      if (action !== undefined) target.dataset.action = String(action);
      if (modalAction !== undefined) target.dataset.modalAction = String(modalAction);
      if (subscriptionQr !== undefined) target.dataset.subscriptionQr = String(subscriptionQr);
      if (renew !== undefined) target.dataset.renew = String(renew);
      if (nav !== undefined) target.dataset.nav = String(nav);
      target.closest = (selector) => {
        if (selector === '[data-buy]' && buy !== undefined) return target;
        if (action !== undefined && (selector === '[data-action]' || selector.includes(`data-action="${action}"`))) return target;
        if (modalAction !== undefined && (selector === '[data-modal-action]' || selector.includes(`data-modal-action="${modalAction}"`))) return target;
        if (selector === '[data-subscription-qr]' && subscriptionQr !== undefined) return target;
        if (selector === '[data-renew]' && renew !== undefined) return target;
        if (selector === '[data-nav]' && nav !== undefined) return target;
        return null;
      };
      doc.dispatchEvent({ type: 'click', target });
      return target;
    },
    advanceTime(ms) {
      nowMs += ms;
      for (const [id, timer] of Array.from(timers.entries())) {
        if (timer.runAt <= nowMs) {
          timers.delete(id);
          timer.fn();
        }
      }
    },
    async advanceTimeAsync(ms) {
      const target = nowMs + ms;
      for (;;) {
        const due = Array.from(timers.values())
          .filter((timer) => timer.runAt <= target)
          .sort((left, right) => left.runAt - right.runAt || left.id - right.id)[0];
        if (!due) break;
        nowMs = Math.max(nowMs, due.runAt);
        timers.delete(due.id);
        due.fn();
        for (let round = 0; round < 40; round += 1) await Promise.resolve();
      }
      nowMs = target;
      for (let round = 0; round < 40; round += 1) await Promise.resolve();
    },
    runDetectorTick() {
      for (const interval of Array.from(intervals.values())) {
        if (interval.delay === 300) interval.fn();
      }
    },
    flushMicrotasks: async (rounds = 24) => {
      for (let i = 0; i < rounds; i++) {
        await Promise.resolve();
      }
    },
  };
}

test('auth compatibility loads synchronously before the compiled application', () => {
  const sessionIndex = dashboard.indexOf('auth-session.js');
  const applicationIndex = dashboard.indexOf('assets/umi.js');

  assert.ok(sessionIndex >= 0);
  assert.ok(applicationIndex > sessionIndex);
  assert.doesNotMatch(
    dashboard.slice(dashboard.lastIndexOf('<script', sessionIndex), dashboard.indexOf('</script>', sessionIndex)),
    /\b(?:async|defer)\b/
  );
});

test('existing and newly stored browser tokens no longer receive a local expiry', () => {
  const storage = new MemoryStorage([
    ['VUE_NAIVE_ACCESS_TOKEN', JSON.stringify({ value: 'Bearer existing', time: 1, expire: 2 })],
  ]);
  const sandbox = {
    window: {
      localStorage: storage,
      location: { origin: 'https://xboard.example' },
      fetch: () => Promise.resolve(),
    },
  };

  vm.runInNewContext(source, sandbox, { filename: 'auth-session.js' });

  assert.equal(JSON.parse(storage.getItem('VUE_NAIVE_ACCESS_TOKEN')).expire, null);
  storage.setItem(
    'VUE_NAIVE_ACCESS_TOKEN',
    JSON.stringify({ value: 'Bearer new', time: 3, expire: Date.now() + 21600000 })
  );
  assert.equal(JSON.parse(storage.getItem('VUE_NAIVE_ACCESS_TOKEN')).expire, null);
});

test('removing the browser token revokes that token through the server logout endpoint', () => {
  const requests = [];
  const storage = new MemoryStorage([
    ['VUE_NAIVE_ACCESS_TOKEN', JSON.stringify({ value: 'Bearer current', time: 1, expire: null })],
  ]);
  const sandbox = {
    window: {
      localStorage: storage,
      routerBase: '/',
      location: { origin: 'https://xboard.example' },
      fetch: (url, options) => {
        requests.push({ url, options });
        return Promise.resolve();
      },
    },
  };

  vm.runInNewContext(source, sandbox, { filename: 'auth-session.js' });
  storage.removeItem('VUE_NAIVE_ACCESS_TOKEN');

  assert.equal(storage.getItem('VUE_NAIVE_ACCESS_TOKEN'), null);
  assert.equal(requests.length, 1);
  assert.equal(requests[0].url, 'https://xboard.example/api/v1/user/logout');
  assert.equal(requests[0].options.method, 'POST');
  assert.equal(requests[0].options.headers.Authorization, 'Bearer current');
  assert.equal(requests[0].options.keepalive, true);
});

test('positive auth failure classification distinguishes expired session from permission or transient errors', () => {
  const storage = new MemoryStorage();
  const sandbox = {
    window: {
      localStorage: storage,
      location: { origin: 'https://xboard.example' },
      fetch: () => Promise.resolve(),
    },
  };
  vm.runInNewContext(source, sandbox, { filename: 'auth-session.js' });
  const auth = sandbox.window.__xboardAuthSession;

  assert.equal(auth.isConfirmedAuthFailure(401, ''), true);
  assert.equal(auth.isConfirmedAuthFailure(403, '未登录或登陆已过期'), true);
  assert.equal(auth.isConfirmedAuthFailure(403, '分销商账号无权访问该功能'), false);
  assert.equal(auth.isConfirmedAuthFailure(403, '没有权限'), false);
  assert.equal(auth.isConfirmedAuthFailure(403, '<html>403 Forbidden</html>'), false);
  assert.equal(auth.isConfirmedAuthFailure(0, 'timeout'), false);
  assert.equal(auth.isConfirmedAuthFailure(502, 'Bad Gateway'), false);
});

test('main guard preserves token on transient failure, mounts retry for target fullPath, and recovers on retry', async () => {
  let shouldFail = true;
  const harness = createUmiGuardHarness({
    initialToken: 'Bearer token-a',
    getConfigImpl: () => Promise.resolve({ data: {} }),
    getUserInfoRawImpl: () => {
      if (shouldFail) {
        return Promise.reject({
          response: { status: 502, data: { message: '网关超时' } },
        });
      }
      return Promise.resolve({
        data: { uuid: 'uuid-a', email: 'user@example.com', is_distributor: false },
      });
    },
  });

  const nextCalls = await harness.runGuard({ path: '/order', fullPath: '/order?page=2', query: { page: '2' } });
  assert.deepEqual(nextCalls, [false]);
  assert.equal(harness.redirects.length, 0, 'transient failure must not redirect to /login');
  assert.ok(harness.storage.getItem('VUE_NAIVE_ACCESS_TOKEN'), 'transient failure must preserve token');
  assert.ok(harness.loadingBarCalls.includes('error'));
  assert.ok(harness.doc.getElementById('xboard-auth-recovery'), 'recovery prompt should be mounted');
  assert.equal(harness.windowObj.__xboardAuthSession.getRecoveryState()?.targetPath, '/order?page=2');

  // Now recover and click retry
  shouldFail = false;
  await harness.windowObj.__xboardAuthSession.retryRecovery();
  assert.equal(harness.doc.getElementById('xboard-auth-recovery'), null, 'recovery prompt should hide on success');
  assert.deepEqual(harness.replaceCalls, ['/order?page=2']);
  assert.equal(harness.getUserStore().userUUID, 'uuid-a');
});

test('main guard discards stale session failures and stale post-userInfo config completions after account switch', async () => {
  let rejectUserInfoA;
  let resolveConfigA;
  let mode = 'slow-fail';

  const harness = createUmiGuardHarness({
    initialToken: 'Bearer token-a',
    getConfigImpl: () => {
      if (mode === 'slow-config') {
        return new Promise((resolve) => { resolveConfigA = resolve; });
      }
      return Promise.resolve({ data: {} });
    },
    getUserInfoRawImpl: () => {
      if (mode === 'slow-fail') {
        return new Promise((_, reject) => { rejectUserInfoA = reject; });
      }
      return Promise.resolve({
        data: { uuid: 'uuid-a', email: 'a@example.com', is_distributor: false },
      });
    },
  });

  // 1. Account A request in flight -> switch to Account B -> A fails with 403 expired
  const guardPromise1 = harness.runGuard({ path: '/dashboard', fullPath: '/dashboard', query: {} });
  harness.storage.setItem(
    'VUE_NAIVE_ACCESS_TOKEN',
    JSON.stringify({ value: 'Bearer token-b', time: 2, expire: null })
  );
  rejectUserInfoA({
    response: { status: 403, data: { message: '未登录或登陆已过期' } },
  });
  const nextCalls1 = await guardPromise1;
  assert.deepEqual(nextCalls1, [false]);
  assert.equal(JSON.parse(harness.storage.getItem('VUE_NAIVE_ACCESS_TOKEN')).value, 'Bearer token-b');
  assert.equal(harness.redirects.length, 0, 'A failure must not redirect B to /login');
  assert.equal(harness.logoutRequests.length, 0, 'A failure must not revoke B token');

  // 2. Account B getUserInfo succeeds, but config is still pending -> switch to Account C -> B config resolves
  mode = 'slow-config';
  const guardPromise2 = harness.runGuard({ path: '/dashboard', fullPath: '/dashboard', query: {} });
  await Promise.resolve();
  await Promise.resolve();
  harness.storage.setItem(
    'VUE_NAIVE_ACCESS_TOKEN',
    JSON.stringify({ value: 'Bearer token-c', time: 3, expire: null })
  );
  resolveConfigA({ data: {} });
  const nextCalls2 = await guardPromise2;
  assert.deepEqual(nextCalls2, [false], 'VN must re-verify sessionSnapshot after all awaits');
  assert.equal(harness.addedRoutes.length, 0, 'stale guard must not register routes after account switch');
  assert.equal(harness.getUserStore().userUUID, undefined, 'session switch must clear cached userInfo');
});

test('main guard preserves login on unknown 403 and supports immediate active logout after transient failure', async () => {
  const harness = createUmiGuardHarness({
    initialToken: 'Bearer token-active',
    getConfigImpl: () => Promise.resolve({ data: {} }),
    getUserInfoRawImpl: () => Promise.reject({
      response: { status: 403, data: { message: '分销商账号无权访问该功能' } },
    }),
  });

  const nextCalls = await harness.runGuard({ path: '/dashboard', fullPath: '/dashboard', query: {} });
  assert.deepEqual(nextCalls, [false]);
  assert.equal(harness.redirects.length, 0, 'non-auth 403 must not redirect to /login');
  assert.equal(JSON.parse(harness.storage.getItem('VUE_NAIVE_ACCESS_TOKEN')).value, 'Bearer token-active');

  // Immediate active logout right after failure
  await harness.getUserStore().logout();
  assert.equal(harness.storage.getItem('VUE_NAIVE_ACCESS_TOKEN'), null);
  assert.equal(harness.logoutRequests.length, 1);
  assert.equal(harness.logoutRequests[0].options.headers.Authorization, 'Bearer token-active');
  assert.deepEqual(harness.redirects, ['/login']);
});

test('distributor detection is single-flight per session and stops polling once normal user is confirmed', async () => {
  let infoCalls = 0;
  let resolveInfo;
  const harness = createDistributorHarness({
    initialToken: 'Bearer normal-token',
    fetchImpl: (url) => {
      if (url.includes('/api/v1/user/info')) {
        infoCalls += 1;
        return new Promise((resolve) => {
          resolveInfo = resolve;
        });
      }
      return Promise.resolve({ ok: true, status: 200, text: () => Promise.resolve('{"data":[]}') });
    },
  });

  assert.equal(infoCalls, 1);
  // Trigger multiple 300ms detector ticks while /user/info is still in flight
  harness.runDetectorTick();
  harness.runDetectorTick();
  harness.runDetectorTick();
  assert.equal(infoCalls, 1, 'must not overlap /user/info requests in the same session');

  resolveInfo({
    ok: true,
    status: 200,
    text: () => Promise.resolve(JSON.stringify({ data: { id: 10, email: 'normal@example.com', is_distributor: false } })),
  });
  await harness.flushMicrotasks();

  // Subsequent 300ms ticks must not re-poll for the same confirmed normal-user token
  harness.runDetectorTick();
  harness.runDetectorTick();
  assert.equal(infoCalls, 1);
  assert.equal(harness.doc.getElementById('distributor-app'), null);
});

test('distributor discards stale account responses and tears down view when token is removed or switched', async () => {
  const pendingInfo = [];
  const logoutCalls = [];
  const harness = createDistributorHarness({
    initialToken: 'Bearer dist-a',
    fetchImpl: (url, options) => {
      if (url.includes('/api/v1/user/logout')) {
        logoutCalls.push({ url, options });
        return Promise.resolve({ ok: true, status: 200, text: () => Promise.resolve('{}') });
      }
      if (url.includes('/api/v1/user/info')) {
        return new Promise((resolve) => {
          pendingInfo.push({ auth: options.headers.Authorization, resolve });
        });
      }
      if (url.includes('/api/v1/user/plan/fetch')) {
        return Promise.resolve({
          ok: true,
          status: 200,
          text: () => Promise.resolve(JSON.stringify({
            data: [{ id: 1, name: 'Plan A', month_price: 1000, transfer_enable: 100 }],
          })),
        });
      }
      if (url.includes('/api/v1/user/notice/fetch')) {
        return Promise.resolve({
          ok: true,
          status: 200,
          text: () => Promise.resolve(JSON.stringify({ data: [], total: 0 })),
        });
      }
      return Promise.resolve({ ok: true, status: 200, text: () => Promise.resolve('{"data":[]}') });
    },
  });

  assert.equal(pendingInfo.length, 1);
  assert.equal(pendingInfo[0].auth, 'Bearer dist-a');

  // Switch to normal user B before dist-a's response arrives
  harness.storage.setItem(
    'VUE_NAIVE_ACCESS_TOKEN',
    JSON.stringify({ value: 'Bearer user-b', time: 2, expire: null })
  );
  await harness.flushMicrotasks();
  assert.equal(pendingInfo.length, 2);
  assert.equal(pendingInfo[1].auth, 'Bearer user-b');

  // Old dist-a resolves with is_distributor: true AND 403 expired check
  pendingInfo[0].resolve({
    ok: true,
    status: 200,
    text: () => Promise.resolve(JSON.stringify({
      data: { id: 1, email: 'dist-a@example.com', is_distributor: true },
    })),
  });
  await harness.flushMicrotasks();
  assert.equal(harness.doc.getElementById('distributor-app'), null, 'stale dist-a response must not activate distributor view');

  // Now switch to distributor C and activate it
  harness.storage.setItem(
    'VUE_NAIVE_ACCESS_TOKEN',
    JSON.stringify({ value: 'Bearer dist-c', time: 3, expire: null })
  );
  await harness.flushMicrotasks();
  const distCReq = pendingInfo[pendingInfo.length - 1];
  assert.equal(distCReq.auth, 'Bearer dist-c');

  // Meanwhile user-b's stale request fails with 403 expired
  pendingInfo[1].resolve({
    ok: false,
    status: 403,
    text: () => Promise.resolve(JSON.stringify({ message: '未登录或登陆已过期' })),
  });
  await harness.flushMicrotasks();
  assert.equal(
    JSON.parse(harness.storage.getItem('VUE_NAIVE_ACCESS_TOKEN')).value,
    'Bearer dist-c',
    'stale user-b 403 must not clear dist-c token'
  );
  assert.equal(logoutCalls.length, 0);

  // Resolve dist-c as distributor
  distCReq.resolve({
    ok: true,
    status: 200,
    text: () => Promise.resolve(JSON.stringify({
      data: { id: 3, email: 'dist-c@example.com', distributor_name: '分销商C', is_distributor: true },
    })),
  });
  await harness.flushMicrotasks(12);
  assert.ok(harness.doc.getElementById('distributor-app'), 'dist-c should activate distributor view');
  assert.equal(harness.doc.getElementById('app').style.display, 'none');
  assert.equal(harness.doc.documentElement.classList.contains('distributor-mode'), true);

  // Removing token tears down distributor view completely
  harness.storage.removeItem('VUE_NAIVE_ACCESS_TOKEN');
  await harness.flushMicrotasks();
  assert.equal(harness.doc.getElementById('distributor-app'), null);
  assert.equal(harness.doc.getElementById('app').style.display, '');
  assert.equal(harness.doc.documentElement.classList.contains('distributor-mode'), false);
  assert.equal(logoutCalls.length, 1);
  assert.equal(logoutCalls[0].options.headers.Authorization, 'Bearer dist-c');
});

test('read-only timeout covers hanging response body stream while mutating endpoints have no client auto-timeout', async () => {
  let orderSaveSignal = 'not-called';
  let orderSaveResolved = false;
  let planCallCount = 0;

  const harness = createDistributorHarness({
    initialToken: 'Bearer dist-timeout',
    fetchImpl: (url, options) => {
      if (url.includes('/api/v1/user/info')) {
        return Promise.resolve({
          ok: true,
          status: 200,
          text: () => Promise.resolve(JSON.stringify({
            data: { id: 9, email: 'dist@example.com', is_distributor: true },
          })),
        });
      }
      if (url.includes('/api/v1/user/plan/fetch')) {
        planCallCount += 1;
        if (planCallCount === 1) {
          // HTTP 200 header arrives immediately, but body stream hangs forever
          return Promise.resolve({
            ok: true,
            status: 200,
            text: () => new Promise(() => {}),
          });
        }
        return Promise.resolve({
          ok: true,
          status: 200,
          text: () => Promise.resolve(JSON.stringify({
            data: [{ id: 1, name: 'Plan Write', month_price: 1000, transfer_enable: 100 }],
          })),
        });
      }
      if (url.includes('/api/v1/user/notice/fetch')) {
        return Promise.resolve({
          ok: true,
          status: 200,
          text: () => Promise.resolve(JSON.stringify({ data: [], total: 0 })),
        });
      }
      if (url.includes('/api/v1/user/order/save')) {
        orderSaveSignal = options.signal || null;
        return new Promise((resolve) => {
          // Will only resolve after 15s (past 12s read timeout)
          harness.windowObj.setTimeout(() => {
            orderSaveResolved = true;
            resolve({
              ok: true,
              status: 200,
              text: () => Promise.resolve(JSON.stringify({ data: 'TRADE123' })),
            });
          }, 15000);
        });
      }
      if (url.includes('/api/v1/user/distributor/delivery')) {
        return Promise.resolve({
          ok: true,
          status: 200,
          text: () => Promise.resolve(JSON.stringify({
            data: { trade_no: 'TRADE123', delivery_status: 1, config_issued_at: 1700000000 },
          })),
        });
      }
      return Promise.resolve({ ok: true, status: 200, text: () => Promise.resolve('{"data":[]}') });
    },
  });

  await harness.flushMicrotasks();
  // Before 12s, distributor-app is in loading state
  assert.match(harness.doc.getElementById('distributor-app').innerHTML, /class="dist-loading"/);

  // Advance past 12s read timeout
  harness.advanceTime(12001);
  await harness.flushMicrotasks();

  // Hanging response.text() must time out and render error state instead of empty catalog
  assert.match(harness.doc.getElementById('distributor-app').innerHTML, /class="dist-error"/);
  assert.match(harness.doc.getElementById('distributor-app').innerHTML, /请求超时，请点击重试/);

  // Click retry to load Plan Write, then click buy to actually execute POST /user/order/save
  harness.click({ action: 'retry' });
  await harness.flushMicrotasks();
  assert.match(harness.doc.getElementById('distributor-app').innerHTML, /Plan Write/);

  harness.click({ buy: 1 });
  await harness.flushMicrotasks();
  assert.equal(orderSaveSignal, null, 'mutating /user/order/save must not attach an auto-timeout AbortSignal');

  // Advance 13s (past 12s read timeout): /user/order/save must still be waiting, not timed out
  harness.advanceTime(13000);
  await harness.flushMicrotasks();
  assert.equal(orderSaveResolved, false);

  // Advance remaining 2s (to 15s): /user/order/save resolves and opens delivery modal
  harness.advanceTime(2500);
  await harness.flushMicrotasks();
  assert.equal(orderSaveResolved, true);
  assert.match(harness.doc.getElementById('dist-modal-root')?.innerHTML || '', /TRADE123|订阅已经领取/);
});

test('stale purchase, renewal, and QR composition from a previous session cannot unlock or contaminate the active account', async () => {
  const pendingOrderSaves = [];
  const pendingRenewals = [];
  createDistributorHarness.manualImageLoad = true;

  try {
    const harness = createDistributorHarness({
      initialToken: 'Bearer dist-a',
      fetchImpl: (url, options) => {
        if (url.includes('/api/v1/user/info')) {
          const auth = options.headers.Authorization;
          const id = auth === 'Bearer dist-a' ? 1 : 2;
          return Promise.resolve({
            ok: true,
            status: 200,
            text: () => Promise.resolve(JSON.stringify({
              data: { id, email: `dist-${id}@example.com`, is_distributor: true },
            })),
          });
        }
        if (url.includes('/api/v1/user/plan/fetch')) {
          return Promise.resolve({
            ok: true,
            status: 200,
            text: () => Promise.resolve(JSON.stringify({
              data: [{ id: 1, name: 'Plan Shared', month_price: 1000, transfer_enable: 100 }],
            })),
          });
        }
        if (url.includes('/api/v1/user/notice/fetch')) {
          return Promise.resolve({
            ok: true,
            status: 200,
            text: () => Promise.resolve(JSON.stringify({ data: [], total: 0 })),
          });
        }
        if (url.includes('/api/v1/user/order/save')) {
          return new Promise((resolve) => {
            pendingOrderSaves.push({ auth: options.headers.Authorization, resolve });
          });
        }
        if (url.includes('/api/v1/user/order/fetch')) {
          return Promise.resolve({
            ok: true,
            status: 200,
            text: () => Promise.resolve(JSON.stringify({
              data: [{
                id: 101,
                trade_no: 'ORD-A-101',
                type: 1,
                period: 'month_price',
                total_amount: 1000,
                settlement_status: 0,
                is_subscription_origin: true,
                can_view_subscription_qr: true,
                can_renew: true,
                plan: { id: 1, name: 'Plan Shared', month_price: 1000 },
              }],
              current_page: 1,
              per_page: 20,
              total: 1,
              last_page: 1,
            })),
          });
        }
        if (url.includes('/api/v1/user/distributor/subscription-qr')) {
          return Promise.resolve({
            ok: true,
            status: 200,
            text: () => Promise.resolve(JSON.stringify({
              data: {
                trade_no: 'ORD-A-101',
                subscription_name: 'A专属二维码',
                customer_name: '客户A',
                qr_code: 'data:image/png;base64,AAA',
                hwid_enabled: false,
                hwid_devices: [],
              },
            })),
          });
        }
        if (url.includes('/api/v1/user/order/renew')) {
          return new Promise((resolve) => {
            pendingRenewals.push({ auth: options.headers.Authorization, resolve });
          });
        }
        if (url.includes('/api/v1/user/distributor/delivery')) {
          return Promise.resolve({
            ok: true,
            status: 200,
            text: () => Promise.resolve(JSON.stringify({
              data: { trade_no: 'TRADE-B', delivery_status: 1, config_issued_at: 1700000000 },
            })),
          });
        }
        return Promise.resolve({ ok: true, status: 200, text: () => Promise.resolve('{"data":[]}') });
      },
    });

    await harness.flushMicrotasks();
    // 1. Account A starts a purchase (in flight)
    harness.click({ buy: 1 });
    await harness.flushMicrotasks();
    assert.equal(pendingOrderSaves.length, 1);
    assert.equal(pendingOrderSaves[0].auth, 'Bearer dist-a');

    // Account A also navigates to orders, starts QR composition and renewal before switching to B
    harness.click({ nav: '/order' });
    await harness.flushMicrotasks();
    harness.click({ subscriptionQr: 'ORD-A-101' });
    await harness.flushMicrotasks();
    assert.equal(harness.pendingImages.length, 1, 'Account A QR image load is pending');

    harness.click({ renew: 'ORD-A-101' });
    await harness.flushMicrotasks();
    harness.click({ modalAction: 'confirm-renewal' });
    await harness.flushMicrotasks();
    assert.equal(pendingRenewals.length, 1);

    // 2. Switch to Account B on /plan and B starts its own purchase
    harness.windowObj.location.hash = '#/plan';
    harness.storage.setItem(
      'VUE_NAIVE_ACCESS_TOKEN',
      JSON.stringify({ value: 'Bearer dist-b', time: 2, expire: null })
    );
    await harness.flushMicrotasks();
    const buyButtonB = harness.click({ buy: 1 });
    await harness.flushMicrotasks();
    assert.equal(pendingOrderSaves.length, 2);
    assert.equal(pendingOrderSaves[1].auth, 'Bearer dist-b');
    assert.equal(buyButtonB.disabled, true);

    // 3. Account A's delayed purchase, QR image load, and renewal all complete now
    pendingOrderSaves[0].resolve({
      ok: true,
      status: 200,
      text: () => Promise.resolve(JSON.stringify({ data: 'TRADE-A' })),
    });
    harness.pendingImages[0].onload();
    pendingRenewals[0].resolve({
      ok: true,
      status: 200,
      text: () => Promise.resolve(JSON.stringify({
        data: { trade_no: 'RENEW-A', total_amount: 1000, expired_at_after: 1800000000 },
      })),
    });
    await harness.flushMicrotasks();

    // Account B's purchase lock must still be held: clicking buy again for B must NOT issue a 3rd /user/order/save!
    harness.click({ buy: 1 });
    await harness.flushMicrotasks();
    assert.equal(pendingOrderSaves.length, 2, 'Account A finally must not unlock Account B purchasingPlanId');
    assert.equal(harness.doc.getElementById('dist-modal-root')?.innerHTML || '', '', 'Account A QR/delivery/renewal must not open in Account B');
  } finally {
    createDistributorHarness.manualImageLoad = false;
  }
});

test('invalid HTTP 200 user info response does not permanently stop distributor detection or tear down an active distributor on resume', async () => {
  let infoCalls = 0;
  let returnHtml = true;

  const harness = createDistributorHarness({
    initialToken: 'Bearer dist-html',
    fetchImpl: (url) => {
      if (url.includes('/api/v1/user/info')) {
        infoCalls += 1;
        if (returnHtml) {
          return Promise.resolve({
            ok: true,
            status: 200,
            text: () => Promise.resolve('<!doctype html><html><body>Gateway HTML</body></html>'),
          });
        }
        return Promise.resolve({
          ok: true,
          status: 200,
          text: () => Promise.resolve(JSON.stringify({
            data: { id: 7, email: 'dist7@example.com', distributor_name: '分销商7', is_distributor: true },
          })),
        });
      }
      if (url.includes('/api/v1/user/plan/fetch')) {
        return Promise.resolve({
          ok: true,
          status: 200,
          text: () => Promise.resolve(JSON.stringify({
            data: [{ id: 1, name: 'Plan 7', month_price: 1000, transfer_enable: 100 }],
          })),
        });
      }
      if (url.includes('/api/v1/user/notice/fetch')) {
        return Promise.resolve({
          ok: true,
          status: 200,
          text: () => Promise.resolve(JSON.stringify({ data: [], total: 0 })),
        });
      }
      return Promise.resolve({ ok: true, status: 200, text: () => Promise.resolve('{"data":[]}') });
    },
  });

  await harness.flushMicrotasks();
  assert.equal(infoCalls, 1);
  assert.equal(harness.doc.getElementById('distributor-app'), null, 'must not activate on HTML response');

  // Recover after backoff: token must NOT be permanently marked as decided ordinary user
  returnHtml = false;
  harness.advanceTime(700);
  harness.runDetectorTick();
  await harness.flushMicrotasks();
  assert.equal(infoCalls, 2);
  assert.ok(harness.doc.getElementById('distributor-app'), 'should retry and activate once valid JSON arrives');
  assert.match(harness.doc.getElementById('distributor-app').innerHTML, /Plan 7/);

  // Now simulate 60s background resume where /user/info transiently returns HTTP 200 HTML again
  returnHtml = true;
  harness.doc.hidden = true;
  harness.doc.dispatchEvent({ type: 'visibilitychange' });
  harness.advanceTime(65000);
  harness.doc.hidden = false;
  harness.doc.dispatchEvent({ type: 'visibilitychange' });
  harness.advanceTime(60);
  await harness.flushMicrotasks();
  assert.equal(infoCalls, 3);
  assert.ok(harness.doc.getElementById('distributor-app'), 'transient HTTP 200 HTML on resume must not tear down active distributor view');
});

test('switching accounts resets order pagination, search keyword, and advanced filters to defaults', async () => {
  const orderFetchUrls = [];

  const harness = createDistributorHarness({
    initialToken: 'Bearer dist-filter-a',
    fetchImpl: (url, options) => {
      if (url.includes('/api/v1/user/info')) {
        const auth = options.headers.Authorization;
        const id = auth === 'Bearer dist-filter-a' ? 1 : 2;
        return Promise.resolve({
          ok: true,
          status: 200,
          text: () => Promise.resolve(JSON.stringify({
            data: { id, email: `filter-${id}@example.com`, is_distributor: true },
          })),
        });
      }
      if (url.includes('/api/v1/user/order/fetch')) {
        orderFetchUrls.push({ auth: options.headers.Authorization, url });
        return Promise.resolve({
          ok: true,
          status: 200,
          text: () => Promise.resolve(JSON.stringify({
            data: [],
            current_page: 1,
            per_page: 20,
            total: 2000,
            last_page: 100,
          })),
        });
      }
      return Promise.resolve({ ok: true, status: 200, text: () => Promise.resolve('{"data":[]}') });
    },
  });

  await harness.flushMicrotasks();
  harness.click({ nav: '/order' });
  await harness.flushMicrotasks();

  // Account A searches for a private customer and jumps to page 99
  harness.doc.inputs.set('dist-order-search', { value: 'T1 private customer' });
  harness.click({ action: 'search-orders' });
  await harness.flushMicrotasks();

  harness.doc.inputs.set('dist-order-page-input', { value: '99', focus() {} });
  harness.click({ action: 'jump-order-page' });
  await harness.flushMicrotasks();

  const lastUrlA = orderFetchUrls[orderFetchUrls.length - 1].url;
  assert.match(lastUrlA, /page=99/);
  assert.match(lastUrlA, /search=T1\+private\+customer/);

  // Switch to Account B while still on #/order
  harness.storage.setItem(
    'VUE_NAIVE_ACCESS_TOKEN',
    JSON.stringify({ value: 'Bearer dist-filter-b', time: 2, expire: null })
  );
  await harness.flushMicrotasks();

  const bOrders = orderFetchUrls.filter((item) => item.auth === 'Bearer dist-filter-b');
  assert.equal(bOrders.length, 1);
  assert.match(bOrders[0].url, /page=1&per_page=20/);
  assert.doesNotMatch(bOrders[0].url, /search=/);
  assert.doesNotMatch(bOrders[0].url, /page=99/);
});

test('coalesced pageshow and visibilitychange recover stuck inactive or loading views and revalidate once after 60s', async () => {
  let infoCalls = 0;
  let planCalls = 0;
  let failInitialInfo = true;
  let hangInitialPlan = true;

  const harness = createDistributorHarness({
    initialToken: 'Bearer dist-resume',
    fetchImpl: (url) => {
      if (url.includes('/api/v1/user/info')) {
        infoCalls += 1;
        if (failInitialInfo) {
          return Promise.resolve({
            ok: false,
            status: 502,
            text: () => Promise.resolve(JSON.stringify({ message: 'Bad Gateway' })),
          });
        }
        return Promise.resolve({
          ok: true,
          status: 200,
          text: () => Promise.resolve(JSON.stringify({
            data: { id: 5, email: 'resume@example.com', distributor_name: '恢复测试', is_distributor: true },
          })),
        });
      }
      if (url.includes('/api/v1/user/plan/fetch')) {
        planCalls += 1;
        if (hangInitialPlan) {
          return new Promise(() => {});
        }
        return Promise.resolve({
          ok: true,
          status: 200,
          text: () => Promise.resolve(JSON.stringify({
            data: [{ id: 1, name: 'Plan Resume', month_price: 1000, transfer_enable: 100 }],
          })),
        });
      }
      if (url.includes('/api/v1/user/notice/fetch')) {
        return Promise.resolve({
          ok: true,
          status: 200,
          text: () => Promise.resolve(JSON.stringify({ data: [], total: 0 })),
        });
      }
      return Promise.resolve({ ok: true, status: 200, text: () => Promise.resolve('{"data":[]}') });
    },
  });

  await harness.flushMicrotasks(8);
  assert.equal(infoCalls, 1);
  assert.equal(harness.doc.getElementById('distributor-app'), null, 'inactive after initial transient failure');

  // 1. Tab goes hidden and comes back with both visibilitychange and pageshow (persisted: true)
  failInitialInfo = false;
  harness.doc.hidden = true;
  harness.doc.dispatchEvent({ type: 'visibilitychange' });
  harness.advanceTime(65000);
  harness.doc.hidden = false;
  harness.doc.dispatchEvent({ type: 'visibilitychange' });
  harness.windowObj.dispatchEvent({ type: 'pageshow', persisted: true });

  // Advance 50ms coalescing timer
  harness.advanceTime(60);
  await harness.flushMicrotasks(12);

  // Coalesced resume should trigger detectDistributor once and activate distributor view (now stuck in loading because hangInitialPlan = true)
  assert.equal(infoCalls, 2);
  assert.equal(planCalls, 1);
  assert.match(harness.doc.getElementById('distributor-app').innerHTML, /class="dist-loading"/);

  // 2. Tab hides and resumes again while stuck in .dist-loading
  hangInitialPlan = false;
  harness.doc.hidden = true;
  harness.doc.dispatchEvent({ type: 'visibilitychange' });
  harness.advanceTime(65000);
  harness.doc.hidden = false;
  harness.doc.dispatchEvent({ type: 'visibilitychange' });
  harness.windowObj.dispatchEvent({ type: 'pageshow', persisted: false });

  harness.advanceTime(60);
  await harness.flushMicrotasks(12);

  // Stuck loading view should abort previous route request, re-render plans, and run 1 coalesced session check
  assert.equal(planCalls, 2);
  assert.equal(infoCalls, 3);
  assert.match(harness.doc.getElementById('distributor-app').innerHTML, /Plan Resume/);
});

function networkResponse(payload, status = 200) {
  return {
    ok: status >= 200 && status < 300,
    status,
    text: () => Promise.resolve(JSON.stringify(payload)),
  };
}

function healthyDistributorResponse(url, options) {
  if (url.includes('/user/info')) {
    return networkResponse({ data: {
      id: 501,
      uuid: 'mobile-network-review',
      email: 'mobile-review@example.invalid',
      is_distributor: true,
    } });
  }
  if (url.includes('/plan/fetch')) {
    return networkResponse({ data: [{ id: 1, name: 'Mobile Priority Plan', month_price: 1000, transfer_enable: 100 }] });
  }
  if (url.includes('/notice/fetch')) return networkResponse({ data: [], total: 0 });
  if (url.includes('/order/fetch')) return networkResponse({ data: [], current_page: 1, per_page: 20, total: 0, last_page: 1 });
  if (url.includes('/distributor/delivery')) {
    return networkResponse({ data: { trade_no: 'MOBILE-NEW-ORDER', delivery_status: 1, config_issued_at: 1700000000 } });
  }
  return networkResponse({ data: true });
}

function renderedText(harness) {
  const walk = (element) => [element.innerHTML, element.textContent, ...element.children.map(walk)].join('\n');
  return walk(harness.doc.body);
}

test('mobile request recovery retries identity and plans once after 400ms for transient failures', async (t) => {
  for (const endpoint of ['/user/info', '/user/plan/fetch']) {
    for (const failure of ['network', 502, 503, 504]) {
      await t.test(`${endpoint}: ${failure}`, async () => {
        let attempts = 0;
        const harness = createDistributorHarness({
          initialToken: 'Bearer mobile-retry',
          fetchImpl(url, options) {
            if (url.includes(endpoint)) {
              attempts += 1;
              if (attempts === 1) {
                return failure === 'network'
                  ? Promise.reject(new TypeError('Failed to fetch'))
                  : Promise.resolve(networkResponse({ message: 'Gateway temporarily unavailable' }, failure));
              }
            }
            return Promise.resolve(healthyDistributorResponse(url, options));
          },
        });
        await harness.flushMicrotasks();
        assert.equal(attempts, 1);
        await harness.advanceTimeAsync(399);
        assert.equal(attempts, 1, 'retry must not run before the backoff');
        await harness.advanceTimeAsync(1);
        assert.equal(attempts, 2, 'one bounded retry must run at 400ms');
        assert.match(harness.doc.getElementById('distributor-app')?.innerHTML || '', /Mobile Priority Plan/);
        await harness.advanceTimeAsync(2000);
        harness.runDetectorTick();
        await harness.flushMicrotasks();
        assert.equal(attempts, 2);
      });
    }
  }
});

test('mobile request recovery stops failed identity polling after its one retry until a new recovery event', async () => {
  let attempts = 0;
  const harness = createDistributorHarness({
    initialToken: 'Bearer mobile-stop',
    fetchImpl(url, options) {
      if (url.includes('/user/info')) {
        attempts += 1;
        return Promise.reject(new TypeError('Failed to fetch'));
      }
      return Promise.resolve(healthyDistributorResponse(url, options));
    },
  });
  await harness.flushMicrotasks();
  await harness.advanceTimeAsync(400);
  assert.equal(attempts, 2);
  for (let round = 0; round < 4; round += 1) {
    await harness.advanceTimeAsync(5000);
    harness.runDetectorTick();
    await harness.flushMicrotasks();
  }
  assert.equal(attempts, 2, 'offline identity requests must not restart every five seconds');
  assert.match(renderedText(harness), /重试|网络/);
});

test('mobile request recovery shares the original 12s deadline with the delayed plan retry', async () => {
  let attempts = 0;
  let resolveFirst;
  let retrySignal;
  const harness = createDistributorHarness({
    initialToken: 'Bearer mobile-deadline',
    fetchImpl(url, options) {
      if (url.includes('/plan/fetch')) {
        attempts += 1;
        if (attempts === 1) return new Promise((resolve) => { resolveFirst = resolve; });
        retrySignal = options.signal;
        return new Promise(() => {});
      }
      return Promise.resolve(healthyDistributorResponse(url, options));
    },
  });
  await harness.flushMicrotasks();
  await harness.advanceTimeAsync(11000);
  resolveFirst(networkResponse({ message: 'Service temporarily unavailable' }, 503));
  await harness.flushMicrotasks();
  await harness.advanceTimeAsync(400);
  assert.equal(attempts, 2);
  assert.equal(retrySignal?.aborted, false);
  await harness.advanceTimeAsync(600);
  assert.equal(retrySignal?.aborted, true, 'retry must end at the original deadline rather than receive another 12 seconds');
  assert.match(harness.doc.getElementById('distributor-app')?.innerHTML || '', /请求超时/);
  await harness.advanceTimeAsync(1000);
  assert.equal(attempts, 2);
});

test('mobile main route ownership confirms distributors without registering ordinary business pages', async (t) => {
  for (const entry of ['/plan', '/dashboard']) {
    await t.test(entry, async () => {
      let resolveIdentity;
      const harness = createUmiGuardHarness({
        initialToken: 'Bearer mobile-handoff',
        getConfigImpl: () => Promise.resolve({}),
        getUserInfoRawImpl: () => new Promise((resolve) => { resolveIdentity = resolve; }),
      });
      const pending = harness.runGuard({ path: entry, fullPath: entry, query: {} });
      await Promise.resolve();
      assert.equal(harness.addedRoutes.length, 0, 'identity must be decided before business routes can be installed');
      resolveIdentity({ data: { id: 501, uuid: 'dist-mobile', email: 'dist@example.invalid', is_distributor: true } });
      await pending;
      assert.equal(harness.addedRoutes.filter((route) => route.name === 'Dashboard').length, 0,
        'a distributor must hand off instead of mounting the ordinary dashboard/plan route');
      assert.equal(harness.redirects.length, 0);
      await harness.runGuard({ path: entry, fullPath: entry, query: {} });
      assert.equal(harness.addedRoutes.filter((route) => route.name === 'Dashboard').length, 0);
    });
  }
});

test('mobile request recovery does not retry timeout, authentication, business rejection or cancelled reads', async (t) => {
  for (const endpoint of ['/user/info', '/user/plan/fetch']) {
    for (const status of [400, 401, 403]) {
      await t.test(`${endpoint}: HTTP ${status}`, async () => {
        let attempts = 0;
        const harness = createDistributorHarness({
          initialToken: 'Bearer mobile-no-retry',
          fetchImpl(url, options) {
            if (url.includes(endpoint)) {
              attempts += 1;
              return Promise.resolve(networkResponse({ message: status === 401 ? '未登录或登陆已过期' : '该操作不可用' }, status));
            }
            return Promise.resolve(healthyDistributorResponse(url, options));
          },
        });
        await harness.flushMicrotasks();
        await harness.advanceTimeAsync(400);
        assert.equal(attempts, 1);
      });
    }
  }
  await t.test('timeout exhausts its deadline', async () => {
    let attempts = 0;
    let signal;
    const harness = createDistributorHarness({
      initialToken: 'Bearer mobile-timeout-no-retry',
      fetchImpl(url, options) {
        if (url.includes('/plan/fetch')) {
          attempts += 1;
          signal = options.signal;
          return new Promise(() => {});
        }
        return Promise.resolve(healthyDistributorResponse(url, options));
      },
    });
    await harness.flushMicrotasks();
    await harness.advanceTimeAsync(12400);
    assert.equal(signal.aborted, true);
    assert.equal(attempts, 1);
    assert.match(renderedText(harness), /请求超时/);
  });
  await t.test('route cancellation never becomes a retry or error', async () => {
    let attempts = 0;
    let signal;
    const harness = createDistributorHarness({
      initialToken: 'Bearer mobile-cancel-no-retry',
      fetchImpl(url, options) {
        if (url.includes('/plan/fetch')) {
          attempts += 1;
          signal = options.signal;
          return new Promise(() => {});
        }
        return Promise.resolve(healthyDistributorResponse(url, options));
      },
    });
    await harness.flushMicrotasks();
    harness.click({ nav: '/order' });
    await harness.flushMicrotasks();
    assert.equal(signal.aborted, true);
    await harness.advanceTimeAsync(12400);
    assert.equal(attempts, 1);
    assert.doesNotMatch(renderedText(harness), /Request cancelled|请求超时/);
  });
});

test('mobile hidden network failure stays off the error page and visible plus online recover once', async () => {
  let planAttempts = 0;
  let rejectInitialPlan;
  let releaseRecovery;
  let infoCalls = 0;
  const harness = createDistributorHarness({
    initialToken: 'Bearer mobile-offline-resume',
    fetchImpl(url, options) {
      if (url.includes('/user/info')) infoCalls += 1;
      if (url.includes('/plan/fetch')) {
        planAttempts += 1;
        if (planAttempts === 1) return new Promise((_, reject) => { rejectInitialPlan = reject; });
        return new Promise((resolve) => { releaseRecovery = () => resolve(healthyDistributorResponse(url, options)); });
      }
      return Promise.resolve(healthyDistributorResponse(url, options));
    },
  });
  await harness.flushMicrotasks();
  harness.setHidden(true);
  harness.setOnline(false);
  rejectInitialPlan(new TypeError('Failed to fetch'));
  await harness.flushMicrotasks();
  await harness.advanceTimeAsync(1000);
  assert.doesNotMatch(harness.doc.getElementById('distributor-app')?.innerHTML || '', /class="dist-error"/);
  assert.equal(planAttempts, 1, 'hidden/offline tabs must not spend their retry in the background');
  harness.setHidden(false);
  harness.windowObj.dispatchEvent({ type: 'pageshow', persisted: true });
  await harness.advanceTimeAsync(50);
  assert.equal(planAttempts, 1, 'visible alone must not request while navigator reports offline');
  harness.setOnline(true);
  harness.doc.dispatchEvent({ type: 'visibilitychange' });
  harness.windowObj.dispatchEvent({ type: 'pageshow', persisted: true });
  await harness.advanceTimeAsync(50);
  assert.equal(planAttempts, 2);
  const checkCalls = infoCalls;
  harness.setOnline(true);
  harness.doc.dispatchEvent({ type: 'visibilitychange' });
  harness.windowObj.dispatchEvent({ type: 'pageshow', persisted: true });
  await harness.advanceTimeAsync(50);
  assert.equal(planAttempts, 2, 'the same recovery must not cancel and restart an in-flight read');
  assert.ok(infoCalls <= checkCalls + 1, 'session validation must also remain single-flight');
  releaseRecovery();
  await harness.flushMicrotasks();
  assert.match(harness.doc.getElementById('distributor-app')?.innerHTML || '', /Mobile Priority Plan/);
  assert.doesNotMatch(renderedText(harness), /Failed to fetch|未知错误|Request cancelled/);
});

test('mobile resume leaves an already usable plan visible when silent identity validation fails', async () => {
  let failValidation = false;
  let planAttempts = 0;
  const harness = createDistributorHarness({
    initialToken: 'Bearer mobile-keep-plan',
    fetchImpl(url, options) {
      if (url.includes('/plan/fetch')) planAttempts += 1;
      if (url.includes('/user/info') && failValidation) return Promise.reject(new TypeError('Failed to fetch'));
      return Promise.resolve(healthyDistributorResponse(url, options));
    },
  });
  await harness.flushMicrotasks(80);
  const before = harness.doc.getElementById('distributor-app').innerHTML;
  assert.match(before, /Mobile Priority Plan/, 'the page must be usable before testing silent validation');
  failValidation = true;
  harness.setHidden(true);
  await harness.advanceTimeAsync(65000);
  harness.setHidden(false);
  harness.windowObj.dispatchEvent({ type: 'pageshow', persisted: true });
  await harness.advanceTimeAsync(450);
  assert.equal(harness.doc.getElementById('distributor-app').innerHTML, before);
  assert.equal(planAttempts, 1);
});

test('mobile logout from errors or in-flight reads remains on login even when the revoke request fails', async (t) => {
  for (const mode of ['error', 'in-flight']) {
    await t.test(mode, async () => {
      let resolveLatePlan;
      const harness = createDistributorHarness({
        initialToken: 'Bearer mobile-logout',
        fetchImpl(url, options) {
          if (url.includes('/plan/fetch')) {
            return mode === 'error'
              ? Promise.resolve(networkResponse({ message: '套餐读取暂不可用' }, 400))
              : new Promise((resolve) => { resolveLatePlan = resolve; });
          }
          if (url.includes('/user/logout')) return Promise.reject(new TypeError('Failed to fetch'));
          return Promise.resolve(healthyDistributorResponse(url, options));
        },
      });
      await harness.flushMicrotasks();
      harness.click({ action: 'logout' });
      await harness.flushMicrotasks();
      assert.equal(harness.windowObj.location.hash, '#/login');
      assert.equal(harness.doc.getElementById('distributor-app'), null);
      assert.equal(harness.doc.documentElement.classList.contains('distributor-mode'), false);
      assert.equal(harness.storage.getItem('VUE_NAIVE_ACCESS_TOKEN'), null);
      if (resolveLatePlan) resolveLatePlan(healthyDistributorResponse('/plan/fetch', {}));
      await harness.advanceTimeAsync(13000);
      harness.windowObj.dispatchEvent({ type: 'pageshow', persisted: true });
      await harness.advanceTimeAsync(50);
      assert.equal(harness.windowObj.location.hash, '#/login');
      assert.equal(harness.doc.getElementById('distributor-app'), null);
      assert.doesNotMatch(renderedText(harness), /Unauthorized|Request cancelled/);
    });
  }
});

test('mobile slow new purchase survives timeout and resume without issuing a second POST', async () => {
  let postCalls = 0;
  let resolveSave;
  let writeSignal;
  const harness = createDistributorHarness({
    initialToken: 'Bearer mobile-slow-purchase',
    fetchImpl(url, options) {
      if (url.includes('/order/save')) {
        postCalls += 1;
        writeSignal = options.signal;
        return new Promise((resolve) => { resolveSave = resolve; });
      }
      return Promise.resolve(healthyDistributorResponse(url, options));
    },
  });
  await harness.flushMicrotasks();
  harness.click({ buy: 1 });
  await harness.flushMicrotasks();
  harness.setHidden(true);
  await harness.advanceTimeAsync(15000);
  harness.setHidden(false);
  harness.setOnline(true);
  harness.windowObj.dispatchEvent({ type: 'pageshow', persisted: true });
  await harness.advanceTimeAsync(50);
  harness.click({ action: 'retry' });
  await harness.flushMicrotasks();
  harness.click({ buy: 1 });
  await harness.flushMicrotasks();
  assert.equal(postCalls, 1);
  assert.equal(writeSignal, undefined, 'writes must not inherit the read deadline controller');
  resolveSave(networkResponse({ data: 'MOBILE-NEW-ORDER' }));
  await harness.flushMicrotasks();
  assert.match(harness.doc.getElementById('dist-modal-root')?.innerHTML || '', /MOBILE-NEW-ORDER|已经领取|订阅已经领取/);
});

test('mobile ambiguous new purchase retains its lock until read-only order review and explicit new intent', async () => {
  let postCalls = 0;
  let orderReads = 0;
  const harness = createDistributorHarness({
    initialToken: 'Bearer mobile-ambiguous-purchase',
    fetchImpl(url, options) {
      if (url.includes('/order/save')) {
        postCalls += 1;
        return postCalls === 1
          ? Promise.reject(new TypeError('Failed to fetch'))
          : Promise.resolve(networkResponse({ data: 'MOBILE-NEW-ORDER' }));
      }
      if (url.includes('/order/fetch')) orderReads += 1;
      return Promise.resolve(healthyDistributorResponse(url, options));
    },
  });
  await harness.flushMicrotasks();
  harness.click({ buy: 1 });
  await harness.flushMicrotasks();
  assert.match(renderedText(harness), /未能确认下单结果，请先查看我的订单/);
  assert.match(renderedText(harness), /data-action="check-purchase-orders"/);
  harness.click({ buy: 1 });
  harness.setHidden(true);
  await harness.advanceTimeAsync(65000);
  harness.setHidden(false);
  harness.setOnline(true);
  harness.windowObj.dispatchEvent({ type: 'pageshow', persisted: true });
  await harness.advanceTimeAsync(450);
  harness.click({ action: 'retry' });
  await harness.flushMicrotasks();
  harness.click({ buy: 1 });
  await harness.flushMicrotasks();
  assert.equal(postCalls, 1);
  harness.click({ action: 'check-purchase-orders' });
  await harness.flushMicrotasks();
  assert.ok(orderReads >= 1);
  harness.click({ buy: 1 });
  await harness.flushMicrotasks();
  assert.equal(postCalls, 1, 'reading orders alone cannot prove that a delayed write did not commit');
  assert.match(renderedText(harness), /我已核对，继续下单/);
  harness.click({ action: 'acknowledge-order-check' });
  harness.click({ nav: '/plan' });
  await harness.flushMicrotasks();
  harness.click({ buy: 1 });
  await harness.flushMicrotasks();
  assert.equal(postCalls, 2, 'only an explicit new purchase intention may release the uncertain result lock');
});

test('mobile confirmed purchase followed by failed delivery keeps the order number and never offers a duplicate buy', async () => {
  let postCalls = 0;
  const harness = createDistributorHarness({
    initialToken: 'Bearer mobile-known-order',
    fetchImpl(url, options) {
      if (url.includes('/order/save')) {
        postCalls += 1;
        return Promise.resolve(networkResponse({ data: 'KNOWN-ORDER-501' }));
      }
      if (url.includes('/distributor/delivery')) return Promise.reject(new TypeError('Failed to fetch'));
      return Promise.resolve(healthyDistributorResponse(url, options));
    },
  });
  await harness.flushMicrotasks();
  harness.click({ buy: 1 });
  await harness.flushMicrotasks();
  assert.match(renderedText(harness), /KNOWN-ORDER-501/);
  assert.match(renderedText(harness), /data-action="check-purchase-orders"/);
  harness.click({ buy: 1 });
  await harness.flushMicrotasks();
  assert.equal(postCalls, 1, 'delivery failure must not turn a successful purchase into another purchase request');
});

test('mobile known order delivery retry only reads its original order and unlocks after showing delivery', async () => {
  let postCalls = 0;
  const deliveryUrls = [];
  const harness = createDistributorHarness({
    initialToken: 'Bearer mobile-delivery-success',
    fetchImpl(url, options) {
      if (url.includes('/order/save')) {
        postCalls += 1;
        return Promise.resolve(networkResponse({ data: 'KNOWN-DELIVERY-ORDER' }));
      }
      if (url.includes('/distributor/delivery')) {
        deliveryUrls.push(url);
        return deliveryUrls.length === 1
          ? Promise.reject(new TypeError('Failed to fetch'))
          : Promise.resolve(networkResponse({ data: { trade_no: 'KNOWN-DELIVERY-ORDER', delivery_status: 1, config_issued_at: 1700000000 } }));
      }
      return Promise.resolve(healthyDistributorResponse(url, options));
    },
  });
  await harness.flushMicrotasks(80);
  harness.click({ buy: 1 });
  await harness.flushMicrotasks(80);
  assert.match(renderedText(harness), /data-action="retry-purchase-delivery"/);
  harness.click({ action: 'retry-purchase-delivery' });
  await harness.flushMicrotasks(80);
  assert.equal(postCalls, 1, 'retrieving a known order must never repeat its purchase');
  assert.equal(deliveryUrls.length, 2);
  assert.ok(deliveryUrls.every((url) => new URL(url).searchParams.get('trade_no') === 'KNOWN-DELIVERY-ORDER'));
  assert.match(harness.doc.getElementById('dist-modal-root')?.innerHTML || '', /订阅已经领取|已经领取/);
  assert.doesNotMatch(harness.doc.getElementById('distributor-app')?.innerHTML || '', /data-action="retry-purchase-delivery"/);
  harness.click({ modalAction: 'close-delivery' });
  harness.click({ buy: 1 });
  await harness.flushMicrotasks(80);
  assert.equal(postCalls, 2, 'a displayed successful delivery must permit the next explicit purchase');
});

test('mobile definite business or authentication rejection does not retain an ambiguous purchase lock', async (t) => {
  for (const status of [400, 401]) {
    await t.test(`HTTP ${status}`, async () => {
      let postCalls = 0;
      const harness = createDistributorHarness({
        initialToken: 'Bearer mobile-known-rejection',
        fetchImpl(url, options) {
          if (url.includes('/order/save')) {
            postCalls += 1;
            return Promise.resolve(postCalls === 1
              ? networkResponse({ message: status === 401 ? '未登录或登陆已过期' : '该套餐当前不可购买' }, status)
              : networkResponse({ data: 'MOBILE-NEW-ORDER' }));
          }
          return Promise.resolve(healthyDistributorResponse(url, options));
        },
      });
      await harness.flushMicrotasks();
      harness.click({ buy: 1 });
      await harness.flushMicrotasks();
      assert.doesNotMatch(renderedText(harness), /未能确认下单结果/);
      if (status === 401) {
        assert.equal(harness.windowObj.location.hash, '#/login');
        harness.storage.setItem('VUE_NAIVE_ACCESS_TOKEN', JSON.stringify({ value: 'Bearer mobile-new-session', expire: null }));
        harness.windowObj.location.hash = '#/plan';
        await harness.flushMicrotasks();
      }
      harness.click({ buy: 1 });
      await harness.flushMicrotasks();
      assert.equal(postCalls, 2);
    });
  }
});

test('mobile main identity confirmation is session-bound and ordinary accounts keep their business routes', async () => {
  const harness = createUmiGuardHarness({
    initialToken: 'Bearer mobile-normal',
    getConfigImpl: () => Promise.resolve({}),
    getUserInfoRawImpl: () => Promise.resolve({ data: { id: 601, uuid: 'normal-mobile', email: 'normal@example.invalid', is_distributor: false } }),
  });
  const session = harness.windowObj.__xboardAuthSession;
  assert.equal(typeof session.confirmUserIdentity, 'function');
  assert.equal(typeof session.getConfirmedUser, 'function');
  const snapshot = session.captureSession();
  const nextCalls = await harness.runGuard({ path: '/plan', fullPath: '/plan', query: {} });
  assert.equal(harness.addedRoutes.filter((route) => route.name === 'Dashboard').length, 1);
  assert.ok(nextCalls.some((target) => target && target.path === '/plan'));
  assert.equal(session.getConfirmedUser(snapshot)?.is_distributor, false);
  harness.storage.setItem('VUE_NAIVE_ACCESS_TOKEN', JSON.stringify({ value: 'Bearer mobile-replaced', expire: null }));
  assert.equal(session.getConfirmedUser(snapshot), null);
});

test('mobile main initialization owns one Chinese retry prompt instead of duplicate unknown-error toasts', async () => {
  const harness = createUmiGuardHarness({
    initialToken: 'Bearer mobile-init-error',
    getConfigImpl: () => Promise.resolve({}),
    getUserInfoRawImpl: () => Promise.reject(new TypeError('Failed to fetch')),
  });
  const nextCalls = await harness.runGuard({ path: '/plan', fullPath: '/plan', query: {} });
  assert.equal(nextCalls.length, 1);
  assert.equal(nextCalls[0], false);
  assert.equal(harness.redirects.length, 0);
  assert.equal(harness.logoutRequests.length, 0);
  assert.equal(harness.messages.length, 0, 'the initialization banner owns this failure; RN and guard must not each toast it');
  assert.match(harness.doc.getElementById('xboard-auth-recovery-msg')?.textContent || '', /网络|连接|暂时/);
  assert.doesNotMatch(renderedText(harness), /Failed to fetch|未知错误/);
});

function deferredIdentityResponse() {
  let resolve, reject;
  const promise = new Promise((onResolve, onReject) => { resolve = onResolve; reject = onReject; });
  return { promise, resolve, reject };
}

test('main initialization reuses a concurrently confirmed identity only for a current-session transient failure', async (t) => {
  for (const status of [0, 429, 502]) {
    await t.test(`HTTP ${status}`, async () => {
      const response = deferredIdentityResponse();
      const harness = createUmiGuardHarness({
        initialToken: 'Bearer concurrent-identity',
        getUserInfoRawImpl: () => response.promise,
      });
      const auth = harness.windowObj.__xboardAuthSession;
      const user = { id: 7, uuid: 'concurrent-identity', email: 'qa-concurrent@example.invalid', is_distributor: true };
      const request = harness.getUserStore().getUserInfo({ xboardAuthInitialization: true });
      auth.confirmUserIdentity(auth.captureSession(), user);
      response.reject(status === 0 ? new TypeError('Failed to fetch') : { response: { status, data: { message: 'QA transient identity failure' } } });
      const recoveredUser = await request;
      assert.equal(recoveredUser.uuid, user.uuid);
      assert.equal(recoveredUser.email, user.email);
      assert.equal(recoveredUser.is_distributor, true);
      assert.equal(harness.getUserStore().userUUID, user.uuid);
      assert.equal(harness.logoutRequests.length, 0);
    });
  }
});

test('confirmed identity never masks a non-initialization failure or an invalid HTTP 200 identity', async (t) => {
  for (const mode of ['non-initialization', 'invalid-200']) {
    await t.test(mode, async () => {
      const response = deferredIdentityResponse();
      const harness = createUmiGuardHarness({
        initialToken: 'Bearer identity-fallback-negative',
        getUserInfoRawImpl: () => response.promise,
      });
      const auth = harness.windowObj.__xboardAuthSession;
      const request = harness.getUserStore().getUserInfo(mode === 'invalid-200' ? { xboardAuthInitialization: true } : {});
      auth.confirmUserIdentity(auth.captureSession(), { id: 7, uuid: 'must-not-be-used', is_distributor: true });
      if (mode === 'invalid-200') response.resolve({ data: null });
      else response.reject(new TypeError('Failed to fetch'));
      await assert.rejects(request, error => mode === 'invalid-200' ? error.data === null : error.status === 0);
      assert.equal(harness.getUserStore().userUUID, undefined);
      assert.equal(harness.logoutRequests.length, 0);
    });
  }
});

test('late initialization failure cannot reuse a replacement session identity', async () => {
  const response = deferredIdentityResponse();
  const harness = createUmiGuardHarness({
    initialToken: 'Bearer identity-old-session',
    getUserInfoRawImpl: () => response.promise,
  });
  const auth = harness.windowObj.__xboardAuthSession;
  const request = harness.getUserStore().getUserInfo({ xboardAuthInitialization: true });
  harness.storage.setItem('VUE_NAIVE_ACCESS_TOKEN', JSON.stringify({ value: 'Bearer identity-new-session', expire: null }));
  auth.confirmUserIdentity(auth.captureSession(), { id: 8, uuid: 'replacement-session', is_distributor: true });
  response.reject(new TypeError('Failed to fetch'));
  await assert.rejects(request, error => error.status === 0);
  assert.equal(harness.getUserStore().userUUID, undefined);
  assert.equal(JSON.parse(harness.storage.getItem('VUE_NAIVE_ACCESS_TOKEN')).value, 'Bearer identity-new-session');
  assert.equal(harness.logoutRequests.length, 0);
});

test('mobile read retry policy never repeats renewal, invitation creation or balance transfer', async (t) => {
  for (const endpoint of ['/user/order/renew', '/user/invite/save', '/user/transfer']) {
    await t.test(endpoint, async () => {
      let writes = 0;
      const writeSignals = [];
      const harness = createDistributorHarness({
        initialToken: 'Bearer mobile-mutations',
        fetchImpl(url, options) {
          if (url.includes(endpoint)) {
            writes += 1;
            writeSignals.push(options.signal);
            return Promise.reject(new TypeError('Failed to fetch'));
          }
          if (url.includes('/order/fetch')) {
            return Promise.resolve(networkResponse({
              data: [{ trade_no: 'MOBILE-RENEWABLE', period: 'month_price', can_renew: true,
                plan: { id: 1, name: 'Mobile Priority Plan', month_price: 1000 } }],
              current_page: 1, per_page: 20, total: 1, last_page: 1,
            }));
          }
          return Promise.resolve(healthyDistributorResponse(url, options));
        },
      });
      await harness.flushMicrotasks();
      if (endpoint.endsWith('/renew')) {
        harness.click({ nav: '/order' });
        await harness.flushMicrotasks();
        harness.click({ renew: 'MOBILE-RENEWABLE' });
        harness.click({ modalAction: 'confirm-renewal' });
      } else if (endpoint.endsWith('/invite/save')) {
        harness.click({ action: 'generate-code' });
      } else {
        const input = new FakeElement('input', harness.doc);
        input.value = '5';
        harness.doc.inputs.set('transfer-amount', input);
        harness.click({ action: 'transfer' });
      }
      await harness.flushMicrotasks();
      assert.equal(writes, 1);
      await harness.advanceTimeAsync(15000);
      harness.setHidden(true);
      await harness.advanceTimeAsync(65000);
      harness.setHidden(false);
      harness.setOnline(true);
      harness.windowObj.dispatchEvent({ type: 'pageshow', persisted: true });
      await harness.advanceTimeAsync(450);
      assert.equal(writes, 1);
      assert.equal(writeSignals[0], undefined);
    });
  }
});

test('mobile main config failure keeps the target recoverable without installing ordinary business routes early', async () => {
  let configCalls = 0;
  const harness = createUmiGuardHarness({
    initialToken: 'Bearer mobile-config-retry',
    getConfigImpl: () => {
      configCalls += 1;
      return configCalls === 1
        ? Promise.reject(Object.assign(new Error('Service temporarily unavailable'), { status: 503, rawMessage: '' }))
        : Promise.resolve({});
    },
    getUserInfoRawImpl: () => Promise.resolve({ data: { uuid: 'ordinary-mobile', email: 'normal@example.invalid', is_distributor: false } }),
  });
  const calls = await harness.runGuard({ path: '/order', fullPath: '/order?page=2', query: { page: '2' } });
  assert.equal(calls[0], false);
  assert.equal(harness.addedRoutes.length, 0);
  assert.equal(harness.redirects.length, 0);
  assert.equal(harness.messages.length, 0);
  assert.match(harness.doc.getElementById('xboard-auth-recovery-msg')?.textContent || '', /网络|连接|暂时/);
  await harness.windowObj.__xboardAuthSession.retryRecovery();
  assert.deepEqual(harness.replaceCalls, ['/order?page=2']);
  assert.equal(configCalls, 2);
});

test('mobile distributor handoff removes previously registered ordinary routes when the account changes', async () => {
  let distributor = false;
  const harness = createUmiGuardHarness({
    initialToken: 'Bearer mobile-role-normal',
    getConfigImpl: () => Promise.resolve({}),
    getUserInfoRawImpl: () => Promise.resolve({ data: {
      uuid: distributor ? 'mobile-role-dist' : 'mobile-role-normal',
      email: distributor ? 'dist@example.invalid' : 'normal@example.invalid',
      is_distributor: distributor,
    } }),
  });
  await harness.runGuard({ path: '/plan', fullPath: '/plan', query: {} });
  assert.ok(harness.addedRoutes.some((route) => route.name === 'Dashboard'));
  distributor = true;
  harness.storage.setItem('VUE_NAIVE_ACCESS_TOKEN', JSON.stringify({ value: 'Bearer mobile-role-distributor', expire: null }));
  await harness.runGuard({ path: '/plan', fullPath: '/plan', query: {} });
  assert.equal(harness.addedRoutes.some((route) => route.name === 'Dashboard'), false);
  assert.equal(harness.redirects.length, 0);
});

test('mobile main identity handoff reparses an already mounted ordinary page at the same address', async () => {
  const harness = createUmiGuardHarness({
    initialToken: 'Bearer mobile-role-change',
    getConfigImpl: () => Promise.resolve({}),
    getUserInfoRawImpl: () => Promise.resolve({ data: { id: 701, uuid: 'role-change', is_distributor: false } }),
  });
  const target = { path: '/dashboard', fullPath: '/dashboard?retained=1', matched: [] };
  await harness.runGuard(target);
  assert.ok(harness.addedRoutes.some((route) => route.name === 'Dashboard'));
  harness.router.currentRoute.value = { ...target, matched: [{ name: 'Dashboard', path: '/dashboard' }] };
  const auth = harness.windowObj.__xboardAuthSession;
  assert.equal(auth.confirmUserIdentity(auth.captureSession(), { id: 701, uuid: 'role-change', is_distributor: true }), true);
  assert.equal(harness.addedRoutes.length, 0, 'confirmed distributors must unload ordinary business route records');
  assert.deepEqual(harness.replaceCalls, [target.fullPath], 'mounted ordinary content must be reparsed while retaining its address');
});

test('mobile cancelled order search cannot leak a toast onto the next page', async () => {
  let orderReads = 0;
  const harness = createDistributorHarness({
    initialToken: 'Bearer mobile-cancelled-search',
    initialHash: '#/order',
    fetchImpl(url, options) {
      if (url.includes('/order/fetch') && ++orderReads === 2) return new Promise(() => {});
      return Promise.resolve(healthyDistributorResponse(url, options));
    },
  });
  await harness.flushMicrotasks(80);
  harness.click({ action: 'search-orders' });
  await harness.flushMicrotasks();
  harness.click({ nav: '/plan' });
  await harness.flushMicrotasks(80);
  assert.doesNotMatch(renderedText(harness), /Request cancelled|请求超时/);
});

for (const retryDelivery of [false, true]) {
  test(`mobile superseded ${retryDelivery ? 'retried' : 'initial'} delivery retains the known order and purchase lock`, async () => {
    let postCalls = 0;
    let deliveryReads = 0;
    let releaseDelivery;
    const harness = createDistributorHarness({
      initialToken: 'Bearer mobile-delivery-replaced',
      fetchImpl(url, options) {
        if (url.includes('/order/save')) {
          postCalls += 1;
          return Promise.resolve(networkResponse({ data: 'MOBILE-KNOWN-ORDER' }));
        }
        if (url.includes('/distributor/delivery')) {
          deliveryReads += 1;
          if (retryDelivery && deliveryReads === 1) return Promise.reject(new TypeError('Failed to fetch'));
          return new Promise((resolve) => {
            releaseDelivery = () => resolve(networkResponse({ data: { trade_no: 'MOBILE-KNOWN-ORDER', delivery_status: 1 } }));
          });
        }
        if (url.includes('/subscription-qr')) {
          return Promise.resolve(networkResponse({ data: { trade_no: 'OLD-ORDER', qr_code: 'data:image/png;base64,OLD' } }));
        }
        if (url.includes('/order/fetch')) {
          return Promise.resolve(networkResponse({
            data: [{ id: 1, trade_no: 'OLD-ORDER', period: 'month_price', plan: { name: 'Old plan' }, can_view_subscription_qr: true }],
            current_page: 1, per_page: 20, total: 1, last_page: 1,
          }));
        }
        return Promise.resolve(healthyDistributorResponse(url, options));
      },
    });
    await harness.flushMicrotasks(80);
    harness.click({ buy: 1 });
    await harness.flushMicrotasks(80);
    if (retryDelivery) {
      assert.match(renderedText(harness), /MOBILE-KNOWN-ORDER/);
      harness.click({ action: 'retry-purchase-delivery' });
      await harness.flushMicrotasks();
    }
    harness.click({ nav: '/order' });
    await harness.flushMicrotasks(80);
    harness.click({ subscriptionQr: 'OLD-ORDER' });
    await harness.flushMicrotasks(80);
    releaseDelivery();
    await harness.flushMicrotasks(80);
    harness.click({ nav: '/plan' });
    await harness.flushMicrotasks(80);
    assert.match(renderedText(harness), /MOBILE-KNOWN-ORDER/, 'a superseded delivery must retain its created order recovery entry');
    harness.click({ buy: 1 });
    await harness.flushMicrotasks(80);
    assert.equal(postCalls, 1, 'another QR does not establish a new purchase intention');
  });
}

function reviewRenewableOrder() {
  return {
    id: 710, trade_no: 'REVIEW-RENEW-SOURCE', can_renew: true,
    period: 'month_price', plan: { id: 1, name: 'Review renewal plan', month_price: 1000, quarter_price: 3000 },
  };
}

test('review fix pending renewal survives modal close and reopen without another POST or idempotency key', async () => {
  const renewRequests = [];
  let releaseFirstRenewal;
  const harness = createDistributorHarness({
    initialToken: 'Bearer review-pending-renewal', initialHash: '#/order',
    fetchImpl(url, options) {
      if (url.includes('/order/fetch')) return Promise.resolve(networkResponse({
        data: [reviewRenewableOrder()], current_page: 1, per_page: 20, total: 1, last_page: 1,
      }));
      if (url.includes('/order/renew')) {
        renewRequests.push(Object.fromEntries(options.body.entries()));
        if (renewRequests.length === 1) return new Promise((resolve) => { releaseFirstRenewal = resolve; });
        return Promise.resolve(networkResponse({ data: { trade_no: 'REVIEW-RENEW-SECOND', expired_at_after: 1900000000 } }));
      }
      return Promise.resolve(healthyDistributorResponse(url, options));
    },
  });
  await harness.flushMicrotasks(80);
  harness.click({ renew: 'REVIEW-RENEW-SOURCE' });
  harness.click({ modalAction: 'confirm-renewal' });
  await harness.flushMicrotasks(80);
  harness.setHidden(true);
  await harness.advanceTimeAsync(15000);
  harness.setHidden(false);
  await harness.advanceTimeAsync(50);
  harness.click({ modalAction: 'cancel' });
  harness.click({ renew: 'REVIEW-RENEW-SOURCE' });
  harness.click({ modalAction: 'confirm-renewal' });
  await harness.flushMicrotasks(80);
  assert.equal(renewRequests.length, 1, 'closing or restoring a slow renewal must not create another operation');
  releaseFirstRenewal(networkResponse({ data: { trade_no: 'REVIEW-RENEW-FIRST', expired_at_after: 1900000000 } }));
  await harness.flushMicrotasks(80);
  assert.match(harness.doc.getElementById('dist-modal-root')?.innerHTML || '', /REVIEW-RENEW-FIRST/);
  harness.click({ modalAction: 'renew-done' });
  harness.click({ renew: 'REVIEW-RENEW-SOURCE' });
  harness.click({ modalAction: 'confirm-renewal' });
  await harness.flushMicrotasks(80);
  assert.equal(renewRequests.length, 2, 'a confirmed renewal may be followed by a new explicit renewal');
  assert.notEqual(renewRequests[0].idempotency_key, renewRequests[1].idempotency_key);
});

test('review fix uncertain renewal reuses its idempotency key after closing and reopening the modal', async () => {
  const renewRequests = [];
  const harness = createDistributorHarness({
    initialToken: 'Bearer review-uncertain-renewal', initialHash: '#/order',
    fetchImpl(url, options) {
      if (url.includes('/order/fetch')) return Promise.resolve(networkResponse({
        data: [reviewRenewableOrder()], current_page: 1, per_page: 20, total: 1, last_page: 1,
      }));
      if (url.includes('/order/renew')) {
        renewRequests.push(Object.fromEntries(options.body.entries()));
        return renewRequests.length === 1
          ? Promise.reject(new TypeError('Failed to fetch'))
          : Promise.resolve(networkResponse({ data: { trade_no: 'REVIEW-ONE-COMMITTED-RENEWAL', expired_at_after: 1900000000 } }));
      }
      return Promise.resolve(healthyDistributorResponse(url, options));
    },
  });
  await harness.flushMicrotasks(80);
  harness.click({ renew: 'REVIEW-RENEW-SOURCE' });
  harness.click({ modalAction: 'confirm-renewal' });
  await harness.flushMicrotasks(80);
  assert.equal(renewRequests.length, 1);
  assert.match(harness.doc.getElementById('dist-modal-root')?.innerHTML || '', /<select[^>]*id="dist-renewal-period"[^>]*disabled/, 'an unknown renewal must keep its original period fixed');
  harness.click({ modalAction: 'cancel' });
  harness.click({ renew: 'REVIEW-RENEW-SOURCE' });
  harness.click({ modalAction: 'confirm-renewal' });
  await harness.flushMicrotasks(80);
  assert.equal(renewRequests.length, 2, 'only an explicit retry may send another renewal request');
  assert.equal(renewRequests[1].idempotency_key, renewRequests[0].idempotency_key, 'an unknown result must retain the operation identity');
  assert.equal(renewRequests[1].trade_no, renewRequests[0].trade_no);
  assert.equal(renewRequests[1].period, renewRequests[0].period);
  assert.match(harness.doc.getElementById('dist-modal-root')?.innerHTML || '', /REVIEW-ONE-COMMITTED-RENEWAL/);
});

for (const status of [400, 422]) {
  test(`review fix definite renewal HTTP ${status} rejection permits correcting its period`, async () => {
    const requests = [];
    const harness = createDistributorHarness({
      initialToken: `Bearer review-renewal-rejected-${status}`, initialHash: '#/order',
      fetchImpl(url, options) {
        if (url.includes('/order/fetch')) return Promise.resolve(networkResponse({
          data: [reviewRenewableOrder()], current_page: 1, per_page: 20, total: 1, last_page: 1,
        }));
        if (url.includes('/order/renew')) {
          requests.push(Object.fromEntries(options.body.entries()));
          return Promise.resolve(requests.length === 1
            ? networkResponse({ message: 'Choose another period' }, status)
            : networkResponse({ data: { trade_no: 'REVIEW-CORRECTED-RENEWAL', expired_at_after: 1900000000 } }));
        }
        return Promise.resolve(healthyDistributorResponse(url, options));
      },
    });
    await harness.flushMicrotasks(80);
    harness.click({ renew: 'REVIEW-RENEW-SOURCE' });
    harness.click({ modalAction: 'confirm-renewal' });
    await harness.flushMicrotasks(80);
    assert.doesNotMatch(harness.doc.getElementById('dist-modal-root')?.innerHTML || '', /<select[^>]*id="dist-renewal-period"[^>]*disabled/);
    harness.doc.dispatchEvent({ type: 'change', target: { id: 'dist-renewal-period', value: 'quarter_price' } });
    harness.click({ modalAction: 'confirm-renewal' });
    await harness.flushMicrotasks(80);
    assert.equal(requests.length, 2);
    assert.equal(requests[1].period, 'quarter_price');
    assert.match(harness.doc.getElementById('dist-modal-root')?.innerHTML || '', /REVIEW-CORRECTED-RENEWAL/);
  });
}

async function seedReviewOrderFilters(harness) {
  harness.click({ nav: '/order' });
  await harness.flushMicrotasks(80);
  harness.doc.inputs.set('dist-order-search', { value: 'unrelated old customer' });
  harness.click({ action: 'search-orders' });
  await harness.flushMicrotasks(80);
  for (const [id, value] of Object.entries({
    'dist-order-settlement': '1', 'dist-order-start': '2020-01-01', 'dist-order-end': '2020-01-31',
    'dist-order-min-amount': '10', 'dist-order-max-amount': '20',
  })) harness.doc.inputs.set(id, { value });
  harness.click({ action: 'apply-order-filters' });
  await harness.flushMicrotasks(80);
  harness.doc.inputs.set('dist-order-page-input', { value: '99', focus() {} });
  harness.click({ action: 'jump-order-page' });
  await harness.flushMicrotasks(80);
}

for (const entry of ['banner', 'bottom-navigation']) {
  test(`review fix ${entry} opens unfiltered first-page order reconciliation and empty results keep purchase locked`, async () => {
    let postCalls = 0;
    const orderQueries = [];
    const harness = createDistributorHarness({
      initialToken: `Bearer review-order-check-${entry}`,
      fetchImpl(url, options) {
        if (url.includes('/order/save')) {
          postCalls += 1;
          return Promise.reject(new TypeError('Failed to fetch'));
        }
        if (url.includes('/order/fetch')) {
          const params = new URL(url).searchParams;
          orderQueries.push(params);
          return Promise.resolve(networkResponse({
            data: [], current_page: Number(params.get('page') || 1), per_page: 20,
            total: postCalls ? 0 : 2000, last_page: postCalls ? 1 : 100,
          }));
        }
        return Promise.resolve(healthyDistributorResponse(url, options));
      },
    });
    await harness.flushMicrotasks(80);
    await seedReviewOrderFilters(harness);
    assert.equal(orderQueries.at(-1).get('page'), '99');
    assert.equal(orderQueries.at(-1).get('search'), 'unrelated old customer');
    harness.click({ nav: '/plan' });
    await harness.flushMicrotasks(80);
    harness.click({ buy: 1 });
    await harness.flushMicrotasks(80);
    if (entry === 'banner') harness.click({ action: 'check-purchase-orders' });
    else harness.click({ nav: '/order' });
    await harness.flushMicrotasks(80);
    const query = orderQueries.at(-1);
    assert.equal(query.get('page'), '1', 'reconciliation must inspect the most recent orders');
    for (const key of ['search', 'settlement_status', 'start_date', 'end_date', 'period', 'min_amount', 'max_amount']) {
      assert.equal(query.has(key), false, `reconciliation must remove the old ${key} restriction`);
    }
    assert.match(renderedText(harness), /data-action="acknowledge-order-check"/, 'both entrances must expose the same explicit acknowledgement');
    harness.click({ nav: '/plan' });
    await harness.flushMicrotasks(80);
    harness.click({ buy: 1 });
    await harness.flushMicrotasks(80);
    assert.equal(postCalls, 1, 'empty order results must not release the uncertain purchase lock');
  });
}

test('review fix reconciliation of a known order searches its exact trade number without unrelated restrictions', async () => {
  const knownTradeNo = 'REVIEW-KNOWN-ORDER-710';
  const readUrls = [];
  let postCalls = 0;
  const harness = createDistributorHarness({
    initialToken: 'Bearer review-known-order-check',
    fetchImpl(url, options) {
      if (url.includes('/order/save')) { postCalls += 1; return Promise.resolve(networkResponse({ data: knownTradeNo })); }
      if (url.includes('/distributor/delivery')) return Promise.reject(new TypeError('Failed to fetch'));
      if (url.includes('/order/fetch') || url.includes('/order/detail')) {
        readUrls.push(url);
        if (url.includes('/order/detail')) return Promise.resolve(networkResponse({ data: {
          id: 711, trade_no: knownTradeNo, period: 'month_price', plan: { id: 1, name: 'Exact known order' },
        } }));
        const params = new URL(url).searchParams;
        return Promise.resolve(networkResponse({ data: [], current_page: Number(params.get('page') || 1), per_page: 20, total: 2000, last_page: 100 }));
      }
      return Promise.resolve(healthyDistributorResponse(url, options));
    },
  });
  await harness.flushMicrotasks(80);
  await seedReviewOrderFilters(harness);
  harness.click({ nav: '/plan' });
  await harness.flushMicrotasks(80);
  harness.click({ buy: 1 });
  await harness.flushMicrotasks(80);
  harness.click({ action: 'check-purchase-orders' });
  await harness.flushMicrotasks(80);
  const query = new URL(readUrls.at(-1)).searchParams;
  assert.equal(new URL(readUrls.at(-1)).pathname, '/api/v1/user/order/detail');
  assert.equal(query.get('trade_no'), knownTradeNo);
  for (const key of ['settlement_status', 'start_date', 'end_date', 'min_amount', 'max_amount']) assert.equal(query.has(key), false);
  assert.equal(postCalls, 1);
});

test('review fix successful delivery recovery removes stale order-page actions while keeping the delivery modal', async () => {
  let postCalls = 0;
  let deliveryReads = 0;
  const harness = createDistributorHarness({
    initialToken: 'Bearer review-order-delivery-recovered',
    fetchImpl(url, options) {
      if (url.includes('/order/save')) { postCalls += 1; return Promise.resolve(networkResponse({ data: 'REVIEW-DELIVERY-KNOWN' })); }
      if (url.includes('/order/detail')) return Promise.resolve(networkResponse({ data: {
        id: 712, trade_no: 'REVIEW-DELIVERY-KNOWN', period: 'month_price', plan: { id: 1, name: 'Known delivery order' },
      } }));
      if (url.includes('/distributor/delivery')) {
        deliveryReads += 1;
        return deliveryReads === 1
          ? Promise.reject(new TypeError('Failed to fetch'))
          : Promise.resolve(networkResponse({ data: { trade_no: 'REVIEW-DELIVERY-KNOWN', delivery_status: 1, config_issued_at: 1700000000 } }));
      }
      return Promise.resolve(healthyDistributorResponse(url, options));
    },
  });
  await harness.flushMicrotasks(80);
  harness.click({ buy: 1 });
  await harness.flushMicrotasks(80);
  harness.click({ nav: '/order' });
  await harness.flushMicrotasks(80);
  assert.match(renderedText(harness), /data-action="retry-purchase-delivery"/);
  harness.click({ action: 'retry-purchase-delivery' });
  await harness.flushMicrotasks(80);
  assert.match(harness.doc.getElementById('dist-modal-root')?.innerHTML || '', /data-modal-action="close-delivery"/);
  assert.doesNotMatch(harness.doc.getElementById('distributor-app')?.innerHTML || '', /data-action="(?:retry-purchase-delivery|check-purchase-orders|acknowledge-order-check)"/);
  assert.equal(harness.windowObj.location.hash, '#/order');
  assert.equal(postCalls, 1);
  assert.equal(deliveryReads, 2);
  harness.click({ modalAction: 'close-delivery' });
  assert.doesNotMatch(renderedText(harness), /暂未获取到交付信息|data-action="retry-purchase-delivery"/);
});

test('review fix aborted background search retries the newest query after resume even while old orders remain visible', async () => {
  const orderReads = [];
  let releaseCancelledSearch;
  let pendingSignal;
  const harness = createDistributorHarness({
    initialToken: 'Bearer review-background-search', initialHash: '#/order',
    fetchImpl(url, options) {
      if (url.includes('/order/fetch')) {
        orderReads.push(url);
        if (orderReads.length === 2) {
          pendingSignal = options.signal;
          return new Promise((resolve) => { releaseCancelledSearch = resolve; });
        }
        const latest = new URL(url).searchParams.get('search');
        return Promise.resolve(networkResponse({
          data: [{ id: latest ? 2 : 1, trade_no: latest ? 'REVIEW-LATEST-ORDER' : 'REVIEW-OLD-ORDER', customer_name: latest || 'Old customer', plan: { name: 'Review plan' } }],
          current_page: 1, per_page: 20, total: 1, last_page: 1,
        }));
      }
      return Promise.resolve(healthyDistributorResponse(url, options));
    },
  });
  await harness.flushMicrotasks(80);
  assert.match(renderedText(harness), /REVIEW-OLD-ORDER/);
  harness.doc.inputs.set('dist-order-search', { value: 'latest customer' });
  harness.click({ action: 'search-orders' });
  await harness.flushMicrotasks(80);
  assert.equal(orderReads.length, 2);
  assert.match(renderedText(harness), /REVIEW-OLD-ORDER/, 'the prior usable content remains while searching');
  harness.setHidden(true);
  await harness.flushMicrotasks(80);
  assert.equal(pendingSignal?.aborted, true);
  harness.setHidden(false);
  harness.setOnline(true);
  harness.windowObj.dispatchEvent({ type: 'pageshow', persisted: true });
  await harness.advanceTimeAsync(50);
  await harness.flushMicrotasks(80);
  assert.equal(orderReads.length, 3, 'resume must recover an aborted search despite the retained old content');
  assert.equal(new URL(orderReads[2]).searchParams.get('search'), 'latest customer');
  assert.match(renderedText(harness), /REVIEW-LATEST-ORDER/);
  releaseCancelledSearch(networkResponse({ data: [{ id: 99, trade_no: 'REVIEW-STALE-LATE-ORDER', plan: { name: 'Stale' } }] }));
  await harness.flushMicrotasks(80);
  assert.doesNotMatch(renderedText(harness), /REVIEW-STALE-LATE-ORDER|Request cancelled|Failed to fetch/);
});

for (const mode of ['failed', 'cancelled']) {
  test(`review fix ${mode} reconciliation cannot acknowledge an unknown purchase or release its lock`, async () => {
    let postCalls = 0;
    let releaseCancelledRead;
    const harness = createDistributorHarness({
      initialToken: `Bearer review-reconciliation-${mode}`,
      fetchImpl(url, options) {
        if (url.includes('/order/save')) { postCalls += 1; return Promise.reject(new TypeError('Failed to fetch')); }
        if (url.includes('/order/fetch')) {
          if (mode === 'failed') return Promise.resolve(networkResponse({ message: 'Review read rejected' }, 422));
          return new Promise((resolve) => { releaseCancelledRead = resolve; });
        }
        return Promise.resolve(healthyDistributorResponse(url, options));
      },
    });
    await harness.flushMicrotasks(80);
    harness.click({ buy: 1 });
    await harness.flushMicrotasks(80);
    harness.click({ action: 'check-purchase-orders' });
    await harness.flushMicrotasks(80);
    if (mode === 'cancelled') {
      harness.click({ nav: '/knowledge' });
      await harness.flushMicrotasks(80);
      releaseCancelledRead(networkResponse({ data: [], current_page: 1, per_page: 20, total: 0, last_page: 1 }));
      await harness.flushMicrotasks(80);
    }
    assert.doesNotMatch(renderedText(harness), /data-action="acknowledge-order-check"/);
    harness.click({ action: 'acknowledge-order-check' });
    harness.click({ nav: '/plan' });
    await harness.flushMicrotasks(80);
    harness.click({ buy: 1 });
    await harness.flushMicrotasks(80);
    assert.equal(postCalls, 1, 'failed or cancelled reads cannot establish an explicit reviewed purchase intent');
    assert.match(renderedText(harness), /未能确认下单结果/);
  });
}

test('review fix renewal operation identity and late completion remain isolated after switching accounts', async () => {
  const requests = [];
  const pending = new Map();
  const harness = createDistributorHarness({
    initialToken: 'Bearer review-renewal-account-a', initialHash: '#/order',
    fetchImpl(url, options) {
      if (url.includes('/order/fetch')) return Promise.resolve(networkResponse({
        data: [reviewRenewableOrder()], current_page: 1, per_page: 20, total: 1, last_page: 1,
      }));
      if (url.includes('/order/renew')) {
        const auth = options.headers.Authorization;
        requests.push({ auth, ...Object.fromEntries(options.body.entries()) });
        return new Promise((resolve) => { pending.set(auth, resolve); });
      }
      return Promise.resolve(healthyDistributorResponse(url, options));
    },
  });
  await harness.flushMicrotasks(80);
  harness.click({ renew: 'REVIEW-RENEW-SOURCE' });
  harness.click({ modalAction: 'confirm-renewal' });
  await harness.flushMicrotasks(80);
  harness.storage.setItem('VUE_NAIVE_ACCESS_TOKEN', JSON.stringify({ value: 'Bearer review-renewal-account-b', expire: null }));
  await harness.flushMicrotasks(80);
  harness.click({ renew: 'REVIEW-RENEW-SOURCE' });
  harness.click({ modalAction: 'confirm-renewal' });
  await harness.flushMicrotasks(80);
  assert.equal(requests.length, 2);
  assert.notEqual(requests[0].idempotency_key, requests[1].idempotency_key, 'a new session must never inherit another account operation');
  pending.get('Bearer review-renewal-account-a')(networkResponse({ data: { trade_no: 'REVIEW-OLD-ACCOUNT-RENEWAL', expired_at_after: 1900000000 } }));
  await harness.flushMicrotasks(80);
  assert.doesNotMatch(renderedText(harness), /REVIEW-OLD-ACCOUNT-RENEWAL/);
  harness.click({ modalAction: 'cancel' });
  harness.click({ renew: 'REVIEW-RENEW-SOURCE' });
  harness.click({ modalAction: 'confirm-renewal' });
  await harness.flushMicrotasks(80);
  assert.equal(requests.filter((request) => request.auth === 'Bearer review-renewal-account-b').length, 1, 'old completion must not unlock a new session pending renewal');
  pending.get('Bearer review-renewal-account-b')(networkResponse({ data: { trade_no: 'REVIEW-CURRENT-ACCOUNT-RENEWAL', expired_at_after: 1900000000 } }));
  await harness.flushMicrotasks(80);
  assert.match(harness.doc.getElementById('dist-modal-root')?.innerHTML || '', /REVIEW-CURRENT-ACCOUNT-RENEWAL/);
});

test('review fix renewal completed while its modal is closed remains confirmed until explicit acknowledgement', async () => {
  const requests = [];
  let releaseRenewal;
  const harness = createDistributorHarness({
    initialToken: 'Bearer review-renewal-closed-completion', initialHash: '#/order',
    fetchImpl(url, options) {
      if (url.includes('/order/fetch')) return Promise.resolve(networkResponse({
        data: [reviewRenewableOrder()], current_page: 1, per_page: 20, total: 1, last_page: 1,
      }));
      if (url.includes('/order/renew')) {
        requests.push(Object.fromEntries(options.body.entries()));
        if (requests.length === 1) return new Promise((resolve) => { releaseRenewal = resolve; });
        return Promise.resolve(networkResponse({ data: { trade_no: 'REVIEW-EXPLICIT-NEXT-RENEWAL', expired_at_after: 1900000000 } }));
      }
      return Promise.resolve(healthyDistributorResponse(url, options));
    },
  });
  await harness.flushMicrotasks(80);
  harness.click({ renew: 'REVIEW-RENEW-SOURCE' });
  harness.click({ modalAction: 'confirm-renewal' });
  await harness.flushMicrotasks(80);
  harness.click({ modalAction: 'cancel' });
  assert.equal(harness.doc.getElementById('dist-modal-root')?.innerHTML || '', '');
  releaseRenewal(networkResponse({ data: { trade_no: 'REVIEW-COMPLETED-WHILE-CLOSED', expired_at_after: 1900000000 } }));
  await harness.flushMicrotasks(80);
  assert.equal(harness.doc.getElementById('dist-modal-root')?.innerHTML || '', '', 'a closed renewal must not reopen itself when its result arrives');
  harness.click({ renew: 'REVIEW-RENEW-SOURCE' });
  assert.match(harness.doc.getElementById('dist-modal-root')?.innerHTML || '', /REVIEW-COMPLETED-WHILE-CLOSED/);
  harness.click({ modalAction: 'confirm-renewal' });
  await harness.flushMicrotasks(80);
  assert.equal(requests.length, 1, 'an already confirmed operation cannot be resubmitted before acknowledgement');
  harness.click({ modalAction: 'renew-done' });
  harness.click({ renew: 'REVIEW-RENEW-SOURCE' });
  harness.click({ modalAction: 'confirm-renewal' });
  await harness.flushMicrotasks(80);
  assert.equal(requests.length, 2);
  assert.notEqual(requests[1].idempotency_key, requests[0].idempotency_key, 'only the new explicit renewal receives a new operation key');
});

test('review fix failed order refresh cannot make a confirmed renewal resubmittable', async () => {
  let renewCalls = 0;
  let orderCalls = 0;
  const harness = createDistributorHarness({
    initialToken: 'Bearer review-renewal-refresh-failed', initialHash: '#/order',
    fetchImpl(url, options) {
      if (url.includes('/order/fetch')) {
        orderCalls += 1;
        return Promise.resolve(orderCalls === 1
          ? networkResponse({ data: [reviewRenewableOrder()], current_page: 1, per_page: 20, total: 1, last_page: 1 })
          : networkResponse({ message: 'Review order refresh unavailable' }, 503));
      }
      if (url.includes('/order/renew')) {
        renewCalls += 1;
        return Promise.resolve(networkResponse({ data: { trade_no: 'REVIEW-RENEWAL-CONFIRMED-BEFORE-REFRESH', expired_at_after: 1900000000 } }));
      }
      return Promise.resolve(healthyDistributorResponse(url, options));
    },
  });
  await harness.flushMicrotasks(80);
  harness.click({ renew: 'REVIEW-RENEW-SOURCE' });
  harness.click({ modalAction: 'confirm-renewal' });
  await harness.flushMicrotasks(80);
  assert.equal(orderCalls, 2, 'the failing read must actually run after the successful write');
  assert.match(harness.doc.getElementById('dist-modal-root')?.innerHTML || '', /REVIEW-RENEWAL-CONFIRMED-BEFORE-REFRESH/);
  harness.click({ modalAction: 'cancel' });
  harness.click({ renew: 'REVIEW-RENEW-SOURCE' });
  assert.match(harness.doc.getElementById('dist-modal-root')?.innerHTML || '', /REVIEW-RENEWAL-CONFIRMED-BEFORE-REFRESH/);
  harness.click({ modalAction: 'confirm-renewal' });
  await harness.flushMicrotasks(80);
  assert.equal(renewCalls, 1, 'a failed post-success list refresh must never unlock the confirmed renewal');
});

test('review fix invalid HTTP 200 renewal data stays uncertain and explicit retry preserves its key and period', async () => {
  const requests = [];
  const harness = createDistributorHarness({
    initialToken: 'Bearer review-renewal-invalid-success', initialHash: '#/order',
    fetchImpl(url, options) {
      if (url.includes('/order/fetch')) return Promise.resolve(networkResponse({
        data: [reviewRenewableOrder()], current_page: 1, per_page: 20, total: 1, last_page: 1,
      }));
      if (url.includes('/order/renew')) {
        requests.push(Object.fromEntries(options.body.entries()));
        return Promise.resolve(networkResponse({ data: requests.length === 1
          ? {}
          : { trade_no: 'REVIEW-RENEWAL-RECOVERED-SAME-KEY', expired_at_after: 1900000000 } }));
      }
      return Promise.resolve(healthyDistributorResponse(url, options));
    },
  });
  await harness.flushMicrotasks(80);
  harness.click({ renew: 'REVIEW-RENEW-SOURCE' });
  harness.click({ modalAction: 'confirm-renewal' });
  await harness.flushMicrotasks(80);
  await harness.advanceTimeAsync(15000);
  assert.equal(requests.length, 1, 'invalid success data cannot trigger an automatic write retry');
  assert.match(harness.doc.getElementById('dist-modal-root')?.innerHTML || '', /<select[^>]*id="dist-renewal-period"[^>]*disabled/);
  assert.match(harness.doc.getElementById('dist-modal-root')?.innerHTML || '', /role="alert">[^<]*(?:尚未确认|未能确认)/);
  harness.click({ modalAction: 'cancel' });
  harness.click({ renew: 'REVIEW-RENEW-SOURCE' });
  harness.doc.dispatchEvent({ type: 'change', target: { id: 'dist-renewal-period', value: 'quarter_price' } });
  harness.click({ modalAction: 'confirm-renewal' });
  await harness.flushMicrotasks(80);
  assert.equal(requests.length, 2);
  assert.deepEqual(requests[1], requests[0], 'unknown-result replay must preserve the full original renewal intent');
  assert.match(harness.doc.getElementById('dist-modal-root')?.innerHTML || '', /REVIEW-RENEWAL-RECOVERED-SAME-KEY/);
});

test('review fix cancelling only a background notice read never clears or reloads a usable catalog', async () => {
  let planCalls = 0;
  let noticeSignal;
  let releaseNotice;
  const harness = createDistributorHarness({
    initialToken: 'Bearer review-notice-only-background',
    fetchImpl(url, options) {
      if (url.includes('/plan/fetch')) planCalls += 1;
      if (url.includes('/notice/fetch')) {
        noticeSignal = options.signal;
        return new Promise((resolve) => { releaseNotice = resolve; });
      }
      return Promise.resolve(healthyDistributorResponse(url, options));
    },
  });
  await harness.flushMicrotasks(80);
  const catalog = harness.doc.getElementById('distributor-app').innerHTML;
  assert.match(catalog, /Mobile Priority Plan/);
  assert.equal(noticeSignal.aborted, false);
  harness.setHidden(true);
  await harness.flushMicrotasks(80);
  assert.equal(noticeSignal.aborted, true);
  harness.setHidden(false);
  harness.windowObj.dispatchEvent({ type: 'pageshow', persisted: true });
  await harness.advanceTimeAsync(50);
  assert.equal(planCalls, 1, 'an aborted auxiliary notice cannot start another catalog load');
  assert.equal(harness.doc.getElementById('distributor-app').innerHTML, catalog, 'foreground recovery must leave the usable catalog intact');
  releaseNotice(networkResponse({ data: [{ id: 1, title: 'Cancelled old notice' }], total: 1 }));
  await harness.flushMicrotasks(80);
  assert.equal(harness.doc.getElementById('distributor-app').innerHTML, catalog, 'the cancelled notice response must not alter the retained catalog');
});

for (const rejectedRetryStatus of [400, 422, 403]) {
  test(`review R7 unknown renewal remains the same operation after a later HTTP ${rejectedRetryStatus} retry rejection`, async () => {
    const requests = [];
    const harness = createDistributorHarness({
      initialToken: `Bearer review-sticky-uncertain-${rejectedRetryStatus}`, initialHash: '#/order',
      fetchImpl(url, options) {
        if (url.includes('/order/fetch')) return Promise.resolve(networkResponse({
          data: [reviewRenewableOrder()], current_page: 1, per_page: 20, total: 1, last_page: 1,
        }));
        if (url.includes('/order/renew')) {
          requests.push(Object.fromEntries(options.body.entries()));
          if (requests.length === 1) return Promise.reject(new TypeError('Failed to fetch'));
          if (requests.length === 2) return Promise.resolve(networkResponse({ message: 'Gateway rejected this retry' }, rejectedRetryStatus));
          return Promise.resolve(networkResponse({ data: { trade_no: 'REVIEW-SAME-COMMITTED-RENEWAL', expired_at_after: 1900000000 } }));
        }
        return Promise.resolve(healthyDistributorResponse(url, options));
      },
    });
    await harness.flushMicrotasks(80);
    harness.click({ renew: 'REVIEW-RENEW-SOURCE' });
    harness.click({ modalAction: 'confirm-renewal' });
    await harness.flushMicrotasks(80);
    await harness.advanceTimeAsync(15000);
    assert.equal(requests.length, 1, 'unknown writes are never automatically retried');
    harness.click({ modalAction: 'confirm-renewal' });
    await harness.flushMicrotasks(80);
    const rejectedModal = harness.doc.getElementById('dist-modal-root')?.innerHTML || '';
    harness.doc.dispatchEvent({ type: 'change', target: { id: 'dist-renewal-period', value: 'quarter_price' } });
    harness.click({ modalAction: 'cancel' });
    harness.click({ renew: 'REVIEW-RENEW-SOURCE' });
    harness.click({ modalAction: 'confirm-renewal' });
    await harness.flushMicrotasks(80);
    assert.equal(requests.length, 3, 'only the two explicit user retries may send another request');
    assert.deepEqual(requests[1], requests[0]);
    assert.deepEqual(requests[2], requests[0], 'a later rejected attempt cannot disprove the first attempt committed');
    assert.match(rejectedModal, /<select[^>]*id="dist-renewal-period"[^>]*disabled/, 'the original period remains fixed until this operation is confirmed');
    assert.match(rejectedModal, /续费结果尚未确认/);
    assert.match(harness.doc.getElementById('dist-modal-root')?.innerHTML || '', /REVIEW-SAME-COMMITTED-RENEWAL/);
  });
}

test('review R9 resumed different hash never presents old orders as a failed plan page', async () => {
  let plansFail = true;
  let planCalls = 0;
  const harness = createDistributorHarness({
    initialToken: 'Bearer review-resume-different-hash', initialHash: '#/order',
    fetchImpl(url, options) {
      if (url.includes('/order/fetch')) return Promise.resolve(networkResponse({
        data: [{ id: 719, trade_no: 'REVIEW-ORDER-BELONGS-TO-OLD-PAGE', period: 'month_price', plan: { name: 'Old order plan' } }],
        current_page: 1, per_page: 20, total: 1, last_page: 1,
      }));
      if (url.includes('/plan/fetch')) {
        planCalls += 1;
        if (plansFail) return Promise.reject(new TypeError('Failed to fetch'));
      }
      return Promise.resolve(healthyDistributorResponse(url, options));
    },
  });
  await harness.flushMicrotasks(80);
  assert.match(renderedText(harness), /REVIEW-ORDER-BELONGS-TO-OLD-PAGE/);
  harness.setHidden(true);
  harness.windowObj.location.hash = '#/plan';
  await harness.flushMicrotasks(80);
  assert.equal(planCalls, 0, 'hidden navigation must defer its read until recovery');
  harness.setHidden(false);
  harness.windowObj.dispatchEvent({ type: 'pageshow', persisted: true });
  await harness.advanceTimeAsync(450);
  await harness.flushMicrotasks(80);
  assert.equal(harness.windowObj.location.hash, '#/plan');
  assert.equal(planCalls, 2, 'the resumed plan read has one bounded transient retry');
  assert.doesNotMatch(harness.doc.getElementById('distributor-app')?.innerHTML || '', /REVIEW-ORDER-BELONGS-TO-OLD-PAGE|dist-orders-table/);
  assert.match(harness.doc.getElementById('distributor-app')?.innerHTML || '', /class="dist-error"/);
  assert.match(harness.doc.getElementById('distributor-app')?.innerHTML || '', /data-action="retry"/);
  plansFail = false;
  harness.click({ action: 'retry' });
  await harness.flushMicrotasks(80);
  assert.equal(planCalls, 3);
  assert.match(harness.doc.getElementById('distributor-app')?.innerHTML || '', /Mobile Priority Plan/);
});
