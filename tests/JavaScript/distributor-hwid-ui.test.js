const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..', '..');
const distributor = fs.readFileSync(path.join(root, 'theme/Xboard/assets/distributor.js'), 'utf8');
const admin = fs.readFileSync(path.join(root, 'public/assets/admin-distributor.js'), 'utf8');

test('distributor delivery waits for first positive traffic and reports the connected node', () => {
  assert.match(distributor, /等待用户开启代理 进入网络/);
  assert.match(distributor, /客户已经通过 \{node\} 节点进入网络/);
  assert.match(distributor, /updated\.connected_at/);
  assert.match(distributor, /closeModal\(\);\s*await renderPage\(\)/);
  assert.doesNotMatch(distributor, /delivery\.config_issued_at\) \{ closeModal\(\)/);
});

test('admin order detail manages HWID settings and registered devices', () => {
  assert.match(admin, /HWID 设备限制/);
  assert.match(admin, /\/order\/hwid\/update/);
  assert.match(admin, /\/order\/hwid\/devices/);
  assert.match(admin, /\/order\/hwid\/device\/delete/);
  assert.match(admin, /降低上限不会删除已有设备/);
});
