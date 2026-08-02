const assert = require('node:assert/strict');
const fs = require('node:fs');
const test = require('node:test');

const source = fs.readFileSync('theme/Xboard/assets/distributor.js', 'utf8');
const styles = fs.readFileSync('theme/Xboard/assets/distributor.css', 'utf8');

test('distributor plan catalog exposes the new commerce-oriented page structure', () => {
  assert.match(source, /dist-catalog-topbar/);
  assert.match(source, /dist-plan-filters/);
  assert.match(source, /data-plan-filter/);
  assert.match(source, /dist-plan-specs/);
  assert.match(source, /role="radiogroup"/);
  assert.match(source, /data-plan-period/);
  assert.match(source, /dist-plan-checkout-summary/);
  assert.match(source, /分销免支付下单/);
  assert.match(source, /dist-plan-checkout-summary"><span>\$\{t\('original'\)\} \$\{money\(selectedPrice\)\}<\/span><\/div>/);
  assert.doesNotMatch(source, /dist-plan-checkout-summary"><span>[\s\S]*?<strong>\$\{t\('free'\)\}<\/strong>/);
  assert.doesNotMatch(source, /dist-catalog-hero|dist-delivery-guide|选择套餐，免支付快速交付|选择要交付的订阅套餐/);
});

test('plan route renders the slogan and compact delivery steps inside dist-topbar', () => {
  const shellBlock = source.match(/function shell\(content\)[\s\S]*?\n  }/);
  assert.ok(shellBlock, 'distributor shell should exist');
  assert.match(shellBlock[0], /page === '\/plan'/);
  assert.match(shellBlock[0], /dist-topbar-promo/);
  assert.match(shellBlock[0], /dist-topbar-steps/);
  assert.match(shellBlock[0], /dist-topbar \$\{page === '\/plan' \? 'has-promo' : ''\}/);
  assert.match(shellBlock[0], /<small><b>0\$\{index \+ 1\}<\/b>\$\{title\}<\/small>/);
  assert.match(source, /promoStable: '稳定'/);
  assert.match(source, /promoFast: '高速'/);
  assert.match(source, /promoCompensation: '慢必赔'/);
  assert.match(styles, /\.dist-topbar-promo/);
  assert.match(styles, /\.dist-topbar-steps small/);
  assert.doesNotMatch(source, /dist-delivery-strip|dist-delivery-step/);
  assert.doesNotMatch(styles, /\.dist-delivery-strip|\.dist-delivery-step/);
});

test('every period price carries its own gold monthly-equivalent insight', () => {
  const periodButton = source.match(/const periodButtons = prices\.map[\s\S]*?\.join\(''\);/);
  assert.ok(periodButton, 'period price buttons should exist');
  assert.match(periodButton[0], /<strong>\$\{money\(plan\[key\]\)\}<\/strong><small>\$\{periodInsight\(plan, key\)\}<\/small>/);
  assert.match(source, /perMonth: '折合 \{price\}\/月'/);
  assert.match(source, /save: '省 \{percent\}%'/);
  assert.match(styles, /\.dist-period-options button small \{[^}]*color:#b7791f/);
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
  assert.match(styles, /\.dist-catalog-topbar/);
  assert.match(styles, /\.dist-plan-card/);
  assert.doesNotMatch(styles, /\.dist-catalog-hero|\.dist-delivery-guide/);
  assert.doesNotMatch(styles, /(^|[,{])\s*\.plan-card\b/m);
});
