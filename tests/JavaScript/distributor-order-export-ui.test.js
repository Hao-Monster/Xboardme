const assert = require('node:assert/strict');
const fs = require('node:fs');
const test = require('node:test');

const source = fs.readFileSync('theme/Xboard/assets/distributor.js', 'utf8');

test('distributor orders share one settlement filter between list and xlsx export', () => {
  assert.match(source, /orderSettlementStatus:\s*''/);
  assert.match(source, /id="dist-order-settlement"/);
  assert.match(source, /settlement_status/);
  assert.match(source, /\/user\/order\/fetch/);
  assert.match(source, /\/user\/order\/export/);

  const exportBlock = source.match(/async function exportOrders\(button\)[\s\S]*?\n  }/);
  assert.ok(exportBlock, 'distributor export handler should exist');
  assert.match(exportBlock[0], /state\.orderSettlementStatus/);
  assert.match(exportBlock[0], /settlement_status/);
  assert.doesNotMatch(exportBlock[0], /user_id|distributor_user_id|current|pageSize/);
});

test('binary download handles xlsx filenames and json errors without exposing subscription data', () => {
  const downloadBlock = source.match(/async function downloadFile\(path\)[\s\S]*?\n  }/);
  assert.ok(downloadBlock, 'binary download helper should exist');
  assert.match(downloadBlock[0], /application\/json/);
  assert.match(downloadBlock[0], /Content-Disposition/);
  assert.match(downloadBlock[0], /response\.blob\(\)/);
  assert.doesNotMatch(downloadBlock[0], /subscribe_url|\.token|\.uuid/);
});
