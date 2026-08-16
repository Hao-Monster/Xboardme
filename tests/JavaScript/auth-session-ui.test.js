const assert = require('node:assert/strict');
const fs = require('node:fs');
const test = require('node:test');
const vm = require('node:vm');

const source = fs.readFileSync('theme/Xboard/assets/auth-session.js', 'utf8');
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
