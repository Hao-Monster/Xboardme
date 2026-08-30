const assert = require('node:assert/strict');
const fs = require('node:fs');
const test = require('node:test');

const adminSource = fs.readFileSync('public/assets/admin-distributor.js', 'utf8');
const distributorSource = fs.readFileSync('theme/Xboard/assets/distributor.js', 'utf8');

test('admin entitlement editor exposes five values but submits only four editable fields', () => {
  assert.match(adminSource, /subscription_entitlement/);
  assert.match(adminSource, /套餐/);
  assert.match(adminSource, /总流量/);
  assert.match(adminSource, /已用流量/);
  assert.match(adminSource, /剩余流量/);
  assert.match(adminSource, /到期时间/);
  assert.match(adminSource, /限速/);
  assert.match(adminSource, /设备限制/);
  assert.match(adminSource, /data-save-entitlement/);
  assert.match(adminSource, /\/order\/entitlement\/update/);

  const updateBlock = adminSource.match(/await api\('\/order\/entitlement\/update'[\s\S]*?\}\s*\}\);/);
  assert.ok(updateBlock, 'the entitlement update request should exist');
  assert.match(updateBlock[0], /order_id/);
  assert.match(updateBlock[0], /transfer_enable/);
  assert.match(updateBlock[0], /expired_at/);
  assert.match(updateBlock[0], /speed_limit/);
  assert.match(updateBlock[0], /device_limit/);
  assert.doesNotMatch(updateBlock[0], /plan_id/);
  assert.doesNotMatch(updateBlock[0], /\bu\s*:/);
  assert.doesNotMatch(updateBlock[0], /\bd\s*:/);
  assert.doesNotMatch(updateBlock[0], /settlement_status/);
  assert.doesNotMatch(updateBlock[0], /total_amount/);
});

test('admin entitlement editor preserves current user-management units and nullable limits', () => {
  assert.match(adminSource, /Math\.round\(traffic \* GIB\)/);
  assert.match(adminSource, /type="datetime-local"/);
  assert.match(adminSource, /data-entitlement-permanent/);
  assert.match(adminSource, /speedValue === '' \? null/);
  assert.match(adminSource, /deviceValue === '' \? null/);
  assert.match(adminSource, /min="0"/);
});

test('distributor order page renders a read-only entitlement summary without subscription credentials', () => {
  assert.match(distributorSource, /subscription_entitlement/);
  assert.match(distributorSource, /dist-entitlement-row/);
  assert.match(distributorSource, /entitlement\.plan_name/);
  assert.match(distributorSource, /entitlement\.transfer_enable/);
  assert.match(distributorSource, /entitlement\.used_traffic/);
  assert.match(distributorSource, /entitlement\.remaining_traffic/);
  assert.match(distributorSource, /entitlement\.expired_at/);
  assert.match(distributorSource, /entitlement\.speed_limit/);
  assert.match(distributorSource, /entitlement\.device_limit/);
  assert.match(distributorSource, /escapeHtml\(entitlement\.plan_name/);

  const orderRendering = distributorSource.match(/async function renderOrders\(options = \{\}\)[\s\S]*?function periodLabel/);
  assert.ok(orderRendering, 'the distributor order renderer should exist');
  assert.doesNotMatch(orderRendering[0], /subscribe_url/);
  assert.doesNotMatch(orderRendering[0], /\.token/);
  assert.doesNotMatch(orderRendering[0], /\.uuid/);
  assert.doesNotMatch(orderRendering[0], /data-save-entitlement/);
});
