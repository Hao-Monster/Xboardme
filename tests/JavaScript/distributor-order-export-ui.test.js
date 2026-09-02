const assert = require('node:assert/strict');
const fs = require('node:fs');
const test = require('node:test');

const source = fs.readFileSync('theme/Xboard/assets/distributor.js', 'utf8');

test('distributor orders share one settlement filter between list and xlsx export', () => {
  assert.match(source, /orderSettlementStatus:\s*''/);
  assert.match(source, /id="dist-order-settlement"/);
  assert.match(source, /settlement_status/);
  assert.match(source, /orderSearch:\s*''/);
  assert.match(source, /id="dist-order-search"/);
  assert.match(source, /data-action="search-orders"/);
  assert.match(source, /data-action="clear-order-search"/);
  assert.match(source, /\/user\/order\/fetch/);
  assert.match(source, /\/user\/order\/export/);

  const exportBlock = source.match(/async function exportOrders\(button\)[\s\S]*?\n  }/);
  assert.ok(exportBlock, 'distributor export handler should exist');
  assert.match(exportBlock[0], /state\.orderSettlementStatus/);
  assert.match(exportBlock[0], /settlement_status/);
  assert.match(exportBlock[0], /state\.orderSearch/);
  assert.match(exportBlock[0], /params\.set\('search', state\.orderSearch\)/);
  assert.doesNotMatch(exportBlock[0], /user_id|distributor_user_id|current|pageSize/);
});

test('settlement status is part of the existing advanced filters and applies with them', () => {
  const filtersBlock = source.match(/function renderOrderFilters\(\)[\s\S]*?\n  }/);
  const renderer = source.match(/async function renderOrders\(options = \{\}\)[\s\S]*?function periodLabel/);
  assert.ok(filtersBlock, 'advanced order filters renderer should exist');
  assert.ok(renderer, 'order list renderer should exist');

  assert.match(filtersBlock[0], /id="dist-order-settlement"/);
  assert.match(filtersBlock[0], /id="dist-order-start"/);
  assert.match(filtersBlock[0], /id="dist-order-end"/);
  assert.match(filtersBlock[0], /id="dist-order-min-amount"/);
  assert.match(filtersBlock[0], /id="dist-order-max-amount"/);
  assert.doesNotMatch(renderer[0], /<div class="dist-order-toolbar"[^\n]*id="dist-order-settlement"/);
  assert.match(source, /state\.orderSettlementStatus = settlementStatus/);
  assert.match(source, /state\.orderSettlementStatus = ''/);
  assert.doesNotMatch(source, /event\.target\.id === 'dist-order-settlement'/);
});

test('distributor order search supports enter, trims input and searches on the server', () => {
  const renderBlock = source.match(/async function renderOrders\(options = \{\}\)[\s\S]*?\n  }/);
  assert.ok(renderBlock, 'order list renderer should exist');
  assert.match(renderBlock[0], /params\.set\('search', state\.orderSearch\)/);
  assert.match(source, /event\.key !== 'Enter'/);
  assert.match(source, /event\.target\.value\.trim\(\)/);
  assert.match(source, /输入订单号或用户名称查询/);
});

test('distributor order list renders the administrator remark as read-only text', () => {
  const renderBlock = source.match(/async function renderOrders\(options = \{\}\)[\s\S]*?\n  }/);
  assert.ok(renderBlock, 'order list renderer should exist');
  assert.match(renderBlock[0], /t\('remark'\)/);
  assert.match(renderBlock[0], /order\.remark/);
  assert.match(renderBlock[0], /escapeHtml\(order\.remark\)/);
  assert.doesNotMatch(renderBlock[0], /edit-remark|remark\/update|textarea/);
});

test('binary download handles xlsx filenames and json errors without exposing subscription data', () => {
  const downloadBlock = source.match(/async function downloadFile\(path\)[\s\S]*?\n  }/);
  assert.ok(downloadBlock, 'binary download helper should exist');
  assert.match(downloadBlock[0], /application\/json/);
  assert.match(downloadBlock[0], /Content-Disposition/);
  assert.match(downloadBlock[0], /response\.blob\(\)/);
  assert.doesNotMatch(downloadBlock[0], /subscribe_url|\.token|\.uuid/);
});
