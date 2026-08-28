const assert = require('node:assert/strict');
const fs = require('node:fs');
const test = require('node:test');

const distributor = fs.readFileSync('theme/Xboard/assets/distributor.js', 'utf8');
const admin = fs.readFileSync('public/assets/admin-distributor.js', 'utf8');

test('distributor order table places live usage columns between amount and settlement', () => {
  const renderer = distributor.match(/async function renderOrders\(\)[\s\S]*?function periodLabel/);
  assert.ok(renderer, 'distributor order renderer should exist');
  assert.match(renderer[0], /order\.bound_device_count \?\? boundDevices\.length/);
  assert.match(renderer[0], /order\.used_traffic \?\? entitlement\?\.used_traffic \?\? 0/);
  assert.match(renderer[0], /dist-order-amount[\s\S]*dist-order-bound-devices[\s\S]*dist-order-used-traffic[\s\S]*dist-order-settlement/);
  assert.match(renderer[0], /t\('amount'\)[\s\S]*t\('boundDevices'\)[\s\S]*t\('usedTraffic'\)[\s\S]*t\('settlement'\)/);
});

test('administrator order tables place live usage columns between customer and distributor', () => {
  const rows = admin.match(/function orderRows\([\s\S]*?\n  }/);
  assert.ok(rows, 'administrator order row renderer should exist');
  assert.match(rows[0], /customer_name[\s\S]*admin-dist-bound-devices[\s\S]*admin-dist-used-traffic[\s\S]*distributor_name/);
  assert.match(rows[0], /formatTraffic\(order\.used_traffic\)/);

  const headers = [...admin.matchAll(/<th>用户名称<\/th><th>已绑定设备<\/th><th>已用流量<\/th><th>分销商<\/th>/g)];
  assert.equal(headers.length, 2, 'modal and native administrator tables should share the same column order');
});
