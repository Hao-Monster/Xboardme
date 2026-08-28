const assert = require('node:assert/strict');
const fs = require('node:fs');
const test = require('node:test');

const source = fs.readFileSync('theme/Xboard/assets/distributor.js', 'utf8');
const styles = fs.readFileSync('theme/Xboard/assets/distributor.css', 'utf8');

test('distributor checkout creates an order directly without a customer-name confirmation modal', () => {
  const purchaseBlock = source.match(/async function purchasePlan\(plan, period, button\)[\s\S]*?\n  }/);
  assert.ok(purchaseBlock, 'direct purchase handler should exist');
  assert.match(purchaseBlock[0], /data: \{ plan_id: plan\.id, period \}/);
  assert.match(purchaseBlock[0], /await openDelivery\(tradeNo\)/);
  assert.match(purchaseBlock[0], /button\.disabled = true/);
  assert.match(purchaseBlock[0], /button\.disabled = false/);
  assert.doesNotMatch(purchaseBlock[0], /customer_name|customerName/);
  assert.match(source, /orderAction: '已确认，直接下单'/);
  assert.doesNotMatch(source, /openPurchaseModal|submitPurchase|confirm-purchase|dist-customer-name|customerNamePlaceholder|customerNameRequired/);
  assert.doesNotMatch(styles, /\.dist-customer-name/);
});

test('distributor order list shows the customer name and preserves historical blanks', () => {
  const ordersBlock = source.match(/async function renderOrders\(\)[\s\S]*?\n  }/);
  assert.ok(ordersBlock, 'order list renderer should exist');
  assert.match(ordersBlock[0], /t\('customerName'\)/);
  assert.match(ordersBlock[0], /escapeHtml\(order\.customer_name \|\| '-'\)/);
  assert.match(ordersBlock[0], /colspan="11"/);
});
