const assert = require('node:assert/strict');
const fs = require('node:fs');
const test = require('node:test');

const source = fs.readFileSync('theme/Xboard/assets/distributor.js', 'utf8');
const styles = fs.readFileSync('theme/Xboard/assets/distributor.css', 'utf8');

test('distributor checkout requires a trimmed customer name before submission', () => {
  const modalBlock = source.match(/function renderModal\(\)[\s\S]*?\n  }/);
  assert.ok(modalBlock, 'modal renderer should exist');
  assert.match(modalBlock[0], /id="dist-customer-name"/);
  assert.match(modalBlock[0], /maxlength="64"/);
  assert.match(modalBlock[0], /customerNamePlaceholder/);

  const submitBlock = source.match(/async function submitPurchase\(\)[\s\S]*?\n  }/);
  assert.ok(submitBlock, 'purchase submission should exist');
  assert.match(submitBlock[0], /String\(customerNameInput\?\.value \|\| ''\)\.trim\(\)/);
  assert.match(submitBlock[0], /if \(!customerName\)/);
  assert.match(submitBlock[0], /customerNameRequired/);
  assert.match(submitBlock[0], /customer_name: customerName/);
  assert.match(source, /customerNameRequired: '为了售后方便，请输入备注清楚用户'/);
  assert.match(styles, /\.dist-customer-name input/);
});

test('distributor order list shows the customer name and preserves historical blanks', () => {
  const ordersBlock = source.match(/async function renderOrders\(\)[\s\S]*?\n  }/);
  assert.ok(ordersBlock, 'order list renderer should exist');
  assert.match(ordersBlock[0], /t\('customerName'\)/);
  assert.match(ordersBlock[0], /escapeHtml\(order\.customer_name \|\| '-'\)/);
  assert.match(ordersBlock[0], /colspan="8"/);
});
