const assert = require('node:assert/strict');
const fs = require('node:fs');
const test = require('node:test');

const source = fs.readFileSync('theme/Xboard/assets/distributor.js', 'utf8');
const styles = fs.readFileSync('theme/Xboard/assets/distributor.css', 'utf8');

test('distributor plan catalog exposes the new commerce-oriented page structure', () => {
  assert.match(source, /dist-catalog-hero/);
  assert.match(source, /dist-plan-filters/);
  assert.match(source, /data-plan-filter/);
  assert.match(source, /dist-plan-specs/);
  assert.match(source, /role="radiogroup"/);
  assert.match(source, /data-plan-period/);
  assert.match(source, /dist-plan-checkout-summary/);
  assert.match(source, /dist-delivery-guide/);
  assert.match(source, /分销免支付下单/);
  assert.match(source, /每单独立订阅/);
  assert.match(source, /二维码仅领取一次/);
});

test('period selection changes display state while checkout keeps the existing order contract', () => {
  assert.match(source, /selectedPeriods:\s*\{\}/);
  assert.match(source, /state\.selectedPeriods\[period\.dataset\.planId\]/);

  const confirmBlock = source.match(/function confirmPurchase\(planId, planName\)[\s\S]*?\n  }/);
  assert.ok(confirmBlock, 'purchase confirmation should exist');
  assert.match(confirmBlock[0], /state\.selectedPeriods\[planId\]/);
  assert.match(confirmBlock[0], /plan\[period\]/);

  const submitBlock = source.match(/async function submitPurchase\(\)[\s\S]*?\n  }/);
  assert.ok(submitBlock, 'purchase submission should exist');
  assert.match(submitBlock[0], /\/user\/order\/save/);
  assert.match(submitBlock[0], /plan_id:\s*modal\.planId/);
  assert.match(submitBlock[0], /period:\s*modal\.period/);
});

test('catalog remains isolated behind distributor detection and scoped styles', () => {
  assert.match(source, /if \(user\?\.is_distributor\) activate\(user\)/);
  assert.match(source, /normalApp\.style\.display = 'none'/);
  assert.match(source, /document\.documentElement\.classList\.add\('distributor-mode'\)/);
  assert.match(styles, /\.dist-catalog-hero/);
  assert.match(styles, /\.dist-plan-card/);
  assert.doesNotMatch(styles, /(^|[,{])\s*\.plan-card\b/m);
});
