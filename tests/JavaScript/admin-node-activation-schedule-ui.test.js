const assert = require('node:assert/strict');
const fs = require('node:fs');
const test = require('node:test');

const createNodeActivationSchedule = require('../../public/assets/admin-node-activation-schedule.js');

function storage(values = {}) {
  return {
    getItem(key) {
      return Object.prototype.hasOwnProperty.call(values, key) ? values[key] : null;
    },
  };
}

test('node activation schedule is scoped to machine management and extracts exact row ids', () => {
  const app = createNodeActivationSchedule({});

  assert.equal(app.isMachineRoute('#/server/machine'), true);
  assert.equal(app.isMachineRoute('#/server/machine?tab=all'), true);
  assert.equal(app.isMachineRoute('#/server/manage?machine_id=1'), false);
  assert.equal(app.isMachineRoute('#/'), false);
  assert.equal(app.parseServerId('#42 Singapore node'), 42);
  assert.equal(app.parseServerId('Node 42'), null);
  assert.equal(app.parseServerId('#0 invalid'), null);
});

test('node activation schedule validates API data and local input boundaries', () => {
  const app = createNodeActivationSchedule({});
  const schedule = {
    server_id: 42,
    enable_at: 1787202000,
    disable_at: 1787205600,
    revision: 'revision-id',
    enabled_applied_at: null,
    disabled_applied_at: null,
    phase: 'pending',
  };

  assert.deepEqual(app.normalizeSchedule(schedule), schedule);
  assert.equal(app.normalizeSchedule(null), null);
  assert.throws(() => app.normalizeSchedule({ ...schedule, disable_at: schedule.enable_at }), /disable_at/);
  assert.throws(() => app.normalizeSchedule({ ...schedule, server_id: '42' }), /server_id/);
  assert.throws(() => app.normalizeSchedule({ ...schedule, phase: 'unknown' }), /phase/);
  assert.deepEqual(app.normalizeInputRange('2026-08-20T13:00', '2026-08-20T14:00'), {
    enable_at: new Date('2026-08-20T13:00').getTime() / 1000,
    disable_at: new Date('2026-08-20T14:00').getTime() / 1000,
  });
  assert.throws(() => app.normalizeInputRange('2026-08-20T14:00', '2026-08-20T13:00'), /later/);
});

test('node activation schedule uses protected admin endpoints and existing token conventions', async () => {
  const requests = [];
  const app = createNodeActivationSchedule({
    settings: { secure_path: '/secret-admin/' },
    localStorage: storage({
      XBOARD_ACCESS_TOKEN: JSON.stringify({ value: 'Bearer test-token', expire: null }),
      i18nextLng: 'en-US',
    }),
    fetch: async (url, options) => {
      requests.push({ url, options });
      return {
        ok: true,
        json: async () => ({
          status: 'success',
          data: url.includes('dropActivationSchedule') ? true : null,
        }),
      };
    },
  });

  assert.equal(await app.requestSchedule(42), null);
  await app.saveSchedule(42, 1787202000, 1787205600);
  assert.equal(await app.dropSchedule(42), true);

  assert.equal(requests[0].url, '/api/v2/secret-admin/server/manage/activationSchedule?server_id=42');
  assert.equal(requests[0].options.method, 'GET');
  assert.equal(requests[0].options.headers.Authorization, 'Bearer test-token');
  assert.equal(requests[0].options.cache, 'no-store');
  assert.equal(requests[1].url, '/api/v2/secret-admin/server/manage/activationSchedule');
  assert.equal(requests[1].options.method, 'POST');
  assert.deepEqual(JSON.parse(requests[1].options.body), {
    server_id: 42,
    enable_at: 1787202000,
    disable_at: 1787205600,
  });
  assert.equal(requests[2].url, '/api/v2/secret-admin/server/manage/dropActivationSchedule');
  assert.deepEqual(JSON.parse(requests[2].options.body), { server_id: 42 });
});

test('admin template and pinned bundle expose the activation schedule UI contracts', () => {
  const blade = fs.readFileSync('resources/views/admin.blade.php', 'utf8');
  const styles = fs.readFileSync('public/assets/admin-node-activation-schedule.css', 'utf8');
  const script = fs.readFileSync('public/assets/admin-node-activation-schedule.js', 'utf8');
  const manifest = JSON.parse(fs.readFileSync('public/assets/admin/manifest.json', 'utf8'));
  const bundle = fs.readFileSync(`public/assets/admin/${manifest['index.html'].file}`, 'utf8');
  const workflow = fs.readFileSync('.github/workflows/docker-publish.yml', 'utf8');
  const releaseSmoke = fs.readFileSync('.github/scripts/smoke-distributor-remote.sh', 'utf8');

  assert.match(blade, /admin-node-activation-schedule\.css/);
  assert.match(blade, /admin-node-activation-schedule\.js/);
  assert.match(styles, /\.xboard-node-schedule-trigger/);
  assert.match(styles, /\.xboard-node-schedule-dialog/);
  assert.match(script, /button\[role="switch"\]\[aria-label="Enabled"\]/);
  assert.match(script, /button\[role="switch"\]\[aria-label="Disabled"\]/);
  assert.match(script, /event\.key !== 'Tab'/);
  assert.match(bundle, /\["machineNodes",e\]/);
  assert.match(bundle, /server\/manage\/update/);
  assert.match(bundle, /enabled:!t\.enabled/);
  assert.match(workflow, /node --check public\/assets\/admin-node-activation-schedule\.js/);
  assert.match(releaseSmoke, /admin-node-activation-schedule\.js/);
  assert.match(releaseSmoke, /admin-node-activation-schedule\.css/);
  assert.match(releaseSmoke, /dropActivationSchedule/);
});
