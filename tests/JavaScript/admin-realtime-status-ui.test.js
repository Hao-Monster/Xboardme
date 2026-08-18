const assert = require('node:assert/strict');
const fs = require('node:fs');
const test = require('node:test');

const createRealtimeStatus = require('../../public/assets/admin-realtime-status.js');

function storage(values = {}) {
  return {
    getItem(key) {
      return Object.prototype.hasOwnProperty.call(values, key) ? values[key] : null;
    },
  };
}

test('realtime status recognizes only the dashboard hash route and supported languages', () => {
  const app = createRealtimeStatus({});

  assert.equal(app.isDashboardRoute(''), true);
  assert.equal(app.isDashboardRoute('#/'), true);
  assert.equal(app.isDashboardRoute('#/?from=login'), true);
  assert.equal(app.isDashboardRoute('#/user'), false);
  assert.equal(app.isDashboardRoute('#/config/system'), false);
  assert.equal(app.normalizeLanguage('en'), 'en-US');
  assert.equal(app.normalizeLanguage('ru-RU'), 'ru-RU');
  assert.equal(app.normalizeLanguage('unsupported'), 'zh-CN');
});

test('realtime status validates the complete non-negative snapshot contract', () => {
  const app = createRealtimeStatus({});
  const valid = {
    onlineDevices: 7,
    onlineUsers: 4,
    onlineNodes: 2,
    windowSeconds: 300,
    generatedAt: 1787035200,
  };

  assert.deepEqual(app.normalizeSnapshot(valid), valid);
  assert.throws(() => app.normalizeSnapshot({ ...valid, onlineDevices: -1 }), /onlineDevices/);
  assert.throws(() => app.normalizeSnapshot({ ...valid, onlineUsers: '4' }), /onlineUsers/);
  assert.throws(() => app.normalizeSnapshot({ ...valid, generatedAt: 0 }), /generatedAt/);
  assert.throws(() => app.normalizeSnapshot(null), /snapshot/i);
  assert.deepEqual(app.failedViewState(null), { phase: 'stale', snapshot: null });
  assert.deepEqual(app.failedViewState(valid), { phase: 'stale', snapshot: valid });
  assert.equal(app.refreshInterval, 60000);
});

test('realtime status calls the protected lightweight endpoint with existing admin conventions', async () => {
  let request;
  const app = createRealtimeStatus({
    settings: { secure_path: '/secret-admin/' },
    localStorage: storage({
      XBOARD_ACCESS_TOKEN: JSON.stringify({ value: 'Bearer test-token', expire: null }),
      i18nextLng: 'en-US',
    }),
    fetch: async (url, options) => {
      request = { url, options };
      return {
        ok: true,
        json: async () => ({
          status: 'success',
          data: {
            onlineDevices: 3,
            onlineUsers: 2,
            onlineNodes: 1,
            windowSeconds: 300,
            generatedAt: 1787035200,
          },
        }),
      };
    },
  });

  const snapshot = await app.requestSnapshot();

  assert.equal(request.url, '/api/v2/secret-admin/stat/getRealtimeStats');
  assert.equal(request.options.headers.Authorization, 'Bearer test-token');
  assert.equal(request.options.headers['Content-Language'], 'en-US');
  assert.equal(request.options.cache, 'no-store');
  assert.equal(snapshot.onlineDevices, 3);
});

test('realtime poller never overlaps requests and stops its scheduled refresh', async () => {
  const scheduled = [];
  const cancelled = [];
  let resolveFirst;
  let calls = 0;
  const first = new Promise((resolve) => { resolveFirst = resolve; });
  const app = createRealtimeStatus({});
  const poller = app.createPoller(async () => {
    calls += 1;
    if (calls === 1) await first;
  }, {
    delay: 60000,
    setTimer(callback, delay) {
      scheduled.push({ callback, delay });
      return scheduled.length;
    },
    clearTimer(id) {
      cancelled.push(id);
    },
  });

  const running = poller.start();
  await poller.trigger();
  assert.equal(calls, 1);

  resolveFirst();
  await running;
  assert.equal(scheduled.length, 1);
  assert.equal(scheduled[0].delay, 60000);

  poller.stop();
  assert.deepEqual(cancelled, [1]);
});

test('realtime poller pauses while hidden and refreshes immediately when resumed', async () => {
  const scheduled = [];
  const cancelled = [];
  let calls = 0;
  const app = createRealtimeStatus({});
  const poller = app.createPoller(async () => { calls += 1; }, {
    delay: 60000,
    setTimer(callback) {
      scheduled.push(callback);
      return scheduled.length;
    },
    clearTimer(id) {
      cancelled.push(id);
    },
  });

  await poller.start();
  assert.equal(calls, 1);
  poller.pause();
  assert.equal(await poller.trigger(), false);
  assert.equal(calls, 1);
  assert.equal(await poller.resume(), true);
  assert.equal(calls, 2);
  poller.stop();
  assert.deepEqual(cancelled, [1, 2]);
});

test('admin template loads the extension and pinned bundle still exposes its dashboard anchor contract', () => {
  const blade = fs.readFileSync('resources/views/admin.blade.php', 'utf8');
  const styles = fs.readFileSync('public/assets/admin-realtime-status.css', 'utf8');
  const manifest = JSON.parse(fs.readFileSync('public/assets/admin/manifest.json', 'utf8'));
  const bundle = fs.readFileSync(`public/assets/admin/${manifest['index.html'].file}`, 'utf8');

  assert.match(blade, /admin-realtime-status\.css/);
  assert.match(blade, /admin-realtime-status\.js/);
  assert.match(styles, /\.xboard-realtime-grid/);
  assert.match(styles, /@media \(min-width: 768px\)/);
  assert.match(bundle, /dashboard:stats\.todayIncome/);
  assert.match(bundle, /dashboard:stats\.monthlyDownload/);
  assert.match(bundle, /grid gap-4 md:grid-cols-2 lg:grid-cols-4/);
});
