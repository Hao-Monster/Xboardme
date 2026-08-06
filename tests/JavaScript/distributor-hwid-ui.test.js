const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..', '..');
const distributor = fs.readFileSync(path.join(root, 'theme/Xboard/assets/distributor.js'), 'utf8');
const admin = fs.readFileSync(path.join(root, 'public/assets/admin-distributor.js'), 'utf8');

test('distributor delivery waits for first positive traffic and reports the connected node', () => {
  assert.match(distributor, /等待用户开启代理进入网络/);
  assert.match(distributor, /客户已经通过 \{node\} 节点进入网络/);
  assert.match(distributor, /updated\.connected_at/);
  assert.match(distributor, /delivery\.config_issued_at\) \{ closeModal\(\)/);
  assert.doesNotMatch(distributor, /if \(updated\.connected_at\) \{\s*closeModal\(\)/);
  assert.match(distributor, /class="dist-network-status"/);
});

test('distributor checkout keeps the pre-HWID interaction copy and state transitions', () => {
  assert.match(distributor, /每个订单生成一份独立订阅，客户扫码领取后不可再次领取。/);
  assert.match(distributor, /请让终端客户使用订阅客户端扫描。二维码只能成功领取一次。/);
  assert.match(distributor, /delivery\.delivery_status === 0 && delivery\.qr_code/);
  assert.match(distributor, /claimed && issued \? '✓'/);
  assert.match(distributor, /delivery\?\.delivery_status === 0/);
  assert.doesNotMatch(distributor, /默认通过 HWID 限制 1 台设备/);
});

test('admin order detail manages HWID settings and registered devices', () => {
  assert.match(admin, /HWID 设备限制/);
  assert.match(admin, /\/order\/hwid\/update/);
  assert.match(admin, /\/order\/hwid\/devices/);
  assert.match(admin, /\/order\/hwid\/device\/delete/);
  assert.match(admin, /降低上限不会删除已有设备/);
});
